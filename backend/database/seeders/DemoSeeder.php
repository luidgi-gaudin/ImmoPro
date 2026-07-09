<?php

namespace Database\Seeders;

use App\Enums\Dpe;
use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Enums\PropertyType;
use App\Models\Alert;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Alerts\AlertScanner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Jeu de démonstration : un bailleur avec un patrimoine réaliste dont les
 * échéances déclenchent les 4 types d'alertes. Idempotent (réinitialise le
 * bailleur de démo à chaque exécution). À supprimer après la démo.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'demo@immopro.test';

        // ── Nettoyage idempotent du bailleur de démo ──
        if ($existing = User::where('email', $email)->first()) {
            Alert::where('user_id', $existing->id)->delete();
            foreach ($existing->portfolios()->with('properties.leases')->get() as $pf) {
                foreach ($pf->properties as $pr) {
                    RentPayment::whereIn('lease_id', $pr->leases()->withTrashed()->pluck('id'))->withTrashed()->forceDelete();
                    $pr->leases()->withTrashed()->forceDelete();
                    $pr->forceDelete();
                }
                $pf->delete();
            }
            $existing->tenants()->withTrashed()->forceDelete();
            $existing->forceDelete();
        }

        $today = Carbon::today();

        $user = User::factory()->create([
            'name' => 'Camille Bernard',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        $paris = Portfolio::create(['user_id' => $user->id, 'name' => 'Patrimoine Paris', 'description' => 'Appartements intra-muros']);
        $lyon = Portfolio::create(['user_id' => $user->id, 'name' => 'Résidences Lyon', 'description' => 'Biens rive gauche']);

        // ── Biens (dont deux avec DPE proche/dépassé) ──
        $p1 = Property::create([
            'portfolio_id' => $paris->id, 'title' => 'T2 Rue de Charonne', 'property_type' => PropertyType::cases()[0]->value,
            'address' => '48 rue de Charonne', 'city' => 'Paris', 'postal_code' => '75011',
            'dpe' => Dpe::D->value, 'dpe_date' => $today->copy()->subYears(10)->addMonths(2)->toDateString(), // expire dans ~2 mois
            'rooms' => 2, 'area_sqm' => 44, 'is_rented' => true, 'monthly_rent' => 1180,
        ]);
        $p2 = Property::create([
            'portfolio_id' => $paris->id, 'title' => 'Studio Oberkampf', 'property_type' => PropertyType::cases()[0]->value,
            'address' => '12 rue Oberkampf', 'city' => 'Paris', 'postal_code' => '75011',
            'dpe' => Dpe::F->value, 'dpe_date' => $today->copy()->subYears(10)->subMonths(1)->toDateString(), // DPE déjà expiré
            'rooms' => 1, 'area_sqm' => 26, 'is_rented' => true, 'monthly_rent' => 890,
        ]);
        $p3 = Property::create([
            'portfolio_id' => $lyon->id, 'title' => 'T3 Quai Saint-Antoine', 'property_type' => PropertyType::cases()[0]->value,
            'address' => '5 quai Saint-Antoine', 'city' => 'Lyon', 'postal_code' => '69002',
            'dpe' => Dpe::C->value, 'dpe_date' => $today->copy()->subYears(3)->toDateString(),
            'rooms' => 3, 'area_sqm' => 68, 'is_rented' => true, 'monthly_rent' => 1050,
        ]);

        // ── Locataires ──
        $t1 = Tenant::create(['user_id' => $user->id, 'first_name' => 'Julien', 'last_name' => 'Moreau', 'email' => 'julien.moreau@example.com', 'phone' => '06 12 34 56 78', 'country' => 'France', 'address' => '48 rue de Charonne, 75011 Paris']);
        $t2 = Tenant::create(['user_id' => $user->id, 'first_name' => 'Sophie', 'last_name' => 'Lefevre', 'email' => 'sophie.lefevre@example.com', 'phone' => '06 98 76 54 32', 'country' => 'France', 'address' => '12 rue Oberkampf, 75011 Paris']);
        $t3 = Tenant::create(['user_id' => $user->id, 'first_name' => 'Marc', 'last_name' => 'Petit', 'email' => 'marc.petit@example.com', 'phone' => '07 11 22 33 44', 'country' => 'France', 'address' => '5 quai Saint-Antoine, 69002 Lyon']);

        // ── Baux : échéances déclenchant fin de bail (3 mois) + révision IRL ──
        $leaseImpaye = Lease::create([
            'property_id' => $p1->id, 'tenant_id' => $t1->id, 'type' => LeaseType::Nu->value,
            'start_date' => $today->copy()->subMonths(14)->toDateString(), 'end_date' => $today->copy()->addMonths(22)->toDateString(),
            'monthly_rent' => 1180, 'charges' => 90, 'deposit' => 1180, 'payment_day' => 5, 'statut' => LeaseStatus::Actif->value,
        ]);
        $leaseFinBail = Lease::create([
            'property_id' => $p2->id, 'tenant_id' => $t2->id, 'type' => LeaseType::Meuble->value,
            'start_date' => $today->copy()->subMonths(10)->toDateString(), 'end_date' => $today->copy()->addMonths(2)->addDays(4)->toDateString(),
            'monthly_rent' => 890, 'charges' => 60, 'deposit' => 1780, 'payment_day' => 1, 'statut' => LeaseStatus::Actif->value,
        ]);
        $leaseRevision = Lease::create([
            'property_id' => $p3->id, 'tenant_id' => $t3->id, 'type' => LeaseType::Nu->value,
            'start_date' => $today->copy()->subYears(2)->toDateString(), 'end_date' => $today->copy()->addYears(1)->toDateString(),
            'monthly_rent' => 1050, 'charges' => 80, 'deposit' => 1050, 'payment_day' => 10, 'statut' => LeaseStatus::Actif->value,
            'last_rent_revision_at' => $today->copy()->subYear()->addDays(20)->toDateString(), // révision possible dans ~20 j
        ]);

        // ── Loyers : historique payé + un impayé du mois précédent ──
        foreach ([3, 2] as $monthsAgo) {
            $period = $today->copy()->subMonths($monthsAgo)->startOfMonth();
            RentPayment::create(['lease_id' => $leaseImpaye->id, 'period' => $period->toDateString(), 'amount_rent' => 1180, 'amount_charges' => 90, 'paid_at' => $period->copy()->addDays(3)->toDateString(), 'payment_method' => 'virement']);
        }
        // Loyer du mois dernier NON réglé -> alerte impayé (J+3 dépassé)
        RentPayment::create(['lease_id' => $leaseImpaye->id, 'period' => $today->copy()->subMonth()->startOfMonth()->toDateString(), 'amount_rent' => 1180, 'amount_charges' => 90, 'paid_at' => null]);

        RentPayment::create(['lease_id' => $leaseRevision->id, 'period' => $today->copy()->subMonth()->startOfMonth()->toDateString(), 'amount_rent' => 1050, 'amount_charges' => 80, 'paid_at' => $today->copy()->subMonth()->startOfMonth()->addDays(2)->toDateString(), 'payment_method' => 'virement']);

        // ── Génère les alertes proactives sur ce jeu de données ──
        app(AlertScanner::class)->scan();

        $this->command?->info("Bailleur de démo : {$email} / password — ".Alert::where('user_id', $user->id)->count().' alertes générées.');
    }
}
