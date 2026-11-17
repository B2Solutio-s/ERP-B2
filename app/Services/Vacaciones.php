<?php

namespace App\Services;

use App\Models\Vacacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reglas de vacaciones: régimen general de 30 días al año (2.5 por mes de servicio), en días calendario corridos
 * (también cuentan feriados y domingos). Se pueden programar como adelanto desde el 3.er mes de servicio.
 */
class Vacaciones
{
    public const DIAS_ANIO = 30;

    public const MESES_PARA_ADELANTO = 3;

    /** Días calendario del periodo, ambos extremos incluidos. */
    public static function dias(Carbon|string $inicio, Carbon|string $fin): int
    {
        return (int) Carbon::parse($inicio)->startOfDay()->diffInDays(Carbon::parse($fin)->startOfDay()) + 1;
    }

    /** Meses completos de servicio entre el ingreso y una fecha. */
    public static function mesesServicio(Carbon|string $ingreso, Carbon|string $hasta): int
    {
        $i = Carbon::parse($ingreso)->startOfDay();
        $h = Carbon::parse($hasta)->startOfDay();
        $meses = ($h->year - $i->year) * 12 + ($h->month - $i->month);

        // Mes completo = la fecha de aniversario (sin pasarse del fin de mes) ya llegó; así coincide con elegibleDesde().
        while ($meses > 0 && $i->copy()->addMonthsNoOverflow($meses)->gt($h)) {
            $meses--;
        }

        return max($meses, 0);
    }

    /** Días ganados: 2.5 por mes completo de servicio. */
    public static function ganados(Carbon|string $ingreso, Carbon|string $hasta): float
    {
        return round(self::mesesServicio($ingreso, $hasta) * self::DIAS_ANIO / 12, 1);
    }

    /** Fecha desde la que se pueden programar vacaciones (3.er mes de servicio). */
    public static function elegibleDesde(Carbon|string $ingreso): Carbon
    {
        return Carbon::parse($ingreso)->startOfDay()->addMonthsNoOverflow(self::MESES_PARA_ADELANTO);
    }

    /**
     * Saldo a una fecha: ganados, gozados (hasta hoy), programados (después de hoy) y disponible. Un saldo negativo es adelanto.
     *
     * @param  Collection<int, Vacacion>  $periodos  periodos de la persona (se ignoran los cancelados)
     * @return array{meses: int, ganados: float, gozados: int, programados: int, saldo: float, elegible_desde: Carbon, puede: bool}
     */
    public static function saldo(Carbon|string $ingreso, Collection $periodos, string $hoy): array
    {
        $gozados = 0;
        $programados = 0;
        $manana = Carbon::parse($hoy)->addDay()->toDateString();

        foreach ($periodos->where('estado', '!=', Vacacion::CANCELADA) as $p) {
            $inicio = $p->fecha_inicio->toDateString();
            $fin = $p->fecha_fin->toDateString();

            if ($inicio <= $hoy) {
                $gozados += self::dias($inicio, min($fin, $hoy));
            }

            if ($fin > $hoy) {
                $programados += self::dias(max($inicio, $manana), $fin);
            }
        }

        $ganados = self::ganados($ingreso, $hoy);
        $elegible = self::elegibleDesde($ingreso);

        return [
            'meses' => self::mesesServicio($ingreso, $hoy),
            'ganados' => $ganados,
            'gozados' => $gozados,
            'programados' => $programados,
            'saldo' => round($ganados - $gozados - $programados, 1),
            'elegible_desde' => $elegible,
            'puede' => $elegible->toDateString() <= $hoy,
        ];
    }

    /**
     * Días de vacaciones (periodos no cancelados) dentro de un rango.
     *
     * @return array<string, array{inicio: string, fin: string}>  «empleado_id|fecha» => periodo
     */
    public static function mapaDias(string $desde, string $hasta): array
    {
        $mapa = [];

        $periodos = Vacacion::where('estado', '!=', Vacacion::CANCELADA)
            ->whereDate('fecha_inicio', '<=', $hasta)->whereDate('fecha_fin', '>=', $desde)
            ->get(['empleado_id', 'fecha_inicio', 'fecha_fin']);

        foreach ($periodos as $p) {
            $inicio = max($p->fecha_inicio->toDateString(), $desde);
            $fin = min($p->fecha_fin->toDateString(), $hasta);

            for ($dia = Carbon::parse($inicio); $dia->toDateString() <= $fin; $dia->addDay()) {
                $mapa[$p->empleado_id.'|'.$dia->toDateString()] = ['inicio' => $p->fecha_inicio->toDateString(), 'fin' => $p->fecha_fin->toDateString()];
            }
        }

        return $mapa;
    }
}
