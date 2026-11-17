<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Periodo de vacaciones de un trabajador (días calendario corridos). */
class Vacacion extends Model
{
    public const APROBADA = 'aprobada';

    public const CANCELADA = 'cancelada';

    protected $table = 'vacaciones';

    protected $fillable = [
        'empleado_id',
        'fecha_inicio',
        'fecha_fin',
        'dias',
        'estado',
        'comentario',
        'documento_ruta',
        'documento_nombre',
        'documento_mime',
        'registrado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    /** Estado según las fechas: cancelada, programada, en curso o gozada. */
    public function situacion(string $hoy): string
    {
        return match (true) {
            $this->estado === self::CANCELADA => 'cancelada',
            $this->fecha_fin->toDateString() < $hoy => 'gozada',
            $this->fecha_inicio->toDateString() > $hoy => 'programada',
            default => 'en_curso',
        };
    }
}
