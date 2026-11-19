<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resumen diario de compromisos para gerencia (8:00 a. m. de Lima). Lo ejecuta el servicio «programador» (schedule:work).
Schedule::command('seguimiento:resumen-diario')->dailyAt('08:00')->timezone('America/Lima')->withoutOverlapping();
