<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public string $error = '';

    public function ingresar(): void
    {
        $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            $this->error = 'Correo o contrasena incorrectos.';

            return;
        }

        session()->regenerate();

        $this->redirect(route('panel'), navigate: true);
    }
};
?>

<div class="page-shell flex items-center">
    <div class="page-container">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-bold text-slate-900">{{ config('app.name') }}</h1>
            <p class="mt-1 text-slate-500">Ingresa a tu panel de Recursos Humanos.</p>
        </div>

        <form wire:submit="ingresar" class="card-panel space-y-4">
            @if ($error)
                <p class="form-error">{{ $error }}</p>
            @endif

            <div>
                <label class="form-label">Correo electronico</label>
                <input type="email" wire:model="email" autofocus class="form-input">
                @error('email') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="form-label">Contrasena</label>
                <input type="password" wire:model="password" class="form-input">
                @error('password') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled" class="btn-primary">
                <span wire:loading.remove>Ingresar</span>
                <span wire:loading>Ingresando...</span>
            </button>
        </form>
    </div>
</div>
