<?php

use Illuminate\Support\Facades\Auth;
use App\Models\Area;
use App\Models\CandidatoReclutamiento;
use App\Models\Campana;
use App\Models\Cargo;
use App\Models\Puesto;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel', ['title' => 'Gestión de candidatos'])] class extends Component
{
    public string $busqueda = '';
    public string $vistaActiva = 'reclutamiento';
    public int $limite = 30;
    public ?int $candidatoEditandoId = null;
    public bool $editorAbierto = false;
    public string $editorPestana = 'reclutamiento';
    public array $form = [];
    public array $formCapacitacion = [];
    public array $areas = [];
    public array $areasConfig = [];
    public array $puestosPorArea = [];
    public array $puestosSinCampana = [];
    public array $campanasPorArea = [];
    public array $cargosPorArea = [];
    public array $agentesReclutadores = [];
    public array $meses = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
    public array $distritos = ['ATE', 'BARRANCO', 'BREÑA', 'CARABAYLLO', 'CHACLACAYO', 'CHORRILLOS', 'CIENEGUILLA', 'COMAS', 'EL AGUSTINO', 'INDEPENDENCIA', 'JESÚS MARÍA', 'LA MOLINA', 'LA VICTORIA', 'LIMA', 'LINCE', 'LOS OLIVOS', 'LURIGANCHO', 'LURÍN', 'MAGDALENA DEL MAR', 'PUEBLO LIBRE', 'MIRAFLORES', 'PACHACÁMAC', 'PUCUSANA', 'PUENTE PIEDRA', 'PUNTA HERMOSA', 'PUNTA NEGRA', 'RÍMAC', 'SAN BARTOLO', 'SAN BORJA', 'SAN ISIDRO', 'SAN JUAN DE LURIGANCHO', 'SAN JUAN DE MIRAFLORES', 'SAN LUIS', 'SAN MARTÍN DE PORRES', 'SAN MIGUEL', 'SANTA ANITA', 'SANTA MARÍA DEL MAR', 'SANTA ROSA', 'SANTIAGO DE SURCO', 'SURQUILLO', 'VILLA EL SALVADOR', 'VILLA MARÍA DEL TRIUNFO', 'OTROS'];
    public ?string $mensajeEstado = null;

    private function verificarAdministrador(): void
    {
        abort_unless(Auth::user()?->esAdmin(), 403);
    }

    private function estadoInicial(): array
    {
        return [
            'mes' => mb_strtoupper(now('America/Lima')->locale('es')->translatedFormat('F')), 'edad' => '', 'distrito' => '', 'cargo' => '',
            'nombres' => '', 'apellidos' => '', 'numero_celular' => '', 'dni_ce' => '',
            'area' => '', 'puesto' => '', 'campana' => '', 'agente_reclutador' => '',
            'fecha_gestion' => now('America/Lima')->format('Y-m-d'), 'tipificacion' => '',
            'subtipificacion_rechazo' => '', 'aceptacion_entrevista' => '', 'fecha_entrevista' => '',
            'hora_entrevista' => '', 'asistio_entrevista' => '', 'fecha_reprogramada' => '',
            'asistio_entrevista_reprogramada' => '', 'observaciones' => '',
        ];
    }

    private function estadoCapacitacion(CandidatoReclutamiento $candidato): array
    {
        return [
            'capacitacion_1' => $candidato->capacitacion_1?->format('Y-m-d') ?? '',
            'asistio_cap_1' => $candidato->asistio_cap_1 ?? '',
            'obs_1' => $candidato->obs_1 ?? '',
            'capacitacion_1_reprogramada' => $candidato->capacitacion_1_reprogramada?->format('Y-m-d') ?? '',
            'asistio_cap_1_reprogramada' => $candidato->asistio_cap_1_reprogramada ?? '',
            'obs_1_reprogramada' => $candidato->obs_1_reprogramada ?? '',
            'capacitacion_2' => $candidato->capacitacion_2?->format('Y-m-d') ?? '',
            'asistio_cap_2' => $candidato->asistio_cap_2 ?? '',
            'obs_2' => $candidato->obs_2 ?? '',
            'capacitacion_2_reprogramada' => $candidato->capacitacion_2_reprogramada?->format('Y-m-d') ?? '',
            'asistio_cap_2_reprogramada' => $candidato->asistio_cap_2_reprogramada ?? '',
            'obs_2_reprogramada' => $candidato->obs_2_reprogramada ?? '',
            'apto' => (bool) $candidato->apto,
        ];
    }

    public function nuevoCandidato(): void
    {
        $this->verificarAdministrador();
        $this->resetValidation();
        $this->mensajeEstado = null;
        $this->candidatoEditandoId = null;
        $this->form = $this->estadoInicial();
        $this->formCapacitacion = [];
        $this->editorPestana = 'reclutamiento';
        $this->editorAbierto = true;
    }

    public function editarCandidato(int $id): void
    {
        $this->verificarAdministrador();
        $this->resetValidation();
        $this->mensajeEstado = null;
        $candidato = CandidatoReclutamiento::findOrFail($id);
        $this->candidatoEditandoId = $id;
        $this->form = array_merge($this->estadoInicial(), $candidato->only([
            'mes', 'edad', 'distrito', 'cargo', 'nombres', 'apellidos', 'numero_celular', 'dni_ce', 'area', 'puesto', 'campana', 'agente_reclutador',
            'tipificacion', 'subtipificacion_rechazo', 'aceptacion_entrevista', 'hora_entrevista', 'asistio_entrevista',
            'asistio_entrevista_reprogramada', 'observaciones',
        ]), [
            'fecha_gestion' => $candidato->fecha_gestion?->format('Y-m-d') ?? '',
            'fecha_entrevista' => $candidato->fecha_entrevista?->format('Y-m-d') ?? '',
            'fecha_reprogramada' => $candidato->fecha_reprogramada?->format('Y-m-d') ?? '',
        ]);
        $this->formCapacitacion = $this->estadoCapacitacion($candidato);
        $this->editorPestana = $this->candidatoGraduado($candidato) ? 'capacitacion' : 'reclutamiento';
        $this->editorAbierto = true;
    }

    private function candidatoGraduado(CandidatoReclutamiento $candidato): bool
    {
        return $candidato->asistio_entrevista === 'SI, APTO'
            || ($candidato->asistio_entrevista === 'REPROGRAMADO' && $candidato->asistio_entrevista_reprogramada === 'SI, APTO');
    }

    public function guardarReclutamiento(): void
    {
        $this->verificarAdministrador();
        $datos = $this->validate([
            'form.mes' => 'nullable|string|max:30',
            'form.edad' => 'nullable|string|max:10',
            'form.distrito' => 'nullable|string|max:100',
            'form.cargo' => 'nullable|string|max:160',
            'form.nombres' => 'required|string|max:120',
            'form.apellidos' => 'required|string|max:160',
            'form.numero_celular' => 'nullable|string|max:40',
            'form.dni_ce' => 'nullable|string|max:30',
            'form.area' => 'nullable|string|max:120',
            'form.puesto' => 'nullable|string|max:160',
            'form.campana' => 'nullable|string|max:160',
            'form.agente_reclutador' => 'nullable|string|max:160',
            'form.fecha_gestion' => 'nullable|date',
            'form.tipificacion' => 'nullable|string|max:80',
            'form.subtipificacion_rechazo' => 'nullable|string|max:100',
            'form.aceptacion_entrevista' => 'nullable|in:SI,NO',
            'form.fecha_entrevista' => 'nullable|date',
            'form.hora_entrevista' => 'nullable|string|max:30',
            'form.asistio_entrevista' => ['nullable', Rule::in(['SI, APTO', 'SI, NO APTO', 'NO', 'REPROGRAMADO'])],
            'form.fecha_reprogramada' => 'nullable|date',
            'form.asistio_entrevista_reprogramada' => ['nullable', Rule::in(['SI, APTO', 'SI, NO APTO', 'NO'])],
            'form.observaciones' => 'nullable|string|max:2000',
        ])['form'];

        if (($datos['aceptacion_entrevista'] ?? null) !== 'SI'
            && (! empty($datos['fecha_entrevista']) || ! empty($datos['asistio_entrevista']) || ! empty($datos['fecha_reprogramada']))) {
            $this->addError('form.aceptacion_entrevista', 'Registra la aceptación antes de programar o evaluar la entrevista.');
            return;
        }

        $normalizar = fn ($valor) => is_string($valor) && trim($valor) !== '' ? mb_strtoupper(trim($valor)) : null;
        foreach (['mes', 'edad', 'distrito', 'cargo', 'nombres', 'apellidos', 'numero_celular', 'dni_ce', 'area', 'puesto', 'campana', 'agente_reclutador', 'tipificacion', 'subtipificacion_rechazo', 'aceptacion_entrevista', 'hora_entrevista', 'asistio_entrevista', 'asistio_entrevista_reprogramada', 'observaciones'] as $campo) {
            $datos[$campo] = $normalizar($datos[$campo] ?? null);
        }
        if (! in_array($datos['tipificacion'], ['NO INTERESADO', 'NO PASA FILTRO'], true)) {
            $datos['subtipificacion_rechazo'] = null;
        }
        if ($datos['asistio_entrevista'] !== 'REPROGRAMADO') {
            $datos['fecha_reprogramada'] = null;
            $datos['asistio_entrevista_reprogramada'] = null;
        } elseif (empty($datos['fecha_reprogramada'])) {
            $datos['asistio_entrevista_reprogramada'] = null;
        }

        $candidato = $this->candidatoEditandoId
            ? CandidatoReclutamiento::findOrFail($this->candidatoEditandoId)
            : new CandidatoReclutamiento();
        if ($candidato->exists && $this->candidatoGraduado($candidato)) {
            $this->addError('form.nombres', 'Este registro ya pasó a capacitación y el reclutamiento quedó cerrado.');
            return;
        }
        if ($this->puestoSinCampana($datos['area'] ?? null, $datos['puesto'] ?? null)) {
            $datos['campana'] = 'NO APLICA';
        }
        $candidato->fill($datos);
        $candidato->save();
        $this->candidatoEditandoId = $candidato->id;
        $this->formCapacitacion = $this->estadoCapacitacion($candidato);
        $this->mensajeEstado = 'Datos de reclutamiento guardados.';
        if ($this->candidatoGraduado($candidato)) {
            $this->editorPestana = 'capacitacion';
        }
    }

    public function guardarProcesoCapacitacion(): void
    {
        $this->verificarAdministrador();
        $candidato = CandidatoReclutamiento::findOrFail($this->candidatoEditandoId);
        abort_unless($this->candidatoGraduado($candidato), 422);
        abort_if($candidato->apto, 422, 'El candidato ya está marcado como apto.');

        $d = $this->validate([
            'formCapacitacion.capacitacion_1' => 'nullable|date',
            'formCapacitacion.asistio_cap_1' => ['nullable', Rule::in(['SI, APTO', 'SI, NO APTO', 'NO', 'REPROGRAMADO'])],
            'formCapacitacion.obs_1' => 'nullable|string|max:1000',
            'formCapacitacion.capacitacion_1_reprogramada' => 'nullable|date',
            'formCapacitacion.asistio_cap_1_reprogramada' => ['nullable', Rule::in(['SI, APTO', 'SI, NO APTO', 'NO'])],
            'formCapacitacion.obs_1_reprogramada' => 'nullable|string|max:1000',
            'formCapacitacion.capacitacion_2' => 'nullable|date',
            'formCapacitacion.asistio_cap_2' => ['nullable', Rule::in(['SI, APTO', 'SI, NO APTO', 'NO', 'REPROGRAMADO'])],
            'formCapacitacion.obs_2' => 'nullable|string|max:1000',
            'formCapacitacion.capacitacion_2_reprogramada' => 'nullable|date',
            'formCapacitacion.asistio_cap_2_reprogramada' => ['nullable', Rule::in(['SI, APTO', 'SI, NO APTO', 'NO'])],
            'formCapacitacion.obs_2_reprogramada' => 'nullable|string|max:1000',
            'formCapacitacion.apto' => 'boolean',
        ])['formCapacitacion'];

        $c1 = $d['asistio_cap_1'] ?? null;
        if (empty($d['capacitacion_1'])) {
            $d['asistio_cap_1'] = $d['capacitacion_1_reprogramada'] = $d['asistio_cap_1_reprogramada'] = $d['obs_1_reprogramada'] = null;
        } elseif ($c1 !== 'REPROGRAMADO') {
            $d['capacitacion_1_reprogramada'] = $d['asistio_cap_1_reprogramada'] = $d['obs_1_reprogramada'] = null;
        } elseif (empty($d['capacitacion_1_reprogramada'])) {
            $d['asistio_cap_1_reprogramada'] = null;
        }

        $cap1Apto = ($d['asistio_cap_1'] ?? null) === 'SI, APTO'
            || (($d['asistio_cap_1'] ?? null) === 'REPROGRAMADO' && ($d['asistio_cap_1_reprogramada'] ?? null) === 'SI, APTO');
        if (! $cap1Apto) {
            foreach (['capacitacion_2', 'asistio_cap_2', 'obs_2', 'capacitacion_2_reprogramada', 'asistio_cap_2_reprogramada', 'obs_2_reprogramada'] as $campo) $d[$campo] = null;
        } elseif (empty($d['capacitacion_2'])) {
            $d['asistio_cap_2'] = $d['capacitacion_2_reprogramada'] = $d['asistio_cap_2_reprogramada'] = $d['obs_2_reprogramada'] = null;
        } elseif (($d['asistio_cap_2'] ?? null) !== 'REPROGRAMADO') {
            $d['capacitacion_2_reprogramada'] = $d['asistio_cap_2_reprogramada'] = $d['obs_2_reprogramada'] = null;
        } elseif (empty($d['capacitacion_2_reprogramada'])) {
            $d['asistio_cap_2_reprogramada'] = null;
        }

        $cap2Apto = ($d['asistio_cap_2'] ?? null) === 'SI, APTO'
            || (($d['asistio_cap_2'] ?? null) === 'REPROGRAMADO' && ($d['asistio_cap_2_reprogramada'] ?? null) === 'SI, APTO');
        $nuevoApto = (bool) ($d['apto'] ?? false) && $cap2Apto;
        $d['apto'] = $nuevoApto;
        $d['fecha_apto'] = $nuevoApto ? ($candidato->fecha_apto ?: now()) : null;
        $candidato->update($d);
        $this->formCapacitacion = $this->estadoCapacitacion($candidato->fresh());
        $this->mensajeEstado = 'Proceso de capacitación guardado.';
    }

    public function cerrarEditor(): void
    {
        $this->verificarAdministrador();
        $this->editorAbierto = false;
    }

    public function updatedBusqueda(): void
    {
        $this->limite = 30;
    }

    public function updatedVistaActiva(): void
    {
        $this->limite = 30;
    }

    public function cargarMas(): void
    {
        $this->limite += 30;
    }

    public function puedeEditarCapacitacion(): bool
    {
        if (! $this->candidatoEditandoId) return false;
        $candidato = CandidatoReclutamiento::find($this->candidatoEditandoId);
        return $candidato ? $this->candidatoGraduado($candidato) : false;
    }

    public function puedeProgramarCapacitacion2(): bool
    {
        $cap1 = $this->formCapacitacion['asistio_cap_1'] ?? null;
        return $cap1 === 'SI, APTO'
            || ($cap1 === 'REPROGRAMADO' && ($this->formCapacitacion['asistio_cap_1_reprogramada'] ?? null) === 'SI, APTO');
    }

    public function puedeMarcarCandidatoApto(): bool
    {
        $cap2 = $this->formCapacitacion['asistio_cap_2'] ?? null;
        return $cap2 === 'SI, APTO'
            || ($cap2 === 'REPROGRAMADO' && ($this->formCapacitacion['asistio_cap_2_reprogramada'] ?? null) === 'SI, APTO');
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);
        $areas = Area::query()->orderBy('nombre')->get();
        $this->areas = $areas->pluck('nombre')->values()->all();
        $this->areasConfig = $areas->mapWithKeys(fn ($area) => [$area->nombre => ['usa_campana' => $area->usa_campana, 'usa_cargo' => $area->usa_cargo]])->all();
        $this->puestosPorArea = $areas->mapWithKeys(fn ($area) => [$area->nombre => Puesto::where('area_id', $area->id)->orderBy('nombre')->pluck('nombre')->values()->all()])->all();
        $this->puestosSinCampana = $areas->mapWithKeys(fn ($area) => [$area->nombre => Puesto::where('area_id', $area->id)->where('usa_campana', false)->pluck('nombre')->values()->all()])->all();
        $this->campanasPorArea = $areas->mapWithKeys(fn ($area) => [$area->nombre => Campana::where('area_id', $area->id)->orderBy('nombre')->pluck('nombre')->values()->all()])->all();
        $this->cargosPorArea = $areas->mapWithKeys(fn ($area) => [$area->nombre => Cargo::where('area_id', $area->id)->orderBy('nombre')->pluck('nombre')->values()->all()])->all();
        $this->agentesReclutadores = User::query()->where('role', User::ROL_GTH)->orderBy('name')->pluck('name')->values()->all();
    }

    public function updatedFormArea(): void
    {
        $this->form['puesto'] = '';
        $this->form['campana'] = '';
        $this->form['cargo'] = '';
    }

    public function updatedFormPuesto(): void
    {
        $this->form['campana'] = $this->puestoSinCampana($this->form['area'] ?? null, $this->form['puesto'] ?? null) ? 'NO APLICA' : '';
    }

    public function updatedFormFechaGestion(): void
    {
        if (! empty($this->form['fecha_gestion'])) {
            $this->form['mes'] = $this->meses[(int) date('n', strtotime($this->form['fecha_gestion'])) - 1] ?? '';
        }
    }

    public function puedeEditarReclutamiento(): bool
    {
        if (! $this->candidatoEditandoId) return true;
        $candidato = CandidatoReclutamiento::find($this->candidatoEditandoId);
        return $candidato ? ! $this->candidatoGraduado($candidato) : false;
    }

    private function puestoSinCampana(?string $area, ?string $puesto): bool
    {
        return $area && $puesto && in_array($puesto, $this->puestosSinCampana[$area] ?? [], true);
    }

    public function getRegistrosProperty(): array
    {
        $this->verificarAdministrador();
        return CandidatoReclutamiento::query()
            ->orderByDesc('id')
            ->get()
            ->map(function (CandidatoReclutamiento $candidato): array {
                $graduado = $candidato->asistio_entrevista === 'SI, APTO'
                    || ($candidato->asistio_entrevista === 'REPROGRAMADO' && $candidato->asistio_entrevista_reprogramada === 'SI, APTO');

                if ($candidato->apto) {
                    $fase = 'capacitacion';
                    $paso = 5;
                    $etapa = 'Apto';
                    $detalle = $candidato->fecha_apto ? 'Apto desde '.$candidato->fecha_apto->format('d/m/Y') : 'Capacitaciones completadas';
                } elseif ($graduado) {
                    $fase = 'capacitacion';
                    $cap1Resultado = $candidato->asistio_cap_1 === 'REPROGRAMADO' ? $candidato->asistio_cap_1_reprogramada : $candidato->asistio_cap_1;
                    $cap2Resultado = $candidato->asistio_cap_2 === 'REPROGRAMADO' ? $candidato->asistio_cap_2_reprogramada : $candidato->asistio_cap_2;
                    if ($candidato->capacitacion_2 || $candidato->asistio_cap_2) {
                        $paso = 4;
                        $etapa = 'Capacitación 2';
                        $detalle = 'Cap. 1: '.($cap1Resultado ?: 'pendiente').' · Cap. 2: '.($cap2Resultado ?: $candidato->capacitacion_2?->format('d/m/Y') ?: 'pendiente');
                    } elseif ($candidato->capacitacion_1 || $candidato->asistio_cap_1) {
                        $paso = 3;
                        $etapa = 'Capacitación 1';
                        $detalle = 'Cap. 1: '.($cap1Resultado ?: $candidato->capacitacion_1?->format('d/m/Y') ?: 'pendiente').' · Cap. 2 pendiente';
                    } else {
                        $paso = 3;
                        $etapa = 'Pendiente de capacitación 1';
                        $detalle = 'Entrevista aprobada · Falta programar capacitación';
                    }
                } else {
                    $fase = 'reclutamiento';
                    $paso = 0;
                    $etapa = 'Nuevo registro';
                    $detalle = 'Tipificación: '.($candidato->tipificacion ?: 'pendiente');
                    if ($candidato->asistio_entrevista === 'REPROGRAMADO') {
                        $paso = 2;
                        $etapa = 'Entrevista reprogramada';
                        $detalle = 'Nueva fecha: '.($candidato->fecha_reprogramada?->format('d/m/Y') ?: 'pendiente de programar');
                    } elseif ($candidato->asistio_entrevista) {
                        $paso = 2;
                        $etapa = str_contains($candidato->asistio_entrevista, 'NO APTO') ? 'No apto en entrevista' : 'Entrevista realizada';
                        $detalle = 'Resultado: '.$candidato->asistio_entrevista;
                    } elseif ($candidato->fecha_entrevista) {
                        $paso = 2;
                        $etapa = 'Entrevista programada';
                        $detalle = 'Entrevista: '.$candidato->fecha_entrevista->format('d/m/Y').($candidato->hora_entrevista ? ' · '.$candidato->hora_entrevista : '');
                    } elseif ($candidato->tipificacion) {
                        $paso = 1;
                        $etapa = $candidato->tipificacion === 'INTERESADO - APTO' ? 'Interesado · apto' : $candidato->tipificacion;
                    }
                }

                $nombre = trim($candidato->nombreCompleto()) ?: 'Sin nombre';
                $nombre = mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE, 'UTF-8');
                $iniciales = collect(preg_split('/\s+/u', $nombre))->filter()->take(2)->map(fn ($parte) => mb_substr($parte, 0, 1))->implode('');

                return [
                    'id' => $candidato->id,
                    'nombre' => $nombre,
                    'iniciales' => $iniciales ?: 'SC',
                    'puesto' => $candidato->puesto ?: 'Puesto sin asignar',
                    'area' => $candidato->area ?: 'Área sin asignar',
                    'campana' => $candidato->campana ?: 'Sin campaña',
                    'etapa' => mb_convert_case(mb_strtolower($etapa), MB_CASE_TITLE, 'UTF-8'),
                    'fase' => $fase,
                    'paso' => $paso,
                    'detalle' => mb_convert_case(mb_strtolower($detalle), MB_CASE_TITLE, 'UTF-8'),
                    'reclutador' => $candidato->agente_reclutador ?: 'Sin asignar',
                    'telefono' => $candidato->numero_celular ?: 'Sin teléfono',
                ];
            })
            ->all();
    }

    public function getFiltradosProperty(): array
    {
        return $this->candidatosFiltrados()->take($this->limite)->all();
    }

    public function getTotalFiltradosProperty(): int
    {
        return $this->candidatosFiltrados()->count();
    }

    public function getHayMasProperty(): bool
    {
        return $this->totalFiltrados > $this->limite;
    }

    private function candidatosFiltrados()
    {
        return collect($this->registros)->filter(fn ($r) => $r['fase'] === $this->vistaActiva
            && ($this->busqueda === '' || str_contains(mb_strtolower($r['nombre'].' '.$r['puesto'].' '.$r['campana'].' '.$r['telefono']), mb_strtolower($this->busqueda))))->values();
    }
};
?>

<div class="candidate-demo space-y-6">
    <div class="candidate-demo-head flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
            <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700">
                <span class="h-1.5 w-1.5 rounded-full bg-violet-500"></span> Vista previa · Datos actuales · Solo administrador
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Gestión de candidatos</h1>
            <p class="mt-1 text-sm text-slate-500">Cada postulante como un registro completo, con seguimiento por etapa.</p>
        </div>
        <button type="button" wire:click="nuevoCandidato" class="candidate-create-button">+ Registrar candidato</button>
    </div>

    <section class="candidate-flow-panel overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="candidate-flow-toolbar flex flex-col justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:flex-row sm:items-center sm:px-5">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="$set('vistaActiva','reclutamiento')" class="candidate-flow-tab {{ $vistaActiva === 'reclutamiento' ? 'is-active' : '' }}">Gestión de candidatos <span>{{ collect($this->registros)->where('fase','reclutamiento')->count() }}</span></button>
                <button type="button" wire:click="$set('vistaActiva','capacitacion')" class="candidate-flow-tab {{ $vistaActiva === 'capacitacion' ? 'is-active' : '' }}">Proceso de capacitación <span>{{ collect($this->registros)->where('fase','capacitacion')->count() }}</span></button>
            </div>
            <label class="candidate-search relative block"><svg class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m16 16 4 4"/></svg><input wire:model.live.debounce.250ms="busqueda" class="w-full rounded-lg border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100" placeholder="Buscar por nombre, puesto o campaña" /></label>
        </div>
        <div class="candidate-flow-legend hidden border-b border-slate-100 bg-slate-50 px-5 py-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400 md:grid">
            <span>Candidato</span><span>Vacante · campaña</span><span>Seguimiento del proceso</span><span>Responsable</span><span>Estado</span>
        </div>
        <div class="candidate-records-count">{{ $this->totalFiltrados }} candidatos <span>·</span> mostrando {{ count($this->filtrados) }} en esta vista</div>
        <div class="divide-y divide-slate-100">
            @forelse ($this->filtrados as $candidato)
                <article class="candidate-register-row px-4 py-2.5 sm:px-5">
                    <div class="candidate-register-main">
                        <div class="candidate-register-person-cell"><span class="candidate-register-avatar">{{ $candidato['iniciales'] }}</span><div class="candidate-register-person"><strong>{{ $candidato['nombre'] }}</strong><span>{{ $candidato['telefono'] }}</span></div></div>
                        <div class="candidate-register-role"><strong>{{ $candidato['puesto'] }}</strong><span>{{ $candidato['area'] }} · {{ $candidato['campana'] }}</span></div>
                        <div class="candidate-register-stages">
                            @foreach (['Registro','Filtro','Entrevista','Cap. 1','Cap. 2','Apto'] as $indice => $paso)
                                <span class="candidate-stage {{ $indice < $candidato['paso'] ? 'is-done' : ($indice === $candidato['paso'] ? 'is-current' : '') }}"><i>{{ $indice < $candidato['paso'] ? '✓' : $indice + 1 }}</i><small>{{ $paso }}</small></span>
                            @endforeach
                        </div>
                        <div class="candidate-register-owner"><span>Reclutador</span><strong>{{ $candidato['reclutador'] }}</strong></div>
                        <div class="candidate-register-status"><span class="candidate-status-pill {{ $candidato['etapa'] === 'Apto' ? 'is-success' : '' }}">{{ $candidato['etapa'] }}</span><small>{{ $candidato['detalle'] }}</small><button type="button" wire:click="editarCandidato({{ $candidato['id'] }})" class="candidate-manage-button">Gestionar registro</button></div>
                    </div>
                    <div class="candidate-register-mobile-flow"><span><b>{{ $candidato['etapa'] }}</b> · {{ $candidato['detalle'] }} · {{ $candidato['reclutador'] }}</span><button type="button" wire:click="editarCandidato({{ $candidato['id'] }})" class="candidate-manage-button">Gestionar</button></div>
                </article>
            @empty
                <div class="p-8 text-center text-sm text-slate-500">No hay registros para esta búsqueda.</div>
            @endforelse
        </div>
        @if ($this->hayMas)
            <div class="candidate-load-more"><button type="button" wire:click="cargarMas" class="candidate-cancel-button">Cargar 30 más</button></div>
        @endif
        <div class="border-t border-slate-100 bg-slate-50/70 px-5 py-2.5 text-[11px] text-slate-400">Los cambios se guardan en el proceso actual.</div>
    </section>

    @if ($editorAbierto)
        <div class="candidate-editor-backdrop" role="presentation" wire:click.self="cerrarEditor">
            <section class="candidate-editor" role="dialog" aria-modal="true" aria-labelledby="candidate-editor-title">
                <header class="candidate-editor-header">
                    <div><p class="text-xs font-semibold uppercase tracking-wide text-violet-600">Registro de candidato</p><h2 id="candidate-editor-title" class="mt-1 text-lg font-bold text-slate-900">{{ $candidatoEditandoId ? trim(($form['nombres'] ?? '').' '.($form['apellidos'] ?? '')) : 'Nuevo candidato' }}</h2></div>
                    <button type="button" wire:click="cerrarEditor" class="candidate-editor-close" aria-label="Cerrar">×</button>
                </header>
                <nav class="candidate-editor-tabs">
                    <button type="button" wire:click="$set('editorPestana','reclutamiento')" class="candidate-flow-tab {{ $editorPestana === 'reclutamiento' ? 'is-active' : '' }}">1. Reclutamiento</button>
                    <button type="button" wire:click="$set('editorPestana','capacitacion')" @disabled(! $this->puedeEditarCapacitacion()) class="candidate-flow-tab {{ $editorPestana === 'capacitacion' ? 'is-active' : '' }}">2. Capacitación</button>
                </nav>
                <div class="candidate-editor-body">
                    @if ($mensajeEstado)<p class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm font-medium text-emerald-700">{{ $mensajeEstado }}</p>@endif
                    @if ($errors->any())<p class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">Revisa los campos marcados y vuelve a guardar.</p>@endif
                    @if ($editorPestana === 'reclutamiento')
                        @if (! $this->puedeEditarReclutamiento())
                            <p class="mb-3 rounded-lg bg-violet-50 px-3 py-2 text-xs text-violet-800">Entrevista aprobada: el registro de reclutamiento está cerrado. Continúa el seguimiento en capacitación.</p>
                        @else
                            <div class="candidate-form-guidance"><b>1. Registro y filtro</b><span>Completa los datos y tipifica el resultado para avanzar a entrevista.</span></div>
                        @endif
                        <fieldset class="candidate-form-grid" @disabled(! $this->puedeEditarReclutamiento())>
                            <div class="candidate-form-section">Datos del candidato</div>
                            <label>Nombre(s)<input wire:model="form.nombres" class="form-input" autocomplete="off"></label>
                            <label>Apellidos<input wire:model="form.apellidos" class="form-input" autocomplete="off"></label>
                            <label>Celular<input wire:model="form.numero_celular" class="form-input" inputmode="tel"></label>
                            <label>DNI / CE<input wire:model="form.dni_ce" class="form-input"></label>
                            <label>Edad<input wire:model="form.edad" class="form-input" inputmode="numeric"></label>
                            <label>Distrito<select wire:model="form.distrito" class="form-input"><option value="">Seleccionar</option>@if (!empty($form['distrito']) && !in_array($form['distrito'], $distritos, true))<option value="{{ $form['distrito'] }}">{{ $form['distrito'] }}</option>@endif @foreach ($distritos as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            <div class="candidate-form-section">Vacante y gestión</div>
                            <label>Área<select wire:model.live="form.area" class="form-input"><option value="">Seleccionar área</option>@if (!empty($form['area']) && !in_array($form['area'], $areas, true))<option value="{{ $form['area'] }}">{{ $form['area'] }}</option>@endif @foreach ($areas as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            <label>Puesto<select wire:model.live="form.puesto" class="form-input" @disabled(empty($form['area'] ?? ''))><option value="">Seleccionar puesto</option>@if (!empty($form['puesto']) && !in_array($form['puesto'], $puestosPorArea[$form['area'] ?? ''] ?? [], true))<option value="{{ $form['puesto'] }}">{{ $form['puesto'] }}</option>@endif @foreach (($puestosPorArea[$form['area'] ?? ''] ?? []) as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            @php($sinCampana = in_array($form['puesto'] ?? '', $puestosSinCampana[$form['area'] ?? ''] ?? [], true))
                            @if ($sinCampana)<label>Campaña<input value="NO APLICA" class="form-input" disabled></label>@else<label>Campaña<select wire:model="form.campana" class="form-input" @disabled(empty($form['area'] ?? ''))><option value="">Seleccionar campaña</option>@if (!empty($form['campana']) && !in_array($form['campana'], $campanasPorArea[$form['area'] ?? ''] ?? [], true))<option value="{{ $form['campana'] }}">{{ $form['campana'] }}</option>@endif @foreach (($campanasPorArea[$form['area'] ?? ''] ?? []) as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>@endif
                            @if (($areasConfig[$form['area'] ?? '']['usa_cargo'] ?? false))<label>Cargo<select wire:model="form.cargo" class="form-input"><option value="">Seleccionar cargo</option>@foreach (($cargosPorArea[$form['area'] ?? ''] ?? []) as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>@endif
                            <label>Reclutador<select wire:model="form.agente_reclutador" class="form-input"><option value="">Seleccionar</option>@if (!empty($form['agente_reclutador']) && !in_array($form['agente_reclutador'], $agentesReclutadores, true))<option value="{{ $form['agente_reclutador'] }}">{{ $form['agente_reclutador'] }}</option>@endif @foreach ($agentesReclutadores as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            <label>Fecha de gestión<input type="date" wire:model="form.fecha_gestion" class="form-input"></label>
                            <label>Mes<select wire:model="form.mes" class="form-input"><option value="">Seleccionar</option>@if (!empty($form['mes']) && !in_array($form['mes'], $meses, true))<option value="{{ $form['mes'] }}">{{ $form['mes'] }}</option>@endif @foreach ($meses as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            <div class="candidate-form-section">Filtro y entrevista</div>
                            <label>Tipificación<select wire:model.live="form.tipificacion" class="form-input"><option value="">Seleccionar</option>@foreach (['INTERESADO - APTO','NO INTERESADO','NO PASA FILTRO','NO CONTESTA','APAGADO','FUERA DE SERVICIO','VOLVER A LLAMAR'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            @if (in_array($form['tipificacion'] ?? '', ['NO INTERESADO','NO PASA FILTRO'], true))
                                <label>Motivo de rechazo<select wire:model="form.subtipificacion_rechazo" class="form-input"><option value="">Seleccionar</option>@foreach (['SUELDO','DISTANCIA','TRABAJA REMOTO','HORARIOS DE ESTUDIO','NO DA MOTIVOS','DESEA OTRA POSICIÓN','YA SE ENCUENTRA LABORANDO','CORTA LA LLAMADA','NO POSEE EXPERIENCIA','EXTRANJERO','FALTA DE RESPETO','TERCERA EDAD'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            @endif
                            <label>Acepta entrevista<select wire:model="form.aceptacion_entrevista" class="form-input"><option value="">Pendiente</option><option value="SI">Sí</option><option value="NO">No</option></select></label>
                            <label>Fecha de entrevista<input type="date" wire:model="form.fecha_entrevista" class="form-input"></label>
                            <label>Hora de entrevista<input type="time" wire:model="form.hora_entrevista" class="form-input"></label>
                            <label>Resultado de entrevista<select wire:model.live="form.asistio_entrevista" class="form-input"><option value="">Pendiente</option>@foreach (['SI, APTO','SI, NO APTO','NO','REPROGRAMADO'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            @if (($form['asistio_entrevista'] ?? '') === 'REPROGRAMADO')
                                <label>Nueva fecha<input type="date" wire:model="form.fecha_reprogramada" class="form-input"></label>
                                <label>Resultado reprogramado<select wire:model="form.asistio_entrevista_reprogramada" class="form-input"><option value="">Pendiente</option>@foreach (['SI, APTO','SI, NO APTO','NO'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label>
                            @endif
                            <label class="candidate-form-wide">Observaciones<textarea wire:model="form.observaciones" class="form-input" rows="2"></textarea></label>
                        </fieldset>
                        @error('form.nombres')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                        @error('form.apellidos')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                        @error('form.aceptacion_entrevista')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @else
                        @if ($this->puedeEditarCapacitacion())
                            @php($candidatoApto = (bool) ($formCapacitacion['apto'] ?? false))
                            <div class="candidate-training-grid">
                                <section class="candidate-training-step"><h3>Capacitación 1</h3><label>Fecha<input type="date" wire:model="formCapacitacion.capacitacion_1" class="form-input" @disabled($candidatoApto)></label><label>Asistencia<select wire:model.live="formCapacitacion.asistio_cap_1" class="form-input" @disabled($candidatoApto || empty($formCapacitacion['capacitacion_1']))><option value="">Pendiente</option>@foreach (['SI, APTO','SI, NO APTO','NO','REPROGRAMADO'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label><label>Observación<textarea wire:model="formCapacitacion.obs_1" class="form-input" rows="2" @disabled($candidatoApto)></textarea></label>
                                    @if (($formCapacitacion['asistio_cap_1'] ?? '') === 'REPROGRAMADO')<div class="candidate-reschedule"><strong>Reprogramación de capacitación 1</strong><label>Fecha<input type="date" wire:model="formCapacitacion.capacitacion_1_reprogramada" class="form-input" @disabled($candidatoApto)></label><label>Resultado<select wire:model="formCapacitacion.asistio_cap_1_reprogramada" class="form-input" @disabled($candidatoApto || empty($formCapacitacion['capacitacion_1_reprogramada']))><option value="">Pendiente</option>@foreach (['SI, APTO','SI, NO APTO','NO'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label><label>Observación<textarea wire:model="formCapacitacion.obs_1_reprogramada" class="form-input" rows="2" @disabled($candidatoApto)></textarea></label></div>@endif
                                </section>
                                <section class="candidate-training-step"><h3>Capacitación 2</h3><p class="mb-2 text-xs text-slate-500">Se habilita cuando la capacitación 1 queda como apta.</p><label>Fecha<input type="date" wire:model="formCapacitacion.capacitacion_2" class="form-input" @disabled($candidatoApto || ! $this->puedeProgramarCapacitacion2())></label><label>Asistencia<select wire:model.live="formCapacitacion.asistio_cap_2" class="form-input" @disabled($candidatoApto || empty($formCapacitacion['capacitacion_2']) || ! $this->puedeProgramarCapacitacion2())><option value="">Pendiente</option>@foreach (['SI, APTO','SI, NO APTO','NO','REPROGRAMADO'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label><label>Observación<textarea wire:model="formCapacitacion.obs_2" class="form-input" rows="2" @disabled($candidatoApto)></textarea></label>
                                    @if (($formCapacitacion['asistio_cap_2'] ?? '') === 'REPROGRAMADO')<div class="candidate-reschedule"><strong>Reprogramación de capacitación 2</strong><label>Fecha<input type="date" wire:model="formCapacitacion.capacitacion_2_reprogramada" class="form-input" @disabled($candidatoApto)></label><label>Resultado<select wire:model="formCapacitacion.asistio_cap_2_reprogramada" class="form-input" @disabled($candidatoApto || empty($formCapacitacion['capacitacion_2_reprogramada']))><option value="">Pendiente</option>@foreach (['SI, APTO','SI, NO APTO','NO'] as $opcion)<option value="{{ $opcion }}">{{ $opcion }}</option>@endforeach</select></label><label>Observación<textarea wire:model="formCapacitacion.obs_2_reprogramada" class="form-input" rows="2" @disabled($candidatoApto)></textarea></label></div>@endif
                                    <label class="candidate-apto-toggle"><input type="checkbox" wire:model="formCapacitacion.apto" @disabled($candidatoApto || ! $this->puedeMarcarCandidatoApto())> Marcar candidato como apto</label>
                                </section>
                            </div>
                            @if ($candidatoApto)<p class="mt-3 rounded-lg bg-emerald-50 p-3 text-sm font-medium text-emerald-700">Proceso completado. El candidato quedó marcado como apto.</p>@endif
                        @else
                            <div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">El candidato pasa a capacitación cuando el resultado de su entrevista sea <strong>SI, APTO</strong>. Guarda ese resultado en la pestaña de reclutamiento para continuar.</div>
                        @endif
                    @endif
                </div>
                <footer class="candidate-editor-footer">
                    @if ($editorPestana === 'reclutamiento' && $this->puedeEditarReclutamiento())
                        <button type="button" wire:click="guardarReclutamiento" class="candidate-save-button">Guardar reclutamiento</button>
                    @elseif ($this->puedeEditarCapacitacion() && ! ($formCapacitacion['apto'] ?? false))
                        <button type="button" wire:click="guardarProcesoCapacitacion" class="candidate-save-button">Guardar capacitación</button>
                    @endif
                    <button type="button" wire:click="cerrarEditor" class="candidate-cancel-button">Cerrar</button>
                </footer>
            </section>
        </div>
    @endif
</div>
