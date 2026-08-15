<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Alert extends Model
{
    use Concerns\Filterable, HasFactory;

    /** @return list<string> */
    protected function searchable(): array
    {
        return ['title', 'message'];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['created_at', 'due_date', 'severity'];
    }

    /**
     * Trier « severity » alphabétiquement donnerait critical, info, warning :
     * l'information passerait avant ce qui est à prévoir. On impose l'ordre
     * métier. CASE WHEN est du SQL standard, compris par PostgreSQL comme par
     * le SQLite des tests.
     *
     * @return array<string, string>
     */
    protected function sortExpressions(): array
    {
        return [
            'severity' => "case severity when 'critical' then 0 when 'warning' then 1 else 2 end",
        ];
    }

    /**
     * Une liste d'alertes se lit par urgence, pas par date : le plus grave
     * d'abord.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function defaultSort(): array
    {
        return ['severity', 'asc'];
    }

    /** @return array<int|string, string|\Closure> */
    protected function filters(): array
    {
        return [
            'type',
            'severity',
            // Permet à la pastille de la barre de navigation de connaître le
            // nombre exact de non-lues via ?unread=1&per_page=1 : le total du
            // paginateur suffit, sans rapatrier une seule alerte.
            'unread' => function (Builder $query, string $value): void {
                if (in_array($value, ['1', 'true'], true)) {
                    $query->whereNull('read_at');
                }
            },
        ];
    }

    protected $fillable = [
        'user_id',
        'type',
        'severity',
        'alertable_type',
        'alertable_id',
        'dedup_key',
        'title',
        'message',
        'due_date',
        'meta',
        'read_at',
        'reminded_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'due_date' => 'date',
            'meta' => 'array',
            'read_at' => 'datetime',
            'reminded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alertable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Restreint aux alertes d'un bailleur (isolation stricte des données). */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** Alertes non résolues (encore d'actualité). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
