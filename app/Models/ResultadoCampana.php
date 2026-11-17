<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ResultadoCampana extends Model
{
    use SoftDeletes;

    protected $table = 'resultados_campana';

    /**
     * Columnas de la tabla, en orden de pantalla. `calculado` = se deriva de otras y no se digita.
     * `corta` = rótulo breve para la cabecera de la tabla (el texto completo queda como tooltip).
     * Tipos: texto | fecha | moneda | entero | porcentaje.
     */
    public const COLUMNAS = [
        'campana' => ['etiqueta' => 'Campaña', 'tipo' => 'texto'],
        'importe_gastado' => ['etiqueta' => 'Importe gastado', 'tipo' => 'moneda'],
        'fecha_inicio' => ['etiqueta' => 'Fecha inicio', 'tipo' => 'fecha'],
        'fecha_fin' => ['etiqueta' => 'Fecha fin', 'tipo' => 'fecha'],
        'clics_enlace' => ['etiqueta' => 'Clics en el enlace', 'tipo' => 'entero'],
        'resultados_meta' => ['etiqueta' => 'Resultados Meta', 'tipo' => 'entero'],
        'postulantes' => ['etiqueta' => 'Postulantes', 'tipo' => 'entero'],
        'no_contesto' => ['etiqueta' => 'No contestó', 'tipo' => 'entero'],
        'pendiente_llamada' => ['etiqueta' => 'Pendiente de llamada', 'tipo' => 'entero'],
        'no_interesados' => ['etiqueta' => 'No interesados', 'tipo' => 'entero'],
        'no_califica' => ['etiqueta' => 'No califica (perfil, edad, sueldo)', 'corta' => 'No califica', 'tipo' => 'entero'],
        'entrevistas' => ['etiqueta' => 'Entrevistas', 'tipo' => 'entero'],
        'costo_entrevista' => ['etiqueta' => 'Costo por entrevista', 'tipo' => 'moneda', 'calculado' => true],
        'pct_entrevistas' => ['etiqueta' => '% Entrevistas', 'tipo' => 'porcentaje', 'calculado' => true],
        'agendados_capacitacion' => ['etiqueta' => 'Agendados para capacitación', 'corta' => 'Agendados capacitación', 'tipo' => 'entero'],
        'ingresos_reales' => ['etiqueta' => 'Ingresos reales', 'tipo' => 'entero'],
        'pct_ingresos' => ['etiqueta' => '% Ingresos reales', 'tipo' => 'porcentaje', 'calculado' => true],
    ];

    protected $fillable = [
        'campana',
        'importe_gastado',
        'fecha_inicio',
        'fecha_fin',
        'clics_enlace',
        'resultados_meta',
        'postulantes',
        'no_contesto',
        'pendiente_llamada',
        'no_interesados',
        'no_califica',
        'entrevistas',
        'agendados_capacitacion',
        'ingresos_reales',
    ];

    protected function casts(): array
    {
        return [
            'importe_gastado' => 'decimal:2',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    /** Campos que se digitan (todas las columnas menos las calculadas). */
    public static function campos(): array
    {
        return array_filter(self::COLUMNAS, fn (array $c) => empty($c['calculado']));
    }

    public function cambios()
    {
        return $this->hasMany(ResultadoCampanaCambio::class);
    }

    /**
     * Costo por entrevista (importe ÷ entrevistas), % entrevistas y % ingresos reales (ambos sobre postulantes).
     * Devuelven null cuando el divisor es 0.
     */
    public static function metricas(float $importe, int $postulantes, int $entrevistas, int $ingresos): array
    {
        return [
            'costo_entrevista' => $entrevistas > 0 ? $importe / $entrevistas : null,
            'pct_entrevistas' => $postulantes > 0 ? $entrevistas / $postulantes : null,
            'pct_ingresos' => $postulantes > 0 ? $ingresos / $postulantes : null,
        ];
    }

    public function metricasPropias(): array
    {
        return self::metricas(
            (float) $this->importe_gastado,
            (int) $this->postulantes,
            (int) $this->entrevistas,
            (int) $this->ingresos_reales,
        );
    }

    /** Valor de una columna (digitada o calculada) listo para mostrar. */
    public function valorMostrado(string $columna): string
    {
        $valor = self::COLUMNAS[$columna]['calculado'] ?? false
            ? $this->metricasPropias()[$columna]
            : $this->getAttribute($columna);

        return self::formatear($columna, $valor);
    }

    public static function formatear(string $columna, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match (self::COLUMNAS[$columna]['tipo'] ?? 'texto') {
            'moneda' => 'S/ '.number_format((float) $valor, 2),
            'entero' => number_format((int) $valor),
            'porcentaje' => number_format((float) $valor * 100, 1).'%',
            'fecha' => Carbon::parse($valor)->format('d/m/Y'),
            default => (string) $valor,
        };
    }
}
