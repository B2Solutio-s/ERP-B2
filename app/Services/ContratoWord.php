<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Manipulaciones sobre las plantillas Word de contratos que TemplateProcessor no hace:
 * quitar la cláusula del período de prueba (cuando el contrato no la lleva) y renumerar las cláusulas.
 */
class ContratoWord
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** La cláusula del período de prueba es la única que contiene este marcador. */
    public const MARCA_PRUEBA = '${PRUEBA_INICIO}';

    private const ORDINALES = [
        'PRIMERO', 'SEGUNDO', 'TERCERO', 'CUARTO', 'QUINTO', 'SEXTO', 'SETIMO', 'OCTAVO', 'NOVENO', 'DECIMO',
        'DECIMO PRIMERO', 'DECIMO SEGUNDO', 'DECIMO TERCERO', 'DECIMO CUARTO', 'DECIMO QUINTO', 'DECIMO SEXTO',
        'DECIMO SETIMO', 'DECIMO OCTAVO', 'DECIMO NOVENO', 'VIGESIMO',
    ];

    private const PATRON_ORDINAL = '/^(\s*)(DECIMO(?: (?:PRIMERO|SEGUNDO|TERCERO|CUARTO|QUINTO|SEXTO|SETIMO|OCTAVO|NOVENO))?|PRIMERO|SEGUNDO|TERCERO|CUARTO|QUINTO|SEXTO|SETIMO|OCTAVO|NOVENO|VIGESIMO)(\s*)(?=[.:])/u';

    /**
     * Devuelve la ruta de la plantilla a usar. Con $llevaPrueba se usa tal cual; sin ella, se genera una copia
     * temporal sin la cláusula del período de prueba y con las cláusulas renumeradas (quien llama borra la copia).
     */
    public static function preparar(string $ruta, bool $llevaPrueba): string
    {
        if ($llevaPrueba) {
            return $ruta;
        }

        $temporal = tempnam(sys_get_temp_dir(), 'plantilla_').'.docx';
        copy($ruta, $temporal);

        self::transformar($temporal, function (DOMDocument $dom, DOMXPath $xp) {
            self::quitarClausulaPrueba($dom, $xp);
            self::renumerar($xp);
        });

        return $temporal;
    }

    /** Abre el document.xml de un .docx, lo transforma y lo vuelve a guardar. */
    public static function transformar(string $docx, callable $accion): void
    {
        $zip = new ZipArchive;
        if ($zip->open($docx) !== true) {
            throw new RuntimeException("No se pudo abrir la plantilla {$docx}");
        }

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($zip->getFromName('word/document.xml'));

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        $accion($dom, $xp);

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();
    }

    public static function quitarClausulaPrueba(DOMDocument $dom, DOMXPath $xp): void
    {
        foreach ($xp->query('//w:body/w:p') as $parrafo) {
            if (str_contains(self::texto($xp, $parrafo), self::MARCA_PRUEBA)) {
                $siguiente = $parrafo->nextSibling;
                $parrafo->parentNode->removeChild($parrafo);

                // También el párrafo en blanco que separa esa cláusula de la siguiente
                if ($siguiente instanceof DOMElement && $siguiente->localName === 'p' && trim(self::texto($xp, $siguiente)) === '') {
                    $siguiente->parentNode->removeChild($siguiente);
                }

                return;
            }
        }
    }

    /** Vuelve a numerar en orden las cláusulas (PRIMERO, SEGUNDO, … DECIMO SETIMO). */
    public static function renumerar(DOMXPath $xp): void
    {
        $indice = 0;

        foreach ($xp->query('//w:body/w:p') as $parrafo) {
            $texto = self::texto($xp, $parrafo);

            if (! preg_match(self::PATRON_ORDINAL, $texto, $m)) {
                continue;
            }

            $nuevo = self::ORDINALES[$indice] ?? $m[2];
            $indice++;

            // Se reemplaza la palabra y los espacios que la separan del punto ("DECIMO ." -> "DECIMO PRIMERO.")
            if ($nuevo !== $m[2] || $m[3] !== '') {
                self::reemplazarRango($xp, $parrafo, mb_strlen($m[1]), mb_strlen($m[2]) + mb_strlen($m[3]), $nuevo);
            }
        }
    }

    /** Inserta una cláusula nueva (copia del formato de la cláusula que contiene $despuesDe) y renumera. */
    public static function insertarClausula(DOMDocument $dom, DOMXPath $xp, string $despuesDe, string $texto): void
    {
        foreach ($xp->query('//w:body/w:p') as $parrafo) {
            if (! str_contains(self::texto($xp, $parrafo), $despuesDe)) {
                continue;
            }

            $separador = $parrafo->nextSibling;
            $copia = $parrafo->cloneNode(true);

            $runs = $xp->query('./w:r', $copia);
            for ($i = $runs->length - 1; $i >= 2; $i--) {
                $copia->removeChild($runs->item($i));
            }

            $partes = $xp->query('.//w:t', $copia);
            $partes->item(0)->textContent = 'QUINTO. -';
            $partes->item(1)->textContent = ' '.$texto;
            $partes->item(1)->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');

            $referencia = $separador->nextSibling;
            $parrafo->parentNode->insertBefore($copia, $referencia);
            $parrafo->parentNode->insertBefore($separador->cloneNode(true), $referencia);

            self::renumerar($xp);

            return;
        }

        throw new RuntimeException("No se encontró la cláusula que contiene «{$despuesDe}»");
    }

    /** Reemplaza texto aunque esté repartido en varios fragmentos (runs) del párrafo. */
    public static function reemplazarTexto(DOMXPath $xp, string $buscar, string $nuevo): int
    {
        $veces = 0;

        foreach ($xp->query('//w:body/w:p') as $parrafo) {
            while (($pos = mb_strpos(self::texto($xp, $parrafo), $buscar)) !== false) {
                self::reemplazarRango($xp, $parrafo, $pos, mb_strlen($buscar), $nuevo);
                $veces++;
            }
        }

        return $veces;
    }

    public static function quitarResaltados(DOMXPath $xp): void
    {
        foreach (iterator_to_array($xp->query('//w:highlight')) as $nodo) {
            $nodo->parentNode->removeChild($nodo);
        }
    }

    public static function texto(DOMXPath $xp, DOMElement $parrafo): string
    {
        $texto = '';
        foreach ($xp->query('.//w:t', $parrafo) as $t) {
            $texto .= $t->textContent;
        }

        return $texto;
    }

    private static function reemplazarRango(DOMXPath $xp, DOMElement $parrafo, int $inicio, int $largo, string $nuevo): void
    {
        $pos = 0;
        $colocado = false;

        foreach ($xp->query('.//w:t', $parrafo) as $t) {
            $contenido = $t->textContent;
            $desde = $pos;
            $hasta = $pos + mb_strlen($contenido);
            $pos = $hasta;

            $a = max($inicio, $desde);
            $b = min($inicio + $largo, $hasta);

            if ($a >= $b) {
                continue;
            }

            $t->textContent = mb_substr($contenido, 0, $a - $desde).($colocado ? '' : $nuevo).mb_substr($contenido, $b - $desde);
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $colocado = true;
        }
    }
}
