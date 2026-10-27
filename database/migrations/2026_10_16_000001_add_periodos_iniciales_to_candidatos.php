<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            // Contratos ya firmados de quien aún no llena su ficha: pasan al historial del trabajador al completarla.
            $table->json('periodos_iniciales')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn('periodos_iniciales');
        });
    }
};
