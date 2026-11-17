<?php

use App\Models\Feriado;
use App\Models\Vacacion;
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

new #[Layout('layouts.panel', ['title' => 'Vacaciones'])] class extends Component
{
    use WithFileUploads;

    private const ZONA = 'America/Lima';

    private const MAX_DIAS_PERIODO = 90;

    /** 'saldos', 'calendario' o 'feriados'. */
    public string $pestana = 'saldos';

    public string $grupo = '';

    public string $buscar = '';

    /** Mes del calendario (Y-m). */
    public string $mes = '';

    /** Persona cuya ficha de vacaciones está abierta y el formulario para registrar un periodo. */
    public ?int $personaId = null;

    public string $inicio = '';

    public string $fin = '';

    public string $comentario = '';

    public $documento = null;

    public string $aviso = '';

    /** Año que se muestra en Feriados y el feriado que se está editando. */
    public int $anio = 0;

    public ?int $feriadoId = null;

    public string $feriadoFecha = '';

    public string $feriadoNombre = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->mes = $this->hoy()->format('Y-m');
        $this->anio = (int) $this->hoy()->format('Y');
    }

    private function hoy(): Carbon
    {
        return now(self::ZONA);
    }

    public function updatedPestana(): void
    {
        $this->cerrarPersona();
        $this->cancelarFeriado();

        if (! in_array($this->pestana, ['saldos', 'calendario', 'feriados'], true)) {
            $this->pestana = 'saldos';
        }
    }

    /** Meses del calendario: desde 6 meses atrás hasta 12 adelante. */
    public function mesesCalendario(): array
    {
        $meses = [];

        for ($mes = $this->hoy()->startOfMonth()->subMonths(6); $mes->lte($this->hoy()->startOfMonth()->addMonths(12)); $mes->addMonthNoOverflow()) {
            $meses[$mes->format('Y-m')] = ucfirst($mes->copy()->locale('es')->isoFormat('MMMM YYYY'));
        }

        return $meses;
    }

    public function updatedMes(): void
    {
        $this->mes = array_key_exists($this->mes, $this->mesesCalendario()) ? $this->mes : $this->hoy()->format('Y-m');
    }

    public function moverMes(int $delta): void
    {
        $claves = array_keys($this->mesesCalendario());
        $actual = array_search($this->mes, $claves, true);
        $this->mes = $claves[min(max(($actual === false ? 6 : $actual) + $delta, 0), count($claves) - 1)];
    }

    // ---------------------------------------------------------------- vacaciones de una persona

    public function abrirPersona(int $id): void
    {
        if (! $this->personal()->contains(fn ($r) => $r['empleado']->id === $id)) {
            return;
        }

        $this->limpiarFormulario();
        $this->personaId = $id;
    }

    public function cerrarPersona(): void
    {
        $this->personaId = null;
        $this->limpiarFormulario();
    }

    private function limpiarFormulario(): void
    {
        $this->resetErrorBag();
        $this->reset('inicio', 'fin', 'comentario', 'documento', 'aviso');
    }

    /** Personal en planilla hoy. */
    private function personal(): Collection
    {
        $hoy = $this->hoy()->toDateString();

        return PersonalAsistencia::todos()->filter(fn ($r) => PersonalAsistencia::activoEn($r, $hoy))->values();
    }

    public function registrar(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $registro = $this->personaId ? $this->personal()->first(fn ($r) => $r['empleado']->id === $this->personaId) : null;

        if (! $registro) {
            return;
        }

        $this->aviso = '';
        $this->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'comentario' => ['nullable', 'string', 'max:500'],
            'documento' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ], [
            'documento.mimes' => 'El documento debe ser PDF, imagen (JPG o PNG) o Word.',
            'documento.max' => 'El documento no debe pesar más de 10 MB.',
        ], ['inicio' => 'fecha de inicio', 'fin' => 'fecha de fin']);

        $dias = Vacaciones::dias($this->inicio, $this->fin);

        if ($dias > self::MAX_DIAS_PERIODO) {
            $this->addError('fin', 'Un periodo no puede pasar de '.self::MAX_DIAS_PERIODO.' días; regístralo en partes.');

            return;
        }

        $elegible = Vacaciones::elegibleDesde($registro['inicio']);
        if ($this->inicio < $elegible->toDateString()) {
            $this->addError('inicio', 'Cumple 3 meses de servicio el '.$elegible->format('d/m/Y').': las vacaciones, incluso adelantadas, pueden empezar desde esa fecha.');

            return;
        }

        if (! PersonalAsistencia::activoEn($registro, $this->inicio)) {
            $this->addError('inicio', 'La persona no estaba en planilla en esa fecha (su contrato empieza o termina fuera del periodo).');

            return;
        }

        $cruce = Vacacion::where('empleado_id', $this->personaId)->where('estado', '!=', Vacacion::CANCELADA)
            ->whereDate('fecha_inicio', '<=', $this->fin)->whereDate('fecha_fin', '>=', $this->inicio)->first();

        if ($cruce) {
            $this->addError('inicio', 'Se cruza con otras vacaciones del '.$cruce->fecha_inicio->format('d/m/Y').' al '.$cruce->fecha_fin->format('d/m/Y').'.');

            return;
        }

        $ruta = $this->documento?->store('vacaciones-documentos/'.$this->personaId, 'local');

        Vacacion::create([
            'empleado_id' => $this->personaId,
            'fecha_inicio' => $this->inicio,
            'fecha_fin' => $this->fin,
            'dias' => $dias,
            'estado' => Vacacion::APROBADA,
            'comentario' => trim($this->comentario) ?: null,
            'documento_ruta' => $ruta,
            'documento_nombre' => $ruta ? $this->documento->getClientOriginalName() : null,
            'documento_mime' => $ruta ? $this->documento->getMimeType() : null,
            'registrado_por_id' => Auth::id(),
        ]);

        $saldo = Vacaciones::saldo($registro['inicio'], Vacacion::where('empleado_id', $this->personaId)->get(), $this->hoy()->toDateString());
        $this->limpiarFormulario();
        $this->aviso = "Vacaciones registradas: {$dias} ".($dias === 1 ? 'día' : 'días').'.'.($saldo['saldo'] < 0 ? ' Incluyen un adelanto de '.rtrim(rtrim(number_format(-$saldo['saldo'], 1), '0'), '.').' días sobre lo ganado hasta hoy.' : '');
    }

    public function cancelarPeriodo(int $id): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        Vacacion::where('empleado_id', $this->personaId)->whereKey($id)->update(['estado' => Vacacion::CANCELADA]);
        $this->aviso = 'Vacaciones canceladas: ya no cuentan en el saldo ni en la asistencia.';
    }

    // ---------------------------------------------------------------- feriados

    public function editarFeriado(int $id): void
    {
        $feriado = Feriado::findOrFail($id);

        $this->resetErrorBag();
        $this->feriadoId = $feriado->id;
        $this->feriadoFecha = $feriado->fecha->toDateString();
        $this->feriadoNombre = $feriado->nombre;
    }

    public function cancelarFeriado(): void
    {
        $this->resetErrorBag();
        $this->reset('feriadoId', 'feriadoFecha', 'feriadoNombre');
    }

    public function guardarFeriado(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        $this->validate([
            'feriadoFecha' => ['required', 'date_format:Y-m-d'],
            'feriadoNombre' => ['required', 'string', 'max:120'],
        ], [], ['feriadoFecha' => 'fecha', 'feriadoNombre' => 'nombre']);

        if (Feriado::where('fecha', $this->feriadoFecha)->where('id', '!=', $this->feriadoId ?? 0)->exists()) {
            $this->addError('feriadoFecha', 'Ya hay un feriado en esa fecha.');

            return;
        }

        Feriado::updateOrCreate(['id' => $this->feriadoId], ['fecha' => $this->feriadoFecha, 'nombre' => trim($this->feriadoNombre)]);

        $this->anio = (int) substr($this->feriadoFecha, 0, 4);
        $this->cancelarFeriado();
    }

    public function eliminarFeriado(int $id): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        Feriado::whereKey($id)->delete();
        $this->cancelarFeriado();
    }

    public function moverAnio(int $delta): void
    {
        $this->anio = min(max($this->anio + $delta, 2020), 2100);
        $this->cancelarFeriado();
    }

    // ---------------------------------------------------------------- datos

    public function datos(): array
    {
        $hoy = $this->hoy()->toDateString();
        $personal = $this->personal();
        $periodos = Vacacion::whereIn('empleado_id', $personal->map(fn ($r) => $r['empleado']->id))->get()->groupBy('empleado_id');

        $filas = $personal->map(function (array $r) use ($periodos, $hoy) {
            $e = $r['empleado'];
            $propios = $periodos->get($e->id, collect());
            $saldo = Vacaciones::saldo($r['inicio'], $propios, $hoy);
            $vigentes = $propios->where('estado', '!=', Vacacion::CANCELADA);
            $enCurso = $vigentes->first(fn ($p) => $p->situacion($hoy) === 'en_curso');
            $proxima = $vigentes->filter(fn ($p) => $p->situacion($hoy) === 'programada')->sortBy('fecha_inicio')->first();

            $estado = match (true) {
                $enCurso !== null => 'en_curso',
                ! $saldo['puede'] => 'sin_derecho',
                $saldo['saldo'] < 0 => 'adelanto',
                default => 'disponible',
            };

            return [
                'id' => $e->id,
                'nombre' => trim("{$e->apellido_paterno} {$e->apellido_materno}, {$e->nombres}"),
                'dni' => $e->documento_identidad,
                'grupo' => $r['campana'] ?: $r['puestoBase'],
                'ingreso' => $r['inicio'],
                'saldo' => $saldo,
                'estado' => $estado,
                'en_curso' => $enCurso,
                'proxima' => $proxima,
            ];
        })->sortBy(fn ($f) => Str::ascii(mb_strtoupper(preg_replace('/\s+/u', ' ', $f['nombre']))))->values();

        $grupos = $filas->countBy('grupo')->sortKeys();
        $normalizar = fn (string $texto) => Str::ascii(mb_strtolower($texto));
        $palabras = array_filter(preg_split('/\s+/u', $normalizar(trim($this->buscar))));
        $visibles = $filas
            ->when($this->grupo !== '', fn ($c) => $c->where('grupo', $this->grupo))
            ->filter(fn ($f) => collect($palabras)->every(fn ($p) => str_contains($normalizar($f['nombre'].' '.$f['dni']), $p)))
            ->values();

        $en30 = Carbon::parse($hoy)->addDays(30)->toDateString();

        return [
            'hoy' => $hoy,
            'filas' => $visibles->all(),
            'grupos' => $grupos->all(),
            'totalPersonal' => $filas->count(),
            'deVacaciones' => $filas->where('estado', 'en_curso')->count(),
            'proximas' => $filas->filter(fn ($f) => $f['proxima'] && $f['proxima']->fecha_inicio->toDateString() <= $en30)->count(),
            'conAdelanto' => $filas->filter(fn ($f) => $f['saldo']['saldo'] < 0)->count(),
            'sinDerecho' => $filas->where('estado', 'sin_derecho')->count(),
            'calendario' => $this->pestana === 'calendario' ? $this->calendario($filas) : null,
            'feriados' => $this->pestana === 'feriados' ? Feriado::whereYear('fecha', $this->anio)->orderBy('fecha')->get() : collect(),
            'persona' => $this->personaId ? $this->persona($filas, $periodos, $hoy) : null,
        ];
    }

    /** Matriz del mes: quién está de vacaciones cada día (días corridos), con feriados y domingos señalados. */
    private function calendario(Collection $filas): array
    {
        $inicio = Carbon::createFromFormat('!Y-m', $this->mes, self::ZONA) ?: $this->hoy()->startOfMonth();
        $fin = $inicio->copy()->endOfMonth()->startOfDay();
        $feriados = Feriado::whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())->get()
            ->mapWithKeys(fn ($f) => [$f->fecha->toDateString() => $f->nombre]);
        $mapa = Vacaciones::mapaDias($inicio->toDateString(), $fin->toDateString());

        $dias = [];
        for ($dia = $inicio->copy(); $dia->lte($fin); $dia->addDay()) {
            $f = $dia->toDateString();
            $dias[] = [
                'fecha' => $f,
                'numero' => $dia->day,
                'letra' => mb_strtoupper(mb_substr($dia->copy()->locale('es')->isoFormat('dd'), 0, 1)),
                'domingo' => $dia->isSunday(),
                'feriado' => $feriados->get($f),
                'hoy' => $f === $this->hoy()->toDateString(),
            ];
        }

        $personas = $filas
            ->when($this->grupo !== '', fn ($c) => $c->where('grupo', $this->grupo))
            ->map(fn ($f) => $f + ['dias_mes' => collect($dias)->filter(fn ($d) => isset($mapa[$f['id'].'|'.$d['fecha']]))->count()])
            ->where('dias_mes', '>', 0)->values();

        return [
            'etiqueta' => ucfirst($inicio->copy()->locale('es')->isoFormat('MMMM YYYY')),
            'dias' => $dias,
            'mapa' => $mapa,
            'personas' => $personas->all(),
            'feriados' => $feriados->all(),
            'porDia' => collect($dias)->mapWithKeys(fn ($d) => [$d['fecha'] => $personas->filter(fn ($p) => isset($mapa[$p['id'].'|'.$d['fecha']]))->count()])->all(),
        ];
    }

    /** Ficha de vacaciones de la persona abierta: saldo, periodos y vista previa del que se está registrando. */
    private function persona(Collection $filas, Collection $periodos, string $hoy): ?array
    {
        $fila = $filas->firstWhere('id', $this->personaId);

        if (! $fila) {
            return null;
        }

        $lista = Vacacion::where('empleado_id', $this->personaId)->orderByDesc('fecha_inicio')->get()
            ->map(fn (Vacacion $p) => [
                'id' => $p->id,
                'inicio' => $p->fecha_inicio->format('d/m/Y'),
                'fin' => $p->fecha_fin->format('d/m/Y'),
                'dias' => $p->dias,
                'situacion' => $p->situacion($hoy),
                'comentario' => $p->comentario,
                'documento_nombre' => $p->documento_nombre,
                'tiene_documento' => (bool) $p->documento_ruta,
            ]);

        $valido = fn (string $f) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && Carbon::hasFormat($f, 'Y-m-d');
        $vista = null;

        if ($valido($this->inicio) && $valido($this->fin) && $this->fin >= $this->inicio) {
            $dias = Vacaciones::dias($this->inicio, $this->fin);
            $despues = round($fila['saldo']['saldo'] - $dias, 1);
            $vista = ['dias' => $dias, 'saldo_despues' => $despues, 'excede' => $dias > self::MAX_DIAS_PERIODO];
        }

        return $fila + ['periodos' => $lista->all(), 'vista' => $vista, 'max_dias' => self::MAX_DIAS_PERIODO];
    }
};
?>

<div class="space-y-5">
    @php($d = $this->datos())
    @php($p = $d['persona'])

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="gth-toggle" role="group" aria-label="Sección">
            @foreach (['saldos' => 'Saldos', 'calendario' => 'Calendario', 'feriados' => 'Feriados'] as $clave => $etiqueta)
                <button type="button" wire:key="pest-{{ $clave }}" wire:click="$set('pestana', '{{ $clave }}')" class="gth-toggle-btn {{ $pestana === $clave ? 'gth-toggle-btn-active' : '' }}" aria-pressed="{{ $pestana === $clave ? 'true' : 'false' }}">{{ $etiqueta }}</button>
            @endforeach
        </div>

        @if ($pestana !== 'feriados')
            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="grupo" class="form-input w-auto" aria-label="Campaña o puesto">
                    <option value="">Todas las campañas y puestos ({{ $d['totalPersonal'] }})</option>
                    @foreach ($d['grupos'] as $nombre => $cantidad)
                        <option value="{{ $nombre }}">{{ $nombre }} ({{ $cantidad }})</option>
                    @endforeach
                </select>
                @if ($pestana === 'saldos')
                    <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por nombre o DNI" class="form-input w-56" aria-label="Buscar" />
                @endif
            </div>
        @endif
    </div>

    {{-- ===================== SALDOS ===================== --}}
    @if ($pestana === 'saldos')
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="stat-tile">
                <span class="stat-tile-value block text-sky-700">{{ $d['deVacaciones'] }}</span>
                <span class="stat-tile-label block">De vacaciones hoy</span>
                <span class="mt-1 block text-xs text-slate-400">no cuentan como falta</span>
            </div>
            <div class="stat-tile">
                <span class="stat-tile-value block">{{ $d['proximas'] }}</span>
                <span class="stat-tile-label block">Salen en los próximos 30 días</span>
                <span class="mt-1 block text-xs text-slate-400">vacaciones ya programadas</span>
            </div>
            <div class="stat-tile">
                <span class="stat-tile-value block {{ $d['conAdelanto'] > 0 ? 'text-amber-600' : '' }}">{{ $d['conAdelanto'] }}</span>
                <span class="stat-tile-label block">Con vacaciones adelantadas</span>
                <span class="mt-1 block text-xs text-slate-400">tomaron más de lo ganado hasta hoy</span>
            </div>
            <div class="stat-tile">
                <span class="stat-tile-value block">{{ $d['sinDerecho'] }}</span>
                <span class="stat-tile-label block">Aún sin 3 meses de servicio</span>
                <span class="mt-1 block text-xs text-slate-400">todavía no pueden programar</span>
            </div>
        </div>

        <div class="card-panel-compact">
            <div class="mb-3">
                <h3 class="text-base font-semibold text-slate-900">Saldo de vacaciones</h3>
                <p class="text-xs text-slate-400">Régimen general: 30 días al año (2.5 por mes de servicio), en días calendario corridos. Se pueden programar como adelanto desde el 3.er mes de servicio.</p>
            </div>

            @if (empty($d['filas']))
                <p class="py-8 text-center text-sm text-slate-400">No hay personal que coincida con el filtro.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Persona</th>
                                <th>Ingreso</th>
                                <th class="text-right">Meses</th>
                                <th class="text-right">Ganados</th>
                                <th class="text-right">Gozados</th>
                                <th class="text-right">Programados</th>
                                <th class="text-right">Saldo</th>
                                <th>Situación</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['filas'] as $fila)
                                @php($sa = $fila['saldo'])
                                <tr wire:key="sal-{{ $fila['id'] }}">
                                    <td>
                                        <button type="button" wire:click="abrirPersona({{ $fila['id'] }})" class="gth-click-numero text-left font-medium text-slate-800">{{ $fila['nombre'] }}</button>
                                        <span class="block text-[11px] text-slate-400">{{ $fila['grupo'] }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">{{ $fila['ingreso']->format('d/m/Y') }}</td>
                                    <td class="text-right">{{ $sa['meses'] }}</td>
                                    <td class="text-right">{{ rtrim(rtrim(number_format($sa['ganados'], 1), '0'), '.') ?: '0' }}</td>
                                    <td class="text-right">{{ $sa['gozados'] }}</td>
                                    <td class="text-right">{{ $sa['programados'] }}</td>
                                    <td class="text-right font-semibold {{ $sa['saldo'] < 0 ? 'text-amber-600' : 'text-slate-800' }}">{{ rtrim(rtrim(number_format($sa['saldo'], 1), '0'), '.') ?: '0' }}</td>
                                    <td class="whitespace-nowrap">
                                        @if ($fila['estado'] === 'en_curso')
                                            <span class="badge bg-sky-100 text-sky-700">De vacaciones hasta {{ $fila['en_curso']->fecha_fin->format('d/m') }}</span>
                                        @elseif ($fila['estado'] === 'sin_derecho')
                                            <span class="badge badge-slate" title="Puede programar desde el 3.er mes de servicio">Desde el {{ $sa['elegible_desde']->format('d/m/Y') }}</span>
                                        @elseif ($fila['estado'] === 'adelanto')
                                            <span class="badge badge-amber">Con adelanto</span>
                                        @else
                                            <span class="badge badge-green">Disponible</span>
                                        @endif
                                        @if ($fila['proxima'])
                                            <span class="ml-1 text-[11px] text-slate-400">próx.: {{ $fila['proxima']->fecha_inicio->format('d/m') }}–{{ $fila['proxima']->fecha_fin->format('d/m') }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        <button type="button" wire:click="abrirPersona({{ $fila['id'] }})" class="btn-secondary w-auto py-1 text-xs">Registrar / ver</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- ===================== CALENDARIO ===================== --}}
    @if ($pestana === 'calendario')
        @php($cal = $d['calendario'])
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="moverMes(-1)" class="btn-secondary px-2.5" aria-label="Mes anterior">‹</button>
                <select wire:model.live="mes" class="form-input w-auto" aria-label="Mes">
                    @foreach ($this->mesesCalendario() as $valor => $etiqueta)
                        <option value="{{ $valor }}">{{ $etiqueta }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="moverMes(1)" class="btn-secondary px-2.5" aria-label="Mes siguiente">›</button>
            </div>
            <span class="text-sm text-slate-500">{{ count($cal['personas']) }} {{ count($cal['personas']) === 1 ? 'persona' : 'personas' }} con vacaciones en {{ $cal['etiqueta'] }}</span>
        </div>

        <div class="card-panel-compact">
            <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-vacaciones !h-5 !w-8">V</span> De vacaciones (días corridos)</span>
                <span class="inline-flex items-center gap-1.5"><span class="asis-celda asis-celda-no-lab !h-5 !w-8"></span> Domingo o feriado</span>
                <span class="text-slate-400">Para registrar o cambiar vacaciones, abre a la persona en «Saldos».</span>
            </div>

            @if (empty($cal['personas']))
                <p class="py-8 text-center text-sm text-slate-400">Nadie tiene vacaciones registradas en este mes.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="asis-matriz">
                        <thead>
                            <tr>
                                <th class="asis-nombre">Persona</th>
                                @foreach ($cal['dias'] as $dia)
                                    <th wire:key="vd-{{ $dia['fecha'] }}" class="{{ $dia['domingo'] || $dia['feriado'] ? 'asis-finde' : '' }} {{ $dia['hoy'] ? 'asis-hoy' : '' }}" title="{{ $dia['feriado'] ?: '' }}">
                                        <span class="asis-dia block cursor-default">
                                            <span class="block text-[10px] font-medium text-slate-400">{{ $dia['letra'] }}</span>
                                            <span class="block">{{ $dia['numero'] }}</span>
                                            @if ($dia['feriado'])<span class="block text-[10px] text-rose-500" aria-label="Feriado">●</span>@endif
                                        </span>
                                    </th>
                                @endforeach
                                <th class="px-2 text-center">Días</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cal['personas'] as $persona)
                                <tr wire:key="vp-{{ $persona['id'] }}">
                                    <td class="asis-nombre">
                                        <button type="button" wire:click="abrirPersona({{ $persona['id'] }})" class="gth-click-numero text-left font-medium text-slate-800">{{ $persona['nombre'] }}</button>
                                        <span class="block text-[11px] text-slate-400">{{ $persona['grupo'] }}</span>
                                    </td>
                                    @foreach ($cal['dias'] as $dia)
                                        <td wire:key="vc-{{ $persona['id'] }}-{{ $dia['fecha'] }}" class="{{ $dia['domingo'] || $dia['feriado'] ? 'asis-finde' : '' }} p-0.5">
                                            @if (isset($cal['mapa'][$persona['id'].'|'.$dia['fecha']]))
                                                <span class="asis-celda asis-celda-vacaciones" title="Vacaciones del {{ \Illuminate\Support\Carbon::parse($cal['mapa'][$persona['id'].'|'.$dia['fecha']]['inicio'])->format('d/m') }} al {{ \Illuminate\Support\Carbon::parse($cal['mapa'][$persona['id'].'|'.$dia['fecha']]['fin'])->format('d/m') }}">V</span>
                                            @else
                                                <span class="asis-celda"></span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-2 text-center font-semibold text-sky-700">{{ $persona['dias_mes'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="asis-nombre text-xs font-semibold text-slate-600">De vacaciones ese día</td>
                                @foreach ($cal['dias'] as $dia)
                                    <td class="{{ $dia['domingo'] || $dia['feriado'] ? 'asis-finde' : '' }} px-0.5 text-center text-[10px] font-semibold text-slate-500">{{ $cal['porDia'][$dia['fecha']] ?: '' }}</td>
                                @endforeach
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- ===================== FERIADOS ===================== --}}
    @if ($pestana === 'feriados')
        <div class="grid gap-5 lg:grid-cols-3">
            <section class="card-panel-compact lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Feriados {{ $anio }}</h3>
                        <p class="text-xs text-slate-400">No se trabajan: no cuentan como falta ni como tardanza en Asistencia. Algunos se mueven por decreto; corrígelos aquí.</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" wire:click="moverAnio(-1)" class="btn-secondary px-2.5" aria-label="Año anterior">‹</button>
                        <span class="min-w-[3.5rem] text-center text-sm font-semibold text-slate-700">{{ $anio }}</span>
                        <button type="button" wire:click="moverAnio(1)" class="btn-secondary px-2.5" aria-label="Año siguiente">›</button>
                    </div>
                </div>

                @if ($d['feriados']->isEmpty())
                    <p class="py-8 text-center text-sm text-slate-400">No hay feriados cargados para {{ $anio }}. Agrégalos con el formulario.</p>
                @else
                    <table class="data-table">
                        <thead>
                            <tr><th>Fecha</th><th>Día</th><th>Feriado</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($d['feriados'] as $feriado)
                                <tr wire:key="fer-{{ $feriado->id }}" class="{{ $feriadoId === $feriado->id ? 'bg-primary/5' : '' }}">
                                    <td class="whitespace-nowrap">{{ $feriado->fecha->format('d/m/Y') }}</td>
                                    <td class="whitespace-nowrap capitalize text-slate-500">{{ $feriado->fecha->copy()->locale('es')->isoFormat('dddd') }}</td>
                                    <td class="font-medium">{{ $feriado->nombre }}</td>
                                    <td class="whitespace-nowrap text-right text-xs">
                                        <button type="button" wire:click="editarFeriado({{ $feriado->id }})" class="gth-click-numero text-primary">Editar</button>
                                        <button type="button" wire:click="eliminarFeriado({{ $feriado->id }})" wire:confirm="¿Eliminar el feriado «{{ $feriado->nombre }}»? Ese día volverá a contar como laborable." class="gth-click-numero ml-2 text-rose-600">Eliminar</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="card-panel-compact h-fit">
                <h3 class="mb-3 text-base font-semibold text-slate-900">{{ $feriadoId ? 'Editar feriado' : 'Agregar feriado' }}</h3>
                <form wire:submit="guardarFeriado" class="space-y-3">
                    <div>
                        <label for="feriado-fecha" class="form-label">Fecha</label>
                        <input id="feriado-fecha" type="date" wire:model="feriadoFecha" class="form-input" />
                        @error('feriadoFecha') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="feriado-nombre" class="form-label">Nombre</label>
                        <input id="feriado-nombre" type="text" wire:model="feriadoNombre" maxlength="120" placeholder="Ej.: Día no laborable por decreto" class="form-input" />
                        @error('feriadoNombre') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary w-auto">{{ $feriadoId ? 'Guardar cambios' : 'Agregar' }}</button>
                        @if ($feriadoId)
                            <button type="button" wire:click="cancelarFeriado" class="btn-secondary w-auto">Cancelar</button>
                        @endif
                    </div>
                </form>
            </section>
        </div>
    @endif

    {{-- ===================== FICHA DE VACACIONES DE UNA PERSONA ===================== --}}
    @if ($p)
        <div class="table-expand-modal" wire:key="vac-persona" wire:click.self="cerrarPersona" wire:keydown.escape.window="cerrarPersona">
            <div class="gth-detalle-panel !max-w-3xl" role="dialog" aria-modal="true" aria-label="Vacaciones de {{ $p['nombre'] }}">
                <div class="table-expand-modal-header">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">{{ $p['nombre'] }}</h3>
                        <p class="text-xs text-slate-400">{{ $p['grupo'] }} · ingresó el {{ $p['ingreso']->format('d/m/Y') }} · {{ $p['saldo']['meses'] }} {{ $p['saldo']['meses'] === 1 ? 'mes' : 'meses' }} de servicio</p>
                    </div>
                    <button type="button" wire:click="cerrarPersona" class="btn-secondary" aria-label="Cerrar">Cerrar ✕</button>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-4">
                    @php($sa = $p['saldo'])
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                        @foreach ([['Ganados', $sa['ganados'], ''], ['Gozados', $sa['gozados'], ''], ['Programados', $sa['programados'], ''], ['Saldo', $sa['saldo'], $sa['saldo'] < 0 ? 'text-amber-600' : 'text-emerald-600']] as [$etiqueta, $valor, $color])
                            <div class="rounded-xl border border-slate-200 bg-white p-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $etiqueta }}</p>
                                <p class="text-xl font-bold {{ $color ?: 'text-slate-800' }}">{{ rtrim(rtrim(number_format($valor, 1), '0'), '.') ?: '0' }} <span class="text-xs font-medium text-slate-400">días</span></p>
                            </div>
                        @endforeach
                    </div>
                    @if (! $sa['puede'])
                        <p class="rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600">Cumple 3 meses de servicio el <strong>{{ $sa['elegible_desde']->format('d/m/Y') }}</strong>: las vacaciones, incluso adelantadas, pueden empezar desde esa fecha.</p>
                    @elseif ($sa['saldo'] < 0)
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Tiene un adelanto de {{ rtrim(rtrim(number_format(-$sa['saldo'], 1), '0'), '.') }} días: ya programó o gozó más de lo ganado hasta hoy.</p>
                    @endif

                    @if ($aviso)
                        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $aviso }}</p>
                    @endif

                    <section class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Periodos registrados</p>
                        @if (empty($p['periodos']))
                            <p class="py-2 text-sm text-slate-400">Todavía no tiene vacaciones registradas.</p>
                        @else
                            <table class="data-table">
                                <thead><tr><th>Del</th><th>Al</th><th class="text-right">Días</th><th>Situación</th><th>Comentario</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($p['periodos'] as $per)
                                        <tr wire:key="per-{{ $per['id'] }}" class="{{ $per['situacion'] === 'cancelada' ? 'opacity-50' : '' }}">
                                            <td class="whitespace-nowrap">{{ $per['inicio'] }}</td>
                                            <td class="whitespace-nowrap">{{ $per['fin'] }}</td>
                                            <td class="text-right">{{ $per['dias'] }}</td>
                                            <td class="whitespace-nowrap">
                                                @if ($per['situacion'] === 'en_curso')
                                                    <span class="badge bg-sky-100 text-sky-700">En curso</span>
                                                @elseif ($per['situacion'] === 'programada')
                                                    <span class="badge badge-amber">Programada</span>
                                                @elseif ($per['situacion'] === 'gozada')
                                                    <span class="badge badge-green">Gozada</span>
                                                @else
                                                    <span class="badge badge-slate">Cancelada</span>
                                                @endif
                                            </td>
                                            <td class="max-w-[14rem] text-xs text-slate-500">
                                                {{ $per['comentario'] ?: '—' }}
                                                @if ($per['tiene_documento'])
                                                    <a href="{{ route('vacaciones.documento', $per['id']) }}" target="_blank" rel="noopener" class="block text-primary hover:underline">ver documento</a>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap text-right text-xs">
                                                @if ($per['situacion'] !== 'cancelada')
                                                    <button type="button" wire:click="cancelarPeriodo({{ $per['id'] }})" wire:confirm="¿Cancelar estas vacaciones? Dejarán de contar en el saldo y en la asistencia." class="gth-click-numero text-rose-600">Cancelar</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Registrar vacaciones</p>
                        <form wire:submit="registrar" class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="vac-inicio" class="form-label">Desde</label>
                                    <input id="vac-inicio" type="date" wire:model.live="inicio" class="form-input" />
                                    @error('inicio') <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="vac-fin" class="form-label">Hasta</label>
                                    <input id="vac-fin" type="date" wire:model.live="fin" class="form-input" />
                                    @error('fin') <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            @if ($p['vista'])
                                <p class="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">
                                    <strong>{{ $p['vista']['dias'] }}</strong> {{ $p['vista']['dias'] === 1 ? 'día' : 'días' }} calendario corridos (incluyen domingos y feriados).
                                    @if ($p['vista']['saldo_despues'] < 0)
                                        <span class="text-amber-700">Quedaría con un adelanto de {{ rtrim(rtrim(number_format(-$p['vista']['saldo_despues'], 1), '0'), '.') }} días sobre lo ganado hasta hoy.</span>
                                    @else
                                        Saldo después: {{ rtrim(rtrim(number_format($p['vista']['saldo_despues'], 1), '0'), '.') ?: '0' }} días.
                                    @endif
                                </p>
                            @endif

                            <div>
                                <label for="vac-comentario" class="form-label">Comentario (opcional)</label>
                                <textarea id="vac-comentario" wire:model="comentario" rows="2" maxlength="500" placeholder="Ej.: vacaciones acordadas con la jefatura." class="form-input max-h-24 min-h-[3.25rem] resize-y overflow-y-auto text-sm"></textarea>
                                @error('comentario') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="vac-documento" class="form-label">Documento (opcional: solicitud firmada, PDF/foto/Word · máx. 10 MB)</label>
                                <input id="vac-documento" type="file" wire:model="documento" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,image/*" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200" />
                                <p wire:loading wire:target="documento" class="mt-1 text-xs text-slate-500">Subiendo el archivo…</p>
                                @error('documento') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn-primary w-auto" wire:loading.attr="disabled" wire:target="registrar,documento">
                                <span wire:loading.remove wire:target="registrar">Registrar vacaciones</span>
                                <span wire:loading wire:target="registrar">Guardando…</span>
                            </button>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    @endif
</div>
