<?php

namespace App\Services;

use App\Models\Feriado;
use Illuminate\Support\Carbon;

/**
 * Calendario laboral de la empresa: de lunes a viernes se trabaja; de los sábados, solo los dos últimos de cada mes; el domingo y los feriados no.
 * La hora límite de entrada es 8:40 de lunes a viernes y 9:05 los sábados que se trabajan.
 */
class CalendarioLaboral
{
    public const LIMITE_SEMANA = '08:40';

    public const LIMITE_SABADO = '09:05';

    public const SABADOS_TRABAJADOS = 2;

    /**
     * Todos los días del mes (domingos incluidos) con su condición laboral.
     *
     * @return array<string, array{laborable: bool, limite: ?string, feriado: ?string}>
     */
    public static function mes(Carbon $referencia): array
    {
        $inicio = $referencia->copy()->startOfMonth()->startOfDay();
        $fin = $referencia->copy()->endOfMonth()->startOfDay();

        $feriados = Feriado::whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())->get()
            ->mapWithKeys(fn (Feriado $feriado) => [$feriado->fecha->toDateString() => $feriado->nombre]);

        $dias = [];
        for ($dia = $inicio->copy(); $dia->lte($fin); $dia->addDay()) {
            $dias[] = $dia->copy();
        }

        $sabadosTrabajados = collect($dias)->filter(fn (Carbon $dia) => $dia->isSaturday())
            ->map(fn (Carbon $dia) => $dia->toDateString())
            ->slice(-self::SABADOS_TRABAJADOS)->all();

        $calendario = [];
        foreach ($dias as $dia) {
            $f = $dia->toDateString();
            $feriado = $feriados->get($f);
            $trabajaSabado = in_array($f, $sabadosTrabajados, true);
            $laborable = $feriado === null && ($dia->isWeekday() || $trabajaSabado);

            $calendario[$f] = [
                'laborable' => $laborable,
                'limite' => $laborable ? ($dia->isWeekday() ? self::LIMITE_SEMANA : self::LIMITE_SABADO) : null,
                'feriado' => $feriado,
            ];
        }

        return $calendario;
    }

    /** @return array{laborable: bool, limite: ?string, feriado: ?string} */
    public static function dia(string $fecha): array
    {
        return self::mes(Carbon::parse($fecha))[$fecha];
    }
}
