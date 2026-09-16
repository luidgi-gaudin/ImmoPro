<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Passage d'une identité vérifiée chez Google ou Apple à un compte ImmoPro.
 *
 * Trois chemins, dans cet ordre :
 *
 *   1. le compte externe est déjà rattaché — on se connecte ;
 *   2. une adresse **attestée par le fournisseur** correspond à un compte
 *      existant — on rattache, puis on se connecte ;
 *   3. aucun des deux — on crée le compte, à condition de savoir quel profil.
 *
 * Le deuxième chemin est celui qui demande le plus de vigilance. Rattacher sur
 * la seule égalité des adresses reviendrait à donner un compte à quiconque
 * déclare l'adresse d'un autre chez un fournisseur qui ne la vérifie pas. C'est
 * la mention `email_verified` du jeton — pas la nôtre, celle du fournisseur —
 * qui autorise ce rapprochement, et son absence le bloque.
 */
class SocialAuthService
{
    /**
     * Connecte ou inscrit l'utilisateur correspondant à une identité vérifiée.
     *
     * @param  UserRole|null  $role  Profil choisi, obligatoire à la création
     *                               seulement : une connexion ne redemande pas
     *                               ce qui a déjà été choisi, et ne permet pas
     *                               non plus de le changer par ce biais.
     * @return array{0: User, 1: bool} Le compte, et s'il vient d'être créé.
     *
     * @throws ValidationException
     */
    public function resolve(VerifiedIdentity $identity, ?UserRole $role = null): array
    {
        $existing = SocialAccount::where('provider', $identity->provider->value)
            ->where('provider_user_id', $identity->subject)
            ->first();

        if ($existing !== null) {
            return [$this->touch($existing, $identity), false];
        }

        $byEmail = $this->matchByVerifiedEmail($identity);

        if ($byEmail !== null) {
            $this->link($byEmail, $identity);

            return [$byEmail, false];
        }

        $this->guardEmailCollision($identity);

        if ($role === null) {
            throw ValidationException::withMessages([
                'role' => 'Choisissez votre profil, propriétaire ou locataire, pour créer votre compte.',
            ])->status(422);
        }

        return [$this->create($identity, $role), true];
    }

    /**
     * Rattache un compte externe à un utilisateur déjà connecté.
     *
     * @throws ValidationException
     */
    public function link(User $user, VerifiedIdentity $identity): SocialAccount
    {
        $claimed = SocialAccount::where('provider', $identity->provider->value)
            ->where('provider_user_id', $identity->subject)
            ->first();

        if ($claimed !== null && $claimed->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'id_token' => "Ce compte {$identity->provider->label()} est déjà rattaché à un autre utilisateur.",
            ])->status(409);
        }

        return SocialAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => $identity->provider->value],
            [
                'provider_user_id' => $identity->subject,
                'provider_email' => $identity->email,
                'provider_name' => $identity->name,
                'avatar_url' => $identity->picture,
                'last_login_at' => now(),
            ]
        );
    }

    /**
     * Compte existant portant l'adresse attestée par le fournisseur.
     *
     * L'attestation est la condition, pas l'égalité des adresses.
     */
    private function matchByVerifiedEmail(VerifiedIdentity $identity): ?User
    {
        if (! $identity->emailVerified || blank($identity->email)) {
            return null;
        }

        return User::where('email', $identity->email)->first();
    }

    /**
     * Adresse déjà prise, mais non attestée par le fournisseur.
     *
     * Créer le compte échouerait sur la contrainte d'unicité, avec une erreur
     * de base incompréhensible. Le rattacher serait une prise de contrôle. Il
     * reste à le dire, et à indiquer le chemin sûr : se connecter par mot de
     * passe, puis rattacher depuis son profil.
     *
     * @throws ValidationException
     */
    private function guardEmailCollision(VerifiedIdentity $identity): void
    {
        if (blank($identity->email) || ! User::where('email', $identity->email)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Un compte existe déjà avec cette adresse. Connectez-vous avec votre mot de passe, '
                ."puis rattachez {$identity->provider->label()} depuis votre profil.",
        ])->status(409);
    }

    private function create(VerifiedIdentity $identity, UserRole $role): User
    {
        if (blank($identity->email)) {
            throw ValidationException::withMessages([
                'email' => 'Ce fournisseur n\'a transmis aucune adresse e-mail. '
                    .'Créez votre compte avec une adresse, puis rattachez-le depuis votre profil.',
            ])->status(422);
        }

        return DB::transaction(function () use ($identity, $role): User {
            $user = User::create([
                'name' => $identity->name ?: Str::before($identity->email, '@'),
                'email' => $identity->email,
                /*
                 * Aucun mot de passe : le compte se connecte par le
                 * fournisseur. Un condensat aléatoire aurait fait croire le
                 * contraire, et rendu impossible de savoir s'il reste un autre
                 * moyen d'entrer au moment de détacher Google ou Apple. La
                 * réinitialisation par courriel reste ouverte, et l'écran de
                 * profil propose d'en définir un.
                 */
                'password' => null,
                'role' => $role->value,
            ]);

            /*
             * L'adresse est réputée vérifiée quand le fournisseur l'atteste :
             * redemander un code à usage unique après une authentification
             * Google reviendrait à faire prouver deux fois la même chose.
             */
            if ($identity->emailVerified) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->link($user, $identity);

            return $user;
        });
    }

    private function touch(SocialAccount $account, VerifiedIdentity $identity): User
    {
        $account->forceFill([
            'provider_email' => $identity->email ?? $account->provider_email,
            'provider_name' => $identity->name ?? $account->provider_name,
            'avatar_url' => $identity->picture ?? $account->avatar_url,
            'last_login_at' => now(),
        ])->save();

        return $account->user()->firstOrFail();
    }
}
