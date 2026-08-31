<?php

namespace App\Mail;

use App\Models\OnboardingInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OnboardingInvitation $invitacion)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Completa tu registro en ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.onboarding-invitation',
            with: [
                'nombreCandidato' => $this->invitacion->nombre_candidato,
                'url' => route('onboarding.form', $this->invitacion->token),
                'expiraEn' => $this->invitacion->expira_en,
            ],
        );
    }
}
