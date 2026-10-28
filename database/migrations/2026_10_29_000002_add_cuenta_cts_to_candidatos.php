<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->string('cuenta_cts', 30)->nullable()->after('cuenta_free');
        });
    }

    public function down(): void
    {
        Schema::table('reclutamiento_candidatos', function (Blueprint $table) {
            $table->dropColumn('cuenta_cts');
        });
    }
};
