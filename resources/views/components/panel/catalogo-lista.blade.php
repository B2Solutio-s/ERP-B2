@props(['titulo', 'descripcion' => null, 'items', 'campo', 'metodoAgregar', 'metodoEliminar', 'placeholder' => ''])

<div class="card-panel-compact">
    <h2 class="mb-2 text-sm font-semibold text-slate-900">{{ $titulo }}</h2>
    @if ($descripcion)
        <p class="mb-3 text-xs text-slate-500">{{ $descripcion }}</p>
    @endif

    <form wire:submit="{{ $metodoAgregar }}" class="mb-3 flex flex-wrap items-end gap-2">
        <div class="flex-1">
            <label class="form-label text-xs">Nombre</label>
            <input type="text" wire:model="{{ $campo }}" class="form-input text-xs" placeholder="{{ $placeholder }}">
            @error($campo) <p class="form-error text-xs">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-secondary w-auto px-3 py-1.5 text-xs">Agregar</button>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table text-xs">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr wire:key="{{ Str::slug($titulo) }}-{{ $item->id }}">
                        <td>{{ $item->nombre }}</td>
                        <td>
                            <button type="button" wire:click="{{ $metodoEliminar }}({{ $item->id }})" wire:confirm="¿Eliminar este registro?" class="text-xs font-medium text-rose-600 hover:opacity-80">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-3 text-center text-slate-400">Aún no hay registros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
