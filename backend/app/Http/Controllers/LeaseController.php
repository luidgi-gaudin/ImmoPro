<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaseRequest;
use App\Models\Lease;

class LeaseController extends Controller
{
    public function index()
    {
        $propertyIds = auth()->user()
            ->portfolios()
            ->with('properties')
            ->get()
            ->pluck('properties')
            ->flatten()
            ->pluck('id');

        return Lease::whereIn('property_id', $propertyIds)->get();
    }

    public function store(LeaseRequest $request)
    {
        return Lease::create($request->validated());
    }

    public function show(Lease $lease)
    {
        $this->authorize('view', $lease);

        return $lease;
    }

    public function update(LeaseRequest $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $lease->update($request->validated());

        return $lease;
    }

    public function destroy(Lease $lease)
    {
        $this->authorize('delete', $lease);

        $lease->delete();

        return response()->json();
    }
}
