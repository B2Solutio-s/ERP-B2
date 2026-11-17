<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dotación (personal con contrato vigente) que se debe mantener en una campaña. */
class MetaDotacion extends Model
{
    protected $table = 'metas_dotacion';

    protected $fillable = [
        'campana',
        'meta',
        'actualizado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'integer',
        ];
    }
}
