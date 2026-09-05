<?php

namespace Tests\Unit;

use App\Enums\LeaseType;
use PHPUnit\Framework\TestCase;

/**
 * Plafonds et durées codifiés par la loi n° 89-462 du 6 juillet 1989.
 */
class LeaseTypeTest extends TestCase
{
    public function test_deposit_caps_follow_the_law(): void
    {
        $this->assertSame(1, LeaseType::Nu->depositCapInMonths());       // art. 22
        $this->assertSame(2, LeaseType::Meuble->depositCapInMonths());   // art. 25-6
        $this->assertSame(2, LeaseType::Etudiant->depositCapInMonths()); // art. 25-6
        $this->assertSame(0, LeaseType::Mobilite->depositCapInMonths()); // art. 25-13 : interdit
    }

    /** Durée que la loi pose par défaut, et à laquelle elle admet des exceptions. */
    public function test_standard_durations_follow_the_law(): void
    {
        $this->assertSame(36, LeaseType::Nu->standardDurationInMonths());      // art. 10
        $this->assertSame(12, LeaseType::Meuble->standardDurationInMonths());  // art. 25-7
        $this->assertSame(9, LeaseType::Etudiant->standardDurationInMonths()); // art. 25-7
        // Le bail mobilité n'a pas de durée de référence, seulement un intervalle.
        $this->assertNull(LeaseType::Mobilite->standardDurationInMonths());
    }

    /**
     * Plancher absolu, à ne pas confondre avec la durée de droit commun : un
     * bail vide peut descendre à un an pour un motif de reprise (art. 11).
     */
    public function test_absolute_floors_follow_the_law(): void
    {
        $this->assertSame(12, LeaseType::Nu->floorDurationInMonths());       // art. 11
        $this->assertSame(12, LeaseType::Meuble->floorDurationInMonths());   // art. 25-7
        $this->assertSame(9, LeaseType::Etudiant->floorDurationInMonths());  // art. 25-7
        $this->assertSame(1, LeaseType::Mobilite->floorDurationInMonths());  // art. 25-12
    }

    public function test_maximum_durations_follow_the_law(): void
    {
        $this->assertNull(LeaseType::Nu->maxDurationInMonths());
        $this->assertNull(LeaseType::Meuble->maxDurationInMonths());
        // Neuf mois est la durée réduite autorisée, pas une valeur imposée :
        // au-delà d'un an on relève du meublé ordinaire.
        $this->assertSame(12, LeaseType::Etudiant->maxDurationInMonths());   // art. 25-7
        $this->assertSame(10, LeaseType::Mobilite->maxDurationInMonths());   // art. 25-12
    }

    /** Le plancher ne peut jamais dépasser la durée de droit commun. */
    public function test_the_floor_never_exceeds_the_standard_duration(): void
    {
        foreach (LeaseType::cases() as $type) {
            $standard = $type->standardDurationInMonths();

            if ($standard !== null) {
                $this->assertLessThanOrEqual($standard, $type->floorDurationInMonths());
            }
        }
    }

    public function test_every_type_has_a_label(): void
    {
        foreach (LeaseType::cases() as $type) {
            $this->assertNotSame('', $type->label());
        }
    }
}
