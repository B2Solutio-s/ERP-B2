<div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
    <p class="mb-2 border-b border-slate-100 pb-1.5 text-[11px] font-bold uppercase tracking-wide text-sky-700">Correo corporativo</p>
    <form wire:submit.prevent="guardarCorreoCorporativo" class="flex items-end gap-2">
        <input type="email" wire:model="correoCorpEdit" class="form-input flex-1 py-1 text-[11px]" placeholder="correo@empresa.com">
        <button type="submit" wire:loading.attr="disabled" wire:target="guardarCorreoCorporativo" class="btn-secondary w-auto shrink-0 px-2 py-1 text-[10px]">
            <span wire:loading.remove wire:target="guardarCorreoCorporativo">Guardar</span>
            <span wire:loading wire:target="guardarCorreoCorporativo">...</span>
        </button>
    </form>
    @error('correoCorpEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
    @if ($correoCorpGuardado)
        <p class="mt-1 text-[10px] text-emerald-600">{{ $correoCorpGuardado }}</p>
    @endif
    <p class="mt-1 text-[10px] text-slate-400">A este correo se envía el link para llenar la ficha de ingreso.</p>
</div>
