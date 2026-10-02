<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('contrato_duracion')->nullable()->after('contrato_cargo');
            $table->decimal('contrato_remuneracion_movilidad', 10, 2)->nullable()->after('contrato_remuneracion');
            $table->string('contrato_area_practicas')->nullable()->after('contrato_cargo');
            $table->string('contrato_universidad')->nullable()->after('contrato_area_practicas');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['contrato_duracion', 'contrato_remuneracion_movilidad', 'contrato_area_practicas', 'contrato_universidad']);
        });
    }
};
