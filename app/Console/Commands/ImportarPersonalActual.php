<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\CandidatoReclutamiento;
use App\Services\LectorXlsx;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class ImportarPersonalActual extends Command
{
    protected $signature = 'personal:importar {archivo : Ruta del .xlsx} {--dry-run : Solo validar, sin guardar}';

    protected $description = 'Carga al personal que ya trabaja como candidatos APTOS para que aparezcan en todo el flujo';

    private const MESES = [1 => 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

    public function handle(): int
    {
        $filas = LectorXlsx::leer($this->argument('archivo'));
        $cabecera = array_shift($filas);
        $indice = array_flip(array_map(fn ($c) => $this->clave($c), $cabecera));

        $areas = Area::with(['puestos', 'cargos', 'campanas'])->get();
        $creados = 0;
        $errores = [];

        foreach ($filas as $n => $fila) {
            $linea = $n + 2;
            $dato = fn (string $campo) => trim((string) ($fila[$indice[$campo] ?? -1] ?? ''));

            try {
                $dni = preg_replace('/\s+/', '', $dato('numero de documento'));
                $ingreso = $this->fecha($dato('fecha de ingreso'));
                $area = $areas->first(fn ($a) => $this->igual($a->nombre, $dato('area')))
                    ?? throw new RuntimeException("área '{$dato('area')}' no existe en el catálogo");

                $puesto = $this->buscar($area->puestos, $dato('puesto'), 'puesto');
                $campana = $this->opcional($area->campanas, $dato('campana'), 'campaña');
                $cargo = $this->opcional($area->cargos, $dato('cargo'), 'cargo');

                if (CandidatoReclutamiento::where('dni_ce', $dni)->exists()) {
                    $this->warn("Línea {$linea}: {$dni} ya existe como candidato, se omite.");

                    continue;
                }

                $nacimiento = $dato('fecha de nacimiento') ? $this->fecha($dato('fecha de nacimiento')) : null;

                $datos = [
                    'anio' => $ingreso->year,
                    'mes' => self::MESES[$ingreso->month],
                    'fecha_gestion' => $ingreso,
                    'area' => mb_strtoupper($area->nombre),
                    'puesto' => mb_strtoupper($puesto),
                    'campana' => $campana,
                    'cargo' => $cargo,
                    'dni_ce' => mb_strtoupper($dni),
                    'fecha_nacimiento' => $nacimiento,
                    'edad' => $nacimiento ? $nacimiento->diffInYears($ingreso) : null,
                    'nombres' => mb_strtoupper($dato('nombres')),
                    'apellidos' => mb_strtoupper(trim($dato('apellido paterno').' '.$dato('apellido materno'))),
                    'distrito' => mb_strtoupper($dato('distrito en donde vive actualmente')) ?: null,
                    'direccion' => $dato('direccion donde vive actualmente') ?: null,
                    'email' => mb_strtolower($dato('correo electronico1')) ?: null,
                    'fuente' => 'CARGA INICIAL',
                    'tipificacion' => 'INTERESADO - APTO',
                    'aceptacion_entrevista' => 'SI',
                    'fecha_entrevista' => $ingreso,
                    'asistio_entrevista' => 'SI, APTO',
                    'capacitacion_1' => $ingreso,
                    'asistio_cap_1' => 'SI, APTO',
                    'capacitacion_2' => $ingreso,
                    'asistio_cap_2' => 'SI, APTO',
                    'apto' => true,
                    'fecha_apto' => $ingreso,
                    'fecha_ingreso' => $ingreso,
                ];

                if (! $this->option('dry-run')) {
                    CandidatoReclutamiento::create($datos);
                }

                $creados++;
                $this->line(sprintf('%s %-10s %-34s %-14s %-18s %-8s %s', $this->option('dry-run') ? '[prueba]' : '[ok]', $dni, $datos['nombres'].' '.$datos['apellidos'], $datos['area'], $datos['puesto'], $campana ?? '-', $cargo ?? '-'));
            } catch (RuntimeException $e) {
                $errores[] = "Línea {$linea} ({$dato('numero de documento')}): ".$e->getMessage();
            }
        }

        foreach ($errores as $error) {
            $this->error($error);
        }

        $this->info(($this->option('dry-run') ? 'Se cargarían ' : 'Cargados ').$creados.' candidatos; '.count($errores).' con error.');

        return $errores ? self::FAILURE : self::SUCCESS;
    }

    /** Devuelve el nombre del catálogo (en mayúsculas) que coincide, o falla. */
    private function buscar($coleccion, string $valor, string $etiqueta): string
    {
        $alias = ['mesa de control' => 'mesa de control (mc)'];
        $objetivo = $alias[$this->clave($valor)] ?? $this->clave($valor);

        $item = $coleccion->first(fn ($i) => $this->clave($i->nombre) === $objetivo)
            ?? throw new RuntimeException("{$etiqueta} '{$valor}' no existe en el catálogo del área");

        return $item->nombre;
    }

    /** Campaña/Cargo: "NO APLICA" o vacío => 'NO APLICA' sin validar contra catálogo. */
    private function opcional($coleccion, string $valor, string $etiqueta): ?string
    {
        if ($valor === '' || $this->clave($valor) === 'no aplica') {
            return 'NO APLICA';
        }

        return mb_strtoupper($this->buscar($coleccion, $valor, $etiqueta));
    }

    private function fecha(string $valor): Carbon
    {
        if (preg_match('/^\d{5}$/', $valor)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $valor)->startOfDay();
        }

        $fecha = Carbon::createFromFormat('d/m/Y', $valor);

        if ($fecha === false) {
            throw new RuntimeException("fecha inválida '{$valor}'");
        }

        return $fecha->startOfDay();
    }

    private function clave(?string $texto): string
    {
        return mb_strtolower(trim(Str::ascii((string) str_replace("\u{a0}", ' ', (string) $texto))));
    }

    private function igual(string $a, string $b): bool
    {
        return $this->clave($a) === $this->clave($b);
    }
}
