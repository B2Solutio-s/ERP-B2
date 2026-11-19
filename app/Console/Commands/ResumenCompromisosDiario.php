<?php

namespace App\Console\Commands;

use App\Services\ResumenCompromisos;
use Illuminate\Console\Command;

class ResumenCompromisosDiario extends Command
{
    protected $signature = 'seguimiento:resumen-diario {--forzar : Enviar aunque ya se haya enviado hoy}';

    protected $description = 'Envía a gerencia el resumen de compromisos vencidos sin evaluar y por vencer (programado a las 8:00, hora de Lima).';

    public function handle(): int
    {
        $resultado = ResumenCompromisos::enviar((bool) $this->option('forzar'));

        $resultado['ok'] ? $this->info($resultado['mensaje']) : $this->error($resultado['mensaje']);

        return $resultado['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
