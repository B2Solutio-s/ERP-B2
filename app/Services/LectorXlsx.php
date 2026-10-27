<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/** Lee la primera hoja de un .xlsx sin dependencias externas. Devuelve filas de texto (las fechas llegan como número de Excel). */
class LectorXlsx
{
    public static function leer(string $ruta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException("No se pudo abrir {$ruta}");
        }

        $compartidas = [];
        if ($xml = $zip->getFromName('xl/sharedStrings.xml')) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $compartidas[] = (string) (isset($si->t) ? $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false))));
            }
        }

        $hoja = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml') ?: $zip->getFromName(self::primeraHoja($zip)));
        $filas = [];

        foreach ($hoja->sheetData->row as $row) {
            $celdas = [];
            foreach ($row->c as $c) {
                preg_match('/^[A-Z]+/', (string) $c['r'], $m);
                $col = 0;
                foreach (str_split($m[0]) as $letra) {
                    $col = $col * 26 + ord($letra) - 64;
                }
                $valor = isset($c->v) ? (string) $c->v : (isset($c->is) ? (string) $c->is->t : '');
                $celdas[$col - 1] = ((string) $c['t']) === 's' ? ($compartidas[(int) $valor] ?? '') : $valor;
            }
            if ($celdas) {
                $fila = array_fill(0, max(array_keys($celdas)) + 1, '');
                foreach ($celdas as $i => $v) {
                    $fila[$i] = $v;
                }
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    private static function primeraHoja(ZipArchive $zip): string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nombre)) {
                return $nombre;
            }
        }

        throw new RuntimeException('El Excel no tiene hojas.');
    }
}
