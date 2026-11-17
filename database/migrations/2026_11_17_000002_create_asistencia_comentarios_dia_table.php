<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nota de RRHH sobre todo un día de asistencia (p. ej. «la máquina de marcación se malogró»); una por fecha.
        Schema::create('asistencia_comentarios_dia', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->string('comentario', 1000);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_comentarios_dia');
    }
};
