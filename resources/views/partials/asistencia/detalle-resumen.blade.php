<div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-2">
    <div>
        <h3 class="mb-2 text-sm font-semibold text-emerald-700">Vinieron — {{ $detalle['nombre'] }} ({{ count($detalle['vinieron']) }})</h3>
        <ul class="space-y-1 text-sm text-slate-800">
            @forelse ($detalle['vinieron'] as $persona)
                <li class="flex items-baseline justify-between gap-3">
                    <span>{{ $persona['nombre'] }} <span class="text-xs text-slate-400">· {{ $persona['puesto'] }}@if ($detalle['clave'] === $detalle['nombre'] && $persona['campana']) · {{ $persona['campana'] }}@endif</span></span>
                    @if ($persona['hora']) <span class="shrink-0 text-xs text-slate-500">{{ $persona['hora'] }}</span> @endif
                </li>
            @empty
                <li class="text-slate-400">Nadie marcó todavía.</li>
            @endforelse
        </ul>
    </div>
    <div>
        <h3 class="mb-2 text-sm font-semibold text-slate-600">No vinieron — {{ $detalle['nombre'] }} ({{ count($detalle['faltaron']) }})</h3>
        <ul class="space-y-1 text-sm text-slate-800">
            @forelse ($detalle['faltaron'] as $persona)
                <li>
                    {{ $persona['nombre'] }} <span class="text-xs text-slate-400">· {{ $persona['puesto'] }}@if ($detalle['clave'] === $detalle['nombre'] && $persona['campana']) · {{ $persona['campana'] }}@endif</span>
                    @if ($persona['sinId']) <span class="text-xs text-amber-600">· sin ID de huella</span> @endif
                </li>
            @empty
                <li class="text-slate-400">Todos vinieron.</li>
            @endforelse
        </ul>
    </div>
</div>
