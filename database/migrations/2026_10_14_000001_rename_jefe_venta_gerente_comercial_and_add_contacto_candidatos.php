<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('puestos')->where('nombre', 'Jefe de Venta')->update(['nombre' => 'Gerente Comercial']);

        DB::table('reclutamiento_candidatos')->where('puesto', 'JEFE DE VENTA')->update(['puesto' => 'GERENTE COMERCIAL']);
        DB::table('empleados')->where('puesto', 'JEFE DE VENTA')->update(['puesto' => 'GERENTE COMERCIAL']);
        DB::table('empleados')->where('contrato_puesto', 'JEFE DE VENTA')->update(['contrato_puesto' => 'GERENTE COMERCIAL']);

        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('direccion')->nullable()->after('distrito');
            $table->string('email')->nullable()->after('direccion');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['direccion', 'email']);
        });

        DB::table('puestos')->where('nombre', 'Gerente Comercial')->update(['nombre' => 'Jefe de Venta']);
    }
};
