<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nota opcional de RRHH sobre la asistencia de una persona en un día (p. ej. el motivo de una tardanza). */
class AsistenciaComentario extends Model
{
    protected $table = 'asistencia_comentarios';

    protected $fillable = [
        'biometrico_id',
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
