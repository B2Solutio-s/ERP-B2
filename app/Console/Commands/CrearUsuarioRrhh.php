<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('usuarios:crear {nombre} {email} {password} {--rol=gth : admin o gth}')]
#[Description('Crea un usuario del ERP (rol admin o gth)')]
class CrearUsuarioRrhh extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email');
        $rol = $this->option('rol');

        if (! in_array($rol, [User::ROL_ADMIN, User::ROL_GTH], true)) {
            $this->error('El rol debe ser "admin" o "gth".');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $usuario = User::create([
            'name' => $this->argument('nombre'),
            'email' => $email,
            'password' => Hash::make($this->argument('password')),
            'role' => $rol,
        ]);

        $this->info("Usuario creado correctamente: {$usuario->email} (rol: {$usuario->role})");

        return self::SUCCESS;
    }
}
