<?php

namespace App\Concerns;

use Illuminate\Support\Carbon;

/** Selector Diario / Mensual de los tableros de control (día o mes elegido, con navegación y validación). */
trait ConPeriodoDiarioMensual
{
    /** 'diario', 'mensual' o 'total' (todo el historial). */
    public string $vista = 'diario';

    public string $fecha = '';

    public string $mes = '';

    /** Fecha (Y-m-d) del dato más antiguo: de ahí sale la lista de meses. */
    abstract protected function primeraFecha(): ?string;

    abstract public function cerrarDetalle(): void;

    protected function iniciarPeriodo(): void
    {
        $this->fecha = $this->hoy()->toDateString();
        $this->mes = $this->hoy()->format('Y-m');
    }

    protected function hoy(): Carbon
    {
        return now('America/Lima');
    }

    public function updatedVista(): void
    {
        $this->cerrarDetalle();

        if (! in_array($this->vista, ['diario', 'mensual', 'total'], true)) {
            $this->vista = 'diario';
        }
    }

    public function updatedFecha(): void
    {
        $this->cerrarDetalle();
        $this->fecha = $this->parsear($this->fecha, 'Y-m-d')?->toDateString() ?? $this->hoy()->toDateString();
    }

    public function updatedMes(): void
    {
        $this->cerrarDetalle();
        $this->mes = $this->parsear($this->mes, 'Y-m')?->format('Y-m') ?? $this->hoy()->format('Y-m');
    }

    public function moverDia(int $dias): void
    {
        $this->cerrarDetalle();
        $this->fecha = ($this->parsear($this->fecha, 'Y-m-d') ?? $this->hoy()->startOfDay())->addDays($dias)->toDateString();
    }

    public function irAHoy(): void
    {
        $this->cerrarDetalle();
        $this->fecha = $this->hoy()->toDateString();
        $this->mes = $this->hoy()->format('Y-m');
    }

    private function parsear(string $valor, string $formato): ?Carbon
    {
        try {
            return Carbon::createFromFormat('!'.$formato, $valor, 'America/Lima') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Rango [desde, hasta] (fechas, hora 00:00 hora de Lima) del período elegido y del inmediato anterior.
     * En la vista Total abarca todo el historial (incluye lo ya programado a futuro) y no tiene período anterior (null).
     */
    protected function rangos(): array
    {
        if ($this->vista === 'total') {
            return [
                'actual' => [Carbon::create(2000, 1, 1, 0, 0, 0, 'America/Lima'), $this->hoy()->startOfDay()->addYear()],
                'anterior' => null,
            ];
        }

        if ($this->vista === 'diario') {
            $dia = $this->parsear($this->fecha, 'Y-m-d') ?? $this->hoy()->startOfDay();

            return [
                'actual' => [$dia->copy(), $dia->copy()],
                'anterior' => [$dia->copy()->subDay(), $dia->copy()->subDay()],
            ];
        }

        $mes = $this->parsear($this->mes, 'Y-m') ?? $this->hoy()->startOfMonth();
        $anterior = $mes->copy()->subMonthNoOverflow();

        return [
            'actual' => [$mes->copy(), $mes->copy()->endOfMonth()->startOfDay()],
            'anterior' => [$anterior, $anterior->copy()->endOfMonth()->startOfDay()],
        ];
    }

    /** Límites en UTC (como se guardan las columnas datetime) de un rango de días de Lima, listos para whereBetween. */
    protected function rangoUtc(array $rango): array
    {
        return [
            $rango[0]->copy()->startOfDay()->setTimezone('UTC')->format('Y-m-d H:i:s'),
            $rango[1]->copy()->endOfDay()->setTimezone('UTC')->format('Y-m-d H:i:s'),
        ];
    }

    protected function etiquetaPeriodo(array $rango): string
    {
        if ($this->vista === 'total') {
            $primero = $this->primeraFecha();

            return 'Total acumulado'.($primero ? ' · desde '.Carbon::parse($primero)->locale('es')->isoFormat('MMMM YYYY') : '');
        }

        $formato = $this->vista === 'diario' ? 'dddd D [de] MMMM' : 'MMMM YYYY';

        return ucfirst($rango[0]->copy()->locale('es')->isoFormat($formato));
    }

    /** Meses desde el primer dato hasta el actual, del más reciente al más antiguo. */
    public function mesesDisponibles(): array
    {
        $primero = $this->primeraFecha();
        $inicio = ($primero ? Carbon::parse($primero) : $this->hoy())->startOfMonth();
        $meses = [];

        for ($mes = $this->hoy()->startOfMonth(); $mes->gte($inicio); $mes->subMonthNoOverflow()) {
            $meses[$mes->format('Y-m')] = ucfirst($mes->copy()->locale('es')->isoFormat('MMMM YYYY'));
        }

        return $meses;
    }

    /** Unidad de las columnas de tendencia: días del mes (Mensual) o meses del historial (Total). */
    protected function unidadTendencia(): string
    {
        return $this->vista === 'total' ? 'mes' : 'día';
    }

    /** Clave de la columna de tendencia a la que pertenece una fecha. */
    protected function claveTendencia(Carbon|string $fecha): string
    {
        $fecha = $fecha instanceof Carbon ? $fecha : Carbon::parse($fecha);

        return $this->vista === 'total' ? $fecha->format('Y-m') : $fecha->toDateString();
    }

    /**
     * Columnas de tendencia: cada día del mes elegido (Mensual) o cada mes desde el primer dato hasta hoy (Total).
     *
     * @return list<array{clave: string, etiqueta: string, titulo: string}>
     */
    protected function columnasTendencia(array $rango): array
    {
        $columnas = [];

        if ($this->vista === 'total') {
            $inicio = Carbon::parse($this->primeraFecha() ?? $this->hoy())->startOfMonth();

            for ($mes = $inicio; $mes->lte($this->hoy()->startOfMonth()); $mes->addMonthNoOverflow()) {
                $columnas[] = [
                    'clave' => $mes->format('Y-m'),
                    'etiqueta' => ucfirst($mes->copy()->locale('es')->isoFormat('MMM')),
                    'titulo' => ucfirst($mes->copy()->locale('es')->isoFormat('MMMM YYYY')),
                ];
            }

            return $columnas;
        }

        for ($dia = $rango[0]->copy(); $dia->lte($rango[1]); $dia->addDay()) {
            $columnas[] = ['clave' => $dia->toDateString(), 'etiqueta' => (string) $dia->day, 'titulo' => $dia->format('d/m')];
        }

        return $columnas;
    }
}
