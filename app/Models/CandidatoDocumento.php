<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoDocumento extends Model
{
    protected $fillable = [
        'reclutamiento_candidato_id',
        'tipo',
        'nombre_original',
        'ruta',
        'mime_type',
        'tamano',
        'subido_por_id',
    ];

    public function candidato()
    {
        return $this->belongsTo(CandidatoReclutamiento::class, 'reclutamiento_candidato_id');
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por_id');
    }
}
