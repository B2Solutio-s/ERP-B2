@php($areaEdit = $this->puestoAreaModelo())
<div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
    <p class="mb-2 border-b border-slate-100 pb-1.5 text-[11px] font-bold uppercase tracking-wide text-sky-700">Área, puesto y campaña</p>
    <form wire:submit.prevent="guardarPuestoCampana" class="space-y-2">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div>
                <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Área</label>
                <select wire:model.live="puestoAreaEdit" class="form-input w-full py-1 text-[11px]">
                    <option value="">Selecciona un área</option>
                    @foreach (\App\Models\Area::orderBy('nombre')->pluck('nombre') as $nombreArea)
                        <option value="{{ mb_strtoupper($nombreArea) }}">{{ mb_strtoupper($nombreArea) }}</option>
                    @endforeach
                </select>
                @error('puestoAreaEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Puesto</label>
                <select wire:model.live="puestoEdit" class="form-input w-full py-1 text-[11px]" @disabled(! $areaEdit)>
                    <option value="">Selecciona un puesto</option>
                    @foreach ($this->puestosEditDisponibles() as $nombrePuesto)
                        <option value="{{ mb_strtoupper($nombrePuesto) }}">{{ mb_strtoupper($nombrePuesto) }}</option>
                    @endforeach
                </select>
                @error('puestoEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
            </div>
            @if ($this->campanaEditAplica())
                <div>
                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Campaña</label>
                    <select wire:model="campanaEdit" class="form-input w-full py-1 text-[11px]">
                        <option value="">Selecciona una campaña</option>
                        @foreach ($this->campanasEditDisponibles() as $nombreCampana)
                            <option value="{{ mb_strtoupper($nombreCampana) }}">{{ mb_strtoupper($nombreCampana) }}</option>
                        @endforeach
                    </select>
                    @error('campanaEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
                </div>
            @endif
            @if ($areaEdit?->usa_cargo)
                <div>
                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Cargo</label>
                    <select wire:model="cargoEdit" class="form-input w-full py-1 text-[11px]">
                        <option value="">Selecciona un cargo</option>
                        @foreach ($this->cargosEditDisponibles() as $nombreCargo)
                            <option value="{{ mb_strtoupper($nombreCargo) }}">{{ mb_strtoupper($nombreCargo) }}</option>
                        @endforeach
                    </select>
                    @error('cargoEdit') <p class="form-error mt-1 text-[10px]">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="guardarPuestoCampana" class="btn-secondary w-auto shrink-0 px-2 py-1 text-[10px]">
                <span wire:loading.remove wire:target="guardarPuestoCampana">Guardar</span>
                <span wire:loading wire:target="guardarPuestoCampana">...</span>
            </button>
            @if ($puestoGuardado)
                <p class="text-[10px] text-emerald-600">{{ $puestoGuardado }}</p>
            @endif
        </div>
    </form>
    <p class="mt-1 text-[10px] text-slate-400">Se guarda en Gestión de candidatos y se usa en el resumen de asistencia por campaña.</p>
</div>
