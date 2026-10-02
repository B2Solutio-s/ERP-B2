<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tipo_contrato` en Área es el tipo por defecto para esa área (ej. toda
     * el área Comercial genera contrato "ejecutivo"); `tipo_contrato` en Cargo
     * es una excepción puntual que, si está presente, manda sobre el default
     * del área (ej. el cargo "Practicante" siempre genera "practicas", sin
     * importar que esté dentro del área Administrativo). Así la detección del
     * tipo de contrato es 100% dato de catálogo, no nombres hardcodeados en
     * el código.
     */
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('tipo_contrato')->nullable()->after('usa_cargo');
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->string('tipo_contrato')->nullable()->after('nombre');
        });

        DB::table('areas')->where('nombre', 'Comercial')->update(['tipo_contrato' => 'ejecutivo']);
        DB::table('areas')->where('nombre', 'Administrativo')->update(['tipo_contrato' => 'administrativo']);
        DB::table('cargos')->where('nombre', 'Practicante')->update(['tipo_contrato' => 'practicas']);
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('tipo_contrato');
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->dropColumn('tipo_contrato');
        });
    }
};
