<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recorrido del puesto tras la entrevista 1: 'capacitacion' (capacitación 1 y 2) o 'entrevista_2' (segunda entrevista).
        Schema::table('puestos', function (Blueprint $table) {
            $table->string('recorrido', 20)->default('capacitacion')->after('usa_campana');
        });

        // Ejecutivo sigue con capacitación; administrativos, supervisores, mesa de control y gerente comercial pasan por una 2.ª entrevista.
        DB::table('puestos')->whereRaw('LOWER(nombre) <> ?', ['ejecutivo'])->update(['recorrido' => 'entrevista_2']);

        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->date('entrevista_2')->nullable()->after('obs_2_reprogramada');
            $table->string('hora_entrevista_2', 5)->nullable()->after('entrevista_2');
            $table->string('asistio_entrevista_2', 30)->nullable()->after('hora_entrevista_2');
            $table->text('obs_entrevista_2')->nullable()->after('asistio_entrevista_2');
            $table->date('entrevista_2_reprogramada')->nullable()->after('obs_entrevista_2');
            $table->string('asistio_entrevista_2_reprogramada', 30)->nullable()->after('entrevista_2_reprogramada');
            $table->text('obs_entrevista_2_reprogramada')->nullable()->after('asistio_entrevista_2_reprogramada');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn([
                'entrevista_2', 'hora_entrevista_2', 'asistio_entrevista_2', 'obs_entrevista_2',
                'entrevista_2_reprogramada', 'asistio_entrevista_2_reprogramada', 'obs_entrevista_2_reprogramada',
            ]);
        });

        Schema::table('puestos', function (Blueprint $table) {
            $table->dropColumn('recorrido');
        });
    }
};
