<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OnboardingInvitation extends Model
{
    protected $fillable = [
        'token',
        'nombre_candidato',
        'email_candidato',
        'creado_por',
        'expira_en',
        'usado_en',
    ];

    protected function casts(): array
    {
        return [
            'expira_en' => 'datetime',
            'usado_en' => 'datetime',
        ];
    }

    public static function generar(?string $nombreCandidato = null, ?string $emailCandidato = null, int $diasValidez = 7): self
    {
        return self::create([
            'token' => Str::random(40),
            'nombre_candidato' => $nombreCandidato,
            'email_candidato' => $emailCandidato,
            'expira_en' => now()->addDays($diasValidez),
        ]);
    }

    public function empleado()
    {
        return $this->hasOne(Empleado::class);
    }

    public function estaVigente(): bool
    {
        return is_null($this->usado_en) && $this->expira_en->isFuture();
    }

    public function estadoLabel(): string
    {
        if ($this->usado_en) {
            return 'usada';
        }

        return $this->expira_en->isPast() ? 'expirada' : 'vigente';
    }
}
