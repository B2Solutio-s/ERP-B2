<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $fillable = [
        'onboarding_invitation_id',
        'campana',
        'fecha_capa',
        'puesto',
        'departamento',
        'fecha_ingreso',
        'documento_identidad',
        'apellido_paterno',
        'apellido_materno',
        'nombres',
        'fecha_nacimiento',
        'lugar_nacimiento',
        'numero_hijos',
        'estado_civil',
        'sexo',
        'direccion',
        'vivienda_tipo',
        'vivienda_tenencia',
        'distrito',
        'provincia',
        'departamento_residencia',
        'celular_llamadas',
        'celular_whatsapp',
        'email',
        'contacto_emergencia_nombre',
        'contacto_emergencia_parentesco',
        'contacto_emergencia_telefono',
        'pension_afiliado',
        'pension_sistema',
        'pension_afp',
        'pension_cuspp',
        'salud_antecedentes',
        'salud_enfermedad_actual',
        'salud_enfermedad_detalle',
        'salud_medicamentos',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_ingreso' => 'date',
            'fecha_capa' => 'date',
            'pension_afiliado' => 'boolean',
            'salud_antecedentes' => 'boolean',
            'salud_enfermedad_actual' => 'boolean',
        ];
    }

    public function invitacion()
    {
        return $this->belongsTo(OnboardingInvitation::class, 'onboarding_invitation_id');
    }

    public function estudios()
    {
        return $this->hasMany(EmpleadoEstudio::class);
    }

    public function empleosAnteriores()
    {
        return $this->hasMany(EmpleadoEmpleoAnterior::class);
    }

    public function familiares()
    {
        return $this->hasMany(EmpleadoFamiliar::class);
    }
}
