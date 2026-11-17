<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Decisión de Administración sobre un descuento sugerido por tardanza o falta (uno por persona y día).
        // Sin fila = sugerido y pendiente de revisar. El monto se guarda al decidir, para que un cambio de sueldo no altere lo ya confirmado.
        Schema::create('asistencia_descuentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('tipo', 12);      // tardanza | falta
            $table->string('tramo', 12);     // fijo | mitad | completo
            $table->string('entrada', 5)->nullable();
            $table->string('estado', 14);    // aplicado | no_aplicado | justificada
            $table->decimal('monto', 10, 2)->default(0);
            $table->string('comentario', 500)->nullable();
            $table->string('sustento_ruta')->nullable();
            $table->string('sustento_nombre')->nullable();
            $table->string('sustento_mime', 100)->nullable();
            $table->foreignId('decidido_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empleado_id', 'fecha']);
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_descuentos');
    }
};
