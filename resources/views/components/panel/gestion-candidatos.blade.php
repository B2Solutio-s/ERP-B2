<?php

use App\Models\CandidatoReclutamiento;
use App\Services\GestionCandidatos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new #[Layout('layouts.panel', ['title' => 'Gestión de candidatos'])] class extends Component
{
    public string $buscar = '';

    public string $filtroEtapa = '';

    public string $filtroReclutador = '';

    public string $filtroArea = '';

    public string $filtroMes = '';

    public bool $soloAccion = false;

    /** Día de gestión elegido (Y-m-d) y orden de la lista: 'fecha' (por fecha de registro) o 'prioridad' (por siguiente acción). */
    public string $filtroDia = '';

    public string $orden = 'fecha';

    public int $limite = 20;

    /** Registro abierto en el panel lateral. */
    public bool $abierto = false;

    public ?int $candidatoId = null;

    /** @var array<string, string> */
    public array $f = [];

    public bool $soloLectura = false;

    public ?string $bloqueadoPor = null;

    public string $aviso = '';

    public ?int $duplicadoId = null;

    /** Registrar una llamada sobre el candidato abierto. */
    public bool $llamadaAbierta = false;

    /** @var array<string, string> */
    public array $ll = [];

    public string $llamadaAviso = '';

    public string $mensaje = '';

    /** Registro masivo: datos comunes, lista pegada y filas ya interpretadas. */
    public bool $masivoAbierto = false;

    /** @var array<string, string> */
    public array $m = [];

    /** @var array<int, array<string, mixed>> */
    public array $masivoFilas = [];

    public string $masivoAviso = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403);

        // Enlace del aviso de llamadas pendientes: abre directo el registro.
        if ($id = (int) request()->query('abrir')) {
            $this->abrir($id);
        }
    }

    private function servicio(): GestionCandidatos
    {
        return app(GestionCandidatos::class);
    }

    private ?array $catalogosCache = null;

    private function catalogos(): array
    {
        return $this->catalogosCache ??= $this->servicio()->catalogos();
    }

    // ---------------------------------------------------------------
    // Filtros
    // ---------------------------------------------------------------

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'filtroEtapa', 'filtroReclutador', 'filtroArea', 'filtroMes', 'filtroDia', 'soloAccion', 'orden'], true)) {
            $this->limite = 20;
        }

        if ($propiedad === 'orden' && ! in_array($this->orden, ['fecha', 'prioridad'], true)) {
            $this->orden = 'fecha';
        }

        $servicio = $this->servicio();

        match ($propiedad) {
            'f.fecha_gestion' => $this->f['mes'] = $servicio->mesDe($this->f['fecha_gestion'] ?: null),
            'f.area' => $this->alCambiarArea('f'),
            'f.puesto' => $this->ajustarCampana('f'),
            'm.area' => $this->alCambiarArea('m'),
            'm.puesto' => $this->ajustarCampana('m'),
            'f.tipificacion' => $this->alCambiarTipificacion(),
            'll.tipificacion' => $this->alCambiarTipificacionLlamada(),
            'f.aceptacion_entrevista' => $this->alCambiarAceptacion(),
            'f.fecha_entrevista' => $this->alCambiarFechaEntrevista(),
            'f.asistio_entrevista' => $this->alCambiarAsistio(),
            'f.fecha_reprogramada' => $this->alCambiarFechaReprogramada(),
            default => null,
        };

        // Al cambiar cómo se interpreta una línea del registro masivo se revisa de nuevo toda la lista.
        if (preg_match('/^masivoFilas\.\d+\.forzado$/', $propiedad)) {
            $this->masivoFilas = $servicio->reclasificar($this->masivoFilas);
        }
    }

    public function elegirEtapa(string $etapa): void
    {
        // Volver a elegir la etapa activa quita el filtro.
        $this->filtroEtapa = $this->filtroEtapa === $etapa || ! array_key_exists($etapa, GestionCandidatos::ETAPAS) ? '' : $etapa;
        $this->limite = 20;
    }

    public function verHoy(): void
    {
        $this->filtroDia = Carbon::now('America/Lima')->toDateString();
        $this->filtroMes = '';
        $this->limite = 20;
    }

    public function limpiarFiltros(): void
    {
        $this->reset('buscar', 'filtroEtapa', 'filtroReclutador', 'filtroArea', 'filtroMes', 'filtroDia', 'soloAccion');
        $this->limite = 20;
    }

    public function verMas(): void
    {
        $this->limite += 20;
    }

    // ---------------------------------------------------------------
    // Dependencias del formulario (mismas reglas que la grilla)
    // ---------------------------------------------------------------

    /** $clave: 'f' (formulario del registro) o 'm' (datos comunes del registro masivo). */
    private function alCambiarArea(string $clave): void
    {
        $servicio = $this->servicio();
        $datos = $this->$clave;
        $datos['puesto'] = '';
        $datos['campana'] = $datos['area'] && ! $servicio->usaCampana($datos['area'], null) ? 'NO APLICA' : '';
        $datos['cargo'] = $datos['area'] && ! $servicio->usaCargo($datos['area']) ? 'NO APLICA' : '';
        $this->$clave = $datos;
    }

    private function ajustarCampana(string $clave): void
    {
        $datos = $this->$clave;
        $sinCampana = $datos['area'] && ! $this->servicio()->usaCampana($datos['area'], $datos['puesto'] ?: null);

        if ($sinCampana) {
            $datos['campana'] = 'NO APLICA';
        } elseif ($datos['campana'] === 'NO APLICA') {
            $datos['campana'] = '';
        }

        $this->$clave = $datos;
    }

    private function alCambiarTipificacion(): void
    {
        if (! in_array($this->f['tipificacion'], GestionCandidatos::RECHAZO, true)) {
            $this->f['subtipificacion_rechazo'] = '';
        }

        if (! in_array($this->f['tipificacion'], GestionCandidatos::VOLVER_A_LLAMAR, true)) {
            $this->f['volver_a_llamar_fecha'] = '';
            $this->f['volver_a_llamar_hora'] = '';
        }

        // Solo se agenda entrevista a quien está INTERESADO - APTO.
        if ($this->f['tipificacion'] !== 'INTERESADO - APTO' && $this->f['aceptacion_entrevista'] === 'SI') {
            $this->f['aceptacion_entrevista'] = '';
            $this->alCambiarAceptacion();
        }
    }

    private function alCambiarTipificacionLlamada(): void
    {
        if (! in_array($this->ll['tipificacion'], GestionCandidatos::RECHAZO, true)) {
            $this->ll['subtipificacion_rechazo'] = '';
        }

        if (! in_array($this->ll['tipificacion'], GestionCandidatos::VOLVER_A_LLAMAR, true)) {
            $this->ll['volver_a_llamar_fecha'] = '';
            $this->ll['volver_a_llamar_hora'] = '';
        }
    }

    private function alCambiarFechaEntrevista(): void
    {
        // Una entrevista que aún no llega solo puede quedar «reprogramada»: no tiene resultado.
        if ($this->f['fecha_entrevista'] > Carbon::now('America/Lima')->toDateString() && $this->f['asistio_entrevista'] !== 'REPROGRAMADO') {
            $this->f['asistio_entrevista'] = '';
        }
    }

    private function alCambiarAceptacion(): void
    {
        if ($this->f['aceptacion_entrevista'] !== 'SI') {
            foreach (['fecha_entrevista', 'hora_entrevista', 'asistio_entrevista', 'fecha_reprogramada', 'asistio_entrevista_reprogramada'] as $campo) {
                $this->f[$campo] = '';
            }
        }
    }

    private function alCambiarAsistio(): void
    {
        if ($this->f['asistio_entrevista'] !== 'REPROGRAMADO') {
            $this->f['fecha_reprogramada'] = '';
            $this->f['asistio_entrevista_reprogramada'] = '';
        }
    }

    private function alCambiarFechaReprogramada(): void
    {
        if ($this->f['fecha_reprogramada'] === '') {
            $this->f['asistio_entrevista_reprogramada'] = '';
        }
    }

    // ---------------------------------------------------------------
    // Abrir, guardar y eliminar un registro
    // ---------------------------------------------------------------

    private function formVacio(): array
    {
        $form = array_fill_keys(GestionCandidatos::FORMULARIO, '');
        $form['fecha_gestion'] = Carbon::now('America/Lima')->toDateString();
        $form['mes'] = $this->servicio()->mesDe($form['fecha_gestion']);

        return $form;
    }

    private function formDe(CandidatoReclutamiento $c): array
    {
        $form = [];
        foreach (GestionCandidatos::FORMULARIO as $campo) {
            $valor = $c->{$campo};
            $form[$campo] = $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : (string) ($valor ?? '');
        }

        return $form;
    }

    private function reiniciarPanel(): void
    {
        $this->resetErrorBag();
        $this->reset('candidatoId', 'soloLectura', 'bloqueadoPor', 'aviso', 'duplicadoId', 'llamadaAbierta', 'llamadaAviso');
        $this->ll = [];
        $this->f = [];
    }

    /** Suelta el bloqueo de edición del registro que se tenía abierto. */
    private function soltarBloqueo(): void
    {
        if ($this->candidatoId && ! $this->soloLectura) {
            $this->servicio()->liberarBloqueo($this->candidatoId);
        }
    }

    public function nuevo(): void
    {
        $this->soltarBloqueo();
        $this->reiniciarPanel();
        $this->mensaje = '';
        $this->f = $this->formVacio();
        $this->abierto = true;
    }

    public function abrir(int $id): void
    {
        $candidato = CandidatoReclutamiento::with('bloqueadoPor')->find($id);

        if (! $candidato) {
            return;
        }

        $this->soltarBloqueo();
        $this->reiniciarPanel();
        $this->mensaje = '';

        $servicio = $this->servicio();
        $this->candidatoId = $id;
        $this->f = $this->formDe($candidato);
        $this->soloLectura = $servicio->graduado($candidato);

        if (! $this->soloLectura) {
            if ($otro = $servicio->bloqueadoPorOtro($candidato)) {
                $this->bloqueadoPor = $otro;
                $this->soloLectura = true;
            } else {
                $servicio->tomarBloqueo($candidato);
            }
        }

        $this->abierto = true;
    }

    public function cerrar(): void
    {
        $this->soltarBloqueo();
        $this->reiniciarPanel();
        $this->abierto = false;
    }

    /** Mientras el panel está abierto se renueva el bloqueo para que nadie más edite el mismo registro. */
    #[Renderless]
    public function renovarBloqueo(): void
    {
        if ($this->abierto && $this->candidatoId && ! $this->soloLectura) {
            $this->servicio()->renovarBloqueo($this->candidatoId);
        }
    }

    public function guardar(): void
    {
        if ($this->soloLectura) {
            return;
        }

        $this->aviso = '';
        $this->duplicadoId = null;

        $this->validate([
            'f.dni_ce' => ['nullable', 'string', 'max:20'],
            'f.edad' => ['nullable', 'integer', 'min:0', 'max:99'],
            'f.nombres' => ['nullable', 'string', 'max:120'],
            'f.apellidos' => ['nullable', 'string', 'max:120'],
            'f.numero_celular' => ['nullable', 'string', 'max:60'],
            'f.distrito' => ['nullable', 'string', 'max:80'],
            'f.observaciones' => ['nullable', 'string', 'max:2000'],
            'f.fecha_gestion' => ['nullable', 'date'],
            'f.fecha_entrevista' => ['nullable', 'date'],
            'f.fecha_reprogramada' => ['nullable', 'date'],
            'f.hora_entrevista' => ['nullable', 'date_format:H:i'],
            'f.volver_a_llamar_fecha' => ['nullable', 'date'],
            'f.volver_a_llamar_hora' => ['nullable', 'date_format:H:i'],
        ], [], [
            'f.dni_ce' => 'DNI / C.E', 'f.edad' => 'edad', 'f.nombres' => 'nombres', 'f.apellidos' => 'apellidos',
            'f.numero_celular' => 'celular', 'f.distrito' => 'distrito', 'f.observaciones' => 'observaciones',
            'f.fecha_gestion' => 'fecha de gestión', 'f.fecha_entrevista' => 'fecha de entrevista',
            'f.fecha_reprogramada' => 'fecha reprogramada', 'f.hora_entrevista' => 'hora de entrevista',
            'f.volver_a_llamar_fecha' => 'fecha para volver a llamar', 'f.volver_a_llamar_hora' => 'hora para volver a llamar',
        ]);

        $errores = $this->servicio()->reglas($this->f, $this->candidatoId ? CandidatoReclutamiento::find($this->candidatoId) : null);

        if ($errores) {
            foreach ($errores as $campo => $mensaje) {
                $this->addError('f.'.$campo, $mensaje);
            }

            $this->aviso = 'Revisa los campos marcados.';

            return;
        }

        $resultado = $this->servicio()->guardar($this->f, $this->candidatoId);

        if (isset($resultado['error'])) {
            $this->aviso = $resultado['error'];
            $this->duplicadoId = $resultado['duplicadoId'] ?? null;

            return;
        }

        $nombre = trim(($this->f['nombres'] ?? '').' '.($this->f['apellidos'] ?? ''));
        $this->mensaje = ($this->candidatoId ? 'Cambios guardados' : 'Candidato registrado').($nombre !== '' ? ': '.Str::upper($nombre) : '').'.';

        $this->reiniciarPanel();
        $this->abierto = false;
    }

    // ---------------------------------------------------------------
    // Registro masivo
    // ---------------------------------------------------------------

    public function abrirMasivo(): void
    {
        $this->soltarBloqueo();
        $this->reiniciarPanel();
        $this->abierto = false;
        $this->mensaje = '';
        $this->m = array_fill_keys(['agente_reclutador', 'area', 'puesto', 'campana', 'cargo', 'lista'], '') + ['fecha_gestion' => Carbon::now('America/Lima')->toDateString()];
        $this->masivoFilas = [];
        $this->masivoAviso = '';
        $this->masivoAbierto = true;
    }

    public function cerrarMasivo(): void
    {
        $this->masivoAbierto = false;
        $this->m = [];
        $this->masivoFilas = [];
        $this->masivoAviso = '';
    }

    public function revisarLista(): void
    {
        $this->masivoAviso = '';
        $this->validate(['m.fecha_gestion' => ['required', 'date', 'before_or_equal:today']], [], ['m.fecha_gestion' => 'fecha de gestión']);

        $filas = $this->servicio()->analizarLista($this->m['lista'] ?? '');

        if (! $filas) {
            $this->masivoAviso = 'Pega al menos una línea con un nombre, un celular, un DNI o un usuario.';

            return;
        }

        $this->masivoFilas = $filas;
    }

    public function volverALista(): void
    {
        $this->masivoFilas = [];
        $this->masivoAviso = '';
    }

    public function registrarMasivo(): void
    {
        $this->masivoAviso = '';

        if (! collect($this->masivoFilas)->contains(fn ($fila) => ! empty($fila['incluir']))) {
            $this->masivoAviso = 'Marca al menos un candidato para registrar.';

            return;
        }

        $resultado = $this->servicio()->crearMasivo($this->masivoFilas, collect($this->m)->except('lista')->all());

        $this->mensaje = 'Registro masivo: '.$resultado['creados'].' '.($resultado['creados'] === 1 ? 'candidato registrado' : 'candidatos registrados')
            .($resultado['omitidos'] > 0 ? ' · '.$resultado['omitidos'].' omitidos (ya existían, repetidos o sin marcar).' : '.');

        $this->cerrarMasivo();
    }

    public function abrirLlamada(): void
    {
        if (! $this->candidatoId || $this->soloLectura) {
            return;
        }

        $this->llamadaAviso = '';
        $this->ll = array_fill_keys(['tipificacion', 'subtipificacion_rechazo', 'volver_a_llamar_fecha', 'volver_a_llamar_hora', 'nota'], '');
        $this->llamadaAbierta = true;
    }

    public function cerrarLlamada(): void
    {
        $this->llamadaAbierta = false;
        $this->llamadaAviso = '';
        $this->ll = [];
    }

    public function guardarLlamada(): void
    {
        if (! $this->candidatoId || $this->soloLectura) {
            return;
        }

        $this->llamadaAviso = '';
        $this->validate([
            'll.nota' => ['nullable', 'string', 'max:1000'],
            'll.volver_a_llamar_fecha' => ['nullable', 'date'],
            'll.volver_a_llamar_hora' => ['nullable', 'date_format:H:i'],
        ], [], ['ll.nota' => 'nota', 'll.volver_a_llamar_fecha' => 'fecha para volver a llamar', 'll.volver_a_llamar_hora' => 'hora para volver a llamar']);

        $resultado = $this->servicio()->registrarLlamada($this->candidatoId, $this->ll);

        if (isset($resultado['error'])) {
            $this->llamadaAviso = $resultado['error'];

            return;
        }

        // El formulario sigue abierto con lo que se hubiera escrito: solo se refrescan los datos de la llamada.
        $candidato = CandidatoReclutamiento::find($this->candidatoId);
        $nuevo = $candidato ? $this->formDe($candidato) : [];
        foreach (['tipificacion', 'subtipificacion_rechazo', 'volver_a_llamar_fecha', 'volver_a_llamar_hora'] as $campo) {
            $this->f[$campo] = $nuevo[$campo] ?? '';
        }

        $pendiente = in_array($this->f['tipificacion'], GestionCandidatos::VOLVER_A_LLAMAR, true) && $this->f['volver_a_llamar_fecha'] !== '';
        $this->mensaje = 'Llamada registrada'.($pendiente
            ? ' · volver a llamar el '.Carbon::parse($this->f['volver_a_llamar_fecha'])->format('d/m/Y').($this->f['volver_a_llamar_hora'] !== '' ? ' '.$this->f['volver_a_llamar_hora'] : '')
            : '').'.';
        $this->cerrarLlamada();
    }

    public function abrirDuplicado(): void
    {
        if ($this->duplicadoId) {
            $this->abrir($this->duplicadoId);
        }
    }

    public function eliminar(): void
    {
        if ($this->soloLectura || ! $this->candidatoId) {
            return;
        }

        $error = $this->servicio()->eliminar($this->candidatoId);

        if ($error) {
            $this->aviso = $error;

            return;
        }

        $this->mensaje = 'Registro eliminado.';
        $this->reiniciarPanel();
        $this->abierto = false;
    }

    // ---------------------------------------------------------------
    // Datos de la pantalla
    // ---------------------------------------------------------------

    /** Opciones [valor => texto] que conservan el valor actual aunque venga de un dato antiguo fuera de la lista. */
    private function opciones(array $lista, ?string $actual): array
    {
        $opciones = array_combine($lista, $lista) ?: [];

        if (filled($actual) && ! array_key_exists($actual, $opciones)) {
            $opciones = [$actual => $actual] + $opciones;
        }

        return $opciones;
    }

    private function deArea(array $mapa, ?string $area): array
    {
        foreach ($mapa as $nombre => $lista) {
            if (mb_strtoupper((string) $nombre) === mb_strtoupper((string) $area)) {
                return array_map('mb_strtoupper', $lista);
            }
        }

        return [];
    }

    private function normalizar(string $texto): string
    {
        return Str::ascii(mb_strtolower($texto));
    }

    private function coincide(CandidatoReclutamiento $c, string $buscado): bool
    {
        $nombre = $this->normalizar($c->nombreCompleto().' '.$c->dni_ce);
        $telefono = preg_replace('/\D+/', '', (string) $c->numero_celular);

        foreach (array_filter(preg_split('/\s+/u', $this->normalizar(trim($buscado)))) as $palabra) {
            $esNumero = ctype_digit($palabra);

            if (! str_contains($nombre, $palabra) && ! ($esNumero && str_contains($telefono, $palabra))) {
                return false;
            }
        }

        return true;
    }

    public function datos(): array
    {
        $servicio = $this->servicio();
        $catalogos = $this->catalogos();

        $todos = CandidatoReclutamiento::query()->orderByDesc('id')->get()->map(fn ($c) => [
            'c' => $c,
            'etapa' => $servicio->etapa($c),
            'accion' => $servicio->accion($c),
            'avance' => $servicio->avance($c),
            'fecha' => $c->fecha_gestion?->toDateString() ?? '',
        ]);

        $meses = $todos->pluck('c.fecha_gestion')->filter()->map(fn ($f) => $f->format('Y-m'))->unique()->sortDesc()->values()
            ->mapWithKeys(fn ($m) => [$m => ucfirst(Carbon::createFromFormat('Y-m-d', $m.'-01')->locale('es')->translatedFormat('F Y'))])->all();

        $sinEtapa = $todos->filter(function ($fila) {
            $c = $fila['c'];

            return ($this->buscar === '' || $this->coincide($c, $this->buscar))
                && ($this->filtroReclutador === '' || mb_strtoupper((string) $c->agente_reclutador) === $this->filtroReclutador)
                && ($this->filtroArea === '' || mb_strtoupper((string) $c->area) === $this->filtroArea)
                && ($this->filtroMes === '' || $c->fecha_gestion?->format('Y-m') === $this->filtroMes)
                && ($this->filtroDia === '' || $c->fecha_gestion?->toDateString() === $this->filtroDia)
                && (! $this->soloAccion || $fila['accion']['orden'] <= 1);
        });

        $conteo = $sinEtapa->countBy('etapa');

        $etapas = [['clave' => '', 'nombre' => 'Todos', 'total' => $sinEtapa->count(), 'nota' => 'registros']];
        foreach (GestionCandidatos::ETAPAS as $clave => $nombre) {
            $etapas[] = [
                'clave' => $clave,
                'nombre' => $nombre,
                'total' => $conteo[$clave] ?? 0,
                'nota' => $sinEtapa->where('etapa', $clave)->filter(fn ($f) => $f['accion']['orden'] <= 1)->count().' con acción',
            ];
        }

        $filas = $sinEtapa
            ->when($this->filtroEtapa !== '', fn ($lista) => $lista->where('etapa', $this->filtroEtapa))
            ->sortBy($this->orden === 'prioridad'
                ? [fn ($a, $b) => $a['accion']['orden'] <=> $b['accion']['orden'], fn ($a, $b) => $b['c']->id <=> $a['c']->id]
                // Por fecha de registro: el día más reciente primero y, dentro del día, lo último que se registró arriba.
                : [fn ($a, $b) => ($b['fecha'] ?: '0000') <=> ($a['fecha'] ?: '0000'), fn ($a, $b) => $b['c']->id <=> $a['c']->id])
            ->values();

        $hoy = Carbon::now('America/Lima')->toDateString();
        $etiquetaDia = function (string $dia) use ($hoy): string {
            if ($dia === '') {
                return 'Sin fecha de gestión';
            }

            $fecha = Carbon::parse($dia)->locale('es');
            $texto = ucfirst($fecha->translatedFormat('l j \d\e F').($fecha->year !== (int) substr($hoy, 0, 4) ? ' '.$fecha->year : ''));

            return match (true) {
                $dia === $hoy => 'Hoy · '.$texto,
                $dia === Carbon::parse($hoy)->subDay()->toDateString() => 'Ayer · '.$texto,
                default => $texto,
            };
        };

        $pagina = $filas->take($this->limite);
        $grupos = $this->orden === 'fecha'
            ? $pagina->groupBy('fecha')->map(function ($items, $dia) use ($filas, $etiquetaDia) {
                $delDia = $filas->where('fecha', (string) $dia);

                return ['clave' => (string) $dia, 'titulo' => $etiquetaDia((string) $dia), 'total' => $delDia->count(), 'accion' => $delDia->filter(fn ($f) => $f['accion']['orden'] <= 1)->count(), 'filas' => $items];
            })->values()->all()
            : [['clave' => 'todos', 'titulo' => null, 'total' => $filas->count(), 'accion' => 0, 'filas' => $pagina]];

        $prioritarios = $sinEtapa->filter(fn ($f) => $f['accion']['orden'] <= 1)
            ->sortBy([fn ($a, $b) => $a['accion']['orden'] <=> $b['accion']['orden'], fn ($a, $b) => $b['fecha'] <=> $a['fecha']])->values();

        $f = $this->f;
        $m = $this->m;
        $hoy = Carbon::now('America/Lima')->toDateString();
        $actual = $this->candidatoId ? CandidatoReclutamiento::find($this->candidatoId) : null;
        $area = $f['area'] ?? '';

        return [
            'etapas' => $etapas,
            'total' => $filas->count(),
            'filas' => $pagina,
            'grupos' => $grupos,
            'prioritarios' => $prioritarios->take(6),
            'prioritariosTotal' => $prioritarios->count(),
            'conAccionHoy' => $todos->filter(fn ($fila) => $fila['accion']['orden'] <= 1)->count(),
            'reclutadores' => $this->opciones(array_values(array_unique(array_merge($catalogos['reclutadores'], $todos->pluck('c.agente_reclutador')->filter()->map(fn ($n) => mb_strtoupper($n))->all()))), $this->filtroReclutador ?: null),
            'areas' => $this->opciones(array_map('mb_strtoupper', $catalogos['areas']), $this->filtroArea ?: null),
            'meses' => $meses,
            'hayFiltros' => $this->buscar !== '' || $this->filtroEtapa !== '' || $this->filtroReclutador !== '' || $this->filtroArea !== '' || $this->filtroMes !== '' || $this->filtroDia !== '' || $this->soloAccion,
            'masivo' => $this->masivoAbierto ? [
                'reclutadores' => $this->opciones($catalogos['reclutadores'], $m['agente_reclutador'] ?? null),
                'areas' => $this->opciones(array_map('mb_strtoupper', $catalogos['areas']), $m['area'] ?? null),
                'puestos' => $this->opciones($this->deArea($catalogos['puestos'], $m['area'] ?? ''), $m['puesto'] ?? null),
                'campanas' => $this->opciones($this->deArea($catalogos['campanas'], $m['area'] ?? ''), $m['campana'] ?? null),
                'cargos' => $this->opciones($this->deArea($catalogos['cargos'], $m['area'] ?? ''), $m['cargo'] ?? null),
                'sinCampana' => ($m['area'] ?? '') !== '' && ! $servicio->usaCampana($m['area'], ($m['puesto'] ?? '') ?: null),
                'sinCargo' => ($m['area'] ?? '') !== '' && ! $servicio->usaCargo($m['area']),
                'incluidos' => collect($this->masivoFilas)->where('incluir', true)->count(),
                'nuevos' => collect($this->masivoFilas)->where('estado', 'nuevo')->count(),
                'existen' => collect($this->masivoFilas)->whereIn('estado', ['existe', 'repetido'])->count(),
                'parecidos' => collect($this->masivoFilas)->where('estado', 'parecido')->count(),
            ] : null,
            'form' => [
                'reclutadores' => $this->opciones($catalogos['reclutadores'], $f['agente_reclutador'] ?? null),
                'areas' => $this->opciones(array_map('mb_strtoupper', $catalogos['areas']), $area),
                'puestos' => $this->opciones($this->deArea($catalogos['puestos'], $area), $f['puesto'] ?? null),
                'campanas' => $this->opciones($this->deArea($catalogos['campanas'], $area), $f['campana'] ?? null),
                'cargos' => $this->opciones($this->deArea($catalogos['cargos'], $area), $f['cargo'] ?? null),
                'distritos' => $this->opciones(GestionCandidatos::DISTRITOS, $f['distrito'] ?? null),
                'tipificaciones' => $this->opciones(GestionCandidatos::TIPIFICACIONES, $f['tipificacion'] ?? null),
                'motivos' => $this->opciones(GestionCandidatos::MOTIVOS_RECHAZO, $f['subtipificacion_rechazo'] ?? null),
                'asistio' => $this->opciones(($f['fecha_entrevista'] ?? '') > $hoy ? ['REPROGRAMADO'] : GestionCandidatos::ASISTIO, $f['asistio_entrevista'] ?? null),
                'asistioRep' => $this->opciones(GestionCandidatos::ASISTIO_REPROGRAMADA, $f['asistio_entrevista_reprogramada'] ?? null),
                'volver' => in_array($f['tipificacion'] ?? '', GestionCandidatos::VOLVER_A_LLAMAR, true),
                'interesado' => ($f['tipificacion'] ?? '') === 'INTERESADO - APTO' || ($f['aceptacion_entrevista'] ?? '') !== '',
                'conFechaEntrevista' => ($f['fecha_entrevista'] ?? '') !== '',
                'entrevistaFutura' => ($f['fecha_entrevista'] ?? '') > $hoy,
                'repFutura' => ($f['fecha_reprogramada'] ?? '') > $hoy,
                'sinCampana' => $area !== '' && ! $servicio->usaCampana($area, ($f['puesto'] ?? '') ?: null),
                'sinCargo' => $area !== '' && ! $servicio->usaCargo($area),
                'rechazo' => in_array($f['tipificacion'] ?? '', GestionCandidatos::RECHAZO, true),
                'entrevistaHabilitada' => trim((string) ($f['nombres'] ?? '')) !== '',
                'acepto' => ($f['aceptacion_entrevista'] ?? '') === 'SI',
                'reprogramada' => ($f['asistio_entrevista'] ?? '') === 'REPROGRAMADO',
                'conFechaRep' => ($f['fecha_reprogramada'] ?? '') !== '',
            ],
            'llamada' => $this->llamadaAbierta ? [
                'tipificaciones' => $this->opciones(GestionCandidatos::TIPIFICACIONES, null),
                'motivos' => $this->opciones(GestionCandidatos::MOTIVOS_RECHAZO, null),
                'rechazo' => in_array($this->ll['tipificacion'] ?? '', GestionCandidatos::RECHAZO, true),
                'pendiente' => in_array($this->ll['tipificacion'] ?? '', GestionCandidatos::VOLVER_A_LLAMAR, true),
            ] : null,
            'historial' => $actual ? $servicio->historial($actual->id) : [],
            'actual' => $actual ? [
                'intentos' => (int) $actual->intentos_contacto,
                'ultimoContacto' => $actual->ultimo_contacto_at?->copy()->timezone('America/Lima')->format('d/m/Y H:i'),
                'volverA' => $actual->volver_a_llamar_fecha ? $actual->volver_a_llamar_fecha->format('d/m/Y').($actual->volver_a_llamar_hora ? ' '.$actual->volver_a_llamar_hora : '') : null,
                'nombre' => $actual->nombreCompleto(),
                'etapa' => $servicio->etapa($actual),
                'accion' => $servicio->accion($actual),
                'graduado' => $servicio->graduado($actual),
                'eliminable' => $servicio->eliminable($actual),
                'capacitacion' => collect([
                    ['Capacitación 1', $actual->capacitacion_1, $actual->asistio_cap_1, $actual->capacitacion_1_reprogramada, $actual->asistio_cap_1_reprogramada],
                    ['Capacitación 2', $actual->capacitacion_2, $actual->asistio_cap_2, $actual->capacitacion_2_reprogramada, $actual->asistio_cap_2_reprogramada],
                ])->filter(fn ($fila) => $fila[1] || $fila[2])->all(),
                'apto' => (bool) $actual->apto,
            ] : null,
        ];
    }
};
?>

<div class="space-y-5" wire:key="gestion-candidatos">
    @php($d = $this->datos())

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-center gap-3">
        <p class="min-w-0 flex-1 text-sm text-slate-500">
            Los registros se ordenan por <b class="text-slate-700">fecha de registro</b>, el día más reciente primero; los que necesitan acción se resaltan y se muestran arriba.
        </p>
        <button type="button" wire:click="abrirMasivo" class="gc-btn gc-btn-ghost">
            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" /></svg>
            Registro masivo
        </button>
        <button type="button" wire:click="nuevo" class="gc-btn gc-btn-primary">
            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
            Nuevo candidato
        </button>
    </div>

    @if ($mensaje !== '')
        <div class="gc-aviso bg-emerald-50 text-emerald-800" role="status">{{ $mensaje }}</div>
    @endif

    {{-- Etapas --}}
    <div class="gc-etapas">
        @foreach ($d['etapas'] as $etapa)
            <button type="button" wire:key="etapa-{{ $etapa['clave'] ?: 'todos' }}" wire:click="elegirEtapa('{{ $etapa['clave'] }}')" class="gc-etapa {{ $filtroEtapa === $etapa['clave'] ? 'gc-etapa-activa' : '' }}" aria-pressed="{{ $filtroEtapa === $etapa['clave'] ? 'true' : 'false' }}">
                <span class="block text-xs font-semibold text-slate-500">{{ $etapa['nombre'] }}</span>
                <span class="mt-1 block text-2xl font-bold text-slate-900">{{ $etapa['total'] }}</span>
                <span class="block text-xs text-slate-400">{{ $etapa['nota'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- Filtros --}}
    <div class="gc-filtros">
        <input type="search" wire:model.live.debounce.300ms="buscar" class="form-input min-w-[16rem] flex-1" placeholder="Buscar por nombre, DNI o celular" aria-label="Buscar candidato">
        <select wire:model.live="filtroReclutador" class="form-input" aria-label="Reclutador">
            <option value="">Reclutador: todos</option>
            @foreach ($d['reclutadores'] as $valor => $texto)
                <option value="{{ $valor }}">{{ $texto }}</option>
            @endforeach
        </select>
        <select wire:model.live="filtroArea" class="form-input" aria-label="Área">
            <option value="">Área: todas</option>
            @foreach ($d['areas'] as $valor => $texto)
                <option value="{{ $valor }}">{{ $texto }}</option>
            @endforeach
        </select>
        <select wire:model.live="filtroMes" class="form-input" aria-label="Mes de gestión">
            <option value="">Mes: todos</option>
            @foreach ($d['meses'] as $valor => $texto)
                <option value="{{ $valor }}">{{ $texto }}</option>
            @endforeach
        </select>
        <input type="date" wire:model.live="filtroDia" class="form-input" aria-label="Día de gestión">
        <button type="button" wire:click="verHoy" class="gc-btn gc-btn-ghost">Hoy</button>
        <label class="inline-flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" wire:model.live="soloAccion" class="h-4 w-4 rounded border-slate-300">
            Solo pendientes de acción
        </label>
        <span class="ml-auto inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="group" aria-label="Orden de la lista">
            <button type="button" wire:click="$set('orden', 'fecha')" class="rounded-md px-3 py-1 font-semibold {{ $orden === 'fecha' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500' }}" aria-pressed="{{ $orden === 'fecha' ? 'true' : 'false' }}">Por fecha</button>
            <button type="button" wire:click="$set('orden', 'prioridad')" class="rounded-md px-3 py-1 font-semibold {{ $orden === 'prioridad' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500' }}" aria-pressed="{{ $orden === 'prioridad' ? 'true' : 'false' }}">Por prioridad</button>
        </span>
        @if ($d['hayFiltros'])
            <button type="button" wire:click="limpiarFiltros" class="text-sm font-semibold text-primary">Limpiar filtros</button>
        @endif
    </div>

    {{-- Requieren acción --}}
    @if ($d['prioritariosTotal'] > 0 && $orden === 'fecha' && ! $soloAccion)
        <section class="gc-atencion" aria-label="Requieren acción">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-sm font-bold text-amber-900">Requieren acción ({{ $d['prioritariosTotal'] }})</h2>
                <span class="text-xs text-amber-800">Entrevistas de hoy, vencidas o con el resultado pendiente.</span>
                @if ($d['prioritariosTotal'] > $d['prioritarios']->count())
                    <button type="button" wire:click="$set('soloAccion', true)" class="ml-auto text-xs font-semibold text-amber-900 underline">Ver todos</button>
                @endif
            </div>
            <div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($d['prioritarios'] as $p)
                    <button type="button" wire:key="prio-{{ $p['c']->id }}" wire:click="abrir({{ $p['c']->id }})" class="gc-atencion-item">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-slate-900">{{ $p['c']->nombreCompleto() ?: ($p['c']->numero_celular ?: 'Sin nombre') }}</span>
                            <span class="block truncate text-xs text-slate-500">Registrado {{ $p['c']->fecha_gestion?->format('d/m') ?: 'sin fecha' }}</span>
                        </span>
                        <span class="badge badge-{{ $p['accion']['tono'] }} shrink-0">{{ $p['accion']['texto'] }}</span>
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Registros --}}
    <div class="gc-lista">
        @forelse ($d['grupos'] as $grupo)
            @if ($grupo['titulo'])
                <div wire:key="dia-{{ $grupo['clave'] }}" class="gc-dia">
                    <span class="font-bold text-slate-800">{{ $grupo['titulo'] }}</span>
                    <span class="text-xs text-slate-500">{{ $grupo['total'] }} {{ $grupo['total'] === 1 ? 'registro' : 'registros' }}</span>
                    @if ($grupo['accion'] > 0)
                        <span class="badge badge-amber">{{ $grupo['accion'] }} con acción</span>
                    @endif
                </div>
            @endif
            @foreach ($grupo['filas'] as $fila)
            @php($c = $fila['c'])
            @php($a = $fila['accion'])
            <button type="button" wire:key="cand-{{ $c->id }}" wire:click="abrir({{ $c->id }})" class="gc-card {{ $fila['etapa'] === 'apto' ? 'gc-card-apto' : '' }} {{ $fila['accion']['orden'] <= 1 ? 'gc-card-accion' : '' }}">
                <span class="gc-avatar gc-av-{{ $fila['etapa'] }}" aria-hidden="true">{{ Str::upper(Str::substr((string) $c->nombres, 0, 1).Str::substr((string) $c->apellidos, 0, 1)) ?: '?' }}</span>

                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-slate-900">{{ $c->nombreCompleto() ?: 'Sin nombre' }}</span>
                    <span class="block truncate text-xs text-slate-500">{{ collect([$c->dni_ce ? 'DNI '.$c->dni_ce : null, $c->numero_celular, $c->distrito])->filter()->implode(' · ') ?: 'Sin datos de contacto' }}</span>
                </span>

                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-slate-700">{{ $c->puesto ?: 'Sin puesto' }}</span>
                    <span class="block truncate text-xs text-slate-500">{{ collect([$c->campana && $c->campana !== 'NO APLICA' ? $c->campana : null, $c->agente_reclutador, $c->fecha_gestion?->format('d/m')])->filter()->implode(' · ') }}</span>
                </span>

                <span class="min-w-0">
                    <span class="gc-pasos" title="Gestión · Entrevista · Capacitación · Apto">
                        @foreach ([1, 2, 3, 4] as $paso)
                            <span class="gc-paso {{ $paso <= $fila['avance'] ? ($fila['etapa'] === 'no_continua' ? 'gc-paso-no' : 'gc-paso-ok') : '' }}"></span>
                        @endforeach
                    </span>
                    <span class="mt-1 block text-xs font-semibold text-slate-600">{{ GestionCandidatos::ETAPAS[$fila['etapa']] }}</span>
                </span>

                <span class="min-w-0">
                    <span class="badge badge-{{ $a['tono'] === 'blue' ? 'blue' : $a['tono'] }}">{{ $a['texto'] }}</span>
                    @if ($a['detalle'] !== '')
                        <span class="mt-1 block truncate text-xs text-slate-400">{{ $a['detalle'] }}</span>
                    @endif
                </span>
            </button>
            @endforeach
        @empty
            <div class="rounded-2xl bg-white px-6 py-12 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-100">
                No hay candidatos con estos filtros.
            </div>
        @endforelse
    </div>

    @if ($d['total'] > $d['filas']->count())
        <div class="flex items-center justify-center gap-3 text-sm text-slate-500">
            Mostrando {{ $d['filas']->count() }} de {{ $d['total'] }}
            <button type="button" wire:click="verMas" class="gc-btn gc-btn-ghost">Ver más</button>
        </div>
    @endif

    {{-- Panel del registro --}}
    @if ($abierto)
        @php($fm = $d['form'])
        @php($ac = $d['actual'])
        <div class="gc-drawer" wire:key="gc-panel" wire:click.self="cerrar" wire:keydown.escape.window="cerrar">
            <div class="gc-drawer-panel" role="dialog" aria-modal="true" aria-label="Registro del candidato">
                @if ($candidatoId && ! $soloLectura)
                    <div wire:poll.20s="renovarBloqueo" class="hidden" aria-hidden="true"></div>
                @endif

                <div class="flex shrink-0 items-start gap-4 border-b border-slate-200 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-lg font-bold text-slate-900">{{ $ac ? ($ac['nombre'] ?: 'Sin nombre') : 'Nuevo candidato' }}</h2>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            @if ($ac)
                                <span class="badge badge-slate">{{ GestionCandidatos::ETAPAS[$ac['etapa']] }}</span>
                                <span class="badge badge-{{ $ac['accion']['tono'] }}">{{ $ac['accion']['texto'] }}</span>
                            @else
                                <span class="text-sm text-slate-500">Registra la llamada inicial; la entrevista se habilita al escribir el nombre.</span>
                            @endif
                        </div>
                    </div>
                    <button type="button" wire:click="cerrar" class="gc-btn gc-btn-ghost" aria-label="Cerrar">Cerrar ✕</button>
                </div>

                <div class="gc-drawer-body gc-form">
                    @if ($ac && $ac['graduado'])
                        <div class="gc-aviso bg-indigo-50 text-indigo-800">
                            <p class="font-semibold">Este candidato ya pasó a capacitación.</p>
                            <p class="mt-0.5">Su gestión ya no se edita aquí: el avance se registra en «Proceso de reclutamiento → Proceso de capacitación».</p>
                            @if (! empty($ac['capacitacion']))
                                <ul class="mt-2 space-y-0.5 text-xs">
                                    @foreach ($ac['capacitacion'] as [$nombre, $fecha, $asistio, $fechaRep, $asistioRep])
                                        <li><span class="font-semibold">{{ $nombre }}:</span> {{ $fecha?->format('d/m/Y') ?: 'sin fecha' }} · {{ $asistio ?: 'sin resultado' }}@if ($fechaRep) · reprogramada {{ $fechaRep->format('d/m/Y') }} ({{ $asistioRep ?: 'sin resultado' }})@endif</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @elseif ($bloqueadoPor)
                        <div class="gc-aviso bg-amber-50 text-amber-800">
                            <span class="font-semibold">{{ $bloqueadoPor }}</span> está editando este registro. Se muestra solo de lectura.
                        </div>
                    @endif

                    @if ($ac)
                        <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                <span class="text-slate-500">Tipificación: <b class="text-slate-800">{{ $f['tipificacion'] !== '' ? $f['tipificacion'] : 'sin tipificar' }}</b>@if ($f['subtipificacion_rechazo'] !== '') <span class="text-slate-500"> — {{ $f['subtipificacion_rechazo'] }}</span>@endif</span>
                                <span class="font-semibold text-slate-800">{{ $ac['intentos'] }} {{ $ac['intentos'] === 1 ? 'intento de contacto' : 'intentos de contacto' }}</span>
                                @if ($ac['ultimoContacto'])
                                    <span class="text-slate-500">Última llamada: {{ $ac['ultimoContacto'] }}</span>
                                @endif
                                @if ($ac['volverA'])
                                    <span class="badge badge-amber">Volver a llamar el {{ $ac['volverA'] }}</span>
                                @endif
                                @if (! $soloLectura && ! $llamadaAbierta)
                                    <button type="button" wire:click="abrirLlamada" class="gc-btn gc-btn-primary ml-auto">Registrar llamada</button>
                                @endif
                            </div>

                            @if ($llamadaAbierta && ! $soloLectura)
                                @php($ll2 = $d['llamada'])
                                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <x-forms.select label="Resultado de la llamada" name="ll.tipificacion" :options="$ll2['tipificaciones']" live :span="! $ll2['rechazo'] && ! $ll2['pendiente']" />
                                    @if ($ll2['rechazo'])
                                        <x-forms.select label="Motivo del rechazo" name="ll.subtipificacion_rechazo" :options="$ll2['motivos']" />
                                    @endif
                                    @if ($ll2['pendiente'])
                                        <div class="grid grid-cols-2 gap-4">
                                            <x-forms.field label="Volver a llamar el" name="ll.volver_a_llamar_fecha" type="date" />
                                            <x-forms.field label="Hora (opcional)" name="ll.volver_a_llamar_hora" type="time" />
                                        </div>
                                    @endif
                                    <x-forms.textarea label="Nota (opcional)" name="ll.nota" :span="true" :rows="2" />
                                </div>
                                @if ($llamadaAviso !== '')
                                    <div class="gc-aviso mt-3 bg-rose-50 text-rose-700" role="alert">{{ $llamadaAviso }}</div>
                                @endif
                                <div class="mt-3 flex gap-2">
                                    <button type="button" wire:click="guardarLlamada" wire:loading.attr="disabled" wire:target="guardarLlamada" class="gc-btn gc-btn-primary">
                                        <span wire:loading.remove wire:target="guardarLlamada">Guardar llamada</span>
                                        <span wire:loading wire:target="guardarLlamada">Guardando…</span>
                                    </button>
                                    <button type="button" wire:click="cerrarLlamada" class="gc-btn gc-btn-ghost">Cancelar</button>
                                </div>
                            @endif
                        </div>
                    @endif

                    <fieldset class="contents" @disabled($soloLectura)>
                        <x-forms.section title="Datos personales">
                            <x-forms.field label="DNI / C.E" name="f.dni_ce" />
                            <x-forms.field label="Edad" name="f.edad" type="number" min="0" max="99" />
                            <x-forms.field label="Nombres" name="f.nombres" live />
                            <x-forms.field label="Apellidos" name="f.apellidos" />
                            <x-forms.field label="Celular o usuario de WhatsApp" name="f.numero_celular" />
                            <x-forms.select label="Distrito" name="f.distrito" :options="$fm['distritos']" />
                        </x-forms.section>

                        <x-forms.section title="Gestión">
                            <x-forms.field label="Fecha de gestión" name="f.fecha_gestion" type="date" live />
                            <x-forms.field label="Mes" name="f.mes" :readonly="true" hint="Se calcula con la fecha de gestión." />
                            <x-forms.select label="Agente reclutador" name="f.agente_reclutador" :options="$fm['reclutadores']" span />
                            <x-forms.select label="Área" name="f.area" :options="$fm['areas']" live />
                            <x-forms.select label="Puesto" name="f.puesto" :options="$fm['puestos']" live />
                            @if ($fm['sinCampana'])
                                <x-forms.field label="Campaña" name="f.campana" :readonly="true" hint="Este puesto no lleva campaña." />
                            @else
                                <x-forms.select label="Campaña" name="f.campana" :options="$fm['campanas']" />
                            @endif
                            @if ($fm['sinCargo'])
                                <x-forms.field label="Cargo" name="f.cargo" :readonly="true" hint="Esta área no lleva cargo." />
                            @else
                                <x-forms.select label="Cargo" name="f.cargo" :options="$fm['cargos']" />
                            @endif
                            <x-forms.textarea label="Observaciones" name="f.observaciones" :span="true" :rows="2" />
                        </x-forms.section>

                        @unless ($candidatoId)
                        <x-forms.section title="Llamada">
                            <x-forms.select label="Tipificación" name="f.tipificacion" :options="$fm['tipificaciones']" live :span="! $fm['rechazo'] && ! $fm['volver']" />
                            @if ($fm['rechazo'])
                                <x-forms.select label="Motivo del rechazo" name="f.subtipificacion_rechazo" :options="$fm['motivos']" />
                            @endif
                            @if ($fm['volver'])
                                <div class="grid grid-cols-2 gap-4">
                                    <x-forms.field label="Volver a llamar el" name="f.volver_a_llamar_fecha" type="date" />
                                    <x-forms.field label="Hora (opcional)" name="f.volver_a_llamar_hora" type="time" />
                                </div>
                            @endif
                        </x-forms.section>
                        @endunless

                        <fieldset class="contents" @disabled(! $fm['entrevistaHabilitada'])>
                            <x-forms.section title="Entrevista">
                                @unless ($fm['entrevistaHabilitada'])
                                    <p class="text-sm text-slate-400 sm:col-span-2">Escribe los nombres del candidato para registrar su entrevista.</p>
                                @endunless
                                @if ($fm['interesado'])
                                    <x-forms.select label="¿Aceptó la entrevista?" name="f.aceptacion_entrevista" :options="['SI' => 'SI', 'NO' => 'NO']" live :span="! $fm['acepto']" />
                                @elseif ($fm['entrevistaHabilitada'])
                                    <p class="text-sm text-slate-400 sm:col-span-2">La entrevista se agenda cuando la última llamada quedó como INTERESADO - APTO.</p>
                                @endif
                                @if ($fm['acepto'])
                                    <div class="grid grid-cols-2 gap-4">
                                        <x-forms.field label="Fecha" name="f.fecha_entrevista" type="date" live />
                                        <x-forms.field label="Hora" name="f.hora_entrevista" type="time" />
                                    </div>
                                    @if ($fm['conFechaEntrevista'])
                                        <x-forms.select label="¿Asistió a la entrevista?" name="f.asistio_entrevista" :options="$fm['asistio']" live :span="! $fm['reprogramada']" />
                                        @if ($fm['entrevistaFutura'])
                                            <p class="text-xs text-slate-400 sm:col-span-2">La asistencia se marca desde el día de la entrevista; mientras tanto solo se puede reprogramar.</p>
                                        @endif
                                        @if ($fm['reprogramada'])
                                            <x-forms.field label="Fecha reprogramada" name="f.fecha_reprogramada" type="date" live />
                                            @if ($fm['conFechaRep'] && ! $fm['repFutura'])
                                                <x-forms.select label="¿Asistió a la reprogramada?" name="f.asistio_entrevista_reprogramada" :options="$fm['asistioRep']" :span="true" />
                                            @elseif ($fm['conFechaRep'])
                                                <p class="text-xs text-slate-400 sm:col-span-2">La asistencia a la entrevista reprogramada se marca desde su fecha.</p>
                                            @endif
                                        @endif
                                    @else
                                        <p class="text-xs text-slate-400 sm:col-span-2">Indica la fecha de la entrevista para registrar su asistencia.</p>
                                    @endif
                                @endif
                            </x-forms.section>
                        </fieldset>
                    </fieldset>

                    @if ($candidatoId)
                        <section aria-label="Historial">
                            <h3 class="form-section-title mb-3">Historial</h3>
                            @forelse ($d['historial'] as $evento)
                                <div wire:key="ev-{{ $loop->index }}" class="relative border-l-2 border-slate-200 pb-4 pl-4 last:pb-0">
                                    <span class="absolute -left-[5px] top-1.5 h-2 w-2 rounded-full {{ $evento['tipo'] === 'llamada' ? 'bg-amber-500' : ($evento['tipo'] === 'alta' ? 'bg-emerald-500' : 'bg-primary') }}"></span>
                                    <p class="text-sm font-medium text-slate-800">{{ $evento['texto'] }}</p>
                                    @if ($evento['nota'])
                                        <p class="mt-0.5 text-sm text-slate-600">«{{ $evento['nota'] }}»</p>
                                    @endif
                                    <p class="text-xs text-slate-400">{{ $evento['fecha'] }} · {{ $evento['usuario'] }}</p>
                                </div>
                            @empty
                                <p class="text-sm text-slate-400">Todavía no hay cambios registrados. El historial empieza desde que se activó.</p>
                            @endforelse
                        </section>
                    @endif
                </div>

                <div class="gc-drawer-pie">
                    @if ($aviso !== '')
                        <div class="gc-aviso w-full bg-rose-50 text-rose-700" role="alert">
                            {{ $aviso }}
                            @if ($duplicadoId)
                                <button type="button" wire:click="abrirDuplicado" class="ml-1 font-semibold underline">Abrir ese registro</button>
                            @endif
                        </div>
                    @endif

                    @if (! $soloLectura)
                        <button type="button" wire:click="guardar" wire:loading.attr="disabled" wire:target="guardar" class="gc-btn gc-btn-primary">
                            <span wire:loading.remove wire:target="guardar">Guardar</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    @endif
                    <button type="button" wire:click="cerrar" class="gc-btn gc-btn-ghost">{{ $soloLectura ? 'Cerrar' : 'Cancelar' }}</button>

                    @if ($ac && $ac['eliminable'] && ! $soloLectura)
                        <button type="button" wire:click="eliminar" wire:confirm="¿Eliminar este registro vacío?" class="gc-btn gc-btn-danger ml-auto">Eliminar</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Registro masivo --}}
    @if ($masivoAbierto)
        @php($mv = $d['masivo'])
        <div class="gc-drawer" wire:key="gc-masivo" wire:click.self="cerrarMasivo" wire:keydown.escape.window="cerrarMasivo">
            <div class="gc-drawer-panel max-w-4xl" role="dialog" aria-modal="true" aria-label="Registro masivo de candidatos">
                <div class="flex shrink-0 items-start gap-4 border-b border-slate-200 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold text-slate-900">Registro masivo</h2>
                        <p class="mt-1 text-sm text-slate-500">Pega la lista del día, una persona por línea. Puede mezclar nombres, celulares, DNI o usuarios de WhatsApp.</p>
                    </div>
                    <button type="button" wire:click="cerrarMasivo" class="gc-btn gc-btn-ghost" aria-label="Cerrar">Cerrar ✕</button>
                </div>

                <div class="gc-drawer-body gc-form">
                    @if (empty($masivoFilas))
                        <x-forms.section title="Datos comunes (opcionales)">
                            <x-forms.field label="Fecha de gestión" name="m.fecha_gestion" type="date" />
                            <x-forms.select label="Agente reclutador" name="m.agente_reclutador" :options="$mv['reclutadores']" />
                            <x-forms.select label="Área" name="m.area" :options="$mv['areas']" live />
                            <x-forms.select label="Puesto" name="m.puesto" :options="$mv['puestos']" live />
                            @if ($mv['sinCampana'])
                                <x-forms.field label="Campaña" name="m.campana" :readonly="true" hint="Este puesto no lleva campaña." />
                            @else
                                <x-forms.select label="Campaña" name="m.campana" :options="$mv['campanas']" />
                            @endif
                            @if ($mv['sinCargo'])
                                <x-forms.field label="Cargo" name="m.cargo" :readonly="true" hint="Esta área no lleva cargo." />
                            @else
                                <x-forms.select label="Cargo" name="m.cargo" :options="$mv['cargos']" />
                            @endif
                        </x-forms.section>

                        <fieldset class="space-y-2">
                            <legend class="form-section-title">Lista</legend>
                            <textarea wire:model="m.lista" rows="12" class="form-input resize-none font-mono" placeholder="Ana Torres 987654321&#10;987654322&#10;Karla ✨&#10;DNI 45678912 Carlos Ruiz&#10;@luis_ventas" aria-label="Lista de candidatos"></textarea>
                            <p class="text-xs text-slate-400">Máximo {{ GestionCandidatos::MASIVO_MAXIMO }} líneas. Lo único necesario en cada línea es un dato de la persona; el resto se completa después en su registro.</p>
                            @error('m.fecha_gestion')
                                <p class="form-error">{{ $message }}</p>
                            @enderror
                        </fieldset>
                    @else
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="badge badge-green">{{ $mv['nuevos'] }} nuevos</span>
                            @if ($mv['parecidos'] > 0)
                                <span class="badge badge-amber">{{ $mv['parecidos'] }} con el mismo nombre de alguien registrado</span>
                            @endif
                            @if ($mv['existen'] > 0)
                                <span class="badge badge-slate">{{ $mv['existen'] }} ya registrados o repetidos</span>
                            @endif
                            <span class="text-slate-500">Revisa cómo se interpretó cada línea. Si algo está mal, cambia «Tratar como».</span>
                        </div>

                        <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                            <table class="data-table">
                                <thead>
                                    <tr><th></th><th>Línea</th><th>Se registrará como</th><th>Tratar como</th><th>Estado</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($masivoFilas as $i => $fila)
                                        <tr wire:key="mv-{{ $i }}-{{ $fila['forzado'] }}-{{ $fila['estado'] }}" class="{{ in_array($fila['estado'], ['existe', 'repetido'], true) ? 'opacity-60' : '' }}">
                                            <td><input type="checkbox" wire:model.live="masivoFilas.{{ $i }}.incluir" class="h-4 w-4 rounded border-slate-300" @disabled(in_array($fila['estado'], ['existe', 'repetido'], true)) aria-label="Registrar la línea {{ $i + 1 }}"></td>
                                            <td class="max-w-[14rem] truncate text-slate-500" title="{{ $fila['texto'] }}">{{ $fila['texto'] }}</td>
                                            <td class="text-sm">
                                                @if ($fila['nombres'] !== '') <span class="block"><span class="text-slate-400">Nombre:</span> <b>{{ $fila['nombres'] }}</b></span> @endif
                                                @if ($fila['dni'] !== '') <span class="block"><span class="text-slate-400">DNI:</span> <b>{{ $fila['dni'] }}</b></span> @endif
                                                @if ($fila['celular'] !== '') <span class="block"><span class="text-slate-400">Celular / usuario:</span> <b>{{ $fila['celular'] }}</b></span> @endif
                                            </td>
                                            <td>
                                                <select wire:model.live="masivoFilas.{{ $i }}.forzado" class="form-input w-auto py-1" aria-label="Cómo tratar la línea {{ $i + 1 }}">
                                                    <option value="auto">Automático</option>
                                                    <option value="nombre">Nombre</option>
                                                    <option value="celular">Celular o usuario</option>
                                                    <option value="dni">DNI</option>
                                                </select>
                                            </td>
                                            <td>
                                                @switch($fila['estado'])
                                                    @case('nuevo') <span class="badge badge-green">Nuevo</span> @break
                                                    @case('parecido') <span class="badge badge-amber">Revisar</span> @break
                                                    @case('existe') <span class="badge badge-slate">Ya registrado</span> @break
                                                    @default <span class="badge badge-slate">Repetido</span>
                                                @endswitch
                                                @if ($fila['detalle'] !== '')
                                                    <span class="mt-0.5 block text-xs text-slate-400">{{ $fila['detalle'] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="gc-drawer-pie">
                    @if ($masivoAviso !== '')
                        <div class="gc-aviso w-full bg-rose-50 text-rose-700" role="alert">{{ $masivoAviso }}</div>
                    @endif

                    @if (empty($masivoFilas))
                        <button type="button" wire:click="revisarLista" wire:loading.attr="disabled" wire:target="revisarLista" class="gc-btn gc-btn-primary">
                            <span wire:loading.remove wire:target="revisarLista">Revisar lista</span>
                            <span wire:loading wire:target="revisarLista">Revisando…</span>
                        </button>
                    @else
                        <button type="button" wire:click="registrarMasivo" wire:loading.attr="disabled" wire:target="registrarMasivo" class="gc-btn gc-btn-primary" @disabled($mv['incluidos'] === 0)>
                            <span wire:loading.remove wire:target="registrarMasivo">Registrar {{ $mv['incluidos'] }} {{ $mv['incluidos'] === 1 ? 'candidato' : 'candidatos' }}</span>
                            <span wire:loading wire:target="registrarMasivo">Registrando…</span>
                        </button>
                        <button type="button" wire:click="volverALista" class="gc-btn gc-btn-ghost">Volver a la lista</button>
                    @endif
                    <button type="button" wire:click="cerrarMasivo" class="gc-btn gc-btn-ghost">Cancelar</button>
                </div>
            </div>
        </div>
    @endif
</div>
