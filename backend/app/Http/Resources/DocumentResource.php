<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Représentation d'une pièce jointe côté API.
 *
 * Ni le chemin ni le disque ne sortent : seul un lien d'aperçu signé, valable
 * quelques minutes, permet d'atteindre le fichier — et il est régénéré à chaque
 * lecture, donc toujours frais au moment où l'écran s'ouvre.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /** Les listes de l'API sont à plat ; les documents ne font pas exception. */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;

        return [
            'id' => $document->id,
            'category' => $document->category->value,
            'category_label' => $document->category->label(),
            'name' => $document->name,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'size_label' => $this->humanSize($document->size_bytes),
            'issued_on' => $document->issued_on?->toDateString(),
            'expires_on' => $document->expires_on?->toDateString(),
            'is_expired' => $document->isExpired(),
            'expires_soon' => $document->expiresSoon(),
            'notes' => $document->notes,

            'attached_to' => [
                'type' => Document::aliasForClass($document->documentable_type),
                'id' => $document->documentable_id,
                'label' => $document->getAttribute('documentable_label'),
            ],

            // Téléchargement : passe par l'API, donc par le jeton. Aperçu :
            // consommé par une balise <img> ou <iframe>, qui ne peut pas porter
            // d'en-tête d'autorisation, d'où le lien signé.
            'download_url' => route('documents.download', $document),
            'preview_url' => $this->previewable($document->mime_type)
                ? URL::temporarySignedRoute(
                    'documents.preview',
                    now()->addMinutes((int) config('immopro.documents.link_ttl_minutes')),
                    ['documentId' => $document->id, 'as' => $document->user_id]
                )
                : null,

            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    /** Seuls les formats qu'un navigateur sait afficher méritent un aperçu. */
    private function previewable(string $mime): bool
    {
        return $mime === 'application/pdf' || str_starts_with($mime, 'image/');
    }

    /**
     * « 2,4 Mo » plutôt que « 2517483 ». Le calcul est fait ici pour que
     * l'unité soit la même partout dans l'application.
     */
    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' o';
        }

        $units = ['ko', 'Mo', 'Go'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return str_replace('.', ',', (string) round($value, $value < 10 ? 1 : 0)).' '.$units[$unit];
    }
}
