<?php

namespace App\Services;

use App\Models\CandidatoDocumento;
use App\Models\CandidatoReclutamiento;
use App\Models\Empleado;
use Illuminate\Support\Facades\Auth;

/**
 * Todos los datos registrados de un candidato o trabajador, agrupados por etapa para mostrarlos en los tableros.
 * Cada grupo es ['titulo' => string, 'campos' => [[etiqueta, valor], ...]].
 */
class FichaCompleta
{
    public const TIPOS_DOCUMENTO = [
        'dni_copia' => 'DNI - Copia',
        'cv_actualizado' => 'CV actualizado',
        'dni' => 'DNI',
        'recibo' => 'Recibo',
        'dni_hijos' => 'DNI - Derechohabiente',
        'cul' => 'CUL - Certificado Único Laboral',
    ];

    private const ESTADOS_PERIODO = [
        'activo' => 'Activo',
        'por_vencer' => 'Por vencer',
        'vencido' => 'Vencido',
        'renovado' => 'Renovado',
        'cesado' => 'Cesado',
    ];

    private const CESES = ['renuncia' => 'Renuncia', 'despido' => 'Despido', 'vencimiento' => 'Vencimiento'];

    public static function candidato(CandidatoReclutamiento $c): array
    {
        return self::grupos($c, [
            'Datos personales' => ['DNI / CE' => 'dni_ce', 'Nombres' => 'nombres', 'Apellidos' => 'apellidos', 'Fecha de nacimiento' => 'fecha_nacimiento', 'Edad' => 'edad', 'Celular' => 'numero_celular', 'Correo' => 'email', 'Correo corporativo' => 'email_corporativo', 'Distrito' => 'distrito', 'Dirección' => 'direccion'],
            'Gestión' => ['Fecha de gestión' => 'fecha_gestion', 'Base' => 'base', 'Fuente' => 'fuente', 'Reclutador' => 'agente_reclutador', 'Área' => 'area', 'Puesto' => 'puesto', 'Campaña' => 'campana', 'Cargo' => 'cargo', 'Experiencia' => 'experiencia', 'Tipificación' => 'tipificacion', 'Motivo de rechazo' => 'subtipificacion_rechazo', 'Observaciones' => 'observaciones'],
            'Entrevista' => ['Aceptó la entrevista' => 'aceptacion_entrevista', 'Fecha' => 'fecha_entrevista', 'Hora' => 'hora_entrevista', 'Asistió' => 'asistio_entrevista', 'Fecha reprogramada' => 'fecha_reprogramada', 'Asistió (reprogramada)' => 'asistio_entrevista_reprogramada'],
            'Capacitación 1' => ['Fecha' => 'capacitacion_1', 'Asistió' => 'asistio_cap_1', 'Observación' => 'obs_1', 'Fecha reprogramada' => 'capacitacion_1_reprogramada', 'Asistió (reprogramada)' => 'asistio_cap_1_reprogramada', 'Observación (reprogramada)' => 'obs_1_reprogramada'],
            'Capacitación 2' => ['Fecha' => 'capacitacion_2', 'Asistió' => 'asistio_cap_2', 'Observación' => 'obs_2', 'Fecha reprogramada' => 'capacitacion_2_reprogramada', 'Asistió (reprogramada)' => 'asistio_cap_2_reprogramada', 'Observación (reprogramada)' => 'obs_2_reprogramada'],
            'Resultado' => ['Apto' => 'apto', 'Marcado apto el' => 'fecha_apto', 'Fecha de ingreso' => 'fecha_ingreso'],
            'Datos bancarios' => ['Cuenta de sueldo' => 'cuenta_sueldo', 'Cuenta free' => 'cuenta_free', 'Cuenta CTS' => 'cuenta_cts', 'CCI' => 'cci'],
        ]);
    }

    public static function empleado(Empleado $e): array
    {
        $e->loadMissing(['periodosContrato.documentoContrato', 'periodosContrato.documentosCese', 'estudios', 'empleosAnteriores', 'familiares', 'invitacion']);

        $candidatoId = $e->invitacion?->reclutamiento_candidato_id;
        $candidato = $candidatoId ? CandidatoReclutamiento::find($candidatoId) : null;

        // Los datos de contrato se llenan al generar el contrato; mientras tanto se muestran los del trabajador y de su período vigente.
        $vigente = $e->periodosContrato->sortBy('fecha_inicio')->last();
        $vista = clone $e;
        foreach ([
            'contrato_area' => $e->area,
            'contrato_puesto' => $e->puesto,
            'contrato_campana' => $e->campana,
            'contrato_cargo' => $e->cargo,
            'contrato_duracion' => $vigente?->duracion_meses ? $vigente->duracion_meses.' MESES' : null,
            'contrato_fecha_inicio' => $vigente?->fecha_inicio,
            'contrato_fecha_fin' => $vigente?->fecha_fin,
        ] as $atributo => $respaldo) {
            if (blank($e->{$atributo}) && filled($respaldo)) {
                $vista->setAttribute($atributo, $respaldo);
            }
        }

        $grupos = self::grupos($vista, [
            'Datos personales' => ['DNI / CE' => 'documento_identidad', 'Apellido paterno' => 'apellido_paterno', 'Apellido materno' => 'apellido_materno', 'Nombres' => 'nombres', 'Sexo' => 'sexo', 'Estado civil' => 'estado_civil', 'Fecha de nacimiento' => 'fecha_nacimiento', 'Lugar de nacimiento' => 'lugar_nacimiento', 'Edad' => 'edad', 'N.º de hijos' => 'numero_hijos', 'Hijos menores de edad' => 'hijos_menores'],
            'Contacto y domicilio' => ['Celular (llamadas)' => 'celular_llamadas', 'Celular (WhatsApp)' => 'celular_whatsapp', 'Correo' => 'email', 'Correo corporativo' => 'email_corporativo', 'Dirección' => 'direccion', 'Distrito' => 'distrito', 'Provincia' => 'provincia', 'Departamento' => 'departamento_residencia', 'Tipo de vivienda' => 'vivienda_tipo', 'Tenencia' => 'vivienda_tenencia'],
            'Contacto de emergencia' => ['Nombre' => 'contacto_emergencia_nombre', 'Parentesco' => 'contacto_emergencia_parentesco', 'Teléfono' => 'contacto_emergencia_telefono'],
            'Puesto y contrato' => ['Área' => 'contrato_area', 'Puesto' => 'contrato_puesto', 'Campaña' => 'contrato_campana', 'Cargo' => 'contrato_cargo', 'Duración' => 'contrato_duracion', 'Remuneración' => 'contrato_remuneracion', 'Movilidad' => 'contrato_remuneracion_movilidad', 'Inicio' => 'contrato_fecha_inicio', 'Fin' => 'contrato_fecha_fin', 'Firma' => 'contrato_fecha_firma', 'Fecha de capacitación' => 'fecha_capa', 'ID de huella' => 'biometrico_id'],
            'Pensión y salud' => ['Afiliado a pensión' => 'pension_afiliado', 'Sistema' => 'pension_sistema', 'AFP' => 'pension_afp', 'CUSPP' => 'pension_cuspp', 'Antecedentes de salud' => 'salud_antecedentes', 'Enfermedad actual' => 'salud_enfermedad_actual', 'Detalle' => 'salud_enfermedad_detalle', 'Medicamentos' => 'salud_medicamentos'],
        ]);

        $periodos = $e->periodosContrato;
        $grupos[] = [
            'titulo' => 'Períodos de contrato',
            'campos' => $periodos->isEmpty()
                ? [['Sin períodos', 'Todavía no tiene contrato registrado']]
                : $periodos->map(fn ($p) => [
                    $p->tipo === 'prueba' ? '1er contrato (Prueba)' : 'Renovación',
                    self::valor($p->fecha_inicio).' – '.self::valor($p->fecha_fin)
                        .' · '.(self::ESTADOS_PERIODO[$p->estadoEn($periodos)] ?? $p->estadoEn($periodos))
                        .' · contrato firmado: '.($p->documentoContrato ? 'sí' : 'no')
                        .($p->cese ? ' · cese: '.(self::CESES[$p->cese_tipo] ?? $p->cese_tipo).' el '.self::valor($p->cese_fecha).' ('.$p->documentosCese->count().' documentos)' : ''),
                ])->values()->all(),
        ];

        foreach ([
            'Estudios' => [$e->estudios, fn ($x) => [$x->nivel, collect([$x->carrera, $x->centro_estudios, $x->grado_obtenido])->filter()->implode(' · ').' ('.self::valor($x->desde).' – '.self::valor($x->hasta).')']],
            'Empleos anteriores' => [$e->empleosAnteriores, fn ($x) => [$x->empresa, collect([$x->cargo, self::valor($x->fecha_inicio).' – '.self::valor($x->fecha_termino), $x->motivo_cese])->filter()->implode(' · ')]],
            'Familiares' => [$e->familiares, fn ($x) => [$x->parentesco, collect([$x->nombres_apellidos, $x->edad ? $x->edad.' años' : null, $x->ocupacion])->filter()->implode(' · ')]],
        ] as $titulo => [$lista, $fila]) {
            if ($lista->isNotEmpty()) {
                $grupos[] = ['titulo' => $titulo, 'campos' => $lista->map($fila)->values()->all()];
            }
        }

        if ($candidato) {
            $grupos[] = [
                'titulo' => 'Proceso de reclutamiento',
                'campos' => [
                    ['Apto', self::valor($candidato->apto)],
                    ['Situación (si no es apto)', self::situacion($candidato)],
                    ['Entrevista', self::etapa($candidato->asistio_entrevista, $candidato->fecha_entrevista).self::etapa($candidato->asistio_entrevista_reprogramada, $candidato->fecha_reprogramada, true)],
                    ['Capacitación 1', self::etapa($candidato->asistio_cap_1, $candidato->capacitacion_1).self::etapa($candidato->asistio_cap_1_reprogramada, $candidato->capacitacion_1_reprogramada, true)],
                    ['Capacitación 2', self::etapa($candidato->asistio_cap_2, $candidato->capacitacion_2).self::etapa($candidato->asistio_cap_2_reprogramada, $candidato->capacitacion_2_reprogramada, true)],
                ],
            ];

            $subidos = CandidatoDocumento::where('reclutamiento_candidato_id', $candidato->id)->get()->keyBy('tipo');
            $grupos[] = [
                'titulo' => 'Documentos de ingreso',
                'campos' => collect(self::TIPOS_DOCUMENTO)->map(fn ($etiqueta, $tipo) => [$etiqueta, $subidos->get($tipo)?->nombre_original ?? 'Pendiente'])->values()->all(),
            ];

            $grupos[] = self::grupos($candidato, [
                'Datos bancarios' => ['Cuenta de sueldo' => 'cuenta_sueldo', 'Cuenta free' => 'cuenta_free', 'Cuenta CTS' => 'cuenta_cts', 'CCI' => 'cci'],
            ])[0];
        }

        return $grupos;
    }

    /** @param  array<string, array<string, string>>  $mapa  título del grupo => [etiqueta => atributo] */
    private static function grupos(object $modelo, array $mapa): array
    {
        // GTH nunca ve la remuneración ni la movilidad del trabajador.
        $ocultos = Auth::user()?->esAdmin() ? [] : ['contrato_remuneracion', 'contrato_remuneracion_movilidad'];
        $mapa = array_map(fn (array $campos) => array_filter($campos, fn (string $atributo) => ! in_array($atributo, $ocultos, true)), $mapa);

        return collect($mapa)->map(fn (array $campos, string $titulo) => [
            'titulo' => $titulo,
            'campos' => collect($campos)->map(fn (string $atributo, string $etiqueta) => [$etiqueta, self::valor($modelo->{$atributo})])->values()->all(),
        ])->values()->all();
    }

    private static function situacion(CandidatoReclutamiento $c): string
    {
        $situacion = $c->situacionSinApto();

        return $situacion ? ($situacion['definitivo'] ? 'No apto — ' : 'Apto pendiente — ').$situacion['motivo'] : '—';
    }

    /** «Resultado (fecha)» de una etapa; la reprogramada se agrega a continuación. */
    private static function etapa(?string $resultado, mixed $fecha, bool $reprogramada = false): string
    {
        if (! $resultado && ! $fecha) {
            return $reprogramada ? '' : '—';
        }

        return ($reprogramada ? ' → reprogramada: ' : '').($resultado ?: 'sin resultado').' ('.self::valor($fecha).')';
    }

    private static function valor(mixed $dato): string
    {
        return match (true) {
            $dato === null || $dato === '' => '—',
            is_bool($dato) => $dato ? 'Sí' : 'No',
            $dato instanceof \DateTimeInterface => $dato->format($dato->format('H:i:s') === '00:00:00' ? 'd/m/Y' : 'd/m/Y H:i'),
            default => (string) $dato,
        };
    }
}
