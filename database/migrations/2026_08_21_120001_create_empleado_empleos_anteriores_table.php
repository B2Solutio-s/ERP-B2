<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleado_empleos_anteriores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->string('empresa');
            $table->string('cargo')->nullable();
            $table->string('funcion_principal')->nullable();
            $table->string('sueldo')->nullable();
            $table->string('fecha_inicio')->nullable();
            $table->string('fecha_termino')->nullable();
            $table->string('motivo_cese')->nullable();
            $table->string('jefe_nombre')->nullable();
            $table->string('jefe_cargo')->nullable();
            $table->string('jefe_celular')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleado_empleos_anteriores');
    }
};
