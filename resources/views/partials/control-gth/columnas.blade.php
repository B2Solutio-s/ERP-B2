{{-- Gráfico de columnas agrupadas (2 series) por día o por mes. Variables: $grafico (columnas, maximo), $nombreA, $nombreB. --}}
<div class="mb-2 flex items-center gap-4 text-xs text-slate-500">
    <span class="flex items-center gap-1.5"><span class="gth-leyenda gth-serie-1"></span>{{ $nombreA }}</span>
    <span class="flex items-center gap-1.5"><span class="gth-leyenda gth-serie-2"></span>{{ $nombreB }}</span>
</div>

<div class="gth-columnas">
    @foreach ($grafico['columnas'] as $columna)
        <div wire:key="col-{{ $nombreA }}-{{ $loop->index }}" class="gth-columna-grupo" title="{{ $columna['titulo'] }} · {{ $nombreA }}: {{ $columna['a'] }} · {{ $nombreB }}: {{ $columna['b'] }}">
            <div class="gth-columna gth-serie-1" style="height: {{ round($columna['a'] / $grafico['maximo'] * 100) }}%"></div>
            <div class="gth-columna gth-serie-2" style="height: {{ round($columna['b'] / $grafico['maximo'] * 100) }}%"></div>
        </div>
    @endforeach
</div>
<div class="mt-1 flex gap-px">
    @foreach ($grafico['columnas'] as $columna)
        <span class="flex-1 text-center text-[10px] text-slate-500">{{ $columna['etiqueta'] }}</span>
    @endforeach
</div>
<p class="mt-1 text-right text-[11px] text-slate-400">Máximo por {{ $grafico['unidad'] }}: {{ $grafico['maximo'] }}</p>
