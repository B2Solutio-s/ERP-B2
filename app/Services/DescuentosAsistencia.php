<?php

namespace App\Services;

/**
 * Reglas de los descuentos sugeridos por tardanza o falta. Son solo una recomendación: Administración decide.
 *
 * De lunes a viernes (límite 8:40): 8:41–8:50 = S/ 5; 8:51–9:00 = 50% del pago del día; desde 9:01 = día completo.
 * Sábados trabajados (límite 9:05): desde 9:06 = día completo. Una falta = día completo (puede justificarse con sustento).
 * Pago del día = sueldo ÷ 30.
 */
class DescuentosAsistencia
{
    public const MONTO_FIJO = 5.00;

    public const DIAS_PAGO = 30;

    public const TRAMOS = ['fijo', 'mitad', 'completo'];

    /** Tramo según la hora de entrada (HH:MM), o null si llegó a tiempo. */
    public static function tramoTardanza(string $entrada, bool $sabado): ?string
    {
        if ($sabado) {
            return $entrada > '09:05' ? 'completo' : null;
        }

        return match (true) {
            $entrada <= '08:40' => null,
            $entrada <= '08:50' => 'fijo',
            $entrada <= '09:00' => 'mitad',
            default => 'completo',
        };
    }

    /** Pago de un día completo, o null si no hay sueldo registrado. */
    public static function pagoDia(?float $sueldo): ?float
    {
        return $sueldo !== null && $sueldo > 0 ? round($sueldo / self::DIAS_PAGO, 2) : null;
    }

    /** Monto del descuento del tramo; null si hace falta el sueldo y no está registrado. */
    public static function monto(string $tramo, ?float $sueldo): ?float
    {
        if ($tramo === 'fijo') {
            return self::MONTO_FIJO;
        }

        if ($sueldo === null || $sueldo <= 0) {
            return null;
        }

        return $tramo === 'mitad' ? round($sueldo / self::DIAS_PAGO / 2, 2) : self::pagoDia($sueldo);
    }

    public static function etiquetaTramo(string $tramo): string
    {
        return match ($tramo) {
            'fijo' => 'S/ 5.00 fijos',
            'mitad' => '50% del pago del día',
            default => 'Día completo',
        };
    }
}
