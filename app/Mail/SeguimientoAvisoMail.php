<?php

namespace App\Mail;

use App\Models\SeguimientoAviso;
use App\Models\SeguimientoRegistro;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SeguimientoAvisoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SeguimientoAviso $aviso)
    {
    }

    protected function esCompromiso(): bool
    {
        return $this->aviso->tipo === SeguimientoRegistro::COMPROMISO;
    }

    public function envelope(): Envelope
    {
        $empleado = $this->aviso->empleado->nombreCompleto();

        return new Envelope(
            subject: $this->esCompromiso()
                ? "Aviso: {$this->aviso->cantidad} compromisos no cumplidos – {$empleado}"
                : "Aviso: {$this->aviso->cantidad} memorándums acumulados – {$empleado}",
        );
    }

    public function content(): Content
    {
        $registros = SeguimientoRegistro::where('empleado_id', $this->aviso->empleado_id)
            ->where('tipo', $this->aviso->tipo)
            ->when($this->esCompromiso(), fn ($q) => $q->where('estado', SeguimientoRegistro::NO_CUMPLIDO))
            ->orderBy('fecha_desde')
            ->get();

        return new Content(
            view: 'emails.seguimiento-aviso',
            with: [
                'aviso' => $this->aviso,
                'empleado' => $this->aviso->empleado,
                'registros' => $registros,
                'esCompromiso' => $this->esCompromiso(),
            ],
        );
    }
}
