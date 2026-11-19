<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ejecutivos del CRM y su vínculo con el trabajador del ERP (que se identifica por DNI).
        Schema::create('crm_ejecutivos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('crm_user_id')->unique();
            $table->string('nombre');
            $table->string('correo')->nullable();
            $table->string('correo_corporativo')->nullable();
            $table->foreignId('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();
            $table->timestamps();
        });

        // Copia del «Resumen Tabla 3» (delivery) del CRM, por mes y vista (móvil = Q, fija = SOT).
        Schema::create('crm_delivery_resumenes', function (Blueprint $table) {
            $table->id();
            $table->string('mes', 7);
            $table->string('vista', 5);
            $table->unsignedBigInteger('crm_user_id');
            $table->string('nombre');
            $table->string('supervisor')->nullable();
            $table->unsignedInteger('activadas')->default(0);
            $table->unsignedInteger('flujo')->default(0);
            $table->unsignedInteger('proyectadas')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->decimal('cf_sin_igv', 10, 2)->default(0);
            $table->timestamp('sincronizado_at');
            $table->timestamps();

            $table->unique(['mes', 'vista', 'crm_user_id']);
        });

        // Meta opcional del compromiso: líneas (Q) y/o SOTs.
        Schema::table('seguimiento_registros', function (Blueprint $table) {
            $table->unsignedSmallInteger('meta_q')->nullable()->after('fecha_hasta');
            $table->unsignedSmallInteger('meta_sots')->nullable()->after('meta_q');
        });
    }

    public function down(): void
    {
        Schema::table('seguimiento_registros', function (Blueprint $table) {
            $table->dropColumn(['meta_q', 'meta_sots']);
        });
        Schema::dropIfExists('crm_delivery_resumenes');
        Schema::dropIfExists('crm_ejecutivos');
    }
};
