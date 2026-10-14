<?php

namespace App\Services;

use RuntimeException;

/**
 * Cliente TCP mínimo del protocolo ZKTeco que solo escucha eventos de marcación
 * en tiempo real (CMD_REG_EVENT / EF_ATTLOG).
 */
class ZkLiveClient
{
    private const CMD_CONNECT = 1000;
    private const CMD_EXIT = 1001;
    private const CMD_AUTH = 1102;
    private const CMD_REG_EVENT = 500;
    private const CMD_GET_TIME = 201;
    private const CMD_ACK_OK = 2000;
    private const CMD_ACK_UNAUTH = 2005;
    private const EF_ATTLOG = 1;
    private const MAGIC = "\x50\x50\x82\x7d";

    /** @var resource|null */
    private $socket = null;

    private int $session = 0;

    private int $reply = 65534;

    private string $buffer = '';

    public string $ultimoCrudo = '';

    public function __construct(
        private string $host,
        private int $port = 4370,
        private int $clave = 0,
        private int $timeout = 30,
    ) {
    }

    public function conectar(): void
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, 10);

        if (! $socket) {
            throw new RuntimeException("No se pudo conectar al biométrico ({$error}).");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->session = 0;
        $this->reply = 65534;
        $this->buffer = '';

        $respuesta = $this->comando(self::CMD_CONNECT);
        $this->session = $respuesta['session'];

        if ($respuesta['cmd'] === self::CMD_ACK_UNAUTH) {
            $respuesta = $this->comando(self::CMD_AUTH, $this->hashClave($this->clave, $this->session));
        }

        if ($respuesta['cmd'] !== self::CMD_ACK_OK) {
            throw new RuntimeException('El biométrico rechazó la conexión (¿tiene clave de comunicación configurada?).');
        }

        $registro = $this->comando(self::CMD_REG_EVENT, pack('V', self::EF_ATTLOG));

        if ($registro['cmd'] !== self::CMD_ACK_OK) {
            throw new RuntimeException('El biométrico no aceptó el registro de eventos en vivo.');
        }
    }

    /**
     * Espera marcaciones. Llama $alMarcar(biometricoId, 'Y-m-d H:i:s', estado, tipo) por cada una.
     * Se detiene cuando $continuar devuelve false.
     */
    public function escuchar(callable $alMarcar, callable $continuar): void
    {
        $ultimoPulso = time();

        while ($continuar()) {
            $paquete = $this->leerPaquete();

            if ($paquete === null) {
                if (stream_get_meta_data($this->socket)['eof'] ?? false) {
                    throw new RuntimeException('El biométrico cerró la conexión.');
                }

                if (time() - $ultimoPulso >= 30) {
                    $this->enviar(self::CMD_GET_TIME);
                    $ultimoPulso = time();
                }

                continue;
            }

            if ($paquete['cmd'] !== self::CMD_REG_EVENT || $paquete['data'] === '') {
                continue;
            }

            $this->ultimoCrudo = bin2hex($paquete['data']);
            $this->enviar(self::CMD_ACK_OK, '', $paquete['reply']);

            if ($marca = $this->decodificarMarcacion($paquete['data'])) {
                $alMarcar($marca['id'], $marca['fecha'], $marca['estado'], $marca['tipo']);
            }
        }
    }

    public function desconectar(): void
    {
        if (! $this->socket) {
            return;
        }

        try {
            $this->enviar(self::CMD_EXIT);
        } catch (\Throwable) {
        }

        @fclose($this->socket);
        $this->socket = null;
    }

    private function decodificarMarcacion(string $data): ?array
    {
        $largo = strlen($data);

        if ($largo === 10) {
            $campos = unpack('vid/Cestado/Ctipo/C6hora', $data);
            $id = (string) $campos['id'];
        } elseif ($largo === 12 || $largo === 14) {
            $campos = unpack('Vid/Cestado/Ctipo/C6hora', $data);
            $id = (string) $campos['id'];
        } elseif ($largo >= 32) {
            $campos = unpack('a24id/Cestado/Ctipo/C6hora', $data);
            $id = rtrim($campos['id'], "\0");
        } else {
            return null;
        }

        $fecha = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            2000 + $campos['hora1'], $campos['hora2'], $campos['hora3'],
            $campos['hora4'], $campos['hora5'], $campos['hora6'],
        );

        return ['id' => $id, 'fecha' => $fecha, 'estado' => $campos['estado'], 'tipo' => $campos['tipo']];
    }

    private function comando(int $cmd, string $data = ''): array
    {
        $this->enviar($cmd, $data);

        while (true) {
            $paquete = $this->leerPaquete();

            if ($paquete === null) {
                throw new RuntimeException('El biométrico no respondió.');
            }

            if (in_array($paquete['cmd'], [self::CMD_ACK_OK, self::CMD_ACK_UNAUTH], true)) {
                return $paquete;
            }
        }
    }

    private function enviar(int $cmd, string $data = '', ?int $reply = null): void
    {
        if ($reply === null) {
            $this->reply = ($this->reply + 1) % 65535;
            $reply = $this->reply;
        }

        $sinSuma = pack('vvvv', $cmd, 0, $this->session, $reply).$data;
        $suma = $this->suma($sinSuma);
        $paquete = pack('vvvv', $cmd, $suma, $this->session, $reply).$data;

        fwrite($this->socket, self::MAGIC.pack('V', strlen($paquete)).$paquete);
    }

    private function leerPaquete(): ?array
    {
        while (strlen($this->buffer) < 8 || strlen($this->buffer) < 8 + unpack('V', substr($this->buffer, 4, 4))[1]) {
            $trozo = @fread($this->socket, 4096);

            if ($trozo === false || $trozo === '') {
                return null;
            }

            $this->buffer .= $trozo;
        }

        $largo = unpack('V', substr($this->buffer, 4, 4))[1];
        $cuerpo = substr($this->buffer, 8, $largo);
        $this->buffer = substr($this->buffer, 8 + $largo);

        if (strlen($cuerpo) < 8) {
            return null;
        }

        $cabecera = unpack('vcmd/vsuma/vsession/vreply', $cuerpo);

        return [
            'cmd' => $cabecera['cmd'],
            'session' => $cabecera['session'],
            'reply' => $cabecera['reply'],
            'data' => substr($cuerpo, 8),
        ];
    }

    private function suma(string $datos): int
    {
        if (strlen($datos) % 2) {
            $datos .= "\0";
        }

        $total = array_sum(unpack('v*', $datos));

        while ($total > 0xFFFF) {
            $total = ($total & 0xFFFF) + ($total >> 16);
        }

        return (~$total) & 0xFFFF;
    }

    private function hashClave(int $clave, int $session): string
    {
        $k = 0;

        for ($i = 0; $i < 32; $i++) {
            $k = ($k << 1) | (($clave >> $i) & 1);
        }

        $b = unpack('C4', pack('V', ($k + $session) & 0xFFFFFFFF));
        $b = [$b[1] ^ 0x5A, $b[2] ^ 0x4B, $b[3] ^ 0x53, $b[4] ^ 0x4F];
        $ticks = 50;

        return pack('C4', $b[2] ^ $ticks, $b[3] ^ $ticks, $ticks, $b[1] ^ $ticks);
    }
}
