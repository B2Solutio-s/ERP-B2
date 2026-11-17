<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Días feriados: no se trabajan, no cuentan como falta ni como tardanza. Los edita el administrador.
        Schema::create('feriados', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->string('nombre', 120);
            $table->timestamps();
        });

        // Feriados nacionales de Perú 2026 y 2027 (algunos se mueven por decreto: se corrigen desde el módulo de Vacaciones).
        $fijos = [
            '01-01' => 'Año Nuevo',
            '05-01' => 'Día del Trabajo',
            '06-07' => 'Día de la Bandera y Batalla de Arica',
            '06-29' => 'San Pedro y San Pablo',
            '07-23' => 'Día de la Fuerza Aérea del Perú',
            '07-28' => 'Fiestas Patrias',
            '07-29' => 'Fiestas Patrias',
            '08-06' => 'Batalla de Junín',
            '08-30' => 'Santa Rosa de Lima',
            '10-08' => 'Combate de Angamos',
            '11-01' => 'Día de Todos los Santos',
            '12-08' => 'Inmaculada Concepción',
            '12-09' => 'Batalla de Ayacucho',
            '12-25' => 'Navidad',
        ];
        $semanaSanta = ['2026-04-02' => 'Jueves Santo', '2026-04-03' => 'Viernes Santo', '2027-03-25' => 'Jueves Santo', '2027-03-26' => 'Viernes Santo'];

        $filas = [];
        foreach ([2026, 2027] as $anio) {
            foreach ($fijos as $mesDia => $nombre) {
                $filas["$anio-$mesDia"] = $nombre;
            }
        }
        $filas += $semanaSanta;
        ksort($filas);

        DB::table('feriados')->insert(collect($filas)->map(fn ($nombre, $fecha) => ['fecha' => $fecha, 'nombre' => $nombre, 'created_at' => now(), 'updated_at' => now()])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
