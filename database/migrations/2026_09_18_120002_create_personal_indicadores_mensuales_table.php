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
        Schema::create('personal_indicadores_mensuales', function (Blueprint $table) {
            $table->id();
            $table->string('campana');
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->unsignedInteger('cantidad_supervisores')->default(0);
            $table->unsignedInteger('inicio_vendedores_activos')->default(0);
            $table->timestamps();

            $table->unique(['campana', 'anio', 'mes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_indicadores_mensuales');
    }
};
