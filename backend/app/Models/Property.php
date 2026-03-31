<?php

namespace App\Models;

use App\Enums\Dpe;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    protected $fillable = [
        'title',
        'portfolio_id',
        'property_type',
        'address',
        'city',
        'postal_code',
        'latitude',
        'longitude',
        'dpe',
        'rooms',
        'area_sqm',
        'has_balcony',
        'has_garden',
        'has_parking',
        'has_cave',
        'is_rented',
        'monthly_rent',
        'description',
    ];

    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class);
    }

    protected function casts()
    {
        return [
            'property_type' => PropertyType::class,
            'dpe'           => Dpe::class,
            'has_balcony' => 'boolean',
            'has_garden' => 'boolean',
            'has_parking' => 'boolean',
            'has_cave' => 'boolean',
            'is_rented' => 'boolean',
            'monthly_rent' => 'decimal:2',
            'area_sqm' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7'
        ];
    }
}
