@php($vino = $fila['porcentaje'])
@php($etiqueta = ! str_contains($fila['clave'], '|') ? 'Total' : (explode('|', $fila['clave'])[1] === 'C' ? 'Campaña' : 'Puesto'))
@php($faltan = count($fila['faltaron']))
@php($color = $vino >= 80 ? '#10b981' : ($vino >= 50 ? '#f59e0b' : '#ef4444'))
<button type="button" wire:click="seleccionarCampana('{{ $fila['clave'] }}')" wire:key="resumen-{{ $fila['clave'] }}" aria-pressed="{{ $campanaDetalle === $fila['clave'] ? 'true' : 'false' }}"
    class="min-w-[7.5rem] rounded-xl border px-3 py-2 text-left transition hover:bg-slate-50 {{ $campanaDetalle === $fila['clave'] ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 bg-white' }}">
    <p class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">{{ $etiqueta }}</p>
    <p class="text-[11px] font-semibold uppercase leading-tight tracking-wide text-slate-600">{{ $fila['nombre'] }}</p>
    <p class="mt-0.5 flex items-baseline gap-1.5">
        <span class="text-xl font-semibold leading-none text-slate-600">{{ count($fila['vinieron']) }}/{{ $fila['total'] }}</span>
        <span class="text-[11px] font-medium text-slate-400">{{ $vino }}%</span>
    </p>
    <p class="mt-1 text-[11px] font-medium {{ $faltan > 0 ? 'text-red-600' : 'text-emerald-600' }}">
        {{ $faltan > 0 ? $faltan.' '.($faltan === 1 ? 'faltó' : 'faltaron') : 'Todos vinieron' }}
    </p>
    <span class="mt-1 block h-1.5 overflow-hidden rounded-full bg-slate-200" role="img" aria-label="{{ $fila['nombre'] }}: {{ $vino }}% vino">
        <span class="block h-full rounded-full" style="width: {{ $vino }}%; background-color: {{ $color }}"></span>
    </span>
</button>
