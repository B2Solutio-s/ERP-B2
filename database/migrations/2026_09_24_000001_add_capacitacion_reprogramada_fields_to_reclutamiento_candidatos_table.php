<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->date('capacitacion_1_reprogramada')->nullable()->after('obs_1');
            $table->string('asistio_cap_1_reprogramada')->nullable()->after('capacitacion_1_reprogramada');
            $table->date('capacitacion_2_reprogramada')->nullable()->after('obs_2');
            $table->string('asistio_cap_2_reprogramada')->nullable()->after('capacitacion_2_reprogramada');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn([
                'capacitacion_1_reprogramada',
                'asistio_cap_1_reprogramada',
                'capacitacion_2_reprogramada',
                'asistio_cap_2_reprogramada',
            ]);
        });
    }
};
