<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('contrato_puesto')->nullable()->after('firma_imagen');
            $table->decimal('contrato_remuneracion', 10, 2)->nullable()->after('contrato_puesto');
            $table->date('contrato_fecha_inicio')->nullable()->after('contrato_remuneracion');
            $table->date('contrato_fecha_fin')->nullable()->after('contrato_fecha_inicio');
            $table->date('contrato_fecha_firma')->nullable()->after('contrato_fecha_fin');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn([
                'contrato_puesto',
                'contrato_remuneracion',
                'contrato_fecha_inicio',
                'contrato_fecha_fin',
                'contrato_fecha_firma',
            ]);
        });
    }
};
