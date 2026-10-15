<?php

namespace App\Mail;

use App\Models\OnboardingInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class OnboardingInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OnboardingInvitation $invitacion, public ?string $tipo = null)
    {
    }

    /** 'actualizacion' (desde Registro de ingresos) o 'ingreso' (desde Proceso de capacitación; valor por defecto). */
    protected function tipo(): string
    {
        return $this->tipo === 'actualizacion' ? 'actualizacion' : 'ingreso';
    }

    protected function variante(): array
    {
        return $this->tipo() === 'actualizacion'
            ? [
                'asunto' => 'Completa tu ficha de actualización de datos – B2 Solutions',
                'titulo' => 'Ficha de actualización de datos',
                'imagen' => 'titulo-modelo',
                'alt' => 'Recursos Humanos · Ficha de Actualización de datos',
                'intro' => 'Desde Recursos Humanos estamos actualizando los datos de todo el personal. Necesitamos que completes tu ficha de actualización de datos con tu información personal y laboral.',
                'boton' => 'Actualizar datos',
            ]
            : [
                'asunto' => 'Completa tu ficha de ingreso – B2 Solutions',
                'titulo' => 'Ficha de ingreso',
                'imagen' => 'titulo-ingreso',
                'alt' => 'Recursos Humanos · Ficha de Ingreso',
                'intro' => 'Te damos la bienvenida a B2. Para completar tu proceso de ingreso necesitamos que llenes tu ficha de ingreso con tu información personal y laboral.',
                'boton' => 'Completar ficha',
            ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->variante()['asunto'],
        );
    }

    public function content(): Content
    {
        $primerNombre = Str::title(Str::lower(explode(' ', trim((string) $this->invitacion->nombre_candidato))[0] ?? ''));

        return new Content(
            view: 'emails.invitacion-ficha',
            text: 'emails.invitacion-ficha-texto',
            with: [
                'primerNombre' => $primerNombre !== '' ? $primerNombre : 'colaborador',
                'url' => route('onboarding.form', $this->invitacion->token),
                'expiraEn' => $this->invitacion->expira_en,
                'v' => $this->variante(),
            ],
        );
    }
}
