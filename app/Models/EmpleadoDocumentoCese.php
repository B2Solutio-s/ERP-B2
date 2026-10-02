<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoDocumentoCese extends Model
{
    protected $table = 'empleado_documentos_cese';

    protected $fillable = [
        'empleado_id',
        'tipo',
        'nombre_original',
        'ruta',
        'mime_type',
        'tamano',
        'subido_por_id',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por_id');
    }
}
