<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoReclutamiento extends Model
{
    protected $table = 'reclutamiento_candidatos';

    protected array $nullableDateFields = [
        'fecha_gestion',
        'fecha_nacimiento',
        'fecha_entrevista',
        'fecha_capacitacion',
        'fecha_ingreso',
    ];

    protected $fillable = [
        'anio',
        'mes_tipologia',
        'mes',
        'dia',
        'fecha_gestion',
        'base',
        'agente_reclutador',
        'campana',
        'dni_ce',
        'fecha_nacimiento',
        'edad',
        'nombres',
        'numero_celular',
        'distrito',
        'experiencia',
        'observaciones',
        'tipificacion',
        'subtipificacion_rechazo',
        'fuente',
        'aceptacion_entrevista',
        'fecha_entrevista',
        'hora_entrevista',
        'asistio_entrevista',
        'fecha_capacitacion',
        'fecha_ingreso',
        'entrego_documentos',
        'bloqueado_por_id',
        'bloqueado_hasta',
    ];

    protected $casts = [
        'fecha_gestion' => 'date',
        'fecha_nacimiento' => 'date',
        'fecha_entrevista' => 'date',
        'fecha_capacitacion' => 'date',
        'fecha_ingreso' => 'date',
        'bloqueado_hasta' => 'datetime',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->nullableDateFields, true) && $value === '') {
            $value = null;
        }

        parent::setAttribute($key, $value);
    }

    public function findDuplicateByIdentity(): ?self
    {
        $dni = trim((string) ($this->dni_ce ?? ''));
        $nombre = trim((string) ($this->nombres ?? ''));
        $telefono = preg_replace('/\D+/', '', (string) ($this->numero_celular ?? ''));

        if ($dni !== '') {
            $duplicate = static::query()
                ->whereRaw('LOWER(TRIM(COALESCE(dni_ce, ""))) = ?', [mb_strtolower($dni)])
                ->first();

            if ($duplicate) {
                return $duplicate;
            }
        }

        if ($nombre !== '' && $telefono !== '') {
            $duplicate = static::query()
                ->whereRaw('LOWER(TRIM(COALESCE(nombres, ""))) = ?', [mb_strtolower($nombre)])
                ->whereRaw('REPLACE(REPLACE(REPLACE(numero_celular, " ", ""), "-", ""), "+", "") = ?', [$telefono])
                ->first();

            if ($duplicate) {
                return $duplicate;
            }
        }

        return null;
    }

    public function bloqueadoPor()
    {
        return $this->belongsTo(User::class, 'bloqueado_por_id');
    }
}
