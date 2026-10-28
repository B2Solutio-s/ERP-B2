<div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
    <p class="mb-2 border-b border-slate-100 pb-1.5 text-[11px] font-bold uppercase tracking-wide text-sky-700">Información bancaria</p>
    <form wire:submit.prevent="guardarInformacionBancaria" class="space-y-2">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div>
                <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Cuenta sueldo</label>
                <input type="text" inputmode="numeric" wire:model="cuentaSueldoEdit" class="form-input w-full py-1 text-[11px]" placeholder="Opcional">
                @error('cuentaSueldoEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Cuenta free</label>
                <input type="text" inputmode="numeric" wire:model="cuentaFreeEdit" class="form-input w-full py-1 text-[11px]" placeholder="Opcional">
                @error('cuentaFreeEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Cuenta CTS</label>
                <input type="text" inputmode="numeric" wire:model="cuentaCtsEdit" class="form-input w-full py-1 text-[11px]" placeholder="Opcional">
                @error('cuentaCtsEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">CCI</label>
                <input type="text" inputmode="numeric" wire:model="cciEdit" class="form-input w-full py-1 text-[11px]" placeholder="Opcional (20 dígitos)">
                @error('cciEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="guardarInformacionBancaria" class="btn-secondary w-auto shrink-0 px-2 py-1 text-[10px]">
                <span wire:loading.remove wire:target="guardarInformacionBancaria">Guardar</span>
                <span wire:loading wire:target="guardarInformacionBancaria">...</span>
            </button>
            @if ($bancoGuardado)
                <p class="text-[10px] text-emerald-600">{{ $bancoGuardado }}</p>
            @endif
        </div>
    </form>
    <p class="mt-1 text-[10px] text-slate-400">Todos los datos son opcionales; se pueden completar por separado.</p>
</div>
