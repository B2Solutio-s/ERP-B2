<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AsistenciaRegistrada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $biometricoId,
        public string $marcadoAt,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('asistencia.marcaciones')];
    }

    public function broadcastAs(): string
    {
        return 'asistencia.registrada';
    }

    public function broadcastWith(): array
    {
        return [
            'biometricoId' => $this->biometricoId,
            'marcadoAt' => $this->marcadoAt,
        ];
    }
}
