@props(['titulo', 'onRemove' => null, 'puedeQuitar' => true])

<div {{ $attributes->class(['rounded-xl border border-slate-200 p-5']) }}>
    <div class="mb-4 flex items-center justify-between">
        <h4 class="text-sm font-semibold text-slate-500">{{ $titulo }}</h4>

        @if ($puedeQuitar && $onRemove)
            <button type="button" wire:click="{{ $onRemove }}" class="text-sm font-medium text-rose-500 hover:text-rose-600">
                Quitar
            </button>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {{ $slot }}
    </div>
</div>
