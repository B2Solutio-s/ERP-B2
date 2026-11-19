<?php

namespace App\Jobs;

use App\Mail\SeguimientoAvisoMail;
use App\Models\SeguimientoAviso;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Aviso a gerencia: 3 compromisos no cumplidos o 3 memorándums acumulados. */
class EnviarAvisoSeguimiento implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $avisoId)
    {
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $aviso = SeguimientoAviso::with('empleado')->find($this->avisoId);

        if (! $aviso || $aviso->enviado_at || ! $aviso->empleado) {
            return;
        }

        $para = array_values(array_filter(array_map('trim', explode(',', $aviso->destinatarios))));

        if (! $para) {
            return;
        }

        Mail::to($para)->send(new SeguimientoAvisoMail($aviso));

        $aviso->update(['enviado_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        SeguimientoAviso::whereKey($this->avisoId)->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
