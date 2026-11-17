<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dotación que se debe mantener por campaña (la edita el Admin desde Control de ingresos).
        Schema::create('metas_dotacion', function (Blueprint $table) {
            $table->id();
            $table->string('campana', 150)->unique();
            $table->unsignedInteger('meta')->default(0);
            $table->foreignId('actualizado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('metas_dotacion')->insert([
            ['campana' => 'MÓVIL', 'meta' => 22, 'created_at' => now(), 'updated_at' => now()],
            ['campana' => 'FIJA', 'meta' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_dotacion');
    }
};
