<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantRequest;
use App\Models\Tenant;

class TenantController extends Controller
{
    public function index()
    {
        return auth()->user()->tenants;
    }

    public function store(TenantRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = auth()->id();

        return Tenant::create($data);
    }

    public function show(Tenant $tenant)
    {
        $this->authorize('view', $tenant);

        return $tenant;
    }

    public function update(TenantRequest $request, Tenant $tenant)
    {
        $this->authorize('update', $tenant);

        $tenant->update($request->validated());

        return $tenant;
    }

    public function destroy(Tenant $tenant)
    {
        $this->authorize('delete', $tenant);

        $tenant->delete();

        return response()->json();
    }
}
