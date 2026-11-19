<?php

use App\Jobs\EnviarAvisoSeguimiento;
use App\Jobs\EnviarRegistrosGerencia;
use App\Jobs\EnviarSeguimientoTrabajador;
use App\Models\Empleado;
use App\Models\SeguimientoAviso;
use App\Models\SeguimientoEnvioGerencia;
use App\Models\SeguimientoRegistro;
use App\Services\CrmDelivery;
use App\Services\Seguimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Pestaña «Seguimiento» del modal de datos del trabajador: compromisos y memorándums. Solo administrador. */
new class extends Component
{
    use WithFileUploads;

    public int $empleadoId;

    public bool $formAbierto = false;

    public string $tipo = SeguimientoRegistro::COMPROMISO;

    public string $titulo = '';

    public string $descripcion = '';

    public string $fechaDesde = '';

    public string $fechaHasta = '';

    public string $metaQ = '';

    public string $metaSots = '';

    public $archivo = null;

    public ?int $evaluandoId = null;

    public ?int $vistaId = null;

    public ?int $resultadoId = null;

    /** Resultado del CRM del compromiso abierto (ver CrmDelivery::resultadoCompromiso). */
    public array $resultado = [];

    public string $evaluacionResultado = '';

    public string $evaluacionObservacion = '';

    /** Modal «Avisos a gerencia» y su envío manual. */
    public bool $avisosAbierto = false;

    /** @var array<int, string> ids de los registros marcados para enviar a gerencia */
    public array $envioSeleccion = [];

    public string $envioMensaje = '';

    public bool $envioAdjuntar = true;

    public string $correosGerencia = '';

    public string $correosAdicionales = '';

    public ?string $mensaje = null;

    public function mount(int $empleadoId): void
    {
        $this->exigirAcceso();
        $this->empleadoId = $empleadoId;
        Empleado::findOrFail($empleadoId);
        $this->cargarCorreos();
    }

    /** Administrador y GTH. (GTH nunca ve remuneración; aquí no hay montos de pago.) */
    private function exigirAcceso(): void
    {
        abort_unless(Auth::user()?->esAdmin() || Auth::user()?->esGth(), 403);
    }

    private function empleado(): Empleado
    {
        return Empleado::findOrFail($this->empleadoId);
    }

    private function registro(int $id): SeguimientoRegistro
    {
        return SeguimientoRegistro::where('empleado_id', $this->empleadoId)->findOrFail($id);
    }

    private function hoy(): string
    {
        return now('America/Lima')->toDateString();
    }

    private function cargarCorreos(): void
    {
        $this->correosGerencia = \App\Models\Ajuste::obtener(Seguimiento::CLAVE_GERENCIA, Seguimiento::GERENCIA_POR_DEFECTO) ?? '';
        $this->correosAdicionales = \App\Models\Ajuste::obtener(Seguimiento::CLAVE_ADICIONAL, '') ?? '';
    }

    public function abrirFormulario(): void
    {
        $this->exigirAcceso();
        $this->reset(['titulo', 'descripcion', 'fechaHasta', 'metaQ', 'metaSots', 'archivo', 'mensaje']);
        $this->resetErrorBag();
        $this->tipo = SeguimientoRegistro::COMPROMISO;
        $this->fechaDesde = $this->hoy();
        $this->formAbierto = true;
    }

    public function cerrarFormulario(): void
    {
        $this->formAbierto = false;
        $this->archivo = null;
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        $this->exigirAcceso();
        $esCompromiso = $this->tipo === SeguimientoRegistro::COMPROMISO;

        $datos = $this->validate([
            'tipo' => 'required|in:compromiso,memorandum',
            'titulo' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:2000',
            'fechaDesde' => 'required|date',
            'fechaHasta' => $esCompromiso ? 'required|date|after_or_equal:fechaDesde' : 'nullable',
            'metaQ' => $esCompromiso ? 'nullable|integer|min:1|max:9999' : 'nullable',
            'metaSots' => $esCompromiso ? 'nullable|integer|min:1|max:9999' : 'nullable',
            'archivo' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ], [
            'archivo.required' => 'Sube el documento firmado.',
            'archivo.mimes' => 'El documento debe ser PDF, imagen (JPG/PNG) o Word.',
            'archivo.max' => 'El documento no puede pasar de 10 MB.',
            'fechaHasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde».',
        ], [
            'titulo' => 'título',
            'fechaDesde' => $esCompromiso ? 'fecha desde' : 'fecha del memorándum',
            'fechaHasta' => 'fecha hasta',
            'metaQ' => 'meta Q',
            'metaSots' => 'meta SOTs',
        ]);

        $ruta = $this->archivo->store('seguimiento', 'local');

        $registro = SeguimientoRegistro::create([
            'empleado_id' => $this->empleadoId,
            'tipo' => $datos['tipo'],
            'titulo' => trim($datos['titulo']),
            'descripcion' => trim((string) $datos['descripcion']) ?: null,
            'fecha_desde' => $datos['fechaDesde'],
            'fecha_hasta' => $esCompromiso ? $datos['fechaHasta'] : null,
            'meta_q' => $esCompromiso && $datos['metaQ'] !== '' ? (int) $datos['metaQ'] : null,
            'meta_sots' => $esCompromiso && $datos['metaSots'] !== '' ? (int) $datos['metaSots'] : null,
            'estado' => $esCompromiso ? SeguimientoRegistro::PENDIENTE : SeguimientoRegistro::REGISTRADO,
            'documento_nombre' => $this->archivo->getClientOriginalName(),
            'documento_ruta' => $ruta,
            'documento_mime' => $this->archivo->getMimeType(),
            'registrado_por_id' => Auth::id(),
        ]);

        $this->cerrarFormulario();
        $this->mensaje = null;

        // Un memorándum no se evalúa: al registrarlo ya cuenta para el aviso de los 3 acumulados.
        if (! $esCompromiso && Seguimiento::revisarAviso($this->empleado(), SeguimientoRegistro::MEMORANDUM)) {
            $this->mensaje = 'Memorándum registrado. Con este ya son '.Seguimiento::cantidad($this->empleadoId, SeguimientoRegistro::MEMORANDUM).': se envió el aviso a gerencia.';
        }
    }

    public function verDocumento(int $id): void
    {
        $this->exigirAcceso();
        $this->vistaId = $this->registro($id)->id;
    }

    public function cerrarVista(): void
    {
        $this->vistaId = null;
    }

    /** 'pdf' | 'imagen' | 'otro' (Word no se puede previsualizar en el navegador). */
    private function tipoVista(SeguimientoRegistro $registro): string
    {
        return match (strtolower(pathinfo($registro->documento_nombre, PATHINFO_EXTENSION))) {
            'pdf' => 'pdf',
            'jpg', 'jpeg', 'png' => 'imagen',
            default => 'otro',
        };
    }

    /** Consulta en el CRM lo activado/atendido dentro del plazo del compromiso, para validar si cumplió. */
    public function verResultado(int $id): void
    {
        $this->exigirAcceso();
        $registro = $this->registro($id);
        abort_unless($registro->esCompromiso() && $registro->fecha_hasta, 422);

        $this->resultadoId = $id;
        $this->resultado = CrmDelivery::resultadoCompromiso($registro);
    }

    public function cerrarResultado(): void
    {
        $this->resultadoId = null;
        $this->resultado = [];
    }

    /** Abre la evaluación con el resultado sugerido por el CRM; el administrador decide y guarda. */
    public function usarSugerencia(int $id): void
    {
        $this->exigirAcceso();
        $this->verResultado($id);

        if (($this->resultado['cumple'] ?? null) === null) {
            return;
        }

        $this->abrirEvaluacion($id);
        $this->evaluacionResultado = $this->resultado['cumple'] ? SeguimientoRegistro::CUMPLIDO : SeguimientoRegistro::NO_CUMPLIDO;

        $partes = [];
        if ($this->resultado['meta_q'] !== null) {
            $partes[] = $this->resultado['activadas_q'].' de '.$this->resultado['meta_q'].' Q activadas';
        }
        if ($this->resultado['meta_sots'] !== null) {
            $partes[] = $this->resultado['atendidas_sot'].' de '.$this->resultado['meta_sots'].' SOT atendidos';
        }
        $this->evaluacionObservacion = 'CRM del '.Carbon::parse($this->resultado['desde'])->format('d/m/Y').' al '.Carbon::parse($this->resultado['hasta'])->format('d/m/Y').': '.implode(' · ', $partes);
    }

    public function abrirEvaluacion(int $id): void
    {
        $this->exigirAcceso();
        $registro = $this->registro($id);
        abort_unless($registro->esCompromiso(), 422);

        $this->evaluandoId = $id;
        $this->evaluacionResultado = in_array($registro->estado, [SeguimientoRegistro::CUMPLIDO, SeguimientoRegistro::NO_CUMPLIDO], true) ? $registro->estado : '';
        $this->evaluacionObservacion = (string) $registro->evaluacion_observacion;
        $this->resetErrorBag();
    }

    public function cancelarEvaluacion(): void
    {
        $this->evaluandoId = null;
        $this->resetErrorBag();
    }

    public function evaluar(): void
    {
        $this->exigirAcceso();
        $registro = $this->registro((int) $this->evaluandoId);
        abort_unless($registro->esCompromiso(), 422);

        $this->validate([
            'evaluacionResultado' => 'required|in:cumplido,no_cumplido',
            'evaluacionObservacion' => 'nullable|string|max:1000',
        ], [
            'evaluacionResultado.required' => 'Indica si cumplió o no cumplió.',
        ]);

        $registro->update([
            'estado' => $this->evaluacionResultado,
            'evaluado_por_id' => Auth::id(),
            'evaluado_at' => now(),
            'evaluacion_observacion' => trim($this->evaluacionObservacion) ?: null,
        ]);

        $this->evaluandoId = null;
        $this->mensaje = null;

        if ($this->evaluacionResultado === SeguimientoRegistro::NO_CUMPLIDO
            && Seguimiento::revisarAviso($this->empleado(), SeguimientoRegistro::COMPROMISO)) {
            $this->mensaje = 'Con este ya son '.Seguimiento::cantidad($this->empleadoId, SeguimientoRegistro::COMPROMISO).' compromisos no cumplidos: se envió el aviso a gerencia.';
        }
    }

    public function enviarTrabajador(int $id): void
    {
        $this->exigirAcceso();
        $registro = $this->registro($id);
        $correo = trim((string) $this->empleado()->email_corporativo);

        $this->mensaje = null;

        if ($correo === '' || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $this->addError('envio-'.$id, 'El trabajador no tiene un correo corporativo válido registrado.');

            return;
        }

        if (! Storage::disk('local')->exists($registro->documento_ruta)) {
            $this->addError('envio-'.$id, 'No se encontró el archivo del documento.');

            return;
        }

        $registro->update(['envio_error' => null, 'envio_solicitado_at' => now()]);
        EnviarSeguimientoTrabajador::dispatch($registro->id, $correo);
    }

    public function eliminar(int $id): void
    {
        $this->exigirAcceso();
        $registro = $this->registro($id);

        Storage::disk('local')->delete($registro->documento_ruta);
        $registro->delete();

        if ($this->evaluandoId === $id) {
            $this->evaluandoId = null;
        }
    }

    public function abrirAvisos(): void
    {
        $this->exigirAcceso();
        $this->cargarCorreos();
        $this->resetErrorBag();
        $this->avisosAbierto = true;
    }

    public function cerrarAvisos(): void
    {
        $this->avisosAbierto = false;
        $this->resetErrorBag();
    }

    public function seleccionarTodosEnvio(): void
    {
        $this->exigirAcceso();
        $this->envioSeleccion = SeguimientoRegistro::where('empleado_id', $this->empleadoId)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function limpiarSeleccionEnvio(): void
    {
        $this->envioSeleccion = [];
    }

    /** Manda a gerencia (y a los correos registrados) los registros marcados, cuando el administrador quiera. */
    public function enviarAGerencia(): void
    {
        $this->exigirAcceso();
        $this->resetErrorBag(['envioSeleccion', 'envioMensaje']);
        $this->validate(['envioMensaje' => 'nullable|string|max:1000'], [], ['envioMensaje' => 'mensaje']);

        $ids = collect($this->envioSeleccion)->map(fn ($id) => (int) $id)->unique()->values();
        $registros = SeguimientoRegistro::where('empleado_id', $this->empleadoId)->whereIn('id', $ids)->get();

        if ($registros->isEmpty()) {
            $this->addError('envioSeleccion', 'Marca al menos un registro para enviar.');

            return;
        }

        $destinatarios = Seguimiento::destinatarios();

        if (! $destinatarios) {
            $this->addError('envioSeleccion', 'No hay correos de gerencia registrados.');

            return;
        }

        if ($this->envioAdjuntar) {
            $disco = Storage::disk('local');
            $faltan = $registros->reject(fn ($r) => $disco->exists($r->documento_ruta));

            if ($faltan->isNotEmpty()) {
                $this->addError('envioSeleccion', 'No se encontró el archivo de: '.$faltan->pluck('titulo')->implode(', ').'.');

                return;
            }

            $bytes = $registros->sum(fn ($r) => $disco->size($r->documento_ruta));

            if ($bytes > 20 * 1024 * 1024) {
                $this->addError('envioSeleccion', 'Los documentos juntos pesan '.round($bytes / 1048576, 1).' MB y no caben en un correo (máximo 20 MB). Envía menos registros o sin adjuntos.');

                return;
            }
        }

        $envio = SeguimientoEnvioGerencia::create([
            'empleado_id' => $this->empleadoId,
            'registro_ids' => $registros->pluck('id')->all(),
            'mensaje' => trim($this->envioMensaje) ?: null,
            'adjuntar_documentos' => $this->envioAdjuntar,
            'destinatarios' => implode(', ', $destinatarios),
            'enviado_por_id' => Auth::id(),
        ]);

        EnviarRegistrosGerencia::dispatch($envio->id);

        $this->envioSeleccion = [];
        $this->envioMensaje = '';
        $this->mensaje = null;
    }

    /** Vuelve a enviar un aviso ya emitido (por ejemplo, tras cambiar los correos de gerencia). */
    public function reenviarAviso(int $avisoId): void
    {
        $this->exigirAcceso();
        $aviso = SeguimientoAviso::where('empleado_id', $this->empleadoId)->findOrFail($avisoId);
        $destinatarios = Seguimiento::destinatarios();

        $this->mensaje = null;

        if (! $destinatarios) {
            $this->addError('reenvio-'.$avisoId, 'No hay correos de aviso registrados.');

            return;
        }

        $aviso->update(['destinatarios' => implode(', ', $destinatarios), 'enviado_at' => null, 'error' => null]);
        EnviarAvisoSeguimiento::dispatch($aviso->id);
    }

    public function guardarCorreos(): void
    {
        $this->exigirAcceso();
        $this->resetErrorBag(['correosGerencia', 'correosAdicionales']);

        // El correo de gerencia solo lo cambia el administrador: GTH solo agrega o quita los correos adicionales.
        // (Se ignora lo que llegue del navegador en $correosGerencia.)
        $gerencia = Auth::user()->esAdmin()
            ? $this->correosGerencia
            : (\App\Models\Ajuste::obtener(Seguimiento::CLAVE_GERENCIA, Seguimiento::GERENCIA_POR_DEFECTO) ?? '');

        $errores = Seguimiento::guardarDestinatarios($gerencia, $this->correosAdicionales);

        foreach ($errores as $campo => $texto) {
            $this->addError($campo, $texto);
        }

        if (! $errores) {
            $this->cargarCorreos();
            $this->mensaje = 'Correos de aviso guardados.';
        }
    }

    public function with(): array
    {
        $registros = SeguimientoRegistro::with('evaluadoPor')
            ->where('empleado_id', $this->empleadoId)
            ->orderByDesc('fecha_desde')
            ->orderByDesc('id')
            ->get();

        return [
            'registros' => $registros,
            'empleado' => $this->empleado(),
            'noCumplidos' => Seguimiento::cantidad($this->empleadoId, SeguimientoRegistro::COMPROMISO),
            'memorandums' => Seguimiento::cantidad($this->empleadoId, SeguimientoRegistro::MEMORANDUM),
            'avisos' => SeguimientoAviso::where('empleado_id', $this->empleadoId)->orderByDesc('id')->get(),
            'envios' => SeguimientoEnvioGerencia::with('enviadoPor:id,name')->where('empleado_id', $this->empleadoId)->orderByDesc('id')->limit(10)->get(),
            'vista' => $this->vistaId ? $registros->firstWhere('id', $this->vistaId) : null,
            'tipoVista' => $this->vistaId && ($v = $registros->firstWhere('id', $this->vistaId)) ? $this->tipoVista($v) : null,
            'correosAviso' => Seguimiento::destinatarios(),
            'puedeEditarGerencia' => (bool) Auth::user()?->esAdmin(),
            'hoy' => $this->hoy(),
            'umbral' => Seguimiento::UMBRAL,
        ];
    }
};
?>

<div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4" @if ($registros->contains(fn ($x) => $x->envio_solicitado_at) || $avisos->contains(fn ($x) => ! $x->enviado_at && ! $x->error) || $envios->contains(fn ($x) => $x->pendiente())) wire:poll.4s @endif>
    <div class="mx-auto max-w-3xl space-y-3">
        {{-- Resumen --}}
        <div class="flex flex-wrap items-center gap-2">
            <span class="badge {{ $noCumplidos >= $umbral ? 'badge-rose' : 'badge-slate' }}">Compromisos no cumplidos: {{ $noCumplidos }}/{{ $umbral }}</span>
            <span class="badge {{ $memorandums >= $umbral ? 'badge-rose' : 'badge-slate' }}">Memorándums: {{ $memorandums }}/{{ $umbral }}</span>
            <div class="ml-auto flex items-center gap-2">
                <button type="button" wire:click="abrirAvisos" class="btn-secondary inline-flex w-auto items-center gap-1.5 px-3 py-1.5 text-[11px]"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 8.5V3.707l.854-.853A.5.5 0 0 0 11.5 2.5v-2A.5.5 0 0 0 11 0H9.5a.5.5 0 0 0-.5.5v8z"/><path d="M4 3h4v1H6.646A4 4 0 0 1 8 7v6h7V7a3 3 0 0 0-3-3V3a4 4 0 0 1 4 4v6a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1V7a4 4 0 0 1 4-4m.585 4.157C4.836 7.264 5 7.334 5 7a1 1 0 0 0-2 0c0 .334.164.264.415.157C3.58 7.087 3.782 7 4 7s.42.086.585.157"/></svg>Avisos a gerencia</button>
                @unless ($formAbierto)
                    <button type="button" wire:click="abrirFormulario" class="btn-primary w-auto px-3 py-1.5 text-[11px]">+ Nuevo registro</button>
                @endunless
            </div>
        </div>

        @if ($mensaje)
            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-800 ring-1 ring-emerald-200">{{ $mensaje }}</p>
        @endif

        {{-- Formulario --}}
        @if ($formAbierto)
            <form wire:submit="guardar" class="space-y-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex gap-4">
                    <label class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-700">
                        <input type="radio" wire:model.live="tipo" value="compromiso"> Compromiso
                    </label>
                    <label class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-700">
                        <input type="radio" wire:model.live="tipo" value="memorandum"> Memorándum
                    </label>
                </div>

                <div>
                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Título o motivo</label>
                    <input type="text" wire:model="titulo" maxlength="150" class="form-input py-1.5 text-[11px]" placeholder="{{ $tipo === 'compromiso' ? 'Ej. Alcanzar 12 ventas en la semana' : 'Ej. Tardanzas reiteradas' }}">
                    @error('titulo') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ $tipo === 'compromiso' ? 'Plazo desde' : 'Fecha del memorándum' }}</label>
                        <input type="date" wire:model="fechaDesde" class="form-input py-1.5 text-[11px]">
                        @error('fechaDesde') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    @if ($tipo === 'compromiso')
                        <div>
                            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Plazo hasta</label>
                            <input type="date" wire:model="fechaHasta" class="form-input py-1.5 text-[11px]">
                            @error('fechaHasta') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                @if ($tipo === 'compromiso')
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Meta Q de líneas (opcional)</label>
                            <input type="number" min="1" max="9999" step="1" wire:model="metaQ" class="form-input py-1.5 text-[11px]" placeholder="Ej. 16">
                            @error('metaQ') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Meta SOTs (opcional)</label>
                            <input type="number" min="1" max="9999" step="1" wire:model="metaSots" class="form-input py-1.5 text-[11px]" placeholder="Ej. 5">
                            @error('metaSots') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <div>
                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Detalle (opcional)</label>
                    <textarea wire:model="descripcion" rows="2" maxlength="2000" class="form-input max-h-32 min-h-[3.25rem] resize-y py-1.5 text-[11px]"></textarea>
                </div>

                <div>
                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Documento firmado <span class="text-rose-500">*</span></label>
                    @if ($archivo)
                        <div class="flex items-center gap-3 rounded-xl border-2 border-emerald-300 bg-emerald-50 px-3 py-3">
                            <svg class="h-6 w-6 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-semibold text-emerald-900">{{ $archivo->getClientOriginalName() }}</p>
                                <p class="text-[10px] text-emerald-700">Documento listo para guardar</p>
                            </div>
                            <label class="cursor-pointer text-[11px] font-semibold text-sky-700 hover:underline">
                                Cambiar
                                <input type="file" wire:model="archivo" accept="application/pdf,image/jpeg,image/png,.doc,.docx" class="hidden">
                            </label>
                            <button type="button" wire:click="$set('archivo', null)" class="text-[11px] font-semibold text-rose-600 hover:underline">Quitar</button>
                        </div>
                    @else
                        <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-sky-300 bg-sky-50 px-3 py-5 text-center transition hover:border-sky-500 hover:bg-sky-100">
                            <svg class="h-8 w-8 text-sky-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                            <span class="text-xs font-semibold text-sky-800">Haz clic para adjuntar el documento firmado</span>
                            <span class="text-[10px] text-slate-500">PDF, imagen (JPG/PNG) o Word · máximo 10 MB</span>
                            <span wire:loading wire:target="archivo" class="text-[11px] font-semibold text-sky-700">Subiendo archivo...</span>
                            <input type="file" wire:model="archivo" accept="application/pdf,image/jpeg,image/png,.doc,.docx" class="hidden">
                        </label>
                    @endif
                    @error('archivo') <p class="mt-1 text-[11px] font-semibold text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" wire:loading.attr="disabled" wire:target="guardar,archivo" class="btn-primary w-auto px-4 py-1.5 text-[11px]">
                        <span wire:loading.remove wire:target="guardar">Guardar</span>
                        <span wire:loading wire:target="guardar">Guardando...</span>
                    </button>
                    <button type="button" wire:click="cerrarFormulario" class="text-[11px] text-slate-400 hover:text-rose-500">Cancelar</button>
                </div>
            </form>
        @endif

        {{-- Lista --}}
        @forelse ($registros as $r)
            @php($vencido = \App\Services\Seguimiento::vencido($r, $hoy))
            <div wire:key="seg-{{ $r->id }}" class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex flex-wrap items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-wide {{ $r->esCompromiso() ? 'text-sky-700' : 'text-violet-700' }}">{{ $r->esCompromiso() ? 'Compromiso' : 'Memorándum' }}</p>
                        <p class="text-xs font-semibold text-slate-900">{{ $r->titulo }}</p>
                        <p class="text-[11px] text-slate-500">
                            @if ($r->esCompromiso())
                                Plazo: {{ $r->fecha_desde->format('d/m/Y') }} – {{ $r->fecha_hasta?->format('d/m/Y') }}
                                @if ($r->meta_q || $r->meta_sots)
                                    · Meta: {{ collect([$r->meta_q ? $r->meta_q.' Q' : null, $r->meta_sots ? $r->meta_sots.' SOTs' : null])->filter()->implode(' · ') }}
                                @endif
                            @else
                                Fecha: {{ $r->fecha_desde->format('d/m/Y') }}
                            @endif
                        </p>
                    </div>
                    @if ($r->esCompromiso())
                        @if ($r->estado === 'cumplido')
                            <span class="badge badge-green">Cumplido</span>
                        @elseif ($r->estado === 'no_cumplido')
                            <span class="badge badge-rose">No cumplido</span>
                        @elseif ($vencido)
                            <span class="badge badge-amber">Vencido · por evaluar</span>
                        @else
                            <span class="badge badge-slate">En plazo</span>
                        @endif
                    @else
                        <span class="badge badge-slate">Registrado</span>
                    @endif
                </div>

                @if ($r->descripcion)
                    <p class="mt-1 whitespace-pre-line text-[11px] text-slate-600">{{ $r->descripcion }}</p>
                @endif

                @if ($r->esCompromiso() && $r->evaluado_at)
                    <p class="mt-1 text-[10px] text-slate-500">
                        Evaluado el {{ $r->evaluado_at->timezone('America/Lima')->format('d/m/Y') }}{{ $r->evaluadoPor ? ' por '.$r->evaluadoPor->name : '' }}{{ $r->evaluacion_observacion ? ' · '.$r->evaluacion_observacion : '' }}
                    </p>
                @endif

                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-slate-100 pt-2 text-[11px]">
                    <button type="button" wire:click="verDocumento({{ $r->id }})" class="font-semibold text-sky-700 hover:underline">Ver documento</button>
                    <a href="{{ route('seguimiento.documento', $r->id) }}" download="{{ $r->documento_nombre }}" class="font-semibold text-sky-700 hover:underline">Descargar</a>
                    @if ($r->esCompromiso() && ($r->meta_q || $r->meta_sots))
                        <button type="button" wire:click="verResultado({{ $r->id }})" wire:loading.attr="disabled" wire:target="verResultado({{ $r->id }})" class="font-semibold text-violet-700 hover:underline">
                            <span wire:loading.remove wire:target="verResultado({{ $r->id }})">Ver resultado del CRM</span>
                            <span wire:loading wire:target="verResultado({{ $r->id }})">Consultando el CRM...</span>
                        </button>
                    @endif
                    @if ($r->esCompromiso())
                        <button type="button" wire:click="abrirEvaluacion({{ $r->id }})" class="font-semibold text-slate-700 hover:underline">{{ in_array($r->estado, ['cumplido', 'no_cumplido'], true) ? 'Cambiar evaluación' : 'Evaluar' }}</button>
                    @endif
                    <button
                        type="button"
                        @disabled($r->envio_solicitado_at)
                        wire:click="enviarTrabajador({{ $r->id }})"
                        wire:confirm="¿Enviar la copia del documento firmado a {{ $empleado->email_corporativo ?: 'el correo corporativo del trabajador' }}?"
                        wire:loading.attr="disabled"
                        wire:target="enviarTrabajador({{ $r->id }})"
                        class="font-semibold text-slate-700 hover:underline"
                    >{{ $r->enviado_trabajador_at ? 'Reenviar al trabajador' : 'Enviar al trabajador' }}</button>
                    <button type="button" wire:click="eliminar({{ $r->id }})" wire:confirm="¿Eliminar este registro y su documento? Esta acción no se puede deshacer." class="ml-auto font-semibold text-rose-600 hover:underline">Eliminar</button>
                </div>

                @if ($r->envio_solicitado_at)
                    <p class="mt-1 text-[10px] font-semibold text-amber-600">Enviando el documento al correo del trabajador…</p>
                @endif
                @if ($r->enviado_trabajador_at && ! $r->envio_solicitado_at)
                    <p class="mt-1 text-[10px] text-emerald-700">Enviado a {{ $r->enviado_trabajador_a }} el {{ $r->enviado_trabajador_at->timezone('America/Lima')->format('d/m/Y H:i') }}</p>
                @endif
                @if ($r->envio_error)
                    <p class="mt-1 text-[10px] text-rose-600">No se pudo enviar: {{ $r->envio_error }}</p>
                @endif
                @error('envio-'.$r->id) <p class="mt-1 text-[10px] text-rose-600">{{ $message }}</p> @enderror

                @if ($resultadoId === $r->id && $resultado)
                    @php($res = $resultado)
                    <div class="mt-2 space-y-2 rounded-lg border border-violet-200 bg-violet-50 p-3">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-violet-800">
                                Resultado en el CRM · {{ \Illuminate\Support\Carbon::parse($res['desde'])->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($res['hasta'])->format('d/m/Y') }}
                            </p>
                            <button type="button" wire:click="cerrarResultado" class="text-[11px] text-slate-400 hover:text-slate-600" aria-label="Cerrar">✕</button>
                        </div>

                        @if (($res['estado'] ?? '') === 'sin_vinculo')
                            <p class="text-[11px] text-amber-700">Este trabajador aún no está vinculado a un ejecutivo del CRM. Vincúlalo en el módulo «Seguimiento de ejecutivos».</p>
                        @elseif (($res['estado'] ?? '') === 'error')
                            <p class="text-[11px] text-rose-600">{{ $res['mensaje'] }}</p>
                        @else
                            <div class="space-y-1 text-[11px] text-slate-700">
                                @if ($res['meta_q'] !== null)
                                    <p>
                                        <strong>Activadas (Q):</strong> {{ $res['activadas_q'] }} de {{ $res['meta_q'] }}
                                        <span class="font-semibold {{ $res['activadas_q'] >= $res['meta_q'] ? 'text-emerald-700' : 'text-rose-600' }}">{{ $res['activadas_q'] >= $res['meta_q'] ? '✓ alcanzó' : '✗ faltan '.($res['meta_q'] - $res['activadas_q']) }}</span>
                                    </p>
                                @endif
                                @if ($res['meta_sots'] !== null)
                                    <p>
                                        <strong>Atendidas (SOT):</strong> {{ $res['atendidas_sot'] }} de {{ $res['meta_sots'] }}
                                        <span class="font-semibold {{ $res['atendidas_sot'] >= $res['meta_sots'] ? 'text-emerald-700' : 'text-rose-600' }}">{{ $res['atendidas_sot'] >= $res['meta_sots'] ? '✓ alcanzó' : '✗ faltan '.($res['meta_sots'] - $res['atendidas_sot']) }}</span>
                                    </p>
                                @endif
                                <p class="text-slate-500">
                                    Para ver (no cuentan para cumplir) · En flujo: {{ $res['flujo_q'] }} Q · {{ $res['flujo_sot'] }} SOT ·
                                    Proyectado: {{ $res['proyectado_q'] }} Q · {{ $res['proyectado_sot'] }} SOT
                                </p>
                                @if ($res['en_curso'])
                                    <p class="font-semibold text-amber-700">El plazo sigue en curso: es el avance a la fecha.</p>
                                @endif
                            </div>

                            @if ($res['cumple'] !== null)
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-[11px] font-bold {{ $res['cumple'] ? 'text-emerald-700' : 'text-rose-600' }}">Sugerencia: {{ $res['cumple'] ? 'CUMPLIÓ' : 'NO CUMPLIÓ' }}</span>
                                    <button type="button" wire:click="usarSugerencia({{ $r->id }})" class="btn-secondary w-auto px-3 py-1 text-[11px]">Usar en la evaluación</button>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif

                @if ($evaluandoId === $r->id)
                    <div class="mt-2 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-[11px] font-semibold text-slate-700">¿Cumplió con el compromiso?</p>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-1.5 text-[11px] font-semibold text-emerald-700"><input type="radio" wire:model="evaluacionResultado" value="cumplido"> Cumplió</label>
                            <label class="flex items-center gap-1.5 text-[11px] font-semibold text-rose-700"><input type="radio" wire:model="evaluacionResultado" value="no_cumplido"> No cumplió</label>
                        </div>
                        @error('evaluacionResultado') <p class="text-[10px] text-rose-500">{{ $message }}</p> @enderror
                        <textarea wire:model="evaluacionObservacion" rows="2" maxlength="1000" class="form-input max-h-32 min-h-[3.25rem] resize-y py-1.5 text-[11px]" placeholder="Observación (opcional)"></textarea>
                        <div class="flex items-center gap-3">
                            <button type="button" wire:click="evaluar" wire:loading.attr="disabled" wire:target="evaluar" class="btn-primary w-auto px-3 py-1 text-[11px]">Guardar evaluación</button>
                            <button type="button" wire:click="cancelarEvaluacion" class="text-[11px] text-slate-400 hover:text-rose-500">Cancelar</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            @unless ($formAbierto)
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-3 py-6 text-center text-[11px] text-slate-500">Este trabajador aún no tiene compromisos ni memorándums.</p>
            @endunless
        @endforelse

    </div>

    {{-- Avisos a gerencia: correos, envío manual y avisos enviados --}}
    @if ($avisosAbierto)
        <div class="fixed inset-0 z-[75] flex items-center justify-center bg-slate-950/70 p-4">
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white text-xs shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-slate-900">Avisos a gerencia</h3>
                    <button type="button" wire:click="cerrarAvisos" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                    @if ($mensaje)
                        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-800 ring-1 ring-emerald-200">{{ $mensaje }}</p>
                    @endif

                    {{-- Correos --}}
                    <section class="space-y-2 rounded-xl border border-slate-200 p-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-600">Correos de aviso</p>
                        <p class="text-[11px] text-slate-500">Reciben los avisos automáticos (al llegar a {{ $umbral }} compromisos no cumplidos o {{ $umbral }} memorándums, y de nuevo a los 6, 9…) y los envíos manuales, desde rrhh@b2s.pe.</p>
                        <div>
                            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Correo de gerencia</label>
                            <input type="text" wire:model="correosGerencia" class="form-input py-1.5 text-[11px] {{ $puedeEditarGerencia ? '' : 'cursor-not-allowed opacity-70' }}" @disabled(! $puedeEditarGerencia)>
                            @unless ($puedeEditarGerencia)
                                <p class="mt-0.5 text-[10px] text-slate-400">Solo el administrador puede cambiar el correo de gerencia.</p>
                            @endunless
                            @error('correosGerencia') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Otro correo (opcional)</label>
                            <input type="text" wire:model="correosAdicionales" class="form-input py-1.5 text-[11px]" placeholder="Varios separados por coma">
                            @error('correosAdicionales') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <button type="button" wire:click="guardarCorreos" class="btn-secondary w-auto px-3 py-1 text-[11px]">Guardar correos</button>
                    </section>

                    {{-- Envío manual --}}
                    <section class="space-y-2 rounded-xl border border-slate-200 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-600">Enviar registros a gerencia ahora</p>
                            <span class="text-[10px] text-slate-500">
                                <button type="button" wire:click="seleccionarTodosEnvio" class="font-semibold text-sky-700 hover:underline">Marcar todos</button> ·
                                <button type="button" wire:click="limpiarSeleccionEnvio" class="font-semibold text-sky-700 hover:underline">Quitar marcas</button>
                            </span>
                        </div>
                        <p class="text-[11px] text-slate-500">Marca los registros que quieras (los que sean) y envíalos cuando lo necesites, aparte del aviso automático.</p>

                        <div class="max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-100">
                            @forelse ($registros as $r)
                                <label wire:key="env-{{ $r->id }}" class="flex cursor-pointer items-center gap-2 px-2.5 py-1.5 hover:bg-slate-50">
                                    <input type="checkbox" wire:model.live="envioSeleccion" value="{{ $r->id }}">
                                    <span class="w-20 shrink-0 text-[10px] font-bold uppercase {{ $r->esCompromiso() ? 'text-sky-700' : 'text-violet-700' }}">{{ $r->esCompromiso() ? 'Compromiso' : 'Memorándum' }}</span>
                                    <span class="min-w-0 flex-1 truncate text-[11px] font-semibold text-slate-800">{{ $r->titulo }}</span>
                                    <span class="shrink-0 text-[10px] text-slate-500">{{ $r->fecha_desde->format('d/m/Y') }}</span>
                                    @if ($r->esCompromiso())
                                        <span class="badge {{ ['cumplido' => 'badge-green', 'no_cumplido' => 'badge-rose'][$r->estado] ?? 'badge-slate' }} shrink-0 !px-2 !py-0.5 !text-[10px]">{{ ['cumplido' => 'Cumplido', 'no_cumplido' => 'No cumplido'][$r->estado] ?? 'Pendiente' }}</span>
                                    @endif
                                </label>
                            @empty
                                <p class="px-3 py-4 text-center text-[11px] text-slate-500">Este trabajador aún no tiene registros.</p>
                            @endforelse
                        </div>
                        @error('envioSeleccion') <p class="text-[10px] font-semibold text-rose-600">{{ $message }}</p> @enderror

                        <textarea wire:model="envioMensaje" rows="2" maxlength="1000" class="form-input max-h-32 min-h-[3.25rem] resize-y py-1.5 text-[11px]" placeholder="Mensaje para gerencia (opcional)"></textarea>
                        @error('envioMensaje') <p class="text-[10px] text-rose-500">{{ $message }}</p> @enderror

                        <label class="flex items-center gap-2 text-[11px] font-semibold text-slate-700">
                            <input type="checkbox" wire:model="envioAdjuntar"> Adjuntar los documentos firmados
                        </label>

                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="enviarAGerencia"
                                wire:confirm="Se enviarán {{ count($envioSeleccion) }} registro(s) a: {{ implode(', ', $correosAviso) }}. ¿Continuar?"
                                wire:loading.attr="disabled"
                                wire:target="enviarAGerencia"
                                class="btn-primary w-auto px-4 py-1.5 text-[11px]"
                            >
                                <span wire:loading.remove wire:target="enviarAGerencia">Enviar {{ count($envioSeleccion) ?: '' }} {{ count($envioSeleccion) === 1 ? 'registro' : 'registros' }} a gerencia</span>
                                <span wire:loading wire:target="enviarAGerencia">Enviando...</span>
                            </button>
                            <span class="text-[10px] text-slate-500">Para: {{ implode(', ', $correosAviso) ?: 'sin correos registrados' }}</span>
                        </div>
                    </section>

                    {{-- Historial de avisos y envíos --}}
                    @if ($avisos->isNotEmpty() || $envios->isNotEmpty())
                        <section class="space-y-1 rounded-xl border border-slate-200 p-3">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-600">Enviados</p>
                            <ul class="space-y-1 text-[11px] text-slate-600">
                                @foreach ($envios as $e)
                                    <li wire:key="envio-{{ $e->id }}">
                                        <span class="font-semibold text-slate-800">Manual · {{ count($e->registro_ids) }} {{ count($e->registro_ids) === 1 ? 'registro' : 'registros' }}</span> ·
                                        @if ($e->enviado_at)
                                            <span class="text-emerald-700">enviado el {{ $e->enviado_at->timezone('America/Lima')->format('d/m/Y H:i') }}</span> a {{ $e->destinatarios }}
                                        @elseif ($e->error)
                                            <span class="text-rose-600">no se pudo enviar: {{ $e->error }}</span>
                                        @else
                                            <span class="text-amber-600">en cola…</span>
                                        @endif
                                        @if ($e->enviadoPor) <span class="text-slate-400">· por {{ $e->enviadoPor->name }}</span> @endif
                                    </li>
                                @endforeach
                                @foreach ($avisos as $a)
                                    <li wire:key="aviso-{{ $a->id }}">
                                        <span class="font-semibold text-slate-800">Automático · {{ $a->tipo === 'compromiso' ? $a->cantidad.' compromisos no cumplidos' : $a->cantidad.' memorándums' }}</span> ·
                                        @if ($a->enviado_at)
                                            <span class="text-emerald-700">enviado el {{ $a->enviado_at->timezone('America/Lima')->format('d/m/Y H:i') }}</span> a {{ $a->destinatarios }}
                                        @elseif ($a->error)
                                            <span class="text-rose-600">no se pudo enviar: {{ $a->error }}</span>
                                        @else
                                            <span class="text-amber-600">en cola…</span>
                                        @endif
                                        @if ($a->enviado_at || $a->error)
                                            <button
                                                type="button"
                                                wire:click="reenviarAviso({{ $a->id }})"
                                                wire:confirm="Se volverá a enviar este aviso a: {{ implode(', ', $correosAviso) }}. ¿Continuar?"
                                                class="ml-1 font-semibold text-sky-700 hover:underline"
                                            >Reenviar</button>
                                        @endif
                                        @error('reenvio-'.$a->id) <span class="text-rose-600">{{ $message }}</span> @enderror
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Vista del documento dentro del ERP --}}
    @if ($vista)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/70 p-4">
            <div class="flex h-[88vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white text-xs shadow-2xl">
                <div class="flex shrink-0 items-center gap-3 border-b border-slate-200 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ $vista->titulo }}</p>
                        <p class="truncate text-[11px] text-slate-500">{{ $vista->esCompromiso() ? 'Compromiso' : 'Memorándum' }} · {{ $vista->documento_nombre }}</p>
                    </div>
                    <a href="{{ route('seguimiento.documento', $vista->id) }}" download="{{ $vista->documento_nombre }}" class="btn-secondary w-auto px-3 py-1 text-[11px]">Descargar</a>
                    <button type="button" wire:click="cerrarVista" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 bg-slate-100">
                    @if ($tipoVista === 'pdf')
                        <iframe src="{{ route('seguimiento.documento', $vista->id) }}" class="h-full w-full" title="{{ $vista->documento_nombre }}"></iframe>
                    @elseif ($tipoVista === 'imagen')
                        <div class="flex h-full items-center justify-center overflow-auto p-3">
                            <img src="{{ route('seguimiento.documento', $vista->id) }}" alt="{{ $vista->documento_nombre }}" class="max-h-full max-w-full object-contain">
                        </div>
                    @else
                        <div class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
                            <p class="text-sm font-semibold text-slate-700">Los archivos de Word no se pueden mostrar en el navegador.</p>
                            <p class="text-[11px] text-slate-500">Descárgalo para abrirlo en Word.</p>
                            <a href="{{ route('seguimiento.documento', $vista->id) }}" download="{{ $vista->documento_nombre }}" class="btn-primary w-auto px-4 py-1.5 text-[11px]">Descargar documento</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
