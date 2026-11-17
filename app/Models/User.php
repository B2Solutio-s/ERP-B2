<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'empleado_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROL_ADMIN = 'admin';

    public const ROL_GTH = 'gth';

    public const ROL_MKT = 'mkt';

    public const ROLES = [
        self::ROL_ADMIN => 'Admin',
        self::ROL_GTH => 'GTH',
        self::ROL_MKT => 'MKT',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function esAdmin(): bool
    {
        return $this->role === self::ROL_ADMIN;
    }

    public function esGth(): bool
    {
        return $this->role === self::ROL_GTH;
    }

    public function esMkt(): bool
    {
        return $this->role === self::ROL_MKT;
    }

    public function etiquetaRol(): string
    {
        return self::ROLES[$this->role] ?? (string) $this->role;
    }

    /** Ruta inicial según el rol (MKT no tiene acceso a los módulos de RRHH). */
    public function rutaInicial(): string
    {
        return $this->esMkt() ? 'resultados-campana' : 'proceso-reclutamiento';
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
