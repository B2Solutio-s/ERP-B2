<?php

namespace App\Console\Commands;

use App\Models\CandidatoReclutamiento;
use App\Services\LectorXlsx;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportarContratosActuales extends Command
{
    protected $signature = 'contratos:importar {archivo : Ruta del .xlsx} {--dry-run : Solo mostrar lo que se haría} {--omitir=* : DNI de personas a las que no se les toca el historial}';

    protected $description = 'Carga el historial de contratos (Contrato 1 = prueba/ingreso, Contrato 2 = renovación) de los trabajadores actuales';

    public function handle(): int
    {
        $filas = array_slice(LectorXlsx::leer($this->argument('archivo')), 2);   // 2 filas de encabezado
        $candidatos = CandidatoReclutamiento::where('apto', true)->get();
        $omitidos = array_map('strval', $this->option('omitir'));
        $resumen = ['trabajador' => 0, 'en espera' => 0, 'omitidos' => 0, 'errores' => 0];

        foreach ($filas as $n => $fila) {
            $nombre = trim((string) ($fila[0] ?? ''));
            if ($nombre === '') {
                continue;
            }

            $linea = $n + 3;
            $clave = $this->clave($nombre);
            // En el sistema algunos figuran solo con el primer nombre: basta con que uno empiece con el otro
            $coincidencias = $candidatos->filter(function ($c) use ($clave) {
                $propio = $this->clave($c->nombres);

                return $propio === $clave || str_starts_with($propio.' ', $clave.' ') || str_starts_with($clave.' ', $propio.' ');
            });

            if ($coincidencias->count() !== 1) {
                $this->error("Línea {$linea} ({$nombre}): ".($coincidencias->isEmpty() ? 'no hay un candidato con ese nombre' : 'hay varios candidatos con ese nombre'));
                $resumen['errores']++;

                continue;
            }

            $candidato = $coincidencias->first();

            if (in_array((string) $candidato->dni_ce, $omitidos, true)) {
                $this->warn("Línea {$linea} ({$nombre}): se deja como está (omitido a pedido).");
                $resumen['omitidos']++;

                continue;
            }

            $periodos = [];
            foreach ([['prueba', 1, 2], ['renovacion', 3, 4]] as [$tipo, $i, $f]) {
                if (empty($fila[$i]) && empty($fila[$f])) {
                    continue;
                }

                $inicio = $this->fecha($fila[$i] ?? null);
                $fin = $this->fecha($fila[$f] ?? null);

                if (! $inicio || ! $fin || $fin->lt($inicio)) {
                    $this->error("Línea {$linea} ({$nombre}): ".($tipo === 'prueba' ? 'Contrato 1' : 'Contrato 2').' con fechas inválidas'.($inicio && $fin ? " ({$inicio->format('d/m/Y')} – {$fin->format('d/m/Y')}: el fin es anterior al inicio)" : '').'; no se carga a esta persona.');
                    $periodos = null;
                    $resumen['errores']++;

                    break;
                }

                $periodos[] = [
                    'tipo' => $tipo,
                    'fecha_inicio' => $inicio->toDateString(),
                    'fecha_fin' => $fin->toDateString(),
                    'duracion_meses' => max(1, (int) floor($inicio->diffInMonths($fin->copy()->addDay()))),
                ];
            }

            if ($periodos === null || $periodos === []) {
                continue;
            }

            $detalle = collect($periodos)->map(fn ($p) => ($p['tipo'] === 'prueba' ? 'C1' : 'C2').' '.Carbon::parse($p['fecha_inicio'])->format('d/m/Y').'–'.Carbon::parse($p['fecha_fin'])->format('d/m/Y').' ('.$p['duracion_meses'].'m)')->join(' · ');
            $empleado = $candidato->empleadoVinculado();

            if ($empleado && $empleado->periodosContrato()->exists()) {
                $this->warn("Línea {$linea} ({$nombre}): ya tiene períodos registrados, no se toca.");
                $resumen['omitidos']++;

                continue;
            }

            if ($empleado) {
                $this->line(sprintf('%s %-22s trabajador  %s', $this->option('dry-run') ? '[prueba]' : '[ok]', $nombre, $detalle));
                if (! $this->option('dry-run')) {
                    foreach ($periodos as $periodo) {
                        $empleado->periodosContrato()->create($periodo);
                    }
                }
                $resumen['trabajador']++;
            } else {
                $this->line(sprintf('%s %-22s EN ESPERA (aún sin ficha)  %s', $this->option('dry-run') ? '[prueba]' : '[ok]', $nombre, $detalle));
                if (! $this->option('dry-run')) {
                    $candidato->update(['periodos_iniciales' => $periodos]);
                }
                $resumen['en espera']++;
            }
        }

        $this->info(($this->option('dry-run') ? 'Se cargarían: ' : 'Cargados: ')."{$resumen['trabajador']} al historial del trabajador, {$resumen['en espera']} en espera de su ficha; {$resumen['omitidos']} omitidos; {$resumen['errores']} con error.");

        return $resumen['errores'] ? self::FAILURE : self::SUCCESS;
    }

    private function clave(?string $texto): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim(Str::ascii((string) $texto))));
    }

    private function fecha(mixed $valor): ?Carbon
    {
        $valor = trim((string) $valor);

        return preg_match('/^\d{5}$/', $valor) ? Carbon::create(1899, 12, 30)->addDays((int) $valor)->startOfDay() : null;
    }
}
