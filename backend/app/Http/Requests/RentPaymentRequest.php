<?php

namespace App\Http\Requests;

use App\Models\RentPayment;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class RentPaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // La période est normalisée au premier jour du mois (une échéance = un mois).
        if ($this->filled('period')) {
            try {
                $this->merge([
                    'period' => Carbon::parse($this->input('period'))->startOfMonth()->toDateString(),
                ]);
            } catch (InvalidFormatException) {
                // On laisse la règle « date » rejeter la valeur.
            }
        }
    }

    public function rules()
    {
        $lease = $this->route('lease');
        $payment = $this->route('payment');

        return [
            'period' => [
                'required',
                'date',
                // Une seule échéance par mois et par bail (les échéances supprimées sont ignorées).
                function ($attribute, $value, $fail) use ($lease, $payment) {
                    $exists = RentPayment::where('lease_id', $lease?->id)
                        ->whereDate('period', $value)
                        ->when($payment !== null, fn ($q) => $q->where('id', '!=', $payment->id))
                        ->exists();

                    if ($exists) {
                        $fail('Une échéance existe déjà pour ce mois sur ce bail.');
                    }
                },
            ],
            'amount_rent' => ['nullable', 'numeric', 'gte:0'],
            'amount_charges' => ['nullable', 'numeric', 'gte:0'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function authorize()
    {
        return true;
    }
}
