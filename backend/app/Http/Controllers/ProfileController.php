<?php

namespace App\Http\Controllers;

use App\Enums\OtpPurpose;
use App\Http\Requests\ProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailOtpService;
use App\Services\Auth\TenantAccountLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informations personnelles du compte connecté.
 *
 * Le changement d'adresse e-mail est traité à part du reste, et en deux temps.
 * L'ancienne adresse reste celle du compte jusqu'à ce que la nouvelle soit
 * confirmée par un code : écrire tout de suite et redemander une vérification
 * serait plus court d'un aller-retour, mais une faute de frappe enfermerait le
 * compte dehors, puisque la connexion exige une adresse vérifiée et que le code
 * partirait vers une boîte inexistante.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->load('socialAccounts')),
        ]);
    }

    public function update(ProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->personalData());

        return response()->json([
            'data' => new UserResource($user->refresh()->load('socialAccounts')),
            'message' => 'Vos informations ont été enregistrées.',
        ]);
    }

    /* ----------------------------------------------------------------------
     | Changement d'adresse e-mail
     |----------------------------------------------------------------------*/

    /**
     * Demande un changement d'adresse : enregistre la cible et y envoie un code.
     *
     * Le mot de passe est réexigé, alors que la session est déjà ouverte. Ce
     * n'est pas une redondance : l'adresse e-mail commande la réinitialisation
     * du mot de passe, donc le compte entier. Un poste laissé déverrouillé
     * quelques minutes suffirait sinon à en prendre possession définitivement.
     * Les comptes sans mot de passe — ouverts par Google ou Apple — en sont
     * dispensés, faute d'avoir quoi que ce soit à confirmer.
     */
    public function requestEmailChange(Request $request, EmailOtpService $otp): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => [
                'required', 'email', 'max:254',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => [$user->hasUsablePassword() ? 'required' : 'nullable', 'string'],
        ]);

        if ($user->hasUsablePassword()
            && ! password_verify($validated['password'] ?? '', $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Mot de passe incorrect.',
            ]);
        }

        if (mb_strtolower($validated['email']) === mb_strtolower($user->email)) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse est déjà celle de votre compte.',
            ]);
        }

        $user->forceFill(['pending_email' => $validated['email']])->save();

        /*
         * Le code part vers la **nouvelle** adresse, pas vers celle du compte :
         * c'est l'accès à cette boîte-là qu'il s'agit de prouver.
         */
        $otp->send($user, OtpPurpose::EmailChange, sendTo: $validated['email']);

        return response()->json([
            'message' => "Un code a été envoyé à {$validated['email']}.",
            'pending_email' => $validated['email'],
            'otp' => [
                'expires_in_minutes' => $otp->validityMinutes(),
                'resend_after_seconds' => $otp->resendIntervalSeconds(),
            ],
        ]);
    }

    /**
     * Confirme le changement d'adresse.
     *
     * L'adresse devient celle du compte et est réputée vérifiée : le code
     * reçu à cette adresse en est la preuve, et redemander une vérification
     * reviendrait à prouver deux fois la même chose.
     */
    public function confirmEmailChange(
        Request $request,
        EmailOtpService $otp,
        TenantAccountLinker $linker,
    ): JsonResponse {
        $user = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (blank($user->pending_email)) {
            throw ValidationException::withMessages([
                'code' => 'Aucun changement d\'adresse n\'est en cours.',
            ]);
        }

        // Contrôlée à nouveau : l'adresse a pu être prise entre la demande et
        // la confirmation, et la contrainte d'unicité échouerait sans message.
        if (User::where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            $user->forceFill(['pending_email' => null])->save();

            throw ValidationException::withMessages([
                'email' => 'Cette adresse vient d\'être utilisée par un autre compte.',
            ]);
        }

        $otp->verify($user, $validated['code'], OtpPurpose::EmailChange);

        $user->forceFill([
            'email' => $user->pending_email,
            'pending_email' => null,
            'email_verified_at' => now(),
        ])->save();

        // La nouvelle adresse peut correspondre à des dossiers locataire que le
        // bailleur avait ouverts avec elle.
        $linker->link($user);

        return response()->json([
            'data' => new UserResource($user->refresh()->load('socialAccounts')),
            'message' => 'Votre adresse e-mail a été mise à jour.',
        ]);
    }

    public function cancelEmailChange(Request $request, EmailOtpService $otp): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['pending_email' => null])->save();
        $otp->clear($user);

        return response()->json([
            'data' => new UserResource($user->refresh()->load('socialAccounts')),
            'message' => 'Changement d\'adresse annulé.',
        ]);
    }

    /* ----------------------------------------------------------------------
     | Photo de profil
     |----------------------------------------------------------------------*/

    /**
     * Dépose ou remplace la photo de profil.
     *
     * Sur le disque privé des documents, jamais en URL publique : un portrait
     * est une donnée personnelle, et une URL devinable la rendrait accessible à
     * qui la devine.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:5120',
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ]);

        $user = $request->user();
        $disk = Storage::disk(config('immopro.documents.disk'));

        $previous = $user->avatar_path;

        $path = $request->file('file')->store("avatars/{$user->id}", [
            'disk' => config('immopro.documents.disk'),
        ]);

        $user->forceFill(['avatar_path' => $path])->save();

        // L'ancienne n'est effacée qu'une fois la nouvelle en place : une
        // suppression d'abord laisserait le compte sans photo si le dépôt
        // échouait.
        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }

        return response()->json([
            'data' => new UserResource($user->refresh()->load('socialAccounts')),
            'message' => 'Photo de profil enregistrée.',
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk(config('immopro.documents.disk'))->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return response()->json([
            'data' => new UserResource($user->refresh()->load('socialAccounts')),
            'message' => 'Photo de profil supprimée.',
        ]);
    }

    /** Sert la photo du compte connecté, en flux, sans jamais exposer son chemin. */
    public function avatar(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_if($user->avatar_path === null, 404);

        $disk = Storage::disk(config('immopro.documents.disk'));

        abort_unless($disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path);
    }
}
