<?php

namespace App\Http\Controllers;

use App\Enums\Dpe;
use App\Enums\PropertyType;
use App\Models\Portfolio;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PropertyController extends Controller
{
    public function index(Portfolio $portfolio)
    {
        $this->authorize('view', $portfolio);

        return $portfolio->properties()->get();
    }

    public function store(Request $request, Portfolio $portfolio)
    {
        $this->authorize('update', $portfolio);

        $data = $request->validate([
            'title'         => ['required', 'string'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'address'       => ['required', 'string'],
            'city'          => ['required', 'string'],
            'postal_code'   => ['required', 'string'],
            'latitude'      => ['nullable', 'numeric'],
            'longitude'     => ['nullable', 'numeric'],
            'dpe'           => ['required', Rule::enum(Dpe::class)],
            'rooms'         => ['nullable', 'integer'],
            'area_sqm'      => ['nullable', 'numeric'],
            'has_balcony'   => ['boolean'],
            'has_garden'    => ['boolean'],
            'has_parking'   => ['boolean'],
            'has_cave'      => ['boolean'],
            'is_rented'     => ['boolean'],
            'monthly_rent'  => ['nullable', 'numeric'],
            'description'   => ['nullable', 'string'],
        ]);

        return $portfolio->properties()->create($data);
    }

    public function show(Portfolio $portfolio, Property $property)
    {
        $this->authorize('view', $portfolio);

        return $property;
    }

    public function update(Request $request, Portfolio $portfolio, Property $property)
    {
        $this->authorize('update', $portfolio);

        $data = $request->validate([
            'title'         => ['required', 'string'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'address'       => ['required', 'string'],
            'city'          => ['required', 'string'],
            'postal_code'   => ['required', 'string'],
            'latitude'      => ['nullable', 'numeric'],
            'longitude'     => ['nullable', 'numeric'],
            'dpe'           => ['required', Rule::enum(Dpe::class)],
            'rooms'         => ['nullable', 'integer'],
            'area_sqm'      => ['nullable', 'numeric'],
            'has_balcony'   => ['boolean'],
            'has_garden'    => ['boolean'],
            'has_parking'   => ['boolean'],
            'has_cave'      => ['boolean'],
            'is_rented'     => ['boolean'],
            'monthly_rent'  => ['nullable', 'numeric'],
            'description'   => ['nullable', 'string'],
        ]);

        $property->update($data);

        return $property;
    }

    public function destroy(Portfolio $portfolio, Property $property)
    {
        $this->authorize('delete', $portfolio);

        $property->delete();

        return response()->json();
    }
}
