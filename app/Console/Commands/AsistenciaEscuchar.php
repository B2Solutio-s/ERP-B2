<?php

namespace App\Console\Commands;

use App\Services\AsistenciaSincronizador;
use App\Services\ZkLiveClient;
use Illuminate\Console\Command;

class AsistenciaEscuchar extends Command
{
    protected $signature = 'asistencia:escuchar {--sin-historial : No descargar el historial al iniciar}';

    protected $description = 'Escucha en vivo las marcaciones del biométrico y las guarda en el ERP';

    private bool $detener = false;

    public function handle(AsistenciaSincronizador $sincronizador): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->detener = true);
            pcntl_signal(SIGINT, fn () => $this->detener = true);
        }

        $host = config('asistencia.biometrico.host');
        $port = config('asistencia.biometrico.port');
        $historialPendiente = ! $this->option('sin-historial');

        while (! $this->detener) {
            $cliente = new ZkLiveClient($host, $port, config('asistencia.biometrico.clave'));

            try {
                $cliente->conectar();
                $this->info("Conectado a {$host}:{$port}");

                if ($historialPendiente) {
                    $nuevas = $sincronizador->sincronizarHistorial();
                    $this->info("Historial sincronizado: {$nuevas} marcaciones nuevas.");
                    $historialPendiente = false;
                }

                $ultimaSync = time();

                $cliente->escuchar(
                    function (string $id, string $fecha, int $estado, int $tipo) use ($sincronizador, $cliente) {
                        $nueva = $sincronizador->registrar($id, $fecha, $estado, $tipo);
                        $this->line(now()->format('H:i:s')." marcación id={$id} {$fecha}".($nueva ? '' : ' (duplicada)')." raw={$cliente->ultimoCrudo}");
                    },
                    function () use ($sincronizador, &$ultimaSync) {
                        // Respaldo: si algún evento en vivo se pierde, el historial lo recupera.
                        if (time() - $ultimaSync >= 60) {
                            $ultimaSync = time();
                            if ($nuevas = $sincronizador->sincronizarHistorial()) {
                                $this->info("Respaldo: {$nuevas} marcaciones recuperadas del historial.");
                            }
                        }

                        return ! $this->detener;
                    },
                );
            } catch (\Throwable $e) {
                $this->error($e->getMessage().' Reintentando en 10s…');
                // Al volver la conexión se recuperan las marcaciones perdidas mientras estuvo caída.
                $historialPendiente = true;
                sleep(10);
            } finally {
                $cliente->desconectar();
            }
        }

        return self::SUCCESS;
    }
}
