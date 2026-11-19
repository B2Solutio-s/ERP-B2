<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Compromiso o memorándum de un trabajador (siempre con su documento firmado). */
class SeguimientoRegistro extends Model
{
    public const COMPROMISO = 'compromiso';

    public const MEMORANDUM = 'memorandum';

    public const PENDIENTE = 'pendiente';

    public const CUMPLIDO = 'cumplido';

    public const NO_CUMPLIDO = 'no_cumplido';

    public const REGISTRADO = 'registrado';

    protected $table = 'seguimiento_registros';

    protected $fillable = [
        'empleado_id', 'tipo', 'titulo', 'descripcion', 'fecha_desde', 'fecha_hasta', 'meta_q', 'meta_sots', 'estado',
        'evaluado_por_id', 'evaluado_at', 'evaluacion_observacion',
        'documento_nombre', 'documento_ruta', 'documento_mime',
        'enviado_trabajador_at', 'enviado_trabajador_a', 'envio_error', 'envio_solicitado_at', 'registrado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_desde' => 'date',
            'fecha_hasta' => 'date',
            'evaluado_at' => 'datetime',
            'enviado_trabajador_at' => 'datetime',
            'envio_solicitado_at' => 'datetime',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function evaluadoPor()
    {
        return $this->belongsTo(User::class, 'evaluado_por_id');
    }

    public function esCompromiso(): bool
    {
        return $this->tipo === self::COMPROMISO;
    }
}
