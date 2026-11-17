<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preferencias del perfil de cada usuario: color del tema (#rrggbb) y foto (archivo privado).
        Schema::table('users', function (Blueprint $table) {
            $table->string('tema_color', 7)->nullable();
            $table->string('foto_ruta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tema_color', 'foto_ruta']);
        });
    }
};
