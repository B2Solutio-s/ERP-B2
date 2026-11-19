<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajustes generales clave → valor (por ahora: destinatarios de los avisos de Seguimiento).
        Schema::create('ajustes', function (Blueprint $table) {
            $table->string('clave')->primary();
            $table->text('valor')->nullable();
            $table->timestamps();
        });

        // Compromisos (con plazo y evaluación) y memorándums (se acumulan) de cada trabajador.
        Schema::create('seguimiento_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->string('tipo', 20); // compromiso | memorandum
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->date('fecha_desde'); // compromiso: inicio del plazo · memorándum: fecha del documento
            $table->date('fecha_hasta')->nullable(); // solo compromisos
            $table->string('estado', 20)->default('pendiente'); // compromiso: pendiente|cumplido|no_cumplido · memorándum: registrado
            $table->foreignId('evaluado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluado_at')->nullable();
            $table->text('evaluacion_observacion')->nullable();
            $table->string('documento_nombre');
            $table->string('documento_ruta');
            $table->string('documento_mime')->nullable();
            $table->timestamp('enviado_trabajador_at')->nullable();
            $table->string('enviado_trabajador_a')->nullable();
            $table->string('envio_error', 500)->nullable();
            $table->foreignId('registrado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empleado_id', 'tipo', 'estado']);
        });

        // Un aviso a gerencia por cada múltiplo de 3 alcanzado (3, 6, 9…), para no repetirlo.
        Schema::create('seguimiento_avisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->unsignedSmallInteger('cantidad');
            $table->text('destinatarios');
            $table->timestamp('enviado_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['empleado_id', 'tipo', 'cantidad']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguimiento_avisos');
        Schema::dropIfExists('seguimiento_registros');
        Schema::dropIfExists('ajustes');
    }
};
