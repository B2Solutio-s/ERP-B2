<?php

use App\Models\CandidatoReclutamiento;
use App\Models\Campana;
use App\Models\OnboardingInvitation;
use App\Events\ReclutamientoActualizado;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public array $modulos = [
        'candidatos' => 'Gestión de candidatos',
        'capacitacion' => 'Proceso de capacitación',
        'entrevista' => 'Entrevista',
        'seguimiento' => 'Seguimiento',
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
            'nombres', 'numero_celular', 'distrito', 'observaciones',
            'tipificacion', 'subtipificacion_rechazo',
            'aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
            'fecha_reprogramada', 'asistio_entrevista_reprogramada',
        ];
    }

    protected function camposTexto(): array
    {
        return [
            'mes', 'agente_reclutador', 'campana', 'dni_ce', 'nombres', 'numero_celular',
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

        if (! $candidato && (! empty($payload['dni_ce']) || ! empty($payload['nombres']))) {
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
        $nombre = trim((string) ($payload['nombres'] ?? ''));
        $telefono = preg_replace('/\D+/', '', (string) ($payload['numero_celular'] ?? ''));

        if ($dni !== '') {
            $candidato = CandidatoReclutamiento::query()
                ->whereRaw('LOWER(TRIM(COALESCE(dni_ce, ""))) = ?', [mb_strtolower($dni)])
                ->first();

            if ($candidato) {
                return $candidato;
            }
        }

        if ($nombre !== '' && $telefono !== '') {
            $candidato = CandidatoReclutamiento::query()
                ->whereRaw('LOWER(TRIM(COALESCE(nombres, ""))) = ?', [mb_strtolower($nombre)])
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
            if (($datos['asistio_cap_1'] ?? null) !== 'REPROGRAMADO') {
                $datos['capacitacion_1_reprogramada'] = null;
                $datos['asistio_cap_1_reprogramada'] = null;
                $datos['obs_1_reprogramada'] = null;
            }

            if (! $this->puedeCapacitacion2($datos)) {
                $datos['capacitacion_2'] = null;
                $datos['asistio_cap_2'] = null;
                $datos['obs_2'] = null;
                $datos['capacitacion_2_reprogramada'] = null;
                $datos['asistio_cap_2_reprogramada'] = null;
                $datos['obs_2_reprogramada'] = null;
            } elseif (($datos['asistio_cap_2'] ?? null) !== 'REPROGRAMADO') {
                $datos['capacitacion_2_reprogramada'] = null;
                $datos['asistio_cap_2_reprogramada'] = null;
                $datos['obs_2_reprogramada'] = null;
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

<div class="space-y-6">
    <div class="card-panel">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-600">GTH</p>
                <h2 class="mt-2 text-2xl font-bold text-slate-900">Proceso de reclutamiento</h2>
            </div>
        </div>

        <div class="reclutamiento-tabs mt-5">
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
    </div>

    <div class="card-panel" @style(['display: none' => $moduloActivo !== 'candidatos'])>
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h3 class="text-xl font-semibold text-slate-900">Gestion de candidatos</h3>
                <p class="text-sm text-slate-500">Carga, edición y mantenimiento de la base inicial del proceso.</p>
            </div>
            <div class="flex gap-2">
                <button type="button" id="agregar-candidato" class="btn-primary inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Agregar fila" title="Agregar fila">
                    <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button type="button" id="ampliar-tabla" class="btn-secondary inline-flex w-auto items-center gap-2" title="Abrir vista ampliada">
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3" />
                        <path d="M16 3h3a2 2 0 0 1 2 2v3" />
                        <path d="M21 16v3a2 2 0 0 1-2 2h-3" />
                        <path d="M3 16v3a2 2 0 0 0 2 2h3" />
                    </svg>
                    <span>Ampliar tabla</span>
                </button>
                <label class="btn-secondary w-auto cursor-pointer">
                    Importar Excel
                    <input id="importar-candidatos" type="file" accept=".xlsx,.xls,.csv" class="hidden">
                </label>
                <button type="submit" form="reclutamiento-form" class="btn-primary w-auto">Guardar cambios</button>
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
                                <th colspan="6" class="border-b border-slate-200 bg-teal-800 px-3 py-3 text-center text-white">DATOS PRIMARIOS DE GESTIÓN</th>
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
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Nombres y apellidos</th>
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
        <div class="card-panel">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="text-xl font-semibold text-slate-900">Proceso de capacitación</h3>
                    <p class="text-sm text-slate-500">Candidatos que aprobaron la entrevista y están en proceso de capacitación.</p>
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
                            <th class="px-3 py-2">Fecha apto</th>
                            <th class="px-3 py-2">Apto</th>
                            <th class="px-3 py-2">Invitación</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->candidatosCapacitacion() as $candidato)
                            @php($valores = $capacitaciones[$candidato->id] ?? $this->valoresCapacitacion($candidato))
                            @php($expandido = $capacitacionExpandido[$candidato->id] ?? false)
                            @php($invitacion = $this->invitacionDe($candidato->id))
                            <tr class="reclutamiento-capacitacion-row" wire:key="cap-row-{{ $candidato->id }}">
                                <td class="px-3 py-2">
                                    <button type="button" wire:click="toggleCapacitacionExpandido({{ $candidato->id }})" class="text-slate-400 transition hover:text-slate-600" aria-label="Expandir">
                                        <svg class="h-4 w-4 {{ $expandido ? 'rotate-90' : '' }} transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </button>
                                </td>
                                <td class="px-3 py-2 font-medium text-slate-900">{{ $candidato->nombreCorto() }}</td>
                                <td class="px-3 py-2 text-slate-500">{{ $candidato->fecha_apto?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td class="px-3 py-2">
                                    <x-ui.badge :color="$candidato->apto ? 'green' : 'slate'">{{ $candidato->apto ? 'APTO' : 'NO' }}</x-ui.badge>
                                </td>
                                <td class="px-3 py-2 text-slate-500">
                                    {{ $invitacion?->estadoLabel() ?? 'sin generar' }}
                                </td>
                            </tr>
                            @if ($expandido)
                                <tr wire:key="cap-panel-{{ $candidato->id }}">
                                    <td colspan="5" class="p-0">
                                        <div class="reclutamiento-capacitacion-panel">
                                            <div>
                                                <label class="form-label">Capacitación 1</label>
                                                <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_1" class="form-input" @disabled($candidato->apto)>
                                            </div>
                                            <div>
                                                <label class="form-label">Asistió cap. 1</label>
                                                <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_1" class="form-input" @disabled($candidato->apto || empty($valores['capacitacion_1']))>
                                                    <option value="">Seleccionar</option>
                                                    @foreach (self::CAPACITACION_ASISTIO_OPTIONS as $opcion)
                                                        <option value="{{ $opcion }}">{{ $opcion }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="form-label">Observación cap. 1</label>
                                                <input type="text" wire:model="capacitaciones.{{ $candidato->id }}.obs_1" class="form-input uppercase" @disabled($candidato->apto)>
                                            </div>

                                            @if (($valores['asistio_cap_1'] ?? null) === 'REPROGRAMADO')
                                                <div>
                                                    <label class="form-label">Cap. 1 reprogramada</label>
                                                    <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_1_reprogramada" class="form-input" @disabled($candidato->apto)>
                                                </div>
                                                <div>
                                                    <label class="form-label">Asistió (reprogramada)</label>
                                                    <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_1_reprogramada" class="form-input" @disabled($candidato->apto)>
                                                        <option value="">Seleccionar</option>
                                                        @foreach (self::CAPACITACION_ASISTIO_OPTIONS as $opcion)
                                                            <option value="{{ $opcion }}">{{ $opcion }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endif

                                            @if ($this->puedeCapacitacion2($valores))
                                                <div>
                                                    <label class="form-label">Capacitación 2</label>
                                                    <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_2" class="form-input" @disabled($candidato->apto)>
                                                </div>
                                                <div>
                                                    <label class="form-label">Asistió cap. 2</label>
                                                    <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_2" class="form-input" @disabled($candidato->apto || empty($valores['capacitacion_2']))>
                                                        <option value="">Seleccionar</option>
                                                        @foreach (self::CAPACITACION_ASISTIO_OPTIONS as $opcion)
                                                            <option value="{{ $opcion }}">{{ $opcion }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="sm:col-span-2">
                                                    <label class="form-label">Observación cap. 2</label>
                                                    <input type="text" wire:model="capacitaciones.{{ $candidato->id }}.obs_2" class="form-input uppercase" @disabled($candidato->apto)>
                                                </div>

                                                @if (($valores['asistio_cap_2'] ?? null) === 'REPROGRAMADO')
                                                    <div>
                                                        <label class="form-label">Cap. 2 reprogramada</label>
                                                        <input type="date" wire:model="capacitaciones.{{ $candidato->id }}.capacitacion_2_reprogramada" class="form-input" @disabled($candidato->apto)>
                                                    </div>
                                                    <div>
                                                        <label class="form-label">Asistió (reprogramada)</label>
                                                        <select wire:model="capacitaciones.{{ $candidato->id }}.asistio_cap_2_reprogramada" class="form-input" @disabled($candidato->apto)>
                                                            <option value="">Seleccionar</option>
                                                            @foreach (self::CAPACITACION_ASISTIO_OPTIONS as $opcion)
                                                                <option value="{{ $opcion }}">{{ $opcion }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endif
                                            @endif

                                            <div class="flex items-center gap-2">
                                                <input type="checkbox" wire:model="capacitaciones.{{ $candidato->id }}.apto" id="apto-{{ $candidato->id }}" @disabled(! $this->puedeMarcarApto($valores) && ! $candidato->apto)>
                                                <label for="apto-{{ $candidato->id }}" class="text-sm font-medium text-slate-700">Marcar como APTO</label>
                                            </div>

                                            <div>
                                                <button type="button" wire:click="guardarCapacitacion({{ $candidato->id }})" class="btn-secondary w-auto">Guardar capacitación</button>
                                            </div>

                                            @if ($this->puedeGenerarInvitacion($valores))
                                                <div class="sm:col-span-2">
                                                    @if ($invitacion)
                                                        <button type="button" data-invitacion-toggle data-invitacion-link="{{ route('onboarding.form', $invitacion->token) }}" data-invitacion-estado="{{ $invitacion->estadoLabel() }}" class="btn-secondary w-auto">
                                                            Ver invitación
                                                        </button>
                                                    @else
                                                        <button type="button" wire:click="generarInvitacionCapacitacion({{ $candidato->id }})" class="btn-secondary w-auto">
                                                            Generar invitación
                                                        </button>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-slate-400">Aún no hay candidatos en proceso de capacitación.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($moduloActivo === 'entrevista')
        <div class="card-panel">
            <x-panel.reclutamiento.proximamente titulo="Entrevista" />
        </div>
    @endif

    @if ($moduloActivo === 'seguimiento')
        <div class="card-panel">
            <x-panel.reclutamiento.proximamente titulo="Seguimiento" />
        </div>
    @endif

    <div class="reclutamiento-invitacion-popover" id="reclutamiento-invitacion-popover"></div>
</div>
