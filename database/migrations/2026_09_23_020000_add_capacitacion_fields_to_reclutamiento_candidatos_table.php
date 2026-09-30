<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->date('capacitacion_1')->nullable()->after('asistio_entrevista_reprogramada');
            $table->string('asistio_cap_1')->nullable()->after('capacitacion_1');
            $table->text('obs_1')->nullable()->after('asistio_cap_1');
            $table->date('capacitacion_2')->nullable()->after('obs_1');
            $table->string('asistio_cap_2')->nullable()->after('capacitacion_2');
            $table->text('obs_2')->nullable()->after('asistio_cap_2');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['capacitacion_1', 'asistio_cap_1', 'obs_1', 'capacitacion_2', 'asistio_cap_2', 'obs_2']);
        });
    }
};
