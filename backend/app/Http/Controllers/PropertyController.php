<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index()
    {
        return Property::all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required'],
            'portfolio_id' => ['required', 'exists:portfolios'],
            'property_type' => ['required'],
            'address' => ['required'],
            'city' => ['required'],
            'postal_code' => ['required'],
            'latitude' => ['nullable', 'decimal:2'],
            'longitude' => ['nullable', 'decimal:2'],
            'dpe' => ['required'],
            'rooms' => ['nullable', 'integer'],
            'area_sqm' => ['nullable', 'decimal:2'],
            'has_balcony' => ['boolean'],
            'has_garden' => ['boolean'],
            'has_parking' => ['boolean'],
            'has_cave' => ['boolean'],
            'is_rented' => ['boolean'],
            'monthly_rent' => ['nullable', 'decimal:2'],
            'description' => ['nullable'],
        ]);

        return Property::create($data);
    }

    public function show(Property $property)
    {
        return $property;
    }

    public function update(Request $request, Property $property)
    {
        $data = $request->validate([
            'title' => ['required'],
            'portfolio_id' => ['required', 'exists:portfolios'],
            'property_type' => ['required'],
            'address' => ['required'],
            'city' => ['required'],
            'postal_code' => ['required'],
            'latitude' => ['nullable', 'decimal:2'],
            'longitude' => ['nullable', 'decimal:2'],
            'dpe' => ['required'],
            'rooms' => ['nullable', 'integer'],
            'area_sqm' => ['nullable', 'decimal:2'],
            'has_balcony' => ['boolean'],
            'has_garden' => ['boolean'],
            'has_parking' => ['boolean'],
            'has_cave' => ['boolean'],
            'is_rented' => ['boolean'],
            'monthly_rent' => ['nullable', 'decimal:2'],
            'description' => ['nullable'],
        ]);

        $property->update($data);

        return $property;
    }

    public function destroy(Property $property)
    {
        $property->delete();

        return response()->json();
    }
}
