<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ResumenCompromisosMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param Collection<int, array{registro: \App\Models\SeguimientoRegistro, trabajador: string, plazo: array}> $novedades */
    public function __construct(public Collection $novedades, public string $hoy)
    {
    }

    private function vencidos(): Collection
    {
        return $this->novedades->filter(fn ($n) => str_contains($n['plazo']['texto'], 'Venció'))->values();
    }

    public function envelope(): Envelope
    {
        $vencidos = $this->vencidos()->count();
        $porVencer = $this->novedades->count() - $vencidos;
        $fecha = Carbon::parse($this->hoy)->format('d/m/Y');

        return new Envelope(subject: "Resumen de compromisos · {$fecha}: {$vencidos} vencidos sin evaluar, {$porVencer} por vencer");
    }

    public function content(): Content
    {
        $vencidos = $this->vencidos();

        return new Content(
            view: 'emails.resumen-compromisos',
            with: [
                'fecha' => Carbon::parse($this->hoy)->format('d/m/Y'),
                'vencidos' => $vencidos,
                'porVencer' => $this->novedades->reject(fn ($n) => $vencidos->contains($n))->values(),
            ],
        );
    }
}
