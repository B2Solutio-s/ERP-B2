<?php

use App\Models\CandidatoReclutamiento;
use App\Services\EnvioInvitacionFicha;
use App\Services\GestionCandidatos;
use App\Services\ProcesoCapacitacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Proceso de capacitación: lo que sigue después de la entrevista 1.
 * Ejecutivos: capacitación 1 → capacitación 2 → apto. Resto de puestos: 2.ª entrevista → apto.
 * Admin y GTH; solo Admin quita un apto.
 */
new #[Layout('layouts.panel', ['title' => 'Proceso de capacitación'])] class extends Component
{
    private const ZONA = 'America/Lima';

    private const MAXIMO_LISTA = 80;

    /** 'candidatos' | 'asistencia' */
    public string $vista = 'candidatos';

    public string $buscar = '';

    public string $filtroEstado = '';

    public string $filtroRecorrido = '';

    public string $filtroReclutador = '';

    public ?int $abiertoId = null;

    /** Formularios del panel, por bloque: b.cap1.fecha, b.cap1.asistio… */
    public array $b = [];

    public string $fichaCorreo = '';

    public string $mensaje = '';

    public bool $mensajeError = false;

    // Agendar a varios
    public bool $variosAbierto = false;

    public string $variosBloque = 'cap1';

    public string $variosFecha = '';

    public string $variosHora = '';

    public string $variosBuscar = '';

    /** @var array<int, string> */
    public array $variosSel = [];

    /** @var array<int, string> */
    public array $variosOmitidos = [];

    // Asistencia por fecha
    public string $fechaAsistencia = '';

    /** @var array<string, array{res: string, fecha: string}> */
    public array $asist = [];

    public function mount(): void
    {
        $this->exigirAcceso();
        $this->fechaAsistencia = $this->hoy();
        $this->variosFecha = $this->hoy();

        // Enlace directo (p. ej. desde Gestión de candidatos): ?abrir=ID
        if ((int) request()->query('abrir') > 0) {
            $this->abrir((int) request()->query('abrir'));
        }
    }

    private function exigirAcceso(): void
    {
        abort_unless(Auth::user()?->esAdmin() || Auth::user()?->esGth(), 403);
    }

    private function servicio(): ProcesoCapacitacion
    {
        return app(ProcesoCapacitacion::class);
    }

    private function hoy(): string
    {
        return Carbon::now(self::ZONA)->toDateString();
    }

    private function avisar(string $texto, bool $error = false): void
    {
        $this->mensaje = $texto;
        $this->mensajeError = $error;
    }

    private function sinTildes(?string $texto): string
    {
        return Str::lower(Str::ascii(trim((string) $texto)));
    }

    // ---------------------------------------------------------------
    // Navegación y filtros
    // ---------------------------------------------------------------

    public function elegirVista(string $vista): void
    {
        $this->vista = $vista === 'asistencia' ? 'asistencia' : 'candidatos';
        $this->mensaje = '';
    }

    public function elegirEstado(string $estado): void
    {
        $this->filtroEstado = $this->filtroEstado === $estado ? '' : $estado;
    }

    public function limpiarFiltros(): void
    {
        $this->reset(['buscar', 'filtroEstado', 'filtroRecorrido', 'filtroReclutador']);
    }

    // ---------------------------------------------------------------
    // Panel del candidato
    // ---------------------------------------------------------------

    public function abrir(int $id): void
    {
        $this->exigirAcceso();
        $c = CandidatoReclutamiento::find($id);

        if (! $c || ! $this->servicio()->elegible($c)) {
            return;
        }

        $this->resetErrorBag();
        $this->mensaje = '';
        $this->abiertoId = $id;
        $this->cargarFormularios($c);
        $this->fichaCorreo = (string) ($this->servicio()->ficha($c)['correo'] ?? '');
    }

    public function cerrar(): void
    {
        $this->abiertoId = null;
        $this->b = [];
        $this->resetErrorBag();
    }

    private function cargarFormularios(CandidatoReclutamiento $c): void
    {
        $this->b = [];

        foreach ($this->servicio()->bloques($c) as $bloque) {
            $v = $this->servicio()->valores($c, $bloque);
            $this->b[$bloque] = [
                'fecha' => $v['fecha']?->toDateString() ?? '',
                'hora' => $v['hora'],
                'asistio' => $v['asistio'],
                'fecha_rep' => $v['fecha_rep']?->toDateString() ?? '',
                'asistio_rep' => $v['asistio_rep'],
                'obs' => $v['obs'],
                'obs_rep' => $v['obs_rep'],
            ];
        }
    }

    public function guardarBloque(string $bloque): void
    {
        $this->exigirAcceso();

        if (! $this->abiertoId || ! isset($this->b[$bloque])) {
            return;
        }

        $this->resetErrorBag();
        $respuesta = $this->servicio()->guardarBloque($this->abiertoId, $bloque, $this->b[$bloque]);

        foreach ($respuesta['errores'] as $campo => $texto) {
            $this->addError("b.{$bloque}.{$campo}", $texto);
        }

        $c = CandidatoReclutamiento::find($this->abiertoId);

        if ($c) {
            // Se recarga siempre: lo que el sistema limpió (cascada) o rechazó debe verse tal como quedó.
            if ($respuesta['ok']) {
                $this->cargarFormularios($c);
                $this->avisar('Guardado: '.ProcesoCapacitacion::BLOQUES[$bloque]['nombre'].'.');
            } else {
                $this->avisar('No se guardó '.mb_strtolower(ProcesoCapacitacion::BLOQUES[$bloque]['nombre']).': revisa los avisos en rojo.', true);
            }
        }
    }

    public function marcarApto(): void
    {
        $this->exigirAcceso();
        $respuesta = $this->servicio()->marcarApto((int) $this->abiertoId);
        $this->avisar($respuesta['mensaje'], ! $respuesta['ok']);
    }

    public function quitarApto(): void
    {
        $this->exigirAcceso();
        $respuesta = $this->servicio()->quitarApto((int) $this->abiertoId, Auth::user());
        $this->avisar($respuesta['mensaje'], ! $respuesta['ok']);

        $c = CandidatoReclutamiento::find($this->abiertoId);
        if ($c) {
            $this->cargarFormularios($c);
        }
    }

    public function enviarFicha(): void
    {
        $this->exigirAcceso();
        $c = CandidatoReclutamiento::find($this->abiertoId);

        if (! $c || ! $this->servicio()->puedeEnviarFicha($c)) {
            $this->avisar('Primero debe tener fecha el primer paso del proceso.', true);

            return;
        }

        if (! EnvioInvitacionFicha::configurado()) {
            $this->addError('fichaCorreo', 'El envío de correos todavía no está configurado en el servidor.');

            return;
        }

        $this->fichaCorreo = mb_strtolower(trim($this->fichaCorreo));
        $this->resetErrorBag('fichaCorreo');
        $this->validate(['fichaCorreo' => 'required|email:rfc|max:150'], [], ['fichaCorreo' => 'correo']);

        // El correo personal queda en el candidato y precarga el campo «Correo electrónico» de su ficha.
        $c->update(['email' => $this->fichaCorreo]);

        $motivo = app(EnvioInvitacionFicha::class)->encolar($c, $this->fichaCorreo, 'ingreso');

        if ($motivo) {
            $this->addError('fichaCorreo', "No se envió: {$motivo}.");

            return;
        }

        $this->avisar('Ficha en cola de envío a '.$this->fichaCorreo.'.');
    }

    // ---------------------------------------------------------------
    // Agendar a varios
    // ---------------------------------------------------------------

    public function abrirVarios(): void
    {
        $this->exigirAcceso();
        $this->variosAbierto = true;
        $this->variosSel = [];
        $this->variosOmitidos = [];
        $this->variosBuscar = '';
        $this->mensaje = '';
    }

    public function cerrarVarios(): void
    {
        $this->variosAbierto = false;
        $this->variosSel = [];
        $this->variosOmitidos = [];
    }

    public function updatedVariosBloque(): void
    {
        $this->variosSel = [];
        $this->variosOmitidos = [];
    }

    public function marcarTodosVarios(): void
    {
        $this->variosSel = collect($this->candidatosParaAgendar())->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function agendarVarios(): void
    {
        $this->exigirAcceso();
        $ids = collect($this->variosSel)->map(fn ($id) => (int) $id)->all();

        if (! $ids) {
            $this->avisar('Marca al menos un candidato.', true);

            return;
        }

        $r = $this->servicio()->agendarVarios($ids, $this->variosBloque, $this->variosFecha, $this->variosHora);
        $this->variosSel = [];
        $this->variosOmitidos = $r['omitidos'];

        $this->avisar(
            $r['agendados'] > 0
                ? $r['agendados'].($r['agendados'] === 1 ? ' candidato agendado' : ' candidatos agendados').' para el '.Carbon::parse($this->variosFecha)->format('d/m/Y').'.'
                : 'No se agendó a nadie.',
            $r['agendados'] === 0,
        );
    }

    /** @return array<int, array<string, mixed>> candidatos con ese paso habilitado y sin agendar */
    private function candidatosParaAgendar(): array
    {
        $servicio = $this->servicio();
        $buscar = $this->sinTildes($this->variosBuscar);

        return $this->elegibles()
            ->filter(fn ($c) => in_array($this->variosBloque, $servicio->bloques($c), true)
                && ! $c->apto
                && $servicio->habilitado($c, $this->variosBloque)
                && $servicio->resultado($c, $this->variosBloque) === 'sin_agendar')
            ->filter(fn ($c) => $buscar === '' || str_contains($this->sinTildes($c->nombreCompleto().' '.$c->dni_ce), $buscar))
            ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto() ?: 'Sin nombre', 'puesto' => $c->puesto, 'reclutador' => $c->agente_reclutador])
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------
    // Asistencia por fecha
    // ---------------------------------------------------------------

    public function cambiarDia(int $dias): void
    {
        $this->fechaAsistencia = Carbon::parse($this->fechaAsistencia ?: $this->hoy())->addDays($dias)->toDateString();
        $this->asist = [];
        $this->mensaje = '';
    }

    public function hoyAsistencia(): void
    {
        $this->fechaAsistencia = $this->hoy();
        $this->asist = [];
        $this->mensaje = '';
    }

    public function updatedFechaAsistencia(): void
    {
        $this->asist = [];
        $this->mensaje = '';
    }

    public function guardarAsistencia(string $clave): void
    {
        $this->exigirAcceso();
        [$id, $bloque, $tipo] = array_pad(explode('|', $clave), 3, '');
        $fila = $this->asist[$clave] ?? ['res' => '', 'fecha' => ''];

        if ($fila['res'] === '') {
            $this->avisar('Elige el resultado antes de guardar.', true);

            return;
        }

        $r = $this->servicio()->marcarAsistencia((int) $id, $bloque, $tipo, $fila['res'], $fila['fecha'] ?: null);

        if ($r['ok']) {
            unset($this->asist[$clave]);
            $this->avisar('Asistencia guardada.');

            return;
        }

        $this->avisar(collect($r['errores'])->first() ?: 'No se pudo guardar.', true);
    }

    /** Marca «Asistió · apto» a todos los de la fecha que aún no tienen resultado (solo desde el día de la sesión). */
    public function marcarTodosAsistieron(): void
    {
        $this->exigirAcceso();
        $hechos = 0;
        $fallos = [];

        foreach ($this->servicio()->asistenciaPorFecha($this->fechaAsistencia) as $e) {
            if (! $e['habilitado'] || $e['resultado'] !== '') {
                continue;
            }

            $r = $this->servicio()->marcarAsistencia($e['id'], $e['bloque'], $e['tipo'], 'SI, APTO');
            $r['ok'] ? $hechos++ : $fallos[] = $e['candidato']->nombreCompleto();
        }

        $this->asist = [];
        $this->avisar(
            $hechos > 0 ? "{$hechos} marcados como «Asistió · apto»".($fallos ? '; '.count($fallos).' no se pudieron marcar.' : '.') : 'No había asistencias pendientes de marcar.',
            $hechos === 0,
        );
    }

    // ---------------------------------------------------------------
    // Datos
    // ---------------------------------------------------------------

    /** Quienes entran al módulo: entrevista 1 aprobada, con fechas del proceso o ya aptos. */
    private function elegibles()
    {
        return CandidatoReclutamiento::query()
            ->where(function ($q) {
                $q->where('apto', true)
                    ->orWhere('asistio_entrevista', 'SI, APTO')
                    ->orWhere(fn ($z) => $z->where('asistio_entrevista', 'REPROGRAMADO')->where('asistio_entrevista_reprogramada', 'SI, APTO'))
                    ->orWhereNotNull('capacitacion_1')
                    ->orWhereNotNull('capacitacion_2')
                    ->orWhereNotNull('entrevista_2');
            })
            ->get()
            ->filter(fn ($c) => $this->servicio()->elegible($c))
            ->values();
    }

    public function with(): array
    {
        $servicio = $this->servicio();
        $hoy = $this->hoy();
        $buscar = $this->sinTildes($this->buscar);

        $todos = $this->elegibles()->map(fn ($c) => [
            'c' => $c,
            'estado' => $servicio->estado($c),
            'pasos' => $servicio->pasos($c),
            'recorrido' => $servicio->recorrido($c),
        ]);

        $reclutadores = $todos->pluck('c.agente_reclutador')->map(fn ($n) => trim((string) $n))->filter()->unique()->sort()->values();

        // Filtros que no son el estado: las cifras de arriba se calculan sobre esto.
        $base = $todos
            ->filter(fn ($f) => $this->filtroRecorrido === '' || $f['recorrido'] === $this->filtroRecorrido)
            ->filter(fn ($f) => $this->filtroReclutador === '' || trim((string) $f['c']->agente_reclutador) === $this->filtroReclutador)
            ->filter(fn ($f) => $buscar === '' || str_contains($this->sinTildes($f['c']->nombreCompleto().' '.$f['c']->dni_ce.' '.$f['c']->numero_celular), $buscar))
            ->values();

        $conteos = $base->countBy(fn ($f) => $f['estado']['clave']);

        $tarjetas = [
            ['clave' => '', 'nombre' => 'Todos', 'total' => $base->count(), 'nota' => 'En el proceso'],
            ['clave' => 'por_agendar', 'nombre' => 'Por agendar', 'total' => $conteos->get('por_agendar', 0), 'nota' => 'Sin fecha'],
            ['clave' => 'hoy', 'nombre' => 'Hoy', 'total' => $conteos->get('hoy', 0), 'nota' => 'Sesión de hoy'],
            ['clave' => 'pendiente_resultado', 'nombre' => 'Marcar asistencia', 'total' => $conteos->get('pendiente_resultado', 0), 'nota' => 'Fecha pasada sin resultado'],
            ['clave' => 'agendado', 'nombre' => 'Agendados', 'total' => $conteos->get('agendado', 0), 'nota' => 'Próximas sesiones'],
            ['clave' => 'listo_apto', 'nombre' => 'Listos para apto', 'total' => $conteos->get('listo_apto', 0), 'nota' => 'Aprobaron todo'],
            ['clave' => 'apto', 'nombre' => 'Aptos', 'total' => $conteos->get('apto', 0), 'nota' => 'Pasan a su ficha'],
            ['clave' => 'no_continua', 'nombre' => 'No continúan', 'total' => $conteos->get('no_continua', 0), 'nota' => 'No apto o no asistió'],
        ];

        $filtradas = $base
            ->filter(fn ($f) => $this->filtroEstado === '' || $f['estado']['clave'] === $this->filtroEstado)
            ->sort(function ($a, $b) {
                $porOrden = $a['estado']['orden'] <=> $b['estado']['orden'];

                if ($porOrden !== 0) {
                    return $porOrden;
                }

                // Aptos: el más reciente primero; el resto, por nombre.
                if ($a['estado']['clave'] === 'apto') {
                    return ($b['c']->fecha_apto?->timestamp ?? 0) <=> ($a['c']->fecha_apto?->timestamp ?? 0);
                }

                return strcmp($this->sinTildes($a['c']->nombreCompleto()), $this->sinTildes($b['c']->nombreCompleto()));
            })
            ->values();

        $actual = $this->abiertoId ? CandidatoReclutamiento::find($this->abiertoId) : null;
        $panel = null;

        if ($actual) {
            $bloques = collect($servicio->bloques($actual))->map(function (string $k) use ($actual, $servicio, $hoy) {
                $def = ProcesoCapacitacion::BLOQUES[$k];
                $form = $this->b[$k] ?? [];
                $resultado = $servicio->resultado($actual, $k);

                return [
                    'clave' => $k,
                    'def' => $def,
                    'habilitado' => $servicio->habilitado($actual, $k),
                    'resultado' => $resultado,
                    'fechaFutura' => ($form['fecha'] ?? '') !== '' && $form['fecha'] > $hoy,
                    'repFutura' => ($form['fecha_rep'] ?? '') !== '' && $form['fecha_rep'] > $hoy,
                ];
            })->all();

            $panel = [
                'c' => $actual,
                'estado' => $servicio->estado($actual),
                'pasos' => $servicio->pasos($actual),
                'recorrido' => $servicio->recorrido($actual),
                'bloques' => $bloques,
                'puedeApto' => $servicio->puedeMarcarApto($actual),
                'ficha' => $servicio->ficha($actual),
                'puedeFicha' => $servicio->puedeEnviarFicha($actual),
                'fichaConfigurada' => EnvioInvitacionFicha::configurado(),
                'historial' => app(GestionCandidatos::class)->historial($actual->id),
            ];
        }

        $asistencia = $this->vista === 'asistencia' ? $servicio->asistenciaPorFecha($this->fechaAsistencia) : collect();

        return [
            'tarjetas' => $tarjetas,
            'lista' => $filtradas->take(self::MAXIMO_LISTA),
            'totalLista' => $filtradas->count(),
            'prioritarios' => $todos->filter(fn ($f) => in_array($f['estado']['clave'], ['hoy', 'pendiente_resultado', 'listo_apto', 'por_agendar'], true))
                ->sortBy(fn ($f) => $f['estado']['orden'])->values()->take(6),
            'prioritariosTotal' => $todos->filter(fn ($f) => in_array($f['estado']['clave'], ['hoy', 'pendiente_resultado', 'listo_apto', 'por_agendar'], true))->count(),
            'reclutadores' => $reclutadores,
            'hayFiltros' => $this->buscar !== '' || $this->filtroEstado !== '' || $this->filtroRecorrido !== '' || $this->filtroReclutador !== '',
            'panel' => $panel,
            'hoy' => $hoy,
            'asistencia' => $asistencia,
            'resumenAsistencia' => [
                'convocados' => $asistencia->count(),
                'apto' => $asistencia->where('resultado', 'SI, APTO')->count(),
                'no_apto' => $asistencia->where('resultado', 'SI, NO APTO')->count(),
                'no' => $asistencia->where('resultado', 'NO')->count(),
                'reprogramado' => $asistencia->where('resultado', 'REPROGRAMADO')->count(),
                'pendientes' => $asistencia->where('resultado', '')->count(),
            ],
            'paraAgendar' => $this->variosAbierto ? $this->candidatosParaAgendar() : [],
            'esAdmin' => (bool) Auth::user()?->esAdmin(),
            'opcionesResultado' => ['SI, APTO' => 'Asistió · apto', 'SI, NO APTO' => 'Asistió · no apto', 'NO' => 'No asistió', 'REPROGRAMADO' => 'Reprogramar'],
            'opcionesReprogramada' => ['SI, APTO' => 'Asistió · apto', 'SI, NO APTO' => 'Asistió · no apto', 'NO' => 'No asistió'],
        ];
    }
};
?>

<div class="space-y-5" wire:key="proceso-capacitacion">
    @if (! $panel && ! $variosAbierto && $vista === 'candidatos')
        <div wire:poll.30s class="hidden" aria-hidden="true"></div>
    @endif

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-center gap-3">
        <span class="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="group" aria-label="Vista">
            <button type="button" wire:click="elegirVista('candidatos')" class="rounded-md px-3 py-1 font-semibold {{ $vista === 'candidatos' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500' }}" aria-pressed="{{ $vista === 'candidatos' ? 'true' : 'false' }}">Candidatos</button>
            <button type="button" wire:click="elegirVista('asistencia')" class="rounded-md px-3 py-1 font-semibold {{ $vista === 'asistencia' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500' }}" aria-pressed="{{ $vista === 'asistencia' ? 'true' : 'false' }}">Asistencia por fecha</button>
        </span>
        <p class="min-w-0 flex-1 text-sm text-slate-500">
            Después de la entrevista 1: <b class="text-slate-700">ejecutivos</b> pasan por capacitación 1 y 2; <b class="text-slate-700">los demás puestos</b>, por una 2.ª entrevista.
        </p>
        <button type="button" wire:click="abrirVarios" class="gc-btn gc-btn-primary">
            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" /></svg>
            Agendar a varios
        </button>
    </div>

    @if ($mensaje !== '' && ! $panel)
        <div class="gc-aviso {{ $mensajeError ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-800' }}" role="status">{{ $mensaje }}</div>
    @endif

    @if ($vista === 'candidatos')
        {{-- Cifras --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
            @foreach ($tarjetas as $t)
                <button type="button" wire:key="tarjeta-{{ $t['clave'] ?: 'todos' }}" wire:click="elegirEstado('{{ $t['clave'] }}')" class="gc-etapa {{ $filtroEstado === $t['clave'] ? 'gc-etapa-activa' : '' }}" aria-pressed="{{ $filtroEstado === $t['clave'] ? 'true' : 'false' }}">
                    <span class="block text-xs font-semibold text-slate-500">{{ $t['nombre'] }}</span>
                    <span class="mt-1 block text-2xl font-bold text-slate-900">{{ $t['total'] }}</span>
                    <span class="block truncate text-xs text-slate-400">{{ $t['nota'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- Filtros --}}
        <div class="gc-filtros">
            <input type="search" wire:model.live.debounce.300ms="buscar" class="form-input min-w-[16rem] flex-1" placeholder="Buscar por nombre, DNI o celular" aria-label="Buscar candidato">
            <select wire:model.live="filtroRecorrido" class="form-input" aria-label="Recorrido">
                <option value="">Recorrido: todos</option>
                <option value="capacitacion">Capacitación (ejecutivos)</option>
                <option value="entrevista_2">2.ª entrevista (demás puestos)</option>
            </select>
            <select wire:model.live="filtroReclutador" class="form-input" aria-label="Reclutador">
                <option value="">Reclutador: todos</option>
                @foreach ($reclutadores as $nombre)
                    <option value="{{ $nombre }}">{{ $nombre }}</option>
                @endforeach
            </select>
            @if ($hayFiltros)
                <button type="button" wire:click="limpiarFiltros" class="text-sm font-semibold text-primary">Limpiar filtros</button>
            @endif
        </div>

        {{-- Requieren acción --}}
        @if ($prioritariosTotal > 0 && $filtroEstado === '' && $buscar === '')
            <section class="gc-atencion" aria-label="Requieren acción">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-bold text-amber-900">Requieren acción ({{ $prioritariosTotal }})</h2>
                    <span class="text-xs text-amber-800">Sesiones de hoy, asistencias por marcar, listos para apto y pasos sin fecha.</span>
                </div>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($prioritarios as $p)
                        <button type="button" wire:key="prio-{{ $p['c']->id }}" wire:click="abrir({{ $p['c']->id }})" class="gc-atencion-item">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-900">{{ $p['c']->nombreCompleto() ?: 'Sin nombre' }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $p['c']->puesto ?: 'Sin puesto' }}</span>
                            </span>
                            <span class="badge badge-{{ $p['estado']['tono'] }} shrink-0">{{ $p['estado']['texto'] }}</span>
                        </button>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Lista --}}
        <div class="gc-lista">
            @forelse ($lista as $f)
                @php($c = $f['c'])
                @php($e = $f['estado'])
                <button type="button" wire:key="cand-{{ $c->id }}" wire:click="abrir({{ $c->id }})" class="gc-card {{ $e['clave'] === 'apto' ? 'gc-card-apto' : '' }} {{ $e['orden'] < 1 ? 'gc-card-accion' : '' }}">
                    <span class="gc-avatar {{ $e['clave'] === 'apto' ? 'gc-av-apto' : ($e['clave'] === 'no_continua' ? 'gc-av-no_continua' : 'gc-av-capacitacion') }}" aria-hidden="true">{{ Str::upper(Str::substr((string) $c->nombres, 0, 1).Str::substr((string) $c->apellidos, 0, 1)) ?: '?' }}</span>

                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-slate-900">{{ $c->nombreCompleto() ?: 'Sin nombre' }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ collect([$c->dni_ce ? 'DNI '.$c->dni_ce : null, $c->numero_celular])->filter()->implode(' · ') ?: 'Sin datos de contacto' }}</span>
                    </span>

                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-slate-700">{{ $c->puesto ?: 'Sin puesto' }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ collect([$c->campana && $c->campana !== 'NO APLICA' ? $c->campana : null, $c->agente_reclutador])->filter()->implode(' · ') }}</span>
                    </span>

                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                            @foreach ($f['pasos'] as $paso)
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold {{ ['ok' => 'text-emerald-700', 'actual' => 'text-amber-700', 'no' => 'text-rose-600', 'pendiente' => 'text-slate-400'][$paso['estado']] }}" title="{{ $paso['nombre'] }}">
                                    <span class="h-2 w-2 rounded-full {{ ['ok' => 'bg-emerald-500', 'actual' => 'bg-amber-400', 'no' => 'bg-rose-500', 'pendiente' => 'bg-slate-300'][$paso['estado']] }}"></span>{{ $paso['clave'] === 'ent1' ? 'Ent. 1' : ($paso['clave'] === 'apto' ? 'Apto' : \App\Services\ProcesoCapacitacion::BLOQUES[$paso['clave']]['corto']) }}
                                </span>
                            @endforeach
                        </span>
                        <span class="mt-1 block text-xs text-slate-500">{{ $f['recorrido'] === 'capacitacion' ? 'Capacitación' : '2.ª entrevista' }}</span>
                    </span>

                    <span class="min-w-0">
                        <span class="badge badge-{{ $e['tono'] }}">{{ $e['texto'] }}</span>
                        @if ($e['detalle'] !== '')
                            <span class="mt-1 block truncate text-xs text-slate-400">{{ $e['detalle'] }}</span>
                        @endif
                    </span>
                </button>
            @empty
                <div class="rounded-2xl bg-white px-6 py-12 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-100">
                    No hay candidatos con estos filtros.
                </div>
            @endforelse
        </div>

        @if ($totalLista > $lista->count())
            <p class="text-center text-sm text-slate-500">Mostrando {{ $lista->count() }} de {{ $totalLista }}: usa los filtros para acotar.</p>
        @endif
    @else
        {{-- Asistencia por fecha --}}
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="cambiarDia(-1)" class="gc-btn gc-btn-ghost" aria-label="Día anterior">‹</button>
            <input type="date" wire:model.live="fechaAsistencia" class="form-input w-auto" aria-label="Fecha de la sesión">
            <button type="button" wire:click="cambiarDia(1)" class="gc-btn gc-btn-ghost" aria-label="Día siguiente">›</button>
            <button type="button" wire:click="hoyAsistencia" class="gc-btn gc-btn-ghost">Hoy</button>
            <span class="text-sm font-semibold text-slate-700">{{ $fechaAsistencia ? \Illuminate\Support\Carbon::parse($fechaAsistencia)->locale('es')->isoFormat('dddd D [de] MMMM') : '' }}</span>
            @if ($resumenAsistencia['pendientes'] > 0 && $fechaAsistencia <= $hoy)
                <button type="button" wire:click="marcarTodosAsistieron" wire:confirm="Se marcará «Asistió · apto» a los {{ $resumenAsistencia['pendientes'] }} que aún no tienen resultado. ¿Continuar?" class="gc-btn gc-btn-ghost ml-auto">Marcar a todos «Asistió · apto» ({{ $resumenAsistencia['pendientes'] }})</button>
            @endif
        </div>

        <div class="flex flex-wrap gap-2 text-xs">
            <span class="badge badge-slate">{{ $resumenAsistencia['convocados'] }} convocados</span>
            <span class="badge badge-green">{{ $resumenAsistencia['apto'] }} asistieron · apto</span>
            <span class="badge badge-rose">{{ $resumenAsistencia['no_apto'] }} no apto</span>
            <span class="badge badge-rose">{{ $resumenAsistencia['no'] }} no asistieron</span>
            <span class="badge badge-amber">{{ $resumenAsistencia['reprogramado'] }} reprogramados</span>
            <span class="badge badge-blue">{{ $resumenAsistencia['pendientes'] }} sin marcar</span>
        </div>

        @if ($fechaAsistencia > $hoy)
            <div class="gc-aviso bg-slate-50 text-slate-600">La asistencia se marca desde el día de la sesión; en una fecha futura solo se puede reprogramar.</div>
        @endif

        <div class="gc-lista">
            @forelse ($asistencia as $a)
                @php($ac = $a['candidato'])
                @php($fila = $asist[$a['clave']] ?? ['res' => '', 'fecha' => ''])
                <div wire:key="asis-{{ $a['clave'] }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl bg-surface px-4 py-3 shadow-sm ring-1 ring-slate-100">
                    <button type="button" wire:click="abrir({{ $ac->id }})" class="min-w-[14rem] flex-1 text-left">
                        <span class="block truncate text-sm font-semibold text-slate-900">{{ $ac->nombreCompleto() ?: 'Sin nombre' }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ collect([$ac->puesto, $ac->dni_ce ? 'DNI '.$ac->dni_ce : null])->filter()->implode(' · ') }}</span>
                    </button>
                    <span class="text-sm text-slate-700">{{ $a['bloque_nombre'] }}{{ $a['tipo'] === 'rep' ? ' (reprogramada)' : '' }}{{ $a['hora'] !== '' ? ' · '.$a['hora'] : '' }}</span>

                    @if ($a['movida'])
                        <span class="badge badge-amber">Se reprogramó a otra fecha</span>
                    @elseif ($a['resultado'] !== '')
                        <span class="badge {{ $a['resultado'] === 'SI, APTO' ? 'badge-green' : ($a['resultado'] === 'REPROGRAMADO' ? 'badge-amber' : 'badge-rose') }}">{{ ['SI, APTO' => 'Asistió · apto', 'SI, NO APTO' => 'Asistió · no apto', 'NO' => 'No asistió', 'REPROGRAMADO' => 'Reprogramado'][$a['resultado']] ?? $a['resultado'] }}</span>
                    @endif

                    @if ($a['habilitado'] && ! $a['movida'] && $a['resultado'] === '')
                        <select wire:model.live="asist.{{ $a['clave'] }}.res" class="form-input w-auto" aria-label="Resultado">
                            <option value="">Resultado…</option>
                            @foreach (($a['tipo'] === 'rep' ? $opcionesReprogramada : $opcionesResultado) as $valor => $texto)
                                <option value="{{ $valor }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                        @if ($fila['res'] === 'REPROGRAMADO')
                            <input type="date" wire:model="asist.{{ $a['clave'] }}.fecha" class="form-input w-auto" aria-label="Nueva fecha">
                        @endif
                        <button type="button" wire:click="guardarAsistencia('{{ $a['clave'] }}')" class="gc-btn gc-btn-primary">Guardar</button>
                    @endif
                </div>
            @empty
                <div class="rounded-2xl bg-white px-6 py-12 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-100">
                    No hay nadie convocado para esta fecha.
                </div>
            @endforelse
        </div>
    @endif

    {{-- Agendar a varios --}}
    @if ($variosAbierto)
        <div class="gc-drawer" wire:key="varios" wire:click.self="cerrarVarios" wire:keydown.escape.window="cerrarVarios">
            <div class="gc-drawer-panel" role="dialog" aria-modal="true" aria-label="Agendar a varios">
                <div class="flex shrink-0 items-start gap-4 border-b border-slate-200 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold text-slate-900">Agendar a varios</h2>
                        <p class="mt-1 text-sm text-slate-500">Ponle la misma fecha a todos los que marques. Solo aparecen quienes ya pueden agendar ese paso.</p>
                    </div>
                    <button type="button" wire:click="cerrarVarios" class="gc-btn gc-btn-ghost" aria-label="Cerrar">Cerrar ✕</button>
                </div>

                <div class="gc-drawer-body gc-form">
                    @if ($mensaje !== '')
                        <div class="gc-aviso {{ $mensajeError ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-800' }}" role="status">{{ $mensaje }}</div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-forms.select label="Paso" name="variosBloque" :options="collect(\App\Services\ProcesoCapacitacion::BLOQUES)->map(fn ($b) => $b['nombre'])->all()" live />
                        <x-forms.field label="Fecha" name="variosFecha" type="date" />
                        @if ($variosBloque === 'ent2')
                            <x-forms.field label="Hora" name="variosHora" type="time" />
                        @endif
                    </div>

                    <div>
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <input type="search" wire:model.live.debounce.300ms="variosBuscar" class="form-input min-w-[12rem] flex-1" placeholder="Buscar por nombre o DNI" aria-label="Buscar candidato">
                            <button type="button" wire:click="marcarTodosVarios" class="gc-btn gc-btn-ghost">Marcar todos ({{ count($paraAgendar) }})</button>
                        </div>

                        <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto rounded-xl ring-1 ring-slate-200">
                            @forelse ($paraAgendar as $p)
                                <label wire:key="var-{{ $p['id'] }}" class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-slate-50">
                                    <input type="checkbox" wire:model.live="variosSel" value="{{ $p['id'] }}" class="h-4 w-4 rounded border-slate-300">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-slate-800">{{ $p['nombre'] }}</span>
                                        <span class="block truncate text-xs text-slate-500">{{ collect([$p['puesto'], $p['reclutador']])->filter()->implode(' · ') }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="px-3 py-6 text-center text-sm text-slate-400">Nadie tiene ese paso listo para agendar.</p>
                            @endforelse
                        </div>
                    </div>

                    @if ($variosOmitidos)
                        <div class="gc-aviso bg-amber-50 text-amber-800">
                            <p class="font-semibold">No se agendó a:</p>
                            <ul class="mt-1 list-disc pl-5 text-xs">
                                @foreach ($variosOmitidos as $o)
                                    <li>{{ $o }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="gc-drawer-pie">
                    <button type="button" wire:click="agendarVarios" wire:confirm="Se agendarán {{ count($variosSel) }} candidato(s) para el {{ $variosFecha ? \Illuminate\Support\Carbon::parse($variosFecha)->format('d/m/Y') : '—' }}. ¿Continuar?" wire:loading.attr="disabled" wire:target="agendarVarios" class="gc-btn gc-btn-primary">
                        <span wire:loading.remove wire:target="agendarVarios">Agendar {{ count($variosSel) ?: '' }} {{ count($variosSel) === 1 ? 'candidato' : 'candidatos' }}</span>
                        <span wire:loading wire:target="agendarVarios">Agendando…</span>
                    </button>
                    <button type="button" wire:click="cerrarVarios" class="gc-btn gc-btn-ghost">Cerrar</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Panel del candidato --}}
    @if ($panel)
        @php($pc = $panel['c'])
        @php($pe = $panel['estado'])
        <div class="gc-drawer" wire:key="panel-{{ $pc->id }}" wire:click.self="cerrar" wire:keydown.escape.window="cerrar">
            <div class="gc-drawer-panel" role="dialog" aria-modal="true" aria-label="Proceso del candidato">
                <div class="flex shrink-0 items-start gap-4 border-b border-slate-200 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-lg font-bold text-slate-900">{{ $pc->nombreCompleto() ?: 'Sin nombre' }}</h2>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <span class="badge badge-slate">{{ $panel['recorrido'] === 'capacitacion' ? 'Recorrido de capacitación' : 'Recorrido de 2.ª entrevista' }}</span>
                            <span class="badge badge-{{ $pe['tono'] }}">{{ $pe['texto'] }}</span>
                        </div>
                        <p class="mt-1 truncate text-sm text-slate-500">{{ collect([$pc->puesto, $pc->campana && $pc->campana !== 'NO APLICA' ? $pc->campana : null, $pc->agente_reclutador ? 'Reclutador: '.$pc->agente_reclutador : null, $pc->dni_ce ? 'DNI '.$pc->dni_ce : null, $pc->numero_celular])->filter()->implode(' · ') }}</p>
                    </div>
                    <button type="button" wire:click="cerrar" class="gc-btn gc-btn-ghost" aria-label="Cerrar">Cerrar ✕</button>
                </div>

                <div class="gc-drawer-body gc-form">
                    @if ($mensaje !== '')
                        <div class="gc-aviso {{ $mensajeError ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-800' }}" role="status">{{ $mensaje }}</div>
                    @endif

                    {{-- Recorrido --}}
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1" aria-label="Recorrido">
                        @foreach ($panel['pasos'] as $paso)
                            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold {{ ['ok' => 'bg-emerald-50 text-emerald-700', 'actual' => 'bg-amber-50 text-amber-700', 'no' => 'bg-rose-50 text-rose-600', 'pendiente' => 'bg-slate-100 text-slate-400'][$paso['estado']] }}">
                                {{ ['ok' => '✓', 'actual' => '●', 'no' => '✕', 'pendiente' => '○'][$paso['estado']] }} {{ $paso['nombre'] }}
                            </span>
                            @unless ($loop->last)
                                <span class="text-slate-300">›</span>
                            @endunless
                        @endforeach
                    </div>

                    {{-- Entrevista 1 (solo lectura) --}}
                    <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm ring-1 ring-slate-200">
                        <p class="font-semibold text-slate-700">Entrevista 1</p>
                        <p class="mt-0.5 text-slate-600">
                            {{ $pc->fecha_entrevista?->format('d/m/Y') ?: 'sin fecha' }}{{ $pc->hora_entrevista ? ' '.$pc->hora_entrevista : '' }} · {{ $pc->asistio_entrevista ?: 'sin resultado' }}@if ($pc->fecha_reprogramada) · reprogramada {{ $pc->fecha_reprogramada->format('d/m/Y') }} ({{ $pc->asistio_entrevista_reprogramada ?: 'sin resultado' }})@endif
                        </p>
                        <p class="mt-0.5 text-xs text-slate-400">Se gestiona en Gestión de candidatos.</p>
                    </div>

                    {{-- Bloques --}}
                    @foreach ($panel['bloques'] as $blq)
                        @php($k = $blq['clave'])
                        @php($def = $blq['def'])
                        @php($form = $b[$k] ?? [])
                        @php($reprog = ($form['asistio'] ?? '') === 'REPROGRAMADO')
                        <section wire:key="bloque-{{ $k }}" class="space-y-3 rounded-xl p-4 ring-1 ring-slate-200">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-slate-900">{{ $def['nombre'] }}</h3>
                                <span class="badge badge-{{ ['apto' => 'green', 'no_apto' => 'rose', 'no_asistio' => 'rose', 'agendado' => 'blue', 'reprogramado' => 'amber', 'reprogramado_sin_fecha' => 'amber', 'por_definir' => 'amber', 'sin_agendar' => 'slate'][$blq['resultado']] ?? 'slate' }}">
                                    {{ ['apto' => 'Aprobado', 'no_apto' => 'No apto', 'no_asistio' => 'No asistió', 'agendado' => 'Agendada', 'reprogramado' => 'Reprogramada', 'reprogramado_sin_fecha' => 'Reprogramada sin fecha', 'por_definir' => 'Definir resultado', 'sin_agendar' => 'Sin agendar'][$blq['resultado']] ?? $blq['resultado'] }}
                                </span>
                            </div>

                            @if (! $blq['habilitado'])
                                <p class="text-sm text-slate-400">Se habilita cuando apruebe el paso anterior.</p>
                            @else
                                <fieldset class="contents" @disabled($pc->apto)>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <x-forms.field label="Fecha" name="b.{{ $k }}.fecha" type="date" live />
                                        @if ($def['hora'])
                                            <x-forms.field label="Hora" name="b.{{ $k }}.hora" type="time" />
                                        @endif

                                        @if (($form['fecha'] ?? '') !== '')
                                            <x-forms.select label="Resultado" name="b.{{ $k }}.asistio" :options="$blq['fechaFutura'] ? ['REPROGRAMADO' => 'Reprogramar'] : $opcionesResultado" live :span="! $reprog" />
                                            @if ($blq['fechaFutura'])
                                                <p class="text-xs text-slate-400 sm:col-span-2">La asistencia se marca desde el día de la sesión; mientras tanto solo se puede reprogramar.</p>
                                            @endif
                                        @else
                                            <p class="text-sm text-slate-400 sm:col-span-2">Pon la fecha para poder registrar el resultado.</p>
                                        @endif

                                        @if ($reprog)
                                            <x-forms.field label="Nueva fecha" name="b.{{ $k }}.fecha_rep" type="date" live />
                                            @if (($form['fecha_rep'] ?? '') !== '')
                                                <x-forms.select label="Resultado de la reprogramada" name="b.{{ $k }}.asistio_rep" :options="$blq['repFutura'] ? [] : $opcionesReprogramada" :span="true" />
                                                @if ($blq['repFutura'])
                                                    <p class="text-xs text-slate-400 sm:col-span-2">El resultado de la reprogramada se marca desde su fecha.</p>
                                                @endif
                                            @endif
                                        @endif

                                        <x-forms.textarea label="Observación" name="b.{{ $k }}.obs" :span="! $reprog" :rows="2" />
                                        @if ($reprog)
                                            <x-forms.textarea label="Observación de la reprogramada" name="b.{{ $k }}.obs_rep" :rows="2" />
                                        @endif
                                    </div>
                                </fieldset>
                                @error("b.{$k}.general") <p class="form-error">{{ $message }}</p> @enderror

                                @unless ($pc->apto)
                                    <button type="button" wire:click="guardarBloque('{{ $k }}')" wire:loading.attr="disabled" wire:target="guardarBloque('{{ $k }}')" class="gc-btn gc-btn-primary">
                                        <span wire:loading.remove wire:target="guardarBloque('{{ $k }}')">Guardar {{ mb_strtolower($def['nombre']) }}</span>
                                        <span wire:loading wire:target="guardarBloque('{{ $k }}')">Guardando…</span>
                                    </button>
                                @endunless
                            @endif
                        </section>
                    @endforeach

                    {{-- Apto --}}
                    <section class="space-y-2 rounded-xl p-4 ring-1 {{ $pc->apto ? 'bg-emerald-50 ring-emerald-200' : 'ring-slate-200' }}">
                        <h3 class="text-sm font-bold text-slate-900">Apto para ingresar</h3>
                        @if ($pc->apto)
                            <p class="text-sm text-emerald-800">Marcado apto el {{ $pc->fecha_apto?->timezone('America/Lima')->format('d/m/Y H:i') ?: 'sin fecha' }}. Sus datos del proceso quedan bloqueados.</p>
                            @if ($esAdmin)
                                <button type="button" wire:click="quitarApto" wire:confirm="¿Quitar el apto de {{ $pc->nombreCompleto() }}? Podrás volver a editar su recorrido." class="gc-btn gc-btn-danger">Quitar apto</button>
                            @else
                                <p class="text-xs text-emerald-700">Solo el administrador puede quitar el apto.</p>
                            @endif
                        @elseif ($panel['puedeApto'])
                            <p class="text-sm text-slate-600">Aprobó todo su recorrido. Al marcarlo apto pasa a Registro de ingresos.</p>
                            <button type="button" wire:click="marcarApto" wire:confirm="¿Marcar a {{ $pc->nombreCompleto() }} como apto?" class="gc-btn gc-btn-primary">Marcar apto</button>
                        @else
                            <p class="text-sm text-slate-400">Se podrá marcar cuando apruebe todo su recorrido.</p>
                        @endif
                    </section>

                    {{-- Ficha de ingreso --}}
                    <section class="space-y-3 rounded-xl p-4 ring-1 ring-slate-200">
                        <h3 class="text-sm font-bold text-slate-900">Ficha de ingreso</h3>
                        @php($fi = $panel['ficha'])
                        <p class="text-sm text-slate-600">
                            @if ($fi['estado'] === 'llenada')
                                <span class="font-semibold text-emerald-700">Ya llenó su ficha.</span>
                            @elseif ($fi['estado'] === 'enviado')
                                Enviada a {{ $fi['correo'] }} el {{ $fi['enviado_at']?->timezone('America/Lima')->format('d/m/Y H:i') }}.
                            @elseif ($fi['estado'] === 'vencida')
                                El enlace enviado a {{ $fi['correo'] }} venció: puedes mandar uno nuevo.
                            @elseif ($fi['estado'] === 'en_cola')
                                En cola de envío a {{ $fi['correo'] }}…
                            @elseif ($fi['estado'] === 'error')
                                <span class="text-rose-600">No se pudo enviar: {{ $fi['error'] }}</span>
                            @else
                                Aún no se envía.
                            @endif
                        </p>

                        @if ($fi['estado'] !== 'llenada')
                            @if (! $panel['puedeFicha'])
                                <p class="text-sm text-slate-400">Se puede enviar desde que se agenda el primer paso del recorrido.</p>
                            @elseif (! $panel['fichaConfigurada'])
                                <p class="text-sm text-amber-700">El envío de correos todavía no está configurado en el servidor.</p>
                            @else
                                <div class="flex flex-wrap items-start gap-2">
                                    <div class="min-w-[14rem] flex-1">
                                        <input type="email" wire:model="fichaCorreo" class="form-input" placeholder="Correo personal del candidato" aria-label="Correo del candidato">
                                        @error('fichaCorreo') <p class="form-error">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="button" wire:click="enviarFicha" wire:loading.attr="disabled" wire:target="enviarFicha" class="gc-btn gc-btn-ghost">
                                        <span wire:loading.remove wire:target="enviarFicha">{{ in_array($fi['estado'], ['enviado', 'vencida', 'error'], true) ? 'Reenviar ficha' : 'Enviar ficha' }}</span>
                                        <span wire:loading wire:target="enviarFicha">Enviando…</span>
                                    </button>
                                </div>
                            @endif
                        @endif
                    </section>

                    {{-- Historial --}}
                    <section class="space-y-2">
                        <h3 class="text-sm font-bold text-slate-900">Historial</h3>
                        @forelse ($panel['historial'] as $ev)
                            <div wire:key="hist-{{ $loop->index }}" class="flex gap-3 text-sm">
                                <span class="w-32 shrink-0 text-xs text-slate-400">{{ $ev['fecha'] }}</span>
                                <span class="min-w-0 flex-1 text-slate-700">{{ $ev['texto'] }} <span class="text-xs text-slate-400">· {{ $ev['usuario'] }}</span></span>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">Todavía no hay cambios registrados.</p>
                        @endforelse
                    </section>
                </div>
            </div>
        </div>
    @endif
</div>
