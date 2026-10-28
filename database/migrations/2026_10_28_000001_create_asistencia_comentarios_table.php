<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_comentarios', function (Blueprint $table) {
            $table->id();
            // Mismo ID que usa asistencia_marcaciones: el comentario sirve también si la huella aún no está vinculada.
            $table->string('biometrico_id', 24);
            $table->date('fecha');
            $table->string('comentario', 500);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['biometrico_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_comentarios');
    }
};
