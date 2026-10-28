<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Genera un .xlsx sencillo (varias hojas, encabezado en negrita con filtro y fila fija) sin dependencias externas.
 * Todos los valores van como texto, igual que se ven en pantalla.
 */
class EscritorXlsx
{
    /** @var array<int, array{nombre: string, anchos: array<int, int>, filas: array<int, array<int, string|int|float|null>>}> */
    private array $hojas = [];

    /** @param array<int, array<int, string|int|float|null>> $filas La primera fila es el encabezado. */
    public function hoja(string $nombre, array $filas, array $anchos = []): static
    {
        $this->hojas[] = ['nombre' => mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $nombre), 0, 31), 'anchos' => $anchos, 'filas' => $filas];

        return $this;
    }

    /** Escribe el libro en $ruta. */
    public function guardar(string $ruta): void
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo Excel.');
        }

        $tipos = '';
        $relaciones = '';
        $libro = '';
        foreach ($this->hojas as $i => $hoja) {
            $n = $i + 1;
            $tipos .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $relaciones .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $libro .= '<sheet name="'.$this->xml($hoja['nombre']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $this->xmlHoja($hoja));
        }

        $estilos = count($this->hojas) + 1;
        $relaciones .= '<Relationship Id="rId'.$estilos.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $cabecera = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $cabecera.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$tipos.'</Types>');
        $zip->addFromString('_rels/.rels', $cabecera.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $cabecera.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$libro.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $cabecera.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relaciones.'</Relationships>');
        $zip->addFromString('xl/styles.xml', $cabecera.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF334155"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');

        if (! $zip->close()) {
            throw new RuntimeException('No se pudo guardar el archivo Excel.');
        }
    }

    private function xmlHoja(array $hoja): string
    {
        $columnas = '';
        foreach ($hoja['anchos'] as $i => $ancho) {
            $columnas .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$ancho.'" customWidth="1"/>';
        }

        $filas = '';
        foreach ($hoja['filas'] as $r => $fila) {
            $celdas = '';
            foreach (array_values($fila) as $c => $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }

                $ref = $this->columna($c).($r + 1);
                $estilo = $r === 0 ? ' s="1"' : '';
                $celdas .= is_int($valor) || is_float($valor)
                    ? '<c r="'.$ref.'"'.$estilo.'><v>'.$valor.'</v></c>'
                    : '<c r="'.$ref.'"'.$estilo.' t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string) $valor).'</t></is></c>';
            }
            $filas .= '<row r="'.($r + 1).'">'.$celdas.'</row>';
        }

        $ultima = $this->columna(max(0, count($hoja['filas'][0] ?? []) - 1)).max(1, count($hoja['filas']));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$columnas.'</cols><sheetData>'.$filas.'</sheetData><autoFilter ref="A1:'.$ultima.'"/></worksheet>';
    }

    private function columna(int $indice): string
    {
        $letras = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26).$letras;
        }

        return $letras;
    }

    private function xml(string $texto): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
