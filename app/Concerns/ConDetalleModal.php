<?php

namespace App\Concerns;

use Illuminate\Support\Collection;

/**
 * Ventana de detalle de los tableros: al hacer clic en una cifra lista los registros que la forman y,
 * al abrir una fila, su ficha completa. El componente aporta contexto() y resolverDetalle().
 */
trait ConDetalleModal
{
    /** Cifra cuyo detalle se está viendo (clave «tipo|a|b»), o null si la ventana está cerrada. */
    public ?string $detalle = null;

    /** Fila del detalle con la ficha completa desplegada. */
    public ?int $fichaFila = null;

    protected int $maxFilasDetalle = 300;

    abstract protected function contexto(): array;

    /** @return array{titulo: string, filas: array<int, array>}|null */
    abstract protected function resolverDetalle(string $clave, array $ctx): ?array;

    public function abrirDetalle(string $clave): void
    {
        $this->detalle = null;
        $this->fichaFila = null;

        $resuelto = $this->resolverDetalle($clave, $this->contexto());

        if ($resuelto === null) {
            return;
        }

        $this->detalle = $clave;
        // Con un solo registro se muestra de una vez su ficha completa.
        $this->fichaFila = count($resuelto['filas']) === 1 ? 0 : null;
    }

    public function cerrarDetalle(): void
    {
        $this->detalle = null;
        $this->fichaFila = null;
    }

    public function verFicha(int $fila): void
    {
        $this->fichaFila = $this->fichaFila === $fila ? null : $fila;
    }

    /**
     * Ordena y recorta las filas; la ficha (un Closure en 'ficha') solo se calcula para la fila desplegada.
     *
     * @param  Collection<int, array{nombre: string, chips: array, nota: ?string, badge: ?array, ficha: \Closure}>  $filas
     */
    protected function armarFilasDetalle(string $titulo, Collection $filas): array
    {
        $filas = $filas->sortBy(fn ($fila) => mb_strtoupper($fila['nombre']))->values();

        return [
            'titulo' => $titulo,
            'total' => $filas->count(),
            'truncado' => $filas->count() > $this->maxFilasDetalle,
            'filas' => $filas->take($this->maxFilasDetalle)
                ->map(fn ($fila, $i) => ['ficha' => $i === $this->fichaFila ? ($fila['ficha'])() : null] + $fila)
                ->all(),
        ];
    }
}
