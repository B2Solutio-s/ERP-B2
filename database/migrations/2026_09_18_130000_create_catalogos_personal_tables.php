<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campanas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('cargos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->boolean('cuenta_como_supervisor')->default(false);
            $table->timestamps();
        });

        $movilId = DB::table('campanas')->insertGetId(['nombre' => 'Móvil', 'created_at' => now(), 'updated_at' => now()]);
        $fijaId = DB::table('campanas')->insertGetId(['nombre' => 'Fija', 'created_at' => now(), 'updated_at' => now()]);

        $vendedorId = DB::table('cargos')->insertGetId(['nombre' => 'Vendedor', 'cuenta_como_supervisor' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cargos')->insert(['nombre' => 'Supervisor', 'cuenta_como_supervisor' => true, 'created_at' => now(), 'updated_at' => now()]);

        // --- movimientos_personal: separa "ejecutivo" en apellidos/nombres y reemplaza
        // la campana de texto libre y el cargo implicito por catalogos administrables ---
        Schema::table('movimientos_personal', function (Blueprint $table) {
            $table->string('apellidos')->nullable()->after('id');
            $table->string('nombres')->nullable()->after('apellidos');
            $table->foreignId('campana_id')->nullable()->after('nombres')->constrained('campanas')->restrictOnDelete();
            $table->foreignId('cargo_id')->nullable()->after('campana_id')->constrained('cargos')->restrictOnDelete();
        });

        $mapaCampana = ['MOVIL' => $movilId, 'FIJA' => $fijaId];

        foreach (DB::table('movimientos_personal')->get() as $movimiento) {
            $palabras = preg_split('/\s+/', trim($movimiento->ejecutivo));
            $total = count($palabras);

            if ($total >= 3) {
                $apellidos = implode(' ', array_slice($palabras, -2));
                $nombres = implode(' ', array_slice($palabras, 0, $total - 2));
            } elseif ($total === 2) {
                [$nombres, $apellidos] = $palabras;
            } else {
                $nombres = $palabras[0] ?? '';
                $apellidos = '';
            }

            DB::table('movimientos_personal')->where('id', $movimiento->id)->update([
                'apellidos' => $apellidos,
                'nombres' => $nombres,
                'campana_id' => $mapaCampana[$movimiento->campana] ?? $movilId,
                'cargo_id' => $vendedorId,
            ]);
        }

        Schema::table('movimientos_personal', function (Blueprint $table) {
            $table->string('apellidos')->nullable(false)->change();
            $table->string('nombres')->nullable(false)->change();
            $table->foreignId('campana_id')->nullable(false)->change();
            $table->foreignId('cargo_id')->nullable(false)->change();
            $table->dropColumn(['ejecutivo', 'campana']);
        });

        // --- personal_indicadores_mensuales: "cantidad_supervisores" pasa a calcularse
        // solo de los movimientos con cargo de supervisor, ya no se ingresa a mano ---
        Schema::table('personal_indicadores_mensuales', function (Blueprint $table) {
            $table->dropUnique(['campana', 'anio', 'mes']);
            $table->dropColumn('cantidad_supervisores');
        });

        Schema::table('personal_indicadores_mensuales', function (Blueprint $table) {
            $table->foreignId('campana_id')->after('id')->constrained('campanas')->restrictOnDelete();
        });

        Schema::table('personal_indicadores_mensuales', function (Blueprint $table) {
            $table->dropColumn('campana');
            $table->unique(['campana_id', 'anio', 'mes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reestructuracion de datos irreversible; no se soporta rollback.
    }
};
