<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historial de cada candidato: altas, cambios de datos del proceso y llamadas registradas.
        Schema::create('reclutamiento_candidato_eventos', function (Blueprint $table) {
            $table->id();
            // Nombre explícito: el automático supera los 64 caracteres que admite MySQL.
            $table->foreignId('reclutamiento_candidato_id')->constrained('reclutamiento_candidatos', 'id', 'rce_candidato_foreign')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tipo', 20); // alta | cambio | llamada
            $table->string('campo', 60)->nullable();
            $table->string('valor_anterior', 500)->nullable();
            $table->string('valor_nuevo', 500)->nullable();
            $table->string('nota', 1000)->nullable();
            $table->timestamps();

            $table->index(['reclutamiento_candidato_id', 'created_at'], 'rce_candidato_fecha');
        });

        // Seguimiento de llamadas: cuántas veces se intentó contactar y cuándo hay que volver a llamar.
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->unsignedSmallInteger('intentos_contacto')->default(0);
            $table->timestamp('ultimo_contacto_at')->nullable();
            $table->date('volver_a_llamar_fecha')->nullable();
            $table->string('volver_a_llamar_hora', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['intentos_contacto', 'ultimo_contacto_at', 'volver_a_llamar_fecha', 'volver_a_llamar_hora']);
        });

        Schema::dropIfExists('reclutamiento_candidato_eventos');
    }
};
