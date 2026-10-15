<?php

namespace App\Jobs;

use App\Mail\OnboardingInvitationMail;
use App\Models\OnboardingInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EnviarInvitacionFicha implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** $tipo: 'actualizacion' (Registro de ingresos) o 'ingreso' (Proceso de capacitación) */
    public function __construct(public int $invitacionId, public ?string $tipo = null)
    {
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $invitacion = OnboardingInvitation::find($this->invitacionId);

        // Si ya llenó la ficha o el link venció mientras esperaba en la cola, no se envía.
        if (! $invitacion || ! $invitacion->correo_a || ! $invitacion->estaVigente()) {
            return;
        }

        Mail::to($invitacion->correo_a)->send(new OnboardingInvitationMail($invitacion, $this->tipo));

        $invitacion->update(['correo_enviado_at' => now(), 'correo_error' => null]);
    }

    public function failed(Throwable $e): void
    {
        OnboardingInvitation::whereKey($this->invitacionId)
            ->update(['correo_error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
