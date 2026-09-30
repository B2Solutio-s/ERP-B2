<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    public string $nombre = '';

    public string $apellidos = '';

    public string $email = '';

    public string $password = '';

    public string $rol = 'gth';

    public string $error = '';

    public bool $mostrarModalCrear = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);
    }

    public function usuarios()
    {
        return User::orderBy('name')->get();
    }

    public function abrirModalCrear(): void
    {
        $this->reset(['nombre', 'apellidos', 'email', 'password', 'rol', 'error']);
        $this->rol = 'gth';
        $this->mostrarModalCrear = true;
    }

    public function cerrarModalCrear(): void
    {
        $this->mostrarModalCrear = false;
    }

    public function crearUsuario(): void
    {
        $this->error = '';
        $this->nombre = mb_strtoupper(trim($this->nombre));
        $this->apellidos = mb_strtoupper(trim($this->apellidos));

        $this->validate([
            'nombre' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'rol' => 'required|in:admin,gth',
        ], [], [
            'nombre' => 'nombre',
            'apellidos' => 'apellidos',
            'email' => 'correo',
            'password' => 'contraseña',
            'rol' => 'rol',
        ]);

        User::create([
            'name' => trim("{$this->nombre} {$this->apellidos}"),
            'email' => $this->email,
            'password' => $this->password,
            'role' => $this->rol,
        ]);

        $this->reset(['nombre', 'apellidos', 'email', 'password', 'rol']);
        $this->rol = 'gth';
        $this->mostrarModalCrear = false;
    }

    public function eliminarUsuario(int $id): void
    {
        $this->error = '';

        if ($id === Auth::id()) {
            $this->error = 'No puedes eliminar tu propia cuenta.';

            return;
        }

        $usuario = User::find($id);
        if (! $usuario) {
            return;
        }

        if ($usuario->esAdmin() && User::where('role', User::ROL_ADMIN)->count() <= 1) {
            $this->error = 'Debe quedar al menos un usuario con rol admin.';

            return;
        }

        $usuario->delete();
    }
};
?>

<div class="space-y-5">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Usuarios</h1>
        <button type="button" wire:click="abrirModalCrear" class="btn-primary w-auto">
            + Crear usuario
        </button>
    </div>

    <div class="card-panel-compact">
        <h2 class="mb-4 text-base font-semibold text-slate-900">Lista de usuarios</h2>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->usuarios() as $usuario)
                        <tr wire:key="usuario-{{ $usuario->id }}">
                            <td>{{ $usuario->name }}</td>
                            <td>{{ $usuario->email }}</td>
                            <td>
                                <x-ui.badge :color="$usuario->esAdmin() ? 'green' : 'slate'">
                                    {{ $usuario->esAdmin() ? 'Admin' : 'GTH' }}
                                </x-ui.badge>
                            </td>
                            <td>
                                @if ($usuario->id !== Auth::id())
                                    <button type="button" wire:click="eliminarUsuario({{ $usuario->id }})" wire:confirm="¿Eliminar a {{ $usuario->name }}?" class="text-sm font-medium text-rose-600 hover:opacity-80">Eliminar</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-slate-400">Aún no hay usuarios.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($mostrarModalCrear)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div class="w-full max-w-xs overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-slate-900">Crear usuario</h3>
                    <button type="button" wire:click="cerrarModalCrear" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="crearUsuario" class="usuarios-modal-form grid gap-3 p-4">
                    <div class="usuarios-modal-mayusculas">
                        <x-forms.field label="Nombre" name="nombre" />
                    </div>
                    <div class="usuarios-modal-mayusculas">
                        <x-forms.field label="Apellidos" name="apellidos" />
                    </div>
                    <x-forms.field label="Correo" name="email" type="email" />
                    <x-forms.field label="Contraseña" name="password" type="password" />
                    <x-forms.select label="Rol" name="rol" :options="['admin' => 'Admin', 'gth' => 'GTH']" placeholder="Selecciona un rol" />

                    <div>
                        @if ($error)
                            <p class="form-error mb-2">{{ $error }}</p>
                        @endif
                        <div class="flex justify-end gap-2">
                            <button type="button" wire:click="cerrarModalCrear" class="btn-secondary w-auto">Cancelar</button>
                            <button type="submit" class="btn-primary w-auto" wire:loading.attr="disabled" wire:target="crearUsuario">
                                <span wire:loading.remove wire:target="crearUsuario">Crear usuario</span>
                                <span wire:loading wire:target="crearUsuario">Creando...</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
