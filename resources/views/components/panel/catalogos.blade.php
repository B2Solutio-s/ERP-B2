<?php

use App\Models\Campana;
use App\Models\Cargo;
use App\Models\Puesto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public string $nuevoPuestoNombre = '';
    public string $nuevaCampanaNombre = '';
    public string $nuevoCargoNombre = '';

    public ?int $puestoSeleccionadoId = null;
    public ?int $campanaSeleccionadaId = null;

    public ?int $editandoPuestoId = null;
    public string $editandoPuestoNombre = '';

    public ?int $editandoCampanaId = null;
    public string $editandoCampanaNombre = '';

    public ?int $editandoCargoId = null;
    public string $editandoCargoNombre = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->puestoSeleccionadoId = Puesto::orderBy('nombre')->value('id');
    }

    public function puestos()
    {
        return Puesto::orderBy('nombre')->get();
    }

    public function campanasDelPuesto()
    {
        if (! $this->puestoSeleccionadoId) {
            return collect();
        }

        return Campana::where('puesto_id', $this->puestoSeleccionadoId)->orderBy('nombre')->get();
    }

    public function cargosDeLaCampana()
    {
        if (! $this->campanaSeleccionadaId) {
            return collect();
        }

        return Cargo::where('campana_id', $this->campanaSeleccionadaId)->orderBy('nombre')->get();
    }

    public function seleccionarPuesto(int $id): void
    {
        $this->puestoSeleccionadoId = $id;
        $this->campanaSeleccionadaId = null;
    }

    public function seleccionarCampana(int $id): void
    {
        $this->campanaSeleccionadaId = $id;
    }

    public function agregarPuesto(): void
    {
        $this->validate([
            'nuevoPuestoNombre' => 'required|string|max:100|unique:puestos,nombre',
        ]);

        $puesto = Puesto::create(['nombre' => $this->nuevoPuestoNombre]);

        $this->reset(['nuevoPuestoNombre']);
        $this->puestoSeleccionadoId = $puesto->id;
        $this->campanaSeleccionadaId = null;
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

        $this->validate([
            'editandoPuestoNombre' => ['required', 'string', 'max:100', Rule::unique('puestos', 'nombre')->ignore($this->editandoPuestoId)],
        ]);

        Puesto::whereKey($this->editandoPuestoId)->update(['nombre' => $this->editandoPuestoNombre]);

        $this->cancelarEdicionPuesto();
    }

    public function cancelarEdicionPuesto(): void
    {
        $this->editandoPuestoId = null;
        $this->editandoPuestoNombre = '';
    }

    public function agregarCampana(): void
    {
        if (! $this->puestoSeleccionadoId) {
            return;
        }

        $this->validate([
            'nuevaCampanaNombre' => 'required|string|max:100|unique:campanas,nombre',
        ]);

        Campana::create([
            'nombre' => $this->nuevaCampanaNombre,
            'puesto_id' => $this->puestoSeleccionadoId,
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

        $this->validate([
            'editandoCampanaNombre' => ['required', 'string', 'max:100', Rule::unique('campanas', 'nombre')->ignore($this->editandoCampanaId)],
        ]);

        Campana::whereKey($this->editandoCampanaId)->update(['nombre' => $this->editandoCampanaNombre]);

        $this->cancelarEdicionCampana();
    }

    public function cancelarEdicionCampana(): void
    {
        $this->editandoCampanaId = null;
        $this->editandoCampanaNombre = '';
    }

    public function agregarCargo(): void
    {
        if (! $this->campanaSeleccionadaId) {
            return;
        }

        $this->validate([
            'nuevoCargoNombre' => [
                'required', 'string', 'max:100',
                Rule::unique('cargos', 'nombre')->where(fn ($query) => $query->where('campana_id', $this->campanaSeleccionadaId)),
            ],
        ]);

        Cargo::create([
            'nombre' => $this->nuevoCargoNombre,
            'campana_id' => $this->campanaSeleccionadaId,
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
                Rule::unique('cargos', 'nombre')
                    ->where(fn ($query) => $query->where('campana_id', $cargo->campana_id))
                    ->ignore($this->editandoCargoId),
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
        <h2 class="text-sm font-semibold text-slate-900">Puestos, campañas y cargos</h2>
        <p class="mt-1 text-xs text-slate-500">
            Selecciona un puesto para ver y administrar sus campañas; selecciona una campaña para ver y administrar sus cargos.
            Las campañas sin ningún cargo registrado se guardan en "Gestión de candidatos" con cargo "-".
            Los nombres se pueden editar, pero no eliminar, para no arrastrar en cascada las campañas/cargos que dependen de ellos.
        </p>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        {{-- Puestos --}}
        <div class="card-panel-compact">
            <h3 class="mb-2 text-sm font-semibold text-slate-900">Puestos</h3>

            <form wire:submit="agregarPuesto" class="mb-3 flex flex-wrap items-end gap-2">
                <div class="flex-1">
                    <label class="form-label text-xs">Nombre</label>
                    <input type="text" wire:model="nuevoPuestoNombre" class="form-input text-xs" placeholder="Ej. Ejecutivo">
                    @error('nuevoPuestoNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-secondary w-auto px-3 py-1.5 text-xs">Agregar</button>
            </form>

            <div class="space-y-1">
                @forelse ($this->puestos() as $puesto)
                    @if ($editandoPuestoId === $puesto->id)
                        <form wire:key="puesto-editar-{{ $puesto->id }}" wire:submit="guardarEdicionPuesto" class="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5">
                            <input type="text" wire:model="editandoPuestoNombre" class="form-input flex-1 text-xs">
                            <button type="submit" class="text-xs font-medium text-primary hover:opacity-80">Guardar</button>
                            <button type="button" wire:click="cancelarEdicionPuesto" class="text-xs font-medium text-slate-500 hover:opacity-80">Cancelar</button>
                        </form>
                        @error('editandoPuestoNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                    @else
                        <div wire:key="puesto-{{ $puesto->id }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-xs {{ $puestoSeleccionadoId === $puesto->id ? 'bg-primary/10 text-primary' : 'hover:bg-slate-50' }}">
                            <button type="button" wire:click="seleccionarPuesto({{ $puesto->id }})" class="flex-1 text-left font-medium">
                                {{ $puesto->nombre }}
                            </button>
                            <button type="button" wire:click="editarPuesto({{ $puesto->id }})" class="ml-2 font-medium text-slate-500 hover:opacity-80">
                                Editar
                            </button>
                        </div>
                    @endif
                @empty
                    <p class="py-3 text-center text-xs text-slate-400">Aún no hay puestos.</p>
                @endforelse
            </div>
        </div>

        {{-- Campañas del puesto seleccionado --}}
        <div class="card-panel-compact">
            <h3 class="mb-2 text-sm font-semibold text-slate-900">
                Campañas
                @if ($puestoSeleccionadoId)
                    <span class="font-normal text-slate-400">— {{ $this->puestos()->firstWhere('id', $puestoSeleccionadoId)?->nombre }}</span>
                @endif
            </h3>

            @if (! $puestoSeleccionadoId)
                <p class="py-3 text-center text-xs text-slate-400">Selecciona un puesto primero.</p>
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
                    @forelse ($this->campanasDelPuesto() as $campana)
                        @if ($editandoCampanaId === $campana->id)
                            <form wire:key="campana-editar-{{ $campana->id }}" wire:submit="guardarEdicionCampana" class="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5">
                                <input type="text" wire:model="editandoCampanaNombre" class="form-input flex-1 text-xs">
                                <button type="submit" class="text-xs font-medium text-primary hover:opacity-80">Guardar</button>
                                <button type="button" wire:click="cancelarEdicionCampana" class="text-xs font-medium text-slate-500 hover:opacity-80">Cancelar</button>
                            </form>
                            @error('editandoCampanaNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                        @else
                            <div wire:key="campana-{{ $campana->id }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-xs {{ $campanaSeleccionadaId === $campana->id ? 'bg-primary/10 text-primary' : 'hover:bg-slate-50' }}">
                                <button type="button" wire:click="seleccionarCampana({{ $campana->id }})" class="flex-1 text-left font-medium">
                                    {{ $campana->nombre }}
                                </button>
                                <button type="button" wire:click="editarCampana({{ $campana->id }})" class="ml-2 font-medium text-slate-500 hover:opacity-80">
                                    Editar
                                </button>
                            </div>
                        @endif
                    @empty
                        <p class="py-3 text-center text-xs text-slate-400">Aún no hay campañas para este puesto.</p>
                    @endforelse
                </div>
            @endif
        </div>

        {{-- Cargos de la campaña seleccionada --}}
        <div class="card-panel-compact">
            <h3 class="mb-2 text-sm font-semibold text-slate-900">
                Cargos
                @if ($campanaSeleccionadaId)
                    <span class="font-normal text-slate-400">— {{ $this->campanasDelPuesto()->firstWhere('id', $campanaSeleccionadaId)?->nombre }}</span>
                @endif
            </h3>

            @if (! $campanaSeleccionadaId)
                <p class="py-3 text-center text-xs text-slate-400">Selecciona una campaña primero.</p>
            @else
                <form wire:submit="agregarCargo" class="mb-3 flex flex-wrap items-end gap-2">
                    <div class="flex-1">
                        <label class="form-label text-xs">Nombre</label>
                        <input type="text" wire:model="nuevoCargoNombre" class="form-input text-xs" placeholder="Ej. Analista">
                        @error('nuevoCargoNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn-secondary w-auto px-3 py-1.5 text-xs">Agregar</button>
                </form>

                <div class="space-y-1">
                    @forelse ($this->cargosDeLaCampana() as $cargo)
                        @if ($editandoCargoId === $cargo->id)
                            <form wire:key="cargo-editar-{{ $cargo->id }}" wire:submit="guardarEdicionCargo" class="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5">
                                <input type="text" wire:model="editandoCargoNombre" class="form-input flex-1 text-xs">
                                <button type="submit" class="text-xs font-medium text-primary hover:opacity-80">Guardar</button>
                                <button type="button" wire:click="cancelarEdicionCargo" class="text-xs font-medium text-slate-500 hover:opacity-80">Cancelar</button>
                            </form>
                            @error('editandoCargoNombre') <p class="form-error text-xs">{{ $message }}</p> @enderror
                        @else
                            <div wire:key="cargo-{{ $cargo->id }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-xs hover:bg-slate-50">
                                <span class="flex-1 font-medium">{{ $cargo->nombre }}</span>
                                <button type="button" wire:click="editarCargo({{ $cargo->id }})" class="ml-2 font-medium text-slate-500 hover:opacity-80">
                                    Editar
                                </button>
                            </div>
                        @endif
                    @empty
                        <p class="py-3 text-center text-xs text-slate-400">Sin cargos — se guardará como "-" en Gestión de candidatos.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</div>
