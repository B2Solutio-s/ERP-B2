<?php

namespace App\Services;

use App\Mail\ResumenCompromisosMail;
use App\Models\Ajuste;
use App\Models\SeguimientoRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Resumen diario (8:00, hora de Lima) de compromisos vencidos sin evaluar y por vencer.
 * Va a los correos de gerencia y, en copia, a los que el administrador registre. Si no hay novedades, no se envía.
 */
class ResumenCompromisos
{
    public const CLAVE_COPIA = 'seguimiento.resumen_copia';

    public const CLAVE_ULTIMO = 'seguimiento.resumen_ultimo_envio';

    /** Se incluyen los compromisos que vencen dentro de este número de días. */
    public const DIAS_PREVIOS = 3;

    public static function hoy(): string
    {
        return now('America/Lima')->toDateString();
    }

    /** @return array<int, string> */
    public static function paraGerencia(): array
    {
        return Seguimiento::dividirCorreos(Ajuste::obtener(Seguimiento::CLAVE_GERENCIA, Seguimiento::GERENCIA_POR_DEFECTO));
    }

    /** @return array<int, string> */
    public static function copia(): array
    {
        return Seguimiento::dividirCorreos(Ajuste::obtener(self::CLAVE_COPIA, ''));
    }

    /** Valida y guarda los correos en copia. Devuelve el mensaje de error o null. */
    public static function guardarCopia(string $texto): ?string
    {
        foreach (preg_split('/[\s,;]+/', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pieza) {
            if (! filter_var($pieza, FILTER_VALIDATE_EMAIL)) {
                return "«{$pieza}» no es un correo válido.";
            }
        }

        Ajuste::guardar(self::CLAVE_COPIA, implode(', ', Seguimiento::dividirCorreos($texto)));

        return null;
    }

    public static function ultimoEnvio(): ?Carbon
    {
        $valor = Ajuste::obtener(self::CLAVE_ULTIMO);

        return $valor ? Carbon::parse($valor) : null;
    }

    /**
     * Compromisos pendientes ya vencidos o que vencen en los próximos días, los más atrasados primero.
     *
     * @return Collection<int, array{registro: SeguimientoRegistro, trabajador: string, plazo: array}>
     */
    public static function novedades(?string $hoy = null): Collection
    {
        $hoy ??= self::hoy();
        $limite = Carbon::parse($hoy)->addDays(self::DIAS_PREVIOS)->toDateString();

        return SeguimientoRegistro::with('empleado')
            ->where('tipo', SeguimientoRegistro::COMPROMISO)
            ->where('estado', SeguimientoRegistro::PENDIENTE)
            ->whereDate('fecha_hasta', '<=', $limite)
            ->orderBy('fecha_hasta')
            ->get()
            ->filter(fn (SeguimientoRegistro $r) => $r->empleado !== null)
            ->map(fn (SeguimientoRegistro $r) => [
                'registro' => $r,
                'trabajador' => $r->empleado->nombreCompleto(),
                'plazo' => Seguimiento::plazo($r, $hoy),
            ])
            ->values();
    }

    /**
     * @return array{ok: bool, enviado: bool, mensaje: string}
     */
    public static function enviar(bool $forzar = false): array
    {
        $hoy = self::hoy();
        $ultimo = self::ultimoEnvio();

        if (! $forzar && $ultimo && $ultimo->timezone('America/Lima')->toDateString() === $hoy) {
            return ['ok' => true, 'enviado' => false, 'mensaje' => 'El resumen de hoy ya se envió.'];
        }

        $novedades = self::novedades($hoy);

        if ($novedades->isEmpty()) {
            return ['ok' => true, 'enviado' => false, 'mensaje' => 'No hay compromisos vencidos ni por vencer: no se envió nada.'];
        }

        $para = self::paraGerencia();

        if (! $para) {
            return ['ok' => false, 'enviado' => false, 'mensaje' => 'No hay correo de gerencia registrado.'];
        }

        $copia = array_values(array_diff(self::copia(), $para)); // quien ya va en «Para» no se repite en copia

        try {
            Mail::to($para)->cc($copia)->send(new ResumenCompromisosMail($novedades, $hoy));
        } catch (Throwable $e) {
            return ['ok' => false, 'enviado' => false, 'mensaje' => 'No se pudo enviar el correo: '.$e->getMessage()];
        }

        Ajuste::guardar(self::CLAVE_ULTIMO, now()->toIso8601String());

        $vencidos = $novedades->filter(fn ($n) => str_contains($n['plazo']['texto'], 'Venció'))->count();

        return [
            'ok' => true,
            'enviado' => true,
            'mensaje' => "Resumen enviado a ".implode(', ', $para).($copia ? ' (copia: '.implode(', ', $copia).')' : '')." — {$vencidos} vencidos y ".($novedades->count() - $vencidos).' por vencer.',
        ];
    }
}
