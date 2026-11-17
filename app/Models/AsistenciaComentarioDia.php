<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nota de RRHH sobre todo un día de asistencia (p. ej. una falla del biométrico); una por fecha. */
class AsistenciaComentarioDia extends Model
{
    protected $table = 'asistencia_comentarios_dia';

    protected $fillable = [
        'fecha',
        'comentario',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }
}
