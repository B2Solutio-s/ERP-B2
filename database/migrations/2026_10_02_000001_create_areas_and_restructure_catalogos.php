<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reestructura el catálogo: "Área" pasa a ser la raíz, y Puesto, Campaña y
     * Cargo son 3 catálogos hermanos filtrados por área (ya no una cadena
     * Puesto -> Campaña -> Cargo). `usa_campana`/`usa_cargo` en `areas` indican
     * qué dimensiones aplican para esa área, para no hardcodear nombres de área
     * en el código.
     */
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->boolean('usa_campana')->default(true);
            $table->boolean('usa_cargo')->default(true);
            $table->timestamps();
        });

        Schema::table('puestos', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('id')->constrained('areas')->restrictOnDelete();
        });

        Schema::table('campanas', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('id')->constrained('areas')->restrictOnDelete();
        });

        // MySQL no permite soltar un índice único mientras siga respaldando una FK,
        // así que primero se suelta la FK (dejando el índice e id huérfanos) y
        // recién después se puede soltar el índice compuesto.
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropForeign(['campana_id']);
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->dropUnique(['campana_id', 'nombre']);
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('id')->constrained('areas')->restrictOnDelete();
        });

        $comercialId = DB::table('areas')->insertGetId([
            'nombre' => 'Comercial', 'usa_campana' => true, 'usa_cargo' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $administrativoId = DB::table('areas')->insertGetId([
            'nombre' => 'Administrativo', 'usa_campana' => false, 'usa_cargo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // "Ejecutivo" ya existía como Puesto raíz -> se reutiliza bajo el área
        // Comercial, y se agregan los otros 3 puestos comerciales nuevos.
        DB::table('puestos')->where('nombre', 'Ejecutivo')->update(['area_id' => $comercialId]);
        foreach (['Supervisor', 'Jefe de Venta', 'Mesa de Control (MC)'] as $nombre) {
            DB::table('puestos')->insert(['nombre' => $nombre, 'area_id' => $comercialId, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Las campañas Móvil/Fija (antes colgadas del puesto Ejecutivo) pasan a
        // colgar directamente del área Comercial.
        DB::table('campanas')->whereIn('nombre', ['Móvil', 'Fija'])->update(['area_id' => $comercialId]);

        // Las "campañas" TI/MKT/RRHH/MC/SUPERVISOR eran en realidad los puestos
        // administrativos/comerciales de antes de este cambio: se eliminan como
        // campañas (ya no aplica Campaña para Administrativo, y MC/Supervisor ya
        // quedaron migrados arriba como Puestos de Comercial). Esto debe pasar
        // ANTES de borrar el viejo Puesto "Administrativo", porque esas campañas
        // todavía lo referencian vía `puesto_id` (restrictOnDelete).
        $campanasObsoletas = DB::table('campanas')->whereIn('nombre', ['TI', 'MKT', 'RRHH', 'MC', 'SUPERVISOR'])->pluck('id', 'nombre');

        DB::table('cargos')->whereIn('campana_id', $campanasObsoletas->values())->delete();
        DB::table('campanas')->whereIn('id', $campanasObsoletas->values())->delete();

        // El viejo Puesto "Administrativo" se elimina (ahora es un Área, no un
        // Puesto) y se reemplaza por los 4 puestos administrativos reales.
        DB::table('puestos')->where('nombre', 'Administrativo')->delete();
        foreach (['MKT', 'TI', 'Administrador', 'RRHH'] as $nombre) {
            DB::table('puestos')->insert(['nombre' => $nombre, 'area_id' => $administrativoId, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Los cargos Practicante/Asistente/Analista/Coordinador estaban
        // duplicados por cada "campaña" administrativa (TI/MKT/RRHH); con Cargo
        // ahora a nivel de Área, se consolidan en un solo set bajo Administrativo.
        foreach (['Practicante', 'Asistente', 'Analista', 'Coordinador'] as $nombre) {
            DB::table('cargos')->insert(['nombre' => $nombre, 'area_id' => $administrativoId, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('campanas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('puesto_id');
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->dropColumn('campana_id');
        });

        Schema::table('puestos', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable(false)->change();
        });

        Schema::table('campanas', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable(false)->change();
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable(false)->change();
            $table->unique(['area_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropUnique(['area_id', 'nombre']);
            $table->dropConstrainedForeignId('area_id');
            $table->foreignId('campana_id')->nullable()->after('id')->constrained('campanas')->restrictOnDelete();
        });

        Schema::table('campanas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->foreignId('puesto_id')->nullable()->after('id')->constrained('puestos')->restrictOnDelete();
        });

        Schema::table('puestos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
        });

        Schema::dropIfExists('areas');
    }
};
