<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoEmpleoAnterior extends Model
{
    protected $table = 'empleado_empleos_anteriores';

    protected $fillable = [
        'empresa',
        'cargo',
        'funcion_principal',
        'sueldo',
        'fecha_inicio',
        'fecha_termino',
        'motivo_cese',
        'jefe_nombre',
        'jefe_cargo',
        'jefe_celular',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
