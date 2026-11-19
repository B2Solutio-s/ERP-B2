<?php

namespace App\Jobs;

use App\Mail\SeguimientoRegistrosGerenciaMail;
use App\Models\SeguimientoEnvioGerencia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envío manual a gerencia de los registros elegidos por el administrador. */
class EnviarRegistrosGerencia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $envioId)
    {
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $envio = SeguimientoEnvioGerencia::with('empleado')->find($this->envioId);

        if (! $envio || $envio->enviado_at || ! $envio->empleado) {
            return;
        }

        $para = array_values(array_filter(array_map('trim', explode(',', $envio->destinatarios))));

        if (! $para) {
            return;
        }

        Mail::to($para)->send(new SeguimientoRegistrosGerenciaMail($envio));

        $envio->update(['enviado_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        SeguimientoEnvioGerencia::whereKey($this->envioId)->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
