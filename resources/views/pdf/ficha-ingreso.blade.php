<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 26px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1e293b; }
        .brand { text-align: right; font-size: 9px; font-weight: bold; color: #0c4a6e; margin-bottom: 4px; }
        .title { background: #0c4a6e; color: #fff; text-align: center; font-size: 12px; font-weight: bold;
            text-transform: uppercase; padding: 6px; border-radius: 4px; margin-bottom: 10px; }
        .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #075985;
            margin: 10px 0 3px; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        table.grid td { border: 1px solid #94a3b8; padding: 3px 5px; vertical-align: top; }
        .label { display: block; font-size: 7px; font-weight: bold; text-transform: uppercase; color: #64748b; }
        .value { display: block; font-size: 9px; color: #0f172a; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.data th { border: 1px solid #94a3b8; background: #e0f2fe; padding: 3px 5px; font-size: 7.5px;
            text-transform: uppercase; text-align: left; }
        table.data td { border: 1px solid #94a3b8; padding: 3px 5px; font-size: 8.5px; }
        .declaracion { border: 1px solid #94a3b8; padding: 6px; margin-bottom: 6px; }
        .declaracion .marca { font-weight: bold; white-space: nowrap; }
        .declaracion p { margin: 3px 0 0; font-size: 8px; line-height: 1.35; text-align: justify; }
        .firma-wrap { margin-top: 26px; width: 100%; }
        .firma-wrap td { width: 50%; text-align: center; vertical-align: bottom; padding-top: 10px; }
        .firma-img { height: 55px; }
        .firma-linea { border-top: 1px solid #64748b; padding-top: 3px; font-size: 8px; font-weight: bold;
            text-transform: uppercase; display: inline-block; width: 70%; }
        .huella { border: 1px solid #94a3b8; width: 60px; height: 60px; margin: 0 auto; padding-top: 22px;
            text-align: center; font-size: 6.5px; color: #94a3b8; text-transform: uppercase; }
        .empty { text-align: center; color: #94a3b8; padding: 6px; }
    </style>
</head>
<body>
    @php
        $chk = fn (?bool $activo) => $activo ? 'X' : '';
        $chkVal = fn (?string $valor, string $opcion) => $valor === $opcion ? 'X' : '';
        $fecha = fn ($valor) => $valor && (($valor instanceof \DateTimeInterface) || strtotime((string) $valor) !== false)
            ? \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y')
            : ($valor ?: '');
    @endphp

    <div class="brand">B2 SOLUTIONS</div>
    <div class="title">Ficha de datos personales del trabajador</div>

    <table class="grid">
        <tr>
            <td style="width:35%">
                <span class="label">Fecha de capa</span>
                <span class="value">{{ $fecha($empleado->fecha_capa) ?: '-' }}</span>
            </td>
            <td>
                <span class="label">Puesto</span>
                <span class="value">{{ $empleado->puesto ?: '-' }}</span>
                <span class="label" style="margin-top:3px">Campaña</span>
                <span class="value">{{ $empleado->campana ?: '-' }}</span>
            </td>
        </tr>
    </table>

    <p class="section-title">I. Datos personales</p>
    <table class="grid">
        <tr>
            <td style="width:25%"><span class="label">DNI / CE / PTP</span><span class="value">{{ $empleado->documento_identidad }}</span></td>
            <td style="width:25%"><span class="label">Apellido paterno</span><span class="value">{{ $empleado->apellido_paterno }}</span></td>
            <td style="width:25%"><span class="label">Apellido materno</span><span class="value">{{ $empleado->apellido_materno }}</span></td>
            <td><span class="label">Nombres</span><span class="value">{{ $empleado->nombres }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Fecha nac.</span><span class="value">{{ $fecha($empleado->fecha_nacimiento) ?: '-' }}</span></td>
            <td><span class="label">Lugar de nacimiento</span><span class="value">{{ $empleado->lugar_nacimiento ?: '-' }}</span></td>
            <td><span class="label">Edad</span><span class="value">{{ $empleado->edad ?? '-' }}</span></td>
            <td>
                <span class="label">Nº hijos</span><span class="value">{{ $empleado->numero_hijos ?? '-' }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">Estado civil</span><span class="value" style="text-transform:capitalize">{{ $empleado->estado_civil ?: '-' }}</span></td>
            <td colspan="2"><span class="label">Sexo</span><span class="value">F ( {{ $chkVal($empleado->sexo, 'F') }} )&nbsp;&nbsp;M ( {{ $chkVal($empleado->sexo, 'M') }} )</span></td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">Dirección actual</span><span class="value">{{ $empleado->direccion ?: '-' }}</span></td>
            <td colspan="2">
                <span class="label">Tipo vivienda (marcar con X)</span>
                <span class="value">
                    [{{ $chkVal($empleado->vivienda_tipo, 'departamento') }}] Dpto
                    [{{ $chkVal($empleado->vivienda_tipo, 'habitacion') }}] Habitac
                    [{{ $chkVal($empleado->vivienda_tipo, 'casa') }}] Casa
                    &nbsp;&nbsp;[{{ $chkVal($empleado->vivienda_tenencia, 'propia') }}] Propia
                    [{{ $chkVal($empleado->vivienda_tenencia, 'alquilada') }}] Alquilada
                </span>
            </td>
        </tr>
        <tr>
            <td><span class="label">Distrito</span><span class="value">{{ $empleado->distrito ?: '-' }}</span></td>
            <td><span class="label">Provincia</span><span class="value">{{ $empleado->provincia ?: '-' }}</span></td>
            <td><span class="label">Departamento</span><span class="value">{{ $empleado->departamento_residencia ?: '-' }}</span></td>
            <td><span class="label">Celular de llamadas</span><span class="value">{{ $empleado->celular_llamadas }}</span></td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">Correo electrónico</span><span class="value">{{ $empleado->email }}</span></td>
            <td colspan="2"><span class="label">Celular de WhatsApp</span><span class="value">{{ $empleado->celular_whatsapp ?: '-' }}</span></td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">Contacto en caso de emergencia</span><span class="value">{{ $empleado->contacto_emergencia_nombre }} ({{ $empleado->contacto_emergencia_parentesco ?: '-' }})</span></td>
            <td colspan="2"><span class="label">Número de contacto / celular</span><span class="value">{{ $empleado->contacto_emergencia_telefono }}</span></td>
        </tr>
    </table>

    <p class="section-title">II. Sistema pensionario</p>
    <table class="grid">
        <tr>
            <td style="width:22%">
                <span class="label">¿Estoy afiliado?</span>
                <span class="value">SI ( {{ $chkVal($empleado->pension_afiliado ? 'si' : 'no', 'si') }} )&nbsp;&nbsp;NO ( {{ $chkVal($empleado->pension_afiliado ? 'si' : 'no', 'no') }} )</span>
            </td>
            <td style="width:10%"><span class="label">ONP</span><span class="value">( {{ $chkVal($empleado->pension_sistema, 'onp') }} )</span></td>
            <td>
                <span class="label">AFP</span>
                <span class="value">
                    Profuturo ({{ $chkVal($empleado->pension_afp, 'profuturo') }})
                    Integra ({{ $chkVal($empleado->pension_afp, 'integra') }})
                    Prima ({{ $chkVal($empleado->pension_afp, 'prima') }})
                    Hábitat ({{ $chkVal($empleado->pension_afp, 'habitat') }})
                </span>
            </td>
            <td style="width:18%"><span class="label">Código CUSPP</span><span class="value">{{ $empleado->pension_cuspp ?: '-' }}</span></td>
        </tr>
    </table>

    <p class="section-title">III. Estudios realizados</p>
    <table class="data">
        <tr>
            <th>Nivel</th><th>Centro de estudios</th><th>Carrera</th><th>Desde</th><th>Hasta</th><th>Grado obtenido</th>
        </tr>
        @forelse ($empleado->estudios as $estudio)
            <tr>
                <td style="text-transform:capitalize">{{ $estudio->nivel ?: '-' }}</td>
                <td>{{ $estudio->centro_estudios ?: '-' }}</td>
                <td>{{ $estudio->carrera ?: '-' }}</td>
                <td>{{ $fecha($estudio->desde) ?: '-' }}</td>
                <td>{{ $fecha($estudio->hasta) ?: '-' }}</td>
                <td style="text-transform:capitalize">{{ $estudio->grado_obtenido ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">Sin registros</td></tr>
        @endforelse
    </table>

    <p class="section-title">IV. Información laboral (2 últimos empleos)</p>
    @forelse ($empleado->empleosAnteriores as $empleo)
        <table class="data">
            <tr>
                <th>Empresa</th><th>Cargo</th><th>Principal función</th><th>Sueldo</th><th>Fec. inicio - térm.</th><th>Motivo de cese</th>
            </tr>
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
        </table>
    @empty
        <p class="empty">Sin registros</p>
    @endforelse

    <p class="section-title">V. Información familiar (Padres, hijos, cónyuge, hermanos)</p>
    <table class="data">
        <tr>
            <th>Apellidos y nombres</th><th>Parentesco</th><th>Fecha de nacimiento</th><th>Edad</th><th>Ocupación</th>
        </tr>
        @forelse ($empleado->familiares as $familiar)
            <tr>
                <td>{{ $familiar->nombres_apellidos ?: '-' }}</td>
                <td>{{ $familiar->parentesco ?: '-' }}</td>
                <td>{{ $fecha($familiar->fecha_nacimiento) ?: '-' }}</td>
                <td>{{ $familiar->edad ?? '-' }}</td>
                <td>{{ $familiar->ocupacion ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Sin registros</td></tr>
        @endforelse
    </table>

    <p class="section-title">VI. Salud</p>
    <table class="grid">
        <tr>
            <td>¿Tiene antecedentes de enfermedades cardíacas o oncológicas?</td>
            <td style="width:22%">SI ( {{ $chk($empleado->salud_antecedentes) }} )&nbsp;&nbsp;NO ( {{ $chk(! $empleado->salud_antecedentes) }} )</td>
        </tr>
        <tr>
            <td>¿Actualmente sufre de alguna enfermedad?</td>
            <td>SI ( {{ $chk($empleado->salud_enfermedad_actual) }} )&nbsp;&nbsp;NO ( {{ $chk(! $empleado->salud_enfermedad_actual) }} )</td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">Si la respuesta es SÍ, por favor detállelo</span><span class="value">{{ $empleado->salud_enfermedad_detalle ?: '-' }}</span></td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">¿Toma algún medicamento?</span><span class="value">{{ $empleado->salud_medicamentos ?: '-' }}</span></td>
        </tr>
    </table>

    <p class="section-title">VII. Declaración</p>
    <div class="declaracion">
        <span class="marca">SI ( {{ $chk($empleado->declaracion_datos_veraces) }} )&nbsp;&nbsp;NO ( {{ $chk(! $empleado->declaracion_datos_veraces) }} )</span>
        <p><strong>DECLARO BAJO JURAMENTO</strong> que los datos registrados en la ficha de ingreso con respecto a la dirección actual, son verdaderos. En caso de falsedad, declaro haber incurrido en el delito, contra la Fe Pública, falsificación de Documentos (Artículo 427° del Código Penal, en concordancia con el Artículo IV inciso 1.7) "Principio de presunción de veracidad" del Título preliminar de la Ley de Procedimiento Administrativo General, Ley N° 27444.</p>
    </div>
    <div class="declaracion">
        <span class="marca">SI ( {{ $chk($empleado->declaracion_autoriza_verificacion) }} )&nbsp;&nbsp;NO ( {{ $chk(! $empleado->declaracion_autoriza_verificacion) }} )</span>
        <p>Declaro bajo juramento que los datos proporcionados son exactos, autorizando a la Institución en la que laboró a efectuar las verificaciones que juzgue necesarias; asimismo me comprometo a presentar los documentos que me soliciten.</p>
    </div>
    <div class="declaracion">
        <span class="marca">SI ( {{ $chk($empleado->declaracion_capacitacion_condiciones) }} )&nbsp;&nbsp;NO ( {{ $chk(! $empleado->declaracion_capacitacion_condiciones) }} )</span>
        <p>Declaro estar informado/a de que la capacitación es un proceso formativo y evaluativo, por lo que me comprometo a participar de manera responsable durante su desarrollo. Asimismo, entiendo y acepto que, si decido no continuar voluntariamente con el proceso antes de finalizarlo, no corresponderá el pago por capacitación. Por otro lado, en caso la empresa determine el retiro del participante por motivos internos, sí se realizará el abono correspondiente por los días de capacitación efectuados.</p>
    </div>

    <table class="firma-wrap">
        <tr>
            <td>
                @if ($empleado->firma_imagen)
                    <img src="{{ $empleado->firma_imagen }}" class="firma-img"><br>
                @endif
                <span class="firma-linea">FIRMA</span>
                <div style="font-size:8px;margin-top:3px">DNI: {{ $empleado->firma_dni ?: '-' }}</div>
            </td>
            <td>
                <div class="huella">HUELLA<br>DIGITAL</div>
            </td>
        </tr>
    </table>
</body>
</html>
