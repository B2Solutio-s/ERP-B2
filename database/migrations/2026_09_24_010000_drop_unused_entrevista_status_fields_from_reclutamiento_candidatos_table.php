<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['resultado_entrevista', 'motivo_desistio', 'estatus']);
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('resultado_entrevista')->nullable();
            $table->string('motivo_desistio')->nullable();
            $table->string('estatus')->nullable();
        });
    }
};
