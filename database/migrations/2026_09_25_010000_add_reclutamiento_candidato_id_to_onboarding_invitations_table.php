<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_invitations', function (Blueprint $table) {
            $table->foreignId('reclutamiento_candidato_id')->nullable()->after('creado_por')
                ->constrained('reclutamiento_candidatos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reclutamiento_candidato_id');
        });
    }
};
