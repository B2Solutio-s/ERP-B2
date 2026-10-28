<?php

use App\Models\Area;
use App\Models\CandidatoDocumento;
use App\Models\CandidatoReclutamiento;
use App\Models\Campana;
use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\EmpleadoDocumentoCese;
use App\Models\EmpleadoDocumentoContrato;
use App\Models\EmpleadoPeriodoContrato;
use App\Services\ContratoWord;
use App\Services\EnvioInvitacionFicha;
use App\Models\OnboardingInvitation;
use App\Models\Puesto;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpWord\TemplateProcessor;

new #[Layout('layouts.panel')] class extends Component
{
    use WithFileUploads;

    public string $mensajeEnvio = '';

    public const FILTROS = ['nombre' => 'Nombre', 'dni' => 'DNI / C.E', 'ingreso' => 'Fecha de ingreso'];

    /** Filtros añadidos como chips, en el orden en que se añadieron. */
    public array $filtrosActivos = [];

    /** Chip con el selector abierto ('' = ninguno, 'agregar' = menú "+ Añadir filtro"). */
    public string $filtroAbierto = '';

    public string $filtroNombre = '';

    public string $filtroDni = '';

    public string $filtroIngresoDesde = '';

    public string $filtroIngresoHasta = '';

    public ?int $empleadoModalId = null;

    public ?int $datosModalId = null;

    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $documentosNuevos = [];

    public array $tiposDocumento = [
        'dni_copia' => 'DNI - Copia',
        'cv_actualizado' => 'CV actualizado',
        'dni' => 'DNI',
        'recibo' => 'Recibo',
        'dni_hijos' => 'DNI - Derechohabiente',
        'cul' => 'CUL - Certificado Único Laboral',
    ];

    public ?string $vistaPreviaUrl = null;

    public ?string $vistaPreviaTitulo = null;

    public bool $vistaPreviaEsImagen = false;

    public string $pestanaDatosModal = 'datos';

    /** Panel lateral desplegable "Generar contrato" de la pestaña Contrato. */
    public bool $panelGenerarAbierto = false;

    /** El contrato incluye la cláusula del período de prueba (ingresos nuevos y reingresos); no las renovaciones. */
    public bool $contratoLlevaPrueba = true;

    /** Período cuyo contrato firmado se está viendo/subiendo en el modal. */
    public ?int $contratoFirmadoPeriodoId = null;

    public $contratoFirmadoNuevo = null;

    public string $cusppEdit = '';

    public string $correoCorpEdit = '';

    public string $correoCorpGuardado = '';

    public string $cuentaSueldoEdit = '';

    public string $cuentaFreeEdit = '';

    public string $cuentaCtsEdit = '';

    public string $cciEdit = '';

    public string $bancoGuardado = '';

    public string $contratoArea = '';

    public string $contratoPuesto = '';

    public string $contratoCampana = '';

    public string $contratoCargo = '';

    public string $contratoAreaPracticas = '';

    public string $contratoUniversidad = '';

    public string $contratoRemuneracion = '';

    public string $contratoRemuneracionMovilidad = '';

    public string $contratoFechaInicio = '';

    public string $contratoFechaFin = '';

    /** Duración del contrato en meses: el fin se calcula (inicio + meses − 1 día). */
    public string $contratoDuracionMeses = '';

    public string $contratoFechaFirma = '';

    public ?int $periodoCeseActivoId = null;

    public string $contratoCeseFecha = '';

    public string $contratoCeseTipo = '';

    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $documentosCeseNuevos = [];

    public bool $mostrarFormularioRenovacion = false;

    public string $nuevoPeriodoFechaInicio = '';

    public string $nuevoPeriodoDuracion = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin() || Auth::user()->esGth(), 403);
    }

    /** Contratos, ceses y renovaciones son solo de administración (GTH ve únicamente la pestaña Datos). */
    private function exigirAdmin(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);
    }

    public function abrirVistaPrevia(string $url, string $titulo, bool $esImagen): void
    {
        $this->vistaPreviaUrl = $url;
        $this->vistaPreviaTitulo = $titulo;
        $this->vistaPreviaEsImagen = $esImagen;
    }

    public function cerrarVistaPrevia(): void
    {
        $this->vistaPreviaUrl = null;
        $this->vistaPreviaTitulo = null;
    }

    public function cancelarDocumento(string $tipo): void
    {
        unset($this->documentosNuevos[$tipo]);
    }

    public function documentosDe(CandidatoReclutamiento $candidato): array
    {
        $existentes = CandidatoDocumento::where('reclutamiento_candidato_id', $candidato->id)
            ->get()
            ->keyBy('tipo');

        $documentos = [];
        foreach (array_keys($this->tiposDocumento) as $tipo) {
            $documentos[$tipo] = $existentes->get($tipo);
        }

        return $documentos;
    }

    public function guardarDocumento(string $tipo): void
    {
        $candidato = $this->candidatoParaDatos();
        $archivo = $this->documentosNuevos[$tipo] ?? null;

        if (! $candidato || ! $archivo || ! array_key_exists($tipo, $this->tiposDocumento)) {
            return;
        }

        $this->validate([
            "documentosNuevos.{$tipo}" => 'required|file|mimes:pdf,jpg,jpeg,png|max:8192',
        ]);

        $existente = CandidatoDocumento::where('reclutamiento_candidato_id', $candidato->id)
            ->where('tipo', $tipo)
            ->first();

        if ($existente) {
            Storage::disk('local')->delete($existente->ruta);
        }

        $ruta = $archivo->store("candidato-documentos/{$candidato->id}", 'local');

        CandidatoDocumento::updateOrCreate(
            ['reclutamiento_candidato_id' => $candidato->id, 'tipo' => $tipo],
            [
                'nombre_original' => $archivo->getClientOriginalName(),
                'ruta' => $ruta,
                'mime_type' => $archivo->getClientMimeType(),
                'tamano' => $archivo->getSize(),
                'subido_por_id' => Auth::id(),
            ],
        );

        unset($this->documentosNuevos[$tipo]);
    }

    public function eliminarDocumento(int $documentoId): void
    {
        $documento = CandidatoDocumento::find($documentoId);

        if (! $documento) {
            return;
        }

        Storage::disk('local')->delete($documento->ruta);
        $documento->delete();
    }

    public function empleadoActual(): ?Empleado
    {
        $candidato = $this->candidatoParaDatos();

        return $candidato ? $this->invitacionDe($candidato)?->empleado : null;
    }

    private function documentosCeseConfig(): array
    {
        return [
            'renuncia' => [
                'carta_renuncia' => ['label' => 'Carta de renuncia', 'modo' => 'subir'],
                'liquidacion' => ['label' => 'Liquidación', 'modo' => 'subir'],
                'constancia_trabajo' => ['label' => 'Constancia de trabajo', 'modo' => 'generar', 'plantilla' => 'constancia-trabajo-template.docx'],
            ],
            'despido' => [
                'liquidacion' => ['label' => 'Liquidación', 'modo' => 'subir'],
                'carta_no_continuidad_firmada' => ['label' => 'Carta de no continuidad (firmada)', 'modo' => 'generar_y_firmar', 'plantilla' => 'carta-no-continuidad-template.docx'],
                'constancia_trabajo' => ['label' => 'Constancia de trabajo', 'modo' => 'generar', 'plantilla' => 'constancia-trabajo-template.docx'],
            ],
            'vencimiento' => [
                'liquidacion' => ['label' => 'Liquidación', 'modo' => 'subir'],
                'constancia_trabajo' => ['label' => 'Constancia de trabajo', 'modo' => 'generar', 'plantilla' => 'constancia-trabajo-template.docx'],
                'carta_no_renovacion_firmada' => ['label' => 'Carta de no renovación (firmada)', 'modo' => 'generar_y_firmar', 'plantilla' => 'carta-no-renovacion-template.docx'],
            ],
        ];
    }

    public function documentosCeseDisponibles(): array
    {
        return $this->documentosCeseConfig()[$this->contratoCeseTipo] ?? [];
    }

    public function ceseePlantillaInfo(string $tipo): ?array
    {
        $info = $this->documentosCeseConfig()[$this->contratoCeseTipo][$tipo] ?? null;

        if (! $info || ! isset($info['plantilla'])) {
            return null;
        }

        $info['ruta'] = resource_path('data/'.$info['plantilla']);
        $info['existe'] = file_exists($info['ruta']);

        return $info;
    }

    public function cancelarDocumentoCese(string $tipo): void
    {
        unset($this->documentosCeseNuevos[$tipo]);
    }

    private function periodoCeseActivo(): ?EmpleadoPeriodoContrato
    {
        if (! $this->periodoCeseActivoId) {
            return null;
        }

        $empleado = $this->empleadoActual();

        if (! $empleado) {
            return null;
        }

        return EmpleadoPeriodoContrato::where('id', $this->periodoCeseActivoId)
            ->where('empleado_id', $empleado->id)
            ->first();
    }

    public function abrirCeseDePeriodo(int $periodoId): void
    {
        $this->exigirAdmin();

        // El cese solo se registra sobre el contrato vigente (no sobre uno vencido o ya renovado).
        $empleado = $this->empleadoActual();
        $periodo = $empleado?->periodosContrato->firstWhere('id', $periodoId);

        if (! $periodo || ! in_array($periodo->estadoEn($empleado->periodosContrato), ['activo', 'por_vencer'], true)) {
            return;
        }

        $this->periodoCeseActivoId = $periodoId;
        $this->contratoCeseFecha = now()->format('Y-m-d');
        $this->contratoCeseTipo = '';
        $this->documentosCeseNuevos = [];
    }

    public function cerrarCeseDePeriodo(): void
    {
        $this->periodoCeseActivoId = null;
        $this->contratoCeseFecha = '';
        $this->contratoCeseTipo = '';
        $this->documentosCeseNuevos = [];
    }

    public function documentosCeseDe(EmpleadoPeriodoContrato $periodo): array
    {
        $existentes = EmpleadoDocumentoCese::where('periodo_id', $periodo->id)
            ->get()
            ->keyBy('tipo');

        $documentos = [];
        foreach (array_keys($this->documentosCeseDisponibles()) as $tipo) {
            $documentos[$tipo] = $existentes->get($tipo);
        }

        return $documentos;
    }

    public function guardarDocumentoCese(string $tipo): void
    {
        $this->exigirAdmin();

        $periodo = $this->periodoCeseActivo();
        $archivo = $this->documentosCeseNuevos[$tipo] ?? null;

        if (! $periodo || ! $archivo || ! array_key_exists($tipo, $this->documentosCeseDisponibles())) {
            return;
        }

        $this->validate([
            "documentosCeseNuevos.{$tipo}" => 'required|file|mimes:pdf,doc,docx|max:8192',
        ]);

        $existente = EmpleadoDocumentoCese::where('periodo_id', $periodo->id)
            ->where('tipo', $tipo)
            ->first();

        if ($existente) {
            Storage::disk('local')->delete($existente->ruta);
        }

        $ruta = $archivo->store("empleado-documentos-cese/{$periodo->id}", 'local');

        EmpleadoDocumentoCese::updateOrCreate(
            ['periodo_id' => $periodo->id, 'tipo' => $tipo],
            [
                'nombre_original' => $archivo->getClientOriginalName(),
                'ruta' => $ruta,
                'mime_type' => $archivo->getClientMimeType(),
                'tamano' => $archivo->getSize(),
                'subido_por_id' => Auth::id(),
            ],
        );

        unset($this->documentosCeseNuevos[$tipo]);
    }

    public function eliminarDocumentoCese(int $documentoId): void
    {
        $this->exigirAdmin();

        $documento = EmpleadoDocumentoCese::find($documentoId);

        if (! $documento) {
            return;
        }

        Storage::disk('local')->delete($documento->ruta);
        $documento->delete();
    }

    public function registrarPeriodoPrueba(): void
    {
        $this->exigirAdmin();

        $empleado = $this->empleadoActual();

        if (! $empleado) {
            return;
        }

        $inicio = $this->contratoFechaInicio !== '' ? $this->contratoFechaInicio : now()->format('Y-m-d');
        $fechaInicio = Carbon::parse($inicio);

        $empleado->periodosContrato()->create([
            'tipo' => 'prueba',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaInicio->copy()->addMonthsNoOverflow(3)->subDay(),
            'duracion_meses' => 3,
        ]);
    }

    public function mostrarRenovacion(): void
    {
        $this->exigirAdmin();

        $empleado = $this->empleadoActual();
        $ultimoPeriodo = $empleado?->periodosContrato->first();

        $this->nuevoPeriodoFechaInicio = $ultimoPeriodo
            ? $ultimoPeriodo->fecha_fin->copy()->addDay()->format('Y-m-d')
            : now()->format('Y-m-d');
        $this->nuevoPeriodoDuracion = '';
        $this->mostrarFormularioRenovacion = true;
    }

    public function cancelarRenovacion(): void
    {
        $this->mostrarFormularioRenovacion = false;
        $this->nuevoPeriodoFechaInicio = '';
        $this->nuevoPeriodoDuracion = '';
    }

    public function registrarPeriodoRenovacion(): void
    {
        $this->exigirAdmin();

        $empleado = $this->empleadoActual();

        if (! $empleado) {
            return;
        }

        $this->validate([
            'nuevoPeriodoFechaInicio' => 'required|date',
            'nuevoPeriodoDuracion' => 'required|integer|min:1|max:60',
        ], [], [
            'nuevoPeriodoFechaInicio' => 'fecha de inicio',
            'nuevoPeriodoDuracion' => 'duración',
        ]);

        $fechaInicio = Carbon::parse($this->nuevoPeriodoFechaInicio);

        $empleado->periodosContrato()->create([
            'tipo' => 'renovacion',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaInicio->copy()->addMonthsNoOverflow((int) $this->nuevoPeriodoDuracion)->subDay(),
            'duracion_meses' => (int) $this->nuevoPeriodoDuracion,
        ]);

        $this->cancelarRenovacion();
    }

    public function guardarCeseDePeriodo(): void
    {
        $this->exigirAdmin();

        $periodo = $this->periodoCeseActivo();

        if (! $periodo) {
            return;
        }

        $this->validate([
            'contratoCeseFecha' => 'required|date',
            'contratoCeseTipo' => 'required|in:renuncia,despido,vencimiento',
        ], [], [
            'contratoCeseFecha' => 'fecha de cese',
            'contratoCeseTipo' => 'tipo de cese',
        ]);

        $periodo->update([
            'cese' => true,
            'cese_tipo' => $this->contratoCeseTipo,
            'cese_fecha' => $this->contratoCeseFecha,
        ]);
    }

    public function estadoPeriodoLabel(string $estado): array
    {
        return match ($estado) {
            'activo' => ['label' => 'Activo', 'clase' => 'bg-emerald-50 text-emerald-700'],
            'por_vencer' => ['label' => 'Por vencer', 'clase' => 'bg-amber-50 text-amber-700'],
            'vencido' => ['label' => 'Vencido', 'clase' => 'bg-slate-100 text-slate-600'],
            'renovado' => ['label' => 'Renovado', 'clase' => 'bg-sky-50 text-sky-700'],
            'cesado' => ['label' => 'Cesado', 'clase' => 'bg-rose-50 text-rose-700'],
            default => ['label' => ucfirst($estado), 'clase' => 'bg-slate-100 text-slate-600'],
        };
    }

    public function generarDocumentoCese(string $tipo)
    {
        $this->exigirAdmin();

        $periodo = $this->periodoCeseActivo();
        $plantilla = $this->ceseePlantillaInfo($tipo);

        if (! $periodo || ! $plantilla) {
            return;
        }

        if (! $plantilla['existe']) {
            $this->addError('contratoCeseTipo', 'Todavía no se subió la plantilla para "'.$plantilla['label'].'".');

            return;
        }

        $empleado = $periodo->empleado;
        $nombreCompleto = trim("{$empleado->apellido_paterno} {$empleado->apellido_materno} {$empleado->nombres}");

        // Placeholders provisionales mientras no llega la plantilla real;
        // setValue() en una clave inexistente no falla, así que se
        // ajustarán los nombres exactos cuando se reciba el .docx final.
        $procesador = new TemplateProcessor($plantilla['ruta']);
        $procesador->setValue('NOMBRE_TRABAJADOR', mb_strtoupper($nombreCompleto));
        $procesador->setValue('DNI_TRABAJADOR', $empleado->documento_identidad);
        $procesador->setValue('DIRECCION_TRABAJADOR', mb_strtoupper((string) $empleado->direccion));
        $procesador->setValue('PUESTO_TRABAJADOR', mb_strtoupper((string) ($empleado->contrato_puesto ?? $empleado->puesto)));
        $procesador->setValue('FECHA_INGRESO', $empleado->contrato_fecha_inicio ? $this->fechaLarga($empleado->contrato_fecha_inicio->format('Y-m-d')) : '');
        $procesador->setValue('FECHA_CESE', $this->contratoCeseFecha !== '' ? $this->fechaLarga($this->contratoCeseFecha) : '');
        $procesador->setValue('FECHA_EMISION', $this->fechaLarga(now()->format('Y-m-d')));

        $rutaTemporal = tempnam(sys_get_temp_dir(), 'cese_').'.docx';
        $procesador->saveAs($rutaTemporal);

        $nombreArchivo = str($plantilla['label'].'-'.$nombreCompleto)->slug().'.docx';

        return response()->streamDownload(function () use ($rutaTemporal) {
            echo file_get_contents($rutaTemporal);
            unlink($rutaTemporal);
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /** Aptos ordenados por apellidos; con la ficha completada el nombre es el que escribió la persona (no el del reclutamiento). */
    public function candidatosAptos()
    {
        return CandidatoReclutamiento::query()
            ->where('apto', true)
            ->get()
            ->each(function (CandidatoReclutamiento $candidato) {
                $empleado = $this->invitacionDe($candidato)?->empleado;
                $nombre = $empleado
                    ? trim("{$empleado->apellido_paterno} {$empleado->apellido_materno} {$empleado->nombres}")
                    : trim("{$candidato->apellidos} {$candidato->nombres}");

                $candidato->setAttribute('nombre_mostrado', preg_replace('/\s+/u', ' ', $nombre));
                $candidato->setAttribute('fecha_ingreso_contrato', $this->inicioPrimerContrato($candidato, $empleado));
                $candidato->setAttribute('dni_mostrado', trim((string) ($empleado?->documento_identidad ?: $candidato->dni_ce)));
            })
            ->filter(fn ($candidato) => $this->cumpleFiltros($candidato))
            ->sortBy(fn ($candidato) => Str::ascii(mb_strtoupper($candidato->nombre_mostrado)))
            ->values();
    }

    public function hayFiltros(): bool
    {
        return trim($this->filtroNombre.$this->filtroDni.$this->filtroIngresoDesde.$this->filtroIngresoHasta) !== '';
    }

    public function alternarFiltro(string $filtro): void
    {
        $this->filtroAbierto = $this->filtroAbierto === $filtro ? '' : $filtro;
    }

    public function cerrarFiltro(): void
    {
        $this->filtroAbierto = '';
    }

    public function agregarFiltro(string $filtro): void
    {
        if (! array_key_exists($filtro, self::FILTROS) || in_array($filtro, $this->filtrosActivos, true)) {
            return;
        }

        $this->filtrosActivos[] = $filtro;
        $this->filtroAbierto = $filtro;
    }

    public function quitarFiltro(string $filtro): void
    {
        $this->filtrosActivos = array_values(array_diff($this->filtrosActivos, [$filtro]));
        $this->filtroAbierto = '';

        match ($filtro) {
            'nombre' => $this->reset('filtroNombre'),
            'dni' => $this->reset('filtroDni'),
            'ingreso' => $this->reset('filtroIngresoDesde', 'filtroIngresoHasta'),
            default => null,
        };
    }

    /** Cuántos criterios tiene el filtro (para el globo del chip). */
    public function criteriosDeFiltro(string $filtro): int
    {
        return match ($filtro) {
            'nombre' => (int) (trim($this->filtroNombre) !== ''),
            'dni' => (int) (trim($this->filtroDni) !== ''),
            'ingreso' => (int) ($this->filtroIngresoDesde !== '' || $this->filtroIngresoHasta !== ''),
            default => 0,
        };
    }

    /** Nombre: todas las palabras escritas, sin importar tildes ni mayúsculas; DNI: contiene lo escrito; ingreso: dentro del rango. */
    protected function cumpleFiltros(CandidatoReclutamiento $candidato): bool
    {
        $normalizar = fn (string $texto) => Str::ascii(mb_strtolower($texto));

        foreach (array_filter(preg_split('/\s+/u', $normalizar(trim($this->filtroNombre)))) as $palabra) {
            if (! str_contains($normalizar($candidato->nombre_mostrado), $palabra)) {
                return false;
            }
        }

        $dni = preg_replace('/\s+/', '', $this->filtroDni);
        if ($dni !== '' && ! str_contains(mb_strtolower($candidato->dni_mostrado), mb_strtolower($dni))) {
            return false;
        }

        if ($this->filtroIngresoDesde !== '' || $this->filtroIngresoHasta !== '') {
            $ingreso = $candidato->fecha_ingreso_contrato?->toDateString();
            if (! $ingreso
                || ($this->filtroIngresoDesde !== '' && $ingreso < $this->filtroIngresoDesde)
                || ($this->filtroIngresoHasta !== '' && $ingreso > $this->filtroIngresoHasta)) {
                return false;
            }
        }

        return true;
    }

    /** Fecha de ingreso = inicio del primer contrato; mientras no hay ficha, el de los contratos cargados en espera. */
    protected function inicioPrimerContrato(CandidatoReclutamiento $candidato, ?Empleado $empleado): ?Carbon
    {
        $fecha = $empleado
            ? $empleado->periodosContrato()->min('fecha_inicio')
            : collect($candidato->periodos_iniciales ?? [])->pluck('fecha_inicio')->filter()->min();

        return $fecha ? Carbon::parse($fecha) : null;
    }

    public function invitacionDe(CandidatoReclutamiento $candidato): ?OnboardingInvitation
    {
        return OnboardingInvitation::principalDe($candidato->id);
    }

    public function correoConfigurado(): bool
    {
        return EnvioInvitacionFicha::configurado();
    }

    public function hayEnviosEnCola(): bool
    {
        return OnboardingInvitation::whereNotNull('correo_encolado_at')
            ->whereNull('correo_enviado_at')->whereNull('correo_error')->exists();
    }

    public function guardarInformacionBancaria(): void
    {
        $candidato = $this->candidatoParaDatos();
        if (! $candidato) {
            return;
        }

        $this->bancoGuardado = '';
        $this->cuentaSueldoEdit = trim($this->cuentaSueldoEdit);
        $this->cuentaFreeEdit = trim($this->cuentaFreeEdit);
        $this->cuentaCtsEdit = trim($this->cuentaCtsEdit);
        $this->cciEdit = preg_replace('/[\s-]+/', '', $this->cciEdit);

        $this->validate([
            'cuentaSueldoEdit' => ['nullable', 'regex:/^[0-9][0-9\s-]*$/', 'max:30'],
            'cuentaFreeEdit' => ['nullable', 'regex:/^[0-9][0-9\s-]*$/', 'max:30'],
            'cuentaCtsEdit' => ['nullable', 'regex:/^[0-9][0-9\s-]*$/', 'max:30'],
            'cciEdit' => ['nullable', 'digits:20'],
        ], [
            'regex' => 'Solo se permiten números, espacios y guiones.',
            'digits' => 'El CCI debe tener 20 dígitos.',
        ], ['cuentaSueldoEdit' => 'cuenta sueldo', 'cuentaFreeEdit' => 'cuenta free', 'cuentaCtsEdit' => 'cuenta CTS', 'cciEdit' => 'CCI']);

        $candidato->update([
            'cuenta_sueldo' => $this->cuentaSueldoEdit ?: null,
            'cuenta_free' => $this->cuentaFreeEdit ?: null,
            'cuenta_cts' => $this->cuentaCtsEdit ?: null,
            'cci' => $this->cciEdit ?: null,
        ]);

        $this->bancoGuardado = 'Información bancaria guardada.';
    }

    public function guardarCorreoCorporativo(): void
    {
        $candidato = $this->candidatoParaDatos();
        if (! $candidato) {
            return;
        }

        $this->correoCorpEdit = mb_strtolower(trim($this->correoCorpEdit));
        $this->correoCorpGuardado = '';

        $this->validate(['correoCorpEdit' => 'nullable|email:rfc|max:150'], [], ['correoCorpEdit' => 'correo corporativo']);

        // Con ficha completada el correo vive en el trabajador; antes de eso espera en el candidato.
        if ($empleado = $candidato->empleadoVinculado()) {
            $empleado->update(['email_corporativo' => $this->correoCorpEdit ?: null]);
            $candidato->update(['email_corporativo' => null]);
        } else {
            $candidato->update(['email_corporativo' => $this->correoCorpEdit ?: null]);
        }

        $this->correoCorpGuardado = $this->correoCorpEdit ? 'Correo guardado.' : 'Correo eliminado.';
    }

    /** Devuelve null si se encoló, o el motivo por el que se omitió. */
    protected function encolarEnvio(CandidatoReclutamiento $candidato): ?string
    {
        $correo = $candidato->correoCorporativo();
        if (! $correo) {
            return 'sin correo corporativo';
        }

        return app(EnvioInvitacionFicha::class)->encolar($candidato, $correo, 'actualizacion');
    }

    public function enviarLink(int $candidatoId): void
    {
        if (! $this->correoConfigurado()) {
            $this->mensajeEnvio = 'El envío de correos todavía no está configurado en el servidor.';

            return;
        }

        $candidato = CandidatoReclutamiento::where('apto', true)->find($candidatoId);
        $motivo = $candidato ? $this->encolarEnvio($candidato) : 'no existe';

        $this->mensajeEnvio = $motivo ? "No se envió: {$motivo}." : 'Correo en cola de envío.';
    }

    public function generarInvitacion(int $candidatoId): void
    {
        $candidato = CandidatoReclutamiento::where('apto', true)->find($candidatoId);
        if (! $candidato) {
            return;
        }

        $existente = OnboardingInvitation::principalDe($candidatoId);
        if ($existente && ($existente->usado_en || $existente->estaVigente())) {
            return;
        }

        $invitacion = OnboardingInvitation::generar($candidato->nombreCompleto(), $candidato->email);
        $invitacion->update([
            'reclutamiento_candidato_id' => $candidatoId,
            'creado_por' => Auth::id(),
        ]);
    }

    public function abrirFicha(int $empleadoId): void
    {
        $this->empleadoModalId = $empleadoId;
    }

    public function cerrarFicha(): void
    {
        $this->empleadoModalId = null;
    }

    public function empleadoSeleccionado(): ?Empleado
    {
        if (! $this->empleadoModalId) {
            return null;
        }

        return Empleado::with(['estudios', 'empleosAnteriores', 'familiares'])->find($this->empleadoModalId);
    }

    public function abrirDatos(int $candidatoId): void
    {
        $this->datosModalId = $candidatoId;

        $candidato = CandidatoReclutamiento::find($candidatoId);
        $this->cusppEdit = (string) ($candidato ? $this->invitacionDe($candidato)?->empleado?->pension_cuspp : '');
        $this->correoCorpEdit = (string) $candidato?->correoCorporativo();
        $this->correoCorpGuardado = '';
        $this->cuentaSueldoEdit = (string) $candidato?->cuenta_sueldo;
        $this->cuentaFreeEdit = (string) $candidato?->cuenta_free;
        $this->cuentaCtsEdit = (string) $candidato?->cuenta_cts;
        $this->cciEdit = (string) $candidato?->cci;
        $this->bancoGuardado = '';
    }

    public function cerrarDatos(): void
    {
        $this->datosModalId = null;
        $this->pestanaDatosModal = 'datos';
        $this->panelGenerarAbierto = false;
        $this->contratoFirmadoPeriodoId = null;
        $this->contratoFirmadoNuevo = null;
        $this->cusppEdit = '';
        $this->correoCorpEdit = '';
        $this->correoCorpGuardado = '';
        $this->cuentaSueldoEdit = '';
        $this->cuentaFreeEdit = '';
        $this->cuentaCtsEdit = '';
        $this->cciEdit = '';
        $this->bancoGuardado = '';
    }

    public function guardarCuspp(): void
    {
        $candidato = $this->candidatoParaDatos();
        $empleado = $candidato ? $this->invitacionDe($candidato)?->empleado : null;

        if (! $empleado) {
            return;
        }

        $this->validate(['cusppEdit' => ['nullable', 'string', 'max:50']], [], ['cusppEdit' => 'Código CUSPP']);

        $valor = trim($this->cusppEdit) !== '' ? mb_strtoupper(trim($this->cusppEdit)) : null;
        $empleado->update(['pension_cuspp' => $valor]);
        $this->cusppEdit = (string) $valor;
    }

    public function seleccionarPestanaDatos(string $pestana): void
    {
        if ($pestana === 'contrato') {
            $this->exigirAdmin();
        }

        $this->pestanaDatosModal = $pestana;

        if ($pestana === 'contrato') {
            $this->precargarFormularioContrato();
            // Sin períodos (ingreso nuevo) o con el último cesado (reingreso): el panel arranca abierto
            // para registrar la prueba y generar el contrato.
            $periodos = $this->empleadoActual()?->periodosContrato ?? collect();
            $this->panelGenerarAbierto = $periodos->isEmpty() || (bool) $periodos->first()->cese;
        }
    }

    public function periodoContratoFirmado(): ?EmpleadoPeriodoContrato
    {
        if (! $this->contratoFirmadoPeriodoId) {
            return null;
        }

        return $this->empleadoActual()?->periodosContrato->firstWhere('id', $this->contratoFirmadoPeriodoId);
    }

    public function abrirContratoFirmado(int $periodoId): void
    {
        $this->exigirAdmin();

        // Solo períodos del trabajador que se está viendo
        if (! $this->empleadoActual()?->periodosContrato->firstWhere('id', $periodoId)) {
            return;
        }

        $this->contratoFirmadoPeriodoId = $periodoId;
        $this->contratoFirmadoNuevo = null;
        $this->resetErrorBag('contratoFirmadoNuevo');
    }

    public function cerrarContratoFirmado(): void
    {
        $this->contratoFirmadoPeriodoId = null;
        $this->contratoFirmadoNuevo = null;
        $this->resetErrorBag('contratoFirmadoNuevo');
    }

    public function cancelarContratoFirmadoNuevo(): void
    {
        $this->contratoFirmadoNuevo = null;
        $this->resetErrorBag('contratoFirmadoNuevo');
    }

    public function guardarContratoFirmado(): void
    {
        $this->exigirAdmin();

        $periodo = $this->periodoContratoFirmado();

        if (! $periodo || ! $this->contratoFirmadoNuevo) {
            return;
        }

        $this->validate([
            'contratoFirmadoNuevo' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ], [], ['contratoFirmadoNuevo' => 'archivo']);

        $existente = $periodo->documentoContrato;
        if ($existente) {
            Storage::disk('local')->delete($existente->ruta);
        }

        $archivo = $this->contratoFirmadoNuevo;
        $ruta = $archivo->store("empleado-contratos-firmados/{$periodo->empleado_id}", 'local');

        EmpleadoDocumentoContrato::updateOrCreate(
            ['periodo_id' => $periodo->id],
            [
                'nombre_original' => $archivo->getClientOriginalName(),
                'ruta' => $ruta,
                'mime_type' => $archivo->getClientMimeType(),
                'tamano' => $archivo->getSize(),
                'subido_por_id' => Auth::id(),
            ],
        );

        $this->contratoFirmadoNuevo = null;
        $this->empleadoActual()?->load('periodosContrato.documentoContrato');
    }

    public function eliminarContratoFirmado(): void
    {
        $this->exigirAdmin();

        $documento = $this->periodoContratoFirmado()?->documentoContrato;

        if (! $documento) {
            return;
        }

        Storage::disk('local')->delete($documento->ruta);
        $documento->delete();
    }

    public function alternarPanelGenerar(): void
    {
        $this->exigirAdmin();

        $this->panelGenerarAbierto = ! $this->panelGenerarAbierto;
    }

    private function precargarFormularioContrato(): void
    {
        $candidato = $this->candidatoParaDatos();
        $empleado = $candidato ? $this->invitacionDe($candidato)?->empleado : null;

        $this->contratoArea = $empleado?->contrato_area ?? $empleado?->area ?? '';
        $this->contratoPuesto = $empleado?->contrato_puesto ?? $empleado?->puesto ?? '';
        $this->contratoCampana = $empleado?->contrato_campana ?? $empleado?->campana ?? '';
        $this->contratoCargo = $empleado?->contrato_cargo ?? $empleado?->cargo ?? '';
        $this->contratoAreaPracticas = $empleado?->contrato_area_practicas ?? '';
        $this->contratoUniversidad = $empleado?->contrato_universidad ?? '';
        $this->contratoRemuneracion = $empleado?->contrato_remuneracion !== null ? (string) $empleado->contrato_remuneracion : '';
        $this->contratoRemuneracionMovilidad = $empleado?->contrato_remuneracion_movilidad !== null ? (string) $empleado->contrato_remuneracion_movilidad : '';
        $this->contratoFechaInicio = $empleado?->contrato_fecha_inicio?->format('Y-m-d') ?? '';
        $this->contratoFechaFin = $empleado?->contrato_fecha_fin?->format('Y-m-d') ?? '';
        $this->contratoDuracionMeses = ($this->contratoFechaInicio !== '' && $this->contratoFechaFin !== '')
            ? (string) $this->duracionEnMeses($this->contratoFechaInicio, $this->contratoFechaFin)
            : '';
        $this->contratoFechaFirma = $empleado?->contrato_fecha_firma?->format('Y-m-d') ?? now()->format('Y-m-d');
        $this->periodoCeseActivoId = null;
        $this->contratoCeseFecha = '';
        $this->contratoCeseTipo = '';
        $this->mostrarFormularioRenovacion = false;

        // Lleva prueba salvo que el último período sea una renovación vigente (si hubo cese, es un reingreso: vuelve a prueba)
        $ultimo = $empleado?->periodosContrato()->latest('fecha_inicio')->first();
        $this->contratoLlevaPrueba = ! ($ultimo && $ultimo->tipo === 'renovacion' && ! $ultimo->cese);
    }

    public function updatedContratoArea(): void
    {
        $this->contratoPuesto = '';
        $this->contratoCampana = '';
        $this->contratoCargo = '';
    }

    public function contratoAreaModelo(): ?Area
    {
        return $this->contratoArea !== '' ? Area::where('nombre', $this->contratoArea)->first() : null;
    }

    public function contratoPuestosDisponibles()
    {
        $area = $this->contratoAreaModelo();

        return $area ? Puesto::where('area_id', $area->id)->orderBy('nombre')->pluck('nombre') : collect();
    }

    /** La campaña aplica si el área la usa y el puesto elegido no está marcado "sin campaña". */
    public function contratoUsaCampana(): bool
    {
        $area = $this->contratoAreaModelo();
        if (! $area?->usa_campana) {
            return false;
        }

        $puesto = Puesto::where('area_id', $area->id)->where('nombre', $this->contratoPuesto)->first();

        return $puesto?->usa_campana ?? true;
    }

    public function contratoCampanasDisponibles()
    {
        $area = $this->contratoAreaModelo();

        return $area ? Campana::where('area_id', $area->id)->orderBy('nombre')->pluck('nombre') : collect();
    }

    public function contratoCargosDisponibles()
    {
        $area = $this->contratoAreaModelo();

        return $area ? Cargo::where('area_id', $area->id)->orderBy('nombre')->pluck('nombre') : collect();
    }

    public function contratoTipo(): ?string
    {
        $area = $this->contratoAreaModelo();
        if (! $area) {
            return null;
        }

        if ($area->usa_cargo) {
            if ($this->contratoCargo === '') {
                return null;
            }

            $cargo = Cargo::where('area_id', $area->id)->where('nombre', $this->contratoCargo)->first();
            if ($cargo?->tipo_contrato) {
                return $cargo->tipo_contrato;
            }
        }

        if ($this->contratoPuesto !== '') {
            $puesto = Puesto::where('area_id', $area->id)->where('nombre', $this->contratoPuesto)->first();
            if ($puesto?->tipo_contrato) {
                return $puesto->tipo_contrato;
            }
        }

        return $area->tipo_contrato;
    }

    public function contratoPlantillaInfo(): ?array
    {
        $tipo = $this->contratoTipo();
        if (! $tipo) {
            return null;
        }

        $plantillas = [
            'ejecutivo' => ['label' => 'Contrato de trabajo (Ejecutivo)', 'archivo' => 'contrato-trabajo-template.docx'],
            'practicas' => ['label' => 'Convenio de prácticas preprofesionales', 'archivo' => 'convenio-practicas-template.docx'],
            'administrativo' => ['label' => 'Contrato administrativo', 'archivo' => 'contrato-administrativo-template.docx'],
        ];

        $info = $plantillas[$tipo] ?? null;
        if (! $info) {
            return null;
        }

        $info['ruta'] = resource_path('data/'.$info['archivo']);
        $info['existe'] = file_exists($info['ruta']);

        return $info;
    }

    public function descargarContrato()
    {
        $this->exigirAdmin();

        $candidato = $this->candidatoParaDatos();
        if (! $candidato) {
            return;
        }

        $empleado = $this->invitacionDe($candidato)?->empleado;
        if (! $empleado) {
            return;
        }

        $area = $this->contratoAreaModelo();
        $tipo = $this->contratoTipo();

        $this->contratoAreaPracticas = mb_strtoupper(trim($this->contratoAreaPracticas));
        $this->contratoUniversidad = mb_strtoupper(trim($this->contratoUniversidad));

        $this->validate([
            'contratoArea' => 'required|string',
            'contratoPuesto' => 'required|string|max:150',
            'contratoCampana' => $this->contratoUsaCampana() ? 'required|string|max:150' : 'nullable|string|max:150',
            'contratoCargo' => $area?->usa_cargo ? 'required|string|max:150' : 'nullable|string|max:150',
            'contratoAreaPracticas' => $tipo === 'practicas' ? 'required|string|max:150' : 'nullable|string|max:150',
            'contratoUniversidad' => $tipo === 'practicas' ? 'required|string|max:150' : 'nullable|string|max:150',
            'contratoRemuneracion' => $tipo === 'practicas' ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'contratoRemuneracionMovilidad' => 'nullable|numeric|min:0',
            'contratoFechaInicio' => 'required|date',
            'contratoDuracionMeses' => 'required|integer|min:1|max:60',
            'contratoFechaFirma' => 'required|date',
        ], [], [
            'contratoArea' => 'área',
            'contratoPuesto' => 'puesto',
            'contratoCampana' => 'campaña',
            'contratoCargo' => 'cargo',
            'contratoAreaPracticas' => 'área de prácticas',
            'contratoUniversidad' => 'universidad',
            'contratoRemuneracion' => $tipo === 'practicas' ? 'subvención' : 'remuneración',
            'contratoRemuneracionMovilidad' => 'movilidad',
            'contratoFechaInicio' => 'fecha de inicio',
            'contratoDuracionMeses' => 'duración (meses)',
            'contratoFechaFirma' => 'fecha de firma',
        ]);

        $plantilla = $this->contratoPlantillaInfo();
        $this->contratoFechaFin = $this->finContrato();
        $duracionTexto = $this->duracionTexto($this->contratoFechaInicio, $this->contratoFechaFin);
        $llevaPrueba = $this->contratoLlevaPrueba && in_array($tipo, ['ejecutivo', 'administrativo'], true);

        $empleado->update([
            'contrato_area' => $this->contratoArea,
            'contrato_puesto' => $this->contratoPuesto,
            'contrato_campana' => $this->contratoUsaCampana() ? $this->contratoCampana : null,
            'contrato_cargo' => $area?->usa_cargo ? $this->contratoCargo : null,
            'contrato_duracion' => in_array($tipo, ['ejecutivo', 'administrativo'], true) ? $duracionTexto : null,
            'contrato_area_practicas' => $tipo === 'practicas' ? $this->contratoAreaPracticas : null,
            'contrato_universidad' => $tipo === 'practicas' ? $this->contratoUniversidad : null,
            'contrato_remuneracion' => $tipo === 'practicas' ? null : $this->contratoRemuneracion,
            'contrato_remuneracion_movilidad' => $tipo === 'administrativo' && $this->contratoRemuneracionMovilidad !== '' ? $this->contratoRemuneracionMovilidad : null,
            'contrato_fecha_inicio' => $this->contratoFechaInicio,
            'contrato_fecha_fin' => $this->contratoFechaFin,
            'contrato_fecha_firma' => $this->contratoFechaFirma,
        ]);

        if (! $plantilla || ! $plantilla['existe']) {
            $this->addError('contratoArea', 'Todavía no se subió la plantilla para este tipo de contrato ('.($plantilla['label'] ?? 'desconocido').'). Los datos ya quedaron guardados.');

            return;
        }

        $nombreCompleto = trim("{$empleado->apellido_paterno} {$empleado->apellido_materno} {$empleado->nombres}");

        // Sin período de prueba: se quita esa cláusula y se renumeran las siguientes
        $rutaPlantilla = $tipo === 'practicas' ? $plantilla['ruta'] : ContratoWord::preparar($plantilla['ruta'], $llevaPrueba);

        $procesador = new TemplateProcessor($rutaPlantilla);
        $procesador->setValue('NOMBRE_TRABAJADOR', mb_strtoupper($nombreCompleto));
        $procesador->setValue('DNI_TRABAJADOR', $empleado->documento_identidad);
        $procesador->setValue('DIRECCION_TRABAJADOR', mb_strtoupper((string) $empleado->direccion));

        if ($tipo === 'ejecutivo') {
            $procesador->setValue('DURACION_CONTRATO', $duracionTexto);
            $procesador->setValue('REMUNERACION', $this->remuneracionNumero((float) $this->contratoRemuneracion));
            $procesador->setValue('FECHA_INICIO_CONTRATO', $this->fechaLarga($this->contratoFechaInicio));
            $procesador->setValue('FECHA_FIN_CONTRATO', $this->fechaLarga($this->contratoFechaFin));
            $procesador->setValue('FECHA_FIRMA_CONTRATO', $this->fechaLarga($this->contratoFechaFirma));
        } elseif ($tipo === 'administrativo') {
            $puestoCompleto = $this->contratoCargo !== ''
                ? "{$this->contratoCargo} DE {$this->contratoPuesto}"
                : $this->contratoPuesto;
            $procesador->setValue('PUESTO_TRABAJADOR', mb_strtoupper($puestoCompleto));
            $procesador->setValue('DURACION_CONTRATO', $duracionTexto);
            $procesador->setValue('FECHA_INICIO_CONTRATO', $this->fechaLarga($this->contratoFechaInicio));
            $procesador->setValue('FECHA_FIN_CONTRATO', $this->fechaLarga($this->contratoFechaFin));
            $remuneracionTexto = $this->remuneracionNumero((float) $this->contratoRemuneracion);
            if ($this->contratoRemuneracionMovilidad !== '' && (float) $this->contratoRemuneracionMovilidad > 0) {
                $remuneracionTexto .= ' + '.$this->remuneracionNumero((float) $this->contratoRemuneracionMovilidad).' DE MOVILIDAD';
            }
            $procesador->setValue('REMUNERACION', $remuneracionTexto);
            $procesador->setValue('FECHA_FIRMA_CONTRATO', $this->fechaLarga($this->contratoFechaFirma));
        } elseif ($tipo === 'practicas') {
            $procesador->setValue('TIPO_DOCUMENTO', 'DNI');
            $procesador->setValue('UNIVERSIDAD', $this->contratoUniversidad);
            $procesador->setValue('AREA_PRACTICAS', $this->contratoAreaPracticas);
            $procesador->setValue('DURACION_CONVENIO', mb_strtolower($duracionTexto));
            $procesador->setValue('FECHA_INICIO_CONVENIO', $this->fechaLarga($this->contratoFechaInicio));
            $procesador->setValue('FECHA_FIN_CONVENIO', $this->fechaLarga($this->contratoFechaFin));
            $procesador->setValue('FECHA_FIRMA_CONVENIO', $this->fechaDiasDelMes($this->contratoFechaFirma));
        }

        if ($llevaPrueba) {
            $procesador->setValue('PRUEBA_INICIO', $this->fechaLarga($this->contratoFechaInicio));
            $procesador->setValue('PRUEBA_FIN', $this->fechaLarga($this->finPeriodoPrueba($this->contratoFechaInicio)));
        }

        $rutaTemporal = tempnam(sys_get_temp_dir(), 'contrato_').'.docx';
        $procesador->saveAs($rutaTemporal);

        if ($rutaPlantilla !== $plantilla['ruta']) {
            @unlink($rutaPlantilla);
        }

        $nombreArchivo = 'contrato-'.str($nombreCompleto)->slug().'.docx';

        return response()->streamDownload(function () use ($rutaTemporal) {
            echo file_get_contents($rutaTemporal);
            unlink($rutaTemporal);
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /** Meses completos entre el inicio y el fin (incluido) del contrato, p. ej. 04/05 – 03/08 = 3 meses. */
    private function duracionEnMeses(string $inicio, string $fin): int
    {
        $desde = Carbon::parse($inicio)->startOfDay();
        $hasta = Carbon::parse($fin)->startOfDay()->addDay();

        return max(1, (int) floor($desde->diffInMonths($hasta)));
    }

    private function duracionTexto(string $inicio, string $fin): string
    {
        $meses = $this->duracionEnMeses($inicio, $fin);

        return $meses.($meses === 1 ? ' MES' : ' MESES');
    }

    /** Último día del contrato: inicio + meses − 1 día (04/05 + 3 meses = 03/08). */
    private function finContrato(): ?string
    {
        if ($this->contratoFechaInicio === '' || ! ctype_digit($this->contratoDuracionMeses) || (int) $this->contratoDuracionMeses < 1) {
            return null;
        }

        return Carbon::parse($this->contratoFechaInicio)->addMonthsNoOverflow((int) $this->contratoDuracionMeses)->subDay()->format('Y-m-d');
    }

    /** El período de prueba dura 3 meses desde el inicio del contrato. */
    private function finPeriodoPrueba(string $inicio): string
    {
        return Carbon::parse($inicio)->addMonthsNoOverflow(3)->subDay()->format('Y-m-d');
    }

    private function fechaLarga(?string $fecha): string
    {
        if (! $fecha) {
            return '';
        }

        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        $carbon = Carbon::parse($fecha);

        return "{$carbon->day} de {$meses[$carbon->month]} del {$carbon->year}";
    }

    private function fechaDiasDelMes(?string $fecha): string
    {
        if (! $fecha) {
            return '';
        }

        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        $carbon = Carbon::parse($fecha);
        $dia = str_pad((string) $carbon->day, 2, '0', STR_PAD_LEFT);

        return "{$dia} días del mes de {$meses[$carbon->month]} del {$carbon->year}";
    }

    private function remuneracionNumero(float $monto): string
    {
        return 'S/ '.number_format($monto, 2, '.', ',');
    }

    private function remuneracionLetras(float $monto): string
    {
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);
        $texto = $this->numeroATexto($entero);

        return sprintf('(%s CON %02d/100 SOLES)', $texto, $centavos);
    }

    private function numeroATexto(int $numero): string
    {
        if ($numero === 0) {
            return 'CERO';
        }

        $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $especiales = [
            10 => 'DIEZ', 11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE',
            16 => 'DIECISÉIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO', 19 => 'DIECINUEVE',
            20 => 'VEINTE', 21 => 'VEINTIUNO', 22 => 'VEINTIDÓS', 23 => 'VEINTITRÉS', 24 => 'VEINTICUATRO',
            25 => 'VEINTICINCO', 26 => 'VEINTISÉIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO', 29 => 'VEINTINUEVE',
        ];
        $decenas = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

        $convertirGrupo = function (int $n) use ($unidades, $especiales, $decenas, $centenas) {
            if ($n === 0) {
                return '';
            }
            if ($n === 100) {
                return 'CIEN';
            }

            $texto = '';
            $centena = intdiv($n, 100);
            $resto = $n % 100;

            if ($centena > 0) {
                $texto .= $centenas[$centena];
            }

            if ($resto > 0) {
                if (isset($especiales[$resto])) {
                    $parteResto = $especiales[$resto];
                } elseif ($resto < 10) {
                    $parteResto = $unidades[$resto];
                } else {
                    $decena = intdiv($resto, 10);
                    $unidad = $resto % 10;
                    $parteResto = $decenas[$decena];
                    if ($unidad > 0) {
                        $parteResto .= ' Y '.$unidades[$unidad];
                    }
                }

                $texto .= ($texto !== '' ? ' ' : '').$parteResto;
            }

            return $texto;
        };

        // Apócope: "VEINTIUNO" -> "VEINTIUN" cuando antecede a un sustantivo ("MIL", "MILLONES").
        $apocope = fn (string $texto): string => str_ends_with($texto, 'UNO') ? substr($texto, 0, -1) : $texto;

        if ($numero < 1000) {
            return $convertirGrupo($numero);
        }

        if ($numero < 1_000_000) {
            $miles = intdiv($numero, 1000);
            $resto = $numero % 1000;
            $textoMiles = $miles === 1 ? 'MIL' : $apocope($convertirGrupo($miles)).' MIL';
            $textoResto = $resto > 0 ? ' '.$convertirGrupo($resto) : '';

            return trim($textoMiles.$textoResto);
        }

        $millones = intdiv($numero, 1_000_000);
        $resto = $numero % 1_000_000;
        $textoMillones = $millones === 1 ? 'UN MILLÓN' : $apocope($convertirGrupo($millones)).' MILLONES';
        $textoResto = $resto > 0 ? ' '.$this->numeroATexto($resto) : '';

        return trim($textoMillones.$textoResto);
    }

    public function candidatoParaDatos(): ?CandidatoReclutamiento
    {
        if (! $this->datosModalId) {
            return null;
        }

        return CandidatoReclutamiento::find($this->datosModalId);
    }

    public function datosContacto(CandidatoReclutamiento $candidato): array
    {
        $limpio = fn (array $campos) => array_filter($campos, fn ($valor) => $valor !== null && $valor !== '');

        $empleado = $this->invitacionDe($candidato)?->empleado;
        $empleado?->load(['estudios', 'empleosAnteriores', 'familiares']);

        if (! $empleado) {
            $nombreCompleto = trim("{$candidato->apellidos} {$candidato->nombres}") ?: 'Sin nombre';

            return [
                'nombre' => $nombreCompleto,
                'tiene_empleado' => false,
                'secciones' => [
                    'Reclutamiento' => $limpio([
                        'DNI / C.E' => $candidato->dni_ce,
                        'Campaña' => $candidato->campana,
                        'Teléfono' => $candidato->numero_celular,
                        'Distrito' => $candidato->distrito,
                    ]),
                ],
            ];
        }

        $fecha = fn ($valor) => $valor && (($valor instanceof \DateTimeInterface) || strtotime((string) $valor) !== false)
            ? \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y')
            : ($valor ?: null);
        $siNo = fn (?bool $valor) => is_null($valor) ? null : ($valor ? 'Sí' : 'No');

        $nombreCompleto = trim("{$empleado->apellido_paterno} {$empleado->apellido_materno} {$empleado->nombres}") ?: 'Sin nombre';

        $contactoEmergencia = null;
        if ($empleado->contacto_emergencia_nombre) {
            $contactoEmergencia = $empleado->contacto_emergencia_nombre;
            if ($empleado->contacto_emergencia_parentesco) {
                $contactoEmergencia .= " ({$empleado->contacto_emergencia_parentesco})";
            }
            if ($empleado->contacto_emergencia_telefono) {
                $contactoEmergencia .= " · {$empleado->contacto_emergencia_telefono}";
            }
        }

        $secciones = [];

        $secciones['Puesto al que ingresa'] = $limpio([
            'Fecha de capa' => $fecha($empleado->fecha_capa),
            'Puesto' => $empleado->puesto,
            'Campaña' => $empleado->campana,
        ]);

        $secciones['Datos personales'] = $limpio([
            'DNI / CE / PTP' => $empleado->documento_identidad,
            'Apellido paterno' => $empleado->apellido_paterno,
            'Apellido materno' => $empleado->apellido_materno,
            'Nombres' => $empleado->nombres,
            'Fecha de nacimiento' => $fecha($empleado->fecha_nacimiento),
            'Lugar de nacimiento' => $empleado->lugar_nacimiento,
            'Edad' => $empleado->edad,
            'N° hijos' => $empleado->numero_hijos,
            '¿Hijos menores de edad?' => $siNo($empleado->hijos_menores),
            'Estado civil' => $empleado->estado_civil ? ucfirst($empleado->estado_civil) : null,
            'Sexo' => match ($empleado->sexo) { 'F' => 'Femenino', 'M' => 'Masculino', default => null },
            'Dirección' => $empleado->direccion,
            'Tipo de vivienda' => $empleado->vivienda_tipo ? ucfirst($empleado->vivienda_tipo) : null,
            'Tenencia' => $empleado->vivienda_tenencia ? ucfirst($empleado->vivienda_tenencia) : null,
            'Distrito' => $empleado->distrito,
            'Provincia' => $empleado->provincia,
            'Departamento' => $empleado->departamento_residencia,
            'Celular de llamadas' => $empleado->celular_llamadas,
            'Correo electrónico (personal)' => $empleado->email,
            'Correo corporativo' => $empleado->email_corporativo,
            'Celular de WhatsApp' => $empleado->celular_whatsapp,
            'Contacto de emergencia' => $contactoEmergencia,
        ]);

        $secciones['Sistema pensionario'] = $limpio([
            '¿Afiliado?' => $siNo($empleado->pension_afiliado),
            'Sistema' => $empleado->pension_sistema ? strtoupper($empleado->pension_sistema) : null,
            'AFP' => $empleado->pension_afp ? ucfirst($empleado->pension_afp) : null,
        ]);

        foreach ($empleado->estudios as $indice => $estudio) {
            $secciones['Estudios realizados #'.($indice + 1)] = $limpio([
                'Nivel' => $estudio->nivel ? ucfirst($estudio->nivel) : null,
                'Centro de estudios' => $estudio->centro_estudios,
                'Carrera' => $estudio->carrera,
                'Desde' => $fecha($estudio->desde),
                'Hasta' => $fecha($estudio->hasta),
                'Grado obtenido' => $estudio->grado_obtenido ? ucfirst($estudio->grado_obtenido) : null,
            ]);
        }

        foreach ($empleado->empleosAnteriores as $indice => $empleo) {
            $secciones['Información laboral #'.($indice + 1)] = $limpio([
                'Empresa' => $empleo->empresa,
                'Cargo' => $empleo->cargo,
                'Función principal' => $empleo->funcion_principal,
                'Sueldo' => $empleo->sueldo,
                'Fecha inicio' => $fecha($empleo->fecha_inicio),
                'Fecha término' => $fecha($empleo->fecha_termino),
                'Motivo de cese' => $empleo->motivo_cese,
                'Jefe inmediato' => $empleo->jefe_nombre,
                'Cargo del jefe' => $empleo->jefe_cargo,
                'Celular del jefe' => $empleo->jefe_celular,
            ]);
        }

        foreach ($empleado->familiares as $indice => $familiar) {
            $secciones['Información familiar #'.($indice + 1)] = $limpio([
                'Apellidos y nombres' => $familiar->nombres_apellidos,
                'Parentesco' => $familiar->parentesco,
                'Fecha de nacimiento' => $fecha($familiar->fecha_nacimiento),
                'Edad' => $familiar->edad,
                'Ocupación' => $familiar->ocupacion,
            ]);
        }

        $secciones['Salud'] = $limpio([
            '¿Antecedentes cardíacos u oncológicos?' => $siNo($empleado->salud_antecedentes),
            '¿Enfermedad actual?' => $siNo($empleado->salud_enfermedad_actual),
            'Detalle' => $empleado->salud_enfermedad_detalle,
            '¿Toma medicamentos?' => $empleado->salud_medicamentos,
        ]);

        $secciones['Declaración'] = $limpio([
            'Datos veraces' => $siNo($empleado->declaracion_datos_veraces),
            'Autoriza verificación' => $siNo($empleado->declaracion_autoriza_verificacion),
            'Condiciones de capacitación' => $siNo($empleado->declaracion_capacitacion_condiciones),
        ]);

        $secciones['Firma'] = $limpio([
            'DNI de firma' => $empleado->firma_dni,
            'Firma registrada' => $empleado->firma_imagen ? 'Sí' : null,
        ]);

        $secciones = array_filter($secciones, fn (array $campos) => $campos !== []);

        return [
            'nombre' => $nombreCompleto,
            'tiene_empleado' => true,
            'secciones' => $secciones,
        ];
    }

    public function descargarFicha(int $empleadoId)
    {
        $empleado = Empleado::with(['estudios', 'empleosAnteriores', 'familiares'])->find($empleadoId);

        if (! $empleado) {
            return;
        }

        $pdf = Pdf::loadView('pdf.ficha-ingreso', ['empleado' => $empleado]);

        $nombreArchivo = 'ficha-'.str("{$empleado->apellido_paterno}-{$empleado->nombres}")->slug().'.pdf';

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $nombreArchivo,
        );
    }
};
?>

<div class="space-y-6">
    <div>
        <h2 class="mt-2 text-2xl font-bold text-slate-900">Registro de ingresos</h2>
    </div>

    @unless ($this->correoConfigurado())
        <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
            El envío de correos aún no está configurado en el servidor (falta el SMTP). Mientras tanto puedes guardar los correos corporativos y copiar los links manualmente.
        </div>
    @endunless

    @if ($mensajeEnvio)
        <p class="text-sm text-slate-600">{{ $mensajeEnvio }}</p>
    @endif

    <div class="reclutamiento-filter-bar">
        @if ($filtroAbierto !== '')
            <button type="button" wire:click="cerrarFiltro" class="fixed inset-0 z-30 cursor-default" aria-label="Cerrar filtro" tabindex="-1"></button>
        @endif

        @foreach ($filtrosActivos as $filtro)
            @php($criterios = $this->criteriosDeFiltro($filtro))
            <div class="reclutamiento-filter-chip" wire:key="chip-{{ $filtro }}">
                <button type="button" wire:click="alternarFiltro('{{ $filtro }}')" class="reclutamiento-filter-chip-button{{ $criterios ? ' is-active' : '' }}{{ $filtroAbierto === $filtro ? ' is-open' : '' }}">
                    <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h12M9 12h6M11 18h2" /></svg>
                    <span>{{ self::FILTROS[$filtro] }}</span>
                    @if ($criterios)
                        <span class="reclutamiento-filter-chip-count">1</span>
                    @endif
                </button>
                <button type="button" wire:click="quitarFiltro('{{ $filtro }}')" class="reclutamiento-filter-chip-remove" aria-label="Quitar filtro" title="Quitar filtro">
                    <svg aria-hidden="true" class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12" /><path d="M18 6L6 18" /></svg>
                </button>

                @if ($filtroAbierto === $filtro)
                    <div class="reclutamiento-filter-popover">
                        @if ($filtro === 'nombre')
                            <input type="text" wire:model.live.debounce.300ms="filtroNombre" wire:key="in-nombre" class="reclutamiento-filter-popover-search !mb-0" placeholder="Apellidos o nombres..." autofocus>
                        @elseif ($filtro === 'dni')
                            <input type="text" inputmode="numeric" wire:model.live.debounce.300ms="filtroDni" wire:key="in-dni" class="reclutamiento-filter-popover-search !mb-0" placeholder="Número de documento..." autofocus>
                        @else
                            <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Desde</label>
                            <input type="date" wire:model.live="filtroIngresoDesde" wire:key="in-desde" class="reclutamiento-filter-popover-search">
                            <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Hasta</label>
                            <input type="date" wire:model.live="filtroIngresoHasta" wire:key="in-hasta" class="reclutamiento-filter-popover-search !mb-0">
                        @endif
                    </div>
                @endif
            </div>
        @endforeach

        @if (count($filtrosActivos) < count(self::FILTROS))
            <div class="reclutamiento-filter-chip">
                <button type="button" wire:click="alternarFiltro('agregar')" class="reclutamiento-filter-chip-button{{ $filtroAbierto === 'agregar' ? ' is-open' : '' }}">
                    <span>+ Añadir filtro</span>
                </button>
                @if ($filtroAbierto === 'agregar')
                    <div class="reclutamiento-filter-popover">
                        <div class="reclutamiento-filter-popover-list">
                            @foreach (self::FILTROS as $clave => $etiqueta)
                                @unless (in_array($clave, $filtrosActivos, true))
                                    <button type="button" wire:click="agregarFiltro('{{ $clave }}')" class="reclutamiento-filter-popover-option w-full text-left">{{ $etiqueta }}</button>
                                @endunless
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <div class="card-panel !p-0 overflow-x-auto" @if ($this->hayEnviosEnCola()) wire:poll.5s @endif>
        <table class="data-table w-full">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>DNI / C.E</th>
                    <th>Puesto</th>
                    <th>Campaña</th>
                    <th>Agente reclutador</th>
                    <th>Fecha APTO</th>
                    <th>Fecha de ingreso</th>
                    <th>Invitación</th>
                    <th>Formulario</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->candidatosAptos() as $candidato)
                    @php($invitacion = $this->invitacionDe($candidato))
                    <tr wire:key="apto-{{ $candidato->id }}">
                        <td class="font-medium text-slate-900">
                            <div class="flex items-center gap-2">
                                <span>{{ $candidato->nombre_mostrado ?: 'Sin nombre' }}</span>
                                <button
                                    type="button"
                                    wire:click="abrirDatos({{ $candidato->id }})"
                                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded text-slate-400 transition hover:bg-slate-100 hover:text-sky-600"
                                    aria-label="Ver datos de contacto"
                                    title="Ver datos de contacto"
                                >
                                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="4" width="20" height="16" rx="2" />
                                        <circle cx="8" cy="11" r="2" />
                                        <path d="M5 17c0-1.7 1.5-3 3-3s3 1.3 3 3" />
                                        <path d="M14 9h6M14 13h6" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                        <td>{{ $candidato->dni_mostrado ?: '-' }}</td>
                        <td>{{ $candidato->puesto ?: '-' }}</td>
                        <td>{{ $candidato->campana ?: '-' }}</td>
                        <td>{{ $candidato->agente_reclutador ?: '-' }}</td>
                        <td>{{ $candidato->fecha_apto?->format('d/m/Y') ?? '-' }}</td>
                        <td>{{ $candidato->fecha_ingreso_contrato?->format('d/m/Y') ?? '-' }}</td>
                        <td>
                            <div class="space-y-1.5">
                            @if ($invitacion && $invitacion->estaVigente())
                                <input type="text" readonly value="{{ $invitacion->url() }}" onclick="this.select()" class="form-input w-56 py-1 text-xs" title="Clic para seleccionar y copiar el enlace" />
                            @elseif ($invitacion?->usado_en)
                                <span class="badge badge-green">Usada</span>
                            @else
                                <button type="button" wire:click="generarInvitacion({{ $candidato->id }})" class="btn-secondary w-auto">
                                    {{ $invitacion ? 'Regenerar link' : 'Generar link de ficha' }}
                                </button>
                            @endif

                                @unless ($invitacion?->empleado)
                                    @php($estadoCorreo = $invitacion?->estadoCorreo() ?? 'sin-enviar')
                                    @if ($estadoCorreo === 'enviado')
                                        <p class="text-xs text-emerald-600">✔ Enviado a {{ $invitacion->correo_a }} · {{ $invitacion->correo_enviado_at->timezone('America/Lima')->format('d/m H:i') }}</p>
                                    @elseif ($estadoCorreo === 'en-cola')
                                        <p class="text-xs text-amber-600">Enviando a {{ $invitacion->correo_a }}…</p>
                                    @elseif ($estadoCorreo === 'error')
                                        <p class="text-xs text-rose-600" title="{{ $invitacion->correo_error }}">✖ Error al enviar a {{ $invitacion->correo_a }}</p>
                                    @endif

                                    @unless ($candidato->correoCorporativo())
                                        <p class="text-xs text-slate-400">Sin correo corporativo (agrégalo en Datos).</p>
                                    @endunless

                                    @if ($candidato->correoCorporativo() && $this->correoConfigurado())
                                        @php($textoEnvio = in_array($estadoCorreo, ['enviado', 'error'], true) ? 'Reenviar correo' : 'Enviar por correo')
                                        <button type="button" wire:click="enviarLink({{ $candidato->id }})" wire:loading.attr="disabled" wire:target="enviarLink({{ $candidato->id }})" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-sky-600 disabled:opacity-40" title="{{ $textoEnvio }}" aria-label="{{ $textoEnvio }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-send" viewBox="0 0 16 16" aria-hidden="true">
                                                <path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576zm6.787-8.201L1.591 6.602l4.339 2.76z"/>
                                            </svg>
                                        </button>
                                    @endif
                                @endunless
                            </div>
                        </td>
                        <td>
                            @if ($invitacion?->empleado)
                                <button
                                    type="button"
                                    wire:click="abrirFicha({{ $invitacion->empleado->id }})"
                                    class="badge badge-green transition hover:opacity-80"
                                >
                                    Completado ({{ ucfirst($invitacion->empleado->estado) }})
                                </button>
                            @else
                                <span class="text-xs text-slate-400">Pendiente</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-sm text-slate-400">
                            {{ $this->hayFiltros() ? 'Ningún registro coincide con los filtros.' : 'Todavía no hay candidatos marcados como aptos en Proceso de capacitación.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->empleadoSeleccionado())
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div class="flex h-[min(92vh,900px)] w-full max-w-[min(96vw,900px)] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h3 class="text-lg font-semibold text-slate-900">Ficha de datos personales</h3>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="descargarFicha({{ $this->empleadoSeleccionado()->id }})"
                            wire:loading.attr="disabled"
                            wire:target="descargarFicha({{ $this->empleadoSeleccionado()->id }})"
                            class="btn-secondary w-auto"
                        >
                            <span wire:loading.remove wire:target="descargarFicha({{ $this->empleadoSeleccionado()->id }})">Descargar PDF</span>
                            <span wire:loading wire:target="descargarFicha({{ $this->empleadoSeleccionado()->id }})">Generando...</span>
                        </button>
                        <button type="button" wire:click="cerrarFicha" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-6">
                    <x-panel.ficha-ingreso :empleado="$this->empleadoSeleccionado()" />
                </div>
            </div>
        </div>
    @endif

    @if ($this->candidatoParaDatos())
        @php($datosCandidatoModal = $this->candidatoParaDatos())
        @php($datosContacto = $this->datosContacto($datosCandidatoModal))
        @php($documentosCandidato = $this->documentosDe($datosCandidatoModal))
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div class="flex h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white text-xs shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h3 class="text-sm font-semibold uppercase text-slate-900">Datos de {{ $datosContacto['nombre'] }}</h3>
                    <button type="button" wire:click="cerrarDatos" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="reclutamiento-tabs shrink-0 border-b border-slate-200 bg-slate-50 px-2 pt-2" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $pestanaDatosModal === 'datos' ? 'true' : 'false' }}"
                        wire:click="seleccionarPestanaDatos('datos')"
                        class="reclutamiento-tab text-[11px]{{ $pestanaDatosModal === 'datos' ? ' is-active' : '' }}"
                    >
                        Datos
                    </button>
                    @if ($datosContacto['tiene_empleado'] && auth()->user()->esAdmin())
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $pestanaDatosModal === 'contrato' ? 'true' : 'false' }}"
                            wire:click="seleccionarPestanaDatos('contrato')"
                            class="reclutamiento-tab text-[11px]{{ $pestanaDatosModal === 'contrato' ? ' is-active' : '' }}"
                        >
                            Contrato
                        </button>
                    @endif
                </div>

                @if ($pestanaDatosModal === 'contrato' && auth()->user()->esAdmin())
                    @php($empleadoHistorial = $this->empleadoActual())
                    @php($periodosHistorial = $empleadoHistorial?->periodosContrato ?? collect())
                    @php($contratoAreaModelo = $this->contratoAreaModelo())
                    @php($contratoPlantilla = $this->contratoPlantillaInfo())
                    @php($contratoTipoActual = $this->contratoTipo())

                    <div class="flex min-h-0 flex-1 bg-slate-50">
                        <div class="min-h-0 flex-1 overflow-y-auto p-4">
                            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-sky-700">Historial de contrato</p>
                                    <div class="flex items-center gap-3">
                                        @if ($periodosHistorial->isNotEmpty() && ! $periodosHistorial->first()->cese)
                                            <button type="button" wire:click="mostrarRenovacion" class="text-[10px] font-semibold text-sky-700 hover:underline">
                                                + Registrar renovación
                                            </button>
                                        @endif
                                        <button type="button" wire:click="alternarPanelGenerar" class="btn-secondary inline-flex w-auto items-center gap-1 px-2.5 py-1 text-[10px]" aria-expanded="{{ $panelGenerarAbierto ? 'true' : 'false' }}">
                                            Generar contrato
                                            <svg aria-hidden="true" class="h-3 w-3 transition {{ $panelGenerarAbierto ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                @if ($mostrarFormularioRenovacion)
                                <div class="mb-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Fecha inicio</label>
                                            <input type="date" wire:model="nuevoPeriodoFechaInicio" class="form-input py-1.5 text-[11px]">
                                            @error('nuevoPeriodoFechaInicio') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Duración (meses)</label>
                                            <input type="number" min="1" max="60" step="1" wire:model="nuevoPeriodoDuracion" class="form-input py-1.5 text-[11px]" placeholder="Ej. 6">
                                            @error('nuevoPeriodoDuracion') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <button type="button" wire:click="registrarPeriodoRenovacion" wire:loading.attr="disabled" class="btn-secondary flex-1 py-1 text-[10px]">
                                            Guardar renovación
                                        </button>
                                        <button type="button" wire:click="cancelarRenovacion" class="shrink-0 text-[10px] text-slate-400 hover:text-rose-500">
                                            Cancelar
                                        </button>
                                    </div>
                                </div>
                            @endif

                                @if ($periodosHistorial->isEmpty())
                                    <p class="py-3 text-[11px] italic text-slate-400">Todavía no hay períodos registrados. Abre «Generar contrato» y usa «Registrar prueba (3 meses)» para empezar.</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-[11px]">
                                            <thead>
                                                <tr class="border-b border-slate-200 text-[9px] font-semibold uppercase tracking-wide text-slate-400">
                                                    <th class="py-1.5 pr-2">Tipo</th>
                                                    <th class="py-1.5 pr-2">Inicio</th>
                                                    <th class="py-1.5 pr-2">Fin</th>
                                                    <th class="py-1.5 pr-2 text-center">Meses</th>
                                                    <th class="py-1.5 pr-2">Estado</th>
                                                    <th class="py-1.5 pr-2">Cese</th>
                                                    <th class="py-1.5 text-center" title="Contrato firmado">Contrato</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($periodosHistorial as $periodo)
                                                    @php($estadoPeriodo = $periodo->estadoEn($periodosHistorial))
                                                    @php($estadoInfo = $this->estadoPeriodoLabel($estadoPeriodo))
                                                    <tr class="border-b border-slate-100 align-middle" wire:key="periodo-{{ $periodo->id }}">
                                                        <td class="py-1.5 pr-2 font-semibold leading-tight text-slate-700">{{ $periodo->tipo === 'prueba' ? '1er contrato (Prueba)' : 'Renovación' }}</td>
                                                        <td class="whitespace-nowrap py-1.5 pr-2 text-slate-600">{{ $periodo->fecha_inicio->format('d/m/Y') }}</td>
                                                        <td class="whitespace-nowrap py-1.5 pr-2 text-slate-600">{{ $periodo->fecha_fin->format('d/m/Y') }}</td>
                                                        <td class="py-1.5 pr-2 text-center text-slate-600">{{ $periodo->duracion_meses ?: (int) floor($periodo->fecha_inicio->diffInMonths($periodo->fecha_fin->copy()->addDay())) }}</td>
                                                        <td class="py-1.5 pr-2">
                                                            <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wide {{ $estadoInfo['clase'] }}">{{ $estadoInfo['label'] }}</span>
                                                        </td>
                                                        <td class="py-1.5 pr-2">
                                                            @if ($periodo->cese)
                                                                <span class="text-rose-600">{{ ['renuncia' => 'Renuncia', 'despido' => 'Despido', 'vencimiento' => 'Vencimiento'][$periodo->cese_tipo] ?? $periodo->cese_tipo }} · {{ $periodo->cese_fecha?->format('d/m/Y') }}</span>
                                                            @elseif ($periodoCeseActivoId === $periodo->id)
                                                                <span class="text-slate-400">Editando…</span>
                                                            @elseif (in_array($estadoPeriodo, ['activo', 'por_vencer'], true))
                                                                <button type="button" wire:click="abrirCeseDePeriodo({{ $periodo->id }})" class="font-semibold text-rose-600 hover:underline">Agregar cese</button>
                                                            @else
                                                                <span class="text-slate-300">—</span>
                                                            @endif
                                                        </td>
                                                        <td class="py-1.5 text-center">
                                                            @php($docFirmado = $periodo->documentoContrato)
                                                            <button
                                                                type="button"
                                                                wire:click="abrirContratoFirmado({{ $periodo->id }})"
                                                                class="inline-flex h-6 w-6 items-center justify-center rounded transition hover:bg-slate-100 {{ $docFirmado ? 'text-emerald-600' : 'text-slate-400' }}"
                                                                title="{{ $docFirmado ? 'Ver contrato firmado' : 'Subir contrato firmado' }}"
                                                                aria-label="{{ $docFirmado ? 'Ver contrato firmado' : 'Subir contrato firmado' }}"
                                                            >
                                                                <svg aria-hidden="true" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                                                </svg>
                                                            </button>
                                                        </td>
                                                    </tr>

                                                    @if ($periodoCeseActivoId === $periodo->id)
                                                        <tr wire:key="cese-{{ $periodo->id }}">
                                                            <td colspan="7" class="bg-slate-50 px-2 pb-3">
                                    @if ($periodoCeseActivoId === $periodo->id)
                                        <div class="mt-2 border-t border-slate-200 pt-2">
                                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                <div class="sm:col-span-2">
                                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Fecha de cese</label>
                                                    <input type="date" wire:model="contratoCeseFecha" class="form-input py-1.5 text-[11px]">
                                                    @error('contratoCeseFecha') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                                </div>

                                                <x-forms.choice
                                                    label="Tipo de cese"
                                                    name="contratoCeseTipo"
                                                    :options="['renuncia' => 'Renuncia voluntaria', 'despido' => 'Despido', 'vencimiento' => 'Vencimiento de contrato']"
                                                    :live="true"
                                                    span
                                                />
                                            </div>

                                            @if ($contratoCeseTipo)
                                                @php($documentosCese = $this->documentosCeseDe($periodo))

                                                <div class="mt-3 space-y-2">
                                                    @foreach ($this->documentosCeseDisponibles() as $tipoDoc => $infoDoc)
                                                        <div class="rounded-lg border border-slate-200 p-2.5">
                                                            <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ $infoDoc['label'] }}</p>

                                                            @if ($infoDoc['modo'] === 'generar')
                                                                @php($plantillaDoc = $this->ceseePlantillaInfo($tipoDoc))
                                                                <button
                                                                    type="button"
                                                                    wire:click="generarDocumentoCese('{{ $tipoDoc }}')"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="generarDocumentoCese('{{ $tipoDoc }}')"
                                                                    class="btn-secondary w-full py-1 text-[10px]"
                                                                    @disabled(! ($plantillaDoc['existe'] ?? false))
                                                                >
                                                                    {{ $plantillaDoc && $plantillaDoc['existe'] ? 'Descargar' : 'Plantilla pendiente de subir' }}
                                                                </button>
                                                            @else
                                                                @if ($infoDoc['modo'] === 'generar_y_firmar')
                                                                    @php($plantillaDoc = $this->ceseePlantillaInfo($tipoDoc))
                                                                    <button
                                                                        type="button"
                                                                        wire:click="generarDocumentoCese('{{ $tipoDoc }}')"
                                                                        wire:loading.attr="disabled"
                                                                        wire:target="generarDocumentoCese('{{ $tipoDoc }}')"
                                                                        class="btn-secondary mb-1.5 w-full py-1 text-[10px]"
                                                                        @disabled(! ($plantillaDoc['existe'] ?? false))
                                                                    >
                                                                        {{ $plantillaDoc && $plantillaDoc['existe'] ? 'Generar borrador' : 'Plantilla pendiente de subir' }}
                                                                    </button>
                                                                @endif

                                                                @php($documentoSubido = $documentosCese[$tipoDoc] ?? null)
                                                                @php($pendienteCese = $documentosCeseNuevos[$tipoDoc] ?? null)

                                                                @if ($pendienteCese)
                                                                    <p class="truncate text-[10px] text-slate-500" title="{{ $pendienteCese->getClientOriginalName() }}">{{ $pendienteCese->getClientOriginalName() }}</p>
                                                                    <div class="mt-1.5 flex items-center gap-2">
                                                                        <button type="button" wire:click="guardarDocumentoCese('{{ $tipoDoc }}')" wire:loading.attr="disabled" class="btn-secondary flex-1 py-1 text-[10px]">
                                                                            Guardar
                                                                        </button>
                                                                        <button type="button" wire:click="cancelarDocumentoCese('{{ $tipoDoc }}')" class="shrink-0 text-[10px] text-slate-400 hover:text-rose-500">
                                                                            Cancelar
                                                                        </button>
                                                                    </div>
                                                                    @error('documentosCeseNuevos.'.$tipoDoc) <p class="mt-1 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                                                @elseif ($documentoSubido)
                                                                    <div class="flex items-center justify-between gap-2">
                                                                        <a href="{{ route('registro-ingresos.documento-cese', $documentoSubido->id) }}" target="_blank" class="truncate text-[10px] text-sky-700 hover:underline" title="{{ $documentoSubido->nombre_original }}">
                                                                            {{ $documentoSubido->nombre_original }}
                                                                        </a>
                                                                        <div class="flex shrink-0 items-center gap-2">
                                                                            <label class="cursor-pointer text-[10px] text-sky-700 hover:underline">
                                                                                Reemplazar
                                                                                <input type="file" wire:model="documentosCeseNuevos.{{ $tipoDoc }}" class="hidden" accept="application/pdf,.doc,.docx">
                                                                            </label>
                                                                            <button
                                                                                type="button"
                                                                                wire:click="eliminarDocumentoCese({{ $documentoSubido->id }})"
                                                                                wire:confirm="¿Eliminar este documento?"
                                                                                class="text-rose-400 transition hover:text-rose-600"
                                                                                aria-label="Eliminar documento"
                                                                                title="Eliminar"
                                                                            >
                                                                                <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4h8v2m1 0-1 14H6L5 6" />
                                                                                </svg>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                @else
                                                                    <label class="btn-secondary block w-full cursor-pointer py-1 text-center text-[10px]">
                                                                        Subir archivo
                                                                        <input type="file" wire:model="documentosCeseNuevos.{{ $tipoDoc }}" class="hidden" accept="application/pdf,.doc,.docx">
                                                                    </label>
                                                                    @error('documentosCeseNuevos.'.$tipoDoc) <p class="mt-1 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                                                @endif

                                                                <div wire:loading wire:target="documentosCeseNuevos.{{ $tipoDoc }}" class="mt-1 text-[10px] text-slate-400">
                                                                    Subiendo...
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="mt-2 flex items-center gap-2">
                                                <button type="button" wire:click="guardarCeseDePeriodo" wire:loading.attr="disabled" class="btn-secondary flex-1 py-1 text-[10px]">
                                                    Guardar cese
                                                </button>
                                                <button type="button" wire:click="cerrarCeseDePeriodo" class="shrink-0 text-[10px] text-slate-400 hover:text-rose-500">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                                            </td>
                                                        </tr>
                                                    @endif
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($panelGenerarAbierto)
                            <aside class="w-80 shrink-0 overflow-y-auto border-l border-slate-200 bg-white p-4">
                                <div class="mb-3 flex items-center justify-between">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-sky-700">Generar contrato</p>
                                    <button type="button" wire:click="alternarPanelGenerar" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar panel">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                            @php($contratoAreaModelo = $this->contratoAreaModelo())
                            @php($contratoPlantilla = $this->contratoPlantillaInfo())

                            <div class="grid grid-cols-1 gap-3">
                                <div class="sm:col-span-2">
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Área</label>
                                    <select wire:model.live="contratoArea" class="form-input py-1.5 text-[11px] uppercase">
                                        <option value="">Selecciona un área</option>
                                        @foreach (\App\Models\Area::orderBy('nombre')->pluck('nombre') as $nombreArea)
                                            <option value="{{ $nombreArea }}">{{ $nombreArea }}</option>
                                        @endforeach
                                    </select>
                                    @error('contratoArea') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>

                                <div class="{{ $this->contratoUsaCampana() || $contratoAreaModelo?->usa_cargo ? '' : 'sm:col-span-2' }}">
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Puesto</label>
                                    <select wire:model.live="contratoPuesto" class="form-input py-1.5 text-[11px] uppercase" @disabled(! $contratoAreaModelo)>
                                        <option value="">Selecciona un puesto</option>
                                        @foreach ($this->contratoPuestosDisponibles() as $nombrePuesto)
                                            <option value="{{ $nombrePuesto }}">{{ $nombrePuesto }}</option>
                                        @endforeach
                                    </select>
                                    @error('contratoPuesto') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>

                                @if ($this->contratoUsaCampana())
                                    <div>
                                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Campaña</label>
                                        <select wire:model.live="contratoCampana" class="form-input py-1.5 text-[11px] uppercase">
                                            <option value="">Selecciona una campaña</option>
                                            @foreach ($this->contratoCampanasDisponibles() as $nombreCampana)
                                                <option value="{{ $nombreCampana }}">{{ $nombreCampana }}</option>
                                            @endforeach
                                        </select>
                                        @error('contratoCampana') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                @if ($contratoAreaModelo?->usa_cargo)
                                    <div>
                                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Cargo</label>
                                        <select wire:model.live="contratoCargo" class="form-input py-1.5 text-[11px] uppercase">
                                            <option value="">Selecciona un cargo</option>
                                            @foreach ($this->contratoCargosDisponibles() as $nombreCargo)
                                                <option value="{{ $nombreCargo }}">{{ $nombreCargo }}</option>
                                            @endforeach
                                        </select>
                                        @error('contratoCargo') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                @php($contratoTipoActual = $this->contratoTipo())

                                @if ($contratoPlantilla)
                                    <div class="sm:col-span-2 flex items-center justify-between gap-2 rounded-lg {{ $contratoPlantilla['existe'] ? 'bg-sky-50 text-sky-700' : 'bg-amber-50 text-amber-700' }} px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wide">
                                        <span>
                                            Tipo: {{ $contratoPlantilla['label'] }}
                                            @unless ($contratoPlantilla['existe'])
                                                — plantilla pendiente de subir
                                            @endunless
                                        </span>
                                        @php($periodosPanel = $this->empleadoActual()?->periodosContrato ?? collect())
                                        @if ($periodosPanel->isEmpty() || $periodosPanel->first()->cese)
                                            <button type="button" wire:click="registrarPeriodoPrueba" wire:loading.attr="disabled" class="btn-secondary shrink-0 py-1 text-[10px]">
                                                {{ $periodosPanel->isEmpty() ? 'Registrar prueba (3 meses)' : 'Registrar reingreso (prueba 3 meses)' }}
                                            </button>
                                        @endif
                                    </div>
                                @endif

                                @if (in_array($contratoTipoActual, ['ejecutivo', 'administrativo'], true))
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2">
                                        <label class="flex cursor-pointer items-center gap-2 text-[11px] font-semibold text-slate-700" title="Lleva período de prueba (3 meses)">
                                            <input type="checkbox" wire:model.live="contratoLlevaPrueba" class="h-3.5 w-3.5 rounded border-slate-300">
                                            P. Prueba
                                        </label>
                                        @if ($contratoLlevaPrueba && $contratoFechaInicio !== '')
                                            <p class="mt-1 text-[10px] text-sky-700">
                                                {{ \Illuminate\Support\Carbon::parse($contratoFechaInicio)->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($contratoFechaInicio)->addMonthsNoOverflow(3)->subDay()->format('d/m/Y') }}
                                            </p>
                                        @endif
                                    </div>
                                @endif

                                @if ($contratoTipoActual === 'practicas')
                                    <div>
                                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Universidad</label>
                                        <input type="text" wire:model="contratoUniversidad" class="form-input py-1.5 text-[11px] uppercase">
                                        @error('contratoUniversidad') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Área de prácticas</label>
                                        <input type="text" wire:model="contratoAreaPracticas" class="form-input py-1.5 text-[11px] uppercase" placeholder="Ej. Tecnología de la información">
                                        @error('contratoAreaPracticas') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                @if ($contratoTipoActual !== 'practicas')
                                    <div class="{{ $contratoTipoActual === 'administrativo' ? '' : 'sm:col-span-2' }}">
                                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Remuneración mensual (S/)</label>
                                        <input type="number" step="0.01" min="0" wire:model="contratoRemuneracion" class="form-input py-1.5 text-[11px]" placeholder="Ej. 2000">
                                        @error('contratoRemuneracion') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                @if ($contratoTipoActual === 'administrativo')
                                    <div>
                                        <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Movilidad (S/) — opcional</label>
                                        <input type="number" step="0.01" min="0" wire:model="contratoRemuneracionMovilidad" class="form-input py-1.5 text-[11px]" placeholder="Ej. 200">
                                        @error('contratoRemuneracionMovilidad') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                <div>
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Fecha inicio</label>
                                    <input type="date" wire:model.live="contratoFechaInicio" class="form-input py-1.5 text-[11px]">
                                    @error('contratoFechaInicio') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Duración (meses)</label>
                                    <input type="number" min="1" max="60" step="1" wire:model.live="contratoDuracionMeses" class="form-input py-1.5 text-[11px]" placeholder="Ej. 6">
                                    @if (ctype_digit($contratoDuracionMeses) && (int) $contratoDuracionMeses > 0 && $contratoFechaInicio !== '')
                                        <p class="mt-0.5 text-[10px] text-sky-700">Termina el {{ \Illuminate\Support\Carbon::parse($contratoFechaInicio)->addMonthsNoOverflow((int) $contratoDuracionMeses)->subDay()->format('d/m/Y') }}</p>
                                    @endif
                                    @error('contratoDuracionMeses') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Fecha de firma</label>
                                    <input type="date" wire:model="contratoFechaFirma" class="form-input py-1.5 text-[11px]">
                                    @error('contratoFechaFirma') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="descargarContrato"
                                wire:loading.attr="disabled"
                                wire:target="descargarContrato"
                                class="btn-secondary mt-4 w-full py-1.5 text-[11px]"
                            >
                                <span wire:loading.remove wire:target="descargarContrato">Descargar contrato (.docx)</span>
                                <span wire:loading wire:target="descargarContrato">Generando...</span>
                            </button>
                            </aside>
                        @endif
                    </div>
                @else
                <div class="flex min-h-0 flex-1">
                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 space-y-3">
                        @unless (isset($datosContacto['secciones']['Datos personales']))
                            @include('partials.registro-ingresos.correo-corporativo')
                        @endunless
                        @unless (isset($datosContacto['secciones']['Sistema pensionario']))
                            @include('partials.registro-ingresos.informacion-bancaria')
                        @endunless

                        @foreach ($datosContacto['secciones'] as $tituloSeccion => $campos)
                            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                                <p class="mb-2 border-b border-slate-100 pb-1.5 text-[11px] font-bold uppercase tracking-wide text-sky-700">
                                    {{ $tituloSeccion }}
                                </p>
                                <dl class="space-y-1">
                                    @foreach ($campos as $etiqueta => $valor)
                                        <div class="flex items-baseline gap-2">
                                            <dt class="w-32 shrink-0 text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ $etiqueta }}</dt>
                                            <dd class="text-[11px] text-slate-800">{{ $valor }}</dd>
                                        </div>
                                    @endforeach

                                    @if ($tituloSeccion === 'Sistema pensionario')
                                        <div class="flex items-end gap-2 pt-1">
                                            <dt class="w-32 shrink-0 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Código CUSPP</dt>
                                            <dd class="flex flex-1 items-end gap-2">
                                                <form wire:submit.prevent="guardarCuspp" class="flex flex-1 items-end gap-2">
                                                    <input type="text" wire:model="cusppEdit" class="form-input flex-1 py-1 text-[11px]" placeholder="Sin registrar">
                                                    <button type="submit" wire:loading.attr="disabled" wire:target="guardarCuspp" class="btn-secondary w-auto shrink-0 px-2 py-1 text-[10px]">
                                                        <span wire:loading.remove wire:target="guardarCuspp">Guardar</span>
                                                        <span wire:loading wire:target="guardarCuspp">...</span>
                                                    </button>
                                                </form>
                                            </dd>
                                        </div>
                                        @error('cusppEdit') <p class="form-error text-[10px]">{{ $message }}</p> @enderror
                                    @endif
                                </dl>
                            </div>

                            @if ($tituloSeccion === 'Datos personales')
                                @include('partials.registro-ingresos.correo-corporativo')
                            @endif
                            @if ($tituloSeccion === 'Sistema pensionario')
                                @include('partials.registro-ingresos.informacion-bancaria')
                            @endif
                        @endforeach

                        @unless ($datosContacto['tiene_empleado'])
                            <p class="text-[11px] italic text-slate-400">Todavía no completó su ficha de datos; solo se muestra lo registrado en reclutamiento.</p>
                        @endunless
                    </div>

                    <div class="w-72 shrink-0 overflow-y-auto border-l border-slate-200 bg-white p-4">
                        <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-sky-700">Documentos</p>
                        <div class="space-y-3">
                            @foreach ($this->tiposDocumento as $tipo => $etiquetaDocumento)
                                @php($documento = $documentosCandidato[$tipo])
                                @php($pendiente = $documentosNuevos[$tipo] ?? null)
                                <div class="rounded-lg border border-slate-200 p-2">
                                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ $etiquetaDocumento }}</p>

                                    @if ($pendiente)
                                        @php($esImagenPendiente = str($pendiente->getMimeType() ?? '')->startsWith('image/'))
                                        <button
                                            type="button"
                                            wire:click="abrirVistaPrevia('{{ $pendiente->temporaryUrl() }}', '{{ $etiquetaDocumento }}', {{ $esImagenPendiente ? 'true' : 'false' }})"
                                            class="block w-full overflow-hidden rounded-md border border-slate-200 bg-slate-50"
                                        >
                                            @if ($esImagenPendiente)
                                                <img src="{{ $pendiente->temporaryUrl() }}" class="h-24 w-full object-cover" alt="Vista previa">
                                            @else
                                                <iframe src="{{ $pendiente->temporaryUrl() }}" class="h-24 w-full" title="Vista previa PDF"></iframe>
                                            @endif
                                        </button>
                                        <p class="mt-1 truncate text-[10px] text-slate-500" title="{{ $pendiente->getClientOriginalName() }}">{{ $pendiente->getClientOriginalName() }}</p>

                                        <div class="mt-1.5 flex items-center gap-2">
                                            <button type="button" wire:click="guardarDocumento('{{ $tipo }}')" wire:loading.attr="disabled" class="btn-secondary flex-1 py-1 text-[10px]">
                                                Guardar
                                            </button>
                                            <button type="button" wire:click="cancelarDocumento('{{ $tipo }}')" class="shrink-0 text-[10px] text-slate-400 hover:text-rose-500">
                                                Cancelar
                                            </button>
                                        </div>

                                        @error('documentosNuevos.'.$tipo)
                                            <p class="mt-1 text-[10px] text-rose-500">{{ $message }}</p>
                                        @enderror
                                    @elseif ($documento)
                                        @php($esImagenGuardada = str($documento->mime_type ?? '')->startsWith('image/'))
                                        <button
                                            type="button"
                                            wire:click="abrirVistaPrevia('{{ route('registro-ingresos.documento', $documento->id) }}', '{{ $etiquetaDocumento }}', {{ $esImagenGuardada ? 'true' : 'false' }})"
                                            class="block w-full overflow-hidden rounded-md border border-slate-200 bg-slate-50"
                                        >
                                            @if ($esImagenGuardada)
                                                <img src="{{ route('registro-ingresos.documento', $documento->id) }}" class="h-24 w-full object-cover" alt="Vista previa">
                                            @else
                                                <div class="flex h-24 w-full flex-col items-center justify-center gap-1 text-slate-400">
                                                    <svg aria-hidden="true" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1M5 3h9l5 5v13a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1z" />
                                                    </svg>
                                                    <span class="text-[9px] uppercase">Ver PDF</span>
                                                </div>
                                            @endif
                                        </button>

                                        <div class="mt-1.5 flex items-center justify-between gap-2">
                                            <label class="cursor-pointer text-[10px] text-sky-700 hover:underline">
                                                Reemplazar
                                                <input type="file" wire:model="documentosNuevos.{{ $tipo }}" class="hidden" accept="application/pdf,image/*">
                                            </label>
                                            <button
                                                type="button"
                                                wire:click="eliminarDocumento({{ $documento->id }})"
                                                wire:confirm="¿Eliminar este documento?"
                                                class="shrink-0 text-rose-400 transition hover:text-rose-600"
                                                aria-label="Eliminar documento"
                                                title="Eliminar"
                                            >
                                                <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4h8v2m1 0-1 14H6L5 6" />
                                                </svg>
                                            </button>
                                        </div>
                                    @else
                                        <label class="btn-secondary block w-full cursor-pointer py-1 text-center text-[10px]">
                                            Subir archivo
                                            <input type="file" wire:model="documentosNuevos.{{ $tipo }}" class="hidden" accept="application/pdf,image/*">
                                        </label>
                                        @error('documentosNuevos.'.$tipo)
                                            <p class="mt-1 text-[10px] text-rose-500">{{ $message }}</p>
                                        @enderror
                                    @endif

                                    <div wire:loading wire:target="documentosNuevos.{{ $tipo }}" class="mt-1 text-[10px] text-slate-400">
                                        Subiendo...
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    @endif

    @if ($vistaPreviaUrl)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/80 p-4" wire:click.self="cerrarVistaPrevia">
            <div class="flex h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $vistaPreviaTitulo }}</h3>
                    <button type="button" wire:click="cerrarVistaPrevia" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar vista previa">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 bg-slate-100">
                    @if ($vistaPreviaEsImagen)
                        <img src="{{ $vistaPreviaUrl }}" class="h-full w-full object-contain" alt="{{ $vistaPreviaTitulo }}">
                    @else
                        <iframe src="{{ $vistaPreviaUrl }}" class="h-full w-full" title="{{ $vistaPreviaTitulo }}"></iframe>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @if ($this->periodoContratoFirmado())
        @php($periodoFirmado = $this->periodoContratoFirmado())
        @php($docFirmadoModal = $periodoFirmado->documentoContrato)
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-4">
            <div class="flex h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white text-xs shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Contrato firmado</h3>
                        <p class="text-[11px] text-slate-500">
                            {{ $periodoFirmado->tipo === 'prueba' ? '1er contrato (Prueba)' : 'Renovación' }} ·
                            {{ $periodoFirmado->fecha_inicio->format('d/m/Y') }} – {{ $periodoFirmado->fecha_fin->format('d/m/Y') }}
                        </p>
                    </div>
                    <button type="button" wire:click="cerrarContratoFirmado" class="text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2">
                    @if ($contratoFirmadoNuevo)
                        <span class="max-w-xs truncate text-[11px] text-slate-600" title="{{ $contratoFirmadoNuevo->getClientOriginalName() }}">{{ $contratoFirmadoNuevo->getClientOriginalName() }}</span>
                        <button type="button" wire:click="guardarContratoFirmado" wire:loading.attr="disabled" wire:target="guardarContratoFirmado" class="btn-primary w-auto px-3 py-1 text-[11px]">
                            <span wire:loading.remove wire:target="guardarContratoFirmado">{{ $docFirmadoModal ? 'Guardar y reemplazar' : 'Guardar' }}</span>
                            <span wire:loading wire:target="guardarContratoFirmado">Guardando...</span>
                        </button>
                        <button type="button" wire:click="cancelarContratoFirmadoNuevo" class="text-[11px] text-slate-400 hover:text-rose-500">Cancelar</button>
                    @else
                        <label class="btn-secondary w-auto cursor-pointer px-3 py-1 text-[11px]">
                            {{ $docFirmadoModal ? 'Reemplazar archivo' : 'Subir contrato firmado' }}
                            <input type="file" wire:model="contratoFirmadoNuevo" class="hidden" accept="application/pdf,image/jpeg,image/png,.doc,.docx">
                        </label>
                        @if ($docFirmadoModal)
                            <a href="{{ route('registro-ingresos.documento-contrato', $docFirmadoModal->id) }}" target="_blank" class="text-[11px] font-semibold text-sky-700 hover:underline">Abrir en otra pestaña</a>
                            <a href="{{ route('registro-ingresos.documento-contrato', $docFirmadoModal->id) }}" download="{{ $docFirmadoModal->nombre_original }}" class="text-[11px] font-semibold text-sky-700 hover:underline">Descargar</a>
                            <button type="button" wire:click="eliminarContratoFirmado" wire:confirm="¿Eliminar el contrato firmado de este período?" class="ml-auto text-[11px] font-semibold text-rose-600 hover:underline">Eliminar</button>
                        @endif
                    @endif
                    <span wire:loading wire:target="contratoFirmadoNuevo" class="text-[11px] text-slate-400">Subiendo...</span>
                    @error('contratoFirmadoNuevo') <span class="w-full text-[11px] text-rose-600">{{ $message }}</span> @enderror
                </div>

                <div class="min-h-0 flex-1 bg-slate-100">
                    @if ($docFirmadoModal)
                        @php($urlDocumento = route('registro-ingresos.documento-contrato', $docFirmadoModal->id).'?v='.$docFirmadoModal->updated_at->timestamp)
                        @if ($docFirmadoModal->tipoVista() === 'pdf')
                            <iframe src="{{ $urlDocumento }}" class="h-full w-full border-0" title="Contrato firmado"></iframe>
                        @elseif ($docFirmadoModal->tipoVista() === 'imagen')
                            <div class="flex h-full items-center justify-center overflow-auto p-4">
                                <img src="{{ $urlDocumento }}" alt="Contrato firmado" class="max-h-full max-w-full object-contain">
                            </div>
                        @else
                            <div class="flex h-full flex-col items-center justify-center gap-2 text-slate-500">
                                <p class="text-sm font-semibold text-slate-700">{{ $docFirmadoModal->nombre_original }}</p>
                                <p class="text-[11px]">Los archivos Word no se pueden previsualizar; usa «Descargar» o «Abrir en otra pestaña».</p>
                            </div>
                        @endif
                    @else
                        <div class="flex h-full flex-col items-center justify-center gap-1 text-slate-400">
                            <svg aria-hidden="true" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke-width="1.2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                            </svg>
                            <p class="text-[12px]">Todavía no se subió el contrato firmado de este período.</p>
                            <p class="text-[10px]">PDF, imagen (JPG/PNG) o Word · máximo 10 MB</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
