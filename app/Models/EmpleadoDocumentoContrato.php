<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoDocumentoContrato extends Model
{
    protected $table = 'empleado_documentos_contrato';

    protected $fillable = [
        'periodo_id',
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

    /** 'pdf' | 'imagen' | 'otro' (Word no se puede previsualizar en el navegador). */
    public function tipoVista(): string
    {
        $extension = strtolower(pathinfo($this->nombre_original, PATHINFO_EXTENSION));

        return match (true) {
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['jpg', 'jpeg', 'png'], true) => 'imagen',
            default => 'otro',
        };
    }
}
