<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertScanner;
use Illuminate\Console\Command;

class ScanAlerts extends Command
{
    protected $signature = 'alerts:scan';

    protected $description = 'Analyse loyers, baux et DPE et génère les alertes proactives (impayés, IRL, fin de bail, expiration DPE).';

    public function handle(AlertScanner $scanner): int
    {
        $count = $scanner->scan();

        $this->info("Analyse terminée : {$count} nouvelle(s) alerte(s) générée(s).");

        return self::SUCCESS;
    }
}
