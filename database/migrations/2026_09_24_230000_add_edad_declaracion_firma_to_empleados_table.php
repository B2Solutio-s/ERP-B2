<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->unsignedTinyInteger('edad')->nullable()->after('fecha_nacimiento');

            $table->boolean('declaracion_datos_veraces')->default(false)->after('salud_medicamentos');
            $table->boolean('declaracion_autoriza_verificacion')->default(false)->after('declaracion_datos_veraces');
            $table->boolean('declaracion_capacitacion_condiciones')->default(false)->after('declaracion_autoriza_verificacion');

            $table->string('firma_dni')->nullable()->after('declaracion_capacitacion_condiciones');
            $table->longText('firma_imagen')->nullable()->after('firma_dni');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn([
                'edad',
                'declaracion_datos_veraces',
                'declaracion_autoriza_verificacion',
                'declaracion_capacitacion_condiciones',
                'firma_dni',
                'firma_imagen',
            ]);
        });
    }
};
