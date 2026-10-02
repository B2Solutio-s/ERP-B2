<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('contrato_area')->nullable()->after('contrato_puesto');
            $table->string('contrato_campana')->nullable()->after('contrato_area');
            $table->string('contrato_cargo')->nullable()->after('contrato_campana');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['contrato_area', 'contrato_campana', 'contrato_cargo']);
        });
    }
};
