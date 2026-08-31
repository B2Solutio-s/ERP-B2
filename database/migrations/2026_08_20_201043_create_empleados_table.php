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
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_invitation_id')->constrained('onboarding_invitations')->cascadeOnDelete();

            // Cabecera de la ficha
            $table->string('campana')->nullable();
            $table->date('fecha_capa')->nullable();
            $table->string('puesto');
            $table->string('departamento');
            $table->date('fecha_ingreso');

            // I. Datos personales
            $table->string('documento_identidad');
            $table->string('apellido_paterno');
            $table->string('apellido_materno');
            $table->string('nombres');
            $table->date('fecha_nacimiento');
            $table->string('lugar_nacimiento')->nullable();
            $table->unsignedTinyInteger('numero_hijos')->nullable();
            $table->string('estado_civil')->nullable();
            $table->string('sexo', 1)->nullable();
            $table->string('direccion');
            $table->string('vivienda_tipo')->nullable();
            $table->string('vivienda_tenencia')->nullable();
            $table->string('distrito')->nullable();
            $table->string('provincia')->nullable();
            $table->string('departamento_residencia')->nullable();
            $table->string('celular_llamadas');
            $table->string('celular_whatsapp')->nullable();
            $table->string('email')->unique();
            $table->string('contacto_emergencia_nombre');
            $table->string('contacto_emergencia_parentesco')->nullable();
            $table->string('contacto_emergencia_telefono');

            // II. Sistema pensionario
            $table->boolean('pension_afiliado')->nullable();
            $table->string('pension_sistema')->nullable();
            $table->string('pension_afp')->nullable();
            $table->string('pension_cuspp')->nullable();

            // VI. Salud
            $table->boolean('salud_antecedentes')->nullable();
            $table->boolean('salud_enfermedad_actual')->nullable();
            $table->text('salud_enfermedad_detalle')->nullable();
            $table->text('salud_medicamentos')->nullable();

            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
