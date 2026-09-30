@props(['empleado'])

@php
    $chk = fn (?bool $activo) => $activo ? 'X' : '';
    $chkVal = fn (?string $valor, string $opcion) => $valor === $opcion ? 'X' : '';
    $fecha = fn ($valor) => $valor && (($valor instanceof \DateTimeInterface) || strtotime((string) $valor) !== false)
        ? \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y')
        : ($valor ?: '');
    $sexoLabel = fn ($valor) => match ($valor) {
        'F' => 'Femenino',
        'M' => 'Masculino',
        default => '-',
    };
@endphp

<div class="ficha-ingreso">
    <div class="ficha-brand">B2 SOLUTIONS</div>
    <div class="ficha-title">Ficha de datos personales del trabajador</div>

    {{-- Cabecera --}}
    <div class="ficha-grid grid-cols-2">
        <div class="ficha-cell">
            <span class="ficha-cell-label">Fecha de capa</span>
            <span class="ficha-cell-value">{{ $fecha($empleado->fecha_capa) ?: '-' }}</span>
        </div>
        <div class="ficha-cell space-y-1">
            <div>
                <span class="ficha-cell-label">Puesto</span>
                <span class="ficha-cell-value">{{ $empleado->puesto ?: '-' }}</span>
            </div>
            <div>
                <span class="ficha-cell-label">Campaña</span>
                <span class="ficha-cell-value">{{ $empleado->campana ?: '-' }}</span>
            </div>
        </div>
    </div>

    {{-- I. DATOS PERSONALES --}}
    <p class="ficha-section-title">I. Datos personales</p>
    <div class="ficha-grid grid-cols-4">
        <div class="ficha-cell">
            <span class="ficha-cell-label">DNI / CE / PTP</span>
            <span class="ficha-cell-value">{{ $empleado->documento_identidad }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Apellido paterno</span>
            <span class="ficha-cell-value">{{ $empleado->apellido_paterno }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Apellido materno</span>
            <span class="ficha-cell-value">{{ $empleado->apellido_materno }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Nombres</span>
            <span class="ficha-cell-value">{{ $empleado->nombres }}</span>
        </div>
    </div>
    <div class="ficha-grid grid-cols-6">
        <div class="ficha-cell">
            <span class="ficha-cell-label">Fecha nac.</span>
            <span class="ficha-cell-value">{{ $fecha($empleado->fecha_nacimiento) ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Lugar de nacimiento</span>
            <span class="ficha-cell-value">{{ $empleado->lugar_nacimiento ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Edad</span>
            <span class="ficha-cell-value">{{ $empleado->edad ?? '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Nº hijos</span>
            <span class="ficha-cell-value">{{ $empleado->numero_hijos ?? '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Estado civil</span>
            <span class="ficha-cell-value capitalize">{{ $empleado->estado_civil ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Sexo</span>
            <span class="ficha-cell-value">
                F ( {{ $chkVal($empleado->sexo, 'F') }} )&nbsp; M ( {{ $chkVal($empleado->sexo, 'M') }} )
            </span>
        </div>
    </div>
    <div class="ficha-grid grid-cols-2">
        <div class="ficha-cell">
            <span class="ficha-cell-label">Dirección actual (Calle, Mz, Lt, Nro, Urb / AA.HH)</span>
            <span class="ficha-cell-value">{{ $empleado->direccion ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Tipo vivienda (marcar con X)</span>
            <span class="ficha-cell-value">
                [{{ $chkVal($empleado->vivienda_tipo, 'departamento') }}] Dpto
                &nbsp;[{{ $chkVal($empleado->vivienda_tipo, 'habitacion') }}] Habitac
                &nbsp;[{{ $chkVal($empleado->vivienda_tipo, 'casa') }}] Casa
                &nbsp;&nbsp;[{{ $chkVal($empleado->vivienda_tenencia, 'propia') }}] Propia
                &nbsp;[{{ $chkVal($empleado->vivienda_tenencia, 'alquilada') }}] Alquilada
            </span>
        </div>
    </div>
    <div class="ficha-grid grid-cols-4">
        <div class="ficha-cell">
            <span class="ficha-cell-label">Distrito</span>
            <span class="ficha-cell-value">{{ $empleado->distrito ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Provincia</span>
            <span class="ficha-cell-value">{{ $empleado->provincia ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Departamento</span>
            <span class="ficha-cell-value">{{ $empleado->departamento_residencia ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Celular de llamadas</span>
            <span class="ficha-cell-value">{{ $empleado->celular_llamadas }}</span>
        </div>
    </div>
    <div class="ficha-grid grid-cols-2">
        <div class="ficha-cell">
            <span class="ficha-cell-label">Correo electrónico</span>
            <span class="ficha-cell-value">{{ $empleado->email }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Celular de WhatsApp</span>
            <span class="ficha-cell-value">{{ $empleado->celular_whatsapp ?: '-' }}</span>
        </div>
    </div>
    <div class="ficha-grid grid-cols-3">
        <div class="ficha-cell">
            <span class="ficha-cell-label">Contacto en caso de emergencia</span>
            <span class="ficha-cell-value">{{ $empleado->contacto_emergencia_nombre }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Parentesco</span>
            <span class="ficha-cell-value">{{ $empleado->contacto_emergencia_parentesco ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Número de contacto / celular</span>
            <span class="ficha-cell-value">{{ $empleado->contacto_emergencia_telefono }}</span>
        </div>
    </div>

    {{-- II. SISTEMA PENSIONARIO --}}
    <p class="ficha-section-title">II. Sistema pensionario</p>
    <div class="ficha-grid grid-cols-4">
        <div class="ficha-cell">
            <span class="ficha-cell-label">¿Estoy afiliado?</span>
            <span class="ficha-cell-value">
                SI ( {{ $chkVal($empleado->pension_afiliado ? 'si' : 'no', 'si') }} )
                &nbsp; NO ( {{ $chkVal($empleado->pension_afiliado ? 'si' : 'no', 'no') }} )
            </span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">ONP</span>
            <span class="ficha-cell-value">( {{ $chkVal($empleado->pension_sistema, 'onp') }} )</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">AFP</span>
            <span class="ficha-cell-value text-[10px]">
                Profuturo ({{ $chkVal($empleado->pension_afp, 'profuturo') }})
                &nbsp;Integra ({{ $chkVal($empleado->pension_afp, 'integra') }})
                &nbsp;Prima ({{ $chkVal($empleado->pension_afp, 'prima') }})
                &nbsp;Hábitat ({{ $chkVal($empleado->pension_afp, 'habitat') }})
            </span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Código CUSPP (opcional)</span>
            <span class="ficha-cell-value">{{ $empleado->pension_cuspp ?: '-' }}</span>
        </div>
    </div>

    {{-- III. ESTUDIOS REALIZADOS --}}
    <p class="ficha-section-title">III. Estudios realizados</p>
    <table class="ficha-table">
        <thead>
            <tr>
                <th>Nivel</th>
                <th>Centro de estudios</th>
                <th>Carrera</th>
                <th>Desde</th>
                <th>Hasta</th>
                <th>Grado obtenido</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($empleado->estudios as $estudio)
                <tr>
                    <td class="capitalize">{{ $estudio->nivel ?: '-' }}</td>
                    <td>{{ $estudio->centro_estudios ?: '-' }}</td>
                    <td>{{ $estudio->carrera ?: '-' }}</td>
                    <td>{{ $fecha($estudio->desde) ?: '-' }}</td>
                    <td>{{ $fecha($estudio->hasta) ?: '-' }}</td>
                    <td class="capitalize">{{ $estudio->grado_obtenido ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-slate-400">Sin registros</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- IV. INFORMACIÓN LABORAL --}}
    <p class="ficha-section-title">IV. Información laboral (2 últimos empleos)</p>
    @forelse ($empleado->empleosAnteriores as $empleo)
        <table class="ficha-table mb-2">
            <thead>
                <tr>
                    <th>Empresa</th>
                    <th>Cargo</th>
                    <th>Principal función</th>
                    <th>Sueldo</th>
                    <th>Fec. inicio - térm.</th>
                    <th>Motivo de cese</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $empleo->empresa ?: '-' }}</td>
                    <td>{{ $empleo->cargo ?: '-' }}</td>
                    <td>{{ $empleo->funcion_principal ?: '-' }}</td>
                    <td>{{ $empleo->sueldo ?: '-' }}</td>
                    <td>{{ $fecha($empleo->fecha_inicio) ?: '-' }} - {{ $fecha($empleo->fecha_termino) ?: '-' }}</td>
                    <td>{{ $empleo->motivo_cese ?: '-' }}</td>
                </tr>
                <tr>
                    <td colspan="2"><strong>Jefe inmediato:</strong> {{ $empleo->jefe_nombre ?: '-' }}</td>
                    <td><strong>Cargo:</strong> {{ $empleo->jefe_cargo ?: '-' }}</td>
                    <td colspan="3"><strong>Celular:</strong> {{ $empleo->jefe_celular ?: '-' }}</td>
                </tr>
            </tbody>
        </table>
    @empty
        <p class="ficha-empty">Sin registros</p>
    @endforelse

    {{-- V. INFORMACIÓN FAMILIAR --}}
    <p class="ficha-section-title">V. Información familiar (Padres, hijos, cónyuge, hermanos)</p>
    <table class="ficha-table">
        <thead>
            <tr>
                <th>Apellidos y nombres</th>
                <th>Parentesco</th>
                <th>Fecha de nacimiento</th>
                <th>Edad</th>
                <th>Ocupación</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($empleado->familiares as $familiar)
                <tr>
                    <td>{{ $familiar->nombres_apellidos ?: '-' }}</td>
                    <td>{{ $familiar->parentesco ?: '-' }}</td>
                    <td>{{ $fecha($familiar->fecha_nacimiento) ?: '-' }}</td>
                    <td>{{ $familiar->edad ?? '-' }}</td>
                    <td>{{ $familiar->ocupacion ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-slate-400">Sin registros</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- VI. SALUD --}}
    <p class="ficha-section-title">VI. Salud</p>
    <div class="ficha-grid grid-cols-1">
        <div class="ficha-cell flex items-center justify-between">
            <span>¿Tiene antecedentes de enfermedades cardíacas o oncológicas?</span>
            <span class="ficha-cell-value whitespace-nowrap">
                SI ( {{ $chk($empleado->salud_antecedentes) }} )&nbsp; NO ( {{ $chk(! $empleado->salud_antecedentes) }} )
            </span>
        </div>
        <div class="ficha-cell flex items-center justify-between">
            <span>¿Actualmente sufre de alguna enfermedad?</span>
            <span class="ficha-cell-value whitespace-nowrap">
                SI ( {{ $chk($empleado->salud_enfermedad_actual) }} )&nbsp; NO ( {{ $chk(! $empleado->salud_enfermedad_actual) }} )
            </span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">Si la respuesta es SÍ, por favor detállelo</span>
            <span class="ficha-cell-value">{{ $empleado->salud_enfermedad_detalle ?: '-' }}</span>
        </div>
        <div class="ficha-cell">
            <span class="ficha-cell-label">¿Toma algún medicamento?</span>
            <span class="ficha-cell-value">{{ $empleado->salud_medicamentos ?: '-' }}</span>
        </div>
    </div>

    {{-- VII. DECLARACIÓN --}}
    <p class="ficha-section-title">VII. Declaración</p>
    <div class="space-y-2">
        <div class="ficha-declaracion">
            <span class="ficha-cell-value shrink-0 whitespace-nowrap">
                SI ( {{ $chk($empleado->declaracion_datos_veraces) }} )&nbsp; NO ( {{ $chk(! $empleado->declaracion_datos_veraces) }} )
            </span>
            <p>
                <strong>DECLARO BAJO JURAMENTO</strong> que los datos registrados en la ficha de ingreso con respecto a la dirección actual,
                son verdaderos. En caso de falsedad, declaro haber incurrido en el delito, contra la Fe Pública, falsificación de
                Documentos (Artículo 427° del Código Penal, en concordancia con el Artículo IV inciso 1.7) "Principio de presunción de
                veracidad" del Título preliminar de la Ley de Procedimiento Administrativo General, Ley N° 27444.
            </p>
        </div>
        <div class="ficha-declaracion">
            <span class="ficha-cell-value shrink-0 whitespace-nowrap">
                SI ( {{ $chk($empleado->declaracion_autoriza_verificacion) }} )&nbsp; NO ( {{ $chk(! $empleado->declaracion_autoriza_verificacion) }} )
            </span>
            <p>
                Declaro bajo juramento que los datos proporcionados son exactos, autorizando a la Institución en la que laboró a
                efectuar las verificaciones que juzgue necesarias; asimismo me comprometo a presentar los documentos que me soliciten.
            </p>
        </div>
        <div class="ficha-declaracion">
            <span class="ficha-cell-value shrink-0 whitespace-nowrap">
                SI ( {{ $chk($empleado->declaracion_capacitacion_condiciones) }} )&nbsp; NO ( {{ $chk(! $empleado->declaracion_capacitacion_condiciones) }} )
            </span>
            <p>
                Declaro estar informado/a de que la capacitación es un proceso formativo y evaluativo, por lo que me comprometo a
                participar de manera responsable durante su desarrollo. Asimismo, entiendo y acepto que, si decido no continuar
                voluntariamente con el proceso antes de finalizarlo, no corresponderá el pago por capacitación. Por otro lado, en caso
                la empresa determine el retiro del participante por motivos internos, sí se realizará el abono correspondiente por los
                días de capacitación efectuados.
            </p>
        </div>
    </div>

    {{-- FIRMA --}}
    <div class="ficha-firma">
        <div class="ficha-firma-box">
            @if ($empleado->firma_imagen)
                <img src="{{ $empleado->firma_imagen }}" alt="Firma" class="mx-auto h-20 object-contain">
            @endif
            <p class="ficha-firma-linea">FIRMA</p>
            <p class="text-[11px]">DNI: {{ $empleado->firma_dni ?: '-' }}</p>
        </div>
        <div class="ficha-firma-box">
            <div class="ficha-huella">HUELLA DIGITAL</div>
        </div>
    </div>
</div>
