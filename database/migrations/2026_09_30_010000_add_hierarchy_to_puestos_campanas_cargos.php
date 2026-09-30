<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaza el catálogo en jerarquía Puesto -> Campaña -> Cargo:
     * - Cada campaña pertenece a un puesto (Ejecutivo/Administrativo).
     * - Cada cargo pertenece a una campaña (solo las campañas
     *   administrativas que lo necesiten tendrán cargos; el resto
     *   simplemente no tiene ninguno, sin necesidad de reglas hardcodeadas).
     */
    public function up(): void
    {
        Schema::table('campanas', function (Blueprint $table) {
            $table->foreignId('puesto_id')->nullable()->after('id')->constrained('puestos')->restrictOnDelete();
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->dropUnique(['nombre']);
            $table->foreignId('campana_id')->nullable()->after('id')->constrained('campanas')->restrictOnDelete();
        });

        // Un mismo nombre de cargo (ej. "Practicante") puede repetirse en varias
        // campañas (TI, MKT, RRHH), pero no dos veces dentro de la misma campaña.
        Schema::table('cargos', function (Blueprint $table) {
            $table->unique(['campana_id', 'nombre']);
        });

        $ejecutivoId = DB::table('puestos')->insertGetId(['nombre' => 'Ejecutivo', 'created_at' => now(), 'updated_at' => now()]);
        $administrativoId = DB::table('puestos')->insertGetId(['nombre' => 'Administrativo', 'created_at' => now(), 'updated_at' => now()]);

        // Las campañas "Móvil"/"Fija" que ya existían (usadas por Ejecutivo) pasan a
        // pertenecer al puesto Ejecutivo; si por alguna razón no existen (BD vacía),
        // se crean.
        foreach (['Móvil', 'Fija'] as $nombre) {
            $existente = DB::table('campanas')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first();
            if ($existente) {
                DB::table('campanas')->where('id', $existente->id)->update(['puesto_id' => $ejecutivoId]);
            } else {
                DB::table('campanas')->insert(['nombre' => $nombre, 'puesto_id' => $ejecutivoId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $cargosConPracticaAsistenteAnalistaCoordinador = ['TI', 'MKT', 'RRHH'];
        $campanasSinCargos = ['MC', 'SUPERVISOR'];

        foreach ([...$cargosConPracticaAsistenteAnalistaCoordinador, ...$campanasSinCargos] as $nombre) {
            $campanaId = DB::table('campanas')->insertGetId([
                'nombre' => $nombre,
                'puesto_id' => $administrativoId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (in_array($nombre, $cargosConPracticaAsistenteAnalistaCoordinador, true)) {
                foreach (['Practicante', 'Asistente', 'Analista', 'Coordinador'] as $cargo) {
                    DB::table('cargos')->insert([
                        'nombre' => $cargo,
                        'campana_id' => $campanaId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropUnique(['campana_id', 'nombre']);
            $table->dropConstrainedForeignId('campana_id');
            $table->unique('nombre');
        });

        Schema::table('campanas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('puesto_id');
        });
    }
};
