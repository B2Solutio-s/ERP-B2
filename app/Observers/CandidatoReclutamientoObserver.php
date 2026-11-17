<?php

namespace App\Observers;

use App\Models\CandidatoEvento;
use App\Models\CandidatoReclutamiento;
use Illuminate\Support\Facades\Auth;

/**
 * Deja en el historial del candidato cada cambio de los datos del proceso, sin importar desde dónde se hizo
 * (grilla, módulo de registros, registro masivo o capacitación).
 */
class CandidatoReclutamientoObserver
{
    /** Datos que se registran en el historial (campo => etiqueta). */
    public const ETIQUETAS = [
        'dni_ce' => 'DNI / C.E',
        'nombres' => 'Nombres',
        'apellidos' => 'Apellidos',
        'numero_celular' => 'Celular / usuario',
        'edad' => 'Edad',
        'distrito' => 'Distrito',
        'observaciones' => 'Observaciones',
        'fecha_gestion' => 'Fecha de gestión',
        'agente_reclutador' => 'Agente reclutador',
        'area' => 'Área',
        'puesto' => 'Puesto',
        'campana' => 'Campaña',
        'cargo' => 'Cargo',
        'tipificacion' => 'Tipificación',
        'subtipificacion_rechazo' => 'Motivo del rechazo',
        'volver_a_llamar_fecha' => 'Volver a llamar el',
        'volver_a_llamar_hora' => 'Hora para volver a llamar',
        'aceptacion_entrevista' => 'Aceptó la entrevista',
        'fecha_entrevista' => 'Fecha de entrevista',
        'hora_entrevista' => 'Hora de entrevista',
        'asistio_entrevista' => 'Asistió a la entrevista',
        'fecha_reprogramada' => 'Fecha reprogramada',
        'asistio_entrevista_reprogramada' => 'Asistió a la reprogramada',
        'capacitacion_1' => 'Fecha de capacitación 1',
        'asistio_cap_1' => 'Asistió a capacitación 1',
        'capacitacion_1_reprogramada' => 'Capacitación 1 reprogramada',
        'asistio_cap_1_reprogramada' => 'Asistió a capacitación 1 (reprog.)',
        'capacitacion_2' => 'Fecha de capacitación 2',
        'asistio_cap_2' => 'Asistió a capacitación 2',
        'capacitacion_2_reprogramada' => 'Capacitación 2 reprogramada',
        'asistio_cap_2_reprogramada' => 'Asistió a capacitación 2 (reprog.)',
        'apto' => 'Apto',
        'fecha_ingreso' => 'Fecha de ingreso',
    ];

    /**
     * Minutos dentro de los que varias ediciones seguidas del mismo dato por la misma persona (la grilla guarda
     * mientras se escribe) se unen en una sola línea del historial.
     */
    public const MINUTOS_UNIR = 2;

    /** Texto que acompaña al alta (p. ej. «Registro masivo»). */
    public static ?string $origen = null;

    /** Campos que no se registran como «cambio» porque otro evento (una llamada) ya los explica. */
    public static array $omitir = [];

    public function created(CandidatoReclutamiento $candidato): void
    {
        CandidatoEvento::create([
            'reclutamiento_candidato_id' => $candidato->id,
            'user_id' => Auth::id(),
            'tipo' => 'alta',
            'nota' => self::$origen,
        ]);
    }

    public function updated(CandidatoReclutamiento $candidato): void
    {
        $atributos = $candidato->getAttributes();

        foreach (array_keys(self::ETIQUETAS) as $campo) {
            if (! $candidato->wasChanged($campo) || in_array($campo, self::$omitir, true)) {
                continue;
            }

            $antes = $this->texto($campo, $candidato->getRawOriginal($campo));
            $despues = $this->texto($campo, $atributos[$campo] ?? null);

            if ($antes === $despues) {
                continue;
            }

            $this->registrarCambio($candidato->id, $campo, $antes, $despues);
        }
    }

    /** Registra el cambio o, si es la continuación de una edición reciente del mismo dato, la une a esa línea. */
    private function registrarCambio(int $candidatoId, string $campo, ?string $antes, ?string $despues): void
    {
        $reciente = CandidatoEvento::query()
            ->where('reclutamiento_candidato_id', $candidatoId)
            ->where('tipo', 'cambio')
            ->where('campo', $campo)
            ->where('user_id', Auth::id())
            ->where('updated_at', '>=', now()->subMinutes(self::MINUTOS_UNIR))
            ->orderByDesc('id')
            ->first();

        if (! $reciente) {
            CandidatoEvento::create([
                'reclutamiento_candidato_id' => $candidatoId,
                'user_id' => Auth::id(),
                'tipo' => 'cambio',
                'campo' => $campo,
                'valor_anterior' => $antes,
                'valor_nuevo' => $despues,
            ]);

            return;
        }

        // Si la persona terminó dejando el dato como estaba, no hubo cambio que contar.
        if ($reciente->valor_anterior === $despues) {
            $reciente->delete();

            return;
        }

        $reciente->forceFill(['valor_nuevo' => $despues, 'created_at' => now()])->save();
    }

    private function texto(string $campo, mixed $valor): ?string
    {
        if ($campo === 'apto') {
            return $valor === null ? null : ((int) $valor === 1 ? 'SI' : 'NO');
        }

        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        // Las fechas se guardan como «2026-11-20 00:00:00»: se comparan y se muestran como fecha.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})( 00:00:00)?$/', $valor, $m)) {
            return $m[1];
        }

        return mb_substr($valor, 0, 500);
    }
}
