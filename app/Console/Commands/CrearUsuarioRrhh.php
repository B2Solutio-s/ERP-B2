<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('usuarios:crear {nombre} {email} {password}')]
#[Description('Crea un usuario de Recursos Humanos con acceso al panel del ERP')]
class CrearUsuarioRrhh extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email');

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $usuario = User::create([
            'name' => $this->argument('nombre'),
            'email' => $email,
            'password' => Hash::make($this->argument('password')),
        ]);

        $this->info("Usuario creado correctamente: {$usuario->email}");

        return self::SUCCESS;
    }
}
