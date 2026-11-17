{{-- Ventana con los registros de una cifra y su ficha completa. Variables: $det (ver ConDetalleModal), $d['periodo']. --}}
<div class="table-expand-modal" wire:key="gth-detalle" wire:click.self="cerrarDetalle" wire:keydown.escape.window="cerrarDetalle">
    <div class="gth-detalle-panel" role="dialog" aria-modal="true" aria-label="{{ $det['titulo'] }}">
        <div class="table-expand-modal-header">
            <div>
                <h3 class="text-base font-semibold text-slate-900">{{ $det['titulo'] }}</h3>
                <p class="text-xs text-slate-400">{{ $det['total'] }} {{ $det['total'] === 1 ? 'registro' : 'registros' }} · {{ $d['periodo'] }}</p>
            </div>
            <button type="button" wire:click="cerrarDetalle" class="btn-secondary" aria-label="Cerrar">Cerrar ✕</button>
        </div>

        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-slate-50 p-4">
            @forelse ($det['filas'] as $i => $fila)
                <div wire:key="det-{{ $i }}" class="gth-detalle-fila {{ isset($fila['ficha']) ? 'gth-detalle-fila-abierta' : '' }}">
                    <button type="button" wire:click="verFicha({{ $i }})" class="gth-detalle-resumen" aria-expanded="{{ isset($fila['ficha']) ? 'true' : 'false' }}">
                        <span class="min-w-[13rem] flex-1 font-semibold text-slate-800">{{ $fila['nombre'] }}</span>
                        @foreach ($fila['chips'] as $chip)
                            <span class="text-xs text-slate-500">{{ $chip }}</span>
                        @endforeach
                        @if ($fila['nota'])
                            <span class="text-xs font-medium text-slate-600">{{ $fila['nota'] }}</span>
                        @endif
                        @if ($fila['badge'])
                            <span class="badge badge-{{ $fila['badge'][1] }}">{{ $fila['badge'][0] }}</span>
                        @endif
                        <span class="text-slate-400" aria-hidden="true">{{ isset($fila['ficha']) ? '▲' : '▼' }}</span>
                    </button>

                    @if (isset($fila['ficha']))
                        <div class="gth-ficha">
                            @foreach ($fila['ficha'] as $grupo)
                                <div wire:key="ficha-{{ $i }}-{{ $loop->index }}">
                                    <h4 class="form-section-title mb-1.5">{{ $grupo['titulo'] }}</h4>
                                    <dl>
                                        @foreach ($grupo['campos'] as [$etiqueta, $valor])
                                            <div class="gth-ficha-fila">
                                                <dt>{{ $etiqueta }}</dt>
                                                <dd>{{ $valor }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="py-10 text-center text-sm text-slate-400">No hay registros para esta cifra.</p>
            @endforelse

            @if ($det['truncado'])
                <p class="pt-2 text-center text-xs text-slate-400">Se muestran los primeros {{ count($det['filas']) }} de {{ $det['total'] }} registros.</p>
            @endif
        </div>
    </div>
</div>
