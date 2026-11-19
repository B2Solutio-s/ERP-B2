<?php

namespace App\Mail;

use App\Models\SeguimientoRegistro;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SeguimientoTrabajadorMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SeguimientoRegistro $registro)
    {
    }

    public function envelope(): Envelope
    {
        $tipo = $this->registro->esCompromiso() ? 'Compromiso' : 'Memorándum';

        return new Envelope(subject: "{$tipo} firmado – B2 Solutions");
    }

    public function content(): Content
    {
        $primerNombre = Str::title(Str::lower(explode(' ', trim((string) $this->registro->empleado->nombres))[0] ?? ''));

        return new Content(
            view: 'emails.seguimiento-trabajador',
            with: [
                'registro' => $this->registro,
                'primerNombre' => $primerNombre !== '' ? $primerNombre : 'colaborador',
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->registro->documento_ruta)
                ->as($this->registro->documento_nombre)
                ->withMime($this->registro->documento_mime ?: 'application/octet-stream'),
        ];
    }
}
