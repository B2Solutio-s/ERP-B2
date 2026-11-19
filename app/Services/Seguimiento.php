<?php

namespace App\Services;

use App\Jobs\EnviarAvisoSeguimiento;
use App\Models\Ajuste;
use App\Models\Empleado;
use App\Models\SeguimientoAviso;
use App\Models\SeguimientoRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Reglas de Seguimiento (compromisos y memorándums de un trabajador).
 *  - Compromiso: tiene plazo; el administrador lo evalúa (cumplió / no cumplió).
 *  - Memorándum: no se evalúa, se acumula.
 *  - Al llegar a 3 (y a cada múltiplo: 6, 9…) se avisa a gerencia desde rrhh@b2s.pe.
 */
class Seguimiento
{
    public const UMBRAL = 3;

    public const CLAVE_GERENCIA = 'seguimiento.correos_gerencia';

    public const CLAVE_ADICIONAL = 'seguimiento.correos_adicionales';

    public const GERENCIA_POR_DEFECTO = 'gerencia@b2s.pe';

    public const TIPOS = [
        SeguimientoRegistro::COMPROMISO => 'Compromiso',
        SeguimientoRegistro::MEMORANDUM => 'Memorándum',
    ];

    /** Correos que reciben los avisos: gerencia + el adicional que se registre. */
    public static function destinatarios(): array
    {
        return self::dividirCorreos(
            Ajuste::obtener(self::CLAVE_GERENCIA, self::GERENCIA_POR_DEFECTO).','.Ajuste::obtener(self::CLAVE_ADICIONAL, ''),
        );
    }

    public static function dividirCorreos(?string $texto): array
    {
        $correos = preg_split('/[\s,;]+/', (string) $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $validos = array_filter($correos, fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL));

        return array_values(array_unique(array_map('strtolower', $validos)));
    }

    /** Valida y guarda los correos de aviso. Devuelve los mensajes de error (vacío si todo bien). */
    public static function guardarDestinatarios(string $gerencia, string $adicional): array
    {
        $errores = [];

        foreach (['correosGerencia' => $gerencia, 'correosAdicionales' => $adicional] as $campo => $texto) {
            $piezas = preg_split('/[\s,;]+/', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($piezas as $pieza) {
                if (! Validator::make(['c' => $pieza], ['c' => 'email'])->passes()) {
                    $errores[$campo] = "«{$pieza}» no es un correo válido.";
                }
            }
        }

        if (! $errores && self::dividirCorreos($gerencia.','.$adicional) === []) {
            $errores['correosGerencia'] = 'Registra al menos un correo para los avisos.';
        }

        if (! $errores) {
            Ajuste::guardar(self::CLAVE_GERENCIA, implode(', ', self::dividirCorreos($gerencia)));
            Ajuste::guardar(self::CLAVE_ADICIONAL, implode(', ', self::dividirCorreos($adicional)));
        }

        return $errores;
    }

    /**
     * Compromiso «actual» de cada trabajador: el pendiente más urgente (el que vence antes);
     * si no tiene pendientes, el último evaluado.
     *
     * @param  array<int>  $empleadoIds
     * @return array<int, SeguimientoRegistro> empleado_id => compromiso
     */
    public static function compromisosActuales(array $empleadoIds): array
    {
        if (! $empleadoIds) {
            return [];
        }

        $actuales = [];

        SeguimientoRegistro::where('tipo', SeguimientoRegistro::COMPROMISO)
            ->whereIn('empleado_id', $empleadoIds)
            ->get()
            ->groupBy('empleado_id')
            ->each(function ($registros, $empleadoId) use (&$actuales) {
                $pendientes = $registros->where('estado', SeguimientoRegistro::PENDIENTE)->sortBy('fecha_hasta');

                $actuales[(int) $empleadoId] = $pendientes->isNotEmpty()
                    ? $pendientes->first()
                    : $registros->sortByDesc('fecha_hasta')->first();
            });

        return $actuales;
    }

    /**
     * Estado y tiempo de un compromiso respecto a hoy.
     *
     * @return array{texto: string, tono: string, urgente: bool}  tono: rose | amber | slate | green
     */
    public static function plazo(SeguimientoRegistro $registro, string $hoy): array
    {
        if ($registro->estado === SeguimientoRegistro::CUMPLIDO) {
            return ['texto' => 'Cumplió', 'tono' => 'green', 'urgente' => false];
        }

        if ($registro->estado === SeguimientoRegistro::NO_CUMPLIDO) {
            return ['texto' => 'No cumplió', 'tono' => 'rose', 'urgente' => false];
        }

        $dias = (int) Carbon::parse($hoy)->diffInDays($registro->fecha_hasta->copy()->startOfDay(), false);

        return match (true) {
            $dias < -1 => ['texto' => 'Venció hace '.abs($dias).' días · por evaluar', 'tono' => 'rose', 'urgente' => true],
            $dias === -1 => ['texto' => 'Venció ayer · por evaluar', 'tono' => 'rose', 'urgente' => true],
            $dias === 0 => ['texto' => 'Vence hoy', 'tono' => 'amber', 'urgente' => true],
            $dias === 1 => ['texto' => 'Vence mañana', 'tono' => 'amber', 'urgente' => true],
            $dias <= 3 => ['texto' => "Vence en {$dias} días", 'tono' => 'amber', 'urgente' => true],
            default => ['texto' => "En plazo · vence en {$dias} días", 'tono' => 'slate', 'urgente' => false],
        };
    }

    /** Compromisos no cumplidos o memorándums acumulados del trabajador. */
    public static function cantidad(int $empleadoId, string $tipo): int
    {
        return SeguimientoRegistro::where('empleado_id', $empleadoId)
            ->where('tipo', $tipo)
            ->when($tipo === SeguimientoRegistro::COMPROMISO, fn ($q) => $q->where('estado', SeguimientoRegistro::NO_CUMPLIDO))
            ->count();
    }

    /** ¿Ya venció el plazo del compromiso (y sigue sin evaluarse)? */
    public static function vencido(SeguimientoRegistro $registro, string $hoy): bool
    {
        return $registro->esCompromiso()
            && $registro->estado === SeguimientoRegistro::PENDIENTE
            && $registro->fecha_hasta
            && $registro->fecha_hasta->toDateString() < $hoy;
    }

    /**
     * Si el trabajador llegó a un múltiplo de 3, deja registrado y encola el aviso a gerencia
     * (una sola vez por cada múltiplo). Devuelve el aviso creado, o null si no corresponde.
     */
    public static function revisarAviso(Empleado $empleado, string $tipo): ?SeguimientoAviso
    {
        $cantidad = self::cantidad($empleado->id, $tipo);

        if ($cantidad < self::UMBRAL || $cantidad % self::UMBRAL !== 0) {
            return null;
        }

        $destinatarios = self::destinatarios();

        $aviso = SeguimientoAviso::firstOrCreate(
            ['empleado_id' => $empleado->id, 'tipo' => $tipo, 'cantidad' => $cantidad],
            ['destinatarios' => implode(', ', $destinatarios), 'error' => $destinatarios ? null : 'No hay correos de aviso registrados.'],
        );

        if (! $aviso->wasRecentlyCreated || ! $destinatarios) {
            return null;
        }

        EnviarAvisoSeguimiento::dispatch($aviso->id);

        return $aviso;
    }
}
