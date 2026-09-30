<?php

use App\Models\Campana;
use App\Models\Cargo;
use App\Models\Puesto;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    #[Validate('required|string|max:100|unique:campanas,nombre')]
    public string $nuevaCampanaNombre = '';

    #[Validate('required|string|max:100|unique:puestos,nombre')]
    public string $nuevoPuestoNombre = '';

    #[Validate('required|string|max:100|unique:cargos,nombre')]
    public string $nuevoCargoNombre = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);
    }

    public function campanas()
    {
        return Campana::orderBy('nombre')->get();
    }

    public function puestos()
    {
        return Puesto::orderBy('nombre')->get();
    }

    public function cargos()
    {
        return Cargo::orderBy('nombre')->get();
    }

    public function agregarCampana(): void
    {
        $this->validate([
            'nuevaCampanaNombre' => 'required|string|max:100|unique:campanas,nombre',
        ]);

        Campana::create(['nombre' => $this->nuevaCampanaNombre]);

        $this->reset(['nuevaCampanaNombre']);
    }

    public function eliminarCampana(int $id): void
    {
        Campana::findOrFail($id)->delete();
    }

    public function agregarPuesto(): void
    {
        $this->validate([
            'nuevoPuestoNombre' => 'required|string|max:100|unique:puestos,nombre',
        ]);

        Puesto::create(['nombre' => $this->nuevoPuestoNombre]);

        $this->reset(['nuevoPuestoNombre']);
    }

    public function eliminarPuesto(int $id): void
    {
        Puesto::findOrFail($id)->delete();
    }

    public function agregarCargo(): void
    {
        $this->validate([
            'nuevoCargoNombre' => 'required|string|max:100|unique:cargos,nombre',
        ]);

        Cargo::create(['nombre' => $this->nuevoCargoNombre]);

        $this->reset(['nuevoCargoNombre']);
    }

    public function eliminarCargo(int $id): void
    {
        Cargo::findOrFail($id)->delete();
    }
};
?>

<div class="grid gap-5 lg:grid-cols-3">
    <x-panel.catalogo-lista
        titulo="Campañas"
        descripcion="Disponible como opción en Proceso de reclutamiento."
        :items="$this->campanas()"
        campo="nuevaCampanaNombre"
        metodoAgregar="agregarCampana"
        metodoEliminar="eliminarCampana"
        placeholder="Ej. Móvil, Fija"
    />

    <x-panel.catalogo-lista
        titulo="Puestos"
        :items="$this->puestos()"
        campo="nuevoPuestoNombre"
        metodoAgregar="agregarPuesto"
        metodoEliminar="eliminarPuesto"
        placeholder="Ej. Asesor de ventas"
    />

    <x-panel.catalogo-lista
        titulo="Cargos"
        :items="$this->cargos()"
        campo="nuevoCargoNombre"
        metodoAgregar="agregarCargo"
        metodoEliminar="eliminarCargo"
        placeholder="Ej. Supervisor"
    />
</div>
