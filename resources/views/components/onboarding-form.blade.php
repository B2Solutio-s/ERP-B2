<?php

use App\Models\Empleado;
use App\Models\OnboardingInvitation;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public OnboardingInvitation $invitacion;

    public bool $enviado = false;
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
    ];

    // Cabecera
    public string $campana = '';
    public string $fecha_capa = '';
    public string $puesto = '';
    public string $departamento = '';
    public string $fecha_ingreso = '';

    // I. Datos personales
    public string $documento_identidad = '';
    public string $apellido_paterno = '';
    public string $apellido_materno = '';
    public string $nombres = '';
    public string $fecha_nacimiento = '';
    public string $lugar_nacimiento = '';
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
    public bool $declaracion_aceptada = false;

    public function mount(string $token): void
    {
        $invitacion = OnboardingInvitation::where('token', $token)->first();

        if (! $invitacion || ! $invitacion->estaVigente()) {
            $this->invalida = true;

            return;
        }

        $this->invitacion = $invitacion;
        $this->email = $invitacion->email_candidato ?? '';

        if ($invitacion->nombre_candidato) {
            $partes = explode(' ', $invitacion->nombre_candidato, 2);
            $this->nombres = $partes[0];
            $this->apellido_paterno = $partes[1] ?? '';
        }

        $this->estudios = [$this->filaEstudioVacia()];
        $this->empleosAnteriores = [$this->filaEmpleoVacio()];
        $this->familiares = [$this->filaFamiliarVacia()];
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
                'direccion' => 'required|string|max:255',
                'celular_llamadas' => 'required|string|max:30',
                'email' => 'required|email|max:150|unique:empleados,email',
                'contacto_emergencia_nombre' => 'required|string|max:100',
                'contacto_emergencia_telefono' => 'required|string|max:30',
                'fecha_ingreso' => 'required|date',
                'puesto' => 'required|string|max:100',
                'departamento' => 'required|string|max:100',
            ],
            2 => [
                'pension_afiliado' => 'required|in:si,no',
                'pension_sistema' => 'nullable|required_if:pension_afiliado,si|in:onp,afp',
                'pension_afp' => 'nullable|required_if:pension_sistema,afp|in:profuturo,integra,prima,habitat',
            ],
            3 => [
                'estudios.0.nivel' => 'required|in:superior,tecnico',
                'estudios.0.centro_estudios' => 'required|string|max:150',
            ],
            4 => [],
            5 => [],
            6 => [
                'salud_antecedentes' => 'required|in:si,no',
                'salud_enfermedad_actual' => 'required|in:si,no',
            ],
            7 => [
                'declaracion_aceptada' => 'accepted',
            ],
            default => [],
        };
    }

    protected function todasLasReglas(): array
    {
        return array_merge(
            $this->reglasPaso(1),
            $this->reglasPaso(2),
            $this->reglasPaso(3),
            $this->reglasPaso(6),
            $this->reglasPaso(7),
        );
    }

    public function siguiente(): void
    {
        $reglas = $this->reglasPaso($this->paso);

        if ($reglas !== []) {
            $this->validate($reglas);
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
        $this->validate($this->todasLasReglas());

        DB::transaction(function () {
            $empleado = Empleado::create([
                'onboarding_invitation_id' => $this->invitacion->id,
                'campana' => $this->campana ?: null,
                'fecha_capa' => $this->fecha_capa ?: null,
                'puesto' => $this->puesto,
                'departamento' => $this->departamento,
                'fecha_ingreso' => $this->fecha_ingreso,
                'documento_identidad' => $this->documento_identidad,
                'apellido_paterno' => $this->apellido_paterno,
                'apellido_materno' => $this->apellido_materno,
                'nombres' => $this->nombres,
                'fecha_nacimiento' => $this->fecha_nacimiento,
                'lugar_nacimiento' => $this->lugar_nacimiento ?: null,
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
            ]);

            $empleado->estudios()->createMany($this->filasConDatos($this->estudios));
            $empleado->empleosAnteriores()->createMany($this->filasConDatos($this->empleosAnteriores));
            $empleado->familiares()->createMany($this->filasConDatos($this->familiares));

            $this->invitacion->update(['usado_en' => now()]);
        });

        $this->enviado = true;
    }
};
?>

<div class="page-shell">
    <div class="mx-auto max-w-3xl">
        @if ($invalida)
            <x-ui.card centered>
                <h1 class="text-xl font-semibold text-slate-900">Este enlace no es valido</h1>
                <p class="mt-2 text-slate-500">El link de registro ya fue usado o ha expirado. Contacta a Recursos Humanos para que te genere uno nuevo.</p>
            </x-ui.card>
        @elseif ($enviado)
            <x-ui.card centered>
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success/10">
                    <svg class="h-6 w-6 text-success" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <h1 class="mt-4 text-xl font-semibold text-slate-900">Registro enviado</h1>
                <p class="mt-2 text-slate-500">Gracias, {{ $nombres }}. Tus datos fueron enviados a Recursos Humanos correctamente.</p>
            </x-ui.card>
        @else
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold text-slate-900">Ficha de datos personales</h1>
                <p class="mt-1 text-slate-500">Completa tus datos para incorporarte a la empresa.</p>
            </div>

            <x-ui.wizard-steps :pasos="$pasos" :actual="$paso" />

            <div class="card-panel">
                @if ($paso === 1)
                    <div class="space-y-6">
                        <x-forms.section title="Puesto al que ingresas">
                            <x-forms.field label="Campaña" name="campana" />
                            <x-forms.field label="Fecha de capa" name="fecha_capa" type="date" />
                            <x-forms.field label="Puesto" name="puesto" />
                            <x-forms.field label="Departamento" name="departamento" />
                            <x-forms.field label="Fecha de ingreso" name="fecha_ingreso" type="date" />
                        </x-forms.section>

                        <x-forms.section title="Datos personales">
                            <x-forms.field label="DNI / CE / PTP" name="documento_identidad" />
                            <x-forms.field label="Apellido paterno" name="apellido_paterno" />
                            <x-forms.field label="Apellido materno" name="apellido_materno" />
                            <x-forms.field label="Nombres" name="nombres" />
                            <x-forms.field label="Fecha de nacimiento" name="fecha_nacimiento" type="date" />
                            <x-forms.field label="Lugar de nacimiento" name="lugar_nacimiento" />
                            <x-forms.field label="Nº de hijos" name="numero_hijos" type="number" />
                            <x-forms.select label="Estado civil" name="estado_civil" :options="[
                                'soltero' => 'Soltero(a)',
                                'casado' => 'Casado(a)',
                                'conviviente' => 'Conviviente',
                                'divorciado' => 'Divorciado(a)',
                                'viudo' => 'Viudo(a)',
                            ]" />
                            <x-forms.select label="Sexo" name="sexo" :options="['F' => 'Femenino', 'M' => 'Masculino']" />
                        </x-forms.section>

                        <x-forms.section title="Domicilio y contacto">
                            <x-forms.field label="Dirección actual" name="direccion" span />
                            <x-forms.select label="Tipo de vivienda" name="vivienda_tipo" :options="[
                                'departamento' => 'Departamento',
                                'habitacion' => 'Habitación',
                                'casa' => 'Casa',
                            ]" />
                            <x-forms.select label="Tenencia" name="vivienda_tenencia" :options="['propia' => 'Propia', 'alquilada' => 'Alquilada']" />
                            <x-forms.field label="Distrito" name="distrito" />
                            <x-forms.field label="Provincia" name="provincia" />
                            <x-forms.field label="Departamento" name="departamento_residencia" />
                            <x-forms.field label="Celular de llamadas" name="celular_llamadas" />
                            <x-forms.field label="Celular de WhatsApp" name="celular_whatsapp" />
                            <x-forms.field label="Correo electrónico" name="email" type="email" span />
                        </x-forms.section>

                        <x-forms.section title="Contacto de emergencia">
                            <x-forms.field label="Nombre" name="contacto_emergencia_nombre" />
                            <x-forms.field label="Parentesco" name="contacto_emergencia_parentesco" />
                            <x-forms.field label="Número de contacto / celular" name="contacto_emergencia_telefono" />
                        </x-forms.section>
                    </div>
                @elseif ($paso === 2)
                    <x-forms.section title="Sistema pensionario">
                        <x-forms.select label="¿Estoy afiliado?" name="pension_afiliado" :options="['si' => 'Sí', 'no' => 'No']" />
                        <x-forms.select label="Sistema" name="pension_sistema" :options="['onp' => 'ONP', 'afp' => 'AFP']" />
                        <x-forms.select label="AFP" name="pension_afp" :options="[
                            'profuturo' => 'Profuturo',
                            'integra' => 'Integra',
                            'prima' => 'Prima',
                            'habitat' => 'Hábitat',
                        ]" />
                        <x-forms.field label="Código CUSPP (opcional)" name="pension_cuspp" />
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
                                <x-forms.field label="Desde" name="estudios.{{ $indice }}.desde" />
                                <x-forms.field label="Hasta" name="estudios.{{ $indice }}.hasta" />
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
                                <x-forms.field label="Fecha inicio" name="empleosAnteriores.{{ $indice }}.fecha_inicio" />
                                <x-forms.field label="Fecha término" name="empleosAnteriores.{{ $indice }}.fecha_termino" />
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
                            <button type="button" wire:click="agregarFamiliar" class="btn-secondary">+ Agregar familiar</button>
                        </div>

                        @foreach ($familiares as $indice => $fila)
                            <x-ui.repeater-card :titulo="'Familiar '.($indice + 1)" onRemove="quitarFamiliar({{ $indice }})" :puede-quitar="count($familiares) > 1" wire:key="familiar-{{ $indice }}">
                                <x-forms.field label="Apellidos y nombres" name="familiares.{{ $indice }}.nombres_apellidos" span />
                                <x-forms.field label="Parentesco" name="familiares.{{ $indice }}.parentesco" />
                                <x-forms.field label="Fecha de nacimiento" name="familiares.{{ $indice }}.fecha_nacimiento" type="date" />
                                <x-forms.field label="Edad" name="familiares.{{ $indice }}.edad" type="number" />
                                <x-forms.field label="Ocupación" name="familiares.{{ $indice }}.ocupacion" />
                            </x-ui.repeater-card>
                        @endforeach
                    </div>
                @elseif ($paso === 6)
                    <x-forms.section title="Salud">
                        <x-forms.select label="¿Tiene antecedentes de enfermedades cardíacas o oncológicas?" name="salud_antecedentes" :options="['si' => 'Sí', 'no' => 'No']" span />
                        <x-forms.select label="¿Actualmente sufre de alguna enfermedad?" name="salud_enfermedad_actual" :options="['si' => 'Sí', 'no' => 'No']" span />
                        <x-forms.textarea label="Si la respuesta es SÍ, por favor detállelo" name="salud_enfermedad_detalle" span />
                        <x-forms.textarea label="¿Toma algún medicamento?" name="salud_medicamentos" span />
                    </x-forms.section>
                @elseif ($paso === 7)
                    <div class="space-y-4">
                        <h3 class="form-section-title">Declaración</h3>
                        <p class="text-sm text-slate-600">
                            Declaro que la información proporcionada en esta ficha es verídica y completa, y autorizo a la empresa a
                            verificarla y utilizarla para fines de gestión de recursos humanos.
                        </p>
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="declaracion_aceptada" class="mt-1">
                            Acepto la declaración anterior.
                        </label>
                        @error('declaracion_aceptada')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
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
        @endif
    </div>
</div>
