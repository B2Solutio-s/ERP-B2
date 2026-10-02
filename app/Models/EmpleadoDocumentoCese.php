<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoDocumentoCese extends Model
{
    protected $table = 'empleado_documentos_cese';

    protected $fillable = [
        'periodo_id',
        'tipo',
        'nombre_original',
        'ruta',
        'mime_type',
        'tamano',
        'subido_por_id',
    ];

    public function periodo()
    {
        return $this->belongsTo(EmpleadoPeriodoContrato::class, 'periodo_id');
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por_id');
    }
}
