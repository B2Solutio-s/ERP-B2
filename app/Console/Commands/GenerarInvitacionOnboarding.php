<?php

namespace App\Console\Commands;

use App\Models\OnboardingInvitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('onboarding:invitar {nombre? : Nombre del candidato} {email? : Email del candidato} {--dias=7 : Dias de vigencia del link}')]
#[Description('Genera un link unico de onboarding para que un nuevo trabajador llene su formulario de alta')]
class GenerarInvitacionOnboarding extends Command
{
    public function handle(): int
    {
        $invitacion = OnboardingInvitation::generar(
            nombreCandidato: $this->argument('nombre'),
            emailCandidato: $this->argument('email'),
            diasValidez: (int) $this->option('dias'),
        );

        $url = route('onboarding.form', $invitacion->token);

        $this->info('Invitacion creada correctamente.');
        $this->line("Link: {$url}");
        $this->line("Vigente hasta: {$invitacion->expira_en->format('d/m/Y H:i')}");

        return self::SUCCESS;
    }
}
