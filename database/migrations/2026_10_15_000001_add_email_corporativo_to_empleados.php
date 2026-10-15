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
            $table->string('email_corporativo', 150)->nullable()->after('email');
        });

        // Quien ya tiene ficha (es trabajador): su correo corporativo pasa del candidato al trabajador.
        $filas = DB::table('reclutamiento_candidatos as c')
            ->join('onboarding_invitations as i', 'i.reclutamiento_candidato_id', '=', 'c.id')
            ->join('empleados as e', 'e.onboarding_invitation_id', '=', 'i.id')
            ->whereNotNull('c.email_corporativo')
            ->get(['c.id as candidato_id', 'c.email_corporativo', 'e.id as empleado_id']);

        foreach ($filas as $fila) {
            DB::table('empleados')->where('id', $fila->empleado_id)->update(['email_corporativo' => $fila->email_corporativo]);
            DB::table('reclutamiento_candidatos')->where('id', $fila->candidato_id)->update(['email_corporativo' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('email_corporativo');
        });
    }
};
