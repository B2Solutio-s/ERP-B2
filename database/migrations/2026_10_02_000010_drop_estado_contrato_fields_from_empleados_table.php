<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reemplaza el estado único (contrato_periodo/contrato_cese*) agregado
     * en 2026_10_02_000008 por un historial de períodos propio
     * (empleado_periodos_contrato, ver siguiente migración).
     */
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn([
                'contrato_periodo',
                'contrato_cese',
                'contrato_cese_periodo',
                'contrato_cese_tipo',
                'contrato_cese_fecha',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('contrato_periodo')->nullable()->after('contrato_fecha_firma');
            $table->boolean('contrato_cese')->default(false)->after('contrato_periodo');
            $table->string('contrato_cese_periodo')->nullable()->after('contrato_cese');
            $table->string('contrato_cese_tipo')->nullable()->after('contrato_cese_periodo');
            $table->date('contrato_cese_fecha')->nullable()->after('contrato_cese_tipo');
        });
    }
};
