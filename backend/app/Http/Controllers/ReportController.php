<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Enums\RentPaymentStatus;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\RentPayment;
use App\Models\Tenant;

class ReportController extends Controller
{
    /**
     * Rapport agrégé pour le bailleur : vue d'ensemble du patrimoine, des baux et des
     * paiements du mois en cours, ainsi qu'une ventilation par bien et par locataire.
     */
    public function overview()
    {
        $user = auth()->user();

        $portfolios = Portfolio::where('user_id', $user->id)->with('properties')->get();
        $properties = $portfolios->pluck('properties')->flatten();
        $propertyIds = $properties->pluck('id');

        $leases = Lease::whereIn('property_id', $propertyIds)->with(['tenant', 'property'])->get();
        $activeLeases = $leases->where('statut', LeaseStatus::Actif);
        $leaseIds = $leases->pluck('id');

        $payments = RentPayment::whereIn('lease_id', $leaseIds)->with('lease')->get();
        $paymentsThisMonth = $payments->filter(fn (RentPayment $p) => $p->period->isSameMonth(now()));

        $tenants = Tenant::where('user_id', $user->id)->get();

        return response()->json([
            'bailleur' => [
                'total_portfolios' => $portfolios->count(),
                'total_properties' => $properties->count(),
                'occupied_properties' => $properties->where('is_rented', true)->count(),
                'vacant_properties' => $properties->where('is_rented', false)->count(),
                'active_leases' => $activeLeases->count(),
                'monthly_rent_expected' => round((float) $activeLeases->sum('monthly_rent'), 2),
                'this_month' => [
                    'paid_amount' => round($paymentsThisMonth->filter(fn ($p) => $p->status === RentPaymentStatus::Paye)->sum('total'), 2),
                    'paid_count' => $paymentsThisMonth->filter(fn ($p) => $p->status === RentPaymentStatus::Paye)->count(),
                    'late_amount' => round($paymentsThisMonth->filter(fn ($p) => $p->status === RentPaymentStatus::EnRetard)->sum('total'), 2),
                    'late_count' => $paymentsThisMonth->filter(fn ($p) => $p->status === RentPaymentStatus::EnRetard)->count(),
                    'pending_amount' => round($paymentsThisMonth->filter(fn ($p) => $p->status === RentPaymentStatus::EnAttente)->sum('total'), 2),
                    'pending_count' => $paymentsThisMonth->filter(fn ($p) => $p->status === RentPaymentStatus::EnAttente)->count(),
                ],
            ],

            'par_bien' => $properties->map(function ($property) use ($activeLeases) {
                $lease = $activeLeases->firstWhere('property_id', $property->id);

                return [
                    'id' => $property->id,
                    'title' => $property->title,
                    'portfolio_name' => $property->portfolio?->name,
                    'is_rented' => $property->is_rented,
                    'monthly_rent' => (float) ($lease?->monthly_rent ?? $property->monthly_rent ?? 0),
                    'tenant_name' => $lease?->tenant ? "{$lease->tenant->first_name} {$lease->tenant->last_name}" : null,
                ];
            })->values(),

            'par_locataire' => $tenants->map(function (Tenant $tenant) use ($leases, $payments) {
                $tenantLeaseIds = $leases->where('tenant_id', $tenant->id)->pluck('id');
                $tenantPayments = $payments->whereIn('lease_id', $tenantLeaseIds);

                return [
                    'id' => $tenant->id,
                    'name' => "{$tenant->first_name} {$tenant->last_name}",
                    'total_paid' => round($tenantPayments->filter(fn ($p) => $p->status === RentPaymentStatus::Paye)->sum('total'), 2),
                    'total_due' => round($tenantPayments->filter(fn ($p) => $p->status !== RentPaymentStatus::Paye)->sum('total'), 2),
                    'late_count' => $tenantPayments->filter(fn ($p) => $p->status === RentPaymentStatus::EnRetard)->count(),
                    'active_lease_id' => $leases->firstWhere(fn ($l) => $l->tenant_id === $tenant->id && $l->statut === LeaseStatus::Actif)?->id,
                ];
            })->values(),
        ]);
    }
}
