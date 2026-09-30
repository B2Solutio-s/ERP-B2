<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->foreignId('bloqueado_por_id')->nullable()->after('entrego_documentos')->constrained('users')->nullOnDelete();
            $table->timestamp('bloqueado_hasta')->nullable()->after('bloqueado_por_id');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropForeign(['bloqueado_por_id']);
            $table->dropColumn(['bloqueado_por_id', 'bloqueado_hasta']);
        });
    }
};
