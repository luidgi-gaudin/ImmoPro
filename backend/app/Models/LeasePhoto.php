<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LeasePhoto extends Model
{
    protected $fillable = [
        'lease_id',
        'type',
        'path',
        'original_name',
    ];

    protected $appends = ['url'];

    public function lease()
    {
        return $this->belongsTo(Lease::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
