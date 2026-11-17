<?php

use App\Models\CandidatoReclutamiento;
use App\Services\GestionCandidatos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    private const ZONA = 'America/Lima';

    /** Admin y GTH, que son quienes usan el módulo de candidatos. */
    public function aplica(): bool
    {
        $usuario = Auth::user();

        return $usuario && ($usuario->esAdmin() || $usuario->esGth());
    }

    private function sinTildes(?string $texto): string
    {
        return Str::upper(Str::ascii(trim((string) $texto)));
    }

    /**
     * Llamadas que ya hay que hacer: las vencidas y las de hoy cuya hora ya llegó (o que no tienen hora).
     *
     * @return array<int, array{id: int, hora: ?string, nombre: string, puesto: ?string, celular: ?string, vencida: bool, intentos: int, desde: ?string}>
     */
    public function llamadas(): array
    {
        if (! $this->aplica()) {
            return [];
        }

        $ahora = Carbon::now(self::ZONA);
        $hoy = $ahora->toDateString();

        return CandidatoReclutamiento::query()
            ->whereIn('tipificacion', GestionCandidatos::VOLVER_A_LLAMAR)
            ->whereNotNull('volver_a_llamar_fecha')
            ->whereDate('volver_a_llamar_fecha', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('apto')->orWhere('apto', false))
            ->get()
            // El Admin ve todas; cada GTH, las de sus candidatos y las que no tienen reclutador.
            ->filter(fn (CandidatoReclutamiento $c) => Auth::user()->esAdmin()
                || blank($c->agente_reclutador)
                || $this->sinTildes($c->agente_reclutador) === $this->sinTildes(Auth::user()->name))
            ->map(function (CandidatoReclutamiento $c) use ($ahora, $hoy) {
                $fecha = $c->volver_a_llamar_fecha->toDateString();
                $hora = preg_match('/^(\d{1,2}):(\d{2})/', (string) $c->volver_a_llamar_hora, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
                $vencida = $fecha < $hoy;

                return [
                    'id' => $c->id,
                    'hora' => $hora,
                    'nombre' => $c->nombreCompleto() ?: ($c->numero_celular ?: 'Sin nombre'),
                    'puesto' => $c->puesto,
                    'celular' => $c->nombreCompleto() ? $c->numero_celular : null,
                    'vencida' => $vencida,
                    'intentos' => (int) $c->intentos_contacto,
                    'desde' => $vencida ? $c->volver_a_llamar_fecha->format('d/m') : null,
                    'llegada' => $vencida || ! $hora || Carbon::parse("{$hoy} {$hora}", self::ZONA)->lte($ahora),
                ];
            })
            ->filter(fn ($l) => $l['llegada'])
            ->sortBy(fn ($l) => ($l['vencida'] ? '0' : '1').($l['hora'] ?? '00:00').$l['nombre'])
            ->map(fn ($l) => collect($l)->except('llegada')->all())
            ->values()
            ->all();
    }

    /** Cambia cuando una llamada llega a su hora, se reprograma o se atiende: el aviso vuelve a mostrarse. */
    private function firma(array $llamadas): string
    {
        return Carbon::now(self::ZONA)->toDateString().'|'.md5(json_encode(array_map(fn ($l) => [$l['id'], $l['hora'], $l['vencida']], $llamadas)));
    }

    public function descartar(): void
    {
        session(['recordatorio_llamadas_firma' => $this->firma($this->llamadas())]);
    }

    public function visibles(): array
    {
        $llamadas = $this->llamadas();

        return $llamadas && session('recordatorio_llamadas_firma') !== $this->firma($llamadas) ? $llamadas : [];
    }
};
?>

<div wire:poll.60s>
    @php($pendientes = $this->visibles())
    @if ($pendientes)
        <div class="recordatorio-popup fixed bottom-4 right-4 z-[60] w-[26rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl bg-white shadow-2xl ring-2 ring-sky-400" role="alert" aria-live="polite">
            <div class="flex items-center gap-3 bg-gradient-to-r from-sky-500 to-indigo-500 px-4 py-3 text-white">
                <svg class="recordatorio-campana h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                </svg>
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="text-sm font-bold uppercase tracking-wide">Volver a llamar</p>
                    <p class="text-[11px] text-sky-50">{{ count($pendientes) }} {{ count($pendientes) === 1 ? 'llamada pendiente' : 'llamadas pendientes' }}</p>
                </div>
                <span class="flex h-8 min-w-8 items-center justify-center rounded-full bg-white px-2 text-sm font-extrabold text-indigo-600">{{ count($pendientes) }}</span>
            </div>

            <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                @foreach ($pendientes as $llamada)
                    <li wire:key="llamada-{{ $llamada['id'] }}">
                        <a href="{{ route('gestion-candidatos', ['abrir' => $llamada['id']]) }}" class="flex items-center gap-3 px-4 py-2 transition hover:bg-slate-50">
                            <span class="shrink-0 rounded-lg {{ $llamada['vencida'] ? 'bg-rose-100 text-rose-700' : 'bg-sky-100 text-sky-800' }} px-2 py-1 text-xs font-extrabold tabular-nums">{{ $llamada['vencida'] ? $llamada['desde'] : ($llamada['hora'] ?? '--:--') }}</span>
                            <div class="min-w-0 flex-1 leading-tight">
                                <p class="truncate text-[13px] font-bold uppercase text-slate-800">{{ $llamada['nombre'] }}</p>
                                <p class="truncate text-[11px] text-slate-400">{{ collect([$llamada['celular'], $llamada['vencida'] ? 'Vencida' : null, $llamada['intentos'] > 0 ? $llamada['intentos'].($llamada['intentos'] === 1 ? ' intento' : ' intentos') : null])->filter()->implode(' · ') }}</p>
                            </div>
                            @if ($llamada['puesto'])
                                <span class="max-w-[8.5rem] shrink-0 truncate rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600" title="{{ $llamada['puesto'] }}">{{ $llamada['puesto'] }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-slate-100 bg-slate-50 px-4 py-2 text-right">
                <button type="button" wire:click="descartar" class="rounded-lg bg-indigo-500 px-3 py-1 text-xs font-bold text-white transition hover:bg-indigo-600">Entendido</button>
            </div>
        </div>
    @endif
</div>
