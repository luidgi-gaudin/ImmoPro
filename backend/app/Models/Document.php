<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RlsProtected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Pièce jointe rattachée à un bail, un bien, un locataire ou un portefeuille.
 *
 * Le fichier lui-même vit sur un disque privé ; seul son chemin est en base, et
 * ce chemin ne sort jamais de l'API. Un document ne se télécharge que par une
 * URL signée à durée limitée, délivrée après vérification du droit d'accès.
 *
 * @property int $id
 * @property int $user_id
 * @property class-string $documentable_type
 * @property int $documentable_id
 * @property DocumentCategory $category
 * @property string $name
 * @property string $original_name
 * @property string $path
 * @property string $disk
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
class Document extends Model
{
    use Filterable, HasFactory, RlsProtected, SoftDeletes;

    /** @return list<string> */
    protected function searchable(): array
    {
        return ['name', 'original_name', 'notes'];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['name', 'category', 'issued_on', 'expires_on', 'size_bytes', 'created_at'];
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    /** @return array<int|string, string|\Closure> */
    protected function filters(): array
    {
        return [
            'category',
            'documentable_type' => function (Builder $query, string $value): void {
                $class = self::classForAlias($value);

                if ($class !== null) {
                    $query->where('documentable_type', $class);
                }
            },
            'documentable_id',

            // Pièces périmées ou sur le point de l'être : c'est la question que
            // se pose un gestionnaire devant une liste de documents.
            'expiration' => function (Builder $query, string $value): void {
                match ($value) {
                    'expire' => $query->whereNotNull('expires_on')->whereDate('expires_on', '<', now()),
                    'bientot' => $query->whereNotNull('expires_on')
                        ->whereDate('expires_on', '>=', now())
                        ->whereDate('expires_on', '<=', now()->addMonths(3)),
                    'valide' => $query->where(fn (Builder $inner) => $inner
                        ->whereNull('expires_on')
                        ->orWhereDate('expires_on', '>', now()->addMonths(3))),
                    default => null,
                };
            },
        ];
    }

    protected $fillable = [
        'user_id',
        'documentable_type',
        'documentable_id',
        'category',
        'name',
        'original_name',
        'path',
        'disk',
        'mime_type',
        'size_bytes',
        'issued_on',
        'expires_on',
        'notes',
    ];

    /**
     * Le chemin de stockage n'a aucune raison de circuler : le connaître ne
     * suffit pas à lire le fichier, mais il renseigne sur l'organisation
     * interne du disque.
     *
     * @var list<string>
     */
    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            // Sans ce transtypage, l'identifiant déposé par un formulaire
            // multipart repart en chaîne (« 1 ») alors que la même ligne relue
            // en base repart en entier : deux formes pour un même champ, et un
            // `===` côté client qui échoue selon d'où vient la donnée.
            'documentable_id' => 'integer',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Correspondance entre le mot employé dans les URL (« lease », « property »)
     * et la classe du modèle.
     *
     * La classe n'apparaît jamais dans une URL : exposer `App\Models\Lease`
     * renseignerait sur la structure interne, et accepter une classe fournie
     * par le client ouvrirait la porte à un rattachement arbitraire.
     *
     * @return array<string, class-string>
     */
    public static function attachableTypes(): array
    {
        return [
            'lease' => Lease::class,
            'property' => Property::class,
            'tenant' => Tenant::class,
            'portfolio' => Portfolio::class,
        ];
    }

    /** @return class-string|null */
    public static function classForAlias(string $alias): ?string
    {
        return self::attachableTypes()[$alias] ?? null;
    }

    public static function aliasForClass(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }

        return array_search($class, self::attachableTypes(), true) ?: null;
    }

    /** Vrai si la pièce a une date de fin de validité déjà passée. */
    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    /** Vrai si la pièce expire dans les trois mois. */
    public function expiresSoon(): bool
    {
        return $this->expires_on !== null
            && ! $this->expires_on->isPast()
            && $this->expires_on->lte(now()->addMonths(3));
    }

    /**
     * Efface le fichier en même temps que l'enregistrement.
     *
     * Uniquement sur une suppression définitive : la suppression réversible
     * doit rester réversible, ce qui suppose que le fichier soit encore là.
     */
    protected static function booted(): void
    {
        static::forceDeleted(function (Document $document): void {
            Storage::disk($document->disk)->delete($document->path);
        });
    }
}
