<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_indicadores_mensuales', function (Blueprint $table) {
            $table->renameColumn('inicio_vendedores_activos', 'inicio_ejecutivos_activos');
        });

        DB::table('cargos')->where('nombre', 'Vendedor')->update(['nombre' => 'Ejecutivo']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_indicadores_mensuales', function (Blueprint $table) {
            $table->renameColumn('inicio_ejecutivos_activos', 'inicio_vendedores_activos');
        });

        DB::table('cargos')->where('nombre', 'Ejecutivo')->update(['nombre' => 'Vendedor']);
    }
};
