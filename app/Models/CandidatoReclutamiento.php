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
        'fecha_reprogramada',
        'capacitacion_1',
        'capacitacion_1_reprogramada',
        'capacitacion_2',
        'capacitacion_2_reprogramada',
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
        'apellidos',
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
        'fecha_reprogramada',
        'asistio_entrevista_reprogramada',
        'capacitacion_1',
        'asistio_cap_1',
        'obs_1',
        'capacitacion_1_reprogramada',
        'asistio_cap_1_reprogramada',
        'obs_1_reprogramada',
        'capacitacion_2',
        'asistio_cap_2',
        'obs_2',
        'capacitacion_2_reprogramada',
        'asistio_cap_2_reprogramada',
        'obs_2_reprogramada',
        'apto',
        'fecha_apto',
        'fecha_ingreso',
        'bloqueado_por_id',
        'bloqueado_hasta',
    ];

    protected $casts = [
        'fecha_gestion' => 'date',
        'fecha_nacimiento' => 'date',
        'fecha_entrevista' => 'date',
        'fecha_reprogramada' => 'date',
        'capacitacion_1' => 'date',
        'capacitacion_1_reprogramada' => 'date',
        'capacitacion_2' => 'date',
        'capacitacion_2_reprogramada' => 'date',
        'fecha_ingreso' => 'date',
        'apto' => 'boolean',
        'fecha_apto' => 'datetime',
        'bloqueado_hasta' => 'datetime',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->nullableDateFields, true) && $value === '') {
            $value = null;
        }

        parent::setAttribute($key, $value);
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    public function nombreCorto(): string
    {
        $nombre = trim((string) $this->nombres);
        $apellido = trim((string) $this->apellidos);

        $primerNombre = explode(' ', $nombre)[0] ?? '';
        $primerApellido = explode(' ', $apellido)[0] ?? '';

        return trim("{$primerNombre} {$primerApellido}");
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

    public function invitacionesOnboarding()
    {
        return $this->hasMany(OnboardingInvitation::class, 'reclutamiento_candidato_id');
    }
}
