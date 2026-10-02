<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los documentos de cese se cuelgan del período puntual del historial
     * en el que se registra el cese, no del empleado en general.
     */
    public function up(): void
    {
        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
        });

        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->dropUnique(['empleado_id', 'tipo']);
        });

        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->dropColumn('empleado_id');
        });

        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->foreignId('periodo_id')->after('id')->constrained('empleado_periodos_contrato')->cascadeOnDelete();
            $table->unique(['periodo_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->dropForeign(['periodo_id']);
        });

        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->dropUnique(['periodo_id', 'tipo']);
        });

        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->dropColumn('periodo_id');
        });

        Schema::table('empleado_documentos_cese', function (Blueprint $table) {
            $table->foreignId('empleado_id')->after('id')->constrained('empleados')->cascadeOnDelete();
            $table->unique(['empleado_id', 'tipo']);
        });
    }
};
