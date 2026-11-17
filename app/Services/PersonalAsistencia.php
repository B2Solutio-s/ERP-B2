<?php

namespace App\Services;

use App\Models\AsistenciaMarcacion;
use App\Models\CandidatoReclutamiento;
use App\Models\Empleado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Personal que cuenta para la asistencia: trabajadores con ficha y al menos un período de contrato.
 * Área, puesto, campaña y cargo salen de Gestión de candidatos (catálogo); la ficha del trabajador solo sirve de respaldo.
 */
class PersonalAsistencia
{
    /**
     * Todo el personal con contrato (quien aún no tiene período, por estar en proceso, no cuenta).
     * Usa el último período para el cese y el primero para la fecha de ingreso.
     *
     * @return Collection<int, array{empleado: Empleado, area: string, campana: ?string, puesto: string, puestoBase: string, inicio: Carbon, cese: bool, cese_fecha: ?Carbon}>
     */
    public static function todos(): Collection
    {
        $empleados = Empleado::with(['periodosContrato:id,empleado_id,fecha_inicio,cese,cese_fecha', 'invitacion:id,reclutamiento_candidato_id'])
            ->get(['id', 'onboarding_invitation_id', 'campana', 'puesto', 'biometrico_id', 'documento_identidad', 'contrato_remuneracion', 'apellido_paterno', 'apellido_materno', 'nombres']);

        $candidatos = CandidatoReclutamiento::whereIn('id', $empleados->pluck('invitacion.reclutamiento_candidato_id')->filter())
            ->get(['id', 'area', 'puesto', 'campana', 'cargo'])
            ->keyBy('id');

        $valido = fn (?string $valor) => ! in_array(mb_strtoupper(trim((string) $valor)), ['', '-', 'NO APLICA'], true);

        return $empleados->map(function (Empleado $empleado) use ($candidatos, $valido) {
            $ultimo = $empleado->periodosContrato->sortByDesc('fecha_inicio')->first();

            if (! $ultimo) {
                return null;
            }

            $candidato = $candidatos->get($empleado->invitacion?->reclutamiento_candidato_id);
            $campana = $valido($candidato?->campana) ? $candidato->campana : ($candidato ? null : $empleado->campana);

            return [
                'empleado' => $empleado,
                'area' => mb_strtoupper(trim((string) $candidato?->area)) ?: 'SIN CLASIFICAR',
                'campana' => mb_strtoupper(trim((string) $campana)) ?: null,
                'puesto' => mb_strtoupper((string) ($candidato
                    ? collect([$valido($candidato->cargo) ? $candidato->cargo : null, $candidato->puesto])->filter()->implode(' - ')
                    : $empleado->puesto)),
                'puestoBase' => mb_strtoupper(trim((string) ($candidato?->puesto ?: $empleado->puesto))) ?: 'SIN PUESTO',
                'inicio' => $empleado->periodosContrato->min('fecha_inicio'),
                'cese' => (bool) $ultimo->cese,
                'cese_fecha' => $ultimo->cese_fecha,
            ];
        })->filter()->values();
    }

    /**
     * Fecha (Y-m-d) de la primera marcación de cada ID de huella. Antes de esa fecha la persona todavía no podía marcar
     * (la huella se registra después de que empieza su contrato), así que esos días no cuentan como falta.
     *
     * @return Collection<string, string>  biometrico_id => fecha
     */
    public static function primerasMarcaciones(): Collection
    {
        return AsistenciaMarcacion::selectRaw('biometrico_id, MIN(marcado_at) as primera')
            ->groupBy('biometrico_id')
            ->pluck('primera', 'biometrico_id')
            ->map(fn ($primera) => substr((string) $primera, 0, 10));
    }

    /** Si la persona estaba en planilla ese día: ya había empezado su primer contrato y no estaba cesada (el día del cese ya no cuenta). */
    public static function activoEn(array $registro, string $fecha): bool
    {
        if ($registro['cese'] && (! $registro['cese_fecha'] || $registro['cese_fecha']->toDateString() <= $fecha)) {
            return false;
        }

        return $registro['inicio']->toDateString() <= $fecha;
    }
}
