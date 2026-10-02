<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dentro del área Comercial, solo el puesto "Ejecutivo" usa el contrato
     * de trabajo tipo ejecutivo; Supervisor, Jefe de Venta y Mesa de Control
     * (MC) generan contrato administrativo aunque estén en esa área.
     */
    public function up(): void
    {
        DB::table('puestos')
            ->whereIn('nombre', ['Supervisor', 'Jefe de Venta'])
            ->update(['tipo_contrato' => 'administrativo']);
    }

    public function down(): void
    {
        DB::table('puestos')
            ->whereIn('nombre', ['Supervisor', 'Jefe de Venta'])
            ->update(['tipo_contrato' => null]);
    }
};
