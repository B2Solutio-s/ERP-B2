<?php

use App\Models\AsistenciaComentario;
use App\Models\AsistenciaComentarioDia;
use App\Models\AsistenciaDescuento;
use App\Models\AsistenciaMarcacion;
use App\Models\Empleado;
use App\Services\CalendarioLaboral;
use App\Services\DescuentosAsistencia;
use App\Services\PersonalAsistencia;
use App\Services\Vacaciones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.panel', ['title' => 'Asistencia mensual'])] class extends Component
{
    use WithFileUploads;

    private const ZONA = 'America/Lima';

    public string $mes = '';

    /** Campaña o puesto elegido en el filtro ('' = todos). */
    public string $grupo = '';

    public string $buscar = '';

    /** 'pendientes' = solo quienes tienen descuentos sugeridos por revisar. */
    public string $revision = '';

    /** Ventana de detalle: día elegido y, si se tocó una celda, la persona. */
    public ?string $celdaFecha = null;

    public ?int $celdaEmpleado = null;

    public string $comentarioPersona = '';

    public string $comentarioDia = '';

    /** Tarjeta del resumen cuyo detalle se está viendo (personas, tardanzas, faltas, sin_marcas, confirmados, por_revisar, justificadas). */
    public ?string $tarjeta = null;

    /** Justificación de una falta: sustento (documento o foto) y comentario. */
    public bool $justificando = false;

    public $sustento = null;

    public string $justificacionComentario = '';

    /** Persona cuyo sueldo se está registrando y el valor tecleado. */
    public ?int $sueldoId = null;

    public string $sueldoValor = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->mes = $this->hoy()->format('Y-m');
    }

    private function hoy(): Carbon
    {
        return now(self::ZONA);
    }

    private function inicioDelMes(): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m', $this->mes, self::ZONA) ?: $this->hoy()->startOfMonth();
        } catch (\Throwable) {
            return $this->hoy()->startOfMonth();
        }
    }

    /** Del mes de la primera marcación hasta el actual, el más reciente primero. */
    public function mesesDisponibles(): array
    {
        $primera = AsistenciaMarcacion::min('marcado_at');
        $inicio = ($primera ? Carbon::parse($primera) : $this->hoy())->startOfMonth();
        $meses = [];

        for ($mes = $this->hoy()->startOfMonth(); $mes->gte($inicio); $mes->subMonthNoOverflow()) {
            $meses[$mes->format('Y-m')] = ucfirst($mes->copy()->locale('es')->isoFormat('MMMM YYYY'));
        }

        return $meses;
    }

    public function updatedMes(): void
    {
        $this->cerrar();
        $this->mes = array_key_exists($this->mes, $this->mesesDisponibles()) ? $this->mes : $this->hoy()->format('Y-m');
    }

    public function moverMes(int $meses): void
    {
        $claves = array_keys($this->mesesDisponibles());   // del más reciente al más antiguo
        $actual = array_search($this->mes, $claves, true);
        $destino = $claves[min(max(($actual === false ? 0 : $actual) - $meses, 0), count($claves) - 1)];

        $this->cerrar();
        $this->mes = $destino;
    }

    public function irAlMesActual(): void
    {
        $this->cerrar();
        $this->mes = $this->hoy()->format('Y-m');
    }

    public function updatedGrupo(): void
    {
        $this->cerrar();
    }

    public function abrirCelda(int $empleadoId, string $fecha): void
    {
        if (! $this->fechaDelMes($fecha) || $fecha > $this->hoy()->toDateString()) {
            return;
        }

        $this->tarjeta = null;
        $this->celdaFecha = $fecha;
        $this->celdaEmpleado = $empleadoId;
        $this->cargarComentarios();
    }

    public function abrirDia(string $fecha): void
    {
        if (! $this->fechaDelMes($fecha)) {
            return;
        }

        $this->tarjeta = null;
        $this->celdaFecha = $fecha;
        $this->celdaEmpleado = null;
        $this->cargarComentarios();
    }

    public function abrirTarjeta(string $clave): void
    {
        if (! in_array($clave, ['personas', 'tardanzas', 'faltas', 'sin_marcas', 'confirmados', 'por_revisar', 'justificadas'], true)) {
            return;
        }

        $this->cerrar();
        $this->tarjeta = $clave;
    }

    public function cerrarTarjeta(): void
    {
        $this->tarjeta = null;
    }

    public function cerrar(): void
    {
        $this->tarjeta = null;
        $this->celdaFecha = null;
        $this->celdaEmpleado = null;
        $this->comentarioPersona = '';
        $this->comentarioDia = '';
        $this->cancelarJustificacion();
    }

    private function fechaDelMes(string $fecha): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)
            && str_starts_with($fecha, $this->mes.'-')
            && checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))
            && ! Carbon::parse($fecha, self::ZONA)->isSunday();
    }

    private function huellaDe(?int $empleadoId): ?string
    {
        return $empleadoId ? PersonalAsistencia::todos()->first(fn ($r) => $r['empleado']->id === $empleadoId)['empleado']->biometrico_id ?? null : null;
    }

    private function cargarComentarios(): void
    {
        $huella = $this->huellaDe($this->celdaEmpleado);

        $this->comentarioDia = (string) AsistenciaComentarioDia::whereDate('fecha', $this->celdaFecha)->value('comentario');
        $this->comentarioPersona = $huella ? (string) AsistenciaComentario::where('biometrico_id', $huella)->whereDate('fecha', $this->celdaFecha)->value('comentario') : '';
    }

    public function updatedComentarioDia(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        if (! $this->celdaFecha) {
            return;
        }

        $texto = mb_substr(trim($this->comentarioDia), 0, 1000);
        $this->comentarioDia = $texto;

        if ($texto === '') {
            AsistenciaComentarioDia::whereDate('fecha', $this->celdaFecha)->delete();

            return;
        }

        AsistenciaComentarioDia::updateOrCreate(['fecha' => $this->celdaFecha], ['comentario' => $texto, 'user_id' => Auth::id()]);
    }

    public function updatedComentarioPersona(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $huella = $this->huellaDe($this->celdaEmpleado);

        // Solo a quien marcó ese día o estaba en planilla (y por tanto pudo faltar), y que tenga ID de huella.
        $registro = PersonalAsistencia::todos()->first(fn ($r) => $r['empleado']->id === $this->celdaEmpleado);
        $permitido = $huella && $this->celdaFecha && $this->celdaFecha <= $this->hoy()->toDateString() && (
            AsistenciaMarcacion::where('biometrico_id', $huella)->whereDate('marcado_at', $this->celdaFecha)->exists()
            || ($registro && PersonalAsistencia::activoEn($registro, $this->celdaFecha))
        );

        if (! $permitido) {
            $this->cargarComentarios();

            return;
        }

        $texto = mb_substr(trim($this->comentarioPersona), 0, 500);
        $this->comentarioPersona = $texto;

        if ($texto === '') {
            AsistenciaComentario::where('biometrico_id', $huella)->whereDate('fecha', $this->celdaFecha)->delete();

            return;
        }

        AsistenciaComentario::updateOrCreate(['biometrico_id' => $huella, 'fecha' => $this->celdaFecha], ['comentario' => $texto, 'user_id' => Auth::id()]);
    }

    /** Celda del mes con descuento sugerido (tardanza o falta) de esa persona y día, calculada desde cero (no se confía en lo que envía el navegador). */
    private function celdaDescuento(int $empleadoId, string $fecha): ?array
    {
        if (! $this->fechaDelMes($fecha)) {
            return null;
        }

        $fila = $this->matriz()['filas']->firstWhere('id', $empleadoId);
        $celda = $fila['celdas'][$fecha] ?? null;

        return $celda && ! empty($celda['descuento']) ? ['fila' => $fila, 'celda' => $celda, 'descuento' => $celda['descuento']] : null;
    }

    private function borrarSustento(?AsistenciaDescuento $decision): void
    {
        if ($decision?->sustento_ruta) {
            Storage::disk('local')->delete($decision->sustento_ruta);
        }
    }

    private function decidir(int $empleadoId, string $fecha, array $contexto, string $estado, float $monto, array $extra = []): void
    {
        $existente = AsistenciaDescuento::where('empleado_id', $empleadoId)->whereDate('fecha', $fecha)->first();

        // Al cambiar de decisión se descarta el sustento anterior (salvo que la nueva lo traiga).
        if (! array_key_exists('sustento_ruta', $extra)) {
            $this->borrarSustento($existente);
        }

        AsistenciaDescuento::updateOrCreate(
            ['empleado_id' => $empleadoId, 'fecha' => $fecha],
            $extra + [
                'tipo' => $contexto['descuento']['tipo'],
                'tramo' => $contexto['descuento']['tramo'],
                'entrada' => $contexto['celda']['entrada'] ?? null,
                'estado' => $estado,
                'monto' => $monto,
                'comentario' => null,
                'sustento_ruta' => null,
                'sustento_nombre' => null,
                'sustento_mime' => null,
                'decidido_por_id' => Auth::id(),
            ],
        );
    }

    /** Casilla «aplicar descuento»: marcada = confirmado (con el monto de hoy); desmarcada = vuelve a pendiente de revisar. */
    public function aplicarDescuento(int $empleadoId, string $fecha, bool $aplicar): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);
        $this->resetErrorBag('descuento');

        $contexto = $this->celdaDescuento($empleadoId, $fecha);

        if (! $contexto) {
            return;
        }

        if (! $aplicar) {
            if ($contexto['descuento']['estado'] === AsistenciaDescuento::APLICADO) {
                $this->quitarDecision($empleadoId, $fecha);
            }

            return;
        }

        // Un caso descartado no se puede aplicar hasta volver a ponerlo «por revisar».
        if ($contexto['descuento']['estado'] === AsistenciaDescuento::NO_APLICADO) {
            return;
        }

        if ($contexto['descuento']['sugerido'] === null) {
            $this->addError('descuento', 'Registra primero el sueldo de la persona para calcular este descuento.');

            return;
        }

        $this->decidir($empleadoId, $fecha, $contexto, AsistenciaDescuento::APLICADO, $contexto['descuento']['sugerido']);
    }

    /** «No aplicar»: Administración revisó el caso y decidió no descontar. */
    public function descartarDescuento(int $empleadoId, string $fecha): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        if ($contexto = $this->celdaDescuento($empleadoId, $fecha)) {
            $this->decidir($empleadoId, $fecha, $contexto, AsistenciaDescuento::NO_APLICADO, 0);
        }
    }

    /** Quita la decisión (y el sustento, si lo había): el descuento vuelve a quedar sugerido y pendiente de revisar. */
    public function quitarDecision(int $empleadoId, string $fecha): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        if (! $this->fechaDelMes($fecha)) {
            return;
        }

        $decision = AsistenciaDescuento::where('empleado_id', $empleadoId)->whereDate('fecha', $fecha)->first();
        $this->borrarSustento($decision);
        $decision?->delete();
    }

    /** Atajo desde la tabla de descuentos: abre el detalle de la falta con el formulario de justificación. */
    public function justificar(int $empleadoId, string $fecha): void
    {
        $this->abrirCelda($empleadoId, $fecha);

        if ($this->celdaFecha === $fecha) {
            $this->iniciarJustificacion();
        }
    }

    public function iniciarJustificacion(): void
    {
        $this->resetErrorBag();
        $this->reset('sustento', 'justificacionComentario');
        $this->justificando = true;
    }

    public function cancelarJustificacion(): void
    {
        $this->resetErrorBag();
        $this->reset('sustento', 'justificacionComentario');
        $this->justificando = false;
    }

    /** Una falta se justifica con un documento o una fotografía y un breve comentario; la falta justificada no se descuenta. */
    public function guardarJustificacion(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $contexto = $this->celdaEmpleado && $this->celdaFecha ? $this->celdaDescuento($this->celdaEmpleado, $this->celdaFecha) : null;

        if (! $contexto || $contexto['descuento']['tipo'] !== 'falta') {
            return;
        }

        $this->validate([
            'sustento' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'justificacionComentario' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'sustento.required' => 'Adjunta un documento o una fotografía como sustento.',
            'sustento.mimes' => 'El sustento debe ser PDF, imagen (JPG o PNG) o Word.',
            'sustento.max' => 'El sustento no debe pesar más de 10 MB.',
        ], ['justificacionComentario' => 'comentario']);

        $ruta = $this->sustento->store('asistencia-justificaciones/'.$this->celdaEmpleado, 'local');

        $this->borrarSustento(AsistenciaDescuento::where('empleado_id', $this->celdaEmpleado)->whereDate('fecha', $this->celdaFecha)->first());
        $this->decidir($this->celdaEmpleado, $this->celdaFecha, $contexto, AsistenciaDescuento::JUSTIFICADA, 0, [
            'comentario' => trim($this->justificacionComentario),
            'sustento_ruta' => $ruta,
            'sustento_nombre' => $this->sustento->getClientOriginalName(),
            'sustento_mime' => $this->sustento->getMimeType(),
        ]);

        $this->cancelarJustificacion();
    }

    public function abrirSueldo(int $empleadoId): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        if (! PersonalAsistencia::todos()->contains(fn ($r) => $r['empleado']->id === $empleadoId)) {
            return;
        }

        $this->resetErrorBag('sueldoValor');
        $this->sueldoId = $empleadoId;
        $this->sueldoValor = (string) (Empleado::whereKey($empleadoId)->value('contrato_remuneracion') ?? '');
    }

    public function cancelarSueldo(): void
    {
        $this->resetErrorBag('sueldoValor');
        $this->sueldoId = null;
        $this->sueldoValor = '';
    }

    /** El sueldo se guarda en la ficha del trabajador (la misma remuneración de su contrato). */
    public function guardarSueldo(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->validate(['sueldoValor' => ['required', 'numeric', 'min:1', 'max:100000']], [], ['sueldoValor' => 'sueldo']);

        Empleado::whereKey($this->sueldoId)->update(['contrato_remuneracion' => round((float) $this->sueldoValor, 2)]);

        $this->cancelarSueldo();
    }

    /**
     * Matriz completa del mes (todo el personal, sin filtros): una fila por persona en planilla y una celda por día.
     * Estados: marco (entrada y salida; 'tarde' y 'tramo' si pasó la hora límite de un día laborable), falta, sin_dia (día laborable en que nadie marcó: feriado
     * o falla del biométrico; no cuenta como falta), no_lab (día que no se trabaja: sábado sin turno o feriado), vacaciones (días corridos del módulo de Vacaciones), sin_huella (aún no podía marcar: sin ID o antes de su primera marcación;
     * no cuenta como falta), fuera (aún no ingresaba o ya estaba cesada) y futuro.
     * Cada tardanza y cada falta lleva su descuento sugerido y la decisión de Administración (pendiente hasta que la tome).
     */
    private function matriz(): array
    {
        $inicio = $this->inicioDelMes();
        $fin = $inicio->copy()->endOfMonth()->startOfDay();
        $hoy = $this->hoy()->toDateString();
        $d0 = $inicio->toDateString();
        $d1 = $fin->toDateString();

        $dias = [];
        // Los domingos no se trabajan: no se muestran.
        for ($dia = $inicio->copy(); $dia->lte($fin); $dia->addDay()) {
            if (! $dia->isSunday()) {
                $dias[] = $dia->copy();
            }
        }

        $marcaciones = AsistenciaMarcacion::whereDate('marcado_at', '>=', $d0)->whereDate('marcado_at', '<=', $d1)
            ->orderBy('marcado_at')->get(['biometrico_id', 'marcado_at']);
        $porPersonaDia = $marcaciones->groupBy(fn ($m) => $m->biometrico_id.'|'.$m->marcado_at->toDateString());
        $marcasPorDia = $marcaciones->countBy(fn ($m) => $m->marcado_at->toDateString());

        $calendario = CalendarioLaboral::mes($inicio);
        $vacaciones = Vacaciones::mapaDias($d0, $d1);
        $primerasMarcaciones = PersonalAsistencia::primerasMarcaciones();

        $comentariosPersona = AsistenciaComentario::whereDate('fecha', '>=', $d0)->whereDate('fecha', '<=', $d1)->get()
            ->mapWithKeys(fn ($c) => [$c->fecha->toDateString().'|'.$c->biometrico_id => $c->comentario]);
        $comentariosDia = AsistenciaComentarioDia::whereDate('fecha', '>=', $d0)->whereDate('fecha', '<=', $d1)->get()
            ->mapWithKeys(fn ($c) => [$c->fecha->toDateString() => $c->comentario]);
        $decisiones = AsistenciaDescuento::whereDate('fecha', '>=', $d0)->whereDate('fecha', '<=', $d1)->get()
            ->keyBy(fn ($d) => $d->empleado_id.'|'.$d->fecha->toDateString());

        $filas = PersonalAsistencia::todos()
            ->filter(fn ($r) => collect($dias)->contains(fn ($dia) => PersonalAsistencia::activoEn($r, $dia->toDateString())))
            ->map(function (array $r) use ($dias, $hoy, $calendario, $vacaciones, $primerasMarcaciones, $porPersonaDia, $marcasPorDia, $comentariosPersona, $decisiones) {
                $e = $r['empleado'];
                $huella = $e->biometrico_id;
                $sueldo = $e->contrato_remuneracion !== null ? (float) $e->contrato_remuneracion : null;
                $primera = $huella ? $primerasMarcaciones->get($huella) : null;
                $celdas = [];
                $asistio = 0;
                $faltas = 0;
                $tardanzas = 0;
                $confirmado = 0.0;
                $confirmadoTardanzas = 0.0;
                $confirmadoFaltas = 0.0;
                $sugeridoPendiente = 0.0;
                $pendientes = 0;
                $pendientesSinSueldo = 0;

                foreach ($dias as $dia) {
                    $f = $dia->toDateString();
                    $marcas = $huella ? $porPersonaDia->get($huella.'|'.$f) : null;
                    $enVacaciones = $vacaciones[$e->id.'|'.$f] ?? null;
                    $entrada = $marcas?->first()->marcado_at->format('H:i');
                    $tramo = $marcas && $calendario[$f]['limite'] !== null ? DescuentosAsistencia::tramoTardanza($entrada, $dia->isSaturday()) : null;

                    $celda = match (true) {
                        $f > $hoy => $enVacaciones && PersonalAsistencia::activoEn($r, $f) ? ['estado' => 'vacaciones', 'vacaciones' => $enVacaciones] : ['estado' => 'futuro'],
                        ! PersonalAsistencia::activoEn($r, $f) => ['estado' => 'fuera'],
                        (bool) $marcas => [
                            'estado' => 'marco',
                            'entrada' => $entrada,
                            'salida' => $marcas->count() > 1 ? $marcas->last()->marcado_at->format('H:i') : null,
                            'tarde' => $tramo !== null,
                            'tramo' => $tramo,
                        ],
                        // De vacaciones (días corridos): no es falta ni genera descuento.
                        $enVacaciones !== null => ['estado' => 'vacaciones', 'vacaciones' => $enVacaciones],
                        ! $calendario[$f]['laborable'] => ['estado' => 'no_lab'],
                        // Sin huella, o antes de su primera marcación: todavía no podía marcar, no es falta.
                        ! $huella || ($primera !== null && $f < $primera) => ['estado' => 'sin_huella'],
                        ($marcasPorDia[$f] ?? 0) === 0 => ['estado' => 'sin_dia'],
                        default => ['estado' => 'falta'],
                    };

                    $celda['comentario'] = $huella && $comentariosPersona->has($f.'|'.$huella);

                    // Descuento sugerido (solo recomendación) y lo que Administración haya decidido.
                    $sugerencia = match (true) {
                        $celda['estado'] === 'marco' && $tramo !== null => ['tipo' => 'tardanza', 'tramo' => $tramo],
                        $celda['estado'] === 'falta' => ['tipo' => 'falta', 'tramo' => 'completo'],
                        default => null,
                    };

                    if ($sugerencia) {
                        $decision = $decisiones->get($e->id.'|'.$f);
                        $sugerido = DescuentosAsistencia::monto($sugerencia['tramo'], $sueldo);
                        $estadoDescuento = $decision?->estado ?? 'pendiente';

                        // Una tardanza que Administración marcó como «No aplicar» no cuenta como tardanza (la fila sigue en la tabla de descuentos para poder revertirla).
                        if ($sugerencia['tipo'] === 'tardanza' && $estadoDescuento === AsistenciaDescuento::NO_APLICADO) {
                            $celda['tarde'] = false;
                        }

                        $celda['descuento'] = $sugerencia + [
                            'sugerido' => $sugerido,
                            'estado' => $estadoDescuento,
                            'monto' => $decision ? (float) $decision->monto : null,
                            'decision_id' => $decision?->id,
                            'comentario' => $decision?->comentario,
                            'sustento_id' => $decision?->sustento_ruta ? $decision->id : null,
                            'sustento_nombre' => $decision?->sustento_nombre,
                            'sustento_tipo' => $decision?->tipoVista(),
                        ];

                        $aplicado = $estadoDescuento === AsistenciaDescuento::APLICADO ? (float) $decision->monto : 0;
                        $confirmado += $aplicado;
                        $confirmadoTardanzas += $sugerencia['tipo'] === 'tardanza' ? $aplicado : 0;
                        $confirmadoFaltas += $sugerencia['tipo'] === 'falta' ? $aplicado : 0;
                        $pendientes += $estadoDescuento === 'pendiente' ? 1 : 0;
                        $sugeridoPendiente += $estadoDescuento === 'pendiente' ? (float) $sugerido : 0;
                        $pendientesSinSueldo += $estadoDescuento === 'pendiente' && $sugerido === null ? 1 : 0;
                    }

                    $asistio += $celda['estado'] === 'marco' ? 1 : 0;
                    $faltas += $celda['estado'] === 'falta' ? 1 : 0;
                    $tardanzas += ($celda['tarde'] ?? false) ? 1 : 0;
                    $celdas[$f] = $celda;
                }

                return [
                    'id' => $e->id,
                    'nombre' => trim("{$e->apellido_paterno} {$e->apellido_materno}, {$e->nombres}"),
                    'dni' => $e->documento_identidad,
                    'huella' => $huella,
                    'sueldo' => $sueldo,
                    'grupo' => $r['campana'] ?: $r['puestoBase'],
                    'celdas' => $celdas,
                    'asistio' => $asistio,
                    'faltas' => $faltas,
                    'tardanzas' => $tardanzas,
                    'desc_confirmado' => round($confirmado, 2),
                    'desc_tardanzas' => round($confirmadoTardanzas, 2),
                    'desc_faltas' => round($confirmadoFaltas, 2),
                    'desc_por_revisar' => round($sugeridoPendiente, 2),
                    'pendientes' => $pendientes,
                    'pendientes_sin_sueldo' => $pendientesSinSueldo,
                ];
            })
            // Orden alfabético por apellidos y nombres (sin distinguir tildes ni espacios repetidos).
            ->sortBy(fn ($fila) => Str::ascii(mb_strtoupper(preg_replace('/\s+/u', ' ', $fila['nombre']))))
            ->values();

        return compact('dias', 'filas', 'calendario', 'marcasPorDia', 'porPersonaDia', 'comentariosDia', 'hoy');
    }

    public function datos(): array
    {
        ['dias' => $dias, 'filas' => $filas, 'calendario' => $calendario, 'marcasPorDia' => $marcasPorDia, 'porPersonaDia' => $porPersonaDia, 'comentariosDia' => $comentariosDia, 'hoy' => $hoy] = $this->matriz();
        $inicio = $this->inicioDelMes();

        $grupos = $filas->countBy('grupo')->sortKeys();

        $normalizar = fn (string $texto) => Str::ascii(mb_strtolower($texto));
        $palabras = array_filter(preg_split('/\s+/u', $normalizar(trim($this->buscar))));
        $visibles = $filas
            ->when($this->grupo !== '', fn ($c) => $c->where('grupo', $this->grupo))
            ->filter(fn ($fila) => collect($palabras)->every(fn ($p) => str_contains($normalizar($fila['nombre'].' '.$fila['dni']), $p)))
            ->when($this->revision === 'pendientes', fn ($c) => $c->filter(fn ($fila) => $fila['pendientes'] > 0))
            ->values();

        $columnas = collect($dias)->map(function (Carbon $dia) use ($visibles, $marcasPorDia, $comentariosDia, $calendario, $hoy) {
            $f = $dia->toDateString();
            $estados = $visibles->map(fn ($fila) => $fila['celdas'][$f]['estado']);

            return [
                'fecha' => $f,
                'numero' => $dia->day,
                'letra' => mb_strtoupper(mb_substr($dia->copy()->locale('es')->isoFormat('dd'), 0, 1)),
                'laborable' => $calendario[$f]['laborable'],
                'limite' => $calendario[$f]['limite'],
                'feriado' => $calendario[$f]['feriado'],
                'hoy' => $f === $hoy,
                'comentario' => $comentariosDia->get($f),
                'con_marcas' => ($marcasPorDia[$f] ?? 0) > 0,
                'marcaron' => $estados->filter(fn ($e) => $e === 'marco')->count(),
                'tarde' => $visibles->filter(fn ($fila) => $fila['celdas'][$f]['tarde'] ?? false)->count(),
                'en_planilla' => $estados->filter(fn ($e) => in_array($e, ['marco', 'falta', 'sin_dia', 'no_lab'], true))->count(),
            ];
        })->all();

        $asistio = $visibles->sum('asistio');
        $faltas = $visibles->sum('faltas');
        $tardanzas = $visibles->sum('tardanzas');

        // Descuentos sugeridos del mes (uno por tardanza o falta) con su estado, para revisarlos y confirmarlos.
        $descuentos = $visibles->flatMap(fn ($fila) => collect($fila['celdas'])
            ->filter(fn ($celda) => ! empty($celda['descuento']))
            ->map(fn ($celda, $fecha) => [
                'empleado_id' => $fila['id'],
                'nombre' => $fila['nombre'],
                'fecha' => $fecha,
                'fecha_texto' => Carbon::parse($fecha, self::ZONA)->locale('es')->isoFormat('ddd D'),
                'entrada' => $celda['entrada'] ?? null,
                'sueldo' => $fila['sueldo'],
            ] + $celda['descuento'])
            ->values())->values();

        return [
            'mesEtiqueta' => ucfirst($inicio->copy()->locale('es')->isoFormat('MMMM YYYY')),
            'columnas' => $columnas,
            'filas' => $visibles->all(),
            'grupos' => $grupos->all(),
            'totalPersonal' => $filas->count(),
            'asistio' => $asistio,
            'faltas' => $faltas,
            'tardanzas' => $tardanzas,
            'confirmado' => round($visibles->sum('desc_confirmado'), 2),
            'confirmado_tardanzas' => round($visibles->sum('desc_tardanzas'), 2),
            'confirmado_faltas' => round($visibles->sum('desc_faltas'), 2),
            'por_revisar' => round($visibles->sum('desc_por_revisar'), 2),
            'casos_por_revisar' => $visibles->sum('pendientes'),
            'casos_sin_sueldo' => $visibles->sum('pendientes_sin_sueldo'),
            'justificadas' => $descuentos->where('estado', AsistenciaDescuento::JUSTIFICADA)->count(),
            'descuentos' => $descuentos->all(),
            'tarjeta' => $this->tarjeta ? $this->detalleTarjeta($visibles, $descuentos, $columnas, $hoy) : null,
            'sueldoNombre' => $this->sueldoId ? ($filas->firstWhere('id', $this->sueldoId)['nombre'] ?? null) : null,
            'porcentaje' => $asistio + $faltas > 0 ? (int) round($asistio / ($asistio + $faltas) * 100) : null,
            'diasSinMarcas' => collect($columnas)->filter(fn ($c) => $c['laborable'] && $c['fecha'] <= $hoy && ! $c['con_marcas'])->count(),
            'conComentarioDia' => collect($columnas)->whereNotNull('comentario')->count(),
            'modal' => $this->celdaFecha ? $this->modal($filas, $columnas, $porPersonaDia, $hoy) : null,
        ];
    }

    /**
     * Lista de registros detrás de una tarjeta del resumen (los mismos de las cifras, ya con los filtros aplicados).
     *
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array>, total: ?string}|null
     */
    private function detalleTarjeta(Collection $visibles, Collection $descuentos, array $columnas, string $hoy): ?array
    {
        $estado = fn (array $i) => match ($i['estado']) {
            AsistenciaDescuento::APLICADO => ['Se descuenta S/ '.number_format($i['monto'], 2), 'badge-rose'],
            AsistenciaDescuento::JUSTIFICADA => ['Falta justificada', 'badge-green'],
            AsistenciaDescuento::NO_APLICADO => ['No se descuenta', 'badge-slate'],
            default => ['Por revisar', 'bg-yellow-100 text-yellow-800'],
        };
        $fila = fn (array $i, array $cols) => ['cols' => $cols, 'estado' => $estado($i), 'empleado_id' => $i['empleado_id'], 'fecha' => $i['fecha'], 'sustento' => $i['sustento_id'] ?? null];
        $motivo = fn (array $i) => $i['tipo'] === 'falta' ? 'Falta · día completo' : 'Tardanza · entrada '.$i['entrada'].' · '.DescuentosAsistencia::etiquetaTramo($i['tramo']);
        $sugerido = fn (array $i) => $i['sugerido'] !== null ? 'S/ '.number_format($i['sugerido'], 2) : 'falta sueldo';
        $items = fn (callable $filtro) => $descuentos->filter($filtro)->values();

        return match ($this->tarjeta) {
            'personas' => [
                'titulo' => 'Personas en la lista',
                'columnas' => ['Persona', 'Campaña / puesto', 'Asistencias', 'Tardanzas', 'Faltas', 'Descuento confirmado'],
                'total' => null,
                'filas' => $visibles->map(fn ($f) => ['cols' => [$f['nombre'], $f['grupo'], $f['asistio'], $f['tardanzas'], $f['faltas'], 'S/ '.number_format($f['desc_confirmado'], 2)], 'estado' => null, 'empleado_id' => null, 'fecha' => null, 'sustento' => null])->all(),
            ],
            'tardanzas' => [
                'titulo' => 'Tardanzas del mes',
                'columnas' => ['Persona', 'Día', 'Entrada', 'Descuento sugerido', 'Monto'],
                'total' => null,
                'filas' => $items(fn ($i) => $i['tipo'] === 'tardanza' && $i['estado'] !== AsistenciaDescuento::NO_APLICADO)->map(fn ($i) => $fila($i, [$i['nombre'], $i['fecha_texto'], $i['entrada'], DescuentosAsistencia::etiquetaTramo($i['tramo']), $sugerido($i)]))->all(),
            ],
            'faltas' => [
                'titulo' => 'Faltas del mes',
                'columnas' => ['Persona', 'Día', 'Descuento sugerido', 'Monto'],
                'total' => null,
                'filas' => $items(fn ($i) => $i['tipo'] === 'falta')->map(fn ($i) => $fila($i, [$i['nombre'], $i['fecha_texto'], 'Día completo', $sugerido($i)]))->all(),
            ],
            'confirmados' => [
                'titulo' => 'Descuentos confirmados',
                'columnas' => ['Persona', 'Día', 'Motivo', 'Monto'],
                'total' => 'S/ '.number_format($items(fn ($i) => $i['estado'] === AsistenciaDescuento::APLICADO)->sum('monto'), 2),
                'filas' => $items(fn ($i) => $i['estado'] === AsistenciaDescuento::APLICADO)->map(fn ($i) => $fila($i, [$i['nombre'], $i['fecha_texto'], $motivo($i), 'S/ '.number_format($i['monto'], 2)]))->all(),
            ],
            'por_revisar' => [
                'titulo' => 'Descuentos por revisar',
                'columnas' => ['Persona', 'Día', 'Motivo', 'Sugerido'],
                'total' => 'S/ '.number_format($items(fn ($i) => $i['estado'] === 'pendiente')->sum(fn ($i) => (float) $i['sugerido']), 2).' (sin contar los que no tienen sueldo)',
                'filas' => $items(fn ($i) => $i['estado'] === 'pendiente')->map(fn ($i) => $fila($i, [$i['nombre'], $i['fecha_texto'], $motivo($i), $sugerido($i)]))->all(),
            ],
            'justificadas' => [
                'titulo' => 'Faltas justificadas',
                'columnas' => ['Persona', 'Día', 'Justificación'],
                'total' => null,
                'filas' => $items(fn ($i) => $i['estado'] === AsistenciaDescuento::JUSTIFICADA)->map(fn ($i) => $fila($i, [$i['nombre'], $i['fecha_texto'], $i['comentario'] ?: '—']))->all(),
            ],
            'sin_marcas' => [
                'titulo' => 'Días laborables sin marcaciones',
                'columnas' => ['Día', 'Comentario del día'],
                'total' => null,
                'filas' => collect($columnas)
                    ->filter(fn ($c) => $c['laborable'] && $c['fecha'] <= $hoy && ! $c['con_marcas'])
                    ->map(fn ($c) => ['cols' => [Carbon::parse($c['fecha'], self::ZONA)->locale('es')->isoFormat('dddd D [de] MMMM'), $c['comentario'] ?: 'Sin comentario: feriado o falla del biométrico'], 'estado' => null, 'empleado_id' => null, 'fecha' => null, 'dia' => $c['fecha'], 'sustento' => null])
                    ->values()->all(),
            ],
            default => null,
        };
    }

    /** Datos de la ventana: el día (comentario, quiénes faltaron) y, si se tocó una celda, la persona con todas sus marcaciones. */
    private function modal(Collection $filas, array $columnas, Collection $porPersonaDia, string $hoy): array
    {
        $fecha = $this->celdaFecha;
        $columna = collect($columnas)->firstWhere('fecha', $fecha);
        $fila = $this->celdaEmpleado ? $filas->firstWhere('id', $this->celdaEmpleado) : null;
        $celda = $fila['celdas'][$fecha] ?? null;

        return [
            'fecha' => $fecha,
            'etiqueta' => ucfirst(Carbon::parse($fecha, self::ZONA)->locale('es')->isoFormat('dddd D [de] MMMM YYYY')),
            'dia' => [
                'marcaron' => $filas->filter(fn ($f) => ($f['celdas'][$fecha]['estado'] ?? null) === 'marco')->count(),
                'en_planilla' => $filas->filter(fn ($f) => in_array($f['celdas'][$fecha]['estado'] ?? null, ['marco', 'falta', 'sin_dia', 'no_lab'], true))->count(),
                'con_marcas' => $columna['con_marcas'] ?? false,
                'laborable' => $columna['laborable'] ?? true,
                'limite' => $columna['limite'] ?? null,
                'feriado' => $columna['feriado'] ?? null,
                'faltaron' => $filas->filter(fn ($f) => ($f['celdas'][$fecha]['estado'] ?? null) === 'falta')->pluck('nombre')->values()->all(),
                'futuro' => $fecha > $hoy,
            ],
            'persona' => $fila ? [
                'nombre' => $fila['nombre'],
                'grupo' => $fila['grupo'],
                'huella' => $fila['huella'],
                'estado' => $celda['estado'] ?? 'fuera',
                'tarde' => $celda['tarde'] ?? false,
                'entrada' => $celda['entrada'] ?? null,
                'vacaciones' => $celda['vacaciones'] ?? null,
                'descuento' => $celda['descuento'] ?? null,
                'sueldo' => $fila['sueldo'],
                'pago_dia' => DescuentosAsistencia::pagoDia($fila['sueldo']),
                'marcaciones' => $fila['huella'] ? ($porPersonaDia->get($fila['huella'].'|'.$fecha) ?? collect())->map(fn ($m) => $m->marcado_at->format('H:i:s'))->all() : [],
                'puede_comentar' => $fila['huella'] && in_array($celda['estado'] ?? 'fuera', ['marco', 'falta', 'sin_dia'], true),
            ] : null,
        ];
    }
};
?>

<div class="space-y-5">
    @php($d = $this->datos())
    @php($modal = $d['modal'])

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('asistencia') }}" class="btn-secondary w-auto" title="Volver a la asistencia del día">← Asistencia diaria</a>

            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="moverMes(-1)" class="btn-secondary px-2.5" aria-label="Mes anterior">‹</button>
                <select wire:model.live="mes" class="form-input w-auto" aria-label="Mes">
                    @foreach ($this->mesesDisponibles() as $valor => $etiqueta)
                        <option value="{{ $valor }}">{{ $etiqueta }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="moverMes(1)" class="btn-secondary px-2.5" aria-label="Mes siguiente">›</button>
            </div>
            <button type="button" wire:click="irAlMesActual" class="btn-secondary w-auto">Mes actual</button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <select wire:model.live="grupo" class="form-input w-auto" aria-label="Campaña o puesto">
                <option value="">Todas las campañas y puestos ({{ $d['totalPersonal'] }})</option>
                @foreach ($d['grupos'] as $nombre => $cantidad)
                    <option value="{{ $nombre }}">{{ $nombre }} ({{ $cantidad }})</option>
                @endforeach
            </select>
            <select wire:model.live="revision" class="form-input w-auto" aria-label="Descuentos">
                <option value="">Todos los descuentos</option>
                <option value="pendientes">Solo con descuentos por revisar</option>
            </select>
            <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por nombre o DNI" class="form-input w-56" aria-label="Buscar" />
        </div>
    </div>

    {{-- Resumen del mes (cada tarjeta abre la lista de registros que la forma) --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <button type="button" wire:click="abrirTarjeta('personas')" class="stat-tile gth-click">
            <span class="stat-tile-value block">{{ count($d['filas']) }}</span>
            <span class="stat-tile-label block">Personas en la lista</span>
            <span class="mt-1 block text-xs text-slate-400">{{ $d['mesEtiqueta'] }}</span>
        </button>
        <button type="button" wire:click="abrirTarjeta('tardanzas')" class="stat-tile gth-click">
            <span class="stat-tile-value block {{ $d['tardanzas'] > 0 ? 'text-yellow-700' : '' }}">{{ $d['tardanzas'] }}</span>
            <span class="stat-tile-label block">Tardanzas</span>
            <span class="mt-1 block text-xs text-slate-400">entrada después de las 8:40 (sáb. 9:05)</span>
        </button>
        <button type="button" wire:click="abrirTarjeta('faltas')" class="stat-tile gth-click">
            <span class="stat-tile-value block {{ $d['faltas'] > 0 ? 'text-red-600' : '' }}">{{ $d['faltas'] }}</span>
            <span class="stat-tile-label block">Faltas</span>
            <span class="mt-1 block text-xs text-slate-400">{{ $d['porcentaje'] !== null ? $d['porcentaje'].'% de asistencia' : 'sin datos' }}</span>
        </button>
        <button type="button" wire:click="abrirTarjeta('sin_marcas')" class="stat-tile gth-click">
            <span class="stat-tile-value block">{{ $d['diasSinMarcas'] }}</span>
            <span class="stat-tile-label block">Días laborables sin marcas</span>
            <span class="mt-1 block text-xs text-slate-400">feriados o fallas: no cuentan como falta</span>
        </button>
    </div>

    {{-- Descuentos --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
        <button type="button" wire:click="abrirTarjeta('confirmados')" class="stat-tile gth-click">
            <span class="stat-tile-value block {{ $d['confirmado'] > 0 ? 'text-rose-700' : '' }}">S/ {{ number_format($d['confirmado'], 2) }}</span>
            <span class="stat-tile-label block">Descuentos confirmados</span>
            <span class="mt-1 block text-xs text-slate-400">Tardanzas S/ {{ number_format($d['confirmado_tardanzas'], 2) }} · Faltas S/ {{ number_format($d['confirmado_faltas'], 2) }}</span>
        </button>
        <button type="button" wire:click="abrirTarjeta('por_revisar')" class="stat-tile gth-click">
            <span class="stat-tile-value block {{ $d['casos_por_revisar'] > 0 ? 'text-yellow-700' : '' }}">{{ $d['casos_por_revisar'] }} <span class="text-base font-semibold text-slate-400">casos</span></span>
            <span class="stat-tile-label block">Por revisar · S/ {{ number_format($d['por_revisar'], 2) }} sugeridos</span>
            <span class="mt-1 block text-xs {{ $d['casos_sin_sueldo'] > 0 ? 'text-amber-600' : 'text-slate-400' }}">{{ $d['casos_sin_sueldo'] > 0 ? $d['casos_sin_sueldo'].' sin monto: falta registrar el sueldo' : 'todos con monto calculado' }}</span>
        </button>
        <button type="button" wire:click="abrirTarjeta('justificadas')" class="stat-tile gth-click">
            <span class="stat-tile-value block text-emerald-600">{{ $d['justificadas'] }}</span>
            <span class="stat-tile-label block">Faltas justificadas</span>
            <span class="mt-1 block text-xs text-slate-400">con sustento y comentario: no se descuentan</span>
        </button>
    </div>

    <div class="card-panel-compact">
        <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-marco !h-9 !w-11 !text-[10px]"><span class="flex flex-col items-center leading-none"><span>08:30</span><span class="mt-0.5 font-normal opacity-80">17:35</span></span></span> A tiempo (arriba la entrada, abajo la salida)</span>
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-tarde !h-9 !w-11 !text-[10px]"><span class="flex flex-col items-center leading-none"><span>08:55</span><span class="mt-0.5 font-normal opacity-80">17:40</span></span></span> Tarde (L–V después de 8:40 · sáb. después de 9:05)</span>
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-falta !h-5 !w-8">F</span> Faltó</span>
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-sin-dia !h-5 !w-8">·</span> Nadie marcó (feriado o falla)</span>
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-vacaciones !h-5 !w-8">V</span> De vacaciones (días corridos): no es falta</span>
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-sin-huella !h-5 !w-8 !text-[9px]">s/h</span> Aún sin huella (antes de su primera marcación): no es falta</span>
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-no-lab !h-5 !w-8"></span> Sábado que no se trabaja (solo los 2 últimos) o feriado <span class="text-rose-500">●</span></span>
            <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-fuera !h-5 !w-8"></span> Fuera de planilla</span>
            <span class="inline-flex items-center gap-1.5"><span class="text-amber-500">●</span> Tiene comentario</span>
            <span class="text-slate-400">Haz clic en una celda o en un día para ver el detalle y comentar.</span>
        </div>

        @if (empty($d['filas']))
            <p class="py-8 text-center text-sm text-slate-400">No hay personal que coincida con el filtro en este mes.</p>
        @else
            <div class="overflow-x-auto">
                <table class="asis-matriz">
                    <thead>
                        <tr>
                            <th class="asis-nombre">Persona</th>
                            @foreach ($d['columnas'] as $col)
                                <th wire:key="col-{{ $col['fecha'] }}" class="{{ $col['laborable'] ? '' : 'asis-finde' }} {{ $col['hoy'] ? 'asis-hoy' : '' }}">
                                    <button type="button" wire:click="abrirDia('{{ $col['fecha'] }}')" class="asis-dia" title="{{ $col['comentario'] ?: ($col['feriado'] ? 'Feriado: '.$col['feriado'] : ($col['laborable'] ? 'Límite de entrada '.$col['limite'] : 'Día que no se trabaja')) }}">
                                        <span class="block text-[10px] font-medium text-slate-400">{{ $col['letra'] }}</span>
                                        <span class="block">{{ $col['numero'] }}</span>
                                        @if ($col['comentario'])<span class="block text-[10px] text-amber-500" aria-label="Tiene comentario">●</span>@endif
                                        @if ($col['feriado'])<span class="block text-[10px] text-rose-500" aria-label="Feriado">●</span>@endif
                                    </button>
                                </th>
                            @endforeach
                            <th class="px-2 text-center">Asist.</th>
                            <th class="px-2 text-center">Tarde</th>
                            <th class="px-2 text-center">Faltas</th>
                            <th class="px-2 text-center" title="Remuneración mensual (el día vale sueldo ÷ 30)">Sueldo</th>
                            <th class="px-2 text-center" title="Descuentos por tardanzas que Administración confirmó">Desc. tardanzas</th>
                            <th class="px-2 text-center" title="Descuentos por faltas que Administración confirmó">Desc. faltas</th>
                            <th class="px-2 text-center" title="Total de descuentos confirmados de la persona">Total desc.</th>
                            <th class="px-2 text-center" title="Sugerencias que aún no se revisan">Por revisar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['filas'] as $fila)
                            <tr wire:key="fila-{{ $fila['id'] }}">
                                <td class="asis-nombre">
                                    <span class="block font-medium text-slate-800">{{ $fila['nombre'] }}</span>
                                    <span class="block text-[11px] text-slate-400">{{ $fila['grupo'] }}@if (! $fila['huella']) · <span class="text-amber-600">sin ID de huella</span>@endif</span>
                                </td>
                                @foreach ($d['columnas'] as $col)
                                    @php($celda = $fila['celdas'][$col['fecha']])
                                    <td wire:key="c-{{ $fila['id'] }}-{{ $col['fecha'] }}" class="{{ $col['laborable'] ? '' : 'asis-finde' }} p-0.5">
                                        @if (in_array($celda['estado'], ['marco', 'falta', 'sin_dia', 'sin_huella', 'vacaciones'], true))
                                            <button type="button" wire:click="abrirCelda({{ $fila['id'] }}, '{{ $col['fecha'] }}')" class="asis-celda asis-celda-{{ $celda['estado'] === 'marco' && $celda['tarde'] ? 'tarde' : str_replace('_', '-', $celda['estado']) }} relative" title="{{ $celda['estado'] === 'marco' ? ($celda['tarde'] ? 'Llegó tarde (límite '.$col['limite'].') · ' : '').'Entrada '.$celda['entrada'].($celda['salida'] ? ' · salida '.$celda['salida'] : '') : ($celda['estado'] === 'falta' ? 'Faltó' : ($celda['estado'] === 'sin_huella' ? 'Aún sin huella (antes de su primera marcación): no cuenta como falta' : ($celda['estado'] === 'vacaciones' ? 'De vacaciones (del '.\Illuminate\Support\Carbon::parse($celda['vacaciones']['inicio'])->format('d/m').' al '.\Illuminate\Support\Carbon::parse($celda['vacaciones']['fin'])->format('d/m').'): no cuenta como falta' : 'Nadie marcó este día'))) }}">
                                                @if ($celda['estado'] === 'marco')
                                                    <span class="flex flex-col items-center leading-none">
                                                        <span>{{ $celda['entrada'] }}</span>
                                                        <span class="mt-0.5 text-[10px] font-normal opacity-80">{{ $celda['salida'] ?: '—' }}</span>
                                                    </span>
                                                @elseif ($celda['estado'] === 'falta')
                                                    F
                                                @elseif ($celda['estado'] === 'sin_huella')
                                                    <span class="text-[9px] font-medium">s/h</span>
                                                @elseif ($celda['estado'] === 'vacaciones')
                                                    V
                                                @else
                                                    ·
                                                @endif
                                                @if ($celda['comentario'])<span class="absolute right-0.5 top-0 text-[8px] leading-none text-amber-500">●</span>@endif
                                                @if (! empty($celda['descuento']))
                                                    <span class="asis-desc asis-desc-{{ str_replace('_', '-', $celda['descuento']['estado']) }}" title="Descuento sugerido: {{ $celda['descuento']['estado'] === 'pendiente' ? 'por revisar' : str_replace('_', ' ', $celda['descuento']['estado']) }}">{{ $celda['descuento']['estado'] === 'justificada' ? '✓' : ($celda['descuento']['estado'] === 'no_aplicado' ? '–' : 'S/') }}</span>
                                                @endif
                                            </button>
                                        @else
                                            <span class="asis-celda asis-celda-{{ str_replace('_', '-', $celda['estado']) }}"></span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-2 text-center font-semibold text-emerald-700">{{ $fila['asistio'] }}</td>
                                <td class="px-2 text-center font-semibold {{ $fila['tardanzas'] > 0 ? 'text-yellow-700' : 'text-slate-400' }}">{{ $fila['tardanzas'] }}</td>
                                <td class="px-2 text-center font-semibold {{ $fila['faltas'] > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ $fila['faltas'] }}</td>
                                <td class="whitespace-nowrap px-2 text-center">
                                    <button type="button" wire:click="abrirSueldo({{ $fila['id'] }})" class="gth-click-numero {{ $fila['sueldo'] ? 'text-slate-700' : 'text-amber-600' }}" title="{{ $fila['sueldo'] ? 'Cambiar el sueldo' : 'Registrar el sueldo' }}">{{ $fila['sueldo'] ? 'S/ '.number_format($fila['sueldo'], 2) : 'registrar' }}</button>
                                </td>
                                <td class="whitespace-nowrap px-2 text-center {{ $fila['desc_tardanzas'] > 0 ? 'font-semibold text-yellow-700' : 'text-slate-400' }}">S/ {{ number_format($fila['desc_tardanzas'], 2) }}</td>
                                <td class="whitespace-nowrap px-2 text-center {{ $fila['desc_faltas'] > 0 ? 'font-semibold text-red-600' : 'text-slate-400' }}">S/ {{ number_format($fila['desc_faltas'], 2) }}</td>
                                <td class="whitespace-nowrap px-2 text-center font-semibold {{ $fila['desc_confirmado'] > 0 ? 'text-rose-700' : 'text-slate-400' }}">S/ {{ number_format($fila['desc_confirmado'], 2) }}</td>
                                <td class="whitespace-nowrap px-2 text-center text-xs">
                                    @if ($fila['pendientes'] > 0)
                                        <span class="font-semibold text-yellow-700">{{ $fila['pendientes'] }} {{ $fila['pendientes'] === 1 ? 'caso' : 'casos' }}</span>
                                        <span class="block text-[11px] text-slate-400">{{ $fila['desc_por_revisar'] > 0 ? 'S/ '.number_format($fila['desc_por_revisar'], 2) : '' }}{{ $fila['pendientes_sin_sueldo'] > 0 ? ' +'.$fila['pendientes_sin_sueldo'].' sin sueldo' : '' }}</span>
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="asis-nombre text-xs font-semibold text-slate-600">Marcaron / en planilla</td>
                            @foreach ($d['columnas'] as $col)
                                <td class="{{ $col['laborable'] ? '' : 'asis-finde' }} px-0.5 text-center text-[10px] font-semibold text-slate-500">
                                    @if ($col['con_marcas'])
                                        {{ $col['marcaron'] }}/{{ $col['en_planilla'] }}
                                        @if ($col['tarde'] > 0)<span class="block text-yellow-700">{{ $col['tarde'] }} tarde</span>@endif
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-2 text-center text-xs font-bold text-emerald-700">{{ $d['asistio'] }}</td>
                            <td class="px-2 text-center text-xs font-bold {{ $d['tardanzas'] > 0 ? 'text-yellow-700' : 'text-slate-400' }}">{{ $d['tardanzas'] }}</td>
                            <td class="px-2 text-center text-xs font-bold {{ $d['faltas'] > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ $d['faltas'] }}</td>
                            <td></td>
                            <td class="whitespace-nowrap px-2 text-center text-xs font-bold text-yellow-700">S/ {{ number_format($d['confirmado_tardanzas'], 2) }}</td>
                            <td class="whitespace-nowrap px-2 text-center text-xs font-bold text-red-600">S/ {{ number_format($d['confirmado_faltas'], 2) }}</td>
                            <td class="whitespace-nowrap px-2 text-center text-xs font-bold text-rose-700">S/ {{ number_format($d['confirmado'], 2) }}</td>
                            <td class="whitespace-nowrap px-2 text-center text-xs font-bold text-yellow-700">{{ $d['casos_por_revisar'] }} · S/ {{ number_format($d['por_revisar'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    {{-- Descuentos del mes: sugeridos por el sistema, confirmados por Administración --}}
    <div class="card-panel-compact">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h3 class="text-base font-semibold text-slate-900">Descuentos del mes</h3>
                <p class="text-xs text-slate-400">Son solo una recomendación. Marca la casilla para confirmar el descuento, «No aplicar» si no corresponde, o justifica la falta con sustento.</p>
            </div>
            <p class="text-xs text-slate-500">
                <strong>Reglas:</strong> L–V entrada 8:41–8:50 = S/ 5 · 8:51–9:00 = 50% del día · desde 9:01 = día completo · sábado desde 9:06 = día completo · falta = día completo · día = sueldo ÷ 30
            </p>
        </div>

        @error('descuento')
            <p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>
        @enderror

        @if (empty($d['descuentos']))
            <p class="py-6 text-center text-sm text-slate-400">No hay tardanzas ni faltas con descuento sugerido en este mes.</p>
        @else
            <div class="max-h-[32rem] overflow-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Persona</th>
                            <th>Día</th>
                            <th>Motivo</th>
                            <th class="text-right">Sugerido</th>
                            <th class="text-center">Aplicar</th>
                            <th>Acciones</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['descuentos'] as $item)
                            <tr wire:key="desc-{{ $item['empleado_id'] }}-{{ $item['fecha'] }}">
                                <td class="font-medium">{{ $item['nombre'] }}</td>
                                <td class="whitespace-nowrap">
                                    <button type="button" wire:click="abrirCelda({{ $item['empleado_id'] }}, '{{ $item['fecha'] }}')" class="gth-click-numero" title="Ver el detalle del día">{{ $item['fecha_texto'] }}</button>
                                </td>
                                <td>
                                    @if ($item['tipo'] === 'falta')
                                        <span class="badge badge-rose">Falta</span> día completo
                                    @else
                                        <span class="badge bg-yellow-100 text-yellow-800">Tardanza</span> entrada {{ $item['entrada'] }} · {{ \App\Services\DescuentosAsistencia::etiquetaTramo($item['tramo']) }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($item['sugerido'] !== null)
                                        S/ {{ number_format($item['sugerido'], 2) }}
                                    @else
                                        <button type="button" wire:click="abrirSueldo({{ $item['empleado_id'] }})" class="gth-click-numero text-amber-600" title="Falta el sueldo para calcular el monto">falta sueldo</button>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($item['estado'] === 'no_aplicado')
                                        <span class="text-slate-300" title="Descartado: vuelve a «por revisar» para poder aplicarlo">—</span>
                                    @else
                                    <input
                                        type="checkbox"
                                        wire:key="chk-{{ $item['empleado_id'] }}-{{ $item['fecha'] }}-{{ $item['estado'] }}"
                                        wire:change="aplicarDescuento({{ $item['empleado_id'] }}, '{{ $item['fecha'] }}', $event.target.checked)"
                                        @checked($item['estado'] === 'aplicado')
                                        @disabled($item['estado'] === 'justificada' || ($item['sugerido'] === null && $item['estado'] !== 'aplicado'))
                                        class="h-4 w-4 cursor-pointer rounded border-slate-300 text-primary focus:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-40"
                                        aria-label="Aplicar el descuento de {{ $item['nombre'] }} del {{ $item['fecha_texto'] }}"
                                    />
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-xs">
                                    @if ($item['estado'] !== 'no_aplicado' && $item['estado'] !== 'justificada')
                                        <button type="button" wire:click="descartarDescuento({{ $item['empleado_id'] }}, '{{ $item['fecha'] }}')" class="gth-click-numero text-slate-500">No aplicar</button>
                                    @endif
                                    @if ($item['tipo'] === 'falta' && $item['estado'] !== 'justificada')
                                        <button type="button" wire:click="justificar({{ $item['empleado_id'] }}, '{{ $item['fecha'] }}')" class="gth-click-numero ml-2 text-emerald-700">Justificar</button>
                                    @endif
                                    @if ($item['estado'] === 'no_aplicado')
                                        <button type="button" wire:click="quitarDecision({{ $item['empleado_id'] }}, '{{ $item['fecha'] }}')" class="gth-click-numero text-yellow-700">Volver a «por revisar»</button>
                                    @endif
                                    @if (in_array($item['estado'], ['no_aplicado', 'justificada'], true))
                                        <button type="button" wire:click="abrirCelda({{ $item['empleado_id'] }}, '{{ $item['fecha'] }}')" class="gth-click-numero ml-2 text-slate-500">Ver detalle</button>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($item['estado'] === 'aplicado')
                                        <span class="badge badge-rose">Se descuenta S/ {{ number_format($item['monto'], 2) }}</span>
                                    @elseif ($item['estado'] === 'justificada')
                                        <span class="badge badge-green">Falta justificada</span>
                                        @if ($item['sustento_id'])
                                            <a href="{{ route('asistencia.sustento', $item['sustento_id']) }}" target="_blank" rel="noopener" class="ml-1 text-xs text-primary hover:underline">ver sustento</a>
                                        @endif
                                    @elseif ($item['estado'] === 'no_aplicado')
                                        <span class="badge badge-slate">No se descuenta</span>
                                    @else
                                        <span class="badge bg-yellow-100 text-yellow-800">Por revisar</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Detalle de una tarjeta del resumen --}}
    @if ($d['tarjeta'])
        @php($tj = $d['tarjeta'])
        <div class="table-expand-modal" wire:key="asis-tarjeta" wire:click.self="cerrarTarjeta" wire:keydown.escape.window="cerrarTarjeta">
            <div class="gth-detalle-panel !max-w-4xl" role="dialog" aria-modal="true" aria-label="{{ $tj['titulo'] }}">
                <div class="table-expand-modal-header">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">{{ $tj['titulo'] }}</h3>
                        <p class="text-xs text-slate-400">{{ count($tj['filas']) }} {{ count($tj['filas']) === 1 ? 'registro' : 'registros' }} · {{ $d['mesEtiqueta'] }}@if ($grupo !== '') · {{ $grupo }}@endif</p>
                    </div>
                    <button type="button" wire:click="cerrarTarjeta" class="btn-secondary" aria-label="Cerrar">Cerrar ✕</button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4">
                    @if (empty($tj['filas']))
                        <p class="py-10 text-center text-sm text-slate-400">No hay registros para esta tarjeta.</p>
                    @else
                        <table class="data-table rounded-xl bg-white">
                            <thead>
                                <tr>
                                    @foreach ($tj['columnas'] as $columna)
                                        <th>{{ $columna }}</th>
                                    @endforeach
                                    @if ($tj['filas'][0]['estado'])<th>Estado</th>@endif
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tj['filas'] as $i => $fila)
                                    <tr wire:key="tj-{{ $i }}">
                                        @foreach ($fila['cols'] as $k => $valor)
                                            <td class="{{ $k === 0 ? 'font-medium text-slate-800' : '' }} {{ is_string($valor) && mb_strlen($valor) > 40 ? 'max-w-md whitespace-pre-line' : '' }}">{{ $valor }}</td>
                                        @endforeach
                                        @if ($tj['filas'][0]['estado'])
                                            <td class="whitespace-nowrap">
                                                <span class="badge {{ $fila['estado'][1] }}">{{ $fila['estado'][0] }}</span>
                                                @if ($fila['sustento'])
                                                    <a href="{{ route('asistencia.sustento', $fila['sustento']) }}" target="_blank" rel="noopener" class="ml-1 text-xs text-primary hover:underline">ver sustento</a>
                                                @endif
                                            </td>
                                        @endif
                                        <td class="whitespace-nowrap text-right text-xs">
                                            @if ($fila['empleado_id'])
                                                <button type="button" wire:click="abrirCelda({{ $fila['empleado_id'] }}, '{{ $fila['fecha'] }}')" class="gth-click-numero text-primary">Ver el día</button>
                                            @elseif (! empty($fila['dia']))
                                                <button type="button" wire:click="abrirDia('{{ $fila['dia'] }}')" class="gth-click-numero text-primary">Ver el día</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($tj['total'])
                                <tfoot>
                                    <tr class="bg-slate-50 font-semibold">
                                        <td colspan="{{ count($tj['columnas']) + ($tj['filas'][0]['estado'] ? 1 : 0) + 1 }}" class="px-3 py-2 text-right text-slate-700">Total: {{ $tj['total'] }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($modal)
        <div class="table-expand-modal" wire:key="asis-modal" wire:click.self="cerrar" wire:keydown.escape.window="cerrar">
            <div class="gth-detalle-panel !max-w-2xl" role="dialog" aria-modal="true" aria-label="{{ $modal['etiqueta'] }}">
                <div class="table-expand-modal-header">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">{{ $modal['persona']['nombre'] ?? $modal['etiqueta'] }}</h3>
                        <p class="text-xs text-slate-400">{{ $modal['persona'] ? $modal['etiqueta'].' · '.$modal['persona']['grupo'] : 'Detalle del día' }}</p>
                    </div>
                    <button type="button" wire:click="cerrar" class="btn-secondary" aria-label="Cerrar">Cerrar ✕</button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto bg-slate-50 p-4">
                    @if ($modal['persona'])
                        @php($p = $modal['persona'])
                        <section class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="mb-2 flex flex-wrap items-center gap-2">
                                @if ($p['estado'] === 'marco' && ! $p['tarde'] && ($p['descuento']['estado'] ?? null) === 'no_aplicado' && ($p['descuento']['tipo'] ?? null) === 'tardanza')
                                    <span class="badge badge-slate" title="Administración marcó «No aplicar»">Entrada {{ $p['entrada'] }} · la tardanza no aplica</span>
                                @elseif ($p['estado'] === 'marco' && $p['tarde'])
                                    <span class="badge bg-yellow-100 text-yellow-800">Marcó tarde · {{ $p['entrada'] }} (límite {{ $modal['dia']['limite'] }})</span>
                                @elseif ($p['estado'] === 'marco')
                                    <span class="badge badge-green">Marcó a tiempo</span>
                                @elseif ($p['estado'] === 'falta')
                                    <span class="badge badge-rose">Faltó</span>
                                @elseif ($p['estado'] === 'sin_dia')
                                    <span class="badge badge-slate">Nadie marcó este día</span>
                                @elseif ($p['estado'] === 'no_lab')
                                    <span class="badge badge-slate">Día que no se trabaja</span>
                                @elseif ($p['estado'] === 'vacaciones')
                                    <span class="badge bg-sky-100 text-sky-700">De vacaciones del {{ \Illuminate\Support\Carbon::parse($p['vacaciones']['inicio'])->format('d/m') }} al {{ \Illuminate\Support\Carbon::parse($p['vacaciones']['fin'])->format('d/m') }} · no es falta</span>
                                @elseif ($p['estado'] === 'sin_huella')
                                    <span class="badge badge-slate" title="Su huella se empezó a usar después">Aún sin huella · no cuenta como falta</span>
                                @else
                                    <span class="badge badge-slate">Fuera de planilla</span>
                                @endif
                                <span class="text-xs text-slate-400">ID de huella: {{ $p['huella'] ?: 'sin asignar' }}</span>
                            </div>

                            @if ($p['marcaciones'])
                                <p class="text-sm text-slate-700">
                                    <strong>Entrada</strong> {{ $p['marcaciones'][0] }}
                                    · <strong>Salida</strong> {{ count($p['marcaciones']) > 1 ? end($p['marcaciones']) : 'sin marcar' }}
                                </p>
                                <p class="mt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Todas las marcaciones del día ({{ count($p['marcaciones']) }})</p>
                                <p class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ($p['marcaciones'] as $hora)
                                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-sm font-medium text-emerald-700">{{ $hora }}</span>
                                    @endforeach
                                </p>
                            @endif

                            <label for="comentario-persona" class="mb-1 mt-4 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Comentario de la persona</label>
                            @if ($p['puede_comentar'])
                                <textarea id="comentario-persona" wire:model.live.blur="comentarioPersona" rows="2" maxlength="500" placeholder="{{ $p['estado'] === 'falta' ? 'Motivo de la falta (opcional)' : 'Opcional' }}" class="form-input max-h-24 min-h-[3.25rem] resize-y overflow-y-auto text-sm"></textarea>
                                <p class="mt-1 text-[11px] text-slate-400">Se guarda solo al salir de la caja.</p>
                            @else
                                <p class="text-xs text-slate-400">{{ $p['huella'] ? 'No se puede comentar: la persona no estaba en planilla ese día.' : 'Asigna primero su ID de huella para poder comentar.' }}</p>
                            @endif
                        </section>
                    @endif

                    @if ($modal['persona'] && $modal['persona']['descuento'])
                        @php($ds = $modal['persona']['descuento'])
                        <section class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Descuento sugerido</p>
                                @if ($ds['estado'] === 'aplicado')
                                    <span class="badge badge-rose">Se descuenta S/ {{ number_format($ds['monto'], 2) }}</span>
                                @elseif ($ds['estado'] === 'justificada')
                                    <span class="badge badge-green">Falta justificada</span>
                                @elseif ($ds['estado'] === 'no_aplicado')
                                    <span class="badge badge-slate">No se descuenta</span>
                                @else
                                    <span class="badge bg-yellow-100 text-yellow-800">Por revisar</span>
                                @endif
                            </div>

                            <p class="text-sm text-slate-700">
                                @if ($ds['tipo'] === 'falta')
                                    <strong>Falta</strong> · día completo
                                @else
                                    <strong>Tardanza</strong> · entrada {{ $modal['persona']['entrada'] }} · {{ \App\Services\DescuentosAsistencia::etiquetaTramo($ds['tramo']) }}
                                @endif
                                →
                                @if ($ds['sugerido'] !== null)
                                    <strong>S/ {{ number_format($ds['sugerido'], 2) }}</strong>
                                    @if ($ds['tramo'] !== 'fijo')<span class="text-xs text-slate-400">(día = S/ {{ number_format($modal['persona']['pago_dia'], 2) }})</span>@endif
                                @else
                                    <span class="text-amber-600">falta registrar el sueldo para calcular el monto</span>
                                    <button type="button" wire:click="abrirSueldo({{ $celdaEmpleado }})" class="btn-secondary ml-2 w-auto py-1 text-xs">Registrar sueldo</button>
                                @endif
                            </p>
                            @error('descuento')
                                <p class="mt-2 text-sm text-rose-600" role="alert">{{ $message }}</p>
                            @enderror

                            @if ($ds['estado'] === 'justificada')
                                <div class="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">
                                    <p class="whitespace-pre-line">{{ $ds['comentario'] }}</p>
                                    @if ($ds['sustento_id'])
                                        <a href="{{ route('asistencia.sustento', $ds['sustento_id']) }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs font-medium text-primary hover:underline">Ver sustento: {{ $ds['sustento_nombre'] }}</a>
                                    @endif
                                </div>
                                <button type="button" wire:click="quitarDecision({{ $celdaEmpleado }}, '{{ $celdaFecha }}')" wire:confirm="¿Quitar la justificación? La falta volverá a quedar por revisar y se borrará el sustento." class="btn-secondary mt-3 w-auto text-xs">Quitar la justificación</button>
                            @else
                                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2">
                                    @if ($ds['estado'] !== 'no_aplicado')
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                                        <input type="checkbox" wire:key="modal-chk-{{ $ds['estado'] }}" wire:change="aplicarDescuento({{ $celdaEmpleado }}, '{{ $celdaFecha }}', $event.target.checked)" @checked($ds['estado'] === 'aplicado') @disabled($ds['sugerido'] === null && $ds['estado'] !== 'aplicado') class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/40 disabled:opacity-40" />
                                        {{ $ds['tipo'] === 'falta' ? 'Confirmar la falta y aplicar el descuento' : 'Aplicar el descuento' }}
                                    </label>
                                    @endif
                                    @if ($ds['estado'] !== 'no_aplicado')
                                        <button type="button" wire:click="descartarDescuento({{ $celdaEmpleado }}, '{{ $celdaFecha }}')" class="gth-click-numero text-sm text-slate-500">No aplicar</button>
                                    @else
                                        <button type="button" wire:click="quitarDecision({{ $celdaEmpleado }}, '{{ $celdaFecha }}')" class="gth-click-numero text-sm text-slate-500">Volver a «por revisar»</button>
                                    @endif
                                    @if ($ds['tipo'] === 'falta' && ! $justificando)
                                        <button type="button" wire:click="iniciarJustificacion" class="btn-secondary w-auto py-1 text-xs">Justificar la falta</button>
                                    @endif
                                </div>

                                @if ($ds['tipo'] === 'falta' && $justificando)
                                    <form wire:submit="guardarJustificacion" class="mt-4 space-y-3 rounded-lg border border-emerald-200 bg-emerald-50/50 p-3">
                                        <p class="text-sm font-medium text-emerald-900">Justificar la falta: adjunta un documento o una fotografía y escribe un breve comentario.</p>
                                        <div>
                                            <label for="sustento" class="form-label">Sustento (PDF, foto JPG/PNG o Word · máx. 10 MB)</label>
                                            <input id="sustento" type="file" wire:model="sustento" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,image/*" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200" />
                                            <p wire:loading wire:target="sustento" class="mt-1 text-xs text-slate-500">Subiendo el archivo…</p>
                                            @error('sustento') <p class="form-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label for="justificacion-comentario" class="form-label">Comentario</label>
                                            <textarea id="justificacion-comentario" wire:model="justificacionComentario" rows="2" maxlength="500" placeholder="Ej.: cita médica; adjunta el certificado." class="form-input max-h-24 min-h-[3.25rem] resize-y overflow-y-auto text-sm"></textarea>
                                            @error('justificacionComentario') <p class="form-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="submit" class="btn-primary w-auto" wire:loading.attr="disabled" wire:target="guardarJustificacion,sustento">
                                                <span wire:loading.remove wire:target="guardarJustificacion">Guardar justificación</span>
                                                <span wire:loading wire:target="guardarJustificacion">Guardando…</span>
                                            </button>
                                            <button type="button" wire:click="cancelarJustificacion" class="btn-secondary w-auto">Cancelar</button>
                                        </div>
                                    </form>
                                @endif
                            @endif
                        </section>
                    @endif

                    <section class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Día completo</p>
                        <p class="mt-1 text-sm text-slate-700">
                            @if ($modal['dia']['futuro'])
                                Este día todavía no llega.
                            @elseif ($modal['dia']['con_marcas'])
                                Marcaron <strong>{{ $modal['dia']['marcaron'] }}</strong> de <strong>{{ $modal['dia']['en_planilla'] }}</strong> en planilla.
                            @elseif ($modal['dia']['feriado'])
                                Feriado: {{ $modal['dia']['feriado'] }}. No se trabaja y no cuenta como falta.
                            @elseif (! $modal['dia']['laborable'])
                                Día que no se trabaja: no cuenta como falta.
                            @else
                                Nadie marcó este día (feriado o falla del biométrico): no cuenta como falta.
                            @endif
                            <span class="block text-xs text-slate-400">{{ $modal['dia']['laborable'] ? 'Día laborable · límite de entrada '.$modal['dia']['limite'] : ($modal['dia']['feriado'] ? 'Día no laborable (feriado)' : 'Día no laborable (sábado que no se trabaja)') }}</span>
                        </p>
                        @if (! empty($modal['dia']['faltaron']))
                            <p class="mt-2 text-xs text-slate-500">Faltaron ({{ count($modal['dia']['faltaron']) }}): {{ implode(' · ', $modal['dia']['faltaron']) }}</p>
                        @endif

                        <label for="comentario-dia-mensual" class="mb-1 mt-4 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Comentario del día</label>
                        <textarea id="comentario-dia-mensual" wire:model.live.blur="comentarioDia" rows="2" maxlength="1000" placeholder="Ej.: la máquina de marcación no funcionó." class="form-input max-h-24 min-h-[3.25rem] resize-y overflow-y-auto text-sm"></textarea>
                        <p class="mt-1 text-[11px] text-slate-400">Se guarda solo al salir de la caja. Lo ve también GTH en la asistencia del día.</p>
                    </section>
                </div>
            </div>
        </div>
    @endif

    {{-- Sueldo de la persona (base del descuento: sueldo ÷ 30) --}}
    @if ($sueldoId)
        <div class="table-expand-modal" wire:key="asis-sueldo" wire:click.self="cancelarSueldo" wire:keydown.escape.window="cancelarSueldo">
            <div class="gth-detalle-panel !max-w-md" role="dialog" aria-modal="true" aria-label="Registrar sueldo">
                <div class="table-expand-modal-header">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Sueldo mensual</h3>
                        <p class="text-xs text-slate-400">{{ $d['sueldoNombre'] ?? 'Trabajador' }}</p>
                    </div>
                    <button type="button" wire:click="cancelarSueldo" class="btn-secondary" aria-label="Cerrar">Cerrar ✕</button>
                </div>
                <form wire:submit="guardarSueldo" class="space-y-3 bg-slate-50 p-4">
                    <div>
                        <label for="sueldo-valor" class="form-label">Remuneración mensual (S/)</label>
                        <input id="sueldo-valor" type="number" step="0.01" min="1" max="100000" wire:model.live.debounce.400ms="sueldoValor" class="form-input" placeholder="Ej.: 1230.00" autofocus />
                        @error('sueldoValor') <p class="form-error">{{ $message }}</p> @enderror
                        @if (is_numeric($sueldoValor) && (float) $sueldoValor > 0)
                            <p class="mt-1 text-xs text-slate-500">El día vale S/ {{ number_format((float) $sueldoValor / 30, 2) }} y el 50% S/ {{ number_format((float) $sueldoValor / 60, 2) }}.</p>
                        @endif
                    </div>
                    <p class="text-[11px] text-slate-400">Se guarda en la ficha del trabajador (la remuneración de su contrato). Lo ya confirmado no cambia: solo los descuentos por revisar usan el sueldo nuevo.</p>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary w-auto">Guardar sueldo</button>
                        <button type="button" wire:click="cancelarSueldo" class="btn-secondary w-auto">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
