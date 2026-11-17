<?php

namespace App\Support;

/** Agrupa por campaña y, para quienes no llevan campaña (administrativos, mesa de control…), por su puesto. */
class GrupoCampana
{
    /** @return array{tipo: 'C'|'P', nombre: string} */
    public static function de(?string $campana, ?string $puesto): array
    {
        $campana = mb_strtoupper(trim((string) $campana));

        if (! in_array($campana, ['', '-', 'NO APLICA'], true)) {
            return ['tipo' => 'C', 'nombre' => $campana];
        }

        return ['tipo' => 'P', 'nombre' => mb_strtoupper(trim((string) $puesto)) ?: 'SIN CAMPAÑA NI PUESTO'];
    }
}
