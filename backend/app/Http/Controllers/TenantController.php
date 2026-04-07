<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantRequest;
use App\Models\Tenant;

class TenantController extends Controller
{
    public function index()
    {
        return Tenant::all();
    }

    public function store(TenantRequest $request)
    {
        return Tenant::create($request->validated());
    }

    public function show(Tenant $tenant)
    {
        return $tenant;
    }

    public function update(TenantRequest $request, Tenant $tenant)
    {
        $tenant->update($request->validated());

        return $tenant;
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();

        return response()->json();
    }
}
