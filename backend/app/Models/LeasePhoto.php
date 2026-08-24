<?php

namespace App\Models;

use App\Models\Concerns\RlsProtected;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LeasePhoto extends Model
{
    use RlsProtected;

    protected $fillable = [
        'lease_id',
        'type',
        'path',
        'original_name',
    ];

    protected $appends = ['url'];

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
