<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoFamiliar extends Model
{
    protected $table = 'empleado_familiares';

    protected $fillable = [
        'nombres_apellidos',
        'parentesco',
        'fecha_nacimiento',
        'edad',
        'ocupacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
