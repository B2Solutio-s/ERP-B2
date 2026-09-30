<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidato_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reclutamiento_candidato_id')->constrained('reclutamiento_candidatos')->cascadeOnDelete();
            $table->string('tipo');
            $table->string('nombre_original');
            $table->string('ruta');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->foreignId('subido_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['reclutamiento_candidato_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidato_documentos');
    }
};
