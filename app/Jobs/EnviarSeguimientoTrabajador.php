<?php

namespace App\Jobs;

use App\Mail\SeguimientoTrabajadorMail;
use App\Models\SeguimientoRegistro;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Copia del documento firmado al correo corporativo del trabajador (la dispara el administrador). */
class EnviarSeguimientoTrabajador implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $registroId, public string $correo)
    {
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $registro = SeguimientoRegistro::with('empleado')->find($this->registroId);

        if (! $registro || ! $registro->empleado) {
            return;
        }

        Mail::to($this->correo)->send(new SeguimientoTrabajadorMail($registro));

        $registro->update(['enviado_trabajador_at' => now(), 'enviado_trabajador_a' => $this->correo, 'envio_error' => null, 'envio_solicitado_at' => null]);
    }

    public function failed(Throwable $e): void
    {
        SeguimientoRegistro::whereKey($this->registroId)->update(['envio_error' => mb_substr($e->getMessage(), 0, 500), 'envio_solicitado_at' => null]);
    }
}
