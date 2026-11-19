<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_delivery_resumenes', function (Blueprint $table) {
            $table->date('desde')->nullable()->after('id');
            $table->date('hasta')->nullable()->after('desde');
        });

        // Lo ya copiado era por mes: pasa a ser el rango del mes completo.
        DB::statement("UPDATE crm_delivery_resumenes SET desde = STR_TO_DATE(CONCAT(mes, '-01'), '%Y-%m-%d'), hasta = LAST_DAY(STR_TO_DATE(CONCAT(mes, '-01'), '%Y-%m-%d'))");

        Schema::table('crm_delivery_resumenes', function (Blueprint $table) {
            $table->dropUnique(['mes', 'vista', 'crm_user_id']);
        });

        Schema::table('crm_delivery_resumenes', function (Blueprint $table) {
            $table->date('desde')->nullable(false)->change();
            $table->date('hasta')->nullable(false)->change();
            $table->dropColumn('mes');
            $table->unique(['desde', 'hasta', 'vista', 'crm_user_id'], 'crm_resumen_rango_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crm_delivery_resumenes', function (Blueprint $table) {
            $table->dropUnique('crm_resumen_rango_unique');
            $table->string('mes', 7)->nullable()->after('id');
        });

        DB::statement("UPDATE crm_delivery_resumenes SET mes = DATE_FORMAT(desde, '%Y-%m')");

        Schema::table('crm_delivery_resumenes', function (Blueprint $table) {
            $table->dropColumn(['desde', 'hasta']);
            $table->unique(['mes', 'vista', 'crm_user_id']);
        });
    }
};
