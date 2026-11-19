<?php

namespace App\Services;

use App\Models\CandidatoReclutamiento;
use App\Models\OnboardingInvitation;
use App\Models\Puesto;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Proceso posterior a la entrevista 1, según el puesto:
 *  - recorrido «capacitacion» (Ejecutivo): capacitación 1 → capacitación 2 → apto.
 *  - recorrido «entrevista_2» (administrativos, supervisores, mesa de control, gerente comercial): 2.ª entrevista → apto.
 *
 * Reglas de siempre: el bloque siguiente solo se habilita si el anterior salió «SI, APTO» (o reprogramado con «SI, APTO»);
 * apto solo se marca si el último bloque salió «SI, APTO»; quien está apto queda bloqueado y solo el administrador lo desmarca;
 * la asistencia no se marca antes de la fecha.
 */
class ProcesoCapacitacion
{
    public const RESULTADOS = ['SI, APTO', 'SI, NO APTO', 'NO', 'REPROGRAMADO'];

    public const RESULTADOS_REPROGRAMADA = ['SI, APTO', 'SI, NO APTO', 'NO'];

    /** Columnas de cada bloque del proceso. */
    public const BLOQUES = [
        'cap1' => [
            'nombre' => 'Capacitación 1', 'corto' => 'Cap. 1', 'fecha' => 'capacitacion_1', 'hora' => null,
            'asistio' => 'asistio_cap_1', 'fecha_rep' => 'capacitacion_1_reprogramada', 'asistio_rep' => 'asistio_cap_1_reprogramada',
            'obs' => 'obs_1', 'obs_rep' => 'obs_1_reprogramada',
        ],
        'cap2' => [
            'nombre' => 'Capacitación 2', 'corto' => 'Cap. 2', 'fecha' => 'capacitacion_2', 'hora' => null,
            'asistio' => 'asistio_cap_2', 'fecha_rep' => 'capacitacion_2_reprogramada', 'asistio_rep' => 'asistio_cap_2_reprogramada',
            'obs' => 'obs_2', 'obs_rep' => 'obs_2_reprogramada',
        ],
        'ent2' => [
            'nombre' => '2.ª entrevista', 'corto' => '2.ª entrev.', 'fecha' => 'entrevista_2', 'hora' => 'hora_entrevista_2',
            'asistio' => 'asistio_entrevista_2', 'fecha_rep' => 'entrevista_2_reprogramada', 'asistio_rep' => 'asistio_entrevista_2_reprogramada',
            'obs' => 'obs_entrevista_2', 'obs_rep' => 'obs_entrevista_2_reprogramada',
        ],
    ];

    public const RECORRIDOS = [
        'capacitacion' => ['nombre' => 'Capacitación', 'bloques' => ['cap1', 'cap2']],
        'entrevista_2' => ['nombre' => '2.ª entrevista', 'bloques' => ['ent2']],
    ];

    /** @var array<string, string>|null */
    private static ?array $mapaPuestos = null;

    public static function olvidarCache(): void
    {
        self::$mapaPuestos = null;
    }

    private function hoy(): string
    {
        return Carbon::now('America/Lima')->toDateString();
    }

    private function mayus(?string $valor): string
    {
        return mb_strtoupper(trim((string) $valor));
    }

    // ---------------------------------------------------------------
    // Recorrido y bloques
    // ---------------------------------------------------------------

    public function recorrido(CandidatoReclutamiento $c): string
    {
        self::$mapaPuestos ??= Puesto::query()->get(['nombre', 'recorrido'])
            ->mapWithKeys(fn ($p) => [mb_strtoupper(trim($p->nombre)) => $p->recorrido])->all();

        $recorrido = self::$mapaPuestos[$this->mayus($c->puesto)] ?? 'capacitacion';

        return array_key_exists($recorrido, self::RECORRIDOS) ? $recorrido : 'capacitacion';
    }

    /** @return array<int, string> claves de bloque, en orden */
    public function bloques(CandidatoReclutamiento $c): array
    {
        return self::RECORRIDOS[$this->recorrido($c)]['bloques'];
    }

    /** Entrevista 1 aprobada («SI, APTO», o reprogramada con «SI, APTO»). */
    public function graduado(CandidatoReclutamiento $c): bool
    {
        return $c->asistio_entrevista === 'SI, APTO'
            || ($c->asistio_entrevista === 'REPROGRAMADO' && $c->asistio_entrevista_reprogramada === 'SI, APTO');
    }

    /** Entra a este módulo quien aprobó la entrevista 1, ya tiene alguna fecha del proceso o ya está apto. */
    public function elegible(CandidatoReclutamiento $c): bool
    {
        if ($c->apto || $this->graduado($c)) {
            return true;
        }

        foreach (self::BLOQUES as $b) {
            if ($c->{$b['fecha']}) {
                return true;
            }
        }

        return false;
    }

    /** @return array{fecha: ?Carbon, hora: string, asistio: string, fecha_rep: ?Carbon, asistio_rep: string, obs: string, obs_rep: string} */
    public function valores(CandidatoReclutamiento $c, string $bloque): array
    {
        $b = self::BLOQUES[$bloque];

        return [
            'fecha' => $c->{$b['fecha']},
            'hora' => $b['hora'] ? (string) $c->{$b['hora']} : '',
            'asistio' => $this->mayus($c->{$b['asistio']}),
            'fecha_rep' => $c->{$b['fecha_rep']},
            'asistio_rep' => $this->mayus($c->{$b['asistio_rep']}),
            'obs' => (string) $c->{$b['obs']},
            'obs_rep' => (string) $c->{$b['obs_rep']},
        ];
    }

    /**
     * Resultado del bloque: sin_agendar | agendado | reprogramado_sin_fecha | reprogramado | apto | no_apto | no_asistio | por_definir.
     */
    public function resultado(CandidatoReclutamiento $c, string $bloque): string
    {
        $v = $this->valores($c, $bloque);

        if (! $v['fecha']) {
            return 'sin_agendar';
        }

        $resultado = $v['asistio'];

        if ($resultado === '') {
            return 'agendado';
        }

        if ($resultado === 'REPROGRAMADO') {
            if (! $v['fecha_rep']) {
                return 'reprogramado_sin_fecha';
            }

            if ($v['asistio_rep'] === '') {
                return 'reprogramado';
            }

            $resultado = $v['asistio_rep'];
        }

        return match (true) {
            $resultado === 'SI, APTO' => 'apto',
            str_contains($resultado, 'NO APTO') => 'no_apto',
            $resultado === 'NO' => 'no_asistio',
            default => 'por_definir',
        };
    }

    /** Fecha en que le toca asistir ahora (la reprogramada si la original se reprogramó). */
    public function fechaVigente(CandidatoReclutamiento $c, string $bloque): ?Carbon
    {
        $v = $this->valores($c, $bloque);

        return $v['asistio'] === 'REPROGRAMADO' ? $v['fecha_rep'] : $v['fecha'];
    }

    public function habilitado(CandidatoReclutamiento $c, string $bloque): bool
    {
        $bloques = $this->bloques($c);
        $i = array_search($bloque, $bloques, true);

        if ($i === false || ! $this->elegible($c)) {
            return false;
        }

        return $i === 0 || $this->resultado($c, $bloques[$i - 1]) === 'apto';
    }

    /** Todos los bloques de su recorrido salieron «SI, APTO». */
    public function completo(CandidatoReclutamiento $c): bool
    {
        foreach ($this->bloques($c) as $bloque) {
            if ($this->resultado($c, $bloque) !== 'apto') {
                return false;
            }
        }

        return true;
    }

    public function puedeMarcarApto(CandidatoReclutamiento $c): bool
    {
        return ! $c->apto && $this->graduado($c) && $this->completo($c);
    }

    // ---------------------------------------------------------------
    // Estado, siguiente paso y avance
    // ---------------------------------------------------------------

    /**
     * Estado del candidato en el proceso.
     * clave: por_agendar | agendado | hoy | pendiente_resultado | listo_apto | apto | no_continua | por_confirmar.
     *
     * @return array{clave: string, texto: string, tono: string, detalle: string, orden: int|float, bloque: ?string}
     */
    public function estado(CandidatoReclutamiento $c): array
    {
        $hoy = $this->hoy();
        $e = fn (string $clave, string $texto, string $tono, string $detalle, int|float $orden, ?string $bloque = null) => compact('clave', 'texto', 'tono', 'detalle', 'orden', 'bloque');

        if ($c->apto) {
            return $e('apto', 'Apto', 'green', 'Ya puede continuar con su ficha de ingreso', 9);
        }

        if (! $this->graduado($c) && ! collect(self::BLOQUES)->contains(fn ($b) => $c->{$b['fecha']})) {
            return $e('por_confirmar', 'Sin datos del proceso', 'slate', 'No tiene la entrevista 1 aprobada', 8);
        }

        foreach ($this->bloques($c) as $bloque) {
            $b = self::BLOQUES[$bloque];
            $resultado = $this->resultado($c, $bloque);
            $v = $this->valores($c, $bloque);
            $vigente = $this->fechaVigente($c, $bloque)?->toDateString();
            $cuando = $vigente ? Carbon::parse($vigente)->format('d/m').($v['hora'] !== '' && $resultado === 'agendado' ? ' '.$v['hora'] : '') : '';
            $rep = $resultado === 'reprogramado' ? ' (reprogramada)' : '';

            switch ($resultado) {
                case 'sin_agendar':
                    return $e('por_agendar', 'Agendar '.$b['corto'], 'amber', 'Falta la fecha de '.mb_strtolower($b['nombre']), 1, $bloque);

                case 'reprogramado_sin_fecha':
                    return $e('por_agendar', 'Falta nueva fecha', 'amber', $b['nombre'].' reprogramada sin nueva fecha', 1, $bloque);

                case 'agendado':
                case 'reprogramado':
                    return match (true) {
                        $vigente === $hoy => $e('hoy', $b['corto'].' hoy'.($v['hora'] !== '' && $resultado === 'agendado' ? ' '.$v['hora'] : '').$rep, 'amber', 'Confirmar asistencia y marcar el resultado', 0, $bloque),
                        $vigente < $hoy => $e('pendiente_resultado', 'Marcar asistencia', 'rose', $b['nombre'].$rep.' fue el '.$cuando.' y no tiene resultado', 0.5, $bloque),
                        default => $e('agendado', $b['corto'].' '.$cuando.$rep, 'blue', 'Programada', 3, $bloque),
                    };

                case 'por_definir':
                    return $e('pendiente_resultado', 'Definir resultado', 'amber', $b['nombre'].': asistió; falta marcar apto o no apto', 0.5, $bloque);

                case 'no_apto':
                    return $e('no_continua', 'No continúa', 'rose', $b['nombre'].': asistió, no apto', 9, $bloque);

                case 'no_asistio':
                    return $e('no_continua', 'No continúa', 'rose', $b['nombre'].': no asistió (debe quedar reprogramado para seguir)', 9, $bloque);
            }
        }

        return $e('listo_apto', 'Listo para marcar apto', 'green', 'Aprobó todo su recorrido', 0.8);
    }

    /**
     * Pasos para dibujar el recorrido: estado ok | actual | pendiente | no.
     *
     * @return array<int, array{clave: string, nombre: string, estado: string}>
     */
    public function pasos(CandidatoReclutamiento $c): array
    {
        $pasos = [['clave' => 'ent1', 'nombre' => 'Entrevista 1', 'estado' => $this->graduado($c) || $c->apto ? 'ok' : 'actual']];
        $hayActual = ! ($this->graduado($c) || $c->apto);

        foreach ($this->bloques($c) as $bloque) {
            $resultado = $this->resultado($c, $bloque);
            $estado = match (true) {
                $resultado === 'apto' => 'ok',
                in_array($resultado, ['no_apto', 'no_asistio'], true) => 'no',
                default => 'pendiente',
            };

            if ($estado === 'pendiente' && ! $hayActual) {
                $estado = 'actual';
                $hayActual = true;
            }

            $pasos[] = ['clave' => $bloque, 'nombre' => self::BLOQUES[$bloque]['nombre'], 'estado' => $estado];
        }

        $todoOk = collect($pasos)->every(fn ($p) => $p['estado'] === 'ok');
        $pasos[] = ['clave' => 'apto', 'nombre' => 'Apto', 'estado' => $c->apto ? 'ok' : ($todoOk ? 'actual' : 'pendiente')];

        // Un paso rechazado corta lo que sigue: nada posterior queda «pendiente» ni «actual».
        $cortado = false;
        foreach ($pasos as $i => $p) {
            if ($cortado && $p['estado'] !== 'ok') {
                $pasos[$i]['estado'] = 'pendiente';
            }
            $cortado = $cortado || $p['estado'] === 'no';
        }

        return $pasos;
    }

    // ---------------------------------------------------------------
    // Guardar un bloque (con las reglas de cascada)
    // ---------------------------------------------------------------

    private function fechaValida(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $texto)->toDateString() === $texto ? $texto : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{fecha?: ?string, hora?: ?string, asistio?: ?string, fecha_rep?: ?string, asistio_rep?: ?string, obs?: ?string, obs_rep?: ?string}  $datos
     * @return array{ok: bool, errores: array<string, string>}
     */
    public function guardarBloque(int $id, string $bloque, array $datos): array
    {
        $c = CandidatoReclutamiento::find($id);

        if (! $c || ! isset(self::BLOQUES[$bloque]) || ! in_array($bloque, $this->bloques($c), true)) {
            return ['ok' => false, 'errores' => ['general' => 'Este bloque no corresponde al recorrido del candidato.']];
        }

        if ($c->apto) {
            return ['ok' => false, 'errores' => ['general' => 'Está marcado apto: el administrador debe quitar el apto para editar.']];
        }

        if (! $this->habilitado($c, $bloque)) {
            return ['ok' => false, 'errores' => ['general' => 'Primero debe aprobar el paso anterior.']];
        }

        $b = self::BLOQUES[$bloque];
        $hoy = $this->hoy();
        $errores = [];

        $fecha = $this->fechaValida($datos['fecha'] ?? null);
        if (trim((string) ($datos['fecha'] ?? '')) !== '' && ! $fecha) {
            $errores['fecha'] = 'La fecha no es válida.';
        }

        $hora = trim((string) ($datos['hora'] ?? ''));
        if ($b['hora'] && $hora !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            $errores['hora'] = 'La hora no es válida.';
        }

        $asistio = $this->mayus($datos['asistio'] ?? '');
        if ($asistio !== '' && ! in_array($asistio, self::RESULTADOS, true)) {
            $errores['asistio'] = 'El resultado no es válido.';
        }

        $fechaRep = $this->fechaValida($datos['fecha_rep'] ?? null);
        if (trim((string) ($datos['fecha_rep'] ?? '')) !== '' && ! $fechaRep) {
            $errores['fecha_rep'] = 'La fecha no es válida.';
        }

        $asistioRep = $this->mayus($datos['asistio_rep'] ?? '');
        if ($asistioRep !== '' && ! in_array($asistioRep, self::RESULTADOS_REPROGRAMADA, true)) {
            $errores['asistio_rep'] = 'El resultado no es válido.';
        }

        $obs = mb_substr(trim((string) ($datos['obs'] ?? '')), 0, 1000);
        $obsRep = mb_substr(trim((string) ($datos['obs_rep'] ?? '')), 0, 1000);

        if ($errores) {
            return ['ok' => false, 'errores' => $errores];
        }

        // Cascada: sin fecha no hay resultado; solo «REPROGRAMADO» lleva reprogramación.
        if (! $fecha) {
            $asistio = '';
        }
        if ($asistio !== 'REPROGRAMADO') {
            $fechaRep = null;
            $asistioRep = '';
            $obsRep = '';
        } elseif (! $fechaRep) {
            $asistioRep = '';
        }

        if (! $fecha) {
            $hora = '';
        }

        // La asistencia se marca desde el día de la sesión; mientras tanto solo se puede reprogramar.
        if ($asistio !== '' && $asistio !== 'REPROGRAMADO' && $fecha > $hoy) {
            $errores['asistio'] = 'La asistencia se marca desde el día de la sesión ('.Carbon::parse($fecha)->format('d/m/Y').').';
        }
        if ($asistioRep !== '' && $fechaRep > $hoy) {
            $errores['asistio_rep'] = 'La asistencia a la reprogramada se marca desde su fecha ('.Carbon::parse($fechaRep)->format('d/m/Y').').';
        }

        if ($errores) {
            return ['ok' => false, 'errores' => $errores];
        }

        $cambios = [
            $b['fecha'] => $fecha,
            $b['asistio'] => $asistio !== '' ? $asistio : null,
            $b['fecha_rep'] => $fechaRep,
            $b['asistio_rep'] => $asistioRep !== '' ? $asistioRep : null,
            $b['obs'] => $obs !== '' ? $obs : null,
            $b['obs_rep'] => $obsRep !== '' ? $obsRep : null,
        ];

        if ($b['hora']) {
            $cambios[$b['hora']] = $hora !== '' ? $hora : null;
        }

        $c->update($cambios);

        // Si este bloque ya no está aprobado, lo que venía después se limpia.
        $c->refresh();
        $bloques = $this->bloques($c);
        $i = array_search($bloque, $bloques, true);

        if ($this->resultado($c, $bloque) !== 'apto') {
            $limpiar = [];
            foreach (array_slice($bloques, $i + 1) as $siguiente) {
                foreach (array_filter(Arr::only(self::BLOQUES[$siguiente], ['fecha', 'hora', 'asistio', 'fecha_rep', 'asistio_rep', 'obs', 'obs_rep'])) as $columna) {
                    $limpiar[$columna] = null;
                }
            }

            if ($limpiar) {
                $c->update($limpiar);
            }
        }

        return ['ok' => true, 'errores' => []];
    }

    // ---------------------------------------------------------------
    // Apto
    // ---------------------------------------------------------------

    /** @return array{ok: bool, mensaje: string} */
    public function marcarApto(int $id): array
    {
        $c = CandidatoReclutamiento::find($id);

        if (! $c) {
            return ['ok' => false, 'mensaje' => 'No se encontró al candidato.'];
        }

        if ($c->apto) {
            return ['ok' => false, 'mensaje' => 'Ya está marcado como apto.'];
        }

        if (! $this->puedeMarcarApto($c)) {
            return ['ok' => false, 'mensaje' => 'Solo se marca apto a quien aprobó todo su recorrido.'];
        }

        $c->update(['apto' => true, 'fecha_apto' => now()]);

        return ['ok' => true, 'mensaje' => $c->nombreCompleto().' quedó marcado como apto.'];
    }

    /** Solo el administrador desmarca a un apto. @return array{ok: bool, mensaje: string} */
    public function quitarApto(int $id, ?User $usuario): array
    {
        if (! $usuario?->esAdmin()) {
            return ['ok' => false, 'mensaje' => 'Solo el administrador puede quitar el apto.'];
        }

        $c = CandidatoReclutamiento::find($id);

        if (! $c || ! $c->apto) {
            return ['ok' => false, 'mensaje' => 'No está marcado como apto.'];
        }

        $c->update(['apto' => false, 'fecha_apto' => null]);

        return ['ok' => true, 'mensaje' => 'Se quitó el apto de '.$c->nombreCompleto().'.'];
    }

    // ---------------------------------------------------------------
    // Agendar a varios y asistencia por fecha
    // ---------------------------------------------------------------

    /**
     * Ponle la misma fecha (y hora) a varios candidatos que tienen ese bloque habilitado y sin agendar.
     *
     * @param  array<int, int>  $ids
     * @return array{agendados: int, omitidos: array<int, string>}
     */
    public function agendarVarios(array $ids, string $bloque, string $fecha, ?string $hora = null): array
    {
        $fecha = $this->fechaValida($fecha);
        $hora = trim((string) $hora);
        $agendados = 0;
        $omitidos = [];

        if (! $fecha || ! isset(self::BLOQUES[$bloque])) {
            return ['agendados' => 0, 'omitidos' => ['La fecha o el paso no son válidos.']];
        }

        foreach (CandidatoReclutamiento::whereIn('id', $ids)->get() as $c) {
            $nombre = $c->nombreCompleto() ?: 'Sin nombre';

            if (! in_array($bloque, $this->bloques($c), true)) {
                $omitidos[] = "{$nombre}: ese paso no es de su recorrido.";
            } elseif ($c->apto) {
                $omitidos[] = "{$nombre}: ya está apto.";
            } elseif (! $this->habilitado($c, $bloque)) {
                $omitidos[] = "{$nombre}: aún no aprobó el paso anterior.";
            } elseif ($this->resultado($c, $bloque) !== 'sin_agendar') {
                $omitidos[] = "{$nombre}: ya tiene fecha en ese paso.";
            } else {
                $b = self::BLOQUES[$bloque];
                $cambios = [$b['fecha'] => $fecha];
                if ($b['hora'] && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                    $cambios[$b['hora']] = $hora;
                }
                $c->update($cambios);
                $agendados++;
            }
        }

        return ['agendados' => $agendados, 'omitidos' => $omitidos];
    }

    /**
     * Quienes asisten a una fecha: bloque con esa fecha (o su reprogramada), con el resultado que llevan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function asistenciaPorFecha(string $fecha): Collection
    {
        $fecha = $this->fechaValida($fecha);

        if (! $fecha) {
            return collect();
        }

        $columnas = [];
        foreach (self::BLOQUES as $b) {
            $columnas[] = $b['fecha'];
            $columnas[] = $b['fecha_rep'];
        }

        $entradas = collect();

        CandidatoReclutamiento::query()
            ->where(function ($q) use ($columnas, $fecha) {
                foreach ($columnas as $columna) {
                    $q->orWhereDate($columna, $fecha);
                }
            })
            ->orderBy('apellidos')->orderBy('nombres')
            ->get()
            ->each(function (CandidatoReclutamiento $c) use ($fecha, &$entradas) {
                foreach ($this->bloques($c) as $bloque) {
                    $v = $this->valores($c, $bloque);
                    $b = self::BLOQUES[$bloque];
                    $esOriginal = $v['fecha']?->toDateString() === $fecha && $v['asistio'] !== 'REPROGRAMADO';
                    $esReprogramada = $v['asistio'] === 'REPROGRAMADO' && $v['fecha_rep']?->toDateString() === $fecha;
                    $movida = $v['fecha']?->toDateString() === $fecha && $v['asistio'] === 'REPROGRAMADO';

                    if (! $esOriginal && ! $esReprogramada && ! $movida) {
                        continue;
                    }

                    $entradas->push([
                        'id' => $c->id,
                        'clave' => $c->id.'|'.$bloque.'|'.($esReprogramada ? 'rep' : 'orig'),
                        'candidato' => $c,
                        'bloque' => $bloque,
                        'bloque_nombre' => $b['nombre'],
                        'tipo' => $esReprogramada ? 'rep' : 'orig',
                        'movida' => $movida,
                        'hora' => $v['hora'],
                        'resultado' => $esReprogramada ? $v['asistio_rep'] : ($movida ? 'REPROGRAMADO' : $v['asistio']),
                        'habilitado' => $this->habilitado($c, $bloque) && ! $c->apto,
                    ]);
                }
            });

        return $entradas->values();
    }

    /**
     * Marca el resultado de una asistencia (desde la lista por fecha), con las mismas reglas que el panel del candidato.
     *
     * @return array{ok: bool, errores: array<string, string>}
     */
    public function marcarAsistencia(int $id, string $bloque, string $tipo, string $resultado, ?string $fechaNueva = null): array
    {
        $c = CandidatoReclutamiento::find($id);

        if (! $c || ! isset(self::BLOQUES[$bloque])) {
            return ['ok' => false, 'errores' => ['general' => 'No se encontró el registro.']];
        }

        $v = $this->valores($c, $bloque);
        $datos = [
            'fecha' => $v['fecha']?->toDateString(), 'hora' => $v['hora'], 'asistio' => $v['asistio'],
            'fecha_rep' => $v['fecha_rep']?->toDateString(), 'asistio_rep' => $v['asistio_rep'],
            'obs' => $v['obs'], 'obs_rep' => $v['obs_rep'],
        ];

        if ($tipo === 'rep') {
            $datos['asistio_rep'] = $resultado;
        } else {
            $datos['asistio'] = $resultado;
            if ($resultado === 'REPROGRAMADO') {
                $datos['fecha_rep'] = $fechaNueva ?: $datos['fecha_rep'];
            }
        }

        $respuesta = $this->guardarBloque($id, $bloque, $datos);

        // Los errores de la lista se muestran en un solo mensaje.
        if (! $respuesta['ok'] && isset($respuesta['errores']['asistio_rep']) && $tipo === 'orig') {
            $respuesta['errores']['asistio'] = $respuesta['errores']['asistio_rep'];
        }

        return $respuesta;
    }

    // ---------------------------------------------------------------
    // Ficha de ingreso
    // ---------------------------------------------------------------

    /** Se puede mandar la ficha desde que se agenda el primer paso posterior a la entrevista 1. */
    public function puedeEnviarFicha(CandidatoReclutamiento $c): bool
    {
        $primero = $this->bloques($c)[0] ?? null;

        return $c->apto || ($primero && $c->{self::BLOQUES[$primero]['fecha']});
    }

    /**
     * Estado de la ficha: sin_enviar | en_cola | enviado | error | llenada | vencida.
     *
     * @return array{estado: string, correo: ?string, enviado_at: ?Carbon, error: ?string}
     */
    public function ficha(CandidatoReclutamiento $c): array
    {
        $inv = OnboardingInvitation::principalDe($c->id);

        if (! $inv) {
            return ['estado' => 'sin_enviar', 'correo' => $c->email, 'enviado_at' => null, 'error' => null];
        }

        $estado = match (true) {
            (bool) ($inv->empleado || $inv->usado_en) => 'llenada',
            $inv->estadoCorreo() === 'error' => 'error',
            $inv->estadoCorreo() === 'enviado' => $inv->expira_en->isPast() ? 'vencida' : 'enviado',
            $inv->estadoCorreo() === 'en-cola' => 'en_cola',
            default => 'sin_enviar',
        };

        return ['estado' => $estado, 'correo' => $inv->correo_a ?: $c->email, 'enviado_at' => $inv->correo_enviado_at, 'error' => $inv->correo_error];
    }
}
