<?php

use App\Concerns\ConDetalleModal;
use App\Concerns\ConPeriodoDiarioMensual;
use App\Models\CandidatoEvento;
use App\Models\CandidatoReclutamiento;
use App\Services\FichaCompleta;
use App\Support\GrupoCampana;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel', ['title' => 'Control GTH'])] class extends Component
{
    use ConDetalleModal, ConPeriodoDiarioMensual;

    /** Entrevista y capacitaciones: columnas de fecha y asistencia (original y reprogramada) de cada una. */
    public const ACTIVIDADES = [
        'entrevista' => ['nombre' => 'Entrevista', 'fecha' => 'fecha_entrevista', 'asistio' => 'asistio_entrevista', 'fecha_rep' => 'fecha_reprogramada', 'asistio_rep' => 'asistio_entrevista_reprogramada'],
        'cap1' => ['nombre' => 'Capacitación 1', 'fecha' => 'capacitacion_1', 'asistio' => 'asistio_cap_1', 'fecha_rep' => 'capacitacion_1_reprogramada', 'asistio_rep' => 'asistio_cap_1_reprogramada'],
        'cap2' => ['nombre' => 'Capacitación 2', 'fecha' => 'capacitacion_2', 'asistio' => 'asistio_cap_2', 'fecha_rep' => 'capacitacion_2_reprogramada', 'asistio_rep' => 'asistio_cap_2_reprogramada'],
    ];

    public const ESTADOS = [
        'asistio' => ['Asistió', 'green'],
        'no_apto' => ['Asistió · no apto', 'rose'],
        'falto' => ['No asistió', 'rose'],
        'reprogramado' => ['Reprogramado', 'amber'],
        'sin_registrar' => ['Sin registrar', 'amber'],
        'por_venir' => ['Programado', 'slate'],
    ];

    /** Pasos del embudo de gestión (clave => etiqueta). */
    public const PASOS = [
        'gestionados' => 'Gestionados',
        'contactados' => 'Contactados (respondieron)',
        'interesados' => 'Interesados y aptos al filtro',
        'aceptaron' => 'Aceptaron la entrevista',
        'asistio_entrevista' => 'Asistieron a la entrevista',
        'asistio_cap' => 'Asistieron a capacitación',
        'aptos' => 'Aptos para ingresar',
    ];

    private const RECHAZO = ['NO INTERESADO', 'NO CALIFICA', 'NO PASA FILTRO'];


    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->iniciarPeriodo();
    }

    protected function primeraFecha(): ?string
    {
        return CandidatoReclutamiento::whereNotNull('fecha_gestion')->min('fecha_gestion');
    }













    private function candidatosGestionados(array $rango): Collection
    {
        return CandidatoReclutamiento::query()
            ->whereBetween('fecha_gestion', [$rango[0]->toDateString(), $rango[1]->toDateString()])
            ->get(['id', 'fecha_gestion', 'agente_reclutador', 'campana', 'puesto', 'tipificacion', 'subtipificacion_rechazo', 'aceptacion_entrevista', 'asistio_entrevista', 'asistio_entrevista_reprogramada', 'asistio_cap_1', 'asistio_cap_1_reprogramada', 'apto']);
    }

    /**
     * Gestiones del período: cada llamada registrada en el historial del candidato (puede haber varias por persona y por día).
     *
     * @return Collection<int, array{candidato_id: int, cuando: Carbon, resultado: string, usuario: string, grupo: string, tipo: string}>
     */
    private function llamadas(array $rango): Collection
    {
        return CandidatoEvento::query()
            ->with(['usuario:id,name', 'candidato:id,campana,puesto'])
            ->where('tipo', 'llamada')
            ->whereBetween('created_at', $this->rangoUtc($rango))
            ->orderBy('created_at')
            ->get()
            ->filter(fn (CandidatoEvento $e) => $e->candidato)
            ->map(function (CandidatoEvento $e) {
                $grupo = GrupoCampana::de($e->candidato->campana, $e->candidato->puesto);

                return [
                    'candidato_id' => $e->reclutamiento_candidato_id,
                    'cuando' => $e->created_at->copy()->timezone('America/Lima'),
                    'resultado' => (string) $e->valor_nuevo,
                    'usuario' => trim((string) $e->usuario?->name) ?: 'Sin asignar',
                    'grupo' => $grupo['nombre'],
                    'tipo' => $grupo['tipo'],
                ];
            })
            ->values();
    }

    private function normalizarNombre(string $nombre): string
    {
        return Str::upper(Str::ascii(trim($nombre)));
    }

    private function tipificacion(CandidatoReclutamiento $candidato): string
    {
        return mb_strtoupper(trim((string) $candidato->tipificacion));
    }

    private function grupoCampana(CandidatoReclutamiento $c): array
    {
        return GrupoCampana::de($c->campana, $c->puesto);
    }

    /** Si el candidato llegó a ese paso del embudo (misma regla para las cifras y para su detalle). */
    private function cumplePaso(string $paso, CandidatoReclutamiento $c): bool
    {
        $asistio = fn (?string $valor) => str_starts_with((string) $valor, 'SI');
        $interesado = $this->tipificacion($c) === 'INTERESADO - APTO';

        return match ($paso) {
            'gestionados' => true,
            'contactados' => $interesado || in_array($this->tipificacion($c), self::RECHAZO, true),
            'interesados' => $interesado,
            'aceptaron' => $c->aceptacion_entrevista === 'SI',
            'asistio_entrevista' => $asistio($c->asistio_entrevista) || $asistio($c->asistio_entrevista_reprogramada),
            'asistio_cap' => $asistio($c->asistio_cap_1) || $asistio($c->asistio_cap_1_reprogramada),
            'aptos' => $c->apto === true,
            default => false,
        };
    }

    /** Gestión de candidatos del período: totales, embudo, tipificación, motivos y desglose por reclutador. */
    private function gestion(array $rangos): array
    {
        $candidatos = $this->candidatosGestionados($rangos['actual']);
        $anterior = $rangos['anterior']
            ? CandidatoReclutamiento::whereBetween('fecha_gestion', [$rangos['anterior'][0]->toDateString(), $rangos['anterior'][1]->toDateString()])->count()
            : null;

        $llamadas = $this->llamadas($rangos['actual']);
        $llamadasAnterior = $rangos['anterior'] ? $this->llamadas($rangos['anterior'])->count() : null;

        $pasos = [];
        foreach (self::PASOS as $clave => $nombre) {
            $pasos[] = ['clave' => $clave, 'nombre' => $nombre, 'cantidad' => $candidatos->filter(fn ($c) => $this->cumplePaso($clave, $c))->count()];
        }

        $base = max($pasos[0]['cantidad'], 1);
        foreach ($pasos as $i => $paso) {
            $pasos[$i]['porcentaje'] = (int) round($paso['cantidad'] / $base * 100);
            $pasos[$i]['conversion'] = $i > 0 && $pasos[$i - 1]['cantidad'] > 0 ? (int) round($paso['cantidad'] / $pasos[$i - 1]['cantidad'] * 100) : null;
        }

        $tipificaciones = $candidatos->groupBy(fn ($c) => $this->tipificacion($c) ?: 'SIN TIPIFICAR')->map->count()->sortDesc();

        $motivos = $candidatos
            ->map(fn ($c) => mb_strtoupper(trim((string) $c->subtipificacion_rechazo)))
            ->filter()->countBy()->sortDesc()->take(6);

        $reclutadores = $candidatos
            ->groupBy(fn ($c) => trim((string) $c->agente_reclutador) ?: 'Sin asignar')
            ->map(fn (Collection $grupo, string $nombre) => [
                'nombre' => $nombre,
                'gestionados' => $grupo->count(),
                'contactados' => $grupo->filter(fn ($c) => $this->cumplePaso('contactados', $c))->count(),
                'interesados' => $grupo->filter(fn ($c) => $this->cumplePaso('interesados', $c))->count(),
                'aceptaron' => $grupo->filter(fn ($c) => $this->cumplePaso('aceptaron', $c))->count(),
                'tasa' => (int) round($grupo->filter(fn ($c) => $this->cumplePaso('interesados', $c))->count() / max($grupo->count(), 1) * 100),
            ])
            ->all();

        // A cada llamada se le pone el nombre del reclutador de la tabla (sin importar tildes); quien no está en ella aparece con su nombre.
        $nombresFila = collect($reclutadores)->mapWithKeys(fn ($fila) => [$this->normalizarNombre($fila['nombre']) => $fila['nombre']]);
        $llamadas = $llamadas->map(fn ($l) => $l + ['reclutador' => $nombresFila->get($this->normalizarNombre($l['usuario']), mb_strtoupper($l['usuario']))]);

        $porReclutador = $llamadas->countBy('reclutador');
        $reclutadores = collect($reclutadores)->map(fn ($fila) => $fila + ['llamadas' => $porReclutador->get($fila['nombre'], 0)]);

        foreach ($porReclutador as $nombre => $cantidad) {
            if (! $reclutadores->contains('nombre', $nombre)) {
                $reclutadores->push(['nombre' => $nombre, 'gestionados' => 0, 'contactados' => 0, 'interesados' => 0, 'aceptaron' => 0, 'tasa' => 0, 'llamadas' => $cantidad]);
            }
        }

        $reclutadores = $reclutadores->sortBy([fn ($a, $b) => $b['gestionados'] <=> $a['gestionados'], fn ($a, $b) => $b['llamadas'] <=> $a['llamadas']])->values()->all();

        $campanas = $candidatos
            ->groupBy(fn ($c) => $this->grupoCampana($c)['nombre'])
            ->map(function (Collection $grupo, string $nombre) {
                $fila = ['nombre' => $nombre, 'tipo' => $this->grupoCampana($grupo->first())['tipo']];

                foreach (array_keys(self::PASOS) as $paso) {
                    $fila[$paso] = $grupo->filter(fn ($c) => $this->cumplePaso($paso, $c))->count();
                }

                $fila['tasa'] = (int) round($fila['interesados'] / max($fila['gestionados'], 1) * 100);

                return $fila;
            });

        $llamadasPorGrupo = $llamadas->countBy('grupo');
        $campanas = $campanas->map(fn ($fila) => $fila + ['llamadas' => $llamadasPorGrupo->get($fila['nombre'], 0)]);

        foreach ($llamadasPorGrupo as $nombre => $cantidad) {
            if (! $campanas->contains('nombre', $nombre)) {
                $campanas->push(['nombre' => $nombre, 'tipo' => $llamadas->firstWhere('grupo', $nombre)['tipo'], 'tasa' => 0, 'llamadas' => $cantidad] + array_fill_keys(array_keys(self::PASOS), 0));
            }
        }

        $campanas = $campanas
            ->sort(fn ($a, $b) => [$a['tipo'] === 'C' ? 0 : 1, -$a['gestionados'], $a['nombre']] <=> [$b['tipo'] === 'C' ? 0 : 1, -$b['gestionados'], $b['nombre']])
            ->values()->all();

        return [
            'candidatos' => $candidatos,
            'campanas' => $campanas,
            'total' => $candidatos->count(),
            'anterior' => $anterior,
            'llamadas' => $llamadas,
            'totalLlamadas' => $llamadas->count(),
            'personasLlamadas' => $llamadas->pluck('candidato_id')->unique()->count(),
            'llamadasAnterior' => $llamadasAnterior,
            'desdeLlamadas' => CandidatoEvento::min('created_at'),
            'pasos' => $pasos,
            'tipificaciones' => $tipificaciones,
            'maxTipificacion' => max($tipificaciones->max() ?? 1, 1),
            'motivos' => $motivos,
            'maxMotivo' => max($motivos->max() ?? 1, 1),
            'reclutadores' => $reclutadores,
        ];
    }

    private function estadoAsistencia(?string $valor, Carbon $fecha, Carbon $hoy): string
    {
        $valor = mb_strtoupper(trim((string) $valor));

        return match (true) {
            str_contains($valor, 'NO APTO') => 'no_apto',
            str_starts_with($valor, 'SI') => 'asistio',
            $valor === 'NO' => 'falto',
            $valor === 'REPROGRAMADO' => 'reprogramado',
            $fecha->lte($hoy) => 'sin_registrar',
            default => 'por_venir',
        };
    }

    /**
     * Citas de entrevista y capacitación que caen en el período (cada fecha original o reprogramada es una cita).
     *
     * @return Collection<int, array>
     */
    private function citas(array $rango): Collection
    {
        $hoy = $this->hoy()->startOfDay();
        $columnas = collect(self::ACTIVIDADES)->flatMap(fn ($a) => [$a['fecha'], $a['fecha_rep']])->all();
        $desde = $rango[0]->toDateString();
        $hasta = $rango[1]->toDateString();

        $candidatos = CandidatoReclutamiento::query()
            ->where(function ($q) use ($columnas, $desde, $hasta) {
                foreach ($columnas as $columna) {
                    $q->orWhereBetween($columna, [$desde, $hasta]);
                }
            })
            ->get(array_merge(['id', 'nombres', 'apellidos', 'agente_reclutador', 'puesto', 'campana', 'hora_entrevista'], $columnas, collect(self::ACTIVIDADES)->flatMap(fn ($a) => [$a['asistio'], $a['asistio_rep']])->all()));

        $citas = collect();

        foreach ($candidatos as $candidato) {
            foreach (self::ACTIVIDADES as $clave => $actividad) {
                foreach ([[$actividad['fecha'], $actividad['asistio'], false], [$actividad['fecha_rep'], $actividad['asistio_rep'], true]] as [$campoFecha, $campoAsistio, $reprogramada]) {
                    $fecha = $candidato->{$campoFecha};

                    if (! $fecha || $fecha->toDateString() < $desde || $fecha->toDateString() > $hasta) {
                        continue;
                    }

                    $citas->push([
                        'candidato_id' => $candidato->id,
                        'actividad' => $clave,
                        'fecha' => $fecha,
                        'estado' => $this->estadoAsistencia($candidato->{$campoAsistio}, $fecha, $hoy),
                        'nombre' => $candidato->nombreCorto(),
                        'detalle' => collect([$candidato->campana, $candidato->puesto])->first(fn ($v) => $v && ! in_array($v, ['-', 'NO APLICA'], true)),
                        'reclutador' => $candidato->agente_reclutador,
                        'hora' => $clave === 'entrevista' && $candidato->hora_entrevista ? substr((string) $candidato->hora_entrevista, 0, 5) : null,
                        'reprogramada' => $reprogramada,
                    ]);
                }
            }
        }

        return $citas;
    }

    /** Resumen de asistencia por actividad. */
    private function resumenCitas(Collection $citas): array
    {
        $resumen = [];

        foreach (self::ACTIVIDADES as $clave => $actividad) {
            $propias = $citas->where('actividad', $clave);
            $cuenta = fn (string ...$estados) => $propias->whereIn('estado', $estados)->count();

            $asistieron = $cuenta('asistio', 'no_apto');
            $faltaron = $cuenta('falto');

            $resumen[$clave] = [
                'nombre' => $actividad['nombre'],
                'programadas' => $propias->count(),
                'asistieron' => $asistieron,
                'porcentaje' => $asistieron + $faltaron > 0 ? (int) round($asistieron / ($asistieron + $faltaron) * 100) : null,
                'filas' => [
                    ['No asistieron', $faltaron, $faltaron > 0 ? 'text-red-600' : 'text-slate-700', 'falto'],
                    ['Reprogramados', $cuenta('reprogramado'), 'text-slate-700', 'reprogramado'],
                    ['No aptos', $cuenta('no_apto'), $cuenta('no_apto') > 0 ? 'text-red-600' : 'text-slate-700', 'no_apto'],
                    ['Sin registrar asistencia', $cuenta('sin_registrar'), $cuenta('sin_registrar') > 0 ? 'text-amber-600' : 'text-slate-700', 'sin_registrar'],
                    ['Por venir', $cuenta('por_venir'), 'text-slate-700', 'por_venir'],
                ],
                'no_aptos' => $cuenta('no_apto'),
            ];
        }

        return $resumen;
    }

    /** Tendencia de la gestión y la asistencia: día por día del mes elegido (Mensual) o mes por mes (Total). */
    private function tendencia(array $rango, Collection $candidatos, Collection $citas): array
    {
        $clave = fn ($fecha) => $this->claveTendencia($fecha);
        $gestionados = $candidatos->countBy(fn ($c) => $clave($c->fecha_gestion));
        $aceptaron = $candidatos->where('aceptacion_entrevista', 'SI')->countBy(fn ($c) => $clave($c->fecha_gestion));
        $asistieron = fn (array $actividades) => $citas->whereIn('actividad', $actividades)->whereIn('estado', ['asistio', 'no_apto'])->countBy(fn ($c) => $clave($c['fecha']));
        $entrevista = $asistieron(['entrevista']);
        $capacitacion = $asistieron(['cap1', 'cap2']);

        $gestion = [];
        $asistencia = [];

        foreach ($this->columnasTendencia($rango) as $columna) {
            $base = ['etiqueta' => $columna['etiqueta'], 'titulo' => $columna['titulo']];
            $gestion[] = $base + ['a' => $gestionados->get($columna['clave'], 0), 'b' => $aceptaron->get($columna['clave'], 0)];
            $asistencia[] = $base + ['a' => $entrevista->get($columna['clave'], 0), 'b' => $capacitacion->get($columna['clave'], 0)];
        }

        $armar = fn (array $columnas) => [
            'columnas' => $columnas,
            'maximo' => max(collect($columnas)->flatMap(fn ($c) => [$c['a'], $c['b']])->max(), 1),
            'unidad' => $this->unidadTendencia(),
        ];

        return ['gestion' => $armar($gestion), 'asistencia' => $armar($asistencia)];
    }

    protected function contexto(): array
    {
        $rangos = $this->rangos();
        $citas = $this->citas($rangos['actual']);

        return [
            'rangos' => $rangos,
            'gestion' => $this->gestion($rangos),
            'citas' => $citas,
            'resumen' => $this->resumenCitas($citas),
        ];
    }

    /**
     * Registros detrás de una cifra. La clave viene de la pantalla: «tipo|a|b» (paso, tip, mot, rec, cita, noaptos, aptos).
     * Solo se comparan valores en PHP (nada de la clave llega a una consulta), y lo que no es reconocido devuelve null.
     *
     * @return array{titulo: string, filas: array<int, array>}|null
     */
    protected function resolverDetalle(string $clave, array $ctx): ?array
    {
        [$tipo, $a, $b] = array_pad(explode('|', $clave, 3), 3, null);

        $candidatos = $ctx['gestion']['candidatos'];
        $deCandidatos = fn (Collection $lista) => $lista->map(fn ($c) => ['id' => $c->id, 'nota' => null, 'estado' => null])->values()->all();
        $deCitas = fn (Collection $lista) => $lista->map(fn ($cita) => [
            'id' => $cita['candidato_id'],
            'nota' => self::ACTIVIDADES[$cita['actividad']]['nombre'].' · '.$cita['fecha']->format('d/m/Y').($cita['hora'] ? ' · '.$cita['hora'] : '').($cita['reprogramada'] ? ' · reprogramada' : ''),
            'estado' => self::ESTADOS[$cita['estado']],
        ])->values()->all();

        $nombreReclutador = fn ($c) => trim((string) $c->agente_reclutador) ?: 'Sin asignar';

        return match (true) {
            $tipo === 'paso' && isset(self::PASOS[$a]) => [
                'titulo' => self::PASOS[$a],
                'filas' => $deCandidatos($candidatos->filter(fn ($c) => $this->cumplePaso($a, $c))),
            ],
            $tipo === 'tip' && $a !== null => [
                'titulo' => 'Tipificación: '.$a,
                'filas' => $deCandidatos($candidatos->filter(fn ($c) => ($this->tipificacion($c) ?: 'SIN TIPIFICAR') === $a)),
            ],
            $tipo === 'mot' && $a !== null => [
                'titulo' => 'Motivo de rechazo: '.$a,
                'filas' => $deCandidatos($candidatos->filter(fn ($c) => mb_strtoupper(trim((string) $c->subtipificacion_rechazo)) === $a)),
            ],
            $tipo === 'rec' && $a !== null && isset(self::PASOS[$b]) => [
                'titulo' => $a.' · '.self::PASOS[$b],
                'filas' => $deCandidatos($candidatos->filter(fn ($c) => $nombreReclutador($c) === $a && $this->cumplePaso($b, $c))),
            ],
            $tipo === 'camp' && $a !== null && isset(self::PASOS[$b]) => [
                'titulo' => $a.' · '.self::PASOS[$b],
                'filas' => $deCandidatos($candidatos->filter(fn ($c) => $this->grupoCampana($c)['nombre'] === $a && $this->cumplePaso($b, $c))),
            ],
            $tipo === 'cita' && isset(self::ACTIVIDADES[$a]) && ($b === 'todas' || $b === 'asistieron' || isset(self::ESTADOS[$b])) => [
                'titulo' => self::ACTIVIDADES[$a]['nombre'].' · '.match ($b) {
                    'todas' => 'programadas',
                    'asistieron' => 'asistieron',
                    default => mb_strtolower(self::ESTADOS[$b][0]),
                },
                'filas' => $deCitas($ctx['citas']->where('actividad', $a)->filter(fn ($cita) => match ($b) {
                    'todas' => true,
                    'asistieron' => in_array($cita['estado'], ['asistio', 'no_apto'], true),
                    default => $cita['estado'] === $b,
                })),
            ],
            $tipo === 'llam' && in_array($a, ['todas', 'camp', 'rec'], true) && ($a === 'todas' || $b !== null) => [
                'titulo' => 'Gestiones (llamadas)'.($a === 'todas' ? '' : ' · '.$b),
                'filas' => $ctx['gestion']['llamadas']
                    ->filter(fn ($l) => match ($a) {
                        'camp' => $l['grupo'] === $b,
                        'rec' => $l['reclutador'] === $b,
                        default => true,
                    })
                    ->map(fn ($l) => ['id' => $l['candidato_id'], 'nota' => 'Llamada · '.$l['cuando']->format('d/m/Y H:i').' · '.$l['resultado'].' · '.$l['usuario'], 'estado' => null])
                    ->values()->all(),
            ],
            $tipo === 'cand' && $a !== null && ctype_digit($a) => [
                'titulo' => 'Ficha del candidato',
                'filas' => [['id' => (int) $a, 'nota' => null, 'estado' => null]],
            ],
            $tipo === 'noaptos' => [
                'titulo' => 'No aptos en entrevista o capacitación',
                'filas' => $deCitas($ctx['citas']->where('estado', 'no_apto')),
            ],
            $tipo === 'aptos' => [
                'titulo' => 'Aptos para ingresar',
                'filas' => $deCandidatos(CandidatoReclutamiento::where('apto', true)
                    ->whereBetween('fecha_apto', $this->rangoUtc($ctx['rangos']['actual']))
                    ->get(['id'])),
            ],
            default => null,
        };
    }


    /** Filas de la ventana de detalle: datos de cada candidato y, solo al abrirla, su ficha completa. */
    private function hidratarFilas(array $filas): Collection
    {
        $modelos = CandidatoReclutamiento::whereIn('id', collect($filas)->pluck('id')->unique())->get()->keyBy('id');

        return collect($filas)
            ->filter(fn ($fila) => $modelos->has($fila['id']))
            ->map(function ($fila) use ($modelos) {
                $c = $modelos[$fila['id']];

                return [
                    'nombre' => $c->nombreCompleto() ?: '(sin nombre)',
                    'chips' => [
                        'DNI '.($c->dni_ce ?: '—'),
                        'Cel. '.($c->numero_celular ?: '—'),
                        collect([$c->campana, $c->puesto])->first(fn ($v) => $v && ! in_array($v, ['-', 'NO APLICA'], true)) ?: '—',
                        'Reclutador: '.($c->agente_reclutador ?: '—'),
                    ],
                    'nota' => $fila['nota'],
                    'badge' => $fila['estado'] ?? ($c->tipificacion ? [$c->tipificacion, 'slate'] : null),
                    'ficha' => fn () => FichaCompleta::candidato($c),
                ];
            })
            ->values();
    }

    public function datos(): array
    {
        $ctx = $this->contexto();
        $rangos = $ctx['rangos'];
        $gestion = $ctx['gestion'];
        $citas = $ctx['citas'];
        $resumen = $ctx['resumen'];

        $aptos = CandidatoReclutamiento::where('apto', true)->whereBetween('fecha_apto', $this->rangoUtc($rangos['actual']))->count();

        $resuelto = $this->detalle !== null ? $this->resolverDetalle($this->detalle, $ctx) : null;

        return [
            'hoy' => $this->hoy(),
            'vista' => $this->vista,
            'diario' => $this->vista === 'diario',
            'periodo' => $this->etiquetaPeriodo($rangos['actual']),
            'comparativo' => $this->vista === 'diario' ? 'el día anterior' : 'el mes anterior',
            'unidad' => $this->unidadTendencia(),
            'gestion' => $gestion,
            'resumen' => $resumen,
            'aptos' => $aptos,
            'noAptos' => collect($resumen)->sum('no_aptos'),
            'agenda' => $citas->sortBy(fn ($c) => array_search($c['actividad'], array_keys(self::ACTIVIDADES), true).($c['hora'] ?? '99:99').$c['nombre'])->values()->all(),
            'tendencia' => $this->vista !== 'diario' ? $this->tendencia($rangos['actual'], $gestion['candidatos'], $citas) : null,
            'detalle' => $resuelto ? $this->armarFilasDetalle($resuelto['titulo'], $this->hidratarFilas($resuelto['filas'])) : null,
        ];
    }
};
?>

<div class="space-y-5">
    @php($d = $this->datos())
    @php($ges = $d['gestion'])
    @php($dif = $ges['anterior'] === null ? null : $ges['total'] - $ges['anterior'])
    @php($det = $d['detalle'])
    {{-- Se pausa la actualización automática mientras hay un detalle abierto. --}}
    @if (! $det)
        <div wire:poll.60s class="hidden" aria-hidden="true"></div>
    @endif

    @include('partials.control-gth.selector-periodo')

    {{-- Gestión de candidatos --}}
    <section class="space-y-3">
        <h2 class="form-section-title">Gestión de candidatos</h2>

        <div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
            <button type="button" wire:click="abrirDetalle('paso|gestionados')" class="stat-tile gth-click">
                <span class="stat-tile-value block">{{ $ges['total'] }}</span>
                <span class="stat-tile-label block">Candidatos gestionados</span>
                @if ($dif !== null)
                    <span class="mt-1 block text-xs {{ $dif > 0 ? 'text-emerald-600' : ($dif < 0 ? 'text-red-600' : 'text-slate-400') }}">
                        {{ $dif > 0 ? '▲ +'.$dif : ($dif < 0 ? '▼ '.$dif : '= sin cambio') }} vs {{ $d['comparativo'] }} ({{ $ges['anterior'] }})
                    </span>
                @else
                    <span class="mt-1 block text-xs text-slate-400">de todo el historial</span>
                @endif
            </button>
            @php($difLlamadas = $ges['llamadasAnterior'] === null ? null : $ges['totalLlamadas'] - $ges['llamadasAnterior'])
            <button type="button" wire:click="abrirDetalle('llam|todas')" class="stat-tile gth-click">
                <span class="stat-tile-value block">{{ $ges['totalLlamadas'] }}</span>
                <span class="stat-tile-label block">Gestiones (llamadas)</span>
                <span class="mt-1 block text-xs text-slate-400">
                    a {{ $ges['personasLlamadas'] }} {{ $ges['personasLlamadas'] === 1 ? 'persona' : 'personas' }}
                    @if ($difLlamadas !== null && $ges['llamadasAnterior'] > 0)
                        · {{ $difLlamadas > 0 ? '▲ +'.$difLlamadas : ($difLlamadas < 0 ? '▼ '.$difLlamadas : '= sin cambio') }} vs {{ $d['comparativo'] }}
                    @endif
                </span>
            </button>
            @foreach ([1 => 'Contactados', 2 => 'Interesados y aptos al filtro', 3 => 'Aceptaron la entrevista'] as $indice => $etiqueta)
                <button type="button" wire:key="tile-{{ $indice }}" wire:click="abrirDetalle('paso|{{ $ges['pasos'][$indice]['clave'] }}')" class="stat-tile gth-click">
                    <span class="stat-tile-value block">{{ $ges['pasos'][$indice]['cantidad'] }}</span>
                    <span class="stat-tile-label block">{{ $etiqueta }}</span>
                    <span class="mt-1 block text-xs text-slate-400">{{ $ges['pasos'][$indice]['porcentaje'] }}% de los gestionados</span>
                </button>
            @endforeach
        </div>

        @if (! $d['diario'])
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="card-panel-compact">
                    <h3 class="text-base font-semibold text-slate-900">Gestión por {{ $d['unidad'] }}</h3>
                    <p class="mb-3 text-xs text-slate-400">Candidatos gestionados y los que aceptaron la entrevista</p>
                    @include('partials.control-gth.columnas', ['grafico' => $d['tendencia']['gestion'], 'nombreA' => 'Gestionados', 'nombreB' => 'Aceptaron entrevista'])
                </section>
                <section class="card-panel-compact">
                    <h3 class="text-base font-semibold text-slate-900">Asistencia por {{ $d['unidad'] }}</h3>
                    <p class="mb-3 text-xs text-slate-400">Personas que asistieron a entrevista y a capacitación (1 y 2)</p>
                    @include('partials.control-gth.columnas', ['grafico' => $d['tendencia']['asistencia'], 'nombreA' => 'Entrevista', 'nombreB' => 'Capacitación'])
                </section>
            </div>
        @endif

        <div class="grid gap-5 xl:grid-cols-3">
            <section class="card-panel-compact xl:col-span-2">
                <h3 class="text-base font-semibold text-slate-900">Embudo de reclutamiento</h3>
                <p class="mb-4 text-xs text-slate-400">De los candidatos gestionados en el período, hasta dónde llegaron. El % grande se mide contra los gestionados y el chico contra el paso anterior.</p>

                <div class="space-y-1.5">
                    @foreach ($ges['pasos'] as $i => $paso)
                        <button type="button" wire:key="embudo-{{ $i }}" wire:click="abrirDetalle('paso|{{ $paso['clave'] }}')" class="gth-click-fila" title="Ver los {{ $paso['cantidad'] }} registros: {{ $paso['nombre'] }}">
                            <span class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                                <span class="text-slate-600">{{ $paso['nombre'] }}</span>
                                <span class="whitespace-nowrap">
                                    <span class="font-semibold text-slate-800">{{ $paso['cantidad'] }}</span>
                                    <span class="text-xs text-slate-400">· {{ $paso['porcentaje'] }}%@if ($paso['conversion'] !== null) · {{ $paso['conversion'] }}% del paso anterior @endif</span>
                                </span>
                            </span>
                            <span class="gth-bar-track block">
                                <span class="gth-bar-fill block {{ $loop->last ? 'gth-bar-fill-success' : '' }}" style="width: {{ $paso['porcentaje'] }}%"></span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="card-panel-compact">
                <h3 class="text-base font-semibold text-slate-900">Resultado de la gestión</h3>
                <p class="mb-4 text-xs text-slate-400">Tipificación de las llamadas</p>

                <div class="space-y-1">
                    @forelse ($ges['tipificaciones'] as $nombre => $cantidad)
                        <button type="button" wire:key="tip-{{ $loop->index }}" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('tip|'.$nombre) }})" class="gth-click-fila" title="Ver los {{ $cantidad }} registros: {{ $nombre }}">
                            <span class="mb-0.5 flex items-baseline justify-between gap-3 text-xs">
                                <span class="truncate text-slate-600">{{ $nombre }}</span>
                                <span class="font-semibold text-slate-700">{{ $cantidad }}</span>
                            </span>
                            <span class="gth-bar-track block">
                                <span class="gth-bar-fill block" style="width: {{ round($cantidad / $ges['maxTipificacion'] * 100) }}%"></span>
                            </span>
                        </button>
                    @empty
                        <p class="py-6 text-center text-sm text-slate-400">No hay candidatos gestionados en este período.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="card-panel-compact">
            <h3 class="text-base font-semibold text-slate-900">Candidatos por campaña</h3>
            <p class="mb-3 text-xs text-slate-400">Hasta dónde llegan los candidatos de cada campaña. Los que no llevan campaña (mesa de control, administrativos…) se agrupan por su puesto.</p>

            @if (empty($ges['campanas']))
                <p class="py-6 text-center text-sm text-slate-400">No hay candidatos gestionados en este período.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Campaña / puesto</th>
                                <th class="text-right">Gestionados</th>
                                <th class="text-right">% del total</th>
                                <th class="text-right" title="Llamadas registradas en el período (una persona puede sumar varias)">Gestiones</th>
                                @foreach (['contactados', 'interesados', 'aceptaron', 'asistio_entrevista', 'asistio_cap', 'aptos'] as $columna)
                                    <th class="text-right">{{ self::PASOS[$columna] }}</th>
                                @endforeach
                                <th class="text-right">% interés</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ges['campanas'] as $fila)
                                <tr wire:key="campana-{{ $loop->index }}">
                                    <td class="font-medium">
                                        {{ $fila['nombre'] }}
                                        <span class="badge {{ $fila['tipo'] === 'C' ? 'badge-green' : 'badge-slate' }} ml-1">{{ $fila['tipo'] === 'C' ? 'Campaña' : 'Puesto' }}</span>
                                    </td>
                                    @foreach (['gestionados'] as $columna)
                                        <td class="text-right"><button type="button" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('camp|'.$fila['nombre'].'|'.$columna) }})" class="gth-click-numero" title="Ver los registros">{{ $fila[$columna] }}</button></td>
                                    @endforeach
                                    <td class="text-right text-slate-500">{{ (int) round($fila['gestionados'] / max($ges['total'], 1) * 100) }}%</td>
                                    <td class="text-right"><button type="button" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('llam|camp|'.$fila['nombre']) }})" class="gth-click-numero" title="Ver las llamadas">{{ $fila['llamadas'] }}</button></td>
                                    @foreach (['contactados', 'interesados', 'aceptaron', 'asistio_entrevista', 'asistio_cap', 'aptos'] as $columna)
                                        <td class="text-right"><button type="button" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('camp|'.$fila['nombre'].'|'.$columna) }})" class="gth-click-numero" title="Ver los registros">{{ $fila[$columna] }}</button></td>
                                    @endforeach
                                    <td class="text-right font-semibold">{{ $fila['tasa'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-semibold">
                                <td class="px-3 py-1.5 text-slate-700">Total</td>
                                <td class="px-3 py-1.5 text-right"><button type="button" wire:click="abrirDetalle('paso|gestionados')" class="gth-click-numero">{{ $ges['total'] }}</button></td>
                                <td class="px-3 py-1.5 text-right text-slate-500">100%</td>
                                <td class="px-3 py-1.5 text-right"><button type="button" wire:click="abrirDetalle('llam|todas')" class="gth-click-numero">{{ $ges['totalLlamadas'] }}</button></td>
                                @foreach ([1, 2, 3, 4, 5, 6] as $indice)
                                    <td class="px-3 py-1.5 text-right"><button type="button" wire:click="abrirDetalle('paso|{{ $ges['pasos'][$indice]['clave'] }}')" class="gth-click-numero">{{ $ges['pasos'][$indice]['cantidad'] }}</button></td>
                                @endforeach
                                <td class="px-3 py-1.5 text-right">{{ (int) round($ges['pasos'][2]['cantidad'] / max($ges['total'], 1) * 100) }}%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-400">Gestionados cuenta personas; Gestiones cuenta llamadas registradas en el período (una persona puede sumar varias).
                    @if ($ges['desdeLlamadas']) Las llamadas se cuentan desde el {{ \Illuminate\Support\Carbon::parse($ges['desdeLlamadas'])->timezone('America/Lima')->format('d/m/Y') }}, cuando empezó el historial. @endif</p>
            @endif
        </section>

        <div class="grid gap-5 xl:grid-cols-3">
            <section class="card-panel-compact xl:col-span-2">
                <h3 class="text-base font-semibold text-slate-900">Por reclutador</h3>
                <p class="mb-3 text-xs text-slate-400">Detalle de la gestión de cada agente en el período</p>

                @if (empty($ges['reclutadores']))
                    <p class="py-6 text-center text-sm text-slate-400">No hay candidatos gestionados en este período.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr><th>Reclutador</th><th class="text-right">Gestionados</th><th class="text-right" title="Llamadas registradas por la persona en el período">Gestiones</th><th class="text-right">Contactados</th><th class="text-right">Interesados</th><th class="text-right">Aceptaron entrevista</th><th class="text-right">% interés</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($ges['reclutadores'] as $fila)
                                    <tr wire:key="reclutador-{{ $loop->index }}">
                                        <td class="font-medium">{{ $fila['nombre'] }}</td>
                                        @foreach (['gestionados', 'llamadas', 'contactados', 'interesados', 'aceptaron'] as $columna)
                                            <td class="text-right">
                                                @if ($columna === 'llamadas')
                                                    <button type="button" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('llam|rec|'.$fila['nombre']) }})" class="gth-click-numero" title="Ver las llamadas">{{ $fila[$columna] }}</button>
                                                @else
                                                    <button type="button" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('rec|'.$fila['nombre'].'|'.$columna) }})" class="gth-click-numero" title="Ver los registros">{{ $fila[$columna] }}</button>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-right font-semibold">{{ $fila['tasa'] }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="card-panel-compact">
                <h3 class="text-base font-semibold text-slate-900">Motivos de rechazo</h3>
                <p class="mb-4 text-xs text-slate-400">Los 6 más frecuentes</p>

                <div class="space-y-1">
                    @forelse ($ges['motivos'] as $motivo => $cantidad)
                        <button type="button" wire:key="motivo-{{ $loop->index }}" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('mot|'.$motivo) }})" class="gth-click-fila" title="Ver los {{ $cantidad }} registros: {{ $motivo }}">
                            <span class="mb-0.5 flex items-baseline justify-between gap-3 text-xs">
                                <span class="truncate text-slate-600">{{ $motivo }}</span>
                                <span class="font-semibold text-slate-700">{{ $cantidad }}</span>
                            </span>
                            <span class="gth-bar-track block">
                                <span class="gth-bar-fill gth-bar-fill-warning block" style="width: {{ round($cantidad / $ges['maxMotivo'] * 100) }}%"></span>
                            </span>
                        </button>
                    @empty
                        <p class="py-6 text-center text-sm text-slate-400">Sin motivos registrados en este período.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </section>

    {{-- Entrevista y capacitación --}}
    <section class="space-y-3">
        <h2 class="form-section-title">Entrevista y capacitación</h2>

        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            @foreach ($d['resumen'] as $clave => $r)
                <div class="stat-tile" wire:key="actividad-{{ $clave }}">
                    <p class="text-sm font-medium text-slate-700">{{ $r['nombre'] }}</p>
                    <p class="stat-tile-value mt-1">
                        <button type="button" wire:click="abrirDetalle('cita|{{ $clave }}|asistieron')" class="gth-click-numero" title="Ver quiénes asistieron">{{ $r['asistieron'] }}</button><button type="button" wire:click="abrirDetalle('cita|{{ $clave }}|todas')" class="gth-click-numero text-base font-semibold text-slate-400" title="Ver todos los programados"> / {{ $r['programadas'] }}</button>
                    </p>
                    <p class="text-xs text-slate-400">asistieron / programadas {{ $r['porcentaje'] !== null ? '· '.$r['porcentaje'].'% de asistencia' : '' }}</p>

                    <div class="mt-3 space-y-0.5 border-t border-slate-100 pt-2 text-xs">
                        @foreach ($r['filas'] as [$etiqueta, $valor, $color, $estadoClave])
                            <button type="button" wire:key="fila-{{ $clave }}-{{ $estadoClave }}" wire:click="abrirDetalle('cita|{{ $clave }}|{{ $estadoClave }}')" class="gth-click-fila flex justify-between gap-2" title="Ver los registros">
                                <span class="text-slate-500">{{ $etiqueta }}</span>
                                <span class="font-semibold {{ $color }}">{{ $valor }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="stat-tile">
                <p class="text-sm font-medium text-slate-700">Aptos para ingresar</p>
                <p class="stat-tile-value mt-1 text-emerald-600">
                    <button type="button" wire:click="abrirDetalle('aptos')" class="gth-click-numero" title="Ver los aptos">{{ $d['aptos'] }}</button>
                </p>
                <p class="text-xs text-slate-400">marcados como aptos en el período</p>

                <div class="mt-3 space-y-0.5 border-t border-slate-100 pt-2 text-xs">
                    <button type="button" wire:click="abrirDetalle('noaptos')" class="gth-click-fila flex justify-between gap-2" title="Ver los registros">
                        <span class="text-slate-500">No aptos en entrevista o capacitación</span>
                        <span class="font-semibold {{ $d['noAptos'] > 0 ? 'text-red-600' : 'text-slate-700' }}">{{ $d['noAptos'] }}</span>
                    </button>
                </div>
            </div>
        </div>

        @if ($d['diario'])
            <section class="card-panel-compact">
                <h3 class="text-base font-semibold text-slate-900">Agenda del día</h3>
                <p class="mb-3 text-xs text-slate-400">Quién tiene entrevista o capacitación en la fecha elegida y cómo va su asistencia (clic en el nombre para ver su ficha)</p>

                @if (empty($d['agenda']))
                    <p class="py-6 text-center text-sm text-slate-400">No hay entrevistas ni capacitaciones programadas para este día.</p>
                @else
                    <div class="max-h-96 overflow-auto">
                        <table class="data-table">
                            <thead>
                                <tr><th>Actividad</th><th>Candidato</th><th>Campaña / puesto</th><th>Reclutador</th><th>Hora</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($d['agenda'] as $cita)
                                    @php($estado = self::ESTADOS[$cita['estado']])
                                    <tr wire:key="cita-{{ $loop->index }}">
                                        <td class="whitespace-nowrap">{{ self::ACTIVIDADES[$cita['actividad']]['nombre'] }}@if ($cita['reprogramada']) <span class="text-xs text-slate-400">(reprog.)</span>@endif</td>
                                        <td class="font-medium"><button type="button" wire:click="abrirDetalle('cand|{{ $cita['candidato_id'] }}')" class="gth-click-numero text-left">{{ $cita['nombre'] }}</button></td>
                                        <td>{{ $cita['detalle'] ?: '—' }}</td>
                                        <td>{{ $cita['reclutador'] ?: '—' }}</td>
                                        <td>{{ $cita['hora'] ?: '—' }}</td>
                                        <td><span class="badge badge-{{ $estado[1] }}">{{ $estado[0] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </section>

    {{-- Detalle de la cifra elegida --}}
    @if ($det)
        @include('partials.control-gth.detalle-modal')
    @endif
</div>
