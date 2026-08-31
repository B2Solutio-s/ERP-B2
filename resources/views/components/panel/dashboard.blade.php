<?php

use App\Mail\OnboardingInvitationMail;
use App\Models\Empleado;
use App\Models\OnboardingInvitation;
use App\Services\QrCodeGenerator;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component
{
    #[Validate('required|string|max:150')]
    public string $nombreCandidato = '';

    #[Validate('nullable|email|max:150')]
    public string $emailCandidato = '';

    public ?int $invitacionGeneradaId = null;

    public string $mensajeCorreo = '';

    public function generarInvitacion(): void
    {
        $this->validate();

        $invitacion = OnboardingInvitation::generar(
            nombreCandidato: $this->nombreCandidato,
            emailCandidato: $this->emailCandidato ?: null,
        );

        $this->invitacionGeneradaId = $invitacion->id;
        $this->mensajeCorreo = '';
        $this->reset(['nombreCandidato', 'emailCandidato']);
    }

    public function enviarCorreo(): void
    {
        $invitacion = OnboardingInvitation::findOrFail($this->invitacionGeneradaId);

        if (! $invitacion->email_candidato) {
            $this->mensajeCorreo = 'Esta invitacion no tiene un correo asociado.';

            return;
        }

        Mail::to($invitacion->email_candidato)->send(new OnboardingInvitationMail($invitacion));

        $this->mensajeCorreo = "Correo enviado a {$invitacion->email_candidato}.";
    }

    public function cerrarResultado(): void
    {
        $this->invitacionGeneradaId = null;
        $this->mensajeCorreo = '';
    }

    public function invitacionGenerada(): ?OnboardingInvitation
    {
        return $this->invitacionGeneradaId
            ? OnboardingInvitation::find($this->invitacionGeneradaId)
            : null;
    }

    public function invitacionGeneradaUrl(): ?string
    {
        return $this->invitacionGenerada()
            ? route('onboarding.form', $this->invitacionGenerada()->token)
            : null;
    }

    public function invitacionGeneradaQr(): ?string
    {
        return $this->invitacionGeneradaUrl()
            ? QrCodeGenerator::dataUri($this->invitacionGeneradaUrl())
            : null;
    }

    public function invitaciones()
    {
        return OnboardingInvitation::latest()->limit(10)->get();
    }

    public function empleados()
    {
        return Empleado::latest()->limit(10)->get();
    }

    public function totalPendientes(): int
    {
        return Empleado::where('estado', 'pendiente')->count();
    }

    public function totalAprobados(): int
    {
        return Empleado::where('estado', 'aprobado')->count();
    }
};
?>

<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="stat-tile">
            <p class="stat-tile-value">{{ $this->totalPendientes() }}</p>
            <p class="stat-tile-label">Empleados pendientes de aprobar</p>
        </div>
        <div class="stat-tile">
            <p class="stat-tile-value">{{ $this->totalAprobados() }}</p>
            <p class="stat-tile-label">Empleados aprobados</p>
        </div>
        <div class="stat-tile">
            <p class="stat-tile-value">{{ $this->invitaciones()->count() }}</p>
            <p class="stat-tile-label">Invitaciones recientes</p>
        </div>
    </div>

    <div class="card-panel">
        <h2 class="mb-4 text-lg font-semibold text-slate-900">Generar invitacion de alta</h2>

        <form wire:submit="generarInvitacion" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="form-label">Nombre del candidato</label>
                <input type="text" wire:model="nombreCandidato" class="form-input">
                @error('nombreCandidato') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">Correo (opcional, para envio directo)</label>
                <input type="email" wire:model="emailCandidato" class="form-input">
                @error('emailCandidato') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <button type="submit" wire:loading.attr="disabled" class="btn-primary sm:w-auto sm:px-8">
                    <span wire:loading.remove>Generar invitacion</span>
                    <span wire:loading>Generando...</span>
                </button>
            </div>
        </form>

        @if ($this->invitacionGenerada())
            <div class="mt-6 rounded-xl border border-slate-200 p-5">
                <div class="mb-4 flex items-start justify-between">
                    <h3 class="font-semibold text-slate-900">Invitacion generada para {{ $this->invitacionGenerada()->nombre_candidato }}</h3>
                    <button type="button" wire:click="cerrarResultado" class="text-sm text-slate-400 hover:text-slate-600">Cerrar</button>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-[auto,1fr] sm:items-start">
                    <img src="{{ $this->invitacionGeneradaQr() }}" alt="Codigo QR de la invitacion" class="h-40 w-40 rounded-lg border border-slate-200 p-2">

                    <div class="space-y-3">
                        <div>
                            <label class="form-label">Enlace</label>
                            <div class="flex gap-2" x-data="{ url: @js($this->invitacionGeneradaUrl()), copiado: false }">
                                <input type="text" readonly x-model="url" class="form-input" @click="$event.target.select()">
                                <button type="button" class="btn-secondary shrink-0" x-on:click="navigator.clipboard.writeText(url); copiado = true; setTimeout(() => copiado = false, 2000)">
                                    <span x-show="!copiado">Copiar</span>
                                    <span x-show="copiado">Copiado</span>
                                </button>
                            </div>
                        </div>

                        @if ($this->invitacionGenerada()->email_candidato)
                            <button type="button" wire:click="enviarCorreo" wire:loading.attr="disabled" class="btn-secondary">
                                Enviar por correo a {{ $this->invitacionGenerada()->email_candidato }}
                            </button>
                        @endif

                        @if ($mensajeCorreo)
                            <p class="text-sm text-emerald-600">{{ $mensajeCorreo }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="card-panel">
        <h2 class="mb-4 text-lg font-semibold text-slate-900">Invitaciones recientes</h2>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Candidato</th>
                        <th>Correo</th>
                        <th>Estado</th>
                        <th>Creada</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->invitaciones() as $invitacion)
                        <tr>
                            <td>{{ $invitacion->nombre_candidato ?? '-' }}</td>
                            <td>{{ $invitacion->email_candidato ?? '-' }}</td>
                            <td>
                                @php $estado = $invitacion->estadoLabel(); @endphp
                                <x-ui.badge :color="match ($estado) { 'usada' => 'green', 'expirada' => 'slate', default => 'amber' }">
                                    {{ $estado }}
                                </x-ui.badge>
                            </td>
                            <td>{{ $invitacion->created_at->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-slate-400">Aun no hay invitaciones.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-panel">
        <h2 class="mb-4 text-lg font-semibold text-slate-900">Empleados dados de alta</h2>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Puesto</th>
                        <th>Departamento</th>
                        <th>Estado</th>
                        <th>Fecha de ingreso</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->empleados() as $empleado)
                        <tr>
                            <td>{{ $empleado->nombres }} {{ $empleado->apellido_paterno }} {{ $empleado->apellido_materno }}</td>
                            <td>{{ $empleado->puesto }}</td>
                            <td>{{ $empleado->departamento }}</td>
                            <td>
                                <x-ui.badge :color="match ($empleado->estado) { 'aprobado' => 'green', 'rechazado' => 'slate', default => 'amber' }">
                                    {{ $empleado->estado }}
                                </x-ui.badge>
                            </td>
                            <td>{{ $empleado->fecha_ingreso->format('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-slate-400">Aun no hay empleados registrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
