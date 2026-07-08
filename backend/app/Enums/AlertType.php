<?php

namespace App\Enums;

enum AlertType: string
{
    case LoyerImpaye = 'loyer_impaye';       // loyer non enregistré à J+3
    case RevisionIrl = 'revision_irl';       // révision annuelle IRL à venir (J-30)
    case FinBail = 'fin_bail';               // approche de la fin du bail (6/3/1 mois)
    case DpeExpiration = 'dpe_expiration';   // DPE arrivant à expiration (validité 10 ans)
}
