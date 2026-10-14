<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puestos', function (Blueprint $table) {
            $table->boolean('usa_campana')->default(true)->after('tipo_contrato');
        });

        DB::table('puestos')
            ->whereIn('nombre', ['Gerente Comercial', 'Mesa de Control (MC)'])
            ->update(['usa_campana' => false]);

        // El ID del biométrico se vincula a la persona (candidato APTO), exista o no su ficha todavía.
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('biometrico_id', 24)->nullable()->unique()->after('dni_ce');
        });

        $vinculados = DB::table('empleados')
            ->join('onboarding_invitations', 'onboarding_invitations.id', '=', 'empleados.onboarding_invitation_id')
            ->whereNotNull('empleados.biometrico_id')
            ->whereNotNull('onboarding_invitations.reclutamiento_candidato_id')
            ->get(['empleados.biometrico_id', 'onboarding_invitations.reclutamiento_candidato_id as candidato_id']);

        foreach ($vinculados as $fila) {
            DB::table('reclutamiento_candidatos')->where('id', $fila->candidato_id)->update(['biometrico_id' => $fila->biometrico_id]);
        }

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropUnique(['biometrico_id']);
            $table->dropColumn('biometrico_id');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('biometrico_id', 24)->nullable()->unique()->after('documento_identidad');
        });

        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropUnique(['biometrico_id']);
            $table->dropColumn('biometrico_id');
        });

        Schema::table('puestos', function (Blueprint $table) {
            $table->dropColumn('usa_campana');
        });
    }
};
