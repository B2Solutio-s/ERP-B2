<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tipo_contrato` en Puesto es una excepción puntual igual que en Cargo:
     * si está presente, manda sobre el default del área. Se usa para el
     * puesto "Mesa de Control (MC)", que aunque vive bajo el área Comercial
     * genera un contrato administrativo, no el de ejecutivo que usa el
     * resto del área.
     */
    public function up(): void
    {
        Schema::table('puestos', function (Blueprint $table) {
            $table->string('tipo_contrato')->nullable()->after('nombre');
        });

        DB::table('puestos')->where('nombre', 'Mesa de Control (MC)')->update(['tipo_contrato' => 'administrativo']);
    }

    public function down(): void
    {
        Schema::table('puestos', function (Blueprint $table) {
            $table->dropColumn('tipo_contrato');
        });
    }
};
