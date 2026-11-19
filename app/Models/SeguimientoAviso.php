<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeguimientoAviso extends Model
{
    protected $table = 'seguimiento_avisos';

    protected $fillable = ['empleado_id', 'tipo', 'cantidad', 'destinatarios', 'enviado_at', 'error'];

    protected function casts(): array
    {
        return ['enviado_at' => 'datetime'];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
