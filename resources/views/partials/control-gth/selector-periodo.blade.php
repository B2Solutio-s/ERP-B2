{{-- Selector Diario / Mensual / Total con navegación de día o mes. Variables: $d (con 'vista' y 'periodo'). --}}
<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-3">
        <div class="gth-toggle" role="group" aria-label="Vista">
            @foreach (['diario' => 'Diario', 'mensual' => 'Mensual', 'total' => 'Total'] as $clave => $etiqueta)
                <button type="button" wire:key="vista-{{ $clave }}" wire:click="$set('vista', '{{ $clave }}')" class="gth-toggle-btn {{ $d['vista'] === $clave ? 'gth-toggle-btn-active' : '' }}" aria-pressed="{{ $d['vista'] === $clave ? 'true' : 'false' }}">{{ $etiqueta }}</button>
            @endforeach
        </div>

        @if ($d['vista'] === 'diario')
            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="moverDia(-1)" class="btn-secondary px-2.5" aria-label="Día anterior">‹</button>
                <input type="date" wire:model.live="fecha" class="form-input w-auto" aria-label="Día" />
                <button type="button" wire:click="moverDia(1)" class="btn-secondary px-2.5" aria-label="Día siguiente">›</button>
            </div>
        @elseif ($d['vista'] === 'mensual')
            <select wire:model.live="mes" class="form-input w-auto" aria-label="Mes">
                @foreach ($this->mesesDisponibles() as $valor => $etiqueta)
                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                @endforeach
            </select>
        @endif

        @if ($d['vista'] !== 'total')
            <button type="button" wire:click="irAHoy" class="btn-secondary">{{ $d['diario'] ? 'Hoy' : 'Mes actual' }}</button>
        @endif
    </div>

    <div class="flex items-center gap-3">
        <span class="text-sm font-medium text-slate-600">{{ $d['periodo'] }}</span>
        <span class="badge badge-green">● Actualización automática</span>
    </div>
</div>

