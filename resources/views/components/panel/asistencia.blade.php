<?php

use App\Models\AsistenciaComentario;
use App\Models\AsistenciaMarcacion;
use App\Models\Empleado;
use App\Services\EscritorXlsx;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public string $fecha = '';

    /** Comentario del día por ID de biométrico (uno por persona y fecha). @var array<string, string> */
    public array $comentarios = [];

    public string $reporteDesde = '';

    public string $reporteHasta = '';

    public bool $reporteAbierto = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->fecha = now('America/Lima')->toDateString();
        $this->reporteDesde = $this->reporteHasta = $this->fecha;
        $this->cargarComentarios();
    }

    public function updatedFecha(): void
    {
        $this->cargarComentarios();
    }

    private function cargarComentarios(): void
    {
        $this->comentarios = AsistenciaComentario::whereDate('fecha', $this->fecha)->pluck('comentario', 'biometrico_id')->all();
    }

    public function updatedComentarios(mixed $valor = null, ?string $biometricoId = null): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        // Solo IDs que tienen marcaciones ese día (también sirve para huellas sin vincular).
        if ($biometricoId === null || ! AsistenciaMarcacion::where('biometrico_id', $biometricoId)->whereDate('marcado_at', $this->fecha)->exists()) {
            $this->cargarComentarios();

            return;
        }

        $texto = mb_substr(trim((string) $valor), 0, 500);
        $this->comentarios[$biometricoId] = $texto;

        if ($texto === '') {
            AsistenciaComentario::where('biometrico_id', $biometricoId)->whereDate('fecha', $this->fecha)->delete();

            return;
        }

        AsistenciaComentario::updateOrCreate(
            ['biometrico_id' => $biometricoId, 'fecha' => $this->fecha],
            ['comentario' => $texto, 'user_id' => Auth::id()],
        );
    }

    /** Excel con el resumen diario por persona y el detalle de cada marcación, entre las fechas elegidas. */
    public function descargarReporte()
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->validate([
            'reporteDesde' => ['required', 'date'],
            'reporteHasta' => ['required', 'date', 'after_or_equal:reporteDesde', 'before_or_equal:'.Carbon::parse($this->reporteDesde ?: 'today')->addDays(365)->toDateString()],
        ], [
            'reporteHasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'reporteHasta.before_or_equal' => 'El reporte admite como máximo 1 año.',
        ], ['reporteDesde' => 'fecha inicial', 'reporteHasta' => 'fecha final']);

        $marcaciones = AsistenciaMarcacion::with('empleado:id,biometrico_id,apellido_paterno,apellido_materno,nombres,documento_identidad')
            ->whereDate('marcado_at', '>=', $this->reporteDesde)
            ->whereDate('marcado_at', '<=', $this->reporteHasta)
            ->orderBy('marcado_at')
            ->get();

        $comentarios = AsistenciaComentario::whereDate('fecha', '>=', $this->reporteDesde)
            ->whereDate('fecha', '<=', $this->reporteHasta)
            ->get()
            ->mapWithKeys(fn ($c) => [$c->fecha->toDateString().'|'.$c->biometrico_id => $c->comentario]);

        $nombre = fn ($m) => $m->empleado
            ? trim("{$m->empleado->apellido_paterno} {$m->empleado->apellido_materno}, {$m->empleado->nombres}")
            : 'SIN VINCULAR';

        $resumen = [['Fecha', 'ID biométrico', 'Empleado', 'DNI', 'Entrada', 'Salida', 'N.° marcaciones', 'Todas las marcaciones', 'Comentario']];
        $porDia = $marcaciones->groupBy(fn ($m) => $m->marcado_at->toDateString().'|'.$m->biometrico_id)
            ->sortBy(fn ($grupo, $clave) => $grupo->first()->marcado_at->toDateString().'|'.mb_strtoupper($nombre($grupo->first())).'|'.$clave);

        foreach ($porDia as $clave => $grupo) {
            $primera = $grupo->first();
            $resumen[] = [
                $primera->marcado_at->format('d/m/Y'),
                $primera->biometrico_id,
                $nombre($primera),
                $primera->empleado?->documento_identidad,
                $primera->marcado_at->format('H:i:s'),
                $grupo->count() > 1 ? $grupo->last()->marcado_at->format('H:i:s') : null,
                $grupo->count(),
                $grupo->map(fn ($m) => $m->marcado_at->format('H:i:s'))->implode(' · '),
                $comentarios[$clave] ?? null,
            ];
        }

        $detalle = [['Fecha', 'Hora', 'ID biométrico', 'Empleado', 'DNI', 'Comentario del día']];
        foreach ($marcaciones->sortBy(fn ($m) => $m->marcado_at->format('Y-m-d H:i:s')) as $m) {
            $detalle[] = [
                $m->marcado_at->format('d/m/Y'),
                $m->marcado_at->format('H:i:s'),
                $m->biometrico_id,
                $nombre($m),
                $m->empleado?->documento_identidad,
                $comentarios[$m->marcado_at->toDateString().'|'.$m->biometrico_id] ?? null,
            ];
        }

        $ruta = tempnam(sys_get_temp_dir(), 'asistencia');
        (new EscritorXlsx)
            ->hoja('Resumen diario', $resumen, [12, 14, 38, 12, 11, 11, 15, 60, 50])
            ->hoja('Marcaciones', $detalle, [12, 11, 14, 38, 12, 50])
            ->guardar($ruta);

        $archivo = "asistencia_{$this->reporteDesde}_a_{$this->reporteHasta}.xlsx";

        return response()->streamDownload(function () use ($ruta) {
            readfile($ruta);
            @unlink($ruta);
        }, $archivo, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    #[On('asistencia-registrada')]
    public function refrescar(): void
    {
        // El re-render de Livewire actualiza la tabla.
    }

    public function marcaciones()
    {
        return AsistenciaMarcacion::with('empleado:id,biometrico_id,apellido_paterno,apellido_materno,nombres,documento_identidad')
            ->whereDate('marcado_at', $this->fecha)
            ->orderByDesc('marcado_at')
            ->get();
    }

    public function sinVincular()
    {
        return AsistenciaMarcacion::query()
            ->whereNotIn('biometrico_id', Empleado::whereNotNull('biometrico_id')->pluck('biometrico_id'))
            ->select('biometrico_id', DB::raw('COUNT(*) as total'), DB::raw('MAX(marcado_at) as ultima'))
            ->groupBy('biometrico_id')
            ->orderByRaw('CAST(biometrico_id AS UNSIGNED)')
            ->get();
    }

    /** Empleados (ficha completada) que todavía no tienen ID de huella. */
    public function personas()
    {
        return Empleado::whereNull('biometrico_id')
            ->orderBy('apellido_paterno')->orderBy('nombres')
            ->get(['id', 'apellido_paterno', 'apellido_materno', 'nombres', 'documento_identidad']);
    }

    public function vincular(string $biometricoId, string $empleadoId): void
    {
        if ($empleadoId === '' || ! Empleado::whereKey($empleadoId)->exists()) {
            return;
        }

        Empleado::where('biometrico_id', $biometricoId)->update(['biometrico_id' => null]);
        Empleado::whereKey($empleadoId)->update(['biometrico_id' => $biometricoId]);
    }
};
?>

<div class="space-y-5">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-lg font-semibold text-slate-900">Asistencia</h1>
        <div class="flex items-center gap-3">
            <span class="badge badge-green">● En vivo</span>
            <input type="date" wire:model.live="fecha" class="form-input w-auto" />
            <button type="button" wire:click="$toggle('reporteAbierto')" class="btn-secondary w-auto" aria-expanded="{{ $reporteAbierto ? 'true' : 'false' }}">Descargar reporte</button>
        </div>
    </div>

    @if ($reporteAbierto)
        <div class="card-panel-compact">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Desde</label>
                    <input type="date" wire:model="reporteDesde" class="form-input py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Hasta</label>
                    <input type="date" wire:model="reporteHasta" class="form-input py-1.5 text-sm" />
                </div>
                <button type="button" wire:click="descargarReporte" wire:loading.attr="disabled" wire:target="descargarReporte" class="btn-primary w-auto" title="Descargar Excel" aria-label="Descargar Excel">
                    <span wire:loading.remove wire:target="descargarReporte" class="inline-flex items-center gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M9.293 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.707A1 1 0 0 0 13.707 4L10 .293A1 1 0 0 0 9.293 0M9.5 3.5v-2l3 3h-2a1 1 0 0 1-1-1m-1 4v3.793l1.146-1.147a.5.5 0 0 1 .708.708l-2 2a.5.5 0 0 1-.708 0l-2-2a.5.5 0 0 1 .708-.708L7.5 11.293V7.5a.5.5 0 0 1 1 0"/>
                        </svg>
                    </span>
                    <span wire:loading wire:target="descargarReporte" class="text-sm font-bold leading-4">...</span>
                </button>
            </div>
            @error('reporteDesde') <p class="form-error mt-1 text-xs">{{ $message }}</p> @enderror
            @error('reporteHasta') <p class="form-error mt-1 text-xs">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-slate-500">Incluye una hoja «Resumen diario» (entrada, salida, todas las marcaciones y el comentario de cada persona por día) y una hoja «Marcaciones» con el detalle. La hora es la que registra el biométrico.</p>
        </div>
    @endif

    @php($marcaciones = $this->marcaciones())

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card-panel-compact">
            <p class="text-sm text-slate-500">Marcaciones del día</p>
            <p class="text-2xl font-semibold text-slate-900">{{ $marcaciones->count() }}</p>
        </div>
        <div class="card-panel-compact">
            <p class="text-sm text-slate-500">Personas que marcaron</p>
            <p class="text-2xl font-semibold text-slate-900">{{ $marcaciones->pluck('biometrico_id')->unique()->count() }}</p>
        </div>
    </div>

    <div class="card-panel-compact">
        <h2 class="mb-4 text-base font-semibold text-slate-900">Marcaciones</h2>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>ID biométrico</th>
                        <th>Empleado</th>
                        <th>DNI</th>
                        <th>Comentario</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($marcaciones as $marcacion)
                        <tr wire:key="marcacion-{{ $marcacion->id }}">
                            <td>{{ $marcacion->marcado_at->format('H:i:s') }}</td>
                            <td>{{ $marcacion->biometrico_id }}</td>
                            <td>
                                @if ($marcacion->empleado)
                                    {{ $marcacion->empleado->apellido_paterno }} {{ $marcacion->empleado->apellido_materno }}, {{ $marcacion->empleado->nombres }}
                                @else
                                    <x-ui.badge color="amber">Sin vincular</x-ui.badge>
                                @endif
                            </td>
                            <td>{{ $marcacion->empleado?->documento_identidad }}</td>
                            <td>
                                <input
                                    type="text"
                                    wire:model.blur="comentarios.{{ $marcacion->biometrico_id }}"
                                    maxlength="500"
                                    placeholder="Opcional"
                                    class="form-input w-64 py-1 text-xs"
                                    aria-label="Comentario del día"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-slate-400">Sin marcaciones en esta fecha.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @php($sinVincular = $this->sinVincular())

    @if ($sinVincular->isNotEmpty())
        <details class="card-panel-compact">
            <summary class="cursor-pointer text-base font-semibold text-slate-900">
                IDs del biométrico sin vincular a una persona ({{ $sinVincular->count() }})
            </summary>
            <p class="mt-2 text-xs text-slate-500">Solo aparecen en la lista las personas que ya completaron su ficha de datos.</p>

            <div class="mt-4 overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID biométrico</th>
                            <th>Marcaciones</th>
                            <th>Última</th>
                            <th>Vincular con</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sinVincular as $fila)
                            <tr wire:key="sin-vincular-{{ $fila->biometrico_id }}">
                                <td>{{ $fila->biometrico_id }}</td>
                                <td>{{ $fila->total }}</td>
                                <td>{{ $fila->ultima }}</td>
                                <td>
                                    <select class="form-input py-1.5 text-xs" wire:change="vincular('{{ $fila->biometrico_id }}', $event.target.value)">
                                        <option value="">Seleccionar persona…</option>
                                        @foreach ($this->personas() as $persona)
                                            <option value="{{ $persona->id }}">{{ $persona->apellido_paterno }} {{ $persona->apellido_materno }}, {{ $persona->nombres }} — {{ $persona->documento_identidad }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
