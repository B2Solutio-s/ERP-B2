<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('biometrico_id', 24)->nullable()->unique()->after('documento_identidad');
        });

        Schema::create('asistencia_marcaciones', function (Blueprint $table) {
            $table->id();
            $table->string('biometrico_id', 24)->index();
            $table->dateTime('marcado_at')->index();
            $table->unsignedTinyInteger('estado')->default(0);
            $table->unsignedTinyInteger('tipo')->default(0);
            $table->timestamps();

            $table->unique(['biometrico_id', 'marcado_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_marcaciones');

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropUnique(['biometrico_id']);
            $table->dropColumn('biometrico_id');
        });
    }
};
