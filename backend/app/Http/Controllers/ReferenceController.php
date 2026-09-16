<?php

namespace App\Http\Controllers;

use App\Enums\DocumentCategory;
use App\Enums\Dpe;
use App\Enums\Ges;
use App\Enums\GuaranteeType;
use App\Enums\IdentityDocumentType;
use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Enums\OccupancyStatus;
use App\Enums\OwnershipType;
use App\Enums\PropertyType;
use App\Enums\RentPaymentStatus;
use App\Enums\SocialProvider;
use App\Enums\UserRole;
use Illuminate\Http\JsonResponse;

/**
 * Catalogue des valeurs fermées de l'application.
 *
 * Un seul appel, en cache côté client, plutôt que des listes recopiées dans le
 * front. Ces listes ont une fâcheuse tendance à diverger : on ajoute « studio »
 * côté serveur, le menu déroulant continue de proposer trois choix, et
 * l'anomalie n'apparaît qu'au moment où quelqu'un cherche pourquoi son studio
 * est enregistré comme un appartement.
 *
 * Volontairement public : ce ne sont que des libellés, affichés notamment sur
 * le formulaire d'inscription, qui précède toute authentification.
 */
class ReferenceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'user_roles' => UserRole::catalogue(),
                'social_providers' => SocialProvider::catalogue(),

                'property_types' => PropertyType::catalogue(),
                'occupancy_statuses' => OccupancyStatus::catalogue(),
                'ownership_types' => $this->labelled(OwnershipType::cases()),

                'energy_classes' => array_map(
                    fn (Dpe $class) => ['value' => $class->value, 'label' => $class->value],
                    Dpe::cases()
                ),
                'ges_classes' => array_map(
                    fn (Ges $class) => ['value' => $class->value, 'label' => $class->value],
                    Ges::cases()
                ),

                'lease_types' => $this->leaseTypes(),
                'lease_statuses' => $this->plain(LeaseStatus::cases()),
                'payment_statuses' => $this->plain(RentPaymentStatus::cases()),

                'guarantee_types' => GuaranteeType::catalogue(),
                'identity_document_types' => IdentityDocumentType::catalogue(),

                'document_categories' => DocumentCategory::catalogue(),
            ],
        ]);
    }

    /**
     * Les types de bail voyagent avec leurs bornes légales.
     *
     * Le formulaire peut ainsi annoncer « de 1 à 10 mois » avant la saisie,
     * plutôt que de laisser remplir puis refuser. Les valeurs restent
     * revalidées côté serveur : ce qui part d'ici est une aide, pas un contrôle.
     *
     * @return list<array<string, mixed>>
     */
    private function leaseTypes(): array
    {
        return array_map(fn (LeaseType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'deposit_cap_months' => $type->depositCapInMonths(),
            'standard_duration_months' => $type->standardDurationInMonths(),
            'floor_duration_months' => $type->floorDurationInMonths(),
            'max_duration_months' => $type->maxDurationInMonths(),
        ], LeaseType::cases());
    }

    /**
     * @param  list<OwnershipType>  $cases
     * @return list<array<string, string>>
     */
    private function labelled(array $cases): array
    {
        return array_map(fn (OwnershipType $case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }

    /**
     * Énumérations sans libellé propre : la valeur fait office d'étiquette,
     * mise en forme côté client.
     *
     * @param  list<LeaseStatus|RentPaymentStatus>  $cases
     * @return list<array<string, string>>
     */
    private function plain(array $cases): array
    {
        return array_map(fn (LeaseStatus|RentPaymentStatus $case) => [
            'value' => $case->value,
            'label' => ucfirst(str_replace('_', ' ', $case->value)),
        ], $cases);
    }
}
