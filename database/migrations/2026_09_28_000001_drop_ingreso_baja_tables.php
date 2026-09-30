<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orden por dependencias de FK: movimientos_personal referencia a cargos y campanas;
        // personal_indicadores_mensuales referencia a campanas. La tabla campanas se conserva
        // porque la usa Proceso de reclutamiento.
        Schema::dropIfExists('movimientos_personal');
        Schema::dropIfExists('personal_indicadores_mensuales');
        Schema::dropIfExists('cargos');
    }

    public function down(): void
    {
        // Eliminación irreversible de datos del módulo "Ingreso y Baja de Personal"; no se soporta rollback.
    }
};
