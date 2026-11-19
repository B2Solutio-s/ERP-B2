<?php

namespace App\Mail;

use App\Models\SeguimientoEnvioGerencia;
use App\Models\SeguimientoRegistro;
use App\Services\Seguimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class SeguimientoRegistrosGerenciaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SeguimientoEnvioGerencia $envio)
    {
    }

    /** @return Collection<int, SeguimientoRegistro> */
    private function registros(): Collection
    {
        return SeguimientoRegistro::where('empleado_id', $this->envio->empleado_id)
            ->whereIn('id', $this->envio->registro_ids ?? [])
            ->orderBy('fecha_desde')
            ->get();
    }

    public function envelope(): Envelope
    {
        $n = $this->registros()->count();

        return new Envelope(
            subject: 'Seguimiento de '.$this->envio->empleado->nombreCompleto().': '.$n.($n === 1 ? ' registro' : ' registros'),
        );
    }

    public function content(): Content
    {
        $hoy = now('America/Lima')->toDateString();
        $registros = $this->registros();

        return new Content(
            view: 'emails.seguimiento-registros-gerencia',
            with: [
                'envio' => $this->envio,
                'empleado' => $this->envio->empleado,
                'registros' => $registros->map(fn (SeguimientoRegistro $r) => [
                    'registro' => $r,
                    'estado' => $r->esCompromiso() ? Seguimiento::plazo($r, $hoy)['texto'] : 'Registrado',
                ]),
                'noCumplidos' => Seguimiento::cantidad($this->envio->empleado_id, SeguimientoRegistro::COMPROMISO),
                'memorandums' => Seguimiento::cantidad($this->envio->empleado_id, SeguimientoRegistro::MEMORANDUM),
            ],
        );
    }

    public function attachments(): array
    {
        if (! $this->envio->adjuntar_documentos) {
            return [];
        }

        return $this->registros()->map(
            fn (SeguimientoRegistro $r) => Attachment::fromStorageDisk('local', $r->documento_ruta)
                ->as($r->documento_nombre)
                ->withMime($r->documento_mime ?: 'application/octet-stream')
        )->all();
    }
}
