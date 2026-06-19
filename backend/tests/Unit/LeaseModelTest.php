<?php

namespace Tests\Unit;

use App\Models\Lease;
use Tests\TestCase;

class LeaseModelTest extends TestCase
{
    public function test_deposit_cap_is_one_month_for_a_bail_nu(): void
    {
        $lease = new Lease(['type' => 'nu', 'monthly_rent' => 800]);

        $this->assertSame(800.0, $lease->depositCap());
    }

    public function test_deposit_cap_is_two_months_for_a_bail_meuble(): void
    {
        $lease = new Lease(['type' => 'meuble', 'monthly_rent' => 800]);

        $this->assertSame(1600.0, $lease->depositCap());
    }

    public function test_deposit_cap_is_zero_for_a_bail_mobilite(): void
    {
        $lease = new Lease(['type' => 'mobilite', 'monthly_rent' => 800]);

        $this->assertSame(0.0, $lease->depositCap());
    }

    public function test_rent_can_be_revised_when_never_revised(): void
    {
        $lease = new Lease;

        $this->assertTrue($lease->canReviseRent());
    }

    public function test_rent_can_be_revised_after_twelve_months(): void
    {
        $lease = new Lease;
        $lease->forceFill(['last_rent_revision_at' => now()->subMonths(13)]);

        $this->assertTrue($lease->canReviseRent());
    }

    public function test_rent_cannot_be_revised_twice_within_a_year(): void
    {
        $lease = new Lease;
        $lease->forceFill(['last_rent_revision_at' => now()->subMonths(6)]);

        $this->assertFalse($lease->canReviseRent());
    }
}
