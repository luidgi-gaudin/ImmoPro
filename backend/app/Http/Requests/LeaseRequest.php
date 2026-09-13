<?php

namespace App\Http\Requests;

use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Leases\RentScheduleGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class LeaseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('type')) {
            // En modification, on conserve le type du bail existant.
            $lease = $this->route('lease');

            $this->merge(['type' => $lease?->type?->value ?? LeaseType::Nu->value]);
        }
    }

    public function rules()
    {
        $userId = auth()->id();
        $type = LeaseType::tryFrom((string) $this->input('type'));

        return [
            'property_id' => [
                'required',
                'exists:properties,id',
                function ($attribute, $value, $fail) use ($userId) {
                    $owns = Property::where('id', $value)
                        ->whereHas('portfolio', fn ($q) => $q->where('user_id', $userId))
                        ->exists();

                    if (! $owns) {
                        $fail('The selected property does not belong to you.');
                    }
                },
            ],
            'tenant_id' => [
                'required',
                'exists:tenants,id',
                function ($attribute, $value, $fail) use ($userId) {
                    $owns = Tenant::where('id', $value)
                        ->where('user_id', $userId)
                        ->exists();

                    if (! $owns) {
                        $fail('The selected tenant does not belong to you.');
                    }
                },
            ],
            'type' => ['required', Rule::enum(LeaseType::class)],
            'start_date' => ['required', 'date'],
            'end_date' => [
                // Baux à durée impérativement bornée : étudiant (9 mois) et mobilité (1 à 10 mois)
                Rule::requiredIf(in_array($type, [LeaseType::Etudiant, LeaseType::Mobilite], true)),
                'nullable',
                'date',
                'after:start_date',
            ],
            /*
             * Durée portée par le contrat, distincte de l'écart entre les
             * dates. Facultative : elle se déduit des dates tant que le bail
             * n'a pas été reconduit, et l'imposer obligerait à ressaisir une
             * information déjà présente sur la quasi-totalité des baux.
             *
             * Le plafond de 120 mois n'est pas une règle de droit mais un
             * garde-fou de saisie : au-delà de dix ans, c'est une faute de
             * frappe, pas un bail d'habitation.
             */
            'duration_months' => ['nullable', 'integer', 'between:1,120'],

            'monthly_rent' => ['required', 'numeric', 'gt:0'],
            'charges' => ['nullable', 'numeric', 'gte:0'],
            'deposit' => ['nullable', 'numeric', 'gte:0'],
            'payment_day' => ['nullable', 'integer', 'between:1,28'],
            'statut' => ['sometimes', new Enum(LeaseStatus::class)],

            // Colocation : colocataires additionnels au-delà du locataire principal, avec
            // une éventuelle répartition du loyer par personne.
            'co_tenants' => ['sometimes', 'array'],
            'co_tenants.*.tenant_id' => [
                'required',
                'integer',
                Rule::exists('tenants', 'id')->where(fn ($q) => $q->where('user_id', $userId)),
                Rule::notIn([(int) $this->input('tenant_id')]),
            ],
            'co_tenants.*.rent_share' => ['nullable', 'numeric', 'gte:0'],

            // Échéancier posé dès la création : voir LeaseController::store().
            // Le nombre de mois est un horizon, pas une durée de bail — il est
            // de toute façon raboté sur la date de fin quand elle existe.
            'generate_schedule' => ['sometimes', 'boolean'],
            'schedule_months' => ['sometimes', 'integer', 'between:1,'.RentScheduleGenerator::MAX_MONTHS],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = LeaseType::from($this->input('type'));

            $this->validateDepositCap($validator, $type);
            $this->validateDuration($validator, $type);
            $this->validateCoTenants($validator);
        });
    }

    /**
     * Un même colocataire ne peut pas apparaître plusieurs fois sur un même bail.
     */
    private function validateCoTenants(Validator $validator): void
    {
        $coTenants = collect($this->input('co_tenants', []));
        $ids = $coTenants->pluck('tenant_id')->filter();

        if ($ids->count() !== $ids->unique()->count()) {
            $validator->errors()->add('co_tenants', 'Un colocataire ne peut être ajouté qu\'une seule fois sur ce bail.');
        }
    }

    /**
     * Plafond du dépôt de garantie : 1 mois de loyer hors charges en location vide (art. 22),
     * 2 mois en meublé (art. 25-6), interdit en bail mobilité (art. 25-13).
     */
    private function validateDepositCap(Validator $validator, LeaseType $type): void
    {
        $deposit = (float) ($this->input('deposit') ?? 0);

        if ($deposit <= 0) {
            return;
        }

        $capMonths = $type->depositCapInMonths();

        if ($capMonths === 0) {
            $validator->errors()->add(
                'deposit',
                'Aucun dépôt de garantie ne peut être exigé pour un bail mobilité (art. 25-13, loi n° 89-462).'
            );

            return;
        }

        $cap = $capMonths * (float) $this->input('monthly_rent');

        if ($deposit > $cap) {
            $validator->errors()->add(
                'deposit',
                "Le dépôt de garantie ne peut excéder {$capMonths} mois de loyer hors charges (loi n° 89-462)."
            );
        }
    }

    /**
     * Bornes de durée que rien ne permet de franchir.
     *
     * La validation ne rejette plus toute durée inférieure à la durée de droit
     * commun : elle ne rejette que ce qui est illicite en toutes circonstances.
     *
     * Un bail vide de deux ans est parfaitement valable lorsqu'un événement
     * familial ou professionnel justifie la reprise du logement (art. 11) ; le
     * refuser rendait impossible d'enregistrer un contrat réel. Le motif figure
     * dans le contrat signé, que l'application stocke en pièce jointe — pas dans
     * ce formulaire, où il ne serait qu'une case à cocher sans valeur.
     *
     * Ce qui reste en deçà de la durée de droit commun est signalé sur la fiche
     * du bail par `Lease::durationNotice()`, sans bloquer la saisie.
     *
     * Un bail résilié (statut « termine ») peut avoir une date de fin anticipée.
     */
    private function validateDuration(Validator $validator, LeaseType $type): void
    {
        if (! $this->filled('end_date')) {
            return;
        }

        $statut = $this->input('statut') ?? $this->route('lease')?->statut?->value;

        if ($statut === LeaseStatus::Termine->value) {
            return;
        }

        $start = Carbon::parse($this->input('start_date'));
        $end = Carbon::parse($this->input('end_date'));

        $floor = $type->floorDurationInMonths();

        // Tolérance d'un jour pour les dates de fin inclusives (ex. 01/09 → 31/05).
        if ($end->lt($start->copy()->addMonths($floor)->subDay())) {
            $validator->errors()->add(
                'end_date',
                "Un bail « {$type->label()} » ne peut en aucun cas durer moins de {$floor} mois (loi n° 89-462)."
            );

            return;
        }

        $max = $type->maxDurationInMonths();

        if ($max !== null && $end->gt($start->copy()->addMonths($max))) {
            $validator->errors()->add(
                'end_date',
                $type === LeaseType::Etudiant
                    ? 'Au-delà de douze mois, le contrat relève de la location meublée ordinaire : choisissez ce type de bail.'
                    : "La durée maximale d'un bail « {$type->label()} » est de {$max} mois (loi n° 89-462)."
            );
        }
    }

    public function authorize()
    {
        return true;
    }
}
