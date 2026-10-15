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
        'reclutamiento_candidato_id',
        'expira_en',
        'usado_en',
        'correo_a',
        'correo_encolado_at',
        'correo_enviado_at',
        'correo_error',
    ];

    protected function casts(): array
    {
        return [
            'expira_en' => 'datetime',
            'usado_en' => 'datetime',
            'correo_encolado_at' => 'datetime',
            'correo_enviado_at' => 'datetime',
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

    /** Link que recibe el trabajador: con la dirección pública si está configurada. */
    public function url(): string
    {
        $base = rtrim((string) config('ficha.url_publica'), '/');

        return $base !== '' ? $base.'/alta/'.$this->token : route('onboarding.form', $this->token);
    }

    public function empleado()
    {
        return $this->hasOne(Empleado::class);
    }

    public function candidatoReclutamiento()
    {
        return $this->belongsTo(CandidatoReclutamiento::class, 'reclutamiento_candidato_id');
    }

    public function estaVigente(): bool
    {
        return is_null($this->usado_en) && $this->expira_en->isFuture();
    }

    /** 'sin-enviar' | 'en-cola' | 'enviado' | 'error' */
    public function estadoCorreo(): string
    {
        if ($this->correo_error) {
            return 'error';
        }

        if ($this->correo_enviado_at) {
            return 'enviado';
        }

        return $this->correo_encolado_at ? 'en-cola' : 'sin-enviar';
    }

    public function estadoLabel(): string
    {
        if ($this->usado_en) {
            return 'usada';
        }

        return $this->expira_en->isPast() ? 'expirada' : 'vigente';
    }
}
