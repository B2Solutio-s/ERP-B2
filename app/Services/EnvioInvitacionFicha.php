<?php

namespace App\Services;

use App\Jobs\EnviarInvitacionFicha;
use App\Models\CandidatoReclutamiento;
use App\Models\OnboardingInvitation;
use Illuminate\Support\Facades\Auth;

/** Genera (si hace falta) el link de ficha de un candidato y encola el correo con ese link. */
class EnvioInvitacionFicha
{
    /** El envío solo funciona con un SMTP real (no "log"/"array" ni el remitente de ejemplo). */
    public static function configurado(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true)
            && ! str_ends_with((string) config('mail.from.address'), '@example.com');
    }

    /** Devuelve null si el correo quedó en cola, o el motivo por el que se omitió. */
    /** $tipo: 'actualizacion' (Registro de ingresos) o 'ingreso' (Proceso de capacitación). */
    public function encolar(CandidatoReclutamiento $candidato, ?string $correo, string $tipo): ?string
    {
        if (! $correo) {
            return 'sin correo';
        }

        $invitacion = OnboardingInvitation::principalDe($candidato->id);

        if ($invitacion?->empleado || $invitacion?->usado_en) {
            return 'ya llenó su ficha';
        }

        if (! $invitacion || ! $invitacion->estaVigente()) {
            $invitacion = OnboardingInvitation::generar($candidato->nombreCompleto(), $candidato->email);
            $invitacion->update(['reclutamiento_candidato_id' => $candidato->id, 'creado_por' => Auth::id()]);
        }

        $invitacion->update([
            'correo_a' => $correo,
            'correo_encolado_at' => now(),
            'correo_enviado_at' => null,
            'correo_error' => null,
        ]);

        EnviarInvitacionFicha::dispatch($invitacion->id, $tipo);

        return null;
    }
}
