<?php

namespace App\Services;

use App\Events\ReclutamientoActualizado;
use App\Models\Area;
use App\Models\Campana;
use App\Models\CandidatoEvento;
use App\Models\CandidatoReclutamiento;
use App\Models\Cargo;
use App\Models\OnboardingInvitation;
use App\Models\Puesto;
use App\Models\User;
use App\Observers\CandidatoReclutamientoObserver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reglas de la gestión de candidatos (las mismas de la grilla de «Proceso de reclutamiento») para el módulo de registros:
 * normalización de datos, dependencias Área → Puesto/Campaña/Cargo, entrevista, duplicados, bloqueo de edición,
 * candidatos que ya pasaron a capacitación y sincronización en vivo con la grilla.
 */
class GestionCandidatos
{
    public const CAMPOS = [
        'mes', 'fecha_gestion', 'agente_reclutador', 'area', 'puesto', 'campana', 'cargo', 'dni_ce', 'edad',
        'nombres', 'apellidos', 'numero_celular', 'distrito', 'observaciones',
        'tipificacion', 'subtipificacion_rechazo',
        'aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
        'fecha_reprogramada', 'asistio_entrevista_reprogramada',
    ];

    /** Seguimiento de llamadas que se edita junto con el registro. */
    public const SEGUIMIENTO = ['volver_a_llamar_fecha', 'volver_a_llamar_hora'];

    public const FORMULARIO = [...self::CAMPOS, ...self::SEGUIMIENTO];

    private const TEXTO = [
        'mes', 'agente_reclutador', 'area', 'puesto', 'campana', 'cargo', 'dni_ce', 'nombres', 'apellidos', 'numero_celular',
        'distrito', 'observaciones', 'tipificacion', 'subtipificacion_rechazo',
        'aceptacion_entrevista', 'asistio_entrevista', 'asistio_entrevista_reprogramada',
    ];

    private const ENTREVISTA = [
        'aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
        'fecha_reprogramada', 'asistio_entrevista_reprogramada',
    ];

    /** Tipificaciones que descartan al candidato en la llamada (y habilitan el motivo de rechazo). */
    public const RECHAZO = ['NO INTERESADO', 'NO PASA FILTRO'];

    /** Tipificaciones con las que termina la gestión del candidato (no continúa en el proceso). «NO CALIFICA» es un valor antiguo. */
    public const FIN_GESTION = ['FUERA DE SERVICIO', 'NO PASA FILTRO', 'NO INTERESADO', 'NO CALIFICA'];

    /** Tipificaciones que dejan al candidato pendiente de una nueva llamada («PENDIENTE DE LLAMADA» es un valor antiguo). */
    public const VOLVER_A_LLAMAR = ['NO CONTESTA', 'APAGADO', 'VOLVER A LLAMAR', 'PENDIENTE DE LLAMADA'];

    public const TIPIFICACIONES = [
        'INTERESADO - APTO', 'NO INTERESADO', 'NO PASA FILTRO', 'NO CONTESTA', 'APAGADO', 'FUERA DE SERVICIO', 'VOLVER A LLAMAR',
    ];

    public const MOTIVOS_RECHAZO = [
        'SUELDO', 'DISTANCIA', 'TRABAJA REMOTO', 'HORARIOS DE ESTUDIO', 'NO DA MOTIVOS', 'DESEA OTRA POSICIÓN',
        'YA SE ENCUENTRA LABORANDO', 'CORTA LA LLAMADA', 'NO POSEE EXPERIENCIA', 'EXTRANJERO', 'FALTA DE RESPETO', 'TERCERA EDAD',
    ];

    public const ASISTIO = ['SI, APTO', 'SI, NO APTO', 'NO', 'REPROGRAMADO'];

    public const ASISTIO_REPROGRAMADA = ['SI, APTO', 'SI, NO APTO', 'NO'];

    public const MESES = [
        'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE',
    ];

    public const DISTRITOS = [
        'ATE', 'BARRANCO', 'BREÑA', 'CARABAYLLO', 'CHACLACAYO', 'CHORRILLOS', 'CIENEGUILLA',
        'COMAS', 'EL AGUSTINO', 'INDEPENDENCIA', 'JESÚS MARÍA', 'LA MOLINA', 'LA VICTORIA',
        'LIMA', 'LINCE', 'LOS OLIVOS', 'LURIGANCHO', 'LURÍN', 'MAGDALENA DEL MAR', 'PUEBLO LIBRE',
        'MIRAFLORES', 'PACHACÁMAC', 'PUCUSANA', 'PUENTE PIEDRA', 'PUNTA HERMOSA', 'PUNTA NEGRA',
        'RÍMAC', 'SAN BARTOLO', 'SAN BORJA', 'SAN ISIDRO', 'SAN JUAN DE LURIGANCHO',
        'SAN JUAN DE MIRAFLORES', 'SAN LUIS', 'SAN MARTÍN DE PORRES', 'SAN MIGUEL', 'SANTA ANITA',
        'SANTA MARÍA DEL MAR', 'SANTA ROSA', 'SANTIAGO DE SURCO', 'SURQUILLO', 'VILLA EL SALVADOR',
        'VILLA MARÍA DEL TRIUNFO', 'OTROS',
    ];

    /** Etapas del recorrido (clave => etiqueta). */
    public const ETAPAS = [
        'gestion' => 'Gestión',
        'entrevista' => 'Entrevista',
        'capacitacion' => 'Capacitación',
        'apto' => 'Aptos',
        'no_continua' => 'No continúan',
    ];

    /** Catálogos para los desplegables dependientes del área. */
    public function catalogos(): array
    {
        $areas = Area::query()->orderBy('nombre')->get();
        $por = fn (string $modelo, ?bool $usaCampana = null) => $areas->mapWithKeys(fn ($area) => [
            $area->nombre => $modelo::query()
                ->where('area_id', $area->id)
                ->when($usaCampana === false, fn ($q) => $q->where('usa_campana', false))
                ->orderBy('nombre')->pluck('nombre')->values()->all(),
        ])->all();

        return [
            'areas' => $areas->pluck('nombre')->values()->all(),
            'config' => $areas->mapWithKeys(fn ($a) => [$a->nombre => ['usa_campana' => (bool) $a->usa_campana, 'usa_cargo' => (bool) $a->usa_cargo]])->all(),
            'puestos' => $por(Puesto::class),
            'puestosSinCampana' => $por(Puesto::class, false),
            'campanas' => $por(Campana::class),
            'cargos' => $por(Cargo::class),
            'reclutadores' => User::query()->where('role', User::ROL_GTH)->orderBy('name')->pluck('name')->map(fn ($n) => mb_strtoupper($n))->values()->all(),
        ];
    }

    public function usaCampana(?string $area, ?string $puesto): bool
    {
        $config = Area::query()->whereRaw('UPPER(nombre) = ?', [mb_strtoupper((string) $area)])->first();

        return ($config?->usa_campana ?? true) && ! $this->puestoSinCampana($area, $puesto);
    }

    public function usaCargo(?string $area): bool
    {
        return (bool) (Area::query()->whereRaw('UPPER(nombre) = ?', [mb_strtoupper((string) $area)])->first()?->usa_cargo ?? true);
    }

    public function puestoSinCampana(?string $area, ?string $puesto): bool
    {
        if (! $area || ! $puesto) {
            return false;
        }

        return Puesto::where('usa_campana', false)
            ->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($puesto)])
            ->whereHas('area', fn ($q) => $q->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($area)]))
            ->exists();
    }

    public function mesDe(?string $fecha): string
    {
        if (! $fecha) {
            return '';
        }

        try {
            return self::MESES[Carbon::parse($fecha)->month - 1];
        } catch (\Throwable) {
            return '';
        }
    }

    /** Datos del formulario → columnas, con las mismas normalizaciones de la grilla. */
    public function payload(array $datos): array
    {
        $payload = [];
        foreach (self::CAMPOS as $campo) {
            $valor = $datos[$campo] ?? null;
            $payload[$campo] = $valor === '' ? null : $valor;
        }

        foreach (self::TEXTO as $campo) {
            $payload[$campo] = $payload[$campo] === null ? null : mb_strtoupper(trim((string) $payload[$campo]));
            if ($payload[$campo] === '') {
                $payload[$campo] = null;
            }
        }

        foreach (self::SEGUIMIENTO as $campo) {
            $payload[$campo] = blank($datos[$campo] ?? null) ? null : trim((string) $datos[$campo]);
        }

        // Solo quien queda pendiente de una nueva llamada tiene fecha para volver a llamar.
        if (! in_array($payload['tipificacion'], self::VOLVER_A_LLAMAR, true)) {
            $payload['volver_a_llamar_fecha'] = null;
            $payload['volver_a_llamar_hora'] = null;
        }

        $payload['mes'] = $payload['fecha_gestion'] ? $this->mesDe($payload['fecha_gestion']) : null;

        if ($payload['area'] && ! $this->usaCampana($payload['area'], $payload['puesto'])) {
            $payload['campana'] = 'NO APLICA';
        }

        if ($payload['area'] && ! $this->usaCargo($payload['area'])) {
            $payload['cargo'] = 'NO APLICA';
        }

        if (! in_array($payload['tipificacion'], self::RECHAZO, true)) {
            $payload['subtipificacion_rechazo'] = null;
        }

        // La entrevista solo se registra cuando ya hay un nombre; fecha y hora solo si aceptó.
        if (blank($payload['nombres'])) {
            foreach (self::ENTREVISTA as $campo) {
                $payload[$campo] = null;
            }
        }

        // Sin haber aceptado la entrevista no hay fecha, hora ni asistencia que registrar.
        if ($payload['aceptacion_entrevista'] !== 'SI') {
            foreach (['fecha_entrevista', 'hora_entrevista', 'asistio_entrevista', 'fecha_reprogramada', 'asistio_entrevista_reprogramada'] as $campo) {
                $payload[$campo] = null;
            }
        }

        if ($payload['asistio_entrevista'] !== 'REPROGRAMADO') {
            $payload['fecha_reprogramada'] = null;
            $payload['asistio_entrevista_reprogramada'] = null;
        }

        $payload['edad'] = $payload['edad'] === null ? null : min(99, max(0, (int) $payload['edad']));

        return $payload;
    }

    public function sinDatosDePersona(array $fila): bool
    {
        foreach (['dni_ce', 'nombres', 'apellidos', 'numero_celular'] as $campo) {
            if (trim((string) ($fila[$campo] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Candidato que ya está en el proceso de capacitación (su gestión ya no se edita aquí). */
    public function graduado(CandidatoReclutamiento $c): bool
    {
        return $c->asistio_entrevista === 'SI, APTO'
            || ($c->asistio_entrevista === 'REPROGRAMADO' && $c->asistio_entrevista_reprogramada === 'SI, APTO');
    }

    public function duplicado(array $payload, ?int $ignorarId = null): ?CandidatoReclutamiento
    {
        $dni = trim((string) ($payload['dni_ce'] ?? ''));
        $nombreCompleto = trim(($payload['nombres'] ?? '').' '.($payload['apellidos'] ?? ''));
        $telefono = preg_replace('/\D+/', '', (string) ($payload['numero_celular'] ?? ''));

        if ($dni !== '') {
            $candidato = CandidatoReclutamiento::query()
                ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
                ->whereRaw('LOWER(TRIM(COALESCE(dni_ce, ""))) = ?', [mb_strtolower($dni)])
                ->first();

            if ($candidato) {
                return $candidato;
            }
        }

        if ($nombreCompleto !== '' && $telefono !== '') {
            return CandidatoReclutamiento::query()
                ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
                ->whereRaw('LOWER(TRIM(CONCAT(COALESCE(nombres, ""), " ", COALESCE(apellidos, "")))) = ?', [mb_strtolower($nombreCompleto)])
                ->whereRaw('REPLACE(REPLACE(REPLACE(numero_celular, " ", ""), "-", ""), "+", "") = ?', [$telefono])
                ->first();
        }

        return null;
    }

    /** Solo se elimina un registro huérfano: sin datos de persona ni avance en el proceso. */
    public function eliminable(CandidatoReclutamiento $c): bool
    {
        return $this->sinDatosDePersona($c->only(['dni_ce', 'nombres', 'apellidos', 'numero_celular']))
            && ! $c->apto
            && ! $this->graduado($c)
            && ! OnboardingInvitation::where('reclutamiento_candidato_id', $c->id)->exists();
    }

    // ---------------------------------------------------------------
    // Llamadas e historial
    // ---------------------------------------------------------------

    /**
     * Registra una llamada: actualiza la tipificación, suma un intento y deja la llamada en el historial.
     *
     * @return array{error?: string}
     */
    public function registrarLlamada(int $id, array $datos): array
    {
        $c = CandidatoReclutamiento::with('bloqueadoPor')->find($id);

        if (! $c) {
            return ['error' => 'Este candidato ya no existe: otra persona lo eliminó.'];
        }

        if ($this->graduado($c)) {
            return ['error' => 'Este candidato ya pasó a capacitación.'];
        }

        if ($otro = $this->bloqueadoPorOtro($c)) {
            return ['error' => $otro.' está editando este registro en este momento. Inténtalo en un minuto.'];
        }

        $hoy = Carbon::now('America/Lima')->toDateString();
        $tipificacion = mb_strtoupper(trim((string) ($datos['tipificacion'] ?? '')));
        $motivo = mb_strtoupper(trim((string) ($datos['subtipificacion_rechazo'] ?? '')));
        $fecha = trim((string) ($datos['volver_a_llamar_fecha'] ?? ''));
        $hora = trim((string) ($datos['volver_a_llamar_hora'] ?? ''));
        $nota = trim((string) ($datos['nota'] ?? ''));

        if (! in_array($tipificacion, self::TIPIFICACIONES, true)) {
            return ['error' => 'Elige el resultado de la llamada.'];
        }

        if (in_array($tipificacion, self::RECHAZO, true) && $motivo === '') {
            return ['error' => 'Indica el motivo del rechazo.'];
        }

        $pendiente = in_array($tipificacion, self::VOLVER_A_LLAMAR, true);

        if ($pendiente && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return ['error' => 'Indica cuándo hay que volver a llamar.'];
        }

        if ($pendiente && $fecha < $hoy) {
            return ['error' => 'La fecha para volver a llamar no puede ser pasada.'];
        }

        if ($hora !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            return ['error' => 'La hora no es válida.'];
        }

        $anterior = $c->tipificacion;
        $resultado = $this->resumenLlamada($tipificacion, $motivo, $pendiente ? $fecha : null, $hora);

        $omitidos = CandidatoReclutamientoObserver::$omitir;
        CandidatoReclutamientoObserver::$omitir = ['tipificacion', 'subtipificacion_rechazo', 'volver_a_llamar_fecha', 'volver_a_llamar_hora'];

        try {
            DB::transaction(function () use ($c, $tipificacion, $motivo, $pendiente, $fecha, $hora, $nota, $anterior, $resultado) {
                $c->update([
                    'tipificacion' => $tipificacion,
                    'subtipificacion_rechazo' => in_array($tipificacion, self::RECHAZO, true) ? $motivo : null,
                    'volver_a_llamar_fecha' => $pendiente ? $fecha : null,
                    'volver_a_llamar_hora' => $pendiente && $hora !== '' ? $hora : null,
                    'intentos_contacto' => ($c->intentos_contacto ?? 0) + 1,
                    'ultimo_contacto_at' => now(),
                ]);

                CandidatoEvento::create([
                    'reclutamiento_candidato_id' => $c->id,
                    'user_id' => Auth::id(),
                    'tipo' => 'llamada',
                    'campo' => 'tipificacion',
                    'valor_anterior' => $anterior,
                    'valor_nuevo' => mb_substr($resultado, 0, 500),
                    'nota' => $nota !== '' ? mb_substr($nota, 0, 1000) : null,
                ]);
            });
        } finally {
            CandidatoReclutamientoObserver::$omitir = $omitidos;
        }

        $this->difundir($c);

        return [];
    }

    /** Texto del resultado de una llamada para el historial: «NO CONTESTA · volver a llamar el 18/11/2026 10:00». */
    private function resumenLlamada(string $tipificacion, ?string $motivo, ?string $fecha, ?string $hora): string
    {
        return $tipificacion
            .(in_array($tipificacion, self::RECHAZO, true) && filled($motivo) ? ' — '.$motivo : '')
            .($fecha ? ' · volver a llamar el '.Carbon::parse($fecha)->format('d/m/Y').(filled($hora) ? ' '.$hora : '') : '');
    }

    /** Historial del candidato, lo más reciente primero. */
    public function historial(int $id, int $limite = 40): array
    {
        return CandidatoEvento::with('usuario')
            ->where('reclutamiento_candidato_id', $id)
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (CandidatoEvento $e) => [
                'fecha' => $e->created_at?->copy()->timezone('America/Lima')->format('d/m/Y H:i') ?? '',
                'usuario' => $e->usuario?->name ?? 'Sistema',
                'tipo' => $e->tipo,
                'texto' => $this->textoEvento($e),
                'nota' => $e->nota && $e->tipo !== 'alta' ? $e->nota : null,
            ])
            ->all();
    }

    private function textoEvento(CandidatoEvento $e): string
    {
        $formato = function (?string $valor): string {
            if ($valor === null || $valor === '') {
                return '—';
            }

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? Carbon::parse($valor)->format('d/m/Y') : $valor;
        };

        return match ($e->tipo) {
            'alta' => 'Registrado'.($e->nota ? ' · '.$e->nota : ''),
            'llamada' => 'Llamada: '.$e->valor_nuevo.($e->valor_anterior && ! str_starts_with((string) $e->valor_nuevo, (string) $e->valor_anterior) ? ' (antes: '.$e->valor_anterior.')' : ''),
            default => (CandidatoReclutamientoObserver::ETIQUETAS[$e->campo] ?? $e->campo).': '.$formato($e->valor_anterior).' → '.$formato($e->valor_nuevo),
        };
    }

    // ---------------------------------------------------------------
    // Reglas de coherencia del registro
    // ---------------------------------------------------------------

    /**
     * Reglas que dependen de otros datos del mismo registro. Lo único obligatorio de entrada es identificar a la persona
     * (DNI, nombre o celular/usuario): el resto solo se exige cuando el proceso llega a ese punto.
     *
     * @return array<string, string>  campo del formulario (sin «f.») => mensaje
     */
    public function reglas(array $f, ?CandidatoReclutamiento $actual = null): array
    {
        $errores = [];
        $hoy = Carbon::now('America/Lima')->toDateString();
        $v = fn (string $campo) => trim((string) ($f[$campo] ?? ''));
        $fecha = fn (string $campo) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v($campo)) ? $v($campo) : null;

        if ($fecha('fecha_gestion') && $fecha('fecha_gestion') > $hoy) {
            $errores['fecha_gestion'] = 'La fecha de gestión no puede ser futura.';
        }

        if (in_array(mb_strtoupper($v('tipificacion')), self::RECHAZO, true) && $v('subtipificacion_rechazo') === '') {
            $errores['subtipificacion_rechazo'] = 'Indica el motivo del rechazo.';
        }

        // Al dejar a alguien pendiente de una nueva llamada hay que decir cuándo (los casos antiguos sin fecha no se obligan).
        if (in_array(mb_strtoupper($v('tipificacion')), self::VOLVER_A_LLAMAR, true)) {
            $cambioTipificacion = ! $actual || mb_strtoupper((string) $actual->tipificacion) !== mb_strtoupper($v('tipificacion'));
            $fechaVolver = $fecha('volver_a_llamar_fecha');

            if ($v('volver_a_llamar_fecha') === '' && $cambioTipificacion) {
                $errores['volver_a_llamar_fecha'] = 'Indica cuándo hay que volver a llamar.';
            } elseif ($fechaVolver && $fechaVolver < $hoy && $fechaVolver !== $actual?->volver_a_llamar_fecha?->toDateString()) {
                $errores['volver_a_llamar_fecha'] = 'La fecha para volver a llamar no puede ser pasada.';
            }
        }

        if ($v('aceptacion_entrevista') === 'SI') {
            if (mb_strtoupper($v('tipificacion')) !== 'INTERESADO - APTO') {
                $errores['aceptacion_entrevista'] = 'Solo se agenda entrevista a quien está tipificado como INTERESADO - APTO.';
            }

            if ($v('fecha_entrevista') === '') {
                $errores['fecha_entrevista'] = 'Indica la fecha de la entrevista.';
            }

            if ($v('hora_entrevista') === '') {
                $errores['hora_entrevista'] = 'Indica la hora de la entrevista.';
            }

            if ($fecha('fecha_entrevista') && $fecha('fecha_gestion') && $fecha('fecha_entrevista') < $fecha('fecha_gestion')) {
                $errores['fecha_entrevista'] = 'La entrevista no puede ser antes de la fecha de gestión.';
            }

            $resultados = ['SI', 'SI, APTO', 'SI, NO APTO', 'NO'];

            if (in_array($v('asistio_entrevista'), $resultados, true) && $fecha('fecha_entrevista') && $fecha('fecha_entrevista') > $hoy) {
                $errores['asistio_entrevista'] = 'La asistencia se marca desde el día de la entrevista.';
            }

            if ($v('asistio_entrevista') === 'REPROGRAMADO') {
                if ($fecha('fecha_reprogramada') && $fecha('fecha_entrevista') && $fecha('fecha_reprogramada') <= $fecha('fecha_entrevista')) {
                    $errores['fecha_reprogramada'] = 'La nueva fecha debe ser posterior a la entrevista original.';
                }

                if ($v('asistio_entrevista_reprogramada') !== '' && $fecha('fecha_reprogramada') && $fecha('fecha_reprogramada') > $hoy) {
                    $errores['asistio_entrevista_reprogramada'] = 'La asistencia se marca desde el día de la entrevista reprogramada.';
                }
            }
        }

        return $errores;
    }

    // ---------------------------------------------------------------
    // Registro masivo (lista pegada con nombres, celulares, DNI o usuarios mezclados)
    // ---------------------------------------------------------------

    public const MASIVO_MAXIMO = 300;

    private const ETIQUETAS_DATO = '/\b(?:dni|c\.?e\.?|cel(?:ular)?|tel(?:[eé]fono)?|wsp|whats\s?app|usuario|nombre)\b\s*[:.\-]?/iu';

    /**
     * Separa una línea en DNI, celular/usuario y nombre. «$forzado» obliga a tratar todo el texto como 'nombre', 'celular' o 'dni'.
     *
     * @return array{dni: string, celular: string, nombres: string}
     */
    public function analizarLinea(string $linea, string $forzado = 'auto'): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $linea));
        // Viñetas o numeración al inicio: «1) », «2. », «- », «• ».
        $texto = trim(preg_replace('/^(?:\d{1,3}\s*[\).\-]|[-•*·])\s+/u', '', $texto));
        $resultado = ['dni' => '', 'celular' => '', 'nombres' => ''];

        if ($texto === '') {
            return $resultado;
        }

        $limpio = trim(preg_replace(self::ETIQUETAS_DATO, ' ', $texto), " \t-–—,;:|/()[]");

        if ($forzado === 'nombre') {
            $resultado['nombres'] = $limpio;

            return $resultado;
        }

        if ($forzado === 'celular') {
            $resultado['celular'] = $this->celularNormalizado($limpio);

            return $resultado;
        }

        if ($forzado === 'dni') {
            $resultado['dni'] = preg_replace('/\s+/', '', $limpio);

            return $resultado;
        }

        $resto = $limpio;

        if (preg_match('/(?<!\d)(?:\+?51[\s-]?)?(9\d{2}[\s-]?\d{3}[\s-]?\d{3})(?!\d)/', $resto, $m)) {
            $resultado['celular'] = preg_replace('/\D+/', '', $m[1]);
            $resto = str_replace($m[0], ' ', $resto);
        }

        if (preg_match('/(?<!\d)(\d{8})(?!\d)/', $resto, $m)) {
            $resultado['dni'] = $m[1];
            $resto = str_replace($m[0], ' ', $resto);
        }

        $resto = trim(preg_replace('/\s+/u', ' ', $resto), " \t-–—,;:|/()[]");

        if ($resto !== '') {
            // Un nombre son varias palabras solo con letras; lo demás (una palabra, @usuario, emojis, números) es un usuario de WhatsApp.
            $esNombre = (bool) preg_match('/^\p{L}[\p{L}\.\'’\-]*(?: \p{L}[\p{L}\.\'’\-]*)+$/u', $resto);

            if ($resultado['celular'] === '' && ! $esNombre && ! preg_match('/^\d+$/', $resto)) {
                $resultado['celular'] = $resto;
            } elseif (preg_match('/^\d+$/', $resto)) {
                $resultado['celular'] = $resultado['celular'] ?: $resto;
            } else {
                $resultado['nombres'] = $resto;
            }
        }

        return $resultado;
    }

    /** Un número de celular se guarda sin espacios ni prefijo +51; un usuario de WhatsApp se guarda como viene. */
    private function celularNormalizado(string $valor): string
    {
        if (preg_match('/^\+?(?:51[\s-]?)?(9\d{2}[\s-]?\d{3}[\s-]?\d{3})$/', $valor, $m)) {
            return preg_replace('/\D+/', '', $m[1]);
        }

        return $valor;
    }

    private function claveCelular(?string $valor): string
    {
        $valor = trim((string) $valor);
        $digitos = preg_replace('/\D+/', '', $valor);

        if ($valor !== '' && preg_match('/^[\d\s+\-]+$/', $valor) && strlen($digitos) >= 9) {
            return substr($digitos, -9);
        }

        return mb_strtolower($valor);
    }

    private function claveNombre(?string $nombres, ?string $apellidos = null): string
    {
        return Str::ascii(mb_strtolower(trim(preg_replace('/\s+/u', ' ', trim((string) $nombres).' '.trim((string) $apellidos)))));
    }

    /** Convierte el texto pegado (una persona por línea) en filas revisables. */
    public function analizarLista(string $texto): array
    {
        $filas = [];

        foreach (preg_split('/\R/u', $texto) as $linea) {
            if (trim($linea) === '') {
                continue;
            }

            $filas[] = ['texto' => trim($linea), 'forzado' => 'auto'];

            if (count($filas) >= self::MASIVO_MAXIMO) {
                break;
            }
        }

        return $this->reclasificar($filas);
    }

    /**
     * Vuelve a interpretar cada fila y revisa si la persona ya existe (por DNI, celular/usuario, o nombre) o se repite en la lista.
     * Estados: nuevo, existe (no se puede registrar), repetido (no se puede registrar), parecido (mismo nombre: se revisa a mano).
     */
    public function reclasificar(array $filas): array
    {
        $existentes = CandidatoReclutamiento::query()->get(['id', 'dni_ce', 'nombres', 'apellidos', 'numero_celular']);
        $porDni = $porCelular = $porNombre = [];

        foreach ($existentes as $c) {
            if (filled($c->dni_ce)) {
                $porDni[mb_strtolower(trim($c->dni_ce))] ??= $c;
            }
            if (filled($c->numero_celular)) {
                $porCelular[$this->claveCelular($c->numero_celular)] ??= $c;
            }
            if (filled($c->nombres) || filled($c->apellidos)) {
                $porNombre[$this->claveNombre($c->nombres, $c->apellidos)] ??= $c;
            }
        }

        $vistos = ['dni' => [], 'celular' => [], 'nombre' => []];
        $resultado = [];

        foreach (array_values($filas) as $i => $fila) {
            $campos = $this->analizarLinea((string) $fila['texto'], (string) ($fila['forzado'] ?? 'auto'));
            $estado = 'nuevo';
            $detalle = '';
            $existente = null;

            if ($campos['dni'] === '' && $campos['celular'] === '' && $campos['nombres'] === '') {
                continue;
            }

            if ($campos['dni'] !== '' && isset($porDni[mb_strtolower($campos['dni'])])) {
                $existente = $porDni[mb_strtolower($campos['dni'])];
                $estado = 'existe';
            } elseif ($campos['celular'] !== '' && isset($porCelular[$this->claveCelular($campos['celular'])])) {
                $existente = $porCelular[$this->claveCelular($campos['celular'])];
                $estado = 'existe';
            } elseif ($campos['nombres'] !== '' && isset($porNombre[$this->claveNombre($campos['nombres'])])) {
                $existente = $porNombre[$this->claveNombre($campos['nombres'])];
                $estado = 'parecido';
            }

            if ($existente) {
                $detalle = ($estado === 'existe' ? 'Ya registrado: ' : 'Mismo nombre que: ').($existente->nombreCompleto() ?: ($existente->numero_celular ?: 'sin nombre'))
                    .' · '.self::ETAPAS[$this->etapa($existente->fresh())];
            }

            $claves = [
                'dni' => $campos['dni'] !== '' ? mb_strtolower($campos['dni']) : null,
                'celular' => $campos['celular'] !== '' ? $this->claveCelular($campos['celular']) : null,
                'nombre' => $campos['nombres'] !== '' ? $this->claveNombre($campos['nombres']) : null,
            ];

            if ($estado === 'nuevo' || $estado === 'parecido') {
                foreach (['dni', 'celular'] as $tipo) {
                    if ($claves[$tipo] !== null && isset($vistos[$tipo][$claves[$tipo]])) {
                        $estado = 'repetido';
                        $detalle = 'Repetido en la lista (línea '.($vistos[$tipo][$claves[$tipo]] + 1).')';
                        break;
                    }
                }
            }

            if ($estado === 'nuevo' && $claves['nombre'] !== null && isset($vistos['nombre'][$claves['nombre']])) {
                $estado = 'repetido';
                $detalle = 'Repetido en la lista (línea '.($vistos['nombre'][$claves['nombre']] + 1).')';
            }

            foreach ($claves as $tipo => $clave) {
                if ($clave !== null) {
                    $vistos[$tipo][$clave] ??= $i;
                }
            }

            $resultado[] = [
                'texto' => (string) $fila['texto'],
                'forzado' => (string) ($fila['forzado'] ?? 'auto'),
                'dni' => $campos['dni'],
                'celular' => $campos['celular'],
                'nombres' => $campos['nombres'],
                'estado' => $estado,
                'detalle' => $detalle,
                'incluir' => $estado === 'nuevo',
            ];
        }

        return $resultado;
    }

    /**
     * Registra las filas marcadas con los datos comunes (fecha de gestión, reclutador, área, puesto…).
     *
     * @return array{creados: int, omitidos: int}
     */
    public function crearMasivo(array $filas, array $comunes): array
    {
        $creados = [];

        CandidatoReclutamientoObserver::$origen = 'Registro masivo';

        try {
            DB::transaction(function () use ($filas, $comunes, &$creados) {
            foreach ($filas as $fila) {
                if (empty($fila['incluir']) || in_array($fila['estado'] ?? '', ['existe', 'repetido'], true)) {
                    continue;
                }

                $payload = $this->payload($comunes + [
                    'dni_ce' => $fila['dni'] ?? '',
                    'numero_celular' => $fila['celular'] ?? '',
                    'nombres' => $fila['nombres'] ?? '',
                ]);

                if ($this->sinDatosDePersona($payload)) {
                    continue;
                }

                $creados[] = CandidatoReclutamiento::create($payload);
            }
            });
        } finally {
            CandidatoReclutamientoObserver::$origen = null;
        }

        if ($creados) {
            $this->emitir(new ReclutamientoActualizado(
                tipo: 'tabla-actualizada',
                filaId: null,
                usuarioId: Auth::id(),
                usuarioNombre: Auth::user()->name,
                filas: array_map(fn ($c) => $this->fila($c->fresh('bloqueadoPor')), $creados),
            ));
        }

        return ['creados' => count($creados), 'omitidos' => count($filas) - count($creados)];
    }

    // ---------------------------------------------------------------
    // Bloqueo de edición (compartido con la grilla)
    // ---------------------------------------------------------------

    /** Nombre de quien tiene el registro en edición (si no es el usuario actual). */
    public function bloqueadoPorOtro(CandidatoReclutamiento $c): ?string
    {
        if ($c->bloqueado_por_id && $c->bloqueado_hasta?->isFuture() && $c->bloqueado_por_id !== Auth::id()) {
            return $c->bloqueadoPor?->name ?? 'otro usuario';
        }

        return null;
    }

    public function tomarBloqueo(CandidatoReclutamiento $c): void
    {
        $c->update(['bloqueado_por_id' => Auth::id(), 'bloqueado_hasta' => now()->addMinute()]);
        $this->difundir($c, 'fila-bloqueada', true);
    }

    public function renovarBloqueo(int $id): void
    {
        $c = CandidatoReclutamiento::find($id);

        if ($c && $c->bloqueado_por_id === Auth::id()) {
            $c->update(['bloqueado_hasta' => now()->addMinute()]);
        }
    }

    public function liberarBloqueo(int $id): void
    {
        $c = CandidatoReclutamiento::find($id);

        if ($c && $c->bloqueado_por_id === Auth::id()) {
            $c->update(['bloqueado_por_id' => null, 'bloqueado_hasta' => null]);
            $this->difundir($c);
        }
    }

    // ---------------------------------------------------------------
    // Guardar y eliminar
    // ---------------------------------------------------------------

    /** @return array{error?: string, duplicadoId?: int, id?: int} */
    public function guardar(array $datos, ?int $id): array
    {
        $payload = $this->payload($datos);

        if ($this->sinDatosDePersona($payload)) {
            return ['error' => 'Escribe al menos un dato del candidato: DNI, nombre, o celular / usuario de WhatsApp.'];
        }

        $candidato = $id ? CandidatoReclutamiento::with('bloqueadoPor')->find($id) : null;

        if ($id && ! $candidato) {
            return ['error' => 'Este candidato ya no existe: otra persona lo eliminó.'];
        }

        if ($candidato && $this->graduado($candidato)) {
            return ['error' => 'Este candidato ya pasó a capacitación y su gestión no se puede editar aquí.'];
        }

        if ($duplicado = $this->duplicado($payload, $candidato?->id)) {
            return ['error' => 'Ya existe un candidato con esos datos: '.$duplicado->nombreCompleto().' (DNI '.($duplicado->dni_ce ?: '—').').', 'duplicadoId' => $duplicado->id];
        }

        if ($candidato && ($otro = $this->bloqueadoPorOtro($candidato))) {
            return ['error' => $otro.' está editando este registro en este momento. Inténtalo en un minuto.'];
        }

        $payload['bloqueado_por_id'] = null;
        $payload['bloqueado_hasta'] = null;

        if ($candidato) {
            // La tipificación (y la fecha para volver a llamar) de un registro existente cambia solo registrando una llamada.
            $payload['tipificacion'] = $candidato->tipificacion;
            $payload['subtipificacion_rechazo'] = $candidato->subtipificacion_rechazo;
            $payload['volver_a_llamar_fecha'] = $candidato->volver_a_llamar_fecha?->toDateString();
            $payload['volver_a_llamar_hora'] = $candidato->volver_a_llamar_hora;
            $candidato->update($payload);
        } else {
            $candidato = DB::transaction(function () use ($payload) {
                $nuevo = CandidatoReclutamiento::create($payload);

                // Tipificar al registrar es la primera llamada.
                if (filled($payload['tipificacion'])) {
                    $nuevo->update(['intentos_contacto' => 1, 'ultimo_contacto_at' => now()]);
                    CandidatoEvento::create([
                        'reclutamiento_candidato_id' => $nuevo->id,
                        'user_id' => Auth::id(),
                        'tipo' => 'llamada',
                        'campo' => 'tipificacion',
                        'valor_nuevo' => mb_substr($this->resumenLlamada($payload['tipificacion'], $payload['subtipificacion_rechazo'], $payload['volver_a_llamar_fecha'], $payload['volver_a_llamar_hora']), 0, 500),
                    ]);
                }

                return $nuevo;
            });
        }

        $this->difundir($candidato);

        return ['id' => $candidato->id];
    }

    public function eliminar(int $id): ?string
    {
        $c = CandidatoReclutamiento::find($id);

        if (! $c) {
            return null;
        }

        if ($this->bloqueadoPorOtro($c) || ! $this->eliminable($c)) {
            return 'Solo se pueden eliminar registros vacíos: sin datos de la persona ni avance en el proceso.';
        }

        $c->delete();

        $this->emitir(new ReclutamientoActualizado(
            tipo: 'tabla-actualizada',
            filaId: $id,
            usuarioId: Auth::id(),
            usuarioNombre: Auth::user()->name,
            eliminadas: [$id],
        ));

        return null;
    }

    // ---------------------------------------------------------------
    // Sincronización en vivo con la grilla
    // ---------------------------------------------------------------

    /** Una fila tal como la entiende la grilla (lo que viaja por Reverb). */
    public function fila(CandidatoReclutamiento $c): array
    {
        return [
            'id' => $c->id,
            'mes' => $c->mes,
            'fecha_gestion' => $c->fecha_gestion?->format('Y-m-d'),
            'agente_reclutador' => $c->agente_reclutador,
            'area' => $c->area,
            'puesto' => $c->puesto,
            'campana' => $c->campana,
            'cargo' => $c->cargo,
            'dni_ce' => $c->dni_ce,
            'edad' => $c->edad,
            'nombres' => $c->nombres,
            'apellidos' => $c->apellidos,
            'numero_celular' => $c->numero_celular,
            'distrito' => $c->distrito,
            'observaciones' => $c->observaciones,
            'tipificacion' => $c->tipificacion,
            'subtipificacion_rechazo' => $c->subtipificacion_rechazo,
            'aceptacion_entrevista' => $c->aceptacion_entrevista,
            'fecha_entrevista' => $c->fecha_entrevista?->format('Y-m-d'),
            'hora_entrevista' => $c->hora_entrevista,
            'asistio_entrevista' => $c->asistio_entrevista,
            'fecha_reprogramada' => $c->fecha_reprogramada?->format('Y-m-d'),
            'asistio_entrevista_reprogramada' => $c->asistio_entrevista_reprogramada,
            'bloqueado_por_id' => $c->bloqueado_por_id,
            'bloqueado_por_nombre' => $c->bloqueadoPor?->name,
            'bloqueado_hasta' => $c->bloqueado_hasta?->toIso8601String(),
            'graduado' => $this->graduado($c),
            'no_apto_capacitacion' => $c->noAptoEnCapacitacion(),
            'no_apto_detalle' => $c->detalleNoAptoCapacitacion(),
        ];
    }

    private function difundir(CandidatoReclutamiento $c, string $tipo = 'tabla-actualizada', bool $bloqueada = false): void
    {
        $c = $c->fresh('bloqueadoPor');

        $this->emitir(new ReclutamientoActualizado(
            tipo: $tipo,
            filaId: $c->id,
            usuarioId: Auth::id(),
            usuarioNombre: Auth::user()->name,
            bloqueada: $bloqueada,
            filas: $tipo === 'tabla-actualizada' ? [$this->fila($c)] : [],
        ));
    }

    /** Si el servidor en vivo no responde, el guardado no se pierde: solo se omite el aviso a las demás pantallas. */
    private function emitir(ReclutamientoActualizado $evento): void
    {
        try {
            broadcast($evento);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ---------------------------------------------------------------
    // Vista del registro (etapa, avance y siguiente acción)
    // ---------------------------------------------------------------

    public function etapa(CandidatoReclutamiento $c): string
    {
        if ($c->apto) {
            return 'apto';
        }

        $situacion = $c->situacionSinApto();

        if ($this->graduado($c)) {
            return $situacion && $situacion['definitivo'] ? 'no_continua' : 'capacitacion';
        }

        if ($this->descartadoEnLlamada($c) || ($situacion && $situacion['definitivo'])) {
            return 'no_continua';
        }

        return $c->aceptacion_entrevista === 'SI' ? 'entrevista' : 'gestion';
    }

    /** Cápsula «Volver a llamar»: con fecha pasa a ser de hoy o vencida; sin fecha (casos antiguos) queda como pendiente. */
    private function accionLlamada(CandidatoReclutamiento $c, string $hoy): array
    {
        $intentos = (int) $c->intentos_contacto;
        $info = $intentos > 0 ? ' · '.$intentos.($intentos === 1 ? ' intento' : ' intentos') : '';
        $tipificado = 'Tipificado como '.mb_strtolower((string) $c->tipificacion).$info;
        $fecha = $c->volver_a_llamar_fecha?->toDateString();
        $hora = (string) $c->volver_a_llamar_hora;

        if (! $fecha) {
            return ['texto' => 'Volver a llamar', 'tono' => 'amber', 'detalle' => $tipificado, 'orden' => 2];
        }

        $cuando = Carbon::parse($fecha)->format('d/m').($hora !== '' ? ' '.$hora : '');

        return match (true) {
            $fecha === $hoy => ['texto' => 'Volver a llamar hoy'.($hora !== '' ? ' '.$hora : ''), 'tono' => 'amber', 'detalle' => $tipificado, 'orden' => 0],
            $fecha < $hoy => ['texto' => 'Volver a llamar · vencida', 'tono' => 'rose', 'detalle' => 'Debía llamarse el '.$cuando.$info, 'orden' => 1],
            default => ['texto' => 'Volver a llamar '.$cuando, 'tono' => 'amber', 'detalle' => $tipificado, 'orden' => 3],
        };
    }

    private function descartadoEnLlamada(CandidatoReclutamiento $c): bool
    {
        return in_array($c->tipificacion, self::FIN_GESTION, true);
    }

    /** Pasos cumplidos del recorrido (0 a 4): Gestión → Entrevista → Capacitación → Apto. */
    public function avance(CandidatoReclutamiento $c): int
    {
        return match (true) {
            (bool) $c->apto => 4,
            $this->graduado($c) => 3,
            $c->aceptacion_entrevista === 'SI' => 2,
            filled($c->tipificacion) => 1,
            default => 0,
        };
    }

    /**
     * Siguiente paso del candidato: texto, tono de la insignia, detalle y orden de prioridad (menor = más urgente).
     *
     * @return array{texto: string, tono: string, detalle: string, orden: int}
     */
    public function accion(CandidatoReclutamiento $c): array
    {
        $hoy = Carbon::now('America/Lima')->toDateString();
        $accion = fn (string $texto, string $tono, string $detalle, int $orden) => compact('texto', 'tono', 'detalle', 'orden');

        if ($c->apto) {
            return $accion('Apto', 'green', 'Ya puede continuar con su ficha de ingreso', 9);
        }

        $etapa = $this->etapa($c);

        if ($etapa === 'no_continua') {
            $detalle = $this->descartadoEnLlamada($c)
                ? ($c->subtipificacion_rechazo ?: (string) $c->tipificacion)
                : (string) $c->motivoSinApto();

            return $accion('No continúa', 'rose', $detalle, 9);
        }

        if ($etapa === 'capacitacion') {
            return $accion('En capacitación', 'blue', (string) $c->motivoSinApto(), 8);
        }

        if ($c->aceptacion_entrevista === 'SI') {
            $reprogramada = $c->asistio_entrevista === 'REPROGRAMADO';
            $fecha = ($reprogramada ? $c->fecha_reprogramada : $c->fecha_entrevista)?->toDateString();
            $hora = $reprogramada ? '' : (string) $c->hora_entrevista;
            $cuando = $fecha ? Carbon::parse($fecha)->format('d/m').($hora !== '' ? ' '.$hora : '') : '';

            // Resultado antiguo «SI» (sin indicar apto o no apto): ya asistió, solo falta definirlo.
            if (! $reprogramada && filled($c->asistio_entrevista)) {
                return $accion('Definir resultado', 'amber', 'Asistió a la entrevista; falta marcar apto o no apto', 1);
            }

            if ($reprogramada && ! $fecha) {
                return $accion('Falta fecha reprogramada', 'amber', 'Entrevista reprogramada sin nueva fecha', 1);
            }

            if (! $fecha) {
                return $accion('Falta fecha de entrevista', 'amber', 'Aceptó la entrevista', 1);
            }

            return match (true) {
                $fecha === $hoy => $accion('Entrevista hoy'.($hora !== '' ? ' '.$hora : ''), 'amber', 'Confirmar asistencia y marcar el resultado', 0),
                $fecha < $hoy => $accion('Marcar asistencia', 'rose', 'La entrevista fue el '.$cuando.' y no tiene resultado', 1),
                default => $accion('Entrevista '.$cuando, 'blue', 'Programada', 3),
            };
        }

        return match (true) {
            $c->aceptacion_entrevista === 'NO' => $accion('No aceptó entrevista', 'slate', (string) $c->tipificacion, 5),
            $c->tipificacion === 'INTERESADO - APTO' => $accion('Agendar entrevista', 'blue', 'Interesado: falta que acepte la entrevista', 2),
            in_array($c->tipificacion, self::VOLVER_A_LLAMAR, true) => $this->accionLlamada($c, $hoy),
            default => $accion('Sin tipificar', 'slate', 'Aún no se registró la llamada', 6),
        };
    }
}
