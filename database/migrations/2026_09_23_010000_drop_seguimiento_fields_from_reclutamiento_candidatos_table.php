<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['fecha_capacitacion', 'entrego_documentos']);
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->date('fecha_capacitacion')->nullable()->after('asistio_entrevista_reprogramada');
            $table->string('entrego_documentos')->nullable()->after('fecha_capacitacion');
        });
    }
};
