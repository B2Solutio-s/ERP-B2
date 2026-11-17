<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Periodos de vacaciones registrados por el administrador. Los días son calendario (corridos, incluso feriados y domingos).
        Schema::create('vacaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedSmallInteger('dias');
            $table->string('estado', 12)->default('aprobada');   // aprobada | cancelada
            $table->string('comentario', 500)->nullable();
            $table->string('documento_ruta')->nullable();
            $table->string('documento_nombre')->nullable();
            $table->string('documento_mime', 100)->nullable();
            $table->foreignId('registrado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empleado_id', 'fecha_inicio']);
            $table->index('fecha_fin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacaciones');
    }
};
