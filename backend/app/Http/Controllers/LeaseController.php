<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaseRequest;
use App\Models\Lease;

class LeaseController extends Controller
{
    public function index()
    {
        return Lease::all();
    }

    public function store(LeaseRequest $request)
    {
        return Lease::create($request->validated());
    }

    public function show(Lease $lease)
    {
        return $lease;
    }

    public function update(LeaseRequest $request, Lease $lease)
    {
        $lease->update($request->validated());

        return $lease;
    }

    public function destroy(Lease $lease)
    {
        $lease->delete();

        return response()->json();
    }
}
