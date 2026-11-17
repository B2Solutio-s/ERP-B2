<?php

use App\Models\Empleado;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel', ['title' => 'Usuarios'])] class extends Component
{
    public string $nombre = '';

    public string $apellidos = '';

    public string $email = '';

    public string $password = '';

    public string $rol = 'gth';

    public string $empleadoId = '';

    public string $error = '';

    public bool $mostrarModalCrear = false;

    public ?int $vincularUsuarioId = null;

    public string $vincularEmpleadoId = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);
    }

    public function usuarios()
    {
        return User::with('empleado')->orderBy('name')->get();
    }

    /** Trabajadores con contrato vigente que aún no tienen usuario. */
    public function empleadosDisponibles(): array
    {
        $vinculados = User::whereNotNull('empleado_id')->pluck('empleado_id')->all();

        return Empleado::conContratoVigente()
            ->reject(fn (Empleado $e) => in_array($e->id, $vinculados, true))
            ->mapWithKeys(fn (Empleado $e) => [$e->id => "{$e->nombreCompleto()} — {$e->documento_identidad}"])
            ->all();
    }

    public function usuarioAVincular(): ?User
    {
        return $this->vincularUsuarioId ? User::find($this->vincularUsuarioId) : null;
    }

    /** Al elegir un trabajador en el alta, se precargan nombre, apellidos y correo corporativo. */
    public function updatedEmpleadoId(): void
    {
        $empleado = $this->empleadoId !== '' ? Empleado::find($this->empleadoId) : null;

        if (! $empleado) {
            return;
        }

        $this->nombre = mb_strtoupper(trim((string) $empleado->nombres));
        $this->apellidos = mb_strtoupper(preg_replace('/\s+/', ' ', trim("{$empleado->apellido_paterno} {$empleado->apellido_materno}")));
        $this->email = (string) ($empleado->email_corporativo ?: $this->email);
    }

    /** Reglas del campo trabajador: debe existir, tener contrato vigente y no estar ya vinculado. */
    private function reglasEmpleado(string $campo, bool $obligatorio): array
    {
        return [
            $campo => [
                Rule::requiredIf($obligatorio),
                'nullable',
                'integer',
                'exists:empleados,id',
                'unique:users,empleado_id',
                function (string $atributo, mixed $valor, \Closure $fallo) {
                    $empleado = Empleado::with('periodosContrato')->find($valor);

                    if ($empleado && ! $empleado->tieneContratoVigente()) {
                        $fallo('El trabajador seleccionado no tiene un contrato vigente.');
                    }
                },
            ],
        ];
    }

    public function abrirModalCrear(): void
    {
        $this->reset(['nombre', 'apellidos', 'email', 'password', 'rol', 'empleadoId', 'error']);
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
            'rol' => ['required', Rule::in(array_keys(User::ROLES))],
            ...$this->reglasEmpleado('empleadoId', $this->rol === User::ROL_MKT),
        ], [], [
            'nombre' => 'nombre',
            'apellidos' => 'apellidos',
            'email' => 'correo',
            'password' => 'contraseña',
            'rol' => 'rol',
            'empleadoId' => 'trabajador',
        ]);

        User::create([
            'name' => trim("{$this->nombre} {$this->apellidos}"),
            'email' => $this->email,
            'password' => $this->password,
            'role' => $this->rol,
            'empleado_id' => $this->empleadoId !== '' ? (int) $this->empleadoId : null,
        ]);

        $this->reset(['nombre', 'apellidos', 'email', 'password', 'rol', 'empleadoId']);
        $this->rol = 'gth';
        $this->mostrarModalCrear = false;
    }

    public function abrirModalVincular(int $id): void
    {
        $this->reset(['vincularEmpleadoId', 'error']);
        $this->resetErrorBag();
        $this->vincularUsuarioId = $id;
    }

    public function cerrarModalVincular(): void
    {
        $this->vincularUsuarioId = null;
        $this->resetErrorBag();
    }

    public function vincularTrabajador(): void
    {
        $usuario = $this->usuarioAVincular();
        if (! $usuario) {
            return;
        }

        $this->validate(
            $this->reglasEmpleado('vincularEmpleadoId', true),
            [],
            ['vincularEmpleadoId' => 'trabajador'],
        );

        $usuario->update(['empleado_id' => (int) $this->vincularEmpleadoId]);

        $this->cerrarModalVincular();
    }

    public function desvincularTrabajador(int $id): void
    {
        $this->error = '';

        $usuario = User::find($id);
        if (! $usuario) {
            return;
        }

        if ($usuario->esMkt()) {
            $this->error = 'Un usuario MKT debe estar vinculado a un trabajador.';

            return;
        }

        $usuario->update(['empleado_id' => null]);
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
    <div class="flex items-center justify-end">
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
                        <th>Trabajador</th>
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
                                @if ($usuario->empleado)
                                    {{ $usuario->empleado->nombreCompleto() }}
                                    <button type="button" wire:click="desvincularTrabajador({{ $usuario->id }})" wire:confirm="¿Desvincular a {{ $usuario->empleado->nombres }} de este usuario?" class="ml-2 text-xs font-medium text-slate-400 hover:text-rose-600">Desvincular</button>
                                @else
                                    <button type="button" wire:click="abrirModalVincular({{ $usuario->id }})" class="text-sm font-medium text-primary hover:opacity-80">Vincular trabajador</button>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge :color="match ($usuario->role) { 'admin' => 'green', 'mkt' => 'amber', default => 'slate' }">
                                    {{ $usuario->etiquetaRol() }}
                                </x-ui.badge>
                            </td>
                            <td>
                                @if ($usuario->id !== Auth::id())
                                    <button type="button" wire:click="eliminarUsuario({{ $usuario->id }})" wire:confirm="¿Eliminar a {{ $usuario->name }}?" class="text-sm font-medium text-rose-600 hover:opacity-80">Eliminar</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-slate-400">Aún no hay usuarios.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($mostrarModalCrear)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div class="w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-2xl">
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
                    <x-forms.select label="Rol" name="rol" :options="User::ROLES" placeholder="Selecciona un rol" />
                    <x-forms.select :label="$rol === 'mkt' ? 'Trabajador' : 'Trabajador (opcional)'" name="empleadoId" :live="true" :options="$this->empleadosDisponibles()" placeholder="Sin vincular" />

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

    @if ($vincularUsuarioId && ($usuarioVincular = $this->usuarioAVincular()))
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div class="w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-slate-900">Vincular trabajador a {{ $usuarioVincular->name }}</h3>
                    <button type="button" wire:click="cerrarModalVincular" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="vincularTrabajador" class="grid gap-3 p-4">
                    <x-forms.select label="Trabajador con contrato vigente" name="vincularEmpleadoId" :options="$this->empleadosDisponibles()" placeholder="Selecciona un trabajador" />

                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cerrarModalVincular" class="btn-secondary w-auto">Cancelar</button>
                        <button type="submit" class="btn-primary w-auto" wire:loading.attr="disabled" wire:target="vincularTrabajador">Vincular</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
