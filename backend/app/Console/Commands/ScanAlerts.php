<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertScanner;
use App\Support\Rls;
use Illuminate\Console\Command;

class ScanAlerts extends Command
{
    protected $signature = 'alerts:scan';

    protected $description = 'Analyse loyers, baux et DPE et génère les alertes proactives (impayés, IRL, fin de bail, expiration DPE).';

    /**
     * Le scan travaille pour tous les bailleurs à la fois : c'est le seul
     * traitement de l'application dont c'est le rôle, et donc le seul à devoir
     * s'affranchir de la Row Level Security. Sous le rôle applicatif, il ne
     * verrait aucun bail et se terminerait en annonçant zéro alerte, sans
     * erreur — un silence bien plus coûteux qu'un échec.
     */
    public function handle(AlertScanner $scanner): int
    {
        $count = Rls::withoutRestrictions(fn () => $scanner->scan());

        $this->info("Analyse terminée : {$count} nouvelle(s) alerte(s) générée(s).");

        return self::SUCCESS;
    }
}
