<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsistenciaMarcacion extends Model
{
    protected $table = 'asistencia_marcaciones';

    protected $fillable = [
        'biometrico_id',
        'marcado_at',
        'estado',
        'tipo',
    ];

    protected function casts(): array
    {
        return [
            'marcado_at' => 'datetime',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'biometrico_id', 'biometrico_id');
    }
}
