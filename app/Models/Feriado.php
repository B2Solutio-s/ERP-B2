<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Día feriado: no se trabaja y no cuenta como falta ni tardanza. */
class Feriado extends Model
{
    protected $table = 'feriados';

    protected $fillable = [
        'fecha',
        'nombre',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }
}
