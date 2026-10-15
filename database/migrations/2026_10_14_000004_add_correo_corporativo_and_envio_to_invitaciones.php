<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('email_corporativo', 150)->nullable()->after('email');
        });

        Schema::table('onboarding_invitations', function (Blueprint $table) {
            $table->string('correo_a', 150)->nullable();
            $table->timestamp('correo_encolado_at')->nullable();
            $table->timestamp('correo_enviado_at')->nullable();
            $table->text('correo_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_invitations', function (Blueprint $table) {
            $table->dropColumn(['correo_a', 'correo_encolado_at', 'correo_enviado_at', 'correo_error']);
        });

        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn('email_corporativo');
        });
    }
};
