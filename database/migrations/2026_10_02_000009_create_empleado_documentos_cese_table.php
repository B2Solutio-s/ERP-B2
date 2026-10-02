<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleado_documentos_cese', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->string('tipo');
            $table->string('nombre_original');
            $table->string('ruta');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->foreignId('subido_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empleado_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleado_documentos_cese');
    }
};
