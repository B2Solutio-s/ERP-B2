<?php

namespace App\Console\Commands;

use App\Mail\OnboardingInvitationMail;
use App\Models\OnboardingInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CorreoPrueba extends Command
{
    protected $signature = 'correo:prueba {destino : Correo al que se envía la muestra} {--nombre=Jerry Renato : Nombre de ejemplo} {--tipo=ingreso : actualizacion | ingreso}';

    protected $description = 'Envía una muestra del correo de invitación a la ficha (no crea ninguna invitación real)';

    public function handle(): int
    {
        // Invitación de ejemplo, sin guardar: el link de la muestra no sirve para llenar una ficha.
        $invitacion = new OnboardingInvitation([
            'token' => 'MUESTRA-'.str_repeat('X', 32),
            'nombre_candidato' => $this->option('nombre'),
            'expira_en' => now()->addDays(7),
        ]);

        Mail::to($this->argument('destino'))->send(new OnboardingInvitationMail($invitacion, $this->option('tipo')));

        $this->info('Muestra enviada a '.$this->argument('destino'));

        return self::SUCCESS;
    }
}
