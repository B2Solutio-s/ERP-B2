<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReclutamientoActualizado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $tipo,
        public ?int $filaId,
        public ?int $usuarioId,
        public string $usuarioNombre,
        public bool $bloqueada = false,
        public array $filas = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('reclutamiento.candidatos')];
    }

    public function broadcastAs(): string
    {
        return 'reclutamiento.actualizado';
    }

    public function broadcastWith(): array
    {
        return [
            'tipo' => $this->tipo,
            'filaId' => $this->filaId,
            'usuarioId' => $this->usuarioId,
            'usuarioNombre' => $this->usuarioNombre,
            'bloqueada' => $this->bloqueada,
            'filas' => $this->filas,
        ];
    }
}
