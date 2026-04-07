<?php

namespace App\Enums;

enum LeaseStatus: string
{
    case Actif     = 'actif';
    case Termine   = 'termine';
    case EnAttente = 'en_attente';
}