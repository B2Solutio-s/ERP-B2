<?php

use App\Models\Empleado;
use App\Models\OnboardingInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public OnboardingInvitation $invitacion;

    public bool $invalida = false;

    public int $paso = 1;

    /** @var array<int, string> */
    public array $pasos = [
        'Datos personales',
        'Sistema pensionario',
        'Estudios realizados',
        'Información laboral',
        'Información familiar',
        'Salud',
        'Declaración',
        'Firma',
    ];

    // Cabecera
    public string $campana = '';
    public string $fecha_capa = '';
    public string $puesto = '';
    public bool $puestoBloqueado = false;
    public string $departamento = '';
    public string $fecha_ingreso = '';

    // I. Datos personales
    public string $documento_identidad = '';
    public string $apellido_paterno = '';
    public string $apellido_materno = '';
    public string $nombres = '';
    public string $fecha_nacimiento = '';
    public string $lugar_nacimiento = '';
    public string $edad = '';
    public string $numero_hijos = '';
    public string $estado_civil = '';
    public string $sexo = '';
    public string $direccion = '';
    public string $vivienda_tipo = '';
    public string $vivienda_tenencia = '';
    public string $distrito = '';
    public string $provincia = '';
    public string $departamento_residencia = '';
    public string $celular_llamadas = '';
    public string $celular_whatsapp = '';
    public string $email = '';
    public string $contacto_emergencia_nombre = '';
    public string $contacto_emergencia_parentesco = '';
    public string $contacto_emergencia_telefono = '';

    // II. Sistema pensionario
    public string $pension_afiliado = '';
    public string $pension_sistema = '';
    public string $pension_afp = '';
    public string $pension_cuspp = '';

    // III. Estudios realizados
    public array $estudios = [];

    // IV. Información laboral (empleos anteriores)
    public array $empleosAnteriores = [];

    // V. Información familiar
    public array $familiares = [];

    // VI. Salud
    public string $salud_antecedentes = '';
    public string $salud_enfermedad_actual = '';
    public string $salud_enfermedad_detalle = '';
    public string $salud_medicamentos = '';

    // VII. Declaración
    public string $declaracion_datos_veraces = '';
    public string $declaracion_autoriza_verificacion = '';
    public string $declaracion_capacitacion_condiciones = '';

    // VIII. Firma
    public string $firma_dni = '';
    public string $firma_imagen = '';

    public function mount(string $token): void
    {
        $invitacion = OnboardingInvitation::where('token', $token)->first();

        if (! $invitacion || ! $invitacion->estaVigente()) {
            $this->invalida = true;

            return;
        }

        $this->invitacion = $invitacion;
        $this->email = $invitacion->email_candidato ?? '';

        $candidato = $invitacion->reclutamiento_candidato_id
            ? $invitacion->candidatoReclutamiento
            : null;

        if ($candidato) {
            $this->nombres = trim((string) $candidato->nombres);

            $apellidos = trim((string) $candidato->apellidos);
            if ($apellidos !== '') {
                [$paterno, $materno] = array_pad(explode(' ', $apellidos, 2), 2, '');
                $this->apellido_paterno = $paterno;
                $this->apellido_materno = $materno;
            }

            $this->documento_identidad = (string) ($candidato->dni_ce ?? '');
            $this->celular_llamadas = (string) ($candidato->numero_celular ?? '');
            $this->celular_whatsapp = (string) ($candidato->numero_celular ?? '');
            $this->campana = (string) ($candidato->campana ?? '');

            $puestoCategoria = trim((string) ($candidato->puesto ?? ''));
            $cargoCandidato = trim((string) ($candidato->cargo ?? ''));
            $tieneCargo = $cargoCandidato !== '' && $cargoCandidato !== '-';

            if (mb_strtolower($puestoCategoria) === 'ejecutivo' && $this->campana !== '') {
                // Ejecutivo: "Ejecutivo - Móvil" / "Ejecutivo - Fija"
                $this->puesto = "{$puestoCategoria} - {$this->campana}";
                $this->puestoBloqueado = true;
            } elseif ($tieneCargo && $this->campana !== '') {
                // Administrativo con cargo real: "Practicante - TI" / "Coordinador - MKT"
                $this->puesto = "{$cargoCandidato} - {$this->campana}";
                $this->puestoBloqueado = true;
            } elseif ($this->campana !== '') {
                // Administrativo sin cargo (MC, Supervisor): solo el nombre de la campaña
                $this->puesto = $this->campana;
                $this->puestoBloqueado = true;
            }

            $this->precargarUbicacion((string) ($candidato->distrito ?? ''));
        } elseif ($invitacion->nombre_candidato) {
            $partes = explode(' ', $invitacion->nombre_candidato, 2);
            $this->nombres = $partes[0];
            $this->apellido_paterno = $partes[1] ?? '';
        }

        $this->estudios = array_fill(0, 2, $this->filaEstudioVacia());
        $this->empleosAnteriores = array_fill(0, 2, $this->filaEmpleoVacio());
        $this->familiares = array_fill(0, 4, $this->filaFamiliarVacia());
    }

    private const CAMPOS_SIN_MAYUSCULA = [
        'email', 'estado_civil', 'sexo', 'pension_afiliado', 'pension_sistema', 'pension_afp',
        'vivienda_tipo', 'vivienda_tenencia', 'salud_antecedentes', 'salud_enfermedad_actual',
        'declaracion_datos_veraces', 'declaracion_autoriza_verificacion', 'declaracion_capacitacion_condiciones',
        'firma_imagen',
    ];

    /** @var array<string, array<int, string>> colecciones repetibles con sub-campos que son selects (no texto libre) */
    private const SUBCAMPOS_SIN_MAYUSCULA = [
        'estudios' => ['nivel', 'grado_obtenido'],
    ];

    public function updated(string $name, mixed $value): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (in_array($name, self::CAMPOS_SIN_MAYUSCULA, true)) {
            return;
        }

        foreach (self::SUBCAMPOS_SIN_MAYUSCULA as $coleccion => $subcampos) {
            if (preg_match('/^'.preg_quote($coleccion, '/').'\.\d+\.(\w+)$/', $name, $match) && in_array($match[1], $subcampos, true)) {
                return;
            }
        }

        data_set($this, $name, mb_strtoupper($value));
    }

    /**
     * Livewire no reenvía al servidor un campo "deferred" mientras el input sigue enfocado
     * (para no interrumpir al usuario), así que updated() puede no procesar el último valor
     * tecleado antes de enviar el formulario. Esta pasada final garantiza que lo que
     * efectivamente se guarda en base de datos quede en mayúsculas, sin depender de eso.
     */
    private function normalizarMayusculas(): void
    {
        foreach (get_object_vars($this) as $propiedad => $valor) {
            if (is_string($valor) && $valor !== '' && ! in_array($propiedad, self::CAMPOS_SIN_MAYUSCULA, true)) {
                $this->{$propiedad} = mb_strtoupper($valor);
            }
        }

        foreach (['estudios', 'empleosAnteriores', 'familiares'] as $coleccion) {
            $excluidos = self::SUBCAMPOS_SIN_MAYUSCULA[$coleccion] ?? [];

            foreach ($this->{$coleccion} as $indice => $fila) {
                foreach ($fila as $campo => $valor) {
                    if (is_string($valor) && $valor !== '' && ! in_array($campo, $excluidos, true)) {
                        $this->{$coleccion}[$indice][$campo] = mb_strtoupper($valor);
                    }
                }
            }
        }
    }

    public function updatedPensionAfiliado(): void
    {
        if ($this->pension_afiliado !== 'si') {
            $this->pension_sistema = '';
            $this->pension_afp = '';
            $this->pension_cuspp = '';
        }
    }

    public function updatedPensionSistema(): void
    {
        if ($this->pension_sistema !== 'afp') {
            $this->pension_afp = '';
        }
    }

    public function updatedSaludAntecedentes(): void
    {
        $this->limpiarDetalleSaludSiNoAplica();
    }

    public function updatedSaludEnfermedadActual(): void
    {
        $this->limpiarDetalleSaludSiNoAplica();
    }

    private function limpiarDetalleSaludSiNoAplica(): void
    {
        if ($this->salud_antecedentes !== 'si' && $this->salud_enfermedad_actual !== 'si') {
            $this->salud_enfermedad_detalle = '';
        }
    }

    private function precargarUbicacion(string $distritoTexto): void
    {
        $distritoTexto = trim(mb_strtoupper($distritoTexto));

        if ($distritoTexto === '') {
            return;
        }

        // Varios distritos del Perú comparten el mismo nombre en departamentos distintos
        // (ej. "SAN MIGUEL" existe en Lima, Ayacucho, Cajamarca, San Martín...). Solo
        // precargamos departamento/provincia si el nombre es inequívoco a nivel nacional;
        // si hay más de una coincidencia, dejamos que la persona elija manualmente.
        $coincidencias = [];
        foreach ($this->ubigeo() as $departamento => $provincias) {
            foreach ($provincias as $provincia => $distritos) {
                if (in_array($distritoTexto, $distritos, true)) {
                    $coincidencias[] = [$departamento, $provincia];
                }
            }
        }

        if (count($coincidencias) === 1) {
            [$this->departamento_residencia, $this->provincia] = $coincidencias[0];
            $this->distrito = $distritoTexto;
        }
    }

    private function ubigeo(): array
    {
        static $datos = null;

        return $datos ??= require resource_path('data/peru-ubigeo.php');
    }

    public function departamentosDisponibles(): array
    {
        return array_keys($this->ubigeo());
    }

    public function provinciasDisponibles(): array
    {
        return array_keys($this->ubigeo()[$this->departamento_residencia] ?? []);
    }

    public function distritosDisponibles(): array
    {
        return $this->ubigeo()[$this->departamento_residencia][$this->provincia] ?? [];
    }

    public function updatedDepartamentoResidencia(): void
    {
        $this->provincia = '';
        $this->distrito = '';
    }

    public function updatedProvincia(): void
    {
        $this->distrito = '';
    }

    private function filaEstudioVacia(): array
    {
        return [
            'nivel' => '',
            'centro_estudios' => '',
            'carrera' => '',
            'desde' => '',
            'hasta' => '',
            'grado_obtenido' => '',
        ];
    }

    private function filaEmpleoVacio(): array
    {
        return [
            'empresa' => '',
            'cargo' => '',
            'funcion_principal' => '',
            'sueldo' => '',
            'fecha_inicio' => '',
            'fecha_termino' => '',
            'motivo_cese' => '',
            'jefe_nombre' => '',
            'jefe_cargo' => '',
            'jefe_celular' => '',
        ];
    }

    private function filaFamiliarVacia(): array
    {
        return [
            'nombres_apellidos' => '',
            'parentesco' => '',
            'fecha_nacimiento' => '',
            'edad' => '',
            'ocupacion' => '',
        ];
    }

    public function agregarEstudio(): void
    {
        $this->estudios[] = $this->filaEstudioVacia();
    }

    public function quitarEstudio(int $indice): void
    {
        if (count($this->estudios) > 1) {
            unset($this->estudios[$indice]);
            $this->estudios = array_values($this->estudios);
        }
    }

    public function agregarEmpleo(): void
    {
        $this->empleosAnteriores[] = $this->filaEmpleoVacio();
    }

    public function quitarEmpleo(int $indice): void
    {
        if (count($this->empleosAnteriores) > 1) {
            unset($this->empleosAnteriores[$indice]);
            $this->empleosAnteriores = array_values($this->empleosAnteriores);
        }
    }

    public function agregarFamiliar(): void
    {
        $this->familiares[] = $this->filaFamiliarVacia();
    }

    public function quitarFamiliar(int $indice): void
    {
        if (count($this->familiares) > 1) {
            unset($this->familiares[$indice]);
            $this->familiares = array_values($this->familiares);
        }
    }

    protected function reglasPaso(int $paso): array
    {
        return match ($paso) {
            1 => [
                'documento_identidad' => 'required|string|max:30',
                'apellido_paterno' => 'required|string|max:100',
                'apellido_materno' => 'required|string|max:100',
                'nombres' => 'required|string|max:100',
                'fecha_nacimiento' => 'required|date|before:today',
                'lugar_nacimiento' => 'required|string|max:150',
                'edad' => 'required|integer|min:0|max:120',
                'numero_hijos' => 'required|integer|min:0|max:20',
                'estado_civil' => 'required|in:soltero,casado,conviviente,divorciado,viudo',
                'sexo' => 'required|in:F,M',
                'direccion' => 'required|string|max:255',
                'vivienda_tipo' => 'required|in:departamento,habitacion,casa',
                'vivienda_tenencia' => 'required|in:propia,alquilada',
                'departamento_residencia' => 'required|string',
                'provincia' => 'required|string',
                'distrito' => 'required|string',
                'celular_llamadas' => 'required|string|max:30',
                'celular_whatsapp' => 'required|string|max:30',
                'email' => 'required|email|max:150|unique:empleados,email',
                'contacto_emergencia_nombre' => 'required|string|max:100',
                'contacto_emergencia_parentesco' => 'required|string|max:100',
                'contacto_emergencia_telefono' => 'required|string|max:30',
                'puesto' => 'required|string|max:100',
            ],
            2 => [
                'pension_afiliado' => 'required|in:si,no',
                'pension_sistema' => 'nullable|required_if:pension_afiliado,si|in:onp,afp',
                'pension_afp' => 'nullable|required_if:pension_sistema,afp|in:profuturo,integra,prima,habitat',
            ],
            3 => $this->reglasEstudios(),
            4 => $this->reglasEmpleosAnteriores(),
            5 => $this->reglasFamiliares(),
            6 => [
                'salud_antecedentes' => 'required|in:si,no',
                'salud_enfermedad_actual' => 'required|in:si,no',
                'salud_enfermedad_detalle' => [
                    'nullable', 'string', 'max:500',
                    Rule::requiredIf(fn () => $this->salud_antecedentes === 'si' || $this->salud_enfermedad_actual === 'si'),
                ],
                'salud_medicamentos' => 'required|string|max:255',
            ],
            7 => [
                'declaracion_datos_veraces' => 'required|in:si',
                'declaracion_autoriza_verificacion' => 'required|in:si',
                'declaracion_capacitacion_condiciones' => 'required|in:si',
            ],
            8 => [
                'firma_dni' => 'required|string|max:30|same:documento_identidad',
                'firma_imagen' => 'required|string',
            ],
            default => [],
        };
    }

    /**
     * Reglas para una fila "todo o nada": si cualquiera de sus campos viene
     * lleno, todos los demás campos de esa misma fila pasan a ser requeridos.
     * Así una fila puede quedar completamente vacía (se descarta al guardar,
     * ver filasConDatos()) o completamente llena, nunca a medias.
     */
    protected function reglasFilaCondicional(string $coleccion, int $indice, array $campos, array $reglasExtra = []): array
    {
        $reglas = [];

        foreach ($campos as $campo) {
            $otros = array_values(array_diff($campos, [$campo]));
            $otrosConPrefijo = implode(',', array_map(fn ($c) => "{$coleccion}.{$indice}.{$c}", $otros));
            $extra = $reglasExtra[$campo] ?? null;
            $reglas["{$coleccion}.{$indice}.{$campo}"] = "required_with:{$otrosConPrefijo}" . ($extra ? "|{$extra}" : '');
        }

        return $reglas;
    }

    protected function reglasEstudios(): array
    {
        $campos = ['nivel', 'centro_estudios', 'carrera', 'desde', 'hasta', 'grado_obtenido'];
        $extra = [
            'nivel' => 'in:superior,tecnico',
            'centro_estudios' => 'string|max:150',
            'carrera' => 'string|max:150',
            'desde' => 'date',
            'hasta' => 'date',
            'grado_obtenido' => 'in:cursando,trunco,culminado',
        ];

        $reglas = [
            'estudios.0.nivel' => 'required|in:superior,tecnico',
            'estudios.0.centro_estudios' => 'required|string|max:150',
            'estudios.0.carrera' => 'required|string|max:150',
            'estudios.0.desde' => 'required|date',
            'estudios.0.hasta' => 'required|date|after_or_equal:estudios.0.desde',
            'estudios.0.grado_obtenido' => 'required|in:cursando,trunco,culminado',
        ];

        for ($i = 1; $i < count($this->estudios); $i++) {
            $reglas = array_merge($reglas, $this->reglasFilaCondicional('estudios', $i, $campos, $extra));
        }

        return $reglas;
    }

    protected function reglasEmpleosAnteriores(): array
    {
        $campos = [
            'empresa', 'cargo', 'funcion_principal', 'sueldo', 'fecha_inicio',
            'fecha_termino', 'motivo_cese', 'jefe_nombre', 'jefe_cargo', 'jefe_celular',
        ];
        $extra = [
            'fecha_inicio' => 'date',
            'fecha_termino' => 'date',
        ];

        $reglas = [];
        for ($i = 0; $i < count($this->empleosAnteriores); $i++) {
            $reglas = array_merge($reglas, $this->reglasFilaCondicional('empleosAnteriores', $i, $campos, $extra));
        }

        return $reglas;
    }

    protected function reglasFamiliares(): array
    {
        $campos = ['nombres_apellidos', 'parentesco', 'fecha_nacimiento', 'edad', 'ocupacion'];
        $extra = [
            'fecha_nacimiento' => 'date',
            'edad' => 'integer|min:0|max:120',
        ];

        $reglas = [];
        for ($i = 0; $i < count($this->familiares); $i++) {
            $reglas = array_merge($reglas, $this->reglasFilaCondicional('familiares', $i, $campos, $extra));
        }

        return $reglas;
    }

    protected function todasLasReglas(): array
    {
        return array_merge(
            $this->reglasPaso(1),
            $this->reglasPaso(2),
            $this->reglasPaso(3),
            $this->reglasPaso(4),
            $this->reglasPaso(5),
            $this->reglasPaso(6),
            $this->reglasPaso(7),
            $this->reglasPaso(8),
        );
    }

    protected function mensajes(): array
    {
        return [
            'declaracion_datos_veraces.in' => 'Debes aceptar esta declaración para continuar.',
            'declaracion_autoriza_verificacion.in' => 'Debes aceptar esta declaración para continuar.',
            'declaracion_capacitacion_condiciones.in' => 'Debes aceptar esta declaración para continuar.',
            'firma_dni.same' => 'El DNI ingresado no coincide con el registrado en el paso 1.',
            'firma_imagen.required' => 'Debes dibujar tu firma antes de continuar.',
        ];
    }

    public function siguiente(): void
    {
        $reglas = $this->reglasPaso($this->paso);

        if ($reglas !== []) {
            $this->validate($reglas, $this->mensajes());
        }

        if ($this->paso < count($this->pasos)) {
            $this->paso++;
        }
    }

    public function atras(): void
    {
        if ($this->paso > 1) {
            $this->paso--;
        }
    }

    private function filasConDatos(array $filas): array
    {
        $normalizadas = array_map(
            fn (array $fila) => array_map(fn ($valor) => $valor === '' ? null : $valor, $fila),
            $filas
        );

        return array_values(array_filter(
            $normalizadas,
            fn (array $fila) => array_filter($fila) !== []
        ));
    }

    public function guardar(): void
    {
        $this->normalizarMayusculas();

        $this->validate($this->todasLasReglas(), $this->mensajes());

        DB::transaction(function () {
            $empleado = Empleado::create([
                'onboarding_invitation_id' => $this->invitacion->id,
                'campana' => $this->campana ?: null,
                'fecha_capa' => $this->fecha_capa ?: null,
                'puesto' => $this->puesto,
                'departamento' => $this->departamento ?: null,
                'fecha_ingreso' => $this->fecha_ingreso ?: null,
                'documento_identidad' => $this->documento_identidad,
                'apellido_paterno' => $this->apellido_paterno,
                'apellido_materno' => $this->apellido_materno,
                'nombres' => $this->nombres,
                'fecha_nacimiento' => $this->fecha_nacimiento,
                'lugar_nacimiento' => $this->lugar_nacimiento ?: null,
                'edad' => $this->edad !== '' ? (int) $this->edad : null,
                'numero_hijos' => $this->numero_hijos !== '' ? (int) $this->numero_hijos : null,
                'estado_civil' => $this->estado_civil ?: null,
                'sexo' => $this->sexo ?: null,
                'direccion' => $this->direccion,
                'vivienda_tipo' => $this->vivienda_tipo ?: null,
                'vivienda_tenencia' => $this->vivienda_tenencia ?: null,
                'distrito' => $this->distrito ?: null,
                'provincia' => $this->provincia ?: null,
                'departamento_residencia' => $this->departamento_residencia ?: null,
                'celular_llamadas' => $this->celular_llamadas,
                'celular_whatsapp' => $this->celular_whatsapp ?: null,
                'email' => $this->email,
                'contacto_emergencia_nombre' => $this->contacto_emergencia_nombre,
                'contacto_emergencia_parentesco' => $this->contacto_emergencia_parentesco ?: null,
                'contacto_emergencia_telefono' => $this->contacto_emergencia_telefono,
                'pension_afiliado' => $this->pension_afiliado === 'si',
                'pension_sistema' => $this->pension_sistema ?: null,
                'pension_afp' => $this->pension_afp ?: null,
                'pension_cuspp' => $this->pension_cuspp ?: null,
                'salud_antecedentes' => $this->salud_antecedentes === 'si',
                'salud_enfermedad_actual' => $this->salud_enfermedad_actual === 'si',
                'salud_enfermedad_detalle' => $this->salud_enfermedad_detalle ?: null,
                'salud_medicamentos' => $this->salud_medicamentos ?: null,
                'declaracion_datos_veraces' => $this->declaracion_datos_veraces === 'si',
                'declaracion_autoriza_verificacion' => $this->declaracion_autoriza_verificacion === 'si',
                'declaracion_capacitacion_condiciones' => $this->declaracion_capacitacion_condiciones === 'si',
                'firma_dni' => $this->firma_dni,
                'firma_imagen' => $this->firma_imagen,
            ]);

            $empleado->estudios()->createMany($this->filasConDatos($this->estudios));
            $empleado->empleosAnteriores()->createMany($this->filasConDatos($this->empleosAnteriores));
            $empleado->familiares()->createMany($this->filasConDatos($this->familiares));

            $this->invitacion->update(['usado_en' => now()]);
        });

        session()->flash('onboarding_nombre', $this->nombres);
        $this->redirect(route('onboarding.enviado'), navigate: false);
    }
};
?>

<div class="page-shell onboarding-ficha">
    <div class="mx-auto max-w-xl">
        @if ($invalida)
            <x-ui.card centered>
                <h1 class="text-xl font-semibold text-slate-900">Este enlace no es valido</h1>
                <p class="mt-2 text-slate-500">El link de registro ya fue usado o ha expirado. Contacta a Recursos Humanos para que te genere uno nuevo.</p>
            </x-ui.card>
        @endif
    </div>

    @unless ($invalida)
        <div class="mx-auto max-w-[1160px]">
            <div class="onboarding-shell">
                <aside class="onboarding-sidebar">
                    <h1 class="text-xl font-bold text-slate-900">Ficha de datos personales</h1>
                    <p class="mt-1 text-sm text-slate-500">Completa tus datos para incorporarte a la empresa.</p>

                    <x-ui.wizard-steps-sidebar :pasos="$pasos" :actual="$paso" class="mt-8" />
                </aside>

                <div class="onboarding-content">
                @if ($paso === 1)
                    <div class="space-y-6">
                        <x-forms.section title="Puesto al que ingresas">
                            <x-forms.field label="Campaña" name="campana" />
                            <x-forms.field label="Fecha de capa" name="fecha_capa" type="date" />
                            <x-forms.field
                                label="Puesto"
                                name="puesto"
                                :readonly="$puestoBloqueado"
                                :hint="$puestoBloqueado ? 'Tomado del registro en Gestión de candidatos.' : null"
                            />
                        </x-forms.section>

                        <x-forms.section title="Datos personales">
                            <x-forms.field label="DNI / CE / PTP" name="documento_identidad" />
                            <x-forms.field label="Apellido paterno" name="apellido_paterno" />
                            <x-forms.field label="Apellido materno" name="apellido_materno" />
                            <x-forms.field label="Nombres" name="nombres" />
                            <x-forms.field label="Fecha de nacimiento" name="fecha_nacimiento" type="date" />
                            <x-forms.field label="Lugar de nacimiento" name="lugar_nacimiento" />
                            <x-forms.field label="Edad" name="edad" type="number" min="0" max="120" />
                            <x-forms.field label="Nº de hijos" name="numero_hijos" type="number" min="0" max="20" />
                            <x-forms.choice label="Estado civil" name="estado_civil" :options="[
                                'soltero' => 'Soltero(a)',
                                'casado' => 'Casado(a)',
                                'conviviente' => 'Conviviente',
                                'divorciado' => 'Divorciado(a)',
                                'viudo' => 'Viudo(a)',
                            ]" span />
                            <x-forms.choice label="Sexo" name="sexo" :options="['F' => 'Femenino', 'M' => 'Masculino']" />
                            <x-forms.field label="Dirección actual" name="direccion" span />
                            <x-forms.choice label="Tipo de vivienda" name="vivienda_tipo" :options="[
                                'departamento' => 'Departamento',
                                'habitacion' => 'Habitación',
                                'casa' => 'Casa',
                            ]" />
                            <x-forms.choice label="Tenencia" name="vivienda_tenencia" :options="['propia' => 'Propia', 'alquilada' => 'Alquilada']" />
                            <x-forms.select
                                label="Departamento"
                                name="departamento_residencia"
                                :options="array_combine($this->departamentosDisponibles(), $this->departamentosDisponibles())"
                                live
                            />
                            <x-forms.select
                                label="Provincia"
                                name="provincia"
                                :options="array_combine($this->provinciasDisponibles(), $this->provinciasDisponibles())"
                                :placeholder="$departamento_residencia ? 'Selecciona...' : 'Elige primero un departamento'"
                                live
                            />
                            <x-forms.select
                                label="Distrito"
                                name="distrito"
                                :options="array_combine($this->distritosDisponibles(), $this->distritosDisponibles())"
                                :placeholder="$provincia ? 'Selecciona...' : 'Elige primero una provincia'"
                            />
                            <x-forms.field label="Celular de llamadas" name="celular_llamadas" />
                            <x-forms.field label="Correo electrónico" name="email" type="email" />
                            <x-forms.field label="Celular de WhatsApp" name="celular_whatsapp" />
                            <x-forms.field label="Nombre de contacto de emergencia" name="contacto_emergencia_nombre" />
                            <x-forms.field label="Parentesco de contacto de emergencia" name="contacto_emergencia_parentesco" />
                            <x-forms.field label="Número de contacto" name="contacto_emergencia_telefono" />
                        </x-forms.section>
                    </div>
                @elseif ($paso === 2)
                    <x-forms.section title="Sistema pensionario">
                        <x-forms.choice label="¿Estoy afiliado?" name="pension_afiliado" :options="['si' => 'Sí', 'no' => 'No']" live span />

                        @if ($pension_afiliado === 'si')
                            <x-forms.choice label="Sistema" name="pension_sistema" :options="['onp' => 'ONP', 'afp' => 'AFP']" live span />

                            @if ($pension_sistema === 'afp')
                                <x-forms.choice label="AFP" name="pension_afp" :options="[
                                    'profuturo' => 'Profuturo',
                                    'integra' => 'Integra',
                                    'prima' => 'Prima',
                                    'habitat' => 'Hábitat',
                                ]" span />
                            @endif

                            <x-forms.field label="Código CUSPP (opcional)" name="pension_cuspp" />
                        @endif
                    </x-forms.section>
                @elseif ($paso === 3)
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="form-section-title">Estudios realizados</h3>
                            <button type="button" wire:click="agregarEstudio" class="btn-secondary">+ Agregar estudio</button>
                        </div>

                        @foreach ($estudios as $indice => $fila)
                            <x-ui.repeater-card :titulo="'Estudio '.($indice + 1)" onRemove="quitarEstudio({{ $indice }})" :puede-quitar="count($estudios) > 1" wire:key="estudio-{{ $indice }}">
                                <x-forms.select label="Nivel" name="estudios.{{ $indice }}.nivel" :options="['superior' => 'Superior', 'tecnico' => 'Técnico']" />
                                <x-forms.field label="Centro de estudios" name="estudios.{{ $indice }}.centro_estudios" />
                                <x-forms.field label="Carrera" name="estudios.{{ $indice }}.carrera" />
                                <x-forms.select label="Grado obtenido" name="estudios.{{ $indice }}.grado_obtenido" :options="[
                                    'cursando' => 'Cursando',
                                    'trunco' => 'Trunco',
                                    'culminado' => 'Culminado',
                                ]" />
                                <x-forms.field label="Desde" name="estudios.{{ $indice }}.desde" type="date" />
                                <x-forms.field label="Hasta" name="estudios.{{ $indice }}.hasta" type="date" />
                            </x-ui.repeater-card>
                        @endforeach
                    </div>
                @elseif ($paso === 4)
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="form-section-title">Información laboral (últimos empleos)</h3>
                            <button type="button" wire:click="agregarEmpleo" class="btn-secondary">+ Agregar empleo</button>
                        </div>

                        @foreach ($empleosAnteriores as $indice => $fila)
                            <x-ui.repeater-card :titulo="'Empleo '.($indice + 1)" onRemove="quitarEmpleo({{ $indice }})" :puede-quitar="count($empleosAnteriores) > 1" wire:key="empleo-{{ $indice }}">
                                <x-forms.field label="Empresa" name="empleosAnteriores.{{ $indice }}.empresa" />
                                <x-forms.field label="Cargo" name="empleosAnteriores.{{ $indice }}.cargo" />
                                <x-forms.field label="Función principal" name="empleosAnteriores.{{ $indice }}.funcion_principal" span />
                                <x-forms.field label="Sueldo" name="empleosAnteriores.{{ $indice }}.sueldo" />
                                <x-forms.field label="Fecha inicio" name="empleosAnteriores.{{ $indice }}.fecha_inicio" type="date" />
                                <x-forms.field label="Fecha término" name="empleosAnteriores.{{ $indice }}.fecha_termino" type="date" />
                                <x-forms.field label="Motivo de cese" name="empleosAnteriores.{{ $indice }}.motivo_cese" span />
                                <x-forms.field label="Jefe inmediato" name="empleosAnteriores.{{ $indice }}.jefe_nombre" />
                                <x-forms.field label="Cargo del jefe" name="empleosAnteriores.{{ $indice }}.jefe_cargo" />
                                <x-forms.field label="Celular del jefe" name="empleosAnteriores.{{ $indice }}.jefe_celular" />
                            </x-ui.repeater-card>
                        @endforeach
                    </div>
                @elseif ($paso === 5)
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="form-section-title">Información familiar (padres, hijos, cónyuge, hermanos)</h3>
                            <button type="button" wire:click="agregarFamiliar" wire:loading.attr="disabled" class="btn-secondary">
                                <span wire:loading.remove wire:target="agregarFamiliar">+ Agregar fila</span>
                                <span wire:loading wire:target="agregarFamiliar">Agregando...</span>
                            </button>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-slate-100">
                            <table class="data-table min-w-[720px]">
                                <thead>
                                    <tr>
                                        <th>Apellidos y nombres</th>
                                        <th>Parentesco</th>
                                        <th>Fecha de nacimiento</th>
                                        <th>Edad</th>
                                        <th>Ocupación</th>
                                        <th class="w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($familiares as $indice => $fila)
                                        <tr wire:key="familiar-{{ $indice }}">
                                            <td><input type="text" wire:model="familiares.{{ $indice }}.nombres_apellidos" class="form-input"></td>
                                            <td><input type="text" wire:model="familiares.{{ $indice }}.parentesco" class="form-input"></td>
                                            <td><input type="date" wire:model="familiares.{{ $indice }}.fecha_nacimiento" class="form-input"></td>
                                            <td><input type="number" wire:model="familiares.{{ $indice }}.edad" class="form-input" min="0" max="120"></td>
                                            <td><input type="text" wire:model="familiares.{{ $indice }}.ocupacion" class="form-input"></td>
                                            <td class="text-center">
                                                @if (count($familiares) > 1)
                                                    <button type="button" wire:click="quitarFamiliar({{ $indice }})" class="text-rose-400 transition hover:text-rose-600" aria-label="Quitar fila" title="Quitar fila">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @elseif ($paso === 6)
                    <x-forms.section title="Salud">
                        <x-forms.choice label="¿Tiene antecedentes de enfermedades cardíacas o oncológicas?" name="salud_antecedentes" :options="['si' => 'Sí', 'no' => 'No']" live span />
                        <x-forms.choice label="¿Actualmente sufre de alguna enfermedad?" name="salud_enfermedad_actual" :options="['si' => 'Sí', 'no' => 'No']" live span />

                        @if ($salud_antecedentes === 'si' || $salud_enfermedad_actual === 'si')
                            <x-forms.textarea label="Si la respuesta es SÍ, por favor detállelo" name="salud_enfermedad_detalle" span />
                        @endif

                        <x-forms.field label="¿Toma algún medicamento?" name="salud_medicamentos" span />
                    </x-forms.section>
                @elseif ($paso === 7)
                    <div class="space-y-5">
                        <h3 class="form-section-title">Declaración</h3>

                        <div class="space-y-2">
                            <p class="text-sm text-slate-600">
                                DECLARO BAJO JURAMENTO que los datos registrados en la ficha de ingreso con respecto a la dirección
                                actual, son verdaderos. En caso de falsedad, declaro haber incurrido en el delito, contra la Fe
                                Pública, falsificación de Documentos (Artículo 427° del Código Penal, en concordancia con el
                                Artículo IV inciso 1.7) "Principio de presunción de veracidad" del Título preliminar de la Ley de
                                Procedimiento Administrativo General, Ley N° 27444.
                            </p>
                            <x-forms.choice label="¿Aceptas esta declaración?" name="declaracion_datos_veraces" :options="['si' => 'Sí']" />
                        </div>

                        <div class="space-y-2 border-t border-slate-100 pt-4">
                            <p class="text-sm text-slate-600">
                                Declaro bajo juramento que los datos proporcionados son exactos, autorizando a la Institución en la
                                que laboró a efectuar las verificaciones que juzgue necesarias; asimismo me comprometo a presentar
                                los documentos que me soliciten.
                            </p>
                            <x-forms.choice label="¿Aceptas esta declaración?" name="declaracion_autoriza_verificacion" :options="['si' => 'Sí']" />
                        </div>

                        <div class="space-y-2 border-t border-slate-100 pt-4">
                            <p class="text-sm text-slate-600">
                                Declaro estar informado/a de que la capacitación es un proceso formativo y evaluativo, por lo que me
                                comprometo a participar de manera responsable durante su desarrollo. Asimismo, entiendo y acepto que,
                                si decido no continuar voluntariamente con el proceso antes de finalizarlo, no corresponderá el pago
                                por capacitación. Por otro lado, en caso la empresa determine el retiro del participante por motivos
                                internos, sí se realizará el abono correspondiente por los días de capacitación efectuados.
                            </p>
                            <x-forms.choice label="¿Aceptas esta declaración?" name="declaracion_capacitacion_condiciones" :options="['si' => 'Sí']" />
                        </div>
                    </div>
                @elseif ($paso === 8)
                    <div class="space-y-6">
                        <x-forms.section title="Confirma tu identidad">
                            <x-forms.field label="DNI" name="firma_dni" />
                        </x-forms.section>

                        <div>
                            <label class="form-label">Firma</label>
                            <div class="firma-pad">
                                <canvas data-firma-canvas></canvas>
                            </div>
                            <input type="hidden" wire:model="firma_imagen" data-firma-input>
                            <div class="mt-2 flex items-center justify-between">
                                <p class="text-xs text-slate-400">Dibuja tu firma con el cursor o el dedo.</p>
                                <button type="button" class="btn-secondary w-auto" data-firma-limpiar>Limpiar firma</button>
                            </div>
                            @error('firma_imagen')
                                <p class="form-error">{{ $message }}</p>
                            @enderror
                            @error('firma_dni')
                                <p class="form-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif

                <div class="mt-8 flex items-center justify-between">
                    <button type="button" wire:click="atras" class="btn-secondary" @if ($paso === 1) disabled @endif>
                        Atrás
                    </button>

                    @if ($paso < count($pasos))
                        <button type="button" wire:click="siguiente" wire:loading.attr="disabled" class="btn-primary w-auto px-8">
                            <span wire:loading.remove>Siguiente</span>
                            <span wire:loading>Guardando...</span>
                        </button>
                    @else
                        <button type="button" wire:click="guardar" wire:loading.attr="disabled" class="btn-primary w-auto px-8">
                            <span wire:loading.remove>Enviar registro</span>
                            <span wire:loading>Enviando...</span>
                        </button>
                    @endif
                </div>
            </div>
            </div>
        </div>
    @endunless
</div>
