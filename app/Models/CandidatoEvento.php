<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea del historial de un candidato (alta, cambio de un dato del proceso o llamada). */
class CandidatoEvento extends Model
{
    protected $table = 'reclutamiento_candidato_eventos';

    protected $fillable = [
        'reclutamiento_candidato_id',
        'user_id',
        'tipo',
        'campo',
        'valor_anterior',
        'valor_nuevo',
        'nota',
    ];

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(CandidatoReclutamiento::class, 'reclutamiento_candidato_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
