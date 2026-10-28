<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            // ¿Tiene hijos menores de edad? Null = ficha anterior a esta pregunta.
            $table->boolean('hijos_menores')->nullable()->after('numero_hijos');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('hijos_menores');
        });
    }
};
