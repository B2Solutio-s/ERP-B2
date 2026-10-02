<?php

use App\Models\Area;
use App\Models\Campana;
use App\Models\Cargo;
use App\Models\Puesto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public ?int $areaSeleccionadaId = null;

    public string $nuevoPuestoNombre = '';
    public string $nuevaCampanaNombre = '';
    public string $nuevoCargoNombre = '';

    public ?int $editandoPuestoId = null;
    public string $editandoPuestoNombre = '';

    public ?int $editandoCampanaId = null;
    public string $editandoCampanaNombre = '';

    public ?int $editandoCargoId = null;
    public string $editandoCargoNombre = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->areaSeleccionadaId = Area::orderBy('nombre')->value('id');
    }

    public function areas()
    {
        return Area::orderBy('nombre')->get();
    }

    public function areaSeleccionada(): ?Area
    {
        return $this->areaSeleccionadaId
            ? $this->areas()->firstWhere('id', $this->areaSeleccionadaId)
            : null;
    }

    public function puestosDelArea()
    {
        if (! $this->areaSeleccionadaId) {
            return collect();
        }

        return Puesto::where('area_id', $this->areaSeleccionadaId)->orderBy('nombre')->get();
    }

    public function campanasDelArea()
    {
        if (! $this->areaSeleccionadaId) {
            return collect();
        }

        return Campana::where('area_id', $this->areaSeleccionadaId)->orderBy('nombre')->get();
    }

    public function cargosDelArea()
    {
        if (! $this->areaSeleccionadaId) {
            return collect();
        }

        return Cargo::where('area_id', $this->areaSeleccionadaId)->orderBy('nombre')->get();
    }

    public function seleccionarArea(int $id): void
    {
        $this->areaSeleccionadaId = $id;
    }

    public function agregarPuesto(): void
    {
        if (! $this->areaSeleccionadaId) {
            return;
        }

        $this->validate([
            'nuevoPuestoNombre' => ['required', 'string', 'max:100', Rule::unique('puestos', 'nombre')->where(fn ($query) => $query->where('area_id', $this->areaSeleccionadaId))],
        ]);

        Puesto::create([
            'nombre' => $this->nuevoPuestoNombre,
            'area_id' => $this->areaSeleccionadaId,
        ]);

        $this->reset(['nuevoPuestoNombre']);
    }

    public function editarPuesto(int $id): void
    {
        $puesto = Puesto::findOrFail($id);
        $this->editandoPuestoId = $puesto->id;
        $this->editandoPuestoNombre = $puesto->nombre;
    }

    public function guardarEdicionPuesto(): void
    {
        if (! $this->editandoPuestoId) {
            return;
        }

        $puesto = Puesto::findOrFail($this->editandoPuestoId);

        $this->validate([
            'editandoPuestoNombre' => [
                'required', 'string', 'max:100',
                Rule::unique('puestos', 'nombre')->where(fn ($query) => $query->where('area_id', $puesto->area_id))->ignore($this->editandoPuestoId),
            ],
        ]);

        $puesto->update(['nombre' => $this->editandoPuestoNombre]);

        $this->cancelarEdicionPuesto();
    }

    public function cancelarEdicionPuesto(): void
    {
        $this->editandoPuestoId = null;
        $this->editandoPuestoNombre = '';
    }

    public function agregarCampana(): void
    {
        if (! $this->areaSeleccionadaId) {
            return;
        }

        $this->validate([
            'nuevaCampanaNombre' => ['required', 'string', 'max:100', Rule::unique('campanas', 'nombre')->where(fn ($query) => $query->where('area_id', $this->areaSeleccionadaId))],
        ]);

        Campana::create([
            'nombre' => $this->nuevaCampanaNombre,
            'area_id' => $this->areaSeleccionadaId,
        ]);

        $this->reset(['nuevaCampanaNombre']);
    }

    public function editarCampana(int $id): void
    {
        $campana = Campana::findOrFail($id);
        $this->editandoCampanaId = $campana->id;
        $this->editandoCampanaNombre = $campana->nombre;
    }

    public function guardarEdicionCampana(): void
    {
        if (! $this->editandoCampanaId) {
            return;
        }

        $campana = Campana::findOrFail($this->editandoCampanaId);

        $this->validate([
            'editandoCampanaNombre' => [
                'required', 'string', 'max:100',
                Rule::unique('campanas', 'nombre')->where(fn ($query) => $query->where('area_id', $campana->area_id))->ignore($this->editandoCampanaId),
            ],
        ]);

        $campana->update(['nombre' => $this->editandoCampanaNombre]);

        $this->cancelarEdicionCampana();
    }

    public function cancelarEdicionCampana(): void
    {
        $this->editandoCampanaId = null;
        $this->editandoCampanaNombre = '';
    }

    public function agregarCargo(): void
    {
        if (! $this->areaSeleccionadaId) {
            return;
        }

        $this->validate([
            'nuevoCargoNombre' => ['required', 'string', 'max:100', Rule::unique('cargos', 'nombre')->where(fn ($query) => $query->where('area_id', $this->areaSeleccionadaId))],
        ]);

        Cargo::create([
            'nombre' => $this->nuevoCargoNombre,
            'area_id' => $this->areaSeleccionadaId,
        ]);

        $this->reset(['nuevoCargoNombre']);
    }

    public function editarCargo(int $id): void
    {
        $cargo = Cargo::findOrFail($id);
        $this->editandoCargoId = $cargo->id;
        $this->editandoCargoNombre = $cargo->nombre;
    }

    public function guardarEdicionCargo(): void
    {
        if (! $this->editandoCargoId) {
            return;
        }

        $cargo = Cargo::findOrFail($this->editandoCargoId);

        $this->validate([
            'editandoCargoNombre' => [
                'required', 'string', 'max:100',
                Rule::unique('cargos', 'nombre')->where(fn ($query) => $query->where('area_id', $cargo->area_id))->ignore($this->editandoCargoId),
            ],
        ]);

        $cargo->update(['nombre' => $this->editandoCargoNombre]);

        $this->cancelarEdicionCargo();
    }

    public function cancelarEdicionCargo(): void
    {
        $this->editandoCargoId = null;
        $this->editandoCargoNombre = '';
    }
};
?>

<div class="space-y-5">
    <div class="card-panel-compact">
        <h2 class="text-sm font-semibold text-slate-900">Áreas, puestos, campañas y cargos</h2>
        <p class="mt-1 text-xs text-slate-500">
            Selecciona un área para ver y administrar sus puestos, campañas y cargos. Campaña y Cargo son independientes entre sí — cada área solo usa la(s) que le corresponde(n) (ej. Comercial usa Campaña pero no Cargo; Administrativo usa Cargo pero no Campaña).
            Los nombres se pueden editar, pero no eliminar, para no arrastrar en cascada lo que dependa de ellos.
        </p>
    </div>

    <div class="card-panel-compact">
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Áreas</h3>
        <div class="flex flex-wrap gap-2">
            @foreach ($this->areas() as $area)
                <button
                    type="button"
                    wire:click="seleccionarArea({{ $area->id }})"
                    class="rounded-full border px-3 py-1.5 text-xs font-medium {{ $areaSeleccionadaId === $area->id ? 'border-primary bg-primary/10 text-primary' : 'border-slate-200 text-slate-600 hover:bg-slate-50' }}"
                >
                    {{ $area->nombre }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Puestos del área --}}
        <div class="card-panel-compact">
            <h3 class="mb-2 text-sm font-semibold text-slate-900">
                Puestos
                @if ($this->areaSeleccionada())
                    <span class="font-normal text-slate-400">— {{ $this->areaSeleccionada()->nombre }}</span>
                @endif
            </h3>

            <form wire:submit="agregarPuesto" class="mb-3 flex flex-wrap items-end gap-2">
                <div class="flex-1">
                    <label class="form-label text-xs">Nombre</label>
                    <input type="text" wire:model="nuevoPuestoNombre" class="form-input text-xs" placeholder="Ej. Ejecutivo">
                    @error('nuevoPuestoNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-secondary w-auto px-3 py-1.5 text-xs">Agregar</button>
            </form>

            <div class="space-y-1">
                @forelse ($this->puestosDelArea() as $puesto)
                    @if ($editandoPuestoId === $puesto->id)
                        <form wire:key="puesto-editar-{{ $puesto->id }}" wire:submit="guardarEdicionPuesto" class="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5">
                            <input type="text" wire:model="editandoPuestoNombre" class="form-input flex-1 text-xs">
                            <button type="submit" class="text-xs font-medium text-primary hover:opacity-80">Guardar</button>
                            <button type="button" wire:click="cancelarEdicionPuesto" class="text-xs font-medium text-slate-500 hover:opacity-80">Cancelar</button>
                        </form>
                        @error('editandoPuestoNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                    @else
                        <div wire:key="puesto-{{ $puesto->id }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-xs hover:bg-slate-50">
                            <span class="flex-1 font-medium">{{ $puesto->nombre }}</span>
                            <button type="button" wire:click="editarPuesto({{ $puesto->id }})" class="ml-2 font-medium text-slate-500 hover:opacity-80">
                                Editar
                            </button>
                        </div>
                    @endif
                @empty
                    <p class="py-3 text-center text-xs text-slate-400">Aún no hay puestos para esta área.</p>
                @endforelse
            </div>
        </div>

        {{-- Campañas del área --}}
        <div class="card-panel-compact">
            <h3 class="mb-2 text-sm font-semibold text-slate-900">
                Campañas
                @if ($this->areaSeleccionada())
                    <span class="font-normal text-slate-400">— {{ $this->areaSeleccionada()->nombre }}</span>
                @endif
            </h3>

            @if ($this->areaSeleccionada() && ! $this->areaSeleccionada()->usa_campana)
                <p class="py-3 text-center text-xs text-slate-400">Campaña no aplica para esta área.</p>
            @else
                <form wire:submit="agregarCampana" class="mb-3 flex flex-wrap items-end gap-2">
                    <div class="flex-1">
                        <label class="form-label text-xs">Nombre</label>
                        <input type="text" wire:model="nuevaCampanaNombre" class="form-input text-xs" placeholder="Ej. Móvil">
                        @error('nuevaCampanaNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn-secondary w-auto px-3 py-1.5 text-xs">Agregar</button>
                </form>

                <div class="space-y-1">
                    @forelse ($this->campanasDelArea() as $campana)
                        @if ($editandoCampanaId === $campana->id)
                            <form wire:key="campana-editar-{{ $campana->id }}" wire:submit="guardarEdicionCampana" class="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5">
                                <input type="text" wire:model="editandoCampanaNombre" class="form-input flex-1 text-xs">
                                <button type="submit" class="text-xs font-medium text-primary hover:opacity-80">Guardar</button>
                                <button type="button" wire:click="cancelarEdicionCampana" class="text-xs font-medium text-slate-500 hover:opacity-80">Cancelar</button>
                            </form>
                            @error('editandoCampanaNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                        @else
                            <div wire:key="campana-{{ $campana->id }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-xs hover:bg-slate-50">
                                <span class="flex-1 font-medium">{{ $campana->nombre }}</span>
                                <button type="button" wire:click="editarCampana({{ $campana->id }})" class="ml-2 font-medium text-slate-500 hover:opacity-80">
                                    Editar
                                </button>
                            </div>
                        @endif
                    @empty
                        <p class="py-3 text-center text-xs text-slate-400">Aún no hay campañas para esta área.</p>
                    @endforelse
                </div>
            @endif
        </div>

        {{-- Cargos del área --}}
        <div class="card-panel-compact lg:col-span-2">
            <h3 class="mb-2 text-sm font-semibold text-slate-900">
                Cargos
                @if ($this->areaSeleccionada())
                    <span class="font-normal text-slate-400">— {{ $this->areaSeleccionada()->nombre }}</span>
                @endif
            </h3>

            @if ($this->areaSeleccionada() && ! $this->areaSeleccionada()->usa_cargo)
                <p class="py-3 text-center text-xs text-slate-400">Cargo no aplica para esta área.</p>
            @else
                <form wire:submit="agregarCargo" class="mb-3 flex flex-wrap items-end gap-2">
                    <div class="flex-1">
                        <label class="form-label text-xs">Nombre</label>
                        <input type="text" wire:model="nuevoCargoNombre" class="form-input text-xs" placeholder="Ej. Analista">
                        @error('nuevoCargoNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn-secondary w-auto px-3 py-1.5 text-xs">Agregar</button>
                </form>

                <div class="grid gap-1 sm:grid-cols-2 lg:grid-cols-4">
                    @forelse ($this->cargosDelArea() as $cargo)
                        @if ($editandoCargoId === $cargo->id)
                            <form wire:key="cargo-editar-{{ $cargo->id }}" wire:submit="guardarEdicionCargo" class="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5 sm:col-span-2 lg:col-span-4">
                                <input type="text" wire:model="editandoCargoNombre" class="form-input flex-1 text-xs">
                                <button type="submit" class="text-xs font-medium text-primary hover:opacity-80">Guardar</button>
                                <button type="button" wire:click="cancelarEdicionCargo" class="text-xs font-medium text-slate-500 hover:opacity-80">Cancelar</button>
                            </form>
                            @error('editandoCargoNombre') <p class="form-error text-xs sm:col-span-2 lg:col-span-4">{{ $message }}</p> @enderror
                        @else
                            <div wire:key="cargo-{{ $cargo->id }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-xs hover:bg-slate-50">
                                <span class="flex-1 font-medium">{{ $cargo->nombre }}</span>
                                <button type="button" wire:click="editarCargo({{ $cargo->id }})" class="ml-2 font-medium text-slate-500 hover:opacity-80">
                                    Editar
                                </button>
                            </div>
                        @endif
                    @empty
                        <p class="col-span-full py-3 text-center text-xs text-slate-400">Aún no hay cargos para esta área.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</div>
