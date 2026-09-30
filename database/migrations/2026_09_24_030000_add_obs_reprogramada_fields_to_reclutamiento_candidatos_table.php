<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->text('obs_1_reprogramada')->nullable()->after('asistio_cap_1_reprogramada');
            $table->text('obs_2_reprogramada')->nullable()->after('asistio_cap_2_reprogramada');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['obs_1_reprogramada', 'obs_2_reprogramada']);
        });
    }
};
