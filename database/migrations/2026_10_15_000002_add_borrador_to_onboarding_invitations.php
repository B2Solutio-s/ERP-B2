<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_invitations', function (Blueprint $table) {
            // Avance de la ficha (cifrado). Se borra al enviar la ficha.
            $table->longText('borrador')->nullable();
            $table->timestamp('borrador_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_invitations', function (Blueprint $table) {
            $table->dropColumn(['borrador', 'borrador_at']);
        });
    }
};
