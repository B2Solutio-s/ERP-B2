<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Puesto" y "Cargo" seleccionados por la reclutadora en Gestión de
     * candidatos, igual que "Campaña" ya existente (texto libre validado
     * contra el catálogo, no FK — mismo patrón que campaña/distrito en
     * esta misma tabla).
     */
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('puesto')->nullable()->after('agente_reclutador');
            $table->string('cargo')->nullable()->after('campana');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['puesto', 'cargo']);
        });
    }
};
