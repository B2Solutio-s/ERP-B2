<?php

use App\Models\CandidatoReclutamiento;
use App\Models\Campana;
use App\Events\ReclutamientoActualizado;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public array $pasos = [
        'Gestion de candidatos',
        'Gestion de llamadas',
        'Entrevista',
        'Seguimiento',
    ];

    public string $rowsJson = '[]';
    public array $campanas = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->esGth(), 403);
        $this->rowsJson = $this->encodeRows();
        $this->campanas = Campana::query()
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter()
            ->values()
            ->all();
    }

    public function candidatos()
    {
        return CandidatoReclutamiento::query()
            ->orderBy('id')
            ->limit(25)
            ->get();
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

    public function guardarFila(array $fila): void
    {
        if ($this->filaVacia($fila)) {
            return;
        }

        $payload = [];
        foreach ([
            'mes', 'fecha_gestion', 'agente_reclutador', 'campana', 'dni_ce', 'edad',
            'nombres', 'numero_celular', 'distrito', 'observaciones',
            'tipificacion', 'subtipificacion_rechazo', 'aceptacion_entrevista',
            'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
            'fecha_capacitacion', 'entrego_documentos',
        ] as $campo) {
            $valor = $fila[$campo] ?? null;
            $payload[$campo] = $valor === '' ? null : $valor;
        }

        foreach ([
            'mes', 'agente_reclutador', 'campana', 'dni_ce', 'nombres', 'numero_celular',
            'distrito', 'observaciones', 'tipificacion', 'subtipificacion_rechazo',
            'aceptacion_entrevista', 'asistio_entrevista', 'entrego_documentos',
        ] as $campoTexto) {
            $payload[$campoTexto] = $payload[$campoTexto] === null
                ? null
                : mb_strtoupper(trim((string) $payload[$campoTexto]));
        }

        $payload['edad'] = $payload['edad'] === null || $payload['edad'] === ''
            ? null
            : min(99, max(0, (int) $payload['edad']));

        $candidato = ! empty($fila['id'])
            ? CandidatoReclutamiento::with('bloqueadoPor')->find($fila['id'])
            : null;

        if (! $candidato && (! empty($payload['dni_ce']) || ! empty($payload['nombres']))) {
            $candidato = $this->filaDuplicada($payload);
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

    public function eliminarFila(int $id): void
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

        $candidato->delete();
        $this->rowsJson = $this->encodeRows();

        broadcast(new ReclutamientoActualizado(
            tipo: 'tabla-actualizada',
            filaId: $id,
            usuarioId: Auth::id(),
            usuarioNombre: Auth::user()->name,
            filas: $this->filasActuales(),
        ));
        $this->dispatch('fila-eliminada', filaId: $id);
    }

    public function guardarBase(): void
    {
        $data = json_decode($this->rowsJson, true) ?: [];

        foreach ($data as $fila) {
            if ($this->filaVacia($fila)) {
                continue;
            }

            $campos = [
                'mes', 'fecha_gestion', 'agente_reclutador', 'campana', 'dni_ce', 'edad',
                'nombres', 'numero_celular', 'distrito', 'observaciones',
                'tipificacion', 'subtipificacion_rechazo', 'aceptacion_entrevista',
                'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
                'fecha_capacitacion', 'entrego_documentos',
            ];

            $payload = [];
            foreach ($campos as $campo) {
                $valor = $fila[$campo] ?? null;
                $payload[$campo] = $valor === '' ? null : $valor;
            }

            foreach ([
                'mes', 'agente_reclutador', 'campana', 'dni_ce', 'nombres', 'numero_celular',
                'distrito', 'observaciones', 'tipificacion', 'subtipificacion_rechazo',
                'aceptacion_entrevista', 'asistio_entrevista', 'entrego_documentos',
            ] as $campoTexto) {
                $payload[$campoTexto] = $payload[$campoTexto] === null
                    ? null
                    : mb_strtoupper(trim((string) $payload[$campoTexto]));
            }

            $payload['edad'] = $payload['edad'] === null || $payload['edad'] === ''
                ? null
                : min(99, max(0, (int) $payload['edad']));

            if (! empty($fila['id'])) {
                $candidato = CandidatoReclutamiento::with('bloqueadoPor')->find($fila['id']);
                if (! $candidato) {
                    $candidato = $this->filaDuplicada($payload);
                }

                if (! $candidato) {
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
            'fecha_capacitacion' => $candidato->fecha_capacitacion?->format('Y-m-d'),
            'entrego_documentos' => $candidato->entrego_documentos,
            'bloqueado_por_id' => $candidato->bloqueado_por_id,
            'bloqueado_por_nombre' => $candidato->bloqueadoPor?->name,
            'bloqueado_hasta' => $candidato->bloqueado_hasta?->toIso8601String(),
        ])->all();
    }

    protected function filaVacia(array $fila): bool
    {
        foreach ([
            'mes', 'fecha_gestion', 'agente_reclutador', 'campana', 'dni_ce', 'edad',
            'nombres', 'numero_celular', 'distrito', 'observaciones',
            'tipificacion', 'subtipificacion_rechazo', 'aceptacion_entrevista',
            'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
            'fecha_capacitacion', 'entrego_documentos',
        ] as $campo) {
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

        <div class="mt-5 flex flex-wrap gap-2">
            @foreach ($pasos as $paso)
                <button
                    type="button"
                    class="rounded-full border px-4 py-2 text-sm font-medium {{ $loop->first ? 'border-sky-600 bg-sky-50 text-sky-700' : 'border-slate-200 bg-slate-50 text-slate-500' }}"
                >
                    {{ $paso }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="card-panel">
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

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <label for="reclutamiento-filtro-columna" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Buscar en</label>
            <select id="reclutamiento-filtro-columna" class="form-input w-auto">
                <option value="">Selecciona columna</option>
                <option value="mes">Mes</option>
                <option value="fecha_gestion">Fecha gestión</option>
                <option value="agente_reclutador">Agente reclutador</option>
                <option value="campana">Campaña</option>
                <option value="dni_ce">DNI / C.E</option>
                <option value="edad">Edad</option>
                <option value="nombres">Nombres y apellidos</option>
                <option value="numero_celular">Número celular</option>
                <option value="distrito">Distrito</option>
                <option value="observaciones">Observaciones</option>
                <option value="tipificacion">Tipificación</option>
                <option value="subtipificacion_rechazo">Subtipificación rechazo</option>
                <option value="aceptacion_entrevista">Aceptación entrevista</option>
                <option value="fecha_entrevista">Fecha entrevista</option>
                <option value="hora_entrevista">Hora de entrevista</option>
                <option value="asistio_entrevista">Asistió entrevista</option>
                <option value="fecha_capacitacion">Fecha capacitación</option>
                <option value="entrego_documentos">Entregó documentos</option>
            </select>
            <input type="search" id="reclutamiento-filtro-texto" class="form-input w-56" placeholder="Buscar..." autocomplete="off">
        </div>

        <form id="reclutamiento-form" wire:submit.prevent="guardarBase" class="space-y-4">
            <input type="hidden" id="rowsJson" name="rowsJson" value="{{ $rowsJson }}">
            <script type="application/json" id="reclutamiento-grid-data">{!! $rowsJson !!}</script>
            <script type="application/json" id="reclutamiento-campanas">@json($campanas)</script>
            <script type="application/json" id="reclutamiento-usuario">@json(['id' => Auth::id(), 'nombre' => Auth::user()->name])</script>

            <div id="reclutamiento-grid-host" wire:ignore class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="reclutamiento-grid-scroll">
                    <table id="reclutamiento-grid" class="min-w-[2200px] table-auto border-separate border-spacing-0 text-left text-sm">
                        <thead class="bg-slate-100 text-slate-700">
                            <tr>
                                <th colspan="2" class="border-b border-slate-200 bg-slate-500 px-3 py-3 text-center text-white">GESTIÓN</th>
                                <th colspan="2" class="border-b border-slate-200 bg-cyan-200 px-3 py-3 text-center text-slate-900">BASE</th>
                                <th colspan="6" class="border-b border-slate-200 bg-teal-800 px-3 py-3 text-center text-white">DATOS PRIMARIOS DE GESTIÓN</th>
                                <th colspan="2" class="border-b border-slate-200 bg-slate-300 px-3 py-3 text-center text-slate-900">GESTIÓN DE LLAMADAS</th>
                                <th colspan="4" class="border-b border-slate-200 bg-cyan-200 px-3 py-3 text-center text-slate-900">ENTREVISTA</th>
                                <th colspan="2" class="border-b border-slate-200 bg-slate-300 px-3 py-3 text-center text-slate-900">SEGUIMIENTO DE CANDIDATO</th>
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
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Fecha capacitación</th>
                                <th class="whitespace-nowrap border-b border-slate-200 px-3 py-2 text-center">Entregó documentos</th>
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
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-5 py-3">
                <label for="reclutamiento-filtro-columna-ampliada" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Buscar en</label>
                <select id="reclutamiento-filtro-columna-ampliada" class="form-input w-auto">
                    <option value="">Selecciona columna</option>
                    <option value="mes">Mes</option>
                    <option value="fecha_gestion">Fecha gestión</option>
                    <option value="agente_reclutador">Agente reclutador</option>
                    <option value="campana">Campaña</option>
                    <option value="dni_ce">DNI / C.E</option>
                    <option value="edad">Edad</option>
                    <option value="nombres">Nombres y apellidos</option>
                    <option value="numero_celular">Número celular</option>
                    <option value="distrito">Distrito</option>
                    <option value="observaciones">Observaciones</option>
                    <option value="tipificacion">Tipificación</option>
                    <option value="subtipificacion_rechazo">Subtipificación rechazo</option>
                    <option value="aceptacion_entrevista">Aceptación entrevista</option>
                    <option value="fecha_entrevista">Fecha entrevista</option>
                    <option value="hora_entrevista">Hora de entrevista</option>
                    <option value="asistio_entrevista">Asistió entrevista</option>
                    <option value="fecha_capacitacion">Fecha capacitación</option>
                    <option value="entrego_documentos">Entregó documentos</option>
                </select>
                <input type="search" id="reclutamiento-filtro-texto-ampliada" class="form-input w-56" placeholder="Buscar..." autocomplete="off">
            </div>
            <div id="reclutamiento-grid-modal-body" class="table-expand-modal-body"></div>
        </div>
    </div>
</div>
