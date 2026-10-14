<?php

use App\Models\AsistenciaMarcacion;
use App\Models\Empleado;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public string $fecha = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esGth() || Auth::user()->esAdmin(), 403);

        $this->fecha = now('America/Lima')->toDateString();
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
        </div>
    </div>

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
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-slate-400">Sin marcaciones en esta fecha.</td></tr>
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
