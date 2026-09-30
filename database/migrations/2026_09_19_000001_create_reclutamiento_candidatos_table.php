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
        Schema::create('reclutamiento_candidatos', function (Blueprint $table) {
            $table->id();
            $table->integer('anio')->nullable();
            $table->string('mes_tipologia')->nullable();
            $table->string('mes')->nullable();
            $table->integer('dia')->nullable();
            $table->date('fecha_gestion')->nullable();
            $table->string('base')->nullable();
            $table->string('agente_reclutador')->nullable();
            $table->string('campana')->nullable();
            $table->string('dni_ce')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->integer('edad')->nullable();
            $table->string('nombres_apellidos')->nullable();
            $table->string('numero_celular')->nullable();
            $table->string('distrito')->nullable();
            $table->string('experiencia')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('tipificacion')->nullable();
            $table->string('subtipificacion_rechazo')->nullable();
            $table->string('fuente')->nullable();
            $table->string('aceptacion_entrevista')->nullable();
            $table->date('fecha_entrevista')->nullable();
            $table->string('asistio_entrevista')->nullable();
            $table->string('resultado_entrevista')->nullable();
            $table->string('motivo_desistio')->nullable();
            $table->date('fecha_capacitacion')->nullable();
            $table->string('estatus')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->string('entrego_documentos')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reclutamiento_candidatos');
    }
};
