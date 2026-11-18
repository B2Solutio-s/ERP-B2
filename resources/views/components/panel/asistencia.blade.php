<?php

use App\Models\AsistenciaComentario;
use App\Models\AsistenciaComentarioDia;
use App\Models\AsistenciaMarcacion;
use App\Models\Empleado;
use App\Services\CalendarioLaboral;
use App\Services\PersonalAsistencia;
use App\Services\Vacaciones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new #[Layout('layouts.panel', ['title' => 'Asistencia'])] class extends Component
{
    public string $fecha = '';

    /** Comentario del día por ID de biométrico (uno por persona y fecha). @var array<string, string> */
    public array $comentarios = [];

    /** Comentario de todo el día elegido (p. ej. una falla del biométrico); lo edita el administrador y lo ve también GTH. */
    public string $comentarioDia = '';

    public bool $graficoAbierto = false;

    /** Campaña cuyo detalle (quiénes vinieron / faltaron) se está viendo; 'TODAS' = total. */
    public string $campanaDetalle = '';

    /** Qué personas lista la tabla: 'todos', 'marcaron' o 'faltan'. */
    public string $mostrar = 'todos';

    /** Personal del día ya calculado en esta petición (por fecha), para no repetir las consultas. */
    private array $personalMemo = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin() || Auth::user()->esGth(), 403);

        $this->fecha = now('America/Lima')->toDateString();
        $this->cargarComentarios();
    }

    public function updatedFecha(): void
    {
        // GTH solo ve la asistencia de hoy.
        if (! Auth::user()->esAdmin()) {
            $this->fecha = now('America/Lima')->toDateString();
        }

        $this->cargarComentarios();
    }

    private function cargarComentarios(): void
    {
        $this->comentarios = AsistenciaComentario::whereDate('fecha', $this->fecha)->pluck('comentario', 'biometrico_id')->all();
        $this->comentarioDia = (string) AsistenciaComentarioDia::whereDate('fecha', $this->fecha)->value('comentario');
    }

    /**
     * Guarda el comentario de todo el día. Lo llama el navegador al dejar de escribir (ver resources/js/app.js) y no vuelve a pintar
     * la página: así el texto que se está escribiendo nunca se borra por un refresco (p. ej. cuando alguien marca en el biométrico).
     *
     * @return array{ok: bool, mensaje?: string}
     */
    #[Renderless]
    public function guardarComentarioDia(string $fecha, string $texto): array
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        if ($fecha !== $this->fecha) {
            return ['ok' => false, 'mensaje' => 'La fecha cambió. Actualiza la página e inténtalo de nuevo.'];
        }

        $limpio = mb_substr(trim($texto), 0, 1000);
        $this->comentarioDia = $limpio;

        if ($limpio === '') {
            AsistenciaComentarioDia::whereDate('fecha', $this->fecha)->delete();

            return ['ok' => true];
        }

        AsistenciaComentarioDia::updateOrCreate(
            ['fecha' => $this->fecha],
            ['comentario' => $limpio, 'user_id' => Auth::id()],
        );

        return ['ok' => true];
    }

    /** Guarda el comentario de una persona en el día elegido (mismas razones que el del día: sin volver a pintar la página). */
    #[Renderless]
    public function guardarComentario(string $fecha, string $biometricoId, string $texto): array
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        if ($fecha !== $this->fecha) {
            return ['ok' => false, 'mensaje' => 'La fecha cambió. Actualiza la página e inténtalo de nuevo.'];
        }

        // Solo IDs que marcaron ese día (también sirve para huellas sin vincular) o personal que faltó a marcar.
        $permitido = AsistenciaMarcacion::where('biometrico_id', $biometricoId)->whereDate('marcado_at', $this->fecha)->exists()
            || $this->faltanMarcar()->contains('biometrico_id', $biometricoId);

        if (! $permitido) {
            return ['ok' => false, 'mensaje' => 'Esa persona no figura en la asistencia de este día. Actualiza la página.'];
        }

        $limpio = mb_substr(trim($texto), 0, 500);
        $this->comentarios[$biometricoId] = $limpio;

        if ($limpio === '') {
            AsistenciaComentario::where('biometrico_id', $biometricoId)->whereDate('fecha', $this->fecha)->delete();

            return ['ok' => true];
        }

        AsistenciaComentario::updateOrCreate(
            ['biometrico_id' => $biometricoId, 'fecha' => $this->fecha],
            ['comentario' => $limpio, 'user_id' => Auth::id()],
        );

        return ['ok' => true];
    }

    #[On('asistencia-registrada')]
    public function refrescar(): void
    {
        // El re-render de Livewire actualiza la tabla.
    }

    public function alternarResumen(): void
    {
        $this->graficoAbierto = ! $this->graficoAbierto;
        $this->campanaDetalle = '';
    }

    public function seleccionarCampana(string $clave): void
    {
        $this->campanaDetalle = $this->campanaDetalle === $clave ? '' : $clave;
    }

    /**
     * Personal en planilla el día elegido (ver PersonalAsistencia), sin quien aún no usaba su huella, y si ya marcó ese día.
     *
     * @return Collection<int, array{empleado: Empleado, area: string, campana: ?string, puesto: string, puestoBase: string, marca: ?AsistenciaMarcacion}>
     */
    private function personalDelDia(): Collection
    {
        if (isset($this->personalMemo[$this->fecha])) {
            return $this->personalMemo[$this->fecha];
        }

        $primeraMarca = AsistenciaMarcacion::whereDate('marcado_at', $this->fecha)
            ->orderBy('marcado_at')
            ->get(['biometrico_id', 'marcado_at'])
            ->unique('biometrico_id')
            ->keyBy('biometrico_id');

        $primeras = PersonalAsistencia::primerasMarcaciones();
        $laborable = CalendarioLaboral::dia($this->fecha)['laborable'];
        $vacaciones = Vacaciones::mapaDias($this->fecha, $this->fecha);

        return $this->personalMemo[$this->fecha] = PersonalAsistencia::todos()
            ->filter(fn (array $registro) => PersonalAsistencia::activoEn($registro, $this->fecha))
            // Quien todavía no tenía su huella en uso ese día (su primera marcación es posterior) no pudo faltar.
            ->reject(fn (array $registro) => ($primera = $primeras->get($registro['empleado']->biometrico_id)) !== null && $primera > $this->fecha)
            ->map(fn (array $registro) => $registro + ['marca' => $registro['empleado']->biometrico_id ? $primeraMarca->get($registro['empleado']->biometrico_id) : null])
            // Sin marcación: no cuenta si el día no se trabaja (domingo, sábado sin turno o feriado) o si está de vacaciones.
            ->reject(fn (array $registro) => ! $registro['marca'] && (! $laborable || isset($vacaciones[$registro['empleado']->id.'|'.$this->fecha])))
            ->values();
    }

    /** Por qué el día elegido no se trabaja (feriado, domingo o sábado sin turno), o null si es laborable. */
    public function motivoNoLaborable(): ?string
    {
        $dia = CalendarioLaboral::dia($this->fecha);

        return match (true) {
            $dia['laborable'] => null,
            $dia['feriado'] !== null => 'Feriado: '.$dia['feriado'],
            Carbon::parse($this->fecha)->isSunday() => 'domingo',
            default => 'sábado sin turno (solo se trabajan los 2 últimos del mes)',
        };
    }

    /** Personal en planilla que está de vacaciones en la fecha elegida (por orden alfabético). */
    public function deVacaciones(): Collection
    {
        $mapa = Vacaciones::mapaDias($this->fecha, $this->fecha);

        return PersonalAsistencia::todos()
            ->filter(fn (array $r) => PersonalAsistencia::activoEn($r, $this->fecha) && isset($mapa[$r['empleado']->id.'|'.$this->fecha]))
            ->map(fn (array $r) => trim("{$r['empleado']->apellido_paterno} {$r['empleado']->apellido_materno}, {$r['empleado']->nombres}"))
            ->sort()->values();
    }

    /** Personal que todavía no marcó en la fecha elegida (nada si la fecha aún no llega), por orden alfabético. */
    public function faltanMarcar(): Collection
    {
        if ($this->fecha > now('America/Lima')->toDateString()) {
            return collect();
        }

        return $this->personalDelDia()
            ->filter(fn ($registro) => ! $registro['marca'])
            ->map(fn ($registro) => [
                'id' => $registro['empleado']->id,
                'nombre' => trim("{$registro['empleado']->apellido_paterno} {$registro['empleado']->apellido_materno}, {$registro['empleado']->nombres}"),
                'dni' => $registro['empleado']->documento_identidad,
                'biometrico_id' => $registro['empleado']->biometrico_id,
                'detalle' => collect([$registro['campana'], $registro['puesto']])->filter()->unique()->implode(' · '),
            ])
            ->sortBy(fn ($fila) => mb_strtoupper($fila['nombre']))
            ->values();
    }

    public function updatedMostrar(): void
    {
        if (! in_array($this->mostrar, ['todos', 'marcaron', 'faltan'], true)) {
            $this->mostrar = 'todos';
        }
    }

    /**
     * Asistencia del día elegido por campaña: quiénes marcaron y quiénes no (personal con ficha, sin los cesados), según el área y la campaña de Gestión de candidatos.
     * Una sección por área (Comercial, Administrativo…): su total y, debajo, una tarjeta por campaña y otra por puesto para quienes no llevan campaña.
     *
     * @return array<string, array{area: string, total: array, campanas: array, puestos: array}>
     */
    public function resumenCampanas(): array
    {
        $grupos = [];
        // GTH ve quiénes vinieron y quiénes faltan, pero la hora solo en la sección Marcaciones.
        $sinHora = ! Auth::user()->esAdmin();

        foreach ($this->personalDelDia() as $registro) {
            $empleado = $registro['empleado'];
            $marca = $registro['marca'];

            $persona = [
                'nombre' => trim("{$empleado->apellido_paterno} {$empleado->apellido_materno}, {$empleado->nombres}"),
                'puesto' => $registro['puesto'],
                'campana' => $registro['campana'],
                'hora' => $sinHora ? null : $marca?->marcado_at->format('H:i'),
                'sinId' => ! $empleado->biometrico_id,
            ];

            $subgrupo = $registro['campana'] ? 'campanas' : 'puestos';

            $grupos[$registro['area']][$subgrupo][$registro['campana'] ?: $registro['puestoBase']][$marca ? 'vinieron' : 'faltaron'][] = $persona;
        }

        $armar = function (string $clave, string $nombre, array $vinieron, array $faltaron): array {
            $ordenar = fn (array $lista) => collect($lista)->sortBy(fn ($p) => mb_strtoupper($p['nombre']))->values()->all();
            $total = count($vinieron) + count($faltaron);

            return [
                'clave' => $clave,
                'nombre' => $nombre,
                'total' => $total,
                'vinieron' => $ordenar($vinieron),
                'faltaron' => $ordenar($faltaron),
                'porcentaje' => $total > 0 ? (int) round(count($vinieron) / $total * 100) : 0,
            ];
        };

        // Cada área muestra su total; debajo, una tarjeta por campaña y, para los puestos que no llevan campaña, una por puesto.
        $tarjetas = function (string $area, string $tipo, array $subgrupos) use ($armar): array {
            $filas = [];
            foreach ($subgrupos as $nombre => $listas) {
                $filas[] = $armar("{$area}|{$tipo}|{$nombre}", (string) $nombre, $listas['vinieron'] ?? [], $listas['faltaron'] ?? []);
            }
            usort($filas, fn ($a, $b) => [-$a['total'], $a['nombre']] <=> [-$b['total'], $b['nombre']]);

            return $filas;
        };

        $resumen = [];
        foreach ($grupos as $area => $subgrupos) {
            $todos = collect($subgrupos)->flatMap(fn ($porNombre) => $porNombre)->values();

            $resumen[$area] = [
                'area' => $area,
                'total' => $armar($area, $area, $todos->flatMap(fn ($l) => $l['vinieron'] ?? [])->all(), $todos->flatMap(fn ($l) => $l['faltaron'] ?? [])->all()),
                'campanas' => $tarjetas($area, 'C', $subgrupos['campanas'] ?? []),
                'puestos' => $tarjetas($area, 'P', $subgrupos['puestos'] ?? []),
            ];
        }

        $orden = ['COMERCIAL' => 0, 'ADMINISTRATIVO' => 1, 'SIN CLASIFICAR' => 3];
        uasort($resumen, fn ($a, $b) => [$orden[$a['area']] ?? 2, $a['area']] <=> [$orden[$b['area']] ?? 2, $b['area']]);

        return $resumen;
    }

    public function marcaciones()
    {
        return AsistenciaMarcacion::with('empleado:id,biometrico_id,apellido_paterno,apellido_materno,nombres,documento_identidad')
            ->whereDate('marcado_at', $this->fecha)
            ->orderByDesc('marcado_at')
            ->get();
    }

    public function sinVincular()
    {
        return AsistenciaMarcacion::query()
            ->whereNotIn('biometrico_id', Empleado::whereNotNull('biometrico_id')->pluck('biometrico_id'))
            ->select('biometrico_id', DB::raw('COUNT(*) as total'), DB::raw('MAX(marcado_at) as ultima'))
            ->groupBy('biometrico_id')
            ->orderByRaw('CAST(biometrico_id AS UNSIGNED)')
            ->get();
    }

    /** Empleados (ficha completada) que todavía no tienen ID de huella. */
    public function personas()
    {
        return Empleado::whereNull('biometrico_id')
            ->orderBy('apellido_paterno')->orderBy('nombres')
            ->get(['id', 'apellido_paterno', 'apellido_materno', 'nombres', 'documento_identidad']);
    }

    public function vincular(string $biometricoId, string $empleadoId): void
    {
        // Solo el administrador asigna IDs de huella; GTH ve la asistencia pero no las vincula.
        abort_unless(Auth::user()->esAdmin(), 403);

        if ($empleadoId === '' || ! Empleado::whereKey($empleadoId)->exists()) {
            return;
        }

        Empleado::where('biometrico_id', $biometricoId)->update(['biometrico_id' => null]);
        Empleado::whereKey($empleadoId)->update(['biometrico_id' => $biometricoId]);
    }
};
?>

<div class="space-y-5">
    @php($esAdmin = auth()->user()->esAdmin())
    <div class="flex items-center justify-end gap-4">
        <div class="flex items-center gap-3">
            <span class="badge badge-green">● En vivo</span>
            @if ($esAdmin)
                <input type="date" wire:model.live="fecha" class="form-input w-auto" />
            @else
                <span class="text-sm font-medium text-slate-600">Hoy · {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</span>
            @endif
            <button type="button" wire:click="alternarResumen" class="btn-secondary w-auto" aria-expanded="{{ $graficoAbierto ? 'true' : 'false' }}">Resumen gráfico</button>
            @if ($esAdmin)
                <a href="{{ route('asistencia-mensual') }}" class="btn-secondary w-auto" title="Ver la asistencia de todo el mes">Reporte mensual</a>
            @endif
        </div>
    </div>

    @php($motivoNoLab = $this->motivoNoLaborable())
    @php($deVacaciones = $this->deVacaciones())
    @if ($motivoNoLab)
        <div class="rounded-lg border-l-4 border-l-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-700" role="note">
            <strong>Día que no se trabaja</strong> ({{ $motivoNoLab }}): no se cuentan faltas. Si alguien marcó, aparece abajo.
        </div>
    @endif
    @if ($deVacaciones->isNotEmpty())
        <div class="rounded-lg border-l-4 border-l-sky-400 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="note">
            <strong>De vacaciones ({{ $deVacaciones->count() }}):</strong> {{ $deVacaciones->implode(' · ') }}. No cuentan como falta.
        </div>
    @endif

    @if ($esAdmin)
        {{-- wire:ignore: el texto que se escribe no se toca cuando la página se refresca por marcaciones en vivo. Se guarda con resources/js/app.js. --}}
        <div wire:key="comentario-dia-{{ $fecha }}" wire:ignore class="card-panel-compact {{ $comentarioDia !== '' ? 'border-l-4 border-l-amber-400' : '' }}">
            <label for="comentario-dia" class="mb-1 flex flex-wrap items-baseline justify-between gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                <span>Comentario del día · {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</span>
                <span class="flex items-center gap-3">
                    <span data-comentario-estado class="whitespace-nowrap text-[11px] font-semibold normal-case" role="status"></span>
                    <span class="font-normal normal-case text-slate-400">Se guarda solo al dejar de escribir · lo ve también GTH</span>
                </span>
            </label>
            <textarea
                id="comentario-dia"
                data-comentario-dia="{{ $fecha }}"
                rows="2"
                maxlength="1000"
                placeholder="Ej.: la máquina de marcación no funcionó. Nadie pudo marcar este día."
                class="form-input max-h-24 min-h-[3.25rem] resize-y overflow-y-auto text-sm"
            >{{ $comentarioDia }}</textarea>
        </div>
    @elseif ($comentarioDia !== '')
        <div class="rounded-lg border-l-4 border-l-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">Comentario del día · {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</span>
            <p class="mt-0.5 whitespace-pre-line">{{ $comentarioDia }}</p>
        </div>
    @endif

    @if ($graficoAbierto)
        @php($resumen = $this->resumenCampanas())
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-slate-900">Asistencia por área y campaña · {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</h2>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
        @foreach ($resumen as $area => $seccion)
            @php($filas = collect([$seccion['total']])->merge($seccion['campanas'])->merge($seccion['puestos'])->keyBy('clave'))
            <div class="card-panel-compact" wire:key="seccion-{{ $area }}">
                <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-sky-700">Área {{ $area }}</h3>

                <div class="flex flex-wrap gap-2">
                    @foreach ($filas as $fila)
                        @include('partials.asistencia.tarjeta-resumen', ['fila' => $fila])
                    @endforeach
                </div>
                @if ($campanaDetalle !== '' && $filas->has($campanaDetalle))
                    @include('partials.asistencia.detalle-resumen', ['detalle' => $filas->get($campanaDetalle)])
                @endif
            </div>
        @endforeach
        </div>
    @endif

    @php($marcaciones = $this->marcaciones())
    @php($faltan = $this->faltanMarcar())
    @php($verMarcaron = $mostrar !== 'faltan')
    @php($verFaltan = $mostrar !== 'marcaron')

    <div>
        <div class="card-panel-compact">
            <p class="text-sm text-slate-500">Personas que marcaron</p>
            @php($personasQueMarcaron = $marcaciones->pluck('biometrico_id')->unique()->count())
            @php($marcaronPorArea = collect($this->resumenCampanas())->map(fn ($seccion) => count($seccion['total']['vinieron']))->filter(fn ($cantidad, $area) => $cantidad > 0 || in_array($area, ['COMERCIAL', 'ADMINISTRATIVO'], true)))
            @php($sinVincularHoy = $personasQueMarcaron - $marcaronPorArea->sum())
            <div class="mt-1 flex flex-wrap items-center gap-x-6 gap-y-2">
                <p class="text-3xl font-bold text-slate-900">{{ $personasQueMarcaron }}</p>
                <span class="hidden h-9 w-px bg-slate-200 sm:block" aria-hidden="true"></span>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    @foreach ($marcaronPorArea as $area => $cantidad)
                        @unless ($loop->first) <span class="text-sm font-semibold text-slate-300">+</span> @endunless
                        <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $area }}
                            <span class="min-w-9 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-center text-base font-bold normal-case text-slate-900">{{ $cantidad }}</span>
                        </span>
                    @endforeach
                    @if ($sinVincularHoy > 0)
                        <span class="text-sm font-semibold text-slate-300">+</span>
                        <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-amber-700">
                            Sin vincular
                            <span class="min-w-9 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-center text-base font-bold normal-case text-amber-700">{{ $sinVincularHoy }}</span>
                        </span>
                    @endif
                </div>
                @if ($faltan->isNotEmpty())
                    <span class="hidden h-9 w-px bg-slate-200 sm:block" aria-hidden="true"></span>
                    <button type="button" wire:click="$set('mostrar', 'faltan')" class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-rose-700" title="Ver quiénes faltan marcar">
                        Faltan marcar
                        <span class="min-w-9 rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-center text-base font-bold normal-case text-rose-700">{{ $faltan->count() }}</span>
                    </button>
                @endif
            </div>
        </div>
    </div>

    <div class="card-panel-compact">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">Marcaciones</h2>

            <div class="gth-toggle" role="group" aria-label="Qué personas mostrar">
                @foreach (['todos' => 'Todos ('.($personasQueMarcaron + $faltan->count()).')', 'marcaron' => 'Marcaron ('.$personasQueMarcaron.')', 'faltan' => 'Faltan marcar ('.$faltan->count().')'] as $clave => $etiqueta)
                    <button type="button" wire:key="mostrar-{{ $clave }}" wire:click="$set('mostrar', '{{ $clave }}')" class="gth-toggle-btn {{ $mostrar === $clave ? 'gth-toggle-btn-active' : '' }}" aria-pressed="{{ $mostrar === $clave ? 'true' : 'false' }}">{{ $etiqueta }}</button>
                @endforeach
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="data-table asistencia-tabla">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>ID biométrico</th>
                        <th>Empleado</th>
                        <th>DNI</th>
                        <th>Estado</th>
                        @if ($esAdmin) <th>Comentario</th> @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($verMarcaron ? $marcaciones : collect() as $marcacion)
                        <tr wire:key="marcacion-{{ $fecha }}-{{ $marcacion->id }}">
                            <td>{{ $marcacion->marcado_at->format('H:i:s') }}</td>
                            <td>{{ $marcacion->biometrico_id }}</td>
                            <td>
                                @if ($marcacion->empleado)
                                    {{ $marcacion->empleado->apellido_paterno }} {{ $marcacion->empleado->apellido_materno }}, {{ $marcacion->empleado->nombres }}
                                @else
                                    <x-ui.badge color="amber">Sin vincular</x-ui.badge>
                                @endif
                            </td>
                            <td>{{ $marcacion->empleado?->documento_identidad }}</td>
                            <td><span class="badge badge-green">Marcó</span></td>
                            @if ($esAdmin)
                                <td>
                                    <div wire:ignore class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            data-comentario-asistencia="{{ $marcacion->biometrico_id }}"
                                            data-fecha="{{ $fecha }}"
                                            value="{{ $comentarios[$marcacion->biometrico_id] ?? '' }}"
                                            maxlength="500"
                                            placeholder="Opcional"
                                            class="form-input w-64 py-0.5 text-xs"
                                            aria-label="Comentario del día"
                                        />
                                        <span data-comentario-estado class="w-[4.75rem] shrink-0 whitespace-nowrap text-[11px] font-semibold" role="status"></span>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach

                    @foreach ($verFaltan ? $faltan : collect() as $persona)
                        <tr wire:key="falta-{{ $fecha }}-{{ $persona['id'] }}" class="asistencia-fila-falta">
                            <td class="text-slate-400">—</td>
                            <td>
                                @if ($persona['biometrico_id'])
                                    {{ $persona['biometrico_id'] }}
                                @else
                                    <span class="badge badge-amber whitespace-nowrap" title="No puede marcar hasta que se le asigne un ID de huella">Sin ID de huella</span>
                                @endif
                            </td>
                            <td>
                                {{ $persona['nombre'] }}
                                @if ($persona['detalle'])
                                    <span class="ml-1 text-[11px] text-slate-400">· {{ $persona['detalle'] }}</span>
                                @endif
                            </td>
                            <td>{{ $persona['dni'] }}</td>
                            <td><span class="badge badge-rose whitespace-nowrap">Falta marcar</span></td>
                            @if ($esAdmin)
                                <td>
                                    @if ($persona['biometrico_id'])
                                        <div wire:ignore class="flex items-center gap-2">
                                            <input
                                                type="text"
                                                data-comentario-asistencia="{{ $persona['biometrico_id'] }}"
                                                data-fecha="{{ $fecha }}"
                                                value="{{ $comentarios[$persona['biometrico_id']] ?? '' }}"
                                                maxlength="500"
                                                placeholder="Motivo de la falta (opcional)"
                                                class="form-input w-64 py-0.5 text-xs"
                                                aria-label="Comentario del día"
                                            />
                                            <span data-comentario-estado class="w-[4.75rem] shrink-0 whitespace-nowrap text-[11px] font-semibold" role="status"></span>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400" title="El comentario se guarda con el ID de huella de la persona">Asigna primero su ID de huella</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach

                    @if (($verMarcaron ? $marcaciones->isEmpty() : true) && ($verFaltan ? $faltan->isEmpty() : true))
                        <tr>
                            <td colspan="{{ $esAdmin ? 6 : 4 }}" class="py-4 text-center text-slate-400">
                                {{ $mostrar === 'faltan' ? 'Todos marcaron.' : 'Sin marcaciones en esta fecha.' }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    @php($sinVincular = auth()->user()->esAdmin() ? $this->sinVincular() : collect())

    @if ($sinVincular->isNotEmpty())
        <details class="card-panel-compact">
            <summary class="cursor-pointer text-base font-semibold text-slate-900">
                IDs del biométrico sin vincular a una persona ({{ $sinVincular->count() }})
            </summary>
            <p class="mt-2 text-xs text-slate-500">Solo aparecen en la lista las personas que ya completaron su ficha de datos.</p>

            <div class="mt-4 overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID biométrico</th>
                            <th>Marcaciones</th>
                            <th>Última</th>
                            <th>Vincular con</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sinVincular as $fila)
                            <tr wire:key="sin-vincular-{{ $fila->biometrico_id }}">
                                <td>{{ $fila->biometrico_id }}</td>
                                <td>{{ $fila->total }}</td>
                                <td>{{ $fila->ultima }}</td>
                                <td>
                                    <select class="form-input py-1.5 text-xs" wire:change="vincular('{{ $fila->biometrico_id }}', $event.target.value)">
                                        <option value="">Seleccionar persona…</option>
                                        @foreach ($this->personas() as $persona)
                                            <option value="{{ $persona->id }}">{{ $persona->apellido_paterno }} {{ $persona->apellido_materno }}, {{ $persona->nombres }} — {{ $persona->documento_identidad }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
