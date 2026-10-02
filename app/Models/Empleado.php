<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $fillable = [
        'onboarding_invitation_id',
        'campana',
        'fecha_capa',
        'area',
        'puesto',
        'cargo',
        'departamento',
        'fecha_ingreso',
        'documento_identidad',
        'apellido_paterno',
        'apellido_materno',
        'nombres',
        'fecha_nacimiento',
        'lugar_nacimiento',
        'edad',
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
        'declaracion_datos_veraces',
        'declaracion_autoriza_verificacion',
        'declaracion_capacitacion_condiciones',
        'firma_dni',
        'firma_imagen',
        'estado',
        'contrato_puesto',
        'contrato_area',
        'contrato_campana',
        'contrato_cargo',
        'contrato_duracion',
        'contrato_area_practicas',
        'contrato_universidad',
        'contrato_remuneracion',
        'contrato_remuneracion_movilidad',
        'contrato_fecha_inicio',
        'contrato_fecha_fin',
        'contrato_fecha_firma',
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
            'declaracion_datos_veraces' => 'boolean',
            'declaracion_autoriza_verificacion' => 'boolean',
            'declaracion_capacitacion_condiciones' => 'boolean',
            'contrato_remuneracion' => 'decimal:2',
            'contrato_remuneracion_movilidad' => 'decimal:2',
            'contrato_fecha_inicio' => 'date',
            'contrato_fecha_fin' => 'date',
            'contrato_fecha_firma' => 'date',
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

    public function periodosContrato()
    {
        return $this->hasMany(EmpleadoPeriodoContrato::class)->latest('fecha_inicio');
    }
}
