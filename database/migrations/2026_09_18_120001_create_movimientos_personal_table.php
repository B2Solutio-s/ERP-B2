<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('movimientos_personal', function (Blueprint $table) {
            $table->id();
            $table->string('ejecutivo');
            $table->string('campana');
            $table->date('fecha_ingreso');
            $table->date('fecha_salida')->nullable();
            $table->string('obs')->nullable();
            $table->foreignId('registrado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_personal');
    }
};
