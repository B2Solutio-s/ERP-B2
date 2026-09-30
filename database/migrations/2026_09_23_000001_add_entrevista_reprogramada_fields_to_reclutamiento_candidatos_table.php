<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->date('fecha_reprogramada')->nullable()->after('asistio_entrevista');
            $table->string('asistio_entrevista_reprogramada')->nullable()->after('fecha_reprogramada');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['fecha_reprogramada', 'asistio_entrevista_reprogramada']);
        });
    }
};
