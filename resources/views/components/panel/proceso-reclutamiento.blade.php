<?php

use App\Models\CandidatoReclutamiento;
use App\Models\Campana;
use App\Models\OnboardingInvitation;
use App\Events\ReclutamientoActualizado;
use App\Services\QrCodeGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public array $modulos = [
        'candidatos' => 'Gestión de candidatos',
        'capacitacion' => 'Proceso de capacitación',
    ];

    public string $moduloActivo = 'candidatos';

    public string $rowsJson = '[]';
    public array $campanas = [];

    public const TIPIFICACION_OPTIONS = [
        'INTERESADO - APTO',
        'NO INTERESADO',
        'NO PASA FILTRO',
        'NO CONTESTA',
    ];

    public const SUBTIPIFICACION_OPTIONS = [
        'NO CONTESTA',
        'NO TIENE EXPERIENCIA',
        'NO CALIFICA',
        'DISTANCIA',
        'PENDIENTE DE LLAMADA',
        'SUELDO',
        'REFERIDO',
        'MALCRIADA',
        'LOGISTICA',
        'TRABAJANDO',
        'OTROS',
    ];

    public const ASISTIO_OPTIONS = [
        'SI, APTO',
        'SI, NO APTO',
        'NO',
        'REPROGRAMADO',
    ];

    public const CAPACITACION_ASISTIO_OPTIONS = [
        'SI, APTO',
        'SI, NO APTO',
        'NO',
        'REPROGRAMADO',
    ];

    public const CAPACITACION_ASISTIO_REPROGRAMADA_OPTIONS = [
        'SI, APTO',
        'SI, NO APTO',
        'NO',
    ];

    /** @var array<int, array<string, mixed>> */
    public array $capacitaciones = [];

    /** @var array<int, bool> */
    public array $capacitacionExpandido = [];

    public string $capacitacionBuscarNombre = '';
    public ?string $capacitacionFechaCapDesde = null;
    public ?string $capacitacionFechaCapHasta = null;
    public ?string $capacitacionFechaEntrevistaDesde = null;
    public ?string $capacitacionFechaEntrevistaHasta = null;
    public ?string $capacitacionFiltroApto = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->esGth() || Auth::user()->esAdmin(), 403);
        $this->rowsJson = $this->encodeRows();
        $this->campanas = Campana::query()
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter()
            ->values()
            ->all();
    }

    public function seleccionarModulo(string $modulo): void
    {
        if (! array_key_exists($modulo, $this->modulos)) {
            return;
        }

        $this->moduloActivo = $modulo;
    }

    // ---------------------------------------------------------------
    // Gestión de candidatos
    // ---------------------------------------------------------------

    public function candidatos()
    {
        return CandidatoReclutamiento::query()
            ->orderBy('id')
            ->limit(25)
            ->get();
    }

    protected function camposFormulario(): array
    {
        return [
            'mes', 'fecha_gestion', 'agente_reclutador', 'campana', 'dni_ce', 'edad',
            'nombres', 'apellidos', 'numero_celular', 'distrito', 'observaciones',
            'tipificacion', 'subtipificacion_rechazo',
            'aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
            'fecha_reprogramada', 'asistio_entrevista_reprogramada',
        ];
    }

    protected function camposTexto(): array
    {
        return [
            'mes', 'agente_reclutador', 'campana', 'dni_ce', 'nombres', 'apellidos', 'numero_celular',
            'distrito', 'observaciones', 'tipificacion', 'subtipificacion_rechazo',
            'aceptacion_entrevista', 'asistio_entrevista', 'asistio_entrevista_reprogramada',
        ];
    }

    public function candidatoGraduadoACapacitacion(CandidatoReclutamiento $candidato): bool
    {
        return $candidato->asistio_entrevista === 'SI, APTO'
            || ($candidato->asistio_entrevista === 'REPROGRAMADO' && $candidato->asistio_entrevista_reprogramada === 'SI, APTO');
    }

    protected function construirPayload(array $fila): array
    {
        $payload = [];
        foreach ($this->camposFormulario() as $campo) {
            $valor = $fila[$campo] ?? null;
            $payload[$campo] = $valor === '' ? null : $valor;
        }

        foreach ($this->camposTexto() as $campoTexto) {
            $payload[$campoTexto] = $payload[$campoTexto] === null
                ? null
                : mb_strtoupper(trim((string) $payload[$campoTexto]));
        }

        if (! in_array($payload['tipificacion'], ['NO INTERESADO', 'NO PASA FILTRO'], true)) {
            $payload['subtipificacion_rechazo'] = null;
        }

        if ($payload['asistio_entrevista'] !== 'REPROGRAMADO') {
            $payload['fecha_reprogramada'] = null;
            $payload['asistio_entrevista_reprogramada'] = null;
        }

        $payload['edad'] = $payload['edad'] === null || $payload['edad'] === ''
            ? null
            : min(99, max(0, (int) $payload['edad']));

        return $payload;
    }

    public function guardarFila(array $fila): void
    {
        if ($this->filaVacia($fila)) {
            return;
        }

        $payload = $this->construirPayload($fila);

        $candidato = ! empty($fila['id'])
            ? CandidatoReclutamiento::with('bloqueadoPor')->find($fila['id'])
            : null;

        if (! $candidato && (! empty($payload['dni_ce']) || ! empty($payload['nombres']) || ! empty($payload['apellidos']))) {
            $candidato = $this->filaDuplicada($payload);
        }

        if ($candidato && $this->candidatoGraduadoACapacitacion($candidato)) {
            return;
        }

        $payload['bloqueado_por_id'] = Auth::id();
        $payload['bloqueado_hasta'] = now()->addMinute();

        if ($candidato) {
            $bloqueoActivo = $candidato->bloqueado_por_id
                && $candidato->bloqueado_hasta?->isFuture();
            if ($bloqueoActivo && $candidato->bloqueado_por_id !== Auth::id()) {
                $this->dispatch(
                    'fila-bloqueada',
                    filaId: $candidato->id,
                    bloqueada: true,
                    usuario: $candidato->bloqueadoPor?->name ?? 'otro usuario',
                );
                return;
            }

            $candidato->update($payload);
        } else {
            $candidato = CandidatoReclutamiento::create($payload);
        }

        $this->dispatch('fila-guardada', tempId: $fila['id'] ?? null, id: $candidato->id);

        broadcast(new ReclutamientoActualizado(
            tipo: 'tabla-actualizada',
            filaId: $candidato->id,
            usuarioId: Auth::id(),
            usuarioNombre: Auth::user()->name,
            filas: $this->filasActuales(),
        ));
    }

    public function bloquearFila(int $id): void
    {
        $candidato = CandidatoReclutamiento::with('bloqueadoPor')->find($id);
        if (! $candidato) {
            return;
        }

        $bloqueoActivo = $candidato->bloqueado_por_id
            && $candidato->bloqueado_hasta?->isFuture();

        if ($bloqueoActivo && $candidato->bloqueado_por_id !== Auth::id()) {
            $this->dispatch(
                'fila-bloqueada',
                filaId: $id,
                bloqueada: true,
                usuario: $candidato->bloqueadoPor?->name ?? 'otro usuario',
            );

            return;
        }

        $candidato->update([
            'bloqueado_por_id' => Auth::id(),
            'bloqueado_hasta' => now()->addMinute(),
        ]);

        broadcast(new ReclutamientoActualizado(
            tipo: 'fila-bloqueada',
            filaId: $id,
            usuarioId: Auth::id(),
            usuarioNombre: Auth::user()->name,
            bloqueada: true,
        ));

        $this->dispatch(
            'fila-bloqueada',
            filaId: $id,
            bloqueada: false,
            usuario: Auth::user()->name,
        );
    }

    public function eliminarFila(int $id): void
    {
        $this->dispatch('fila-eliminacion-denegada', filaId: $id);
    }

    public function guardarBase(): void
    {
        $data = json_decode($this->rowsJson, true) ?: [];

        foreach ($data as $fila) {
            if ($this->filaVacia($fila)) {
                continue;
            }

            $payload = $this->construirPayload($fila);

            if (! empty($fila['id'])) {
                $candidato = CandidatoReclutamiento::with('bloqueadoPor')->find($fila['id']);
                if (! $candidato) {
                    $candidato = $this->filaDuplicada($payload);
                }

                if (! $candidato) {
                    continue;
                }

                if ($this->candidatoGraduadoACapacitacion($candidato)) {
                    continue;
                }

                $bloqueoActivo = $candidato->bloqueado_por_id
                    && $candidato->bloqueado_hasta?->isFuture();
                if ($bloqueoActivo && $candidato->bloqueado_por_id !== Auth::id()) {
                    $this->dispatch(
                        'fila-bloqueada',
                        filaId: $candidato->id,
                        bloqueada: true,
                        usuario: $candidato->bloqueadoPor?->name ?? 'otro usuario',
                    );
                    continue;
                }

                $payload['bloqueado_por_id'] = null;
                $payload['bloqueado_hasta'] = null;
                $candidato->update($payload);
                continue;
            }

            $candidato = $this->filaDuplicada($payload);
            if ($candidato) {
                if ($this->candidatoGraduadoACapacitacion($candidato)) {
                    continue;
                }

                $candidato->update($payload);
                continue;
            }

            CandidatoReclutamiento::create($payload);
        }

        $this->rowsJson = $this->encodeRows();

        broadcast(new ReclutamientoActualizado(
            tipo: 'tabla-actualizada',
            filaId: null,
            usuarioId: Auth::id(),
            usuarioNombre: Auth::user()->name,
            filas: $this->filasActuales(),
        ));
        $this->dispatch('reclutamiento-guardado');
    }

    protected function filasActuales(): array
    {
        return $this->candidatos()->map(fn ($candidato) => [
            'id' => $candidato->id,
            'mes' => $candidato->mes,
            'fecha_gestion' => $candidato->fecha_gestion?->format('Y-m-d'),
            'agente_reclutador' => $candidato->agente_reclutador,
            'campana' => $candidato->campana,
            'dni_ce' => $candidato->dni_ce,
            'edad' => $candidato->edad,
            'nombres' => $candidato->nombres,
            'apellidos' => $candidato->apellidos,
            'numero_celular' => $candidato->numero_celular,
            'distrito' => $candidato->distrito,
            'observaciones' => $candidato->observaciones,
            'tipificacion' => $candidato->tipificacion,
            'subtipificacion_rechazo' => $candidato->subtipificacion_rechazo,
            'aceptacion_entrevista' => $candidato->aceptacion_entrevista,
            'fecha_entrevista' => $candidato->fecha_entrevista?->format('Y-m-d'),
            'hora_entrevista' => $candidato->hora_entrevista,
            'asistio_entrevista' => $candidato->asistio_entrevista,
            'fecha_reprogramada' => $candidato->fecha_reprogramada?->format('Y-m-d'),
            'asistio_entrevista_reprogramada' => $candidato->asistio_entrevista_reprogramada,
            'bloqueado_por_id' => $candidato->bloqueado_por_id,
            'bloqueado_por_nombre' => $candidato->bloqueadoPor?->name,
            'bloqueado_hasta' => $candidato->bloqueado_hasta?->toIso8601String(),
            'graduado' => $this->candidatoGraduadoACapacitacion($candidato),
            'no_apto_capacitacion' => $candidato->noAptoEnCapacitacion(),
            'no_apto_detalle' => $candidato->detalleNoAptoCapacitacion(),
        ])->all();
    }

    protected function filaVacia(array $fila): bool
    {
        foreach ($this->camposFormulario() as $campo) {
            if (! empty($fila[$campo])) {
                return false;
            }
        }

        return true;
    }

    protected function filaDuplicada(array $payload): ?CandidatoReclutamiento
    {
        $dni = trim((string) ($payload['dni_ce'] ?? ''));
        $nombreCompleto = trim(($payload['nombres'] ?? '') . ' ' . ($payload['apellidos'] ?? ''));
        $telefono = preg_replace('/\D+/', '', (string) ($payload['numero_celular'] ?? ''));

        if ($dni !== '') {
            $candidato = CandidatoReclutamiento::query()
                ->whereRaw('LOWER(TRIM(COALESCE(dni_ce, ""))) = ?', [mb_strtolower($dni)])
                ->first();

            if ($candidato) {
                return $candidato;
            }
        }

        if ($nombreCompleto !== '' && $telefono !== '') {
            $candidato = CandidatoReclutamiento::query()
                ->whereRaw('LOWER(TRIM(CONCAT(COALESCE(nombres, ""), " ", COALESCE(apellidos, "")))) = ?', [mb_strtolower($nombreCompleto)])
                ->whereRaw('REPLACE(REPLACE(REPLACE(numero_celular, " ", ""), "-", ""), "+", "") = ?', [$telefono])
                ->first();

            if ($candidato) {
                return $candidato;
            }
        }

        return null;
    }

    protected function encodeRows(): string
    {
        return json_encode(
            $this->filasActuales(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }

    // ---------------------------------------------------------------
    // Proceso de capacitación
    // ---------------------------------------------------------------

    public function candidatosCapacitacion()
    {
        return CandidatoReclutamiento::query()
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($candidato) => $this->candidatoGraduadoACapacitacion($candidato))
            ->each(function ($candidato) {
                if (! isset($this->capacitaciones[$candidato->id])) {
                    $this->capacitaciones[$candidato->id] = $this->valoresCapacitacion($candidato);
                }
            })
            ->filter(function ($candidato) {
                if ($this->capacitacionBuscarNombre !== '') {
                    $termino = mb_strtolower($this->capacitacionBuscarNombre);
                    if (! str_contains(mb_strtolower($candidato->nombreCompleto()), $termino)) {
                        return false;
                    }
                }

                if ($this->capacitacionFechaCapDesde || $this->capacitacionFechaCapHasta) {
                    $enRango = fn ($fecha) => $fecha
                        && (! $this->capacitacionFechaCapDesde || $fecha->gte(Carbon::parse($this->capacitacionFechaCapDesde)))
                        && (! $this->capacitacionFechaCapHasta || $fecha->lte(Carbon::parse($this->capacitacionFechaCapHasta)));

                    if (! $enRango($candidato->capacitacion_1) && ! $enRango($candidato->capacitacion_2)) {
                        return false;
                    }
                }

                if ($this->capacitacionFechaEntrevistaDesde || $this->capacitacionFechaEntrevistaHasta) {
                    $fechaRelevante = $candidato->asistio_entrevista === 'SI, APTO'
                        ? $candidato->fecha_entrevista
                        : $candidato->fecha_reprogramada;

                    if (! $fechaRelevante) {
                        return false;
                    }

                    if ($this->capacitacionFechaEntrevistaDesde && $fechaRelevante->lt(Carbon::parse($this->capacitacionFechaEntrevistaDesde))) {
                        return false;
                    }

                    if ($this->capacitacionFechaEntrevistaHasta && $fechaRelevante->gt(Carbon::parse($this->capacitacionFechaEntrevistaHasta))) {
                        return false;
                    }
                }

                if ($this->capacitacionFiltroApto === 'SI' && ! $candidato->apto) {
                    return false;
                }

                if ($this->capacitacionFiltroApto === 'NO' && $candidato->apto) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    public function actualizarFiltrosCapacitacion(array $filtros): void
    {
        $this->capacitacionBuscarNombre = (string) ($filtros['nombre'] ?? '');
        $this->capacitacionFechaCapDesde = $filtros['fecha_capacitacion_desde'] ?? null;
        $this->capacitacionFechaCapHasta = $filtros['fecha_capacitacion_hasta'] ?? null;
        $this->capacitacionFechaEntrevistaDesde = $filtros['fecha_entrevista_desde'] ?? null;
        $this->capacitacionFechaEntrevistaHasta = $filtros['fecha_entrevista_hasta'] ?? null;
        $this->capacitacionFiltroApto = $filtros['apto'] ?? null;
    }

    public function limpiarFiltrosCapacitacion(): void
    {
        $this->capacitacionBuscarNombre = '';
        $this->capacitacionFechaCapDesde = null;
        $this->capacitacionFechaCapHasta = null;
        $this->capacitacionFechaEntrevistaDesde = null;
        $this->capacitacionFechaEntrevistaHasta = null;
        $this->capacitacionFiltroApto = null;
    }

    public function capacitacionFiltrosEstado(): array
    {
        return [
            'nombre' => $this->capacitacionBuscarNombre,
            'fecha_capacitacion_desde' => $this->capacitacionFechaCapDesde,
            'fecha_capacitacion_hasta' => $this->capacitacionFechaCapHasta,
            'fecha_entrevista_desde' => $this->capacitacionFechaEntrevistaDesde,
            'fecha_entrevista_hasta' => $this->capacitacionFechaEntrevistaHasta,
            'apto' => $this->capacitacionFiltroApto,
        ];
    }

    public function toggleCapacitacionExpandido(int $id): void
    {
        $this->capacitacionExpandido[$id] = ! ($this->capacitacionExpandido[$id] ?? false);

        if (! isset($this->capacitaciones[$id])) {
            $candidato = CandidatoReclutamiento::find($id);
            if ($candidato) {
                $this->capacitaciones[$id] = $this->valoresCapacitacion($candidato);
            }
        }
    }

    protected function valoresCapacitacion(CandidatoReclutamiento $candidato): array
    {
        return [
            'capacitacion_1' => $candidato->capacitacion_1?->format('Y-m-d'),
            'asistio_cap_1' => $candidato->asistio_cap_1,
            'obs_1' => $candidato->obs_1,
            'capacitacion_1_reprogramada' => $candidato->capacitacion_1_reprogramada?->format('Y-m-d'),
            'asistio_cap_1_reprogramada' => $candidato->asistio_cap_1_reprogramada,
            'obs_1_reprogramada' => $candidato->obs_1_reprogramada,
            'capacitacion_2' => $candidato->capacitacion_2?->format('Y-m-d'),
            'asistio_cap_2' => $candidato->asistio_cap_2,
            'obs_2' => $candidato->obs_2,
            'capacitacion_2_reprogramada' => $candidato->capacitacion_2_reprogramada?->format('Y-m-d'),
            'asistio_cap_2_reprogramada' => $candidato->asistio_cap_2_reprogramada,
            'obs_2_reprogramada' => $candidato->obs_2_reprogramada,
            'apto' => (bool) $candidato->apto,
        ];
    }

    public function puedeCapacitacion2(array $valores): bool
    {
        return ($valores['asistio_cap_1'] ?? null) === 'SI, APTO'
            || (($valores['asistio_cap_1'] ?? null) === 'REPROGRAMADO' && ($valores['asistio_cap_1_reprogramada'] ?? null) === 'SI, APTO');
    }

    public function puedeMarcarApto(array $valores): bool
    {
        return ($valores['asistio_cap_2'] ?? null) === 'SI, APTO'
            || (($valores['asistio_cap_2'] ?? null) === 'REPROGRAMADO' && ($valores['asistio_cap_2_reprogramada'] ?? null) === 'SI, APTO');
    }

    public function puedeGenerarInvitacion(array $valores): bool
    {
        return ! empty($valores['capacitacion_1']);
    }

    public function resumenReprogramacion(array $valores, string $numero): string
    {
        $fecha = $valores["capacitacion_{$numero}_reprogramada"] ?? null;
        $asistio = $valores["asistio_cap_{$numero}_reprogramada"] ?? null;

        if (! $fecha && ! $asistio) {
            return 'Reprogramación pendiente de completar';
        }

        $fechaTexto = $fecha ? Carbon::parse($fecha)->format('d/m/Y') : 'sin fecha';

        return "{$fechaTexto} · " . ($asistio ?: 'sin registrar');
    }

    public function guardarObservacionCapacitacion(int $id, string $campo, string $valor): void
    {
        if (! in_array($campo, ['obs_1', 'obs_2', 'obs_1_reprogramada', 'obs_2_reprogramada'], true)) {
            return;
        }

        if (! isset($this->capacitaciones[$id])) {
            $candidato = CandidatoReclutamiento::find($id);
            if (! $candidato) {
                return;
            }
            $this->capacitaciones[$id] = $this->valoresCapacitacion($candidato);
        }

        $this->capacitaciones[$id][$campo] = $valor;
        $this->guardarCapacitacion($id);
    }

    public function guardarCapacitacion(int $id): void
    {
        $candidato = CandidatoReclutamiento::find($id);
        if (! $candidato) {
            return;
        }

        $datos = $this->capacitaciones[$id] ?? $this->valoresCapacitacion($candidato);

        foreach (['capacitacion_1', 'obs_1', 'capacitacion_1_reprogramada', 'obs_1_reprogramada',
            'capacitacion_2', 'obs_2', 'capacitacion_2_reprogramada', 'obs_2_reprogramada'] as $campo) {
            if (($datos[$campo] ?? null) !== null) {
                $datos[$campo] = trim((string) $datos[$campo]);
            }
        }

        foreach (['asistio_cap_1', 'asistio_cap_1_reprogramada', 'asistio_cap_2', 'asistio_cap_2_reprogramada'] as $campo) {
            if (! empty($datos[$campo])) {
                $datos[$campo] = mb_strtoupper(trim((string) $datos[$campo]));
            }
        }

        // Candado: si ya estaba APTO y el valor entrante sigue siendo APTO (no se
        // desmarcó explícitamente), los campos que alimentan esa condición se
        // restauran a lo que hay en BD ANTES de aplicar cualquier cascada — así una
        // manipulación del HTML deshabilitado no puede colarse disfrazada de cascada.
        $candadoActivo = $candidato->apto && ($datos['apto'] ?? false);

        if ($candadoActivo) {
            foreach ([
                'capacitacion_1', 'asistio_cap_1', 'capacitacion_1_reprogramada', 'asistio_cap_1_reprogramada',
                'capacitacion_2', 'asistio_cap_2', 'capacitacion_2_reprogramada', 'asistio_cap_2_reprogramada',
            ] as $campoBloqueado) {
                $valorActual = $candidato->{$campoBloqueado};
                $datos[$campoBloqueado] = $valorActual instanceof \DateTimeInterface
                    ? $valorActual->format('Y-m-d')
                    : $valorActual;
            }
        } else {
            if (empty($datos['capacitacion_1'])) {
                $datos['asistio_cap_1'] = null;
                $datos['capacitacion_1_reprogramada'] = null;
                $datos['asistio_cap_1_reprogramada'] = null;
                $datos['obs_1_reprogramada'] = null;
            }

            if (($datos['asistio_cap_1'] ?? null) !== 'REPROGRAMADO') {
                $datos['capacitacion_1_reprogramada'] = null;
                $datos['asistio_cap_1_reprogramada'] = null;
                $datos['obs_1_reprogramada'] = null;
            } elseif (empty($datos['capacitacion_1_reprogramada'])) {
                $datos['asistio_cap_1_reprogramada'] = null;
            }

            if (! $this->puedeCapacitacion2($datos)) {
                $datos['capacitacion_2'] = null;
                $datos['asistio_cap_2'] = null;
                $datos['obs_2'] = null;
                $datos['capacitacion_2_reprogramada'] = null;
                $datos['asistio_cap_2_reprogramada'] = null;
                $datos['obs_2_reprogramada'] = null;
            } else {
                if (empty($datos['capacitacion_2'])) {
                    $datos['asistio_cap_2'] = null;
                    $datos['capacitacion_2_reprogramada'] = null;
                    $datos['asistio_cap_2_reprogramada'] = null;
                    $datos['obs_2_reprogramada'] = null;
                } elseif (($datos['asistio_cap_2'] ?? null) !== 'REPROGRAMADO') {
                    $datos['capacitacion_2_reprogramada'] = null;
                    $datos['asistio_cap_2_reprogramada'] = null;
                    $datos['obs_2_reprogramada'] = null;
                } elseif (empty($datos['capacitacion_2_reprogramada'])) {
                    $datos['asistio_cap_2_reprogramada'] = null;
                }
            }

            if (! $this->puedeMarcarApto($datos)) {
                $datos['apto'] = false;
            }
        }

        $nuevoApto = (bool) ($datos['apto'] ?? false);
        if (! $candidato->apto && $nuevoApto) {
            $datos['fecha_apto'] = now();
        } elseif (! $nuevoApto) {
            $datos['fecha_apto'] = null;
        } else {
            unset($datos['fecha_apto']);
        }

        $datos['apto'] = $nuevoApto;

        $candidato->update($datos);

        $this->capacitaciones[$id] = $this->valoresCapacitacion($candidato);

        $this->dispatch('reclutamiento-filas-actualizadas', filas: $this->filasActuales());

        broadcast(new ReclutamientoActualizado(
            tipo: 'tabla-actualizada',
            filaId: $candidato->id,
            usuarioId: Auth::id(),
            usuarioNombre: Auth::user()->name,
            filas: $this->filasActuales(),
        ));
    }

    public function generarInvitacionCapacitacion(int $id): void
    {
        $candidato = CandidatoReclutamiento::find($id);
        if (! $candidato) {
            return;
        }

        $yaExiste = OnboardingInvitation::where('reclutamiento_candidato_id', $id)->exists();
        if ($yaExiste) {
            return;
        }

        $invitacion = OnboardingInvitation::generar($candidato->nombreCompleto());
        $invitacion->update([
            'reclutamiento_candidato_id' => $id,
            'creado_por' => Auth::id(),
        ]);
    }

    public function invitacionDe(int $candidatoId): ?OnboardingInvitation
    {
        return OnboardingInvitation::where('reclutamiento_candidato_id', $candidatoId)->latest()->first();
    }
};
?>

<div class="space-y-6 reclutamiento-compact">
    <div class="card-panel">
        <div class="reclutamiento-tabs">
            @foreach ($modulos as $clave => $etiqueta)
                <button
                    type="button"
                    wire:click="seleccionarModulo('{{ $clave }}')"
                    class="reclutamiento-tab {{ $moduloActivo === $clave ? 'is-active' : '' }}"
                >
                    {{ $etiqueta }}
                </button>
            @endforeach
        </div>

    <div class="mt-5" @style(['display: none' => $moduloActivo !== 'candidatos'])>
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h3 class="text-xl font-semibold text-slate-900">Gestion de candidatos</h3>
            </div>
            <div class="flex gap-2">
                <button type="button" id="agregar-candidato" class="btn-primary inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Agregar fila" title="Agregar fila">
                    <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button type="button" id="ampliar-tabla" class="btn-secondary inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Abrir vista ampliada" title="Abrir vista ampliada">
                    <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3" />
                        <path d="M16 3h3a2 2 0 0 1 2 2v3" />
                        <path d="M21 16v3a2 2 0 0 1-2 2h-3" />
                        <path d="M3 16v3a2 2 0 0 0 2 2h3" />
                    </svg>
                </button>
                <label class="btn-secondary inline-flex h-10 w-10 cursor-pointer items-center justify-center p-0" aria-label="Importar Excel" title="Importar Excel">
                    <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <path d="m17 8-5-5-5 5" />
                        <path d="M12 3v12" />
                    </svg>
                    <input id="importar-candidatos" type="file" accept=".xlsx,.xls,.csv" class="hidden">
                </label>
                <button type="submit" form="reclutamiento-form" class="btn-primary inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Guardar cambios" title="Guardar cambios">
                    <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                        <path d="M17 21v-8H7v8" />
                        <path d="M7 3v5h8" />
                    </svg>
                </button>
            </div>
        </div>

        <div id="reclutamiento-filtros" wire:ignore class="reclutamiento-filter-bar mb-4"></div>

        <form id="reclutamiento-form" wire:submit.prevent="guardarBase" class="space-y-4">
            <input type="hidden" id="rowsJson" name="rowsJson" value="{{ $rowsJson }}">
            <script type="application/json" id="reclutamiento-grid-data">{!! $rowsJson !!}</script>
            <script type="application/json" id="reclutamiento-campanas">@json($campanas)</script>
            <script type="application/json" id="reclutamiento-tipificacion-options">@json(self::TIPIFICACION_OPTIONS)</script>
            <script type="application/json" id="reclutamiento-subtipificacion-options">@json(self::SUBTIPIFICACION_OPTIONS)</script>
            <script type="application/json" id="reclutamiento-asistio-options">@json(self::ASISTIO_OPTIONS)</script>
            <script type="application/json" id="reclutamiento-usuario">@json(['id' => Auth::id(), 'nombre' => Auth::user()->name])</script>

            <div id="reclutamiento-grid-host" wire:ignore class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="reclutamiento-grid-scroll">
                    <table id="reclutamiento-grid" class="min-w-[2400px] table-auto border-separate border-spacing-0 text-left text-sm">
                        <thead class="bg-slate-100 text-slate-700">
                            <tr>
                                <th colspan="2" class="border-b border-slate-200 bg-slate-500 px-3 py-3 text-center text-white">GESTIÓN</th>
                                <th colspan="2" class="border-b border-slate-200 bg-cyan-200 px-3 py-3 text-center text-slate-900">BASE</th>
                                <th colspan="7" class="border-b border-slate-200 bg-teal-800 px-3 py-3 text-center text-white">DATOS PRIMARIOS DE GESTIÓN</th>
                                <th colspan="2" class="border-b border-slate-200 bg-slate-300 px-3 py-3 text-center text-slate-900">GESTIÓN DE LLAMADAS</th>
                                <th colspan="6" class="border-b border-slate-200 bg-cyan-200 px-3 py-3 text-center text-slate-900">ENTREVISTA</th>
                                <th rowspan="2" class="border-b border-slate-200 bg-slate-100 px-3 py-3 text-center text-slate-700">ACCIONES</th>
                            </tr>
                            <tr>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Mes</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Fecha gestión</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Agente reclutador</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Campaña</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">DNI / C.E</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Edad</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Nombres</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Apellidos</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Número celular</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Distrito</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Observaciones</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Tipificación</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Subtipificación rechazo</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Aceptación entrevista</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Fecha entrevista</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Hora de entrevista</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Asistió entrevista</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Fecha reprogramada</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Asistió ent. Rep</th>
                            </tr>
                        </thead>
                        <tbody id="reclutamiento-body"></tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>

    <div id="reclutamiento-grid-modal" wire:ignore class="table-expand-modal hidden" role="dialog" aria-modal="true" aria-labelledby="reclutamiento-grid-modal-title">
        <div class="table-expand-modal-panel">
            <div class="table-expand-modal-header">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-600">GTH</p>
                    <h3 id="reclutamiento-grid-modal-title" class="mt-1 text-lg font-semibold text-slate-900">Gestión de candidatos</h3>
                </div>
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center gap-1 rounded-lg bg-slate-100 p-1" aria-label="Controles de escala de la tabla">
                        <button type="button" id="reducir-tabla-ampliada" class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-700 transition hover:bg-white" aria-label="Reducir tabla" title="Reducir tabla">
                            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14" /></svg>
                        </button>
                        <button type="button" id="restablecer-tabla-ampliada" class="min-w-14 rounded px-2 py-1 text-xs font-semibold text-slate-600 transition hover:bg-white" aria-label="Restablecer escala" title="Restablecer escala">100%</button>
                        <button type="button" id="ampliar-tabla-ampliada" class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-700 transition hover:bg-white" aria-label="Ampliar tabla" title="Ampliar tabla">
                            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14" /><path d="M5 12h14" /></svg>
                        </button>
                    </div>
                    <button type="button" id="agregar-candidato-ampliado" class="btn-primary inline-flex h-9 w-9 items-center justify-center p-0" aria-label="Agregar fila" title="Agregar fila">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14" />
                            <path d="M5 12h14" />
                        </svg>
                    </button>
                    <button type="button" id="cerrar-tabla-ampliada" class="btn-secondary inline-flex h-9 w-9 items-center justify-center p-0" aria-label="Cerrar vista ampliada" title="Cerrar vista ampliada">
                        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 6l12 12" />
                            <path d="M18 6L6 18" />
                        </svg>
                    </button>
                </div>
            </div>
            <div id="reclutamiento-filtros-ampliada" wire:ignore class="reclutamiento-filter-bar border-b border-slate-200 px-5 py-3"></div>
            <div id="reclutamiento-grid-modal-body" class="table-expand-modal-body"></div>
        </div>
    </div>

    @if ($moduloActivo === 'capacitacion')
        <div class="mt-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="text-xl font-semibold text-slate-900">Proceso de capacitación</h3>
                </div>
            </div>

            <div id="capacitacion-filtros" wire:ignore class="reclutamiento-filter-bar mb-4"></div>
            <script type="application/json" id="capacitacion-filtros-estado">@json($this->capacitacionFiltrosEstado())</script>

            <div class="overflow-hidden rounded-xl border border-slate-200">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-100 text-slate-700">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2">Nombre</th>
                            <th class="px-3 py-2">Capacitación 1</th>
                            <th class="px-3 py-2">Asistió cap. 1</th>
                            <th class="px-3 py-2">Obs. 1</th>
                            <th class="px-3 py-2">Capacitación 2</th>
                            <th class="px-3 py-2">Asistió cap. 2</th>
                            <th class="px-3 py-2">Obs. 2</th>
                            <th class="px-3 py-2">Apto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->candidatosCapacitacion() as $candidato)
                            @php($valores = $capacitaciones[$candidato->id] ?? $this->valoresCapacitacion($candidato))
                            @php($expandido = $capacitacionExpandido[$candidato->id] ?? false)
                            @php($invitacion = $this->invitacionDe($candidato->id))
                            <tr class="reclutamiento-capacitacion-row" wire:key="cap-row-{{ $candidato->id }}">
                                <td class="px-3 py-2">
                                    <button type="button" wire:click="toggleCapacitacionExpandido({{ $candidato->id }})" class="relative text-slate-400 transition hover:text-slate-600" aria-label="Expandir">
                                        <svg class="h-4 w-4 {{ $expandido ? 'rotate-90' : '' }} transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                        @if (($valores['asistio_cap_1'] ?? null) === 'REPROGRAMADO' || ($valores['asistio_cap_2'] ?? null) === 'REPROGRAMADO')
                                            <span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-amber-500" aria-hidden="true" title="Tiene una reprogramación pendiente"></span>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-3 py-2 font-medium text-slate-900">{{ $candidato->nombreCompleto() }}</td>
                                <td class="px-3 py-2">
                                    <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_1" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto)>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_1" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto || empty($valores['capacitacion_1']))>
                                            <option value="">-</option>
                                            @foreach (self::CAPACITACION_ASISTIO_OPTIONS as $opcion)
                                                <option value="{{ $opcion }}">{{ $opcion }}</option>
                                            @endforeach
                                        </select>
                                        @if (($valores['asistio_cap_1'] ?? null) === 'REPROGRAMADO')
                                            <span class="reclutamiento-info-badge" tabindex="0" data-tooltip="{{ $this->resumenReprogramacion($valores, '1') }}">i</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <button type="button" data-obs-toggle data-obs-candidato="{{ $candidato->id }}" data-obs-field="obs_1" data-obs-value="{{ $valores['obs_1'] ?? '' }}" data-obs-label="Observación cap. 1" class="reclutamiento-obs-button {{ filled($valores['obs_1'] ?? null) ? 'has-value' : '' }}" @disabled($candidato->apto) aria-label="Observación cap. 1" title="Observación cap. 1">
                                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                        </svg>
                                    </button>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_2" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto || ! $this->puedeCapacitacion2($valores))>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_2" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto || empty($valores['capacitacion_2']))>
                                            <option value="">-</option>
                                            @foreach (self::CAPACITACION_ASISTIO_OPTIONS as $opcion)
                                                <option value="{{ $opcion }}">{{ $opcion }}</option>
                                            @endforeach
                                        </select>
                                        @if (($valores['asistio_cap_2'] ?? null) === 'REPROGRAMADO')
                                            <span class="reclutamiento-info-badge" tabindex="0" data-tooltip="{{ $this->resumenReprogramacion($valores, '2') }}">i</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <button type="button" data-obs-toggle data-obs-candidato="{{ $candidato->id }}" data-obs-field="obs_2" data-obs-value="{{ $valores['obs_2'] ?? '' }}" data-obs-label="Observación cap. 2" class="reclutamiento-obs-button {{ filled($valores['obs_2'] ?? null) ? 'has-value' : '' }}" @disabled($candidato->apto) aria-label="Observación cap. 2" title="Observación cap. 2">
                                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                        </svg>
                                    </button>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" wire:model="capacitaciones.{{ $candidato->id }}.apto" wire:change="guardarCapacitacion({{ $candidato->id }})" @disabled(! $this->puedeMarcarApto($valores) && ! $candidato->apto)>
                                </td>
                            </tr>
                            @if ($expandido)
                                <tr wire:key="cap-panel-{{ $candidato->id }}">
                                    <td colspan="9" class="p-0">
                                        <div class="reclutamiento-capacitacion-panel">
                                            <dl class="col-span-full grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Mes</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->mes ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Fecha gestión</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->fecha_gestion?->format('d/m/Y') ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Agente reclutador</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->agente_reclutador ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Campaña</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->campana ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">DNI / C.E</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->dni_ce ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Edad</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->edad ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Número celular</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->numero_celular ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Distrito</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->distrito ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Observaciones</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->observaciones ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Tipificación</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->tipificacion ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Subtipificación rechazo</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->subtipificacion_rechazo ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Aceptación entrevista</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->aceptacion_entrevista ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Fecha entrevista</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->fecha_entrevista?->format('d/m/Y') ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Hora de entrevista</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->hora_entrevista ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Asistió entrevista</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->asistio_entrevista ?: '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Fecha reprogramada</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->fecha_reprogramada?->format('d/m/Y') ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Asistió ent. rep</dt>
                                                    <dd class="text-sm text-slate-700">{{ $candidato->asistio_entrevista_reprogramada ?: '-' }}</dd>
                                                </div>
                                                @if ($this->puedeGenerarInvitacion($valores))
                                                    <div>
                                                        @if ($invitacion)
                                                            <button type="button" data-invitacion-toggle data-invitacion-link="{{ route('onboarding.form', $invitacion->token) }}" data-invitacion-estado="{{ $invitacion->estadoLabel() }}" data-invitacion-qr="{{ QrCodeGenerator::dataUri(route('onboarding.form', $invitacion->token)) }}" class="btn-secondary w-auto">
                                                                Ver invitación
                                                            </button>
                                                        @else
                                                            <button type="button" wire:click="generarInvitacionCapacitacion({{ $candidato->id }})" class="btn-secondary w-auto">
                                                                Generar invitación
                                                            </button>
                                                        @endif
                                                    </div>
                                                @endif
                                            </dl>

                                            @if (($valores['asistio_cap_1'] ?? null) === 'REPROGRAMADO' || ($valores['asistio_cap_2'] ?? null) === 'REPROGRAMADO')
                                                <div class="col-span-full grid grid-cols-1 gap-6 border-t border-slate-200 pt-4 sm:grid-cols-2">
                                                    <div>
                                                        @if (($valores['asistio_cap_1'] ?? null) === 'REPROGRAMADO')
                                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Capacitación 1 reprogramada</p>
                                                            <div class="grid grid-cols-2 gap-4">
                                                                <div>
                                                                    <label class="form-label">Fecha</label>
                                                                    <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_1_reprogramada" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto)>
                                                                </div>
                                                                <div>
                                                                    <label class="form-label">Asistió</label>
                                                                    <div class="flex items-center gap-1.5">
                                                                        <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_1_reprogramada" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto || empty($valores['capacitacion_1_reprogramada']))>
                                                                            <option value="">Seleccionar</option>
                                                                            @foreach (self::CAPACITACION_ASISTIO_REPROGRAMADA_OPTIONS as $opcion)
                                                                                <option value="{{ $opcion }}">{{ $opcion }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                        <button type="button" data-obs-toggle data-obs-candidato="{{ $candidato->id }}" data-obs-field="obs_1_reprogramada" data-obs-value="{{ $valores['obs_1_reprogramada'] ?? '' }}" data-obs-label="Observación (reprogramada) cap. 1" class="reclutamiento-obs-button shrink-0 {{ filled($valores['obs_1_reprogramada'] ?? null) ? 'has-value' : '' }}" @disabled($candidato->apto) aria-label="Observación (reprogramada) cap. 1" title="Observación (reprogramada) cap. 1">
                                                                            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                                                            </svg>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>

                                                    <div>
                                                        @if (($valores['asistio_cap_2'] ?? null) === 'REPROGRAMADO')
                                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Capacitación 2 reprogramada</p>
                                                            <div class="grid grid-cols-2 gap-4">
                                                                <div>
                                                                    <label class="form-label">Fecha</label>
                                                                    <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_2_reprogramada" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto)>
                                                                </div>
                                                                <div>
                                                                    <label class="form-label">Asistió</label>
                                                                    <div class="flex items-center gap-1.5">
                                                                        <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_2_reprogramada" wire:change="guardarCapacitacion({{ $candidato->id }})" class="form-input" @disabled($candidato->apto || empty($valores['capacitacion_2_reprogramada']))>
                                                                            <option value="">Seleccionar</option>
                                                                            @foreach (self::CAPACITACION_ASISTIO_REPROGRAMADA_OPTIONS as $opcion)
                                                                                <option value="{{ $opcion }}">{{ $opcion }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                        <button type="button" data-obs-toggle data-obs-candidato="{{ $candidato->id }}" data-obs-field="obs_2_reprogramada" data-obs-value="{{ $valores['obs_2_reprogramada'] ?? '' }}" data-obs-label="Observación (reprogramada) cap. 2" class="reclutamiento-obs-button shrink-0 {{ filled($valores['obs_2_reprogramada'] ?? null) ? 'has-value' : '' }}" @disabled($candidato->apto) aria-label="Observación (reprogramada) cap. 2" title="Observación (reprogramada) cap. 2">
                                                                            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                                                            </svg>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="9" class="py-6 text-center text-slate-400">Aún no hay candidatos en proceso de capacitación.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    </div>

    <div class="reclutamiento-invitacion-popover" id="reclutamiento-invitacion-popover"></div>
    <div class="reclutamiento-obs-popover" id="capacitacion-obs-popover"></div>
</div>
