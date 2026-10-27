<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('cuenta_sueldo', 30)->nullable();
            $table->string('cuenta_free', 30)->nullable();
            $table->string('cci', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn(['cuenta_sueldo', 'cuenta_free', 'cci']);
        });
    }
};
