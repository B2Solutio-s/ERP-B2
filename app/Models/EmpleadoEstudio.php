<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoEstudio extends Model
{
    protected $table = 'empleado_estudios';

    protected $fillable = [
        'nivel',
        'centro_estudios',
        'carrera',
        'desde',
        'hasta',
        'grado_obtenido',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
