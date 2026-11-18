<?php

use App\Concerns\ConDetalleModal;
use App\Concerns\ConPeriodoDiarioMensual;
use App\Models\CandidatoReclutamiento;
use App\Models\Empleado;
use App\Models\EmpleadoPeriodoContrato;
use App\Models\MetaDotacion;
use App\Services\FichaCompleta;
use App\Support\GrupoCampana;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel', ['title' => 'Control de ingresos'])] class extends Component
{
    use ConDetalleModal, ConPeriodoDiarioMensual;

    /** Movimientos de personal que se cuentan en el período (clave => [etiqueta, color de insignia]). */
    public const EVENTOS = [
        'ingreso' => ['Ingresos', 'green'],
        'renovacion' => ['Renovaciones', 'slate'],
        'cese' => ['Ceses', 'rose'],
        'firmado' => ['Contratos firmados cargados', 'green'],
    ];

    public const CESES = ['renuncia' => 'Renuncia', 'despido' => 'Despido', 'vencimiento' => 'Vencimiento'];

    /** Documentos que se suben al cesar a alguien (la constancia de trabajo solo se genera, no se carga). */
    private const CESE_DOCS = [
        'renuncia' => ['carta_renuncia' => 'Carta de renuncia'],
        'despido' => ['carta_no_continuidad_firmada' => 'Carta de no continuidad (firmada)'],
        'vencimiento' => ['carta_no_renovacion_firmada' => 'Carta de no renovación (firmada)'],
    ];

    /** Estado actual del contrato del trabajador (clave => [etiqueta, color de insignia]). */
    public const ESTADOS = [
        'activo' => ['Activo', 'green'],
        'por_vencer' => ['Por vencer', 'amber'],
        'vencido' => ['Vencido', 'rose'],
        'cesado' => ['Cesado', 'slate'],
        'no_apto' => ['No apto', 'rose'],
        'sin_apto' => ['Apto pendiente', 'amber'],
        'por_contratar' => ['Apto · falta contrato', 'amber'],
    ];

    /** Embudo de ingreso de los aptos del período. */
    public const PASOS = [
        'aptos' => 'Aptos para ingresar',
        'ficha' => 'Ficha de datos completada',
        'contrato' => 'Con contrato registrado',
        'firmado' => 'Contrato firmado cargado',
        'vigente' => 'Vigentes hoy',
    ];

    /** Columnas de la tabla por campaña (clave => etiqueta). Las tres primeras son del período; las demás, de hoy. */
    public const METRICAS = [
        'ingreso' => 'Ingresos',
        'renovacion' => 'Renovaciones',
        'cese' => 'Ceses',
        'vigentes' => 'Vigentes hoy',
        'por_vencer' => 'Por vencer',
        'vencidos' => 'Vencidos',
        'no_apto' => 'No aptos',
        'sin_apto' => 'Apto pendiente',
    ];

    public const PENDIENTES = [
        'sin_firma' => 'Contratos sin el firmado cargado',
        'cese_docs' => 'Ceses con documentos pendientes',
        'sin_huella' => 'Personal sin ID de huella',
        'aptos_sin_ficha' => 'Aptos que aún no completan su ficha',
        'por_contratar' => 'Aptos con ficha pero sin contrato registrado',
        'sin_apto' => 'Con ficha y apto pendiente de definir',
    ];

    /** Meta de dotación que se está editando (id) y el valor tecleado. */
    public ?int $metaEditandoId = null;

    public string $metaValor = '';

    public function mount(): void
    {
        // GTH solo ve "Personal hoy" (las metas y la tabla por campaña), sin poder editar.
        abort_unless(Auth::user()->esAdmin() || Auth::user()->esGth(), 403);
        $this->iniciarPeriodo();
    }

    public function editarMeta(int $id): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->resetErrorBag();
        $this->metaEditandoId = $id;
        $this->metaValor = (string) MetaDotacion::findOrFail($id)->meta;
    }

    public function cancelarMeta(): void
    {
        $this->resetErrorBag();
        $this->metaEditandoId = null;
        $this->metaValor = '';
    }

    public function guardarMeta(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->validate(['metaValor' => ['required', 'integer', 'min:0', 'max:1000']], [], ['metaValor' => 'meta']);

        MetaDotacion::whereKey($this->metaEditandoId)->update(['meta' => (int) $this->metaValor, 'actualizado_por_id' => Auth::id(), 'updated_at' => now()]);

        $this->cancelarMeta();
    }

    /** Dotación actual (personal con contrato vigente, incluye los por vencer) frente a la meta de cada campaña. */
    private function metas(array $campanas): array
    {
        $porNombre = collect($campanas)->keyBy(fn ($fila) => mb_strtoupper($fila['nombre']));

        return MetaDotacion::orderBy('id')->get()->map(function (MetaDotacion $meta) use ($porNombre) {
            $fila = $porNombre->get(mb_strtoupper($meta->campana));
            $actual = $fila['vigentes'] ?? 0;
            $porVencer = $fila['por_vencer'] ?? 0;

            return [
                'id' => $meta->id,
                'campana' => $meta->campana,
                'nombre' => mb_convert_case($meta->campana, MB_CASE_TITLE),
                'meta' => $meta->meta,
                'actual' => $actual,
                'activos' => $actual - $porVencer,
                'por_vencer' => $porVencer,
                'faltan' => max($meta->meta - $actual, 0),
                'sobran' => max($actual - $meta->meta, 0),
                'porcentaje' => $meta->meta > 0 ? min((int) round($actual / $meta->meta * 100), 100) : 100,
            ];
        })->all();
    }

    protected function primeraFecha(): ?string
    {
        return EmpleadoPeriodoContrato::min('fecha_inicio');
    }

    private function entre(string $fecha, array $rango): bool
    {
        return $fecha >= $rango[0] && $fecha <= $rango[1];
    }

    /** Trabajadores con su contrato actual, movimientos del período y pendientes (lo que usan las cifras y su detalle). */
    protected function contexto(): array
    {
        $rangos = $this->rangos();
        $actual = [$rangos['actual'][0]->toDateString(), $rangos['actual'][1]->toDateString()];
        $anterior = $rangos['anterior'] ? [$rangos['anterior'][0]->toDateString(), $rangos['anterior'][1]->toDateString()] : null;
        $utc = $this->rangoUtc($rangos['actual']);
        $hoy = $this->hoy()->startOfDay();

        $empleados = Empleado::with(['periodosContrato.documentoContrato', 'periodosContrato.documentosCese', 'invitacion:id,reclutamiento_candidato_id'])
            ->get(['id', 'onboarding_invitation_id', 'biometrico_id', 'apellido_paterno', 'apellido_materno', 'nombres', 'documento_identidad', 'celular_llamadas', 'campana', 'puesto', 'contrato_campana', 'contrato_puesto']);

        $candidatos = CandidatoReclutamiento::whereIn('id', $empleados->pluck('invitacion.reclutamiento_candidato_id')->filter())
            ->get(['id', 'campana', 'puesto', 'apto', 'asistio_entrevista', 'asistio_entrevista_reprogramada', 'asistio_cap_1', 'asistio_cap_1_reprogramada', 'asistio_cap_2', 'asistio_cap_2_reprogramada'])
            ->keyBy('id');

        $registros = collect();
        $eventos = collect();
        $previos = $anterior ? ['ingreso' => 0, 'cese' => 0] : null;

        foreach ($empleados as $e) {
            $periodos = $e->periodosContrato;
            $ultimo = $periodos->first();
            $candidato = $candidatos->get($e->invitacion?->reclutamiento_candidato_id);
            // Sin contrato: según reclutamiento es «No apto» (no asistió o no apto en la capacitación), «Apto pendiente» (el proceso sigue) o apto por contratar.
            $situacion = $candidato?->situacionSinApto();
            $estado = $ultimo ? $ultimo->estado() : ($situacion ? ($situacion['definitivo'] ? 'no_apto' : 'sin_apto') : 'por_contratar');
            $grupo = $candidato
                ? GrupoCampana::de($candidato->campana, $candidato->puesto)
                : GrupoCampana::de($e->contrato_campana ?: $e->campana, $e->contrato_puesto ?: $e->puesto);

            $faltanCese = [];
            if ($estado === 'cesado') {
                $subidos = $ultimo->documentosCese->pluck('tipo')->all();
                $faltanCese = array_values(array_diff_key(self::CESE_DOCS[$ultimo->cese_tipo] ?? [], array_flip($subidos)));
            }

            $nombre = trim("{$e->apellido_paterno} {$e->apellido_materno}, {$e->nombres}");

            $registros->put($e->id, [
                'id' => $e->id,
                'nombre' => $nombre,
                'dni' => $e->documento_identidad,
                'celular' => $e->celular_llamadas,
                'huella' => $e->biometrico_id,
                'estado' => $estado,
                'tiene_periodo' => $ultimo !== null,
                'motivo' => in_array($estado, ['no_apto', 'sin_apto'], true) ? $situacion['motivo'] : null,
                'grupo' => $grupo,
                'candidato_id' => $candidato?->id,
                'tiene_firmado' => $periodos->contains(fn ($p) => $p->documentoContrato),
                'sin_firma' => $ultimo && ! in_array($estado, ['cesado'], true) && ! $ultimo->documentoContrato,
                'faltan_cese' => $faltanCese,
                'contrato' => $ultimo ? ($ultimo->tipo === 'prueba' ? '1er contrato' : 'Renovación') : null,
                'fecha_fin' => $ultimo?->fecha_fin,
                'dias' => $ultimo ? (int) $hoy->diffInDays($ultimo->fecha_fin->copy()->startOfDay(), false) : null,
            ]);

            foreach ($periodos as $p) {
                $tipo = $p->tipo === 'prueba' ? 'ingreso' : 'renovacion';
                $inicio = $p->fecha_inicio->toDateString();
                $nombreTipo = $tipo === 'ingreso' ? 'Ingreso' : 'Renovación';

                if ($this->entre($inicio, $actual)) {
                    $eventos->push(['empleado_id' => $e->id, 'nombre' => $nombre, 'tipo' => $tipo, 'fecha' => $p->fecha_inicio, 'grupo' => $grupo['nombre'], 'detalle' => 'Contrato hasta '.$p->fecha_fin->format('d/m/Y'), 'nota' => $nombreTipo.' · '.$p->fecha_inicio->format('d/m/Y'), 'cese_tipo' => null]);
                }
                if ($anterior && $tipo === 'ingreso' && $this->entre($inicio, $anterior)) {
                    $previos['ingreso']++;
                }

                if ($p->cese && $p->cese_fecha) {
                    $cese = $p->cese_fecha->toDateString();
                    $motivo = self::CESES[$p->cese_tipo] ?? (string) $p->cese_tipo;

                    if ($this->entre($cese, $actual)) {
                        $eventos->push(['empleado_id' => $e->id, 'nombre' => $nombre, 'tipo' => 'cese', 'fecha' => $p->cese_fecha, 'grupo' => $grupo['nombre'], 'detalle' => $motivo, 'nota' => 'Cese · '.$motivo.' · '.$p->cese_fecha->format('d/m/Y'), 'cese_tipo' => $p->cese_tipo]);
                    }
                    if ($anterior && $this->entre($cese, $anterior)) {
                        $previos['cese']++;
                    }
                }

                $subido = $p->documentoContrato?->created_at?->format('Y-m-d H:i:s');
                if ($subido && $subido >= $utc[0] && $subido <= $utc[1]) {
                    $fechaLima = $p->documentoContrato->created_at->copy()->setTimezone('America/Lima');
                    $eventos->push(['empleado_id' => $e->id, 'nombre' => $nombre, 'tipo' => 'firmado', 'fecha' => $fechaLima, 'grupo' => $grupo['nombre'], 'detalle' => 'Contrato firmado cargado', 'nota' => 'Firmado cargado · '.$fechaLima->format('d/m/Y'), 'cese_tipo' => null]);
                }
            }
        }

        $porCandidato = $registros->filter(fn ($r) => $r['candidato_id'])->keyBy('candidato_id');
        $fechasApto = CandidatoReclutamiento::where('apto', true)->whereBetween('fecha_apto', $utc)->pluck('fecha_apto', 'id');
        $aptos = $fechasApto->keys();
        $aptosSinFicha = CandidatoReclutamiento::where('apto', true)->whereNotIn('id', $porCandidato->keys())->pluck('id');

        $conRegistro = $aptos->map(fn ($id) => $porCandidato->get($id))->filter();

        return [
            'rangos' => $rangos,
            'registros' => $registros,
            'eventos' => $eventos,
            'previos' => $previos,
            'aptos' => $aptos,
            'fechasApto' => $fechasApto,
            'porCandidato' => $porCandidato,
            'aptosSinFicha' => $aptosSinFicha,
            'cohorte' => [
                'aptos' => $aptos,
                'ficha' => $conRegistro->pluck('id'),
                'contrato' => $conRegistro->where('tiene_periodo', true)->pluck('id'),
                'firmado' => $conRegistro->where('tiene_firmado', true)->pluck('id'),
                'vigente' => $conRegistro->whereIn('estado', ['activo', 'por_vencer'])->pluck('id'),
            ],
        ];
    }

    private function enLista(array $registro, string $lista): bool
    {
        return match ($lista) {
            'vigentes' => in_array($registro['estado'], ['activo', 'por_vencer'], true),
            'por_vencer' => $registro['estado'] === 'por_vencer',
            'vencidos' => $registro['estado'] === 'vencido',
            'no_apto' => $registro['estado'] === 'no_apto',
            'sin_apto' => $registro['estado'] === 'sin_apto',
            'por_contratar' => $registro['estado'] === 'por_contratar',
            default => false,
        };
    }

    private function esPendiente(array $registro, string $pendiente): bool
    {
        return match ($pendiente) {
            'sin_firma' => $registro['sin_firma'],
            'cese_docs' => $registro['faltan_cese'] !== [],
            'sin_huella' => ! $registro['huella'] && $registro['tiene_periodo'] && $registro['estado'] !== 'cesado',
            'por_contratar' => $registro['estado'] === 'por_contratar',
            'sin_apto' => $registro['estado'] === 'sin_apto',
            default => false,
        };
    }

    /**
     * Registros detrás de una cifra. La clave viene de la pantalla: «tipo|a|b»
     * (mov, cese, pend, paso, camp, emp, cand). Solo se comparan valores en PHP;
     * lo que no se reconoce devuelve null.
     *
     * @return array{titulo: string, filas: array<int, array>}|null
     */
    protected function resolverDetalle(string $clave, array $ctx): ?array
    {
        // GTH solo puede abrir el detalle de las cifras de "Personal hoy" (campaña).
        if (! Auth::user()->esAdmin() && ! str_starts_with($clave, 'camp|')) {
            return null;
        }

        [$tipo, $a, $b] = array_pad(explode('|', $clave, 3), 3, null);

        /** @var Collection $registros */
        $registros = $ctx['registros'];
        $eventos = $ctx['eventos'];

        $deRegistros = fn (Collection $lista, ?callable $nota = null) => $lista->map(fn ($r) => ['tipo' => 'e', 'id' => $r['id'], 'nota' => $nota ? $nota($r) : null, 'estado' => null])->values()->all();
        $deEventos = fn (Collection $lista) => $lista->map(fn ($ev) => ['tipo' => 'e', 'id' => $ev['empleado_id'], 'nota' => $ev['nota'], 'estado' => null])->values()->all();
        // Un candidato que ya llenó su ficha se muestra como trabajador (con su ficha completa); si no, como candidato.
        $deCandidatos = fn ($ids) => collect($ids)->map(function ($id) use ($ctx) {
            $registro = $ctx['porCandidato']->get($id);
            $fechaApto = $ctx['fechasApto'][$id] ?? null;
            $nota = $fechaApto ? 'Apto: '.Carbon::parse($fechaApto, 'UTC')->setTimezone('America/Lima')->format('d/m/Y') : null;

            return $registro
                ? ['tipo' => 'e', 'id' => $registro['id'], 'nota' => $nota, 'estado' => null]
                : ['tipo' => 'c', 'id' => $id, 'nota' => $nota, 'estado' => null];
        })->values()->all();

        return match (true) {
            $tipo === 'mov' && isset(self::EVENTOS[$a]) => [
                'titulo' => self::EVENTOS[$a][0],
                'filas' => $deEventos($eventos->where('tipo', $a)),
            ],
            $tipo === 'cese' && isset(self::CESES[$a]) => [
                'titulo' => 'Ceses por '.mb_strtolower(self::CESES[$a]),
                'filas' => $deEventos($eventos->where('tipo', 'cese')->where('cese_tipo', $a)),
            ],
            $tipo === 'pend' && $a === 'aptos_sin_ficha' => [
                'titulo' => self::PENDIENTES[$a],
                'filas' => $deCandidatos($ctx['aptosSinFicha']),
            ],
            $tipo === 'pend' && isset(self::PENDIENTES[$a]) => [
                'titulo' => self::PENDIENTES[$a],
                'filas' => $deRegistros(
                    $registros->filter(fn ($r) => $this->esPendiente($r, $a)),
                    match ($a) {
                        'cese_docs' => fn ($r) => 'Falta: '.implode(', ', $r['faltan_cese']),
                        'sin_apto' => fn ($r) => $r['motivo'],
                        default => null,
                    },
                ),
            ],
            $tipo === 'paso' && isset(self::PASOS[$a]) => [
                'titulo' => self::PASOS[$a],
                'filas' => $a === 'aptos'
                    ? $deCandidatos($ctx['cohorte']['aptos'])
                    : $deRegistros($registros->only($ctx['cohorte'][$a]->all())),
            ],
            $tipo === 'camp' && $a !== null && isset(self::METRICAS[$b]) => [
                'titulo' => $a.' · '.self::METRICAS[$b],
                'filas' => in_array($b, ['ingreso', 'renovacion', 'cese'], true)
                    ? $deEventos($eventos->where('tipo', $b)->where('grupo', $a))
                    : $deRegistros(
                        $registros->filter(fn ($r) => $r['grupo']['nombre'] === $a && $this->enLista($r, $b)),
                        in_array($b, ['no_apto', 'sin_apto'], true) ? fn ($r) => $r['motivo'] : null,
                    ),
            ],
            $tipo === 'emp' && $a !== null && ctype_digit($a) && $registros->has((int) $a) => [
                'titulo' => 'Ficha del trabajador',
                'filas' => $deRegistros(collect([$registros->get((int) $a)])),
            ],
            $tipo === 'cand' && $a !== null && ctype_digit($a) => [
                'titulo' => 'Ficha del candidato',
                'filas' => $deCandidatos([(int) $a]),
            ],
            default => null,
        };
    }

    /** Filas de la ventana de detalle: datos básicos de cada registro y, solo al abrirla, su ficha completa. */
    private function hidratarFilas(array $filas, array $ctx): Collection
    {
        $candidatos = CandidatoReclutamiento::whereIn('id', collect($filas)->where('tipo', 'c')->pluck('id'))->get()->keyBy('id');

        return collect($filas)->map(function ($fila) use ($ctx, $candidatos) {
            if ($fila['tipo'] === 'e') {
                $r = $ctx['registros']->get($fila['id']);

                return $r ? [
                    'nombre' => $r['nombre'] ?: '(sin nombre)',
                    'chips' => ['DNI '.($r['dni'] ?: '—'), 'Cel. '.($r['celular'] ?: '—'), $r['grupo']['nombre'], 'Huella: '.($r['huella'] ?: '—')],
                    'nota' => $fila['nota'],
                    'badge' => self::ESTADOS[$r['estado']],
                    'ficha' => fn () => FichaCompleta::empleado(Empleado::findOrFail($r['id'])),
                ] : null;
            }

            $c = $candidatos->get($fila['id']);

            return $c ? [
                'nombre' => $c->nombreCompleto() ?: '(sin nombre)',
                'chips' => [
                    'DNI '.($c->dni_ce ?: '—'),
                    'Cel. '.($c->numero_celular ?: '—'),
                    GrupoCampana::de($c->campana, $c->puesto)['nombre'],
                    'Apto: '.($c->fecha_apto ? $c->fecha_apto->copy()->setTimezone('America/Lima')->format('d/m/Y') : '—'),
                ],
                'nota' => $fila['nota'],
                'badge' => ['Sin ficha completada', 'amber'],
                'ficha' => fn () => FichaCompleta::candidato($c),
            ] : null;
        })->filter()->values();
    }

    private function embudo(array $ctx): array
    {
        $pasos = [];
        foreach (self::PASOS as $clave => $nombre) {
            $pasos[] = ['clave' => $clave, 'nombre' => $nombre, 'cantidad' => $ctx['cohorte'][$clave]->count()];
        }

        $base = max($pasos[0]['cantidad'], 1);
        foreach ($pasos as $i => $paso) {
            $pasos[$i]['porcentaje'] = (int) round($paso['cantidad'] / $base * 100);
            $pasos[$i]['conversion'] = $i > 0 && $pasos[$i - 1]['cantidad'] > 0 ? (int) round($paso['cantidad'] / $pasos[$i - 1]['cantidad'] * 100) : null;
        }

        return $pasos;
    }

    /** Personal por campaña (o puesto): movimientos del período y situación de hoy. */
    private function porCampana(array $ctx): array
    {
        $filas = [];
        $nueva = fn (array $grupo) => ['nombre' => $grupo['nombre'], 'tipo' => $grupo['tipo']] + array_fill_keys(array_keys(self::METRICAS), 0);

        foreach ($ctx['registros'] as $r) {
            $filas[$r['grupo']['nombre']] ??= $nueva($r['grupo']);

            foreach (['vigentes', 'por_vencer', 'vencidos', 'no_apto', 'sin_apto'] as $lista) {
                $filas[$r['grupo']['nombre']][$lista] += $this->enLista($r, $lista) ? 1 : 0;
            }
        }

        foreach ($ctx['eventos'] as $ev) {
            if (isset($filas[$ev['grupo']]) && isset(self::METRICAS[$ev['tipo']])) {
                $filas[$ev['grupo']][$ev['tipo']]++;
            }
        }

        return collect($filas)
            ->sort(fn ($x, $y) => [$x['tipo'] === 'C' ? 0 : 1, -$x['vigentes'], $x['nombre']] <=> [$y['tipo'] === 'C' ? 0 : 1, -$y['vigentes'], $y['nombre']])
            ->values()->all();
    }

    /** Ingresos y ceses día por día del mes elegido (Mensual) o mes por mes (Total). */
    private function tendencia(array $rango, Collection $eventos): array
    {
        $porColumna = fn (string $tipo) => $eventos->where('tipo', $tipo)->countBy(fn ($ev) => $this->claveTendencia($ev['fecha']));
        $ingresos = $porColumna('ingreso');
        $ceses = $porColumna('cese');
        $columnas = [];

        foreach ($this->columnasTendencia($rango) as $columna) {
            $columnas[] = ['etiqueta' => $columna['etiqueta'], 'titulo' => $columna['titulo'], 'a' => $ingresos->get($columna['clave'], 0), 'b' => $ceses->get($columna['clave'], 0)];
        }

        return [
            'columnas' => $columnas,
            'maximo' => max(collect($columnas)->flatMap(fn ($c) => [$c['a'], $c['b']])->max(), 1),
            'unidad' => $this->unidadTendencia(),
        ];
    }

    public function datos(): array
    {
        $ctx = $this->contexto();
        $rangos = $ctx['rangos'];
        /** @var Collection $registros */
        $registros = $ctx['registros'];
        $eventos = $ctx['eventos'];

        $cuenta = fn (string $tipo) => $eventos->where('tipo', $tipo)->count();
        $campanas = $this->porCampana($ctx);
        $resuelto = $this->detalle !== null ? $this->resolverDetalle($this->detalle, $ctx) : null;

        $motivos = [];
        foreach (self::CESES as $clave => $etiqueta) {
            $motivos[] = ['clave' => $clave, 'nombre' => $etiqueta, 'cantidad' => $eventos->where('tipo', 'cese')->where('cese_tipo', $clave)->count()];
        }

        return [
            'vista' => $this->vista,
            'diario' => $this->vista === 'diario',
            'unidad' => $this->unidadTendencia(),
            'periodo' => $this->etiquetaPeriodo($rangos['actual']),
            'comparativo' => $this->vista === 'diario' ? 'el día anterior' : 'el mes anterior',
            'periodoCifras' => [
                'ingresos' => $cuenta('ingreso'),
                'ingresosPrevios' => $ctx['previos']['ingreso'] ?? null,
                'renovaciones' => $cuenta('renovacion'),
                'ceses' => $cuenta('cese'),
                'cesesPrevios' => $ctx['previos']['cese'] ?? null,
                'firmados' => $cuenta('firmado'),
            ],
            'pendientes' => [
                'sin_firma' => $registros->filter(fn ($r) => $this->esPendiente($r, 'sin_firma'))->count(),
                'cese_docs' => $registros->filter(fn ($r) => $this->esPendiente($r, 'cese_docs'))->count(),
                'sin_huella' => $registros->filter(fn ($r) => $this->esPendiente($r, 'sin_huella'))->count(),
                'aptos_sin_ficha' => $ctx['aptosSinFicha']->count(),
                'por_contratar' => $registros->filter(fn ($r) => $this->esPendiente($r, 'por_contratar'))->count(),
                'sin_apto' => $registros->filter(fn ($r) => $this->esPendiente($r, 'sin_apto'))->count(),
            ],
            'embudo' => $this->embudo($ctx),
            'motivos' => $motivos,
            'campanas' => $campanas,
            'metas' => $this->metas($campanas),
            'movimientos' => $eventos->whereIn('tipo', ['ingreso', 'renovacion', 'cese'])->sortBy(fn ($ev) => $ev['fecha']->toDateString().$ev['tipo'])->values()->all(),
            'tendencia' => $this->vista !== 'diario' ? $this->tendencia($rangos['actual'], $eventos) : null,
            'accion' => $registros->filter(fn ($r) => in_array($r['estado'], ['vencido', 'por_vencer'], true))->sortBy('dias')->values()->take(10)->all(),
            'detalle' => $resuelto ? $this->armarFilasDetalle($resuelto['titulo'], $this->hidratarFilas($resuelto['filas'], $ctx)) : null,
        ];
    }
};
?>

<div class="space-y-5">
    @php($d = $this->datos())
    @php($per = $d['periodoCifras'])
    @php($det = $d['detalle'])
    {{-- Se pausa la actualización automática mientras hay un detalle abierto. --}}
    @if (! $det && $metaEditandoId === null)
        <div wire:poll.60s class="hidden" aria-hidden="true"></div>
    @endif

    @php($esAdmin = Auth::user()->esAdmin())

    @include('partials.control-gth.selector-periodo')

    {{-- Movimiento del período --}}
    @if ($esAdmin)
    <section class="space-y-3">
        <h2 class="form-section-title">Ingresos y ceses del período</h2>

        @php($difIngresos = $per['ingresosPrevios'] === null ? null : $per['ingresos'] - $per['ingresosPrevios'])
        @php($difCeses = $per['cesesPrevios'] === null ? null : $per['ceses'] - $per['cesesPrevios'])
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <button type="button" wire:click="abrirDetalle('mov|ingreso')" class="stat-tile gth-click">
                <span class="stat-tile-value block text-emerald-600">{{ $per['ingresos'] }}</span>
                <span class="stat-tile-label block">Ingresos</span>
                @if ($difIngresos !== null)
                    <span class="mt-1 block text-xs {{ $difIngresos > 0 ? 'text-emerald-600' : ($difIngresos < 0 ? 'text-red-600' : 'text-slate-400') }}">
                        {{ $difIngresos > 0 ? '▲ +'.$difIngresos : ($difIngresos < 0 ? '▼ '.$difIngresos : '= sin cambio') }} vs {{ $d['comparativo'] }} ({{ $per['ingresosPrevios'] }})
                    </span>
                @else
                    <span class="mt-1 block text-xs text-slate-400">de todo el historial</span>
                @endif
            </button>
            <button type="button" wire:click="abrirDetalle('mov|renovacion')" class="stat-tile gth-click">
                <span class="stat-tile-value block">{{ $per['renovaciones'] }}</span>
                <span class="stat-tile-label block">Renovaciones</span>
                <span class="mt-1 block text-xs text-slate-400">contratos renovados</span>
            </button>
            <button type="button" wire:click="abrirDetalle('mov|cese')" class="stat-tile gth-click">
                <span class="stat-tile-value block {{ $per['ceses'] > 0 ? 'text-red-600' : '' }}">{{ $per['ceses'] }}</span>
                <span class="stat-tile-label block">Ceses</span>
                @if ($difCeses !== null)
                    <span class="mt-1 block text-xs {{ $difCeses > 0 ? 'text-red-600' : ($difCeses < 0 ? 'text-emerald-600' : 'text-slate-400') }}">
                        {{ $difCeses > 0 ? '▲ +'.$difCeses : ($difCeses < 0 ? '▼ '.$difCeses : '= sin cambio') }} vs {{ $d['comparativo'] }} ({{ $per['cesesPrevios'] }})
                    </span>
                @else
                    <span class="mt-1 block text-xs text-slate-400">de todo el historial</span>
                @endif
            </button>
            <button type="button" wire:click="abrirDetalle('mov|firmado')" class="stat-tile gth-click">
                <span class="stat-tile-value block">{{ $per['firmados'] }}</span>
                <span class="stat-tile-label block">Contratos firmados cargados</span>
                <span class="mt-1 block text-xs text-slate-400">subidos en el período</span>
            </button>
        </div>

        @if (! $d['diario'])
            <section class="card-panel-compact">
                <h3 class="text-base font-semibold text-slate-900">Ingresos y ceses por {{ $d['unidad'] }}</h3>
                <p class="mb-3 text-xs text-slate-400">Inicio de contrato de ingreso y fecha de cese de cada {{ $d['unidad'] }}</p>
                @include('partials.control-gth.columnas', ['grafico' => $d['tendencia'], 'nombreA' => 'Ingresos', 'nombreB' => 'Ceses'])
            </section>
        @else
            <section class="card-panel-compact">
                <h3 class="text-base font-semibold text-slate-900">Movimientos del día</h3>
                <p class="mb-3 text-xs text-slate-400">Ingresos, renovaciones y ceses de la fecha elegida (clic en el nombre para ver su ficha)</p>

                @if (empty($d['movimientos']))
                    <p class="py-6 text-center text-sm text-slate-400">No hubo ingresos, renovaciones ni ceses en este día.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr><th>Movimiento</th><th>Trabajador</th><th>Campaña / puesto</th><th>Detalle</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($d['movimientos'] as $mov)
                                    <tr wire:key="mov-{{ $loop->index }}">
                                        <td><span class="badge badge-{{ self::EVENTOS[$mov['tipo']][1] }}">{{ self::EVENTOS[$mov['tipo']][0] }}</span></td>
                                        <td class="font-medium"><button type="button" wire:click="abrirDetalle('emp|{{ $mov['empleado_id'] }}')" class="gth-click-numero text-left">{{ $mov['nombre'] }}</button></td>
                                        <td>{{ $mov['grupo'] }}</td>
                                        <td>{{ $mov['detalle'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        <div class="grid gap-5 xl:grid-cols-3">
            <section class="card-panel-compact xl:col-span-2">
                <h3 class="text-base font-semibold text-slate-900">Embudo de ingreso</h3>
                <p class="mb-4 text-xs text-slate-400">De los candidatos marcados aptos en el período, hasta dónde avanzaron. El % grande se mide contra los aptos y el chico contra el paso anterior.</p>

                <div class="space-y-1.5">
                    @foreach ($d['embudo'] as $i => $paso)
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
                <h3 class="text-base font-semibold text-slate-900">Motivos de cese</h3>
                <p class="mb-4 text-xs text-slate-400">Ceses del período según su tipo</p>

                @php($maxMotivo = max(collect($d['motivos'])->max('cantidad'), 1))
                <div class="space-y-1">
                    @foreach ($d['motivos'] as $motivo)
                        <button type="button" wire:key="motivo-{{ $motivo['clave'] }}" wire:click="abrirDetalle('cese|{{ $motivo['clave'] }}')" class="gth-click-fila" title="Ver los {{ $motivo['cantidad'] }} registros: {{ $motivo['nombre'] }}">
                            <span class="mb-0.5 flex items-baseline justify-between gap-3 text-xs">
                                <span class="truncate text-slate-600">{{ $motivo['nombre'] }}</span>
                                <span class="font-semibold text-slate-700">{{ $motivo['cantidad'] }}</span>
                            </span>
                            <span class="gth-bar-track block">
                                <span class="gth-bar-fill gth-bar-fill-danger block" style="width: {{ round($motivo['cantidad'] / $maxMotivo * 100) }}%"></span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </section>
        </div>
    </section>
    @endif

    {{-- Personal hoy --}}
    <section class="space-y-3">
        <h2 class="form-section-title">Personal hoy</h2>

        @if (! empty($d['metas']))
            <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                @foreach ($d['metas'] as $meta)
                    <div wire:key="meta-{{ $meta['id'] }}" class="gth-meta-tile">
                        @if ($metaEditandoId === $meta['id'])
                            <form wire:submit="guardarMeta" class="space-y-2">
                                <label for="meta-valor-{{ $meta['id'] }}" class="form-label">Meta de {{ $meta['nombre'] }}</label>
                                <input id="meta-valor-{{ $meta['id'] }}" type="number" min="0" max="1000" wire:model="metaValor" class="form-input" autofocus />
                                @error('metaValor')
                                    <p class="form-error">{{ $message }}</p>
                                @enderror
                                <div class="flex gap-2">
                                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Guardar</button>
                                    <button type="button" wire:click="cancelarMeta" class="btn-secondary">Cancelar</button>
                                </div>
                            </form>
                        @else
                            <button type="button" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('camp|'.$meta['campana'].'|vigentes') }})" class="block w-full text-left" title="Ver el personal vigente de {{ $meta['nombre'] }}">
                                <span class="block text-sm font-medium text-slate-700">{{ $meta['nombre'] }} · Actual / Meta</span>
                                <span class="stat-tile-value mt-1 block">{{ $meta['actual'] }}<span class="text-base font-semibold text-slate-400"> / {{ $meta['meta'] }}</span></span>
                                @if ($meta['faltan'] > 0)
                                    <span class="mt-1 block text-xs font-semibold text-amber-600">Faltan {{ $meta['faltan'] }} para la meta</span>
                                @elseif ($meta['sobran'] > 0)
                                    <span class="mt-1 block text-xs font-semibold text-emerald-600">Meta cumplida · {{ $meta['sobran'] }} más de lo requerido</span>
                                @else
                                    <span class="mt-1 block text-xs font-semibold text-emerald-600">Meta cumplida</span>
                                @endif
                                <span class="gth-bar-track mt-2 block">
                                    <span class="gth-bar-fill block {{ $meta['faltan'] > 0 ? '' : 'gth-bar-fill-success' }}" style="width: {{ $meta['porcentaje'] }}%"></span>
                                </span>
                            </button>
                            @if ($esAdmin)
                                <button type="button" wire:click="editarMeta({{ $meta['id'] }})" class="gth-meta-editar" aria-label="Editar la meta de {{ $meta['nombre'] }}" title="Editar la meta">✎</button>
                            @endif
                        @endif
                    </div>
                @endforeach

                @foreach ($d['metas'] as $meta)
                    <button type="button" wire:key="vigentes-{{ $meta['id'] }}" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('camp|'.$meta['campana'].'|vigentes') }})" class="stat-tile gth-click gth-vigentes-tile">
                        <span class="block text-sm font-medium text-slate-700">Vigentes hoy · {{ $meta['nombre'] }}</span>
                        <span class="stat-tile-value mt-1 block text-emerald-600">{{ $meta['actual'] }}</span>
                        <span class="mt-1 block text-xs text-slate-400">{{ $meta['activos'] }} activos · {{ $meta['por_vencer'] }} por vencer</span>
                    </button>
                @endforeach
            </div>
        @endif

        <section class="card-panel-compact">
            <h3 class="text-base font-semibold text-slate-900">Personal por campaña</h3>
            
            @if (empty($d['campanas']))
                <p class="py-6 text-center text-sm text-slate-400">Todavía no hay trabajadores registrados.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Campaña / puesto</th>
                                @foreach (self::METRICAS as $etiqueta)
                                    <th class="text-right">{{ $etiqueta }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['campanas'] as $fila)
                                <tr wire:key="campana-{{ $loop->index }}">
                                    <td class="font-medium">
                                        {{ $fila['nombre'] }}
                                        <span class="badge {{ $fila['tipo'] === 'C' ? 'badge-green' : 'badge-slate' }} ml-1">{{ $fila['tipo'] === 'C' ? 'Campaña' : 'Puesto' }}</span>
                                    </td>
                                    @foreach (array_keys(self::METRICAS) as $metrica)
                                        <td class="text-right">
                                            <button type="button" wire:click="abrirDetalle({{ \Illuminate\Support\Js::from('camp|'.$fila['nombre'].'|'.$metrica) }})" class="gth-click-numero" title="Ver los registros">{{ $fila[$metrica] }}</button>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-semibold">
                                <td class="px-3 py-1.5 text-slate-700">Total</td>
                                @foreach (array_keys(self::METRICAS) as $metrica)
                                    <td class="px-3 py-1.5 text-right">{{ collect($d['campanas'])->sum($metrica) }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        @if ($esAdmin)
        <div class="grid gap-5 xl:grid-cols-3">
            <section class="card-panel-compact xl:col-span-2">
                <h3 class="text-base font-semibold text-slate-900">Contratos que requieren acción</h3>
                <p class="mb-3 text-xs text-slate-400">Vencidos y por vencer en los próximos 15 días (clic en el nombre para ver su ficha)</p>

                @if (empty($d['accion']))
                    <p class="py-6 text-center text-sm text-slate-400">No hay contratos pendientes de renovar.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr><th>Trabajador</th><th>Campaña / puesto</th><th>Contrato</th><th>Vence</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($d['accion'] as $fila)
                                    <tr wire:key="accion-{{ $fila['id'] }}">
                                        <td class="font-medium"><button type="button" wire:click="abrirDetalle('emp|{{ $fila['id'] }}')" class="gth-click-numero text-left">{{ $fila['nombre'] }}</button></td>
                                        <td>{{ $fila['grupo']['nombre'] }}</td>
                                        <td>{{ $fila['contrato'] }}</td>
                                        <td>{{ $fila['fecha_fin']->format('d/m/Y') }}</td>
                                        <td>
                                            @if ($fila['estado'] === 'vencido')
                                                <span class="badge badge-rose">Venció hace {{ abs($fila['dias']) }} {{ abs($fila['dias']) === 1 ? 'día' : 'días' }}</span>
                                            @else
                                                <span class="badge badge-amber">{{ $fila['dias'] === 0 ? 'Vence hoy' : 'Vence en '.$fila['dias'].' '.($fila['dias'] === 1 ? 'día' : 'días') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="card-panel-compact">
                <h3 class="text-base font-semibold text-slate-900">Pendientes de completar</h3>
                <p class="mb-3 text-xs text-slate-400">Trabajo atrasado en el registro de ingresos</p>

                <div class="space-y-1">
                    @foreach (self::PENDIENTES as $clave => $etiqueta)
                        <button type="button" wire:key="pend-{{ $clave }}" wire:click="abrirDetalle('pend|{{ $clave }}')" class="gth-click-fila flex items-center justify-between gap-3 text-sm" title="Ver los registros">
                            <span class="text-slate-600">{{ $etiqueta }}</span>
                            <span class="badge {{ $d['pendientes'][$clave] > 0 ? 'badge-amber' : 'badge-green' }} min-w-[2rem] justify-center">{{ $d['pendientes'][$clave] }}</span>
                        </button>
                    @endforeach
                </div>
            </section>
        </div>
        @endif
    </section>

    {{-- Detalle de la cifra elegida --}}
    @if ($det)
        @include('partials.control-gth.detalle-modal')
    @endif
</div>
