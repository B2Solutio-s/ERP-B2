<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_campana', function (Blueprint $table) {
            $table->id();
            $table->string('campana', 150);
            $table->decimal('importe_gastado', 10, 2)->default(0);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->unsignedInteger('clics_enlace')->default(0);
            $table->unsignedInteger('resultados_meta')->default(0);
            $table->unsignedInteger('postulantes')->default(0);
            $table->unsignedInteger('no_contesto')->default(0);
            $table->unsignedInteger('pendiente_llamada')->default(0);
            $table->unsignedInteger('no_interesados')->default(0);
            $table->unsignedInteger('no_califica')->default(0);
            $table->unsignedInteger('entrevistas')->default(0);
            $table->unsignedInteger('agendados_capacitacion')->default(0);
            $table->unsignedInteger('ingresos_reales')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Historial de cambios: una fila por campo modificado; las filas de un mismo guardado comparten `lote`.
        Schema::create('resultado_campana_cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resultado_campana_id')->constrained('resultados_campana')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('lote');
            $table->string('accion', 20); // creado | editado | eliminado
            $table->string('campo', 40)->nullable();
            $table->string('valor_anterior')->nullable();
            $table->string('valor_nuevo')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['resultado_campana_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultado_campana_cambios');
        Schema::dropIfExists('resultados_campana');
    }
};
