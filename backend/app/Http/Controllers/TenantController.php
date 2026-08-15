<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantRequest;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        return auth()->user()->tenants()
            ->filtered($request)
            ->paginate($this->perPage($request))
            // Sans cela, les liens de pagination perdent la recherche et les
            // filtres, et la page 2 réaffiche la liste complète.
            ->withQueryString();
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
