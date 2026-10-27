<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Texto libre: la función y el motivo de cese superaban los 255 caracteres.
        Schema::table('empleado_empleos_anteriores', function (Blueprint $table) {
            $table->text('funcion_principal')->nullable()->change();
            $table->text('motivo_cese')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('empleado_empleos_anteriores', function (Blueprint $table) {
            $table->string('funcion_principal')->nullable()->change();
            $table->string('motivo_cese')->nullable()->change();
        });
    }
};
