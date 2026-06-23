<?php

namespace App\Models;

use App\Enums\RentPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'lease_id',
        'period',
        'amount_rent',
        'amount_charges',
        'paid_at',
        'payment_method',
    ];

    protected $appends = ['status', 'total'];

    // La relation sert au calcul du statut, inutile de la sérialiser.
    protected $hidden = ['lease'];

    public function lease()
    {
        return $this->belongsTo(Lease::class);
    }

    protected function casts()
    {
        return [
            'period' => 'date',
            'paid_at' => 'date',
            'amount_rent' => 'decimal:2',
            'amount_charges' => 'decimal:2',
        ];
    }

    public function getStatusAttribute(): RentPaymentStatus
    {
        if ($this->paid_at !== null) {
            return RentPaymentStatus::Paye;
        }

        $paymentDay = $this->lease?->payment_day ?? 1;
        $dueDate = $this->period->copy()->day(min($paymentDay, $this->period->daysInMonth));

        return now()->startOfDay()->gt($dueDate)
            ? RentPaymentStatus::EnRetard
            : RentPaymentStatus::EnAttente;
    }

    public function getTotalAttribute(): float
    {
        return round((float) $this->amount_rent + (float) $this->amount_charges, 2);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
