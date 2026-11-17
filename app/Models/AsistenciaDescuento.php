<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Decisión de Administración sobre el descuento sugerido a una persona por una tardanza o una falta en un día. */
class AsistenciaDescuento extends Model
{
    public const APLICADO = 'aplicado';

    public const NO_APLICADO = 'no_aplicado';

    public const JUSTIFICADA = 'justificada';

    protected $table = 'asistencia_descuentos';

    protected $fillable = [
        'empleado_id',
        'fecha',
        'tipo',
        'tramo',
        'entrada',
        'estado',
        'monto',
        'comentario',
        'sustento_ruta',
        'sustento_nombre',
        'sustento_mime',
        'decidido_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    /** 'pdf' | 'imagen' | 'otro' (para decidir cómo mostrar el sustento). */
    public function tipoVista(): string
    {
        $extension = strtolower(pathinfo((string) $this->sustento_nombre, PATHINFO_EXTENSION));

        return match (true) {
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['jpg', 'jpeg', 'png'], true) => 'imagen',
            default => 'otro',
        };
    }
}
