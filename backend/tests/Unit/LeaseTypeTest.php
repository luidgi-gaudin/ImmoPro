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

    public function test_minimum_durations_follow_the_law(): void
    {
        $this->assertSame(36, LeaseType::Nu->minDurationInMonths());      // art. 10
        $this->assertSame(12, LeaseType::Meuble->minDurationInMonths());  // art. 25-7
        $this->assertSame(9, LeaseType::Etudiant->minDurationInMonths()); // art. 25-7
        $this->assertSame(1, LeaseType::Mobilite->minDurationInMonths()); // art. 25-12
    }

    public function test_maximum_durations_follow_the_law(): void
    {
        $this->assertNull(LeaseType::Nu->maxDurationInMonths());
        $this->assertNull(LeaseType::Meuble->maxDurationInMonths());
        $this->assertSame(9, LeaseType::Etudiant->maxDurationInMonths());   // art. 25-7
        $this->assertSame(10, LeaseType::Mobilite->maxDurationInMonths());  // art. 25-12
    }

    public function test_every_type_has_a_label(): void
    {
        foreach (LeaseType::cases() as $type) {
            $this->assertNotSame('', $type->label());
        }
    }
}
