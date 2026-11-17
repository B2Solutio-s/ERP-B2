<?php

use App\Models\ResultadoCampana;
use App\Models\ResultadoCampanaCambio;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel', ['title' => 'Resultados de campaña'])] class extends Component
{
    public bool $mostrarModal = false;

    public ?int $editandoId = null;

    public string $pestana = 'datos';

    public array $form = [];

    public function mount(): void
    {
        $this->autorizar();
        $this->form = $this->formVacio();
    }

    private function autorizar(): void
    {
        abort_unless(Auth::user()->esMkt() || Auth::user()->esAdmin(), 403);
    }

    private function formVacio(): array
    {
        return collect(ResultadoCampana::campos())->map(fn () => '')->all();
    }

    public function registros()
    {
        return ResultadoCampana::orderByDesc('fecha_inicio')->orderByDesc('id')->get();
    }

    /** Fila de totales: suma de las columnas numéricas y métricas recalculadas sobre esas sumas. */
    public function totales($registros): ResultadoCampana
    {
        $total = new ResultadoCampana;

        foreach (ResultadoCampana::campos() as $clave => $columna) {
            if (in_array($columna['tipo'], ['moneda', 'entero'], true)) {
                $total->setAttribute($clave, $registros->sum($clave));
            }
        }

        return $total;
    }

    /** Métricas de lo que se está digitando en el formulario (vista previa en vivo). */
    public function metricasForm(): array
    {
        $numero = fn (string $clave) => is_numeric($this->form[$clave] ?? null) ? $this->form[$clave] + 0 : 0;

        return ResultadoCampana::metricas(
            (float) $numero('importe_gastado'),
            (int) $numero('postulantes'),
            (int) $numero('entrevistas'),
            (int) $numero('ingresos_reales'),
        );
    }

    public function historial()
    {
        if (! $this->editandoId) {
            return collect();
        }

        return ResultadoCampanaCambio::with('usuario')
            ->where('resultado_campana_id', $this->editandoId)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lote');
    }

    public function abrirModal(?int $id = null): void
    {
        $this->autorizar();
        $this->resetErrorBag();
        $this->pestana = 'datos';
        $this->editandoId = $id;

        if ($id) {
            $registro = ResultadoCampana::findOrFail($id);
            $this->form = collect(ResultadoCampana::campos())->map(function ($columna, $clave) use ($registro) {
                $valor = $registro->getAttribute($clave);

                return match ($columna['tipo']) {
                    'fecha' => $valor?->format('Y-m-d') ?? '',
                    default => (string) $valor,
                };
            })->all();
        } else {
            $this->form = $this->formVacio();
        }

        $this->mostrarModal = true;
    }

    public function cerrarModal(): void
    {
        $this->mostrarModal = false;
        $this->resetErrorBag();
    }

    public function seleccionarPestana(string $pestana): void
    {
        $this->pestana = $pestana === 'historial' && $this->editandoId ? 'historial' : 'datos';
    }

    private function reglas(): array
    {
        $reglas = [
            'form.campana' => 'required|string|max:150',
            'form.fecha_inicio' => 'required|date',
            'form.fecha_fin' => 'nullable|date|after_or_equal:form.fecha_inicio',
            'form.importe_gastado' => 'nullable|numeric|min:0|max:99999999',
        ];

        foreach (ResultadoCampana::campos() as $clave => $columna) {
            if ($columna['tipo'] === 'entero') {
                $reglas["form.$clave"] = 'nullable|integer|min:0|max:1000000000';
            }
        }

        return $reglas;
    }

    private function atributos(): array
    {
        return collect(ResultadoCampana::campos())
            ->mapWithKeys(fn ($columna, $clave) => ["form.$clave" => mb_strtolower($columna['etiqueta'])])
            ->all();
    }

    /** Convierte el formulario (todo texto) a valores listos para guardar. */
    private function datosNormalizados(): array
    {
        return collect(ResultadoCampana::campos())->map(function ($columna, $clave) {
            $valor = is_string($this->form[$clave] ?? null) ? trim($this->form[$clave]) : ($this->form[$clave] ?? '');

            return match ($columna['tipo']) {
                'texto' => $valor,
                'fecha' => $valor === '' ? null : $valor,
                'moneda' => $valor === '' ? 0 : (float) $valor,
                default => $valor === '' ? 0 : (int) $valor,
            };
        })->all();
    }

    private function registrarCambio(int $resultadoId, string $lote, string $accion, ?string $campo = null, mixed $anterior = null, mixed $nuevo = null): void
    {
        ResultadoCampanaCambio::create([
            'resultado_campana_id' => $resultadoId,
            'user_id' => Auth::id(),
            'lote' => $lote,
            'accion' => $accion,
            'campo' => $campo,
            'valor_anterior' => $anterior,
            'valor_nuevo' => $nuevo,
        ]);
    }

    public function guardar(): void
    {
        $this->autorizar();
        $this->validate($this->reglas(), [], $this->atributos());
        $datos = $this->datosNormalizados();

        DB::transaction(function () use ($datos) {
            $lote = (string) Str::uuid();

            if ($this->editandoId) {
                $registro = ResultadoCampana::findOrFail($this->editandoId);
                $registro->fill($datos);
                $cambios = $registro->getDirty();

                if ($cambios === []) {
                    return;
                }

                $anteriores = collect($cambios)->mapWithKeys(fn ($v, $campo) => [$campo => $registro->getRawOriginal($campo)]);
                $registro->save();

                foreach ($cambios as $campo => $nuevo) {
                    $this->registrarCambio($registro->id, $lote, 'editado', $campo, $anteriores[$campo], $nuevo);
                }

                return;
            }

            $registro = ResultadoCampana::create($datos);
            $this->registrarCambio($registro->id, $lote, 'creado');
        });

        $this->cerrarModal();
    }

    public function eliminar(int $id): void
    {
        $this->autorizar();

        $registro = ResultadoCampana::find($id);
        if (! $registro) {
            return;
        }

        DB::transaction(function () use ($registro) {
            $this->registrarCambio($registro->id, (string) Str::uuid(), 'eliminado');
            $registro->delete();
        });

        $this->cerrarModal();
    }
};
?>

<div class="space-y-4">
    @php($registros = $this->registros())

    <div class="flex items-center justify-between gap-3">
        <p class="text-sm text-slate-500">
            {{ $registros->count() }} {{ $registros->count() === 1 ? 'registro' : 'registros' }}
            <span class="text-slate-400">· Las columnas en azul se calculan solas.</span>
        </p>
        <button type="button" wire:click="abrirModal" class="btn-primary w-auto !px-3 !py-2 text-sm">+ Nuevo registro</button>
    </div>

    <div class="card-panel-compact !p-0">
        <div class="max-h-[calc(100vh-11rem)] overflow-auto rounded-2xl">
            <table class="resultados-tabla">
                <thead>
                    <tr>
                        @foreach (App\Models\ResultadoCampana::COLUMNAS as $clave => $columna)
                            <th @class([
                                'is-sticky' => $clave === 'campana',
                                'is-calc' => ! empty($columna['calculado']),
                                'is-num' => ! in_array($columna['tipo'], ['texto', 'fecha'], true),
                            ]) title="{{ $columna['etiqueta'] }}">{{ $columna['corta'] ?? $columna['etiqueta'] }}</th>
                        @endforeach
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($registros as $registro)
                        <tr wire:key="resultado-{{ $registro->id }}" wire:click="abrirModal({{ $registro->id }})" class="cursor-pointer">
                            @foreach (App\Models\ResultadoCampana::COLUMNAS as $clave => $columna)
                                <td @class([
                                    'is-sticky font-medium text-slate-900' => $clave === 'campana',
                                    'is-calc' => ! empty($columna['calculado']),
                                    'is-num' => ! in_array($columna['tipo'], ['texto', 'fecha'], true),
                                ])>{{ $registro->valorMostrado($clave) }}</td>
                            @endforeach
                            <td class="text-right">
                                <button type="button" wire:click.stop="eliminar({{ $registro->id }})" wire:confirm="¿Eliminar el registro «{{ $registro->campana }}»?" class="text-slate-300 transition hover:text-rose-600" aria-label="Eliminar" title="Eliminar">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count(App\Models\ResultadoCampana::COLUMNAS) + 1 }}" class="py-8 text-center text-slate-400">Aún no hay registros. Crea el primero con «+ Nuevo registro».</td></tr>
                    @endforelse
                </tbody>
                @if ($registros->count() > 1)
                    <tfoot>
                        @php($totales = $this->totales($registros))
                        <tr>
                            @foreach (App\Models\ResultadoCampana::COLUMNAS as $clave => $columna)
                                <td @class([
                                    'is-sticky !bg-slate-50' => $clave === 'campana',
                                    'is-num' => ! in_array($columna['tipo'], ['texto', 'fecha'], true),
                                ])>
                                    @if ($clave === 'campana')
                                        Total
                                    @elseif (in_array($columna['tipo'], ['texto', 'fecha'], true))
                                        &nbsp;
                                    @else
                                        {{ $totales->valorMostrado($clave) }}
                                    @endif
                                </td>
                            @endforeach
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    @if ($mostrarModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 pt-3">
                    <div class="flex items-end gap-1">
                        <h3 class="mr-3 pb-2 text-sm font-semibold text-slate-900">{{ $editandoId ? 'Editar registro' : 'Nuevo registro' }}</h3>
                        @if ($editandoId)
                            <button type="button" wire:click="seleccionarPestana('datos')" class="resultados-tab {{ $pestana === 'datos' ? 'is-active' : '' }}">Datos</button>
                            <button type="button" wire:click="seleccionarPestana('historial')" class="resultados-tab {{ $pestana === 'historial' ? 'is-active' : '' }}">Historial</button>
                        @endif
                    </div>
                    <button type="button" wire:click="cerrarModal" class="mb-2 text-slate-400 transition hover:text-slate-600" aria-label="Cerrar">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                @if ($pestana === 'datos')
                    <form wire:submit="guardar" class="usuarios-modal-form resultados-form flex-1 space-y-4 overflow-y-auto p-4">
                        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <x-forms.field label="Campaña" name="form.campana" class="col-span-2" />
                            <x-forms.field label="Fecha inicio" name="form.fecha_inicio" type="date" />
                            <x-forms.field label="Fecha fin" name="form.fecha_fin" type="date" />
                        </div>

                        <div>
                            <p class="form-section-title mb-2 !text-[11px]">Meta</p>
                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <x-forms.field label="Importe gastado (S/)" name="form.importe_gastado" type="number" step="0.01" min="0" :live="true" />
                                <x-forms.field label="Clics en el enlace" name="form.clics_enlace" type="number" min="0" />
                                <x-forms.field label="Resultados Meta" name="form.resultados_meta" type="number" min="0" />
                            </div>
                        </div>

                        <div>
                            <p class="form-section-title mb-2 !text-[11px]">Postulantes</p>
                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <x-forms.field label="Postulantes" name="form.postulantes" type="number" min="0" :live="true" />
                                <x-forms.field label="No contestó" name="form.no_contesto" type="number" min="0" />
                                <x-forms.field label="Pendiente de llamada" name="form.pendiente_llamada" type="number" min="0" />
                                <x-forms.field label="No interesados" name="form.no_interesados" type="number" min="0" />
                                <x-forms.field label="No califica" name="form.no_califica" type="number" min="0" />
                                <x-forms.field label="Entrevistas" name="form.entrevistas" type="number" min="0" :live="true" />
                                <x-forms.field label="Agendados capacitación" name="form.agendados_capacitacion" type="number" min="0" />
                                <x-forms.field label="Ingresos reales" name="form.ingresos_reales" type="number" min="0" :live="true" />
                            </div>
                        </div>

                        @php($metricas = $this->metricasForm())
                        <div class="grid grid-cols-3 gap-3">
                            <div class="resultados-metrica">
                                <span class="resultados-metrica-valor">{{ App\Models\ResultadoCampana::formatear('costo_entrevista', $metricas['costo_entrevista']) }}</span>
                                <span class="resultados-metrica-etiqueta">Costo por entrevista</span>
                            </div>
                            <div class="resultados-metrica">
                                <span class="resultados-metrica-valor">{{ App\Models\ResultadoCampana::formatear('pct_entrevistas', $metricas['pct_entrevistas']) }}</span>
                                <span class="resultados-metrica-etiqueta">% Entrevistas</span>
                            </div>
                            <div class="resultados-metrica">
                                <span class="resultados-metrica-valor">{{ App\Models\ResultadoCampana::formatear('pct_ingresos', $metricas['pct_ingresos']) }}</span>
                                <span class="resultados-metrica-etiqueta">% Ingresos reales</span>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-slate-100 pt-3">
                            <button type="button" wire:click="cerrarModal" class="btn-secondary w-auto">Cancelar</button>
                            <button type="submit" class="btn-primary w-auto" wire:loading.attr="disabled" wire:target="guardar">
                                <span wire:loading.remove wire:target="guardar">Guardar</span>
                                <span wire:loading wire:target="guardar">Guardando...</span>
                            </button>
                        </div>
                    </form>
                @else
                    <div class="flex-1 space-y-3 overflow-y-auto p-4">
                        @forelse ($this->historial() as $lote => $filas)
                            @php($primera = $filas->first())
                            <div wire:key="lote-{{ $lote }}" class="rounded-lg border border-slate-100 p-3">
                                <div class="mb-1 flex items-center justify-between text-xs">
                                    <span class="font-semibold text-slate-700">
                                        {{ match ($primera->accion) { 'creado' => 'Registro creado', 'eliminado' => 'Registro eliminado', default => 'Edición' } }}
                                        · {{ $primera->usuario?->name ?? 'Usuario eliminado' }}
                                    </span>
                                    <span class="text-slate-400">{{ $primera->created_at->format('d/m/Y H:i') }}</span>
                                </div>
                                @foreach ($filas->whereNotNull('campo') as $fila)
                                    <p class="text-xs text-slate-600">
                                        <span class="font-medium">{{ App\Models\ResultadoCampana::COLUMNAS[$fila->campo]['etiqueta'] ?? $fila->campo }}:</span>
                                        <span class="text-slate-400 line-through">{{ App\Models\ResultadoCampana::formatear($fila->campo, $fila->valor_anterior) }}</span>
                                        →
                                        <span class="font-medium text-slate-900">{{ App\Models\ResultadoCampana::formatear($fila->campo, $fila->valor_nuevo) }}</span>
                                    </p>
                                @endforeach
                            </div>
                        @empty
                            <p class="py-6 text-center text-xs text-slate-400">Aún no hay cambios registrados.</p>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
