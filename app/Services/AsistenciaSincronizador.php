<?php

namespace App\Services;

use App\Events\AsistenciaRegistrada;
use App\Models\AsistenciaMarcacion;
use Rats\Zkteco\Lib\ZKTeco;
use RuntimeException;

class AsistenciaSincronizador
{
    /** Descarga todas las marcaciones guardadas en el biométrico y guarda las nuevas. */
    public function sincronizarHistorial(): int
    {
        $zk = new ZKTeco(config('asistencia.biometrico.host'), config('asistencia.biometrico.port'));

        if (! $zk->connect()) {
            throw new RuntimeException('No se pudo conectar al biométrico.');
        }

        try {
            $marcaciones = $zk->getAttendance();
        } finally {
            $zk->disconnect();
        }

        $ahora = now();
        $nuevas = 0;

        foreach (array_chunk($marcaciones, 500) as $lote) {
            $filas = array_map(fn ($m) => [
                'biometrico_id' => (string) $m['id'],
                'marcado_at' => $m['timestamp'],
                'estado' => (int) $m['state'],
                'tipo' => (int) $m['type'],
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $lote);

            $nuevas += AsistenciaMarcacion::insertOrIgnore($filas);
        }

        return $nuevas;
    }

    /** Guarda una marcación en vivo; avisa a los paneles abiertos si era nueva. */
    public function registrar(string $biometricoId, string $fecha, int $estado = 0, int $tipo = 0): bool
    {
        $ahora = now();

        $guardada = AsistenciaMarcacion::insertOrIgnore([[
            'biometrico_id' => $biometricoId,
            'marcado_at' => $fecha,
            'estado' => $estado,
            'tipo' => $tipo,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]]) > 0;

        if ($guardada) {
            AsistenciaRegistrada::dispatch($biometricoId, $fecha);
        }

        return $guardada;
    }
}
