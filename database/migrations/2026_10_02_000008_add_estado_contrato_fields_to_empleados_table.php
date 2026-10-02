<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('contrato_periodo')->nullable()->after('contrato_fecha_firma');
            $table->boolean('contrato_cese')->default(false)->after('contrato_periodo');
            $table->string('contrato_cese_periodo')->nullable()->after('contrato_cese');
            $table->string('contrato_cese_tipo')->nullable()->after('contrato_cese_periodo');
            $table->date('contrato_cese_fecha')->nullable()->after('contrato_cese_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn([
                'contrato_periodo',
                'contrato_cese',
                'contrato_cese_periodo',
                'contrato_cese_tipo',
                'contrato_cese_fecha',
            ]);
        });
    }
};
