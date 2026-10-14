<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('biometrico_id', 24)->nullable()->unique()->after('documento_identidad');
        });

        // Solo las personas con ficha completada (empleado) conservan su vínculo.
        $vinculados = DB::table('reclutamiento_candidatos')
            ->join('onboarding_invitations', 'onboarding_invitations.reclutamiento_candidato_id', '=', 'reclutamiento_candidatos.id')
            ->join('empleados', 'empleados.onboarding_invitation_id', '=', 'onboarding_invitations.id')
            ->whereNotNull('reclutamiento_candidatos.biometrico_id')
            ->get(['reclutamiento_candidatos.biometrico_id', 'empleados.id as empleado_id']);

        foreach ($vinculados as $fila) {
            DB::table('empleados')->where('id', $fila->empleado_id)->update(['biometrico_id' => $fila->biometrico_id]);
        }

        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropUnique(['biometrico_id']);
            $table->dropColumn('biometrico_id');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('biometrico_id', 24)->nullable()->unique()->after('dni_ce');
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropUnique(['biometrico_id']);
            $table->dropColumn('biometrico_id');
        });
    }
};
