<?php

namespace App\Enums;

enum RentPaymentStatus: string
{
    case EnAttente = 'en_attente';
    case Paye = 'paye';
    case EnRetard = 'en_retard';
}
