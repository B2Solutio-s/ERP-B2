<?php

use App\Models\CrmDeliveryResumen;
use App\Models\CrmEjecutivo;
use App\Models\Empleado;
use App\Models\CandidatoReclutamiento;
use App\Models\OnboardingInvitation;
use App\Models\SeguimientoRegistro;
use App\Services\CrmDelivery;
use App\Services\ResumenCompromisos;
use App\Services\Seguimiento;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/** Seguimiento de ejecutivos: «Resumen Tabla 3» del CRM (móvil en Q, fija en SOT) frente al compromiso de cada uno. Solo administrador. */
new #[Layout('layouts.panel', ['title' => 'Seguimiento de ejecutivos'])] class extends Component
{
    public string $desde = '';

    public string $hasta = '';

    /** Orden de la tabla: 'nombre' (A–Z) o 'total' (mayor a menor). */
    public string $orden = 'nombre';

    public ?string $aviso = null;

    public bool $avisoError = false;

    /** Selección de trabajador por ejecutivo del CRM (crm_user_id => empleado_id). */
    public array $seleccion = [];

    /** Modal «Datos de…» (Registro de ingresos) abierto en un iframe, y la pestaña con que se abre. */
    public ?int $datosEmbebidoId = null;

    public string $datosEmbebidoPestana = 'datos';

    /** Modal «Resumen diario por correo» y los correos en copia (el «Para» son los de gerencia). */
    public bool $resumenAbierto = false;

    public string $resumenCopia = '';

    public ?string $resumenAviso = null;

    public bool $resumenAvisoError = false;

    /** Trabajador cuyo historial de compromisos está abierto. */
    public ?int $historialEmpleadoId = null;

    public function mount(): void
    {
        $this->exigirAcceso();
        $this->resumenCopia = implode(', ', ResumenCompromisos::copia());
        $this->preajuste('mes_actual');
    }

    /** Administrador y GTH. GTH nunca ve remuneración ni la pestaña Contrato (eso lo controla el modal de datos). */
    private function exigirAcceso(): void
    {
        abort_unless(Auth::user()?->esAdmin() || Auth::user()?->esGth(), 403);
    }

    public function ordenarPor(string $criterio): void
    {
        $this->exigirAcceso();
        $this->orden = $criterio === 'total' ? 'total' : 'nombre';
    }

    public function abrirDatos(int $candidatoId, string $pestana = 'datos'): void
    {
        $this->exigirAcceso();

        if (CandidatoReclutamiento::where('apto', true)->whereKey($candidatoId)->exists()) {
            $this->datosEmbebidoId = $candidatoId;
            $this->datosEmbebidoPestana = $pestana === 'seguimiento' ? 'seguimiento' : 'datos';
        }
    }

    /** El iframe avisa (postMessage) cuando se cierra el modal de datos; se refresca la tabla. */
    #[On('cerrar-datos-embebidos')]
    public function cerrarDatosEmbebidos(): void
    {
        $this->datosEmbebidoId = null;
    }

    public function abrirResumen(): void
    {
        $this->exigirAcceso();
        $this->resumenCopia = implode(', ', ResumenCompromisos::copia());
        $this->resetErrorBag('resumenCopia');
        $this->resumenAviso = null;
        $this->resumenAbierto = true;
    }

    public function cerrarResumen(): void
    {
        $this->resumenAbierto = false;
        $this->resetErrorBag('resumenCopia');
    }

    public function guardarResumenCopia(): void
    {
        $this->exigirAcceso();
        $this->resetErrorBag('resumenCopia');

        $error = ResumenCompromisos::guardarCopia($this->resumenCopia);

        if ($error) {
            $this->addError('resumenCopia', $error);

            return;
        }

        $this->resumenCopia = implode(', ', ResumenCompromisos::copia());
        $this->resumenAvisoError = false;
        $this->resumenAviso = 'Correos en copia guardados.';
    }

    public function enviarResumenAhora(): void
    {
        $this->exigirAcceso();
        $resultado = ResumenCompromisos::enviar(true);
        $this->resumenAvisoError = ! $resultado['ok'];
        $this->resumenAviso = $resultado['mensaje'];
    }

    public function abrirHistorial(int $empleadoId): void
    {
        $this->exigirAcceso();
        $this->historialEmpleadoId = CrmEjecutivo::where('empleado_id', $empleadoId)->exists() ? $empleadoId : null;
    }

    public function cerrarHistorial(): void
    {
        $this->historialEmpleadoId = null;
    }

    /** Atajos de rango: 'mes_actual', 'mes_anterior', 'ultimos_7'. */
    public function preajuste(string $clave): void
    {
        $this->exigirAcceso();
        $hoy = now('America/Lima');

        [$this->desde, $this->hasta] = match ($clave) {
            'mes_anterior' => [$hoy->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $hoy->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'ultimos_7' => [$hoy->copy()->subDays(6)->toDateString(), $hoy->toDateString()],
            default => [$hoy->copy()->startOfMonth()->toDateString(), $hoy->copy()->endOfMonth()->toDateString()],
        };

        $this->aplicarRango();
    }

    public function updatedDesde(): void
    {
        $this->aplicarRango();
    }

    public function updatedHasta(): void
    {
        $this->aplicarRango();
    }

    /** Valida el rango y consulta el CRM si hace falta. */
    private function aplicarRango(): void
    {
        $this->aviso = null;
        $this->avisoError = false;

        $valida = fn (string $f) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && checkdate((int) substr($f, 5, 2), (int) substr($f, 8, 2), (int) substr($f, 0, 4));

        if (! $valida($this->desde) || ! $valida($this->hasta)) {
            $this->avisoError = true;
            $this->aviso = 'Elige las dos fechas del rango.';

            return;
        }

        if ($this->hasta < $this->desde) {
            $this->avisoError = true;
            $this->aviso = 'La fecha «hasta» no puede ser anterior a «desde».';

            return;
        }

        if (Carbon::parse($this->desde)->diffInDays(Carbon::parse($this->hasta)) > CrmDelivery::MAX_DIAS) {
            $this->avisoError = true;
            $this->aviso = 'El rango no puede pasar de '.CrmDelivery::MAX_DIAS.' días.';

            return;
        }

        $resultado = CrmDelivery::sincronizarSiHaceFalta($this->desde, $this->hasta);

        if ($resultado) {
            $this->avisoError = ! $resultado['ok'];
            $this->aviso = $resultado['mensaje'];
        }
    }

    public function sincronizar(): void
    {
        $this->exigirAcceso();
        $this->aviso = null;
        $this->avisoError = false;

        $this->aplicarRango();

        if ($this->avisoError) {
            return;
        }

        $resultado = CrmDelivery::sincronizar($this->desde, $this->hasta);
        $this->avisoError = ! $resultado['ok'];
        $this->aviso = $resultado['mensaje'];
    }

    public function vincular(int $crmUserId, int $empleadoId): void
    {
        $this->exigirAcceso();
        $ejecutivo = CrmEjecutivo::where('crm_user_id', $crmUserId)->firstOrFail();
        $empleado = Empleado::findOrFail($empleadoId);

        $yaVinculado = CrmEjecutivo::where('empleado_id', $empleado->id)->where('id', '!=', $ejecutivo->id)->first();

        if ($yaVinculado) {
            $this->avisoError = true;
            $this->aviso = "{$empleado->nombreCompleto()} ya está vinculado al ejecutivo «{$yaVinculado->nombre}» del CRM.";

            return;
        }

        $ejecutivo->update(['empleado_id' => $empleado->id]);
        unset($this->seleccion[$crmUserId]);
        $this->avisoError = false;
        $this->aviso = "{$ejecutivo->nombre} quedó vinculado a {$empleado->nombreCompleto()}.";
    }

    public function vincularSeleccion(int $crmUserId): void
    {
        $empleadoId = (int) ($this->seleccion[$crmUserId] ?? 0);

        if ($empleadoId <= 0) {
            $this->avisoError = true;
            $this->aviso = 'Elige primero al trabajador.';

            return;
        }

        $this->vincular($crmUserId, $empleadoId);
    }

    public function quitarVinculo(int $crmUserId): void
    {
        $this->exigirAcceso();
        CrmEjecutivo::where('crm_user_id', $crmUserId)->update(['empleado_id' => null]);
        $this->aviso = null;
    }

    public function vincularSugeridos(): void
    {
        $this->exigirAcceso();
        $empleados = Empleado::query()->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno']);
        $ocupados = CrmEjecutivo::whereNotNull('empleado_id')->pluck('empleado_id')->all();
        $hechos = 0;

        foreach (CrmEjecutivo::whereNull('empleado_id')->get() as $ejecutivo) {
            $sugerido = CrmDelivery::sugerir($ejecutivo->nombre, $empleados);

            if ($sugerido && ! in_array($sugerido->id, $ocupados, true)) {
                $ejecutivo->update(['empleado_id' => $sugerido->id]);
                $ocupados[] = $sugerido->id;
                $hechos++;
            }
        }

        $this->avisoError = false;
        $this->aviso = $hechos > 0 ? "Se vincularon {$hechos} ejecutivos sugeridos." : 'No había sugerencias claras para vincular.';
    }

    public function with(): array
    {
        $rangoValido = $this->hasta >= $this->desde && $this->desde !== '';
        $tabla = $rangoValido ? CrmDelivery::tabla($this->desde, $this->hasta, $this->orden) : collect();

        $ejecutivos = CrmEjecutivo::with('empleado:id,nombres,apellido_paterno,apellido_materno,documento_identidad')
            ->get()->keyBy('crm_user_id');

        $metas = $rangoValido
            ? CrmDelivery::metas($ejecutivos->pluck('empleado_id')->filter()->all(), $this->desde, $this->hasta)
            : [];

        $empleados = Empleado::query()->orderBy('apellido_paterno')->orderBy('apellido_materno')->orderBy('nombres')
            ->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'documento_identidad']);

        $sinVincular = $ejecutivos->filter(fn ($e) => ! $e->empleado_id)->sortBy('nombre');
        $sugerencias = [];
        foreach ($sinVincular as $e) {
            $sugerencias[$e->crm_user_id] = CrmDelivery::sugerir($e->nombre, $empleados);
        }

        // Enlace al modal «Datos de …»: trabajador → invitación → candidato.
        $empleadoIds = $ejecutivos->pluck('empleado_id')->filter()->all();
        $invitaciones = Empleado::whereIn('id', $empleadoIds)->pluck('onboarding_invitation_id', 'id');
        $candidatoDeInvitacion = OnboardingInvitation::whereIn('id', $invitaciones->filter()->all())->pluck('reclutamiento_candidato_id', 'id');
        $candidatos = [];
        foreach ($invitaciones as $empleadoId => $invitacionId) {
            if ($invitacionId && ! empty($candidatoDeInvitacion[$invitacionId])) {
                $candidatos[$empleadoId] = (int) $candidatoDeInvitacion[$invitacionId];
            }
        }

        $hoy = now('America/Lima')->toDateString();
        $compromisos = Seguimiento::compromisosActuales($empleadoIds);
        $plazos = [];
        foreach ($compromisos as $empleadoId => $compromiso) {
            $plazos[$empleadoId] = Seguimiento::plazo($compromiso, $hoy);
        }

        $porAtender = [];
        foreach ($ejecutivos as $e) {
            $c = $e->empleado_id ? ($compromisos[$e->empleado_id] ?? null) : null;
            if ($c && $c->estado === 'pendiente' && $plazos[$e->empleado_id]['urgente']) {
                $porAtender[] = ['crm_user_id' => $e->crm_user_id, 'nombre' => $e->nombre, 'empleado_id' => $e->empleado_id, 'plazo' => $plazos[$e->empleado_id], 'hasta' => $c->fecha_hasta, 'meta' => collect([$c->meta_q ? $c->meta_q.' Q' : null, $c->meta_sots ? $c->meta_sots.' SOT' : null])->filter()->implode(' · ')];
            }
        }
        usort($porAtender, fn ($a, $b) => $a['hasta'] <=> $b['hasta']);

        $totalCompromisos = SeguimientoRegistro::where('tipo', SeguimientoRegistro::COMPROMISO)
            ->whereIn('empleado_id', $empleadoIds)
            ->selectRaw("empleado_id, count(*) as n, sum(estado = 'cumplido') as cumplidos, sum(estado = 'no_cumplido') as no_cumplidos")
            ->groupBy('empleado_id')->get()->keyBy('empleado_id')->all();

        $historial = $this->historialEmpleadoId
            ? SeguimientoRegistro::with('evaluadoPor:id,name')->where('tipo', SeguimientoRegistro::COMPROMISO)
                ->where('empleado_id', $this->historialEmpleadoId)->orderByDesc('fecha_desde')->orderByDesc('id')->get()
            : collect();
        $historialEjecutivo = $this->historialEmpleadoId
            ? CrmEjecutivo::with('empleado:id,nombres,apellido_paterno,apellido_materno')->where('empleado_id', $this->historialEmpleadoId)->first()
            : null;

        return [
            'resumenPara' => ResumenCompromisos::paraGerencia(),
            'resumenUltimo' => ResumenCompromisos::ultimoEnvio(),
            'resumenPendientes' => ResumenCompromisos::novedades($hoy)->count(),
            'totalCompromisos' => $totalCompromisos,
            'historial' => $historial,
            'historialEjecutivo' => $historialEjecutivo,
            'hoy' => $hoy,
            'candidatos' => $candidatos,
            'compromisos' => $compromisos,
            'plazos' => $plazos,
            'porAtender' => $porAtender,
            'tabla' => $tabla,
            'ejecutivos' => $ejecutivos,
            'metas' => $metas,
            'empleados' => $empleados,
            'sinVincular' => $sinVincular,
            'vinculados' => $ejecutivos->filter(fn ($e) => $e->empleado_id)->sortBy('nombre'),
            'sugerencias' => $sugerencias,
            'ultima' => $rangoValido ? CrmDelivery::ultimaSincronizacion($this->desde, $this->hasta) : null,
        ];
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Desde</label>
            <input type="date" wire:model.live="desde" class="form-input w-auto py-1.5 text-xs">
        </div>
        <div>
            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Hasta</label>
            <input type="date" wire:model.live="hasta" class="form-input w-auto py-1.5 text-xs">
        </div>

        <div class="flex items-center gap-1">
            <button type="button" wire:click="preajuste('mes_actual')" class="btn-secondary w-auto px-3 py-1.5 text-xs">Mes actual</button>
            <button type="button" wire:click="preajuste('mes_anterior')" class="btn-secondary w-auto px-3 py-1.5 text-xs">Mes anterior</button>
            <button type="button" wire:click="preajuste('ultimos_7')" class="btn-secondary w-auto px-3 py-1.5 text-xs">Últimos 7 días</button>
        </div>

        <button type="button" wire:click="sincronizar" wire:loading.attr="disabled" wire:target="sincronizar,desde,hasta,preajuste" class="btn-secondary w-auto px-3 py-1.5 text-xs">
            <span wire:loading.remove wire:target="sincronizar,desde,hasta,preajuste">Actualizar desde el CRM</span>
            <span wire:loading wire:target="sincronizar,desde,hasta,preajuste">Consultando el CRM...</span>
        </button>

        <span class="pb-2 text-[11px] text-slate-500">
            @if ($ultima)
                Datos del CRM de {{ $ultima->timezone('America/Lima')->format('d/m/Y H:i') }}
            @else
                Aún sin datos para este rango.
            @endif
        </span>

        <button type="button" wire:click="abrirResumen" class="btn-secondary ml-auto inline-flex w-auto items-center gap-1.5 px-3 py-1.5 text-xs" title="Correos del resumen diario de compromisos"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 8.5V3.707l.854-.853A.5.5 0 0 0 11.5 2.5v-2A.5.5 0 0 0 11 0H9.5a.5.5 0 0 0-.5.5v8z"/><path d="M4 3h4v1H6.646A4 4 0 0 1 8 7v6h7V7a3 3 0 0 0-3-3V3a4 4 0 0 1 4 4v6a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1V7a4 4 0 0 1 4-4m.585 4.157C4.836 7.264 5 7.334 5 7a1 1 0 0 0-2 0c0 .334.164.264.415.157C3.58 7.087 3.782 7 4 7s.42.086.585.157"/></svg>Resumen diario por correo</button>
    </div>

    @if ($aviso)
        <p class="rounded-lg px-3 py-2 text-xs font-semibold {{ $avisoError ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' }}">{{ $aviso }}</p>
    @endif

    @if ($porAtender)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
            <p class="text-xs font-bold uppercase tracking-wide text-amber-800">Compromisos que requieren atención ({{ count($porAtender) }})</p>
            <ul class="mt-1.5 space-y-1">
                @foreach ($porAtender as $p)
                    <li wire:key="atender-{{ $p['crm_user_id'] }}" class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="badge badge-{{ $p['plazo']['tono'] }}">{{ $p['plazo']['texto'] }}@if ($p['meta']) · {{ $p['meta'] }}@endif</span>
                        @if (isset($candidatos[$p['empleado_id']]))
                            <button type="button" wire:click="abrirDatos({{ $candidatos[$p['empleado_id']] }}, 'seguimiento')" class="font-semibold uppercase text-sky-800 hover:underline">{{ $p['nombre'] }}</button>
                        @else
                            <span class="font-semibold uppercase text-slate-800">{{ $p['nombre'] }}</span>
                        @endif
                        <span class="text-slate-500">hasta el {{ $p['hasta']->format('d/m/Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card-panel overflow-x-auto p-0">
        <table class="data-table w-full text-sm">
            <thead>
                <tr>
                    <th rowspan="2" class="text-left align-bottom">
                        <button type="button" wire:click="ordenarPor('nombre')" class="inline-flex items-center gap-1 font-semibold hover:text-slate-900 {{ $orden === 'nombre' ? 'text-slate-900' : '' }}" title="Ordenar de la A a la Z">Ejecutivos<span class="text-[10px]">{{ $orden === 'nombre' ? '▲' : '' }}</span></button>
                    </th>
                    <th colspan="2" class="border-l border-slate-200 text-center">Compromiso</th>
                    <th colspan="2" class="border-l border-slate-200 text-center">Logrado</th>
                    <th colspan="2" class="border-l border-slate-200 text-center">En flujo</th>
                    <th colspan="2" class="border-l border-slate-200 text-center">Proyectado</th>
                    <th colspan="2" class="border-l border-slate-200 text-center">
                        <button type="button" wire:click="ordenarPor('total')" class="inline-flex items-center gap-1 font-semibold hover:text-slate-900 {{ $orden === 'total' ? 'text-slate-900' : '' }}" title="Ordenar por total, de mayor a menor">Total<span class="text-[10px]">{{ $orden === 'total' ? '▼' : '' }}</span></button>
                    </th>
                    <th rowspan="2" class="border-l border-slate-200 text-left align-bottom">Plazo del compromiso</th>
                </tr>
                <tr class="text-[11px]">
                    <th class="border-l border-slate-200 text-center">Q</th>
                    <th class="text-center">SOT</th>
                    <th class="border-l border-slate-200 text-center" title="Líneas móviles activadas">Activadas</th>
                    <th class="text-center" title="SOTs de fija atendidos">Atendidas</th>
                    <th class="border-l border-slate-200 text-center">Q</th>
                    <th class="text-center">SOT</th>
                    <th class="border-l border-slate-200 text-center">Q</th>
                    <th class="text-center">SOT</th>
                    <th class="border-l border-slate-200 text-center">Q</th>
                    <th class="text-center">SOT</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tabla as $fila)
                    @php($ejecutivo = $ejecutivos->get($fila['crm_user_id']))
                    @php($meta = $ejecutivo?->empleado_id ? ($metas[$ejecutivo->empleado_id] ?? null) : null)
                    @php($movil = $fila['movil'])
                    @php($fija = $fila['fija'])
                    @php($sinNada = ($movil?->total ?? 0) + ($fija?->total ?? 0) === 0)
                    <tr wire:key="fila-{{ $fila['crm_user_id'] }}" class="{{ $sinNada ? 'bg-sky-50' : '' }}">
                        <td class="font-semibold uppercase text-slate-900">
                            @if ($ejecutivo?->empleado_id && isset($candidatos[$ejecutivo->empleado_id]))
                                <button type="button" wire:click="abrirDatos({{ $candidatos[$ejecutivo->empleado_id] }})" class="text-left uppercase text-sky-800 hover:underline" title="Abrir los datos de {{ $fila['nombre'] }}">{{ $fila['nombre'] }}</button>
                            @else
                                {{ $fila['nombre'] }}
                            @endif
                            @unless ($ejecutivo?->empleado_id)
                                <span class="badge badge-amber ml-1 normal-case">Sin vincular</span>
                            @endunless
                        </td>
                        <td class="border-l border-slate-100 text-center">{{ $meta['q'] ?? '—' }}</td>
                        <td class="text-center">{{ $meta['sots'] ?? '—' }}</td>
                        <td class="border-l border-slate-100 text-center font-bold {{ $meta && $meta['q'] !== null && $movil && $movil->activadas >= $meta['q'] ? 'text-emerald-700' : '' }}">{{ $movil ? $movil->activadas : '—' }}</td>
                        <td class="text-center font-bold {{ $meta && $meta['sots'] !== null && $fija && $fija->activadas >= $meta['sots'] ? 'text-emerald-700' : '' }}">{{ $fija ? $fija->activadas : '—' }}</td>
                        <td class="border-l border-slate-100 text-center">{{ $movil ? $movil->flujo : '—' }}</td>
                        <td class="text-center">{{ $fija ? $fija->flujo : '—' }}</td>
                        <td class="border-l border-slate-100 text-center">{{ $movil ? $movil->proyectadas : '—' }}</td>
                        <td class="text-center">{{ $fija ? $fija->proyectadas : '—' }}</td>
                        <td class="border-l border-slate-100 text-center font-bold">{{ $movil ? $movil->total : '—' }}</td>
                        <td class="text-center font-bold">{{ $fija ? $fija->total : '—' }}</td>
                        <td class="border-l border-slate-100 text-xs">
                            @php($comp = $ejecutivo?->empleado_id ? ($compromisos[$ejecutivo->empleado_id] ?? null) : null)
                            @if ($comp)
                                @php($pl = $plazos[$ejecutivo->empleado_id])
                                @php($candId = $candidatos[$ejecutivo->empleado_id] ?? null)
                                @php($resumenHist = $totalCompromisos[$ejecutivo->empleado_id] ?? null)
                                @php($metaComp = collect([$comp->meta_q ? $comp->meta_q.' Q' : null, $comp->meta_sots ? $comp->meta_sots.' SOT' : null])->filter()->implode(' · '))
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($candId)
                                        <button type="button" wire:click="abrirDatos({{ $candId }}, 'seguimiento')" class="badge badge-{{ $pl['tono'] }} hover:opacity-80" title="Abrir el seguimiento de {{ $fila['nombre'] }}">{{ $pl['texto'] }}@if ($metaComp) · {{ $metaComp }}@endif</button>
                                    @else
                                        <span class="badge badge-{{ $pl['tono'] }}">{{ $pl['texto'] }}@if ($metaComp) · {{ $metaComp }}@endif</span>
                                    @endif
                                    <button type="button" wire:click="abrirHistorial({{ $ejecutivo->empleado_id }})" class="rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50" title="{{ $resumenHist->n ?? 0 }} compromisos: {{ $resumenHist->cumplidos ?? 0 }} cumplidos, {{ $resumenHist->no_cumplidos ?? 0 }} no cumplidos">Historial ({{ $resumenHist->n ?? 0 }} · <span class="text-emerald-700">✓ {{ (int) ($resumenHist->cumplidos ?? 0) }}</span> · <span class="text-rose-600">✗ {{ (int) ($resumenHist->no_cumplidos ?? 0) }}</span>)</button>
                                </div>
                                <span class="mt-0.5 block text-[10px] text-slate-500">{{ $comp->fecha_desde->format('d/m') }} – {{ $comp->fecha_hasta->format('d/m/Y') }}</span>
                            @else
                                <span class="text-slate-400">Sin compromiso</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="py-6 text-center text-xs text-slate-500">No hay datos del CRM para este rango.</td></tr>
                @endforelse
            </tbody>
            @if ($tabla->isNotEmpty())
                @php($metasQ = collect($metas)->sum(fn ($m) => (int) $m['q']))
                @php($metasSot = collect($metas)->sum(fn ($m) => (int) $m['sots']))
                <tfoot>
                    <tr class="border-t-2 border-slate-300 bg-slate-50 font-bold">
                        <td>Total general</td>
                        <td class="border-l border-slate-200 text-center">{{ $metasQ ?: '—' }}</td>
                        <td class="text-center">{{ $metasSot ?: '—' }}</td>
                        <td class="border-l border-slate-200 text-center">{{ $tabla->sum(fn ($f) => $f['movil']->activadas ?? 0) }}</td>
                        <td class="text-center">{{ $tabla->sum(fn ($f) => $f['fija']->activadas ?? 0) }}</td>
                        <td class="border-l border-slate-200 text-center">{{ $tabla->sum(fn ($f) => $f['movil']->flujo ?? 0) }}</td>
                        <td class="text-center">{{ $tabla->sum(fn ($f) => $f['fija']->flujo ?? 0) }}</td>
                        <td class="border-l border-slate-200 text-center">{{ $tabla->sum(fn ($f) => $f['movil']->proyectadas ?? 0) }}</td>
                        <td class="text-center">{{ $tabla->sum(fn ($f) => $f['fija']->proyectadas ?? 0) }}</td>
                        <td class="border-l border-slate-200 text-center">{{ $tabla->sum(fn ($f) => $f['movil']->total ?? 0) }}</td>
                        <td class="text-center">{{ $tabla->sum(fn ($f) => $f['fija']->total ?? 0) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <p class="text-[11px] text-slate-500">
        Q = líneas móviles; SOT = instalaciones de fija. El compromiso sale de los compromisos del trabajador cuyo plazo toca el rango (el más reciente gana; se registran en Registro de ingresos › Seguimiento).
        Lo <strong>logrado</strong> (activadas y atendidas) se cuenta por fecha dentro del rango y es lo que vale para cumplir el compromiso; en verde, quien ya alcanzó su meta.
        <strong>En flujo</strong> y <strong>proyectado</strong> son el estado actual del CRM, solo de referencia.
    </p>

    {{-- Vinculación con los trabajadores del ERP --}}
    <details class="card-panel" @if ($sinVincular->isNotEmpty()) open @endif>
        <summary class="cursor-pointer text-xs font-bold uppercase tracking-wide text-slate-700">
            Vincular ejecutivos con trabajadores
            <span class="ml-1 font-semibold normal-case text-slate-500">({{ $vinculados->count() }} vinculados · {{ $sinVincular->count() }} sin vincular)</span>
        </summary>

        <div class="mt-3 space-y-3">
            <p class="text-[11px] text-slate-500">Cada ejecutivo del CRM se vincula una sola vez con su ficha de trabajador (identificada por DNI). Así sus compromisos del ERP aparecen en la tabla.</p>

            @if ($sugerencias && collect($sugerencias)->filter()->isNotEmpty())
                <button type="button" wire:click="vincularSugeridos" wire:confirm="¿Vincular todos los ejecutivos que tienen un trabajador sugerido?" class="btn-secondary w-auto px-3 py-1.5 text-xs">Aceptar todos los sugeridos</button>
            @endif

            @foreach ($sinVincular as $e)
                @php($sugerido = $sugerencias[$e->crm_user_id] ?? null)
                <div wire:key="sv-{{ $e->crm_user_id }}" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                    <span class="w-44 shrink-0 text-xs font-semibold uppercase text-slate-800">{{ $e->nombre }}</span>
                    <select wire:model="seleccion.{{ $e->crm_user_id }}" class="form-input w-72 py-1.5 text-xs">
                        <option value="">Elegir trabajador…</option>
                        @foreach ($empleados as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->nombreCompleto() }} · {{ $emp->documento_identidad }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="vincularSeleccion({{ $e->crm_user_id }})" class="btn-secondary w-auto px-3 py-1 text-xs">Vincular</button>
                    @if ($sugerido)
                        <button type="button" wire:click="vincular({{ $e->crm_user_id }}, {{ $sugerido->id }})" class="text-[11px] font-semibold text-sky-700 hover:underline">
                            Sugerido: {{ $sugerido->nombreCompleto() }} · {{ $sugerido->documento_identidad }} — Aceptar
                        </button>
                    @endif
                </div>
            @endforeach

            @foreach ($vinculados as $e)
                <div wire:key="vi-{{ $e->crm_user_id }}" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2 text-xs">
                    <span class="w-44 shrink-0 font-semibold uppercase text-slate-800">{{ $e->nombre }}</span>
                    <span class="text-slate-600">{{ $e->empleado->nombreCompleto() }} · DNI {{ $e->empleado->documento_identidad }}</span>
                    <button type="button" wire:click="quitarVinculo({{ $e->crm_user_id }})" wire:confirm="¿Quitar el vínculo de {{ $e->nombre }}?" class="ml-auto text-[11px] font-semibold text-rose-600 hover:underline">Quitar vínculo</button>
                </div>
            @endforeach

            @if ($ejecutivos->isEmpty())
                <p class="text-xs text-slate-500">Aún no hay ejecutivos del CRM: aparecen al consultar el CRM por primera vez.</p>
            @endif
        </div>
    </details>

    {{-- Resumen diario por correo --}}
    @if ($resumenAbierto)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-4">
            <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white text-xs shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Resumen diario por correo</h3>
                        <p class="text-[11px] text-slate-500">Todos los días a las 8:00 a. m.</p>
                    </div>
                    <button type="button" wire:click="cerrarResumen" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="space-y-3 p-4">
                    <p class="text-[11px] text-slate-500">
                        Se envía desde rrhh@b2s.pe un correo con los compromisos <strong>vencidos sin evaluar</strong> y los que <strong>vencen en los próximos {{ \App\Services\ResumenCompromisos::DIAS_PREVIOS }} días</strong>. Si ese día no hay ninguno, no se envía nada.
                    </p>

                    @if ($resumenAviso)
                        <p class="rounded-lg px-3 py-2 text-[11px] font-semibold {{ $resumenAvisoError ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' }}">{{ $resumenAviso }}</p>
                    @endif

                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Para</p>
                        <p class="text-xs text-slate-800">{{ implode(', ', $resumenPara) ?: 'sin correo de gerencia' }}</p>
                        <p class="text-[10px] text-slate-400">Los correos de gerencia los cambia solo el administrador (Registro de ingresos › Seguimiento › Avisos a gerencia).</p>
                    </div>

                    <div>
                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Con copia a (opcional)</label>
                        <div class="flex items-start gap-2">
                            <input type="text" wire:model="resumenCopia" class="form-input min-w-0 flex-1 py-1.5 text-xs" placeholder="Varios correos separados por coma">
                            <button type="button" wire:click="guardarResumenCopia" class="btn-secondary w-auto shrink-0 px-3 py-1.5 text-xs">Guardar</button>
                        </div>
                        @error('resumenCopia') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-3">
                        <button
                            type="button"
                            wire:click="enviarResumenAhora"
                            wire:confirm="Se enviará ahora el resumen a {{ implode(', ', $resumenPara) }}{{ $resumenCopia ? ' (copia: '.$resumenCopia.')' : '' }}. ¿Continuar?"
                            wire:loading.attr="disabled"
                            wire:target="enviarResumenAhora"
                            class="btn-secondary w-auto px-3 py-1.5 text-xs"
                        >
                            <span wire:loading.remove wire:target="enviarResumenAhora">Enviar resumen ahora</span>
                            <span wire:loading wire:target="enviarResumenAhora">Enviando...</span>
                        </button>
                        <span class="text-[11px] text-slate-500">
                            Hoy hay {{ $resumenPendientes }} {{ $resumenPendientes === 1 ? 'compromiso' : 'compromisos' }} por atender.
                            @if ($resumenUltimo) Último envío: {{ $resumenUltimo->timezone('America/Lima')->format('d/m/Y H:i') }}. @else Aún no se ha enviado ninguno. @endif
                        </span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- «Datos de …» (Registro de ingresos) dentro de este módulo --}}
    @if ($datosEmbebidoId)
        <div class="fixed inset-0 z-[80]" wire:key="datos-embebido-{{ $datosEmbebidoId }}-{{ $datosEmbebidoPestana }}">
            <iframe
                src="{{ route('registro-ingresos', ['abrir' => $datosEmbebidoId, 'pestana' => $datosEmbebidoPestana, 'embed' => 1]) }}"
                class="h-full w-full border-0 bg-transparent"
                title="Datos del trabajador"
            ></iframe>
        </div>
    @endif

    {{-- Historial de compromisos del ejecutivo --}}
    @if ($historialEmpleadoId && $historialEjecutivo)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[85vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl bg-white text-xs shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-2.5">
                    <div>
                        <h3 class="text-xs font-bold uppercase text-slate-900">Historial de compromisos · {{ $historialEjecutivo->nombre }}</h3>
                        <p class="text-[11px] text-slate-500">
                            {{ $historial->count() }} {{ $historial->count() === 1 ? 'compromiso' : 'compromisos' }} ·
                            {{ $historial->where('estado', 'cumplido')->count() }} cumplidos ·
                            {{ $historial->where('estado', 'no_cumplido')->count() }} no cumplidos ·
                            {{ $historial->where('estado', 'pendiente')->count() }} en pie
                        </p>
                    </div>
                    <button type="button" wire:click="cerrarHistorial" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    @forelse ($historial as $h)
                        @php($ph = \App\Services\Seguimiento::plazo($h, $hoy))
                        @php($metaH = collect([$h->meta_q ? $h->meta_q.' Q' : null, $h->meta_sots ? $h->meta_sots.' SOT' : null])->filter()->implode(' · '))
                        <div wire:key="hist-{{ $h->id }}" class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <p class="min-w-0 flex-1 truncate text-xs font-semibold text-slate-900" title="{{ $h->titulo }}">{{ $h->titulo }}</p>
                                @if ($metaH)
                                    <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-700">{{ $metaH }}</span>
                                @endif
                                <span class="badge badge-{{ $ph['tono'] }} shrink-0 whitespace-nowrap !px-2 !py-0.5 !text-[10px]">{{ $ph['texto'] }}</span>
                            </div>
                            <p class="mt-0.5 text-[10px] leading-snug text-slate-500">
                                {{ $h->fecha_desde->format('d/m/Y') }} – {{ $h->fecha_hasta?->format('d/m/Y') }}
                                @if ($h->evaluado_at)
                                    · Evaluado el {{ $h->evaluado_at->timezone('America/Lima')->format('d/m/Y') }}{{ $h->evaluadoPor ? ' por '.$h->evaluadoPor->name : '' }}
                                @endif
                            </p>
                            @if ($h->evaluacion_observacion)
                                <p class="mt-0.5 text-[10px] leading-snug text-slate-400">{{ $h->evaluacion_observacion }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="py-6 text-center text-xs text-slate-500">Sin compromisos.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
