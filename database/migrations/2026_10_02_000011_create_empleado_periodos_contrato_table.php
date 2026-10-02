<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleado_periodos_contrato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->string('tipo'); // 'prueba' | 'renovacion'
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedTinyInteger('duracion_meses')->nullable(); // null para 'prueba' (siempre 3 meses, implícito)
            $table->boolean('cese')->default(false);
            $table->string('cese_tipo')->nullable(); // 'renuncia' | 'despido' | 'vencimiento'
            $table->date('cese_fecha')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleado_periodos_contrato');
    }
};
