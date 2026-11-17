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

    /** Color de tema por defecto y colores sugeridos en el perfil (nombre => #rrggbb). */
    public const TEMA_DEFECTO = '#4e73f8';

    public const TEMAS = [
        'Azul' => '#4e73f8',
        'Índigo' => '#6366f1',
        'Violeta' => '#8b5cf6',
        'Rosa' => '#db2777',
        'Rojo' => '#e11d48',
        'Naranja' => '#ea580c',
        'Verde' => '#16a34a',
        'Turquesa' => '#0d9488',
        'Cian' => '#0891b2',
        'Grafito' => '#475569',
    ];

    public static function colorValido(?string $color): bool
    {
        return is_string($color) && (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    }

    /** Color de tema del usuario (siempre un #rrggbb válido). */
    public function temaColor(): string
    {
        return self::colorValido($this->tema_color) ? strtolower($this->tema_color) : self::TEMA_DEFECTO;
    }

    /** Variables CSS del tema del usuario: el color principal y el color del texto que va encima (claro u oscuro según el contraste). */
    public function temaCss(): string
    {
        $color = $this->temaColor();
        [$r, $g, $b] = array_map(fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, sscanf($color, '#%02x%02x%02x'));
        $luminancia = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        // Texto blanco salvo que el color sea tan claro que no se lea (contraste menor a 3:1), como un amarillo.
        $texto = (1.05 / ($luminancia + 0.05)) >= 3.0 ? '#ffffff' : '#1f2937';

        return ":root{--color-primary:{$color};--color-primary-content:{$texto};}";
    }

    public function tieneFoto(): bool
    {
        return filled($this->foto_ruta);
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
        return $this->esMkt() ? 'resultados-campana' : 'gestion-candidatos';
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
