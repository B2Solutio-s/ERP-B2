<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Vínculo opcional usuario ↔ trabajador (un trabajador solo puede tener un usuario).
            $table->foreignId('empleado_id')->nullable()->unique()->after('role')
                ->constrained('empleados')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empleado_id');
        });
    }
};
