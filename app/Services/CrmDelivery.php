<?php

namespace App\Services;

use App\Models\CrmDeliveryResumen;
use App\Models\CrmEjecutivo;
use App\Models\Empleado;
use App\Models\SeguimientoRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resumen de delivery (Tabla 3 del CRM): móvil se mide en líneas (Q) y fija en SOTs.
 * Las cifras se consumen tal cual del CRM (endpoint de solo lectura); aquí no se recalculan.
 */
class CrmDelivery
{
    public const VISTAS = ['movil' => 'Móvil (Q)', 'fija' => 'Fija (SOT)'];

    public const MINUTOS_VIGENCIA = 10;

    public const MAX_DIAS = 366;

    /**
     * Copia desde el CRM el resumen de delivery del rango, en las dos vistas (móvil y fija).
     *
     * @return array{ok: bool, mensaje: string}
     */
    public static function sincronizar(string $desde, string $hasta): array
    {
        $url = rtrim((string) config('services.crm.url'), '/');
        $token = (string) config('services.crm.token');

        if ($url === '' || $token === '') {
            return ['ok' => false, 'mensaje' => 'Falta configurar la conexión con el CRM.'];
        }

        $datos = [];

        foreach (array_keys(self::VISTAS) as $vista) {
            try {
                $respuesta = Http::timeout(60)->acceptJson()->withToken($token)
                    ->get($url.'/api/erp/delivery-resumen', ['from' => $desde, 'to' => $hasta, 'view' => $vista]);
            } catch (Throwable $e) {
                return ['ok' => false, 'mensaje' => 'No se pudo conectar con el CRM.'];
            }

            if (! $respuesta->successful() || ! is_array($respuesta->json('executives'))) {
                return ['ok' => false, 'mensaje' => 'El CRM respondió con un error ('.$respuesta->status().').'];
            }

            $datos[$vista] = $respuesta->json('executives');
        }

        $ahora = now();

        DB::transaction(function () use ($datos, $desde, $hasta, $ahora) {
            CrmDeliveryResumen::where('desde', $desde)->where('hasta', $hasta)->delete();

            foreach ($datos as $vista => $filas) {
                // El CRM separa por supervisor: si un ejecutivo trabajó con dos, aquí se suman en una sola fila.
                $porEjecutivo = [];

                foreach ($filas as $fila) {
                    $crmId = (int) $fila['executive_user_id'];

                    if (isset($porEjecutivo[$crmId])) {
                        foreach (['activated', 'flow', 'projected', 'total'] as $campo) {
                            $porEjecutivo[$crmId][$campo] = (int) $porEjecutivo[$crmId][$campo] + (int) $fila[$campo];
                        }
                        $porEjecutivo[$crmId]['activated_cf_without_igv'] = (float) ($porEjecutivo[$crmId]['activated_cf_without_igv'] ?? 0) + (float) ($fila['activated_cf_without_igv'] ?? 0);
                    } else {
                        $porEjecutivo[$crmId] = $fila;
                    }
                }

                foreach ($porEjecutivo as $crmId => $fila) {

                    CrmEjecutivo::updateOrCreate(['crm_user_id' => $crmId], [
                        'nombre' => (string) $fila['executive_name'],
                        'correo' => $fila['executive_email'] ?? null,
                        'correo_corporativo' => $fila['executive_corporate_email'] ?? null,
                    ]);

                    CrmDeliveryResumen::create([
                        'desde' => $desde,
                        'hasta' => $hasta,
                        'vista' => $vista,
                        'crm_user_id' => $crmId,
                        'nombre' => (string) $fila['executive_name'],
                        'supervisor' => $fila['supervisor_name'] ?? null,
                        'activadas' => (int) $fila['activated'],
                        'flujo' => (int) $fila['flow'],
                        'proyectadas' => (int) $fila['projected'],
                        'total' => (int) $fila['total'],
                        'cf_sin_igv' => (float) ($fila['activated_cf_without_igv'] ?? 0),
                        'sincronizado_at' => $ahora,
                    ]);
                }
            }
        });

        return ['ok' => true, 'mensaje' => 'Datos actualizados desde el CRM.'];
    }

    public static function ultimaSincronizacion(string $desde, string $hasta): ?Carbon
    {
        $valor = CrmDeliveryResumen::where('desde', $desde)->where('hasta', $hasta)->max('sincronizado_at');

        return $valor ? Carbon::parse($valor, 'UTC') : null;
    }

    public static function desactualizado(string $desde, string $hasta): bool
    {
        $ultima = self::ultimaSincronizacion($desde, $hasta);

        return ! $ultima || $ultima->lt(now()->subMinutes(self::MINUTOS_VIGENCIA));
    }

    /** Sincroniza solo si no hay datos del rango o tienen más de MINUTOS_VIGENCIA. */
    public static function sincronizarSiHaceFalta(string $desde, string $hasta): ?array
    {
        return self::desactualizado($desde, $hasta) ? self::sincronizar($desde, $hasta) : null;
    }

    /**
     * Una fila por ejecutivo con sus cifras de móvil (Q) y de fija (SOT).
     * $orden: 'nombre' (A–Z, por defecto) o 'total' (mayor a menor; a igualdad, A–Z).
     *
     * @return Collection<int, array{crm_user_id: int, nombre: string, movil: ?CrmDeliveryResumen, fija: ?CrmDeliveryResumen}>
     */
    public static function tabla(string $desde, string $hasta, string $orden = 'nombre'): Collection
    {
        return CrmDeliveryResumen::where('desde', $desde)->where('hasta', $hasta)->get()
            ->groupBy('crm_user_id')
            ->map(fn (Collection $filas, $crmId) => [
                'crm_user_id' => (int) $crmId,
                'nombre' => (string) $filas->first()->nombre,
                'movil' => $filas->firstWhere('vista', 'movil'),
                'fija' => $filas->firstWhere('vista', 'fija'),
            ])
            ->pipe(function (Collection $filas) use ($orden) {
                // A–Z sin distinguir mayúsculas ni tildes (Rocío entre Raphael y Stefano).
                $porNombre = fn (array $f) => Str::lower(Str::ascii($f['nombre']));

                return $orden === 'total'
                    ? $filas->sortBy([
                        fn ($a, $b) => (($b['movil']->total ?? 0) + ($b['fija']->total ?? 0)) <=> (($a['movil']->total ?? 0) + ($a['fija']->total ?? 0)),
                        fn ($a, $b) => strcmp($porNombre($a), $porNombre($b)),
                    ])
                    : $filas->sortBy($porNombre, SORT_STRING);
            })
            ->values();
    }

    /**
     * Metas de los compromisos EN PIE (sin evaluar) cuyo plazo toca el rango, por trabajador; en cada campo gana el más reciente.
     *
     * @param  array<int>  $empleadoIds
     * @return array<int, array{q: ?int, sots: ?int}> empleado_id => metas
     */
    public static function metas(array $empleadoIds, string $desde, string $hasta): array
    {
        if (! $empleadoIds) {
            return [];
        }

        $metas = [];

        // Solo compromisos en pie (sin evaluar): al evaluarse, su meta deja de mostrarse en la tabla.
        SeguimientoRegistro::where('tipo', SeguimientoRegistro::COMPROMISO)
            ->where('estado', SeguimientoRegistro::PENDIENTE)
            ->whereIn('empleado_id', $empleadoIds)
            ->where(fn ($q) => $q->whereNotNull('meta_q')->orWhereNotNull('meta_sots'))
            ->whereDate('fecha_desde', '<=', $hasta)
            ->whereDate('fecha_hasta', '>=', $desde)
            ->orderBy('fecha_desde')
            ->orderBy('id')
            ->get(['empleado_id', 'meta_q', 'meta_sots'])
            ->each(function ($registro) use (&$metas) {
                $actual = $metas[$registro->empleado_id] ?? ['q' => null, 'sots' => null];
                $metas[$registro->empleado_id] = [
                    'q' => $registro->meta_q !== null ? (int) $registro->meta_q : $actual['q'],
                    'sots' => $registro->meta_sots !== null ? (int) $registro->meta_sots : $actual['sots'],
                ];
            });

        return $metas;
    }

    /**
     * Resultado del CRM para validar un compromiso: cuenta solo lo ACTIVADO (Q) o ATENDIDO (SOT) dentro del plazo.
     * En flujo y proyectado se devuelven como contexto (reflejan el estado actual del CRM).
     *
     * @return array<string, mixed>
     */
    public static function resultadoCompromiso(SeguimientoRegistro $compromiso): array
    {
        $desde = $compromiso->fecha_desde->toDateString();
        $hasta = $compromiso->fecha_hasta->toDateString();
        $enCurso = $hasta >= now('America/Lima')->toDateString();

        $base = ['desde' => $desde, 'hasta' => $hasta, 'en_curso' => $enCurso, 'meta_q' => $compromiso->meta_q, 'meta_sots' => $compromiso->meta_sots];

        $ejecutivo = CrmEjecutivo::where('empleado_id', $compromiso->empleado_id)->first();

        if (! $ejecutivo) {
            return $base + ['estado' => 'sin_vinculo'];
        }

        $sync = self::sincronizarSiHaceFalta($desde, $hasta);

        if ($sync && ! $sync['ok']) {
            return $base + ['estado' => 'error', 'mensaje' => $sync['mensaje']];
        }

        $movil = CrmDeliveryResumen::where(['desde' => $desde, 'hasta' => $hasta, 'vista' => 'movil', 'crm_user_id' => $ejecutivo->crm_user_id])->first();
        $fija = CrmDeliveryResumen::where(['desde' => $desde, 'hasta' => $hasta, 'vista' => 'fija', 'crm_user_id' => $ejecutivo->crm_user_id])->first();

        $activadasQ = (int) ($movil->activadas ?? 0);
        $atendidasSot = (int) ($fija->activadas ?? 0);

        $cumple = null;
        if ($compromiso->meta_q !== null || $compromiso->meta_sots !== null) {
            $cumple = ($compromiso->meta_q === null || $activadasQ >= $compromiso->meta_q)
                && ($compromiso->meta_sots === null || $atendidasSot >= $compromiso->meta_sots);
        }

        return $base + [
            'estado' => 'ok',
            'ejecutivo' => $ejecutivo->nombre,
            'activadas_q' => $activadasQ,
            'atendidas_sot' => $atendidasSot,
            'flujo_q' => (int) ($movil->flujo ?? 0),
            'flujo_sot' => (int) ($fija->flujo ?? 0),
            'proyectado_q' => (int) ($movil->proyectadas ?? 0),
            'proyectado_sot' => (int) ($fija->proyectadas ?? 0),
            'cumple' => $cumple,
            'sincronizado_at' => self::ultimaSincronizacion($desde, $hasta)?->toIso8601String(),
        ];
    }

    private static function palabras(?string $texto): array
    {
        $limpio = Str::upper(Str::ascii((string) $texto));
        $limpio = preg_replace('/[^A-Z ]/', '', $limpio) ?? '';

        return array_values(array_filter(explode(' ', $limpio)));
    }

    /**
     * Trabajador sugerido para un ejecutivo del CRM: todas las palabras de su nombre aparecen en
     * el nombre completo del trabajador, y solo hay uno así. null si no hay o es ambiguo.
     *
     * @param  Collection<int, Empleado>  $empleados
     */
    public static function sugerir(string $nombreCrm, Collection $empleados): ?Empleado
    {
        $buscadas = self::palabras($nombreCrm);

        if (! $buscadas) {
            return null;
        }

        $coincidencias = $empleados->filter(function (Empleado $empleado) use ($buscadas) {
            $palabras = self::palabras("{$empleado->nombres} {$empleado->apellido_paterno} {$empleado->apellido_materno}");

            return ! array_diff($buscadas, $palabras);
        });

        return $coincidencias->count() === 1 ? $coincidencias->first() : null;
    }
}
