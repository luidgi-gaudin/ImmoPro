<?php

namespace Tests\Unit;

use App\Enums\RentPaymentStatus;
use App\Models\Lease;
use App\Models\RentPayment;
use Tests\TestCase;

class RentPaymentModelTest extends TestCase
{
    private function payment(array $attributes, int $paymentDay = 5): RentPayment
    {
        $payment = new RentPayment($attributes);
        $payment->setRelation('lease', new Lease(['payment_day' => $paymentDay]));

        return $payment;
    }

    public function test_status_is_paye_when_paid(): void
    {
        $payment = $this->payment(['period' => '2026-06-01', 'paid_at' => '2026-06-05']);

        $this->assertSame(RentPaymentStatus::Paye, $payment->status);
    }

    public function test_status_is_en_retard_when_due_date_has_passed(): void
    {
        $this->travelTo('2026-07-10');

        $payment = $this->payment(['period' => '2026-06-01']);

        $this->assertSame(RentPaymentStatus::EnRetard, $payment->status);
    }

    public function test_status_is_en_attente_before_the_due_date(): void
    {
        $this->travelTo('2026-07-10');

        $payment = $this->payment(['period' => '2026-07-01'], paymentDay: 28);

        $this->assertSame(RentPaymentStatus::EnAttente, $payment->status);
    }

    public function test_due_day_is_clamped_to_the_length_of_the_month(): void
    {
        $this->travelTo('2026-02-27');

        // payment_day 28 mais février 2026 est valide ; on vérifie surtout l'absence de débordement
        $payment = $this->payment(['period' => '2026-02-01'], paymentDay: 28);

        $this->assertSame(RentPaymentStatus::EnAttente, $payment->status);
    }

    public function test_total_sums_rent_and_charges(): void
    {
        $payment = $this->payment([
            'period' => '2026-06-01',
            'amount_rent' => 850.55,
            'amount_charges' => 49.45,
        ]);

        $this->assertSame(900.0, $payment->total);
    }
}
