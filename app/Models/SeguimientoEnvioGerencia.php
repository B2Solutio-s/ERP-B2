<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Envío manual a gerencia de registros de Seguimiento de un trabajador. */
class SeguimientoEnvioGerencia extends Model
{
    protected $table = 'seguimiento_envios_gerencia';

    protected $fillable = [
        'empleado_id', 'registro_ids', 'mensaje', 'adjuntar_documentos', 'destinatarios',
        'enviado_por_id', 'enviado_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'registro_ids' => 'array',
            'adjuntar_documentos' => 'boolean',
            'enviado_at' => 'datetime',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function enviadoPor()
    {
        return $this->belongsTo(User::class, 'enviado_por_id');
    }

    public function pendiente(): bool
    {
        return ! $this->enviado_at && ! $this->error;
    }
}
