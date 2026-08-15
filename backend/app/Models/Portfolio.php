<?php

namespace App\Models;

use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    use Filterable, HasFactory;

    protected $table = 'portfolios';

    protected $fillable = [
        'name',
        'description',
        'user_id',
    ];

    /** @return list<string> */
    protected function searchable(): array
    {
        return ['name', 'description'];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['name', 'created_at'];
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    protected function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
