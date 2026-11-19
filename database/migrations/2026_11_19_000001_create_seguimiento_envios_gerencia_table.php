<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Envíos manuales a gerencia: el administrador elige cuántos registros (compromisos / memorándums) mandar y cuándo.
        Schema::create('seguimiento_envios_gerencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->json('registro_ids');
            $table->text('mensaje')->nullable();
            $table->boolean('adjuntar_documentos')->default(false);
            $table->text('destinatarios');
            $table->foreignId('enviado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguimiento_envios_gerencia');
    }
};
