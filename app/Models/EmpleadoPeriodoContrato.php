<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoPeriodoContrato extends Model
{
    protected $table = 'empleado_periodos_contrato';

    protected $fillable = [
        'empleado_id',
        'tipo',
        'fecha_inicio',
        'fecha_fin',
        'duracion_meses',
        'cese',
        'cese_tipo',
        'cese_fecha',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'cese_fecha' => 'date',
            'cese' => 'boolean',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function documentosCese()
    {
        return $this->hasMany(EmpleadoDocumentoCese::class, 'periodo_id');
    }

    public function estado(): string
    {
        if ($this->cese) {
            return 'cesado';
        }

        $hoy = now()->startOfDay();

        if ($hoy->gt($this->fecha_fin)) {
            return 'vencido';
        }

        if ($hoy->gte($this->fecha_fin->copy()->subDays(15))) {
            return 'por_vencer';
        }

        return 'activo';
    }
}
