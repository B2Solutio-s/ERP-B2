<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\CandidatoReclutamiento;
use App\Models\User;
use App\Services\LectorXlsx;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportarGestionCandidatos extends Command
{
    protected $signature = 'candidatos:importar-gestion {archivo : Ruta del .xlsx} {--dry-run : Solo mostrar lo que se cargaría} {--forzar : Cargar aunque ya exista una carga previa}';

    protected $description = 'Carga el Excel de gestión de llamadas (formato libre) en Gestión de candidatos, tal cual, para que el equipo lo corrija en la grilla';

    private const FUENTE = 'DATOS NUEVOS';

    // Columnas del Excel.
    private const MES = 0, FECHA = 1, AGENTE = 2, CAMPANA = 3, EDAD = 4, NOMBRE = 5, CELULAR = 6, DISTRITO = 7, OBS = 8,
        TIPIFICACION = 9, SUBTIPIFICACION = 10, ACEPTACION = 11, F_ENTREVISTA = 12, H_ENTREVISTA = 13, ASISTIO = 14;

    /** Avisos para el usuario: filas con datos dudosos que se cargaron tal cual. */
    private array $avisos = [];

    public function handle(): int
    {
        if (! $this->option('forzar') && CandidatoReclutamiento::where('fuente', self::FUENTE)->exists()) {
            $this->error('Ya hay candidatos cargados con fuente '.self::FUENTE.'. Use --forzar si de verdad quiere cargarlos otra vez.');

            return self::FAILURE;
        }

        $filas = LectorXlsx::leer($this->argument('archivo'));
        array_shift($filas);

        // Mismo criterio que la grilla: una persona repetida (nombre + celular) es un solo registro; manda el dato más reciente.
        $registros = [];
        $porClave = [];
        foreach ($filas as $n => $fila) {
            $fila = array_pad($fila, 15, '');
            $fila[] = $n + 2;
            if ($this->nombre($fila[self::NOMBRE]) === '' && $this->celular($fila[self::CELULAR]) === '') {
                continue;
            }

            $clave = $this->clave($fila);
            if ($clave !== null && isset($porClave[$clave])) {
                $registros[$porClave[$clave]] = $this->unir($registros[$porClave[$clave]], $fila);
                $this->avisos[] = "Línea {$fila[15]}: repetida con la línea {$registros[$porClave[$clave]][15]}, se unió en un solo registro.";

                continue;
            }

            $registros[] = $fila;
            if ($clave !== null) {
                $porClave[$clave] = array_key_last($registros);
            }
        }

        $existentes = CandidatoReclutamiento::all()
            ->map(fn ($c) => [$this->ascii($this->nombre($c->nombres.' '.$c->apellidos)), $this->celular((string) $c->numero_celular)])
            ->all();

        $areas = Area::with(['puestos', 'campanas'])->get();
        $agentes = User::where('role', User::ROL_GTH)->pluck('name');
        $creados = 0;
        $omitidos = 0;

        DB::beginTransaction();
        try {
            foreach ($registros as $fila) {
                if ($this->yaExiste($fila, $existentes)) {
                    $omitidos++;
                    $this->warn("Línea {$fila[15]}: {$this->nombre($fila[self::NOMBRE])} ya está en la grilla, se omite.");

                    continue;
                }

                CandidatoReclutamiento::create($this->datos($fila, $areas, $agentes));
                $creados++;
            }

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        foreach ($this->avisos as $aviso) {
            $this->line($aviso);
        }

        $this->info(($this->option('dry-run') ? 'Se cargarían ' : 'Cargados ')."{$creados} candidatos; {$omitidos} omitidos por ya existir; ".count($this->avisos).' avisos.');

        return self::SUCCESS;
    }

    private function datos(array $fila, $areas, $agentes): array
    {
        $linea = $fila[15];
        $dato = fn (int $col) => trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', (string) $fila[$col])));
        $mayus = fn (string $v) => $v === '' ? null : mb_strtoupper($v);

        $fecha = $this->fecha($dato(self::FECHA), $linea, 'fecha de gestión');
        $fechaEntrevista = $this->fecha($dato(self::F_ENTREVISTA), $linea, 'fecha de entrevista');
        $hora = $this->hora($dato(self::H_ENTREVISTA), $linea);
        $tipificacion = $mayus($dato(self::TIPIFICACION));
        $motivo = $mayus($dato(self::SUBTIPIFICACION));
        $observaciones = $mayus($dato(self::OBS));

        // La grilla solo conserva la subtipificación cuando es un rechazo; en los demás casos el motivo pasa a observaciones para no perderse.
        if ($motivo !== null && ! in_array($tipificacion, ['NO INTERESADO', 'NO PASA FILTRO'], true)) {
            $observaciones = $observaciones === null ? "MOTIVO: {$motivo}" : "{$observaciones} | MOTIVO: {$motivo}";
            $motivo = null;
        }

        // La grilla borra fecha y hora de entrevista si no hay aceptación; si el Excel las trae, la aceptación está implícita.
        $aceptacion = $mayus($dato(self::ACEPTACION));
        if ($aceptacion === null && ($fechaEntrevista || $hora)) {
            $aceptacion = 'SI';
            $this->avisos[] = "Línea {$linea}: tenía fecha/hora de entrevista sin aceptación; se marcó aceptación SI.";
        }

        [$area, $puesto, $campana] = $this->campana($dato(self::CAMPANA), $areas);
        $edad = preg_match('/\d+/', $dato(self::EDAD), $m) ? min(99, (int) $m[0]) : null;

        return [
            'anio' => $fecha?->year,
            'mes' => $mayus($dato(self::MES)),
            'fecha_gestion' => $fecha,
            'agente_reclutador' => $this->agente($dato(self::AGENTE), $agentes),
            'area' => $area,
            'puesto' => $puesto,
            'campana' => $campana,
            'edad' => $edad,
            'nombres' => $this->nombre($fila[self::NOMBRE]) ?: null,
            'numero_celular' => $mayus($dato(self::CELULAR)),
            'distrito' => $mayus($dato(self::DISTRITO)),
            'observaciones' => $observaciones,
            'tipificacion' => $tipificacion,
            'subtipificacion_rechazo' => $motivo,
            'aceptacion_entrevista' => $aceptacion,
            'fecha_entrevista' => $fechaEntrevista,
            'hora_entrevista' => $hora,
            'asistio_entrevista' => $mayus($dato(self::ASISTIO)),
            'fuente' => self::FUENTE,
        ];
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} área, puesto, campaña */
    private function campana(string $valor, $areas): array
    {
        $buscado = $this->ascii($valor);
        if ($buscado === '') {
            return [null, null, null];
        }

        foreach ($areas as $area) {
            if ($campana = $area->campanas->first(fn ($c) => $this->ascii($c->nombre) === $buscado)) {
                return [mb_strtoupper($area->nombre), null, mb_strtoupper($campana->nombre)];
            }
        }

        // "Mesa de control" en el Excel es un puesto, no una campaña.
        foreach ($areas as $area) {
            if ($puesto = $area->puestos->first(fn ($p) => str_starts_with($this->ascii($p->nombre), $buscado))) {
                return [mb_strtoupper($area->nombre), mb_strtoupper($puesto->nombre), 'NO APLICA'];
            }
        }

        return [null, null, mb_strtoupper($valor)];
    }

    private function agente(string $valor, $agentes): ?string
    {
        if ($valor === '') {
            return null;
        }

        $agente = $agentes->first(fn ($nombre) => str_starts_with($this->ascii($nombre), $this->ascii($valor)));

        return mb_strtoupper($agente ?? $valor);
    }

    private function fecha(string $valor, int $linea, string $campo): ?Carbon
    {
        if ($valor === '') {
            return null;
        }

        if (preg_match('/^\d{5}$/', $valor)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $valor)->startOfDay();
        }

        if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{2,4})$/', $valor, $m)) {
            return Carbon::create($m[3] < 100 ? 2000 + $m[3] : (int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        }

        $this->avisos[] = "Línea {$linea}: {$campo} '{$valor}' no se entiende, se dejó vacía.";

        return null;
    }

    /** Acepta "10", "10.30", "9.30am", "10:00am" y la fracción de día que guarda Excel (0.4583 = 11:00). */
    private function hora(string $valor, int $linea): ?string
    {
        if ($valor === '') {
            return null;
        }

        $texto = mb_strtolower(str_replace(' ', '', $valor));
        $pm = str_ends_with($texto, 'pm');
        $texto = preg_replace('/[ap]m$/', '', $texto);

        if (is_numeric($texto) && (float) $texto < 1) {
            $minutos = (int) round((float) $texto * 1440);
            $hora = sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
            if ($minutos < 7 * 60) {
                $this->avisos[] = "Línea {$linea}: la hora de entrevista quedó como {$hora} (¿era de la tarde?), revisar.";
            }

            return $hora;
        }

        if (preg_match('/^(\d{1,2})(?:[.:](\d{1,2}))?$/', $texto, $m)) {
            $h = (int) $m[1] + ($pm && (int) $m[1] < 12 ? 12 : 0);

            return sprintf('%02d:%02d', $h, (int) ($m[2] ?? 0));
        }

        $this->avisos[] = "Línea {$linea}: hora '{$valor}' no se entiende, se dejó vacía.";

        return null;
    }

    /** Une dos filas de la misma persona: lo más reciente (fila posterior) manda, las observaciones se acumulan. */
    private function unir(array $anterior, array $nueva): array
    {
        foreach ($nueva as $col => $valor) {
            if ($col === 15 || trim((string) $valor) === '') {
                continue;
            }

            $anterior[$col] = $col === self::OBS && trim((string) $anterior[$col]) !== ''
                ? trim($anterior[$col]).' | '.trim($valor)
                : $valor;
        }

        return $anterior;
    }

    /**
     * ¿Ya lo escribieron a mano en la grilla? Mismo nombre y celular (aunque uno traiga dos números),
     * o un registro sin celular cuyo nombre está contenido en el del Excel (p. ej. "LESLIE FLORES").
     */
    private function yaExiste(array $fila, array $existentes): bool
    {
        $nombre = $this->ascii($this->nombre($fila[self::NOMBRE]));
        $celular = $this->celular($fila[self::CELULAR]);
        $palabras = array_filter(explode(' ', $nombre));

        foreach ($existentes as [$nombreDb, $celularDb]) {
            if ($nombreDb === '') {
                continue;
            }

            if ($celularDb !== '') {
                if ($nombreDb === $nombre && $celular !== '' && (str_contains($celular, $celularDb) || str_contains($celularDb, $celular))) {
                    return true;
                }

                continue;
            }

            $palabrasDb = array_filter(explode(' ', $nombreDb));
            if (count($palabrasDb) >= 2 && ! array_diff($palabrasDb, $palabras)) {
                return true;
            }
        }

        return false;
    }

    private function clave(array $fila): ?string
    {
        return $this->claveDe($this->nombre($fila[self::NOMBRE]), $this->celular($fila[self::CELULAR]));
    }

    private function claveDe(string $nombre, string $celular): ?string
    {
        return $nombre !== '' && $celular !== '' ? $this->ascii($nombre).'|'.$celular : null;
    }

    private function nombre(?string $valor): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', (string) $valor))));
    }

    private function celular(?string $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor);
    }

    private function ascii(?string $texto): string
    {
        return mb_strtolower(trim(Str::ascii((string) $texto)));
    }
}
