<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Analyse quotidienne des alertes proactives (impayés J+3, révision IRL J-30,
// fin de bail 6/3/1 mois, expiration DPE). Idempotente : ré-exécutable sans doublon.
Schedule::command('alerts:scan')->dailyAt('07:00');
