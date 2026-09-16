<?php

namespace App\Http\Controllers;

use App\Enums\AlertType;
use App\Enums\DocumentCategory;
use App\Http\Requests\DocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Notifications\TenantNotifier;
use App\Support\Rls;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pièces jointes de la gestion locative.
 *
 * Un même écran sert les quatre entités auxquelles un document peut se
 * rattacher — bail, bien, locataire, portefeuille — et la même liste sert de
 * coffre général. C'est ce qui permet de répondre aussi bien à « les documents
 * de ce bail » qu'à « toutes mes attestations d'assurance qui expirent ».
 */
class DocumentController extends Controller
{
    /**
     * Liste filtrable, en une seule requête SQL.
     *
     * Le libellé de l'élément rattaché est ramené par une expression `case`
     * plutôt que par `with('documentable')` : une relation polymorphe chargée
     * en relation déclenche une requête *par type* présent dans la page.
     */
    public function index(Request $request)
    {
        $query = Document::query()
            ->where('user_id', $request->user()->id)
            // `select` explicite avant toute expression ajoutée : sans lui, la
            // colonne calculée remplacerait les colonnes du modèle au lieu de
            // s'y ajouter, et la ressource ne recevrait qu'un libellé.
            ->select('documents.*')
            ->selectRaw($this->documentableLabel().' as documentable_label')
            ->filtered($request);

        return $this->paginate($query, $request)
            ->through(fn (Document $document) => new DocumentResource($document));
    }

    /**
     * Catalogue des catégories, de leurs entités compatibles et de leur durée
     * de validité légale.
     *
     * Aucune requête : tout vient de l'énumération. Les en-têtes de cache
     * évitent au front de le redemander à chaque navigation — c'est une donnée
     * qui ne change qu'avec une mise en production.
     */
    public function categories(): JsonResponse
    {
        return response()
            ->json([
                'categories' => DocumentCategory::catalogue(),
                'max_size_kb' => (int) config('immopro.documents.max_size_kb'),
                'accepted_mimes' => config('immopro.documents.mimes'),
            ])
            ->header('Cache-Control', 'private, max-age=86400');
    }

    public function store(DocumentRequest $request, TenantNotifier $notifier): JsonResponse
    {
        $file = $request->file('file');
        $category = DocumentCategory::from($request->validated('category'));
        $class = Document::classForAlias($request->validated('documentable_type'));

        $disk = (string) config('immopro.documents.disk');

        // Le nom d'origine ne sert jamais de nom de fichier : il peut contenir
        // des séparateurs de chemin, des caractères réservés, ou simplement
        // entrer en collision avec un dépôt précédent.
        $path = $file->store(
            sprintf('%d/%s', $request->user()->id, Str::of($class)->classBasename()->lower()),
            $disk
        );

        $document = Document::create([
            'user_id' => $request->user()->id,
            'documentable_type' => $class,
            'documentable_id' => $request->validated('documentable_id'),
            'category' => $category,
            'name' => $request->validated('name') ?: pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'issued_on' => $request->validated('issued_on'),
            'expires_on' => $request->validated('expires_on') ?: $this->defaultExpiry($category, $request->validated('issued_on')),
            'notes' => $request->validated('notes'),
        ]);

        $this->notifyTenant($document, $notifier);

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    /**
     * Prévient le locataire d'une pièce déposée dans son dossier.
     *
     * Une quittance déposée sans que personne ne le sache n'a servi à rien :
     * le locataire continue de la réclamer, le bailleur croit l'avoir fournie.
     *
     * Seules les pièces rattachées à un bail ou à une fiche locataire sont
     * concernées : un diagnostic rangé sur le bien peut relever de la gestion
     * du bailleur, et l'annoncer à chaque dépôt transformerait la boîte du
     * locataire en journal d'activité.
     */
    private function notifyTenant(Document $document, TenantNotifier $notifier): void
    {
        $tenant = match ($document->documentable_type) {
            Tenant::class => Tenant::find($document->documentable_id),
            Lease::class => Lease::with('tenant')->find($document->documentable_id)?->tenant,
            default => null,
        };

        if ($tenant === null) {
            return;
        }

        $isReceipt = $document->category === DocumentCategory::Quittance;

        $notifier->send(
            tenant: $tenant,
            type: $isReceipt ? AlertType::QuittanceDisponible : AlertType::DocumentPartage,
            subject: $isReceipt
                ? 'Une nouvelle quittance est disponible'
                : 'Une pièce a été ajoutée à votre dossier',
            body: sprintf('« %s » (%s) est consultable depuis votre espace.',
                $document->name,
                $document->category->label(),
            ),
            about: $document,
            dedupKey: 'document_partage:document:'.$document->id,
        );
    }

    /**
     * Modifie les métadonnées. Le fichier lui-même ne se remplace pas : on
     * dépose une nouvelle version et on archive l'ancienne, pour que
     * l'historique d'un bail reste vérifiable.
     */
    public function update(DocumentRequest $request, Document $document): DocumentResource
    {
        $this->authorize('update', $document);

        $document->update($request->safe()->only(['name', 'issued_on', 'expires_on', 'notes', 'category']));

        return new DocumentResource($document->refresh());
    }

    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        // Suppression réversible : le fichier reste sur le disque tant que
        // l'enregistrement n'est pas définitivement effacé.
        $document->delete();

        return response()->json();
    }

    /** Téléchargement authentifié, en pièce jointe. */
    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        return $this->stream($document, 'attachment');
    }

    /**
     * Aperçu dans le navigateur, atteint par une URL signée à durée limitée.
     *
     * Une balise `<img>` ou `<iframe>` ne peut pas porter d'en-tête
     * d'autorisation : sans lien signé, il faudrait soit rendre le fichier
     * public, soit renoncer à l'aperçu. La signature vaut autorisation, et
     * expire en quelques minutes.
     *
     * La requête n'étant pas authentifiée, aucune identité n'est posée pour la
     * Row Level Security et la base ne renverrait aucune ligne. Le bailleur est
     * donc transporté dans l'URL — sous la protection de la signature, qui
     * couvre tous les paramètres : le modifier invalide le lien. C'est aussi
     * pourquoi le document est cherché explicitement dans son périmètre, et non
     * par liaison automatique.
     */
    public function preview(Request $request, int $documentId): StreamedResponse
    {
        $owner = (int) $request->query('as');

        Rls::bind($owner);

        $document = Document::where('user_id', $owner)->findOrFail($documentId);

        return $this->stream($document, 'inline');
    }

    private function stream(Document $document, string $disposition): StreamedResponse
    {
        $storage = Storage::disk($document->disk);

        abort_unless($storage->exists($document->path), 404, 'Le fichier n\'est plus disponible.');

        return $storage->response(
            $document->path,
            $document->original_name,
            [
                'Content-Type' => $document->mime_type,
                // Empêche un navigateur d'interpréter le fichier autrement que
                // selon le type annoncé — un HTML déposé ne doit pas s'exécuter.
                'X-Content-Type-Options' => 'nosniff',
            ],
            $disposition
        );
    }

    /**
     * Fin de validité déduite de la catégorie quand elle n'est pas fournie :
     * dix ans pour un DPE, un an pour une attestation d'assurance. Le
     * gestionnaire garde la main, mais n'a rien à ressaisir dans le cas
     * courant.
     */
    private function defaultExpiry(DocumentCategory $category, ?string $issuedOn): ?string
    {
        $months = $category->validityMonths();

        if ($months === null || $issuedOn === null) {
            return null;
        }

        return CarbonImmutable::parse($issuedOn)->addMonths($months)->toDateString();
    }

    /**
     * Libellé lisible de l'élément rattaché, calculé en SQL.
     *
     * Les noms de classe viennent de constantes PHP, jamais d'une saisie.
     */
    private function documentableLabel(): string
    {
        $lease = Lease::class;
        $property = Property::class;
        $tenant = Tenant::class;
        $portfolio = Portfolio::class;

        return "case documents.documentable_type
                    when '{$lease}' then (select 'Bail — ' || coalesce(p.title, 'bien')
                                            from leases l
                                            join properties p on p.id = l.property_id
                                           where l.id = documents.documentable_id)
                    when '{$property}' then (select p.title from properties p
                                              where p.id = documents.documentable_id)
                    when '{$tenant}' then (select trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, ''))
                                             from tenants t where t.id = documents.documentable_id)
                    when '{$portfolio}' then (select pf.name from portfolios pf
                                                where pf.id = documents.documentable_id)
                end";
    }
}
