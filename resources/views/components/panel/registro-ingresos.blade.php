<?php

use App\Models\CandidatoDocumento;
use App\Models\CandidatoReclutamiento;
use App\Models\Empleado;
use App\Models\OnboardingInvitation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpWord\TemplateProcessor;

new #[Layout('layouts.panel')] class extends Component
{
    use WithFileUploads;

    public ?int $empleadoModalId = null;

    public ?int $datosModalId = null;

    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $documentosNuevos = [];

    public array $tiposDocumento = [
        'dni_copia' => 'DNI - Copia',
        'cv_actualizado' => 'CV actualizado',
        'dni' => 'DNI',
        'recibo' => 'Recibo',
        'dni_hijos' => 'DNI hijos',
    ];

    public ?string $vistaPreviaUrl = null;

    public ?string $vistaPreviaTitulo = null;

    public bool $vistaPreviaEsImagen = false;

    public string $pestanaDatosModal = 'datos';

    public string $contratoPuesto = '';

    public string $contratoRemuneracion = '';

    public string $contratoFechaInicio = '';

    public string $contratoFechaFin = '';

    public string $contratoFechaFirma = '';

    public function mount(): void
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

    public function candidatosAptos()
    {
        return CandidatoReclutamiento::query()
            ->where('apto', true)
            ->orderByDesc('id')
            ->get();
    }

    public function invitacionDe(CandidatoReclutamiento $candidato): ?OnboardingInvitation
    {
        return OnboardingInvitation::where('reclutamiento_candidato_id', $candidato->id)
            ->with('empleado')
            ->latest()
            ->first();
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
    }

    public function cerrarDatos(): void
    {
        $this->datosModalId = null;
        $this->pestanaDatosModal = 'datos';
    }

    public function seleccionarPestanaDatos(string $pestana): void
    {
        $this->pestanaDatosModal = $pestana;

        if ($pestana === 'contrato') {
            $this->precargarFormularioContrato();
        }
    }

    private function precargarFormularioContrato(): void
    {
        $candidato = $this->candidatoParaDatos();
        $empleado = $candidato ? $this->invitacionDe($candidato)?->empleado : null;

        $this->contratoPuesto = $empleado?->contrato_puesto ?? $empleado?->puesto ?? '';
        $this->contratoRemuneracion = $empleado?->contrato_remuneracion !== null ? (string) $empleado->contrato_remuneracion : '';
        $this->contratoFechaInicio = $empleado?->contrato_fecha_inicio?->format('Y-m-d') ?? '';
        $this->contratoFechaFin = $empleado?->contrato_fecha_fin?->format('Y-m-d') ?? '';
        $this->contratoFechaFirma = $empleado?->contrato_fecha_firma?->format('Y-m-d') ?? now()->format('Y-m-d');
    }

    public function updatedContratoPuesto(): void
    {
        if ($this->contratoPuesto !== '') {
            $this->contratoPuesto = mb_strtoupper($this->contratoPuesto);
        }
    }

    public function descargarContrato()
    {
        $candidato = $this->candidatoParaDatos();
        if (! $candidato) {
            return;
        }

        $empleado = $this->invitacionDe($candidato)?->empleado;
        if (! $empleado) {
            return;
        }

        $this->contratoPuesto = mb_strtoupper(trim($this->contratoPuesto));

        $this->validate([
            'contratoPuesto' => 'required|string|max:150',
            'contratoRemuneracion' => 'required|numeric|min:0',
            'contratoFechaInicio' => 'required|date',
            'contratoFechaFin' => 'required|date|after_or_equal:contratoFechaInicio',
            'contratoFechaFirma' => 'required|date',
        ], [], [
            'contratoPuesto' => 'puesto',
            'contratoRemuneracion' => 'remuneración',
            'contratoFechaInicio' => 'fecha de inicio',
            'contratoFechaFin' => 'fecha de fin',
            'contratoFechaFirma' => 'fecha de firma',
        ]);

        $empleado->update([
            'contrato_puesto' => $this->contratoPuesto,
            'contrato_remuneracion' => $this->contratoRemuneracion,
            'contrato_fecha_inicio' => $this->contratoFechaInicio,
            'contrato_fecha_fin' => $this->contratoFechaFin,
            'contrato_fecha_firma' => $this->contratoFechaFirma,
        ]);

        $nombreCompleto = trim("{$empleado->apellido_paterno} {$empleado->apellido_materno} {$empleado->nombres}");

        $procesador = new TemplateProcessor(resource_path('data/contrato-trabajo-template.docx'));
        $procesador->setValue('NOMBRE_TRABAJADOR', mb_strtoupper($nombreCompleto));
        $procesador->setValue('DNI_TRABAJADOR', $empleado->documento_identidad);
        $procesador->setValue('DIRECCION_TRABAJADOR', mb_strtoupper((string) $empleado->direccion));
        $procesador->setValue('PUESTO_TRABAJADOR', mb_strtoupper($this->contratoPuesto));
        $procesador->setValue('REMUNERACION_NUMERO', $this->remuneracionNumero((float) $this->contratoRemuneracion));
        $procesador->setValue('REMUNERACION_LETRAS', $this->remuneracionLetras((float) $this->contratoRemuneracion));
        $procesador->setValue('FECHA_INICIO_CONTRATO', $this->fechaLarga($this->contratoFechaInicio));
        $procesador->setValue('FECHA_FIN_CONTRATO', $this->fechaLarga($this->contratoFechaFin));
        $procesador->setValue('FECHA_FIRMA_CONTRATO', $this->fechaLarga($this->contratoFechaFirma));

        $rutaTemporal = tempnam(sys_get_temp_dir(), 'contrato_').'.docx';
        $procesador->saveAs($rutaTemporal);

        $nombreArchivo = 'contrato-'.str($nombreCompleto)->slug().'.docx';

        return response()->streamDownload(function () use ($rutaTemporal) {
            echo file_get_contents($rutaTemporal);
            unlink($rutaTemporal);
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
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
            $nombreCompleto = trim("{$candidato->nombres} {$candidato->apellidos}") ?: 'Sin nombre';

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
            'Estado civil' => $empleado->estado_civil ? ucfirst($empleado->estado_civil) : null,
            'Sexo' => match ($empleado->sexo) { 'F' => 'Femenino', 'M' => 'Masculino', default => null },
            'Dirección' => $empleado->direccion,
            'Tipo de vivienda' => $empleado->vivienda_tipo ? ucfirst($empleado->vivienda_tipo) : null,
            'Tenencia' => $empleado->vivienda_tenencia ? ucfirst($empleado->vivienda_tenencia) : null,
            'Distrito' => $empleado->distrito,
            'Provincia' => $empleado->provincia,
            'Departamento' => $empleado->departamento_residencia,
            'Celular de llamadas' => $empleado->celular_llamadas,
            'Correo electrónico' => $empleado->email,
            'Celular de WhatsApp' => $empleado->celular_whatsapp,
            'Contacto de emergencia' => $contactoEmergencia,
        ]);

        $secciones['Sistema pensionario'] = $limpio([
            '¿Afiliado?' => $siNo($empleado->pension_afiliado),
            'Sistema' => $empleado->pension_sistema ? strtoupper($empleado->pension_sistema) : null,
            'AFP' => $empleado->pension_afp ? ucfirst($empleado->pension_afp) : null,
            'Código CUSPP' => $empleado->pension_cuspp,
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
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-600">RRHH</p>
        <h2 class="mt-2 text-2xl font-bold text-slate-900">Registro de ingresos</h2>
        <p class="text-sm text-slate-500">Candidatos marcados como aptos en el proceso de capacitación y el estado de su invitación de alta.</p>
    </div>

    <div class="card-panel !p-0 overflow-hidden">
        <table class="data-table w-full">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>DNI / C.E</th>
                    <th>Campaña</th>
                    <th>Agente reclutador</th>
                    <th>Fecha APTO</th>
                    <th>Invitación</th>
                    <th>Formulario</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->candidatosAptos() as $candidato)
                    @php($invitacion = $this->invitacionDe($candidato))
                    <tr>
                        <td class="font-medium text-slate-900">
                            <div class="flex items-center gap-2">
                                <span>{{ trim("{$candidato->nombres} {$candidato->apellidos}") ?: 'Sin nombre' }}</span>
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
                        <td>{{ $candidato->dni_ce ?: '-' }}</td>
                        <td>{{ $candidato->campana ?: '-' }}</td>
                        <td>{{ $candidato->agente_reclutador ?: '-' }}</td>
                        <td>{{ $candidato->fecha_apto?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td>
                            @if ($invitacion)
                                <span class="badge {{ $invitacion->estadoLabel() === 'vigente' ? 'badge-green' : 'badge-amber' }}">
                                    {{ ucfirst($invitacion->estadoLabel()) }}
                                </span>
                            @else
                                <span class="text-xs text-slate-400">Sin generar</span>
                            @endif
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
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-400">
                            Todavía no hay candidatos marcados como aptos en Proceso de capacitación.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->empleadoSeleccionado())
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
            wire:click.self="cerrarFicha"
        >
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
                        <button type="button" wire:click="cerrarFicha" class="btn-secondary w-auto" aria-label="Cerrar">
                            Cerrar
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
                    @if ($datosContacto['tiene_empleado'])
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

                @if ($pestanaDatosModal === 'contrato')
                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4">
                        <div class="mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="mb-3 text-[11px] font-bold uppercase tracking-wide text-sky-700">Generar contrato de trabajo</p>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Puesto</label>
                                    <input type="text" wire:model="contratoPuesto" class="form-input py-1.5 text-[11px] uppercase">
                                    @error('contratoPuesto') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Remuneración mensual (S/)</label>
                                    <input type="number" step="0.01" min="0" wire:model="contratoRemuneracion" class="form-input py-1.5 text-[11px]" placeholder="Ej. 2000">
                                    @error('contratoRemuneracion') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Fecha inicio</label>
                                    <input type="date" wire:model="contratoFechaInicio" class="form-input py-1.5 text-[11px]">
                                    @error('contratoFechaInicio') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">Fecha fin</label>
                                    <input type="date" wire:model="contratoFechaFin" class="form-input py-1.5 text-[11px]">
                                    @error('contratoFechaFin') <p class="mt-0.5 text-[10px] text-rose-500">{{ $message }}</p> @enderror
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
                        </div>
                    </div>
                @else
                <div class="flex min-h-0 flex-1">
                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 space-y-3">
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
                                </dl>
                            </div>
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
</div>
