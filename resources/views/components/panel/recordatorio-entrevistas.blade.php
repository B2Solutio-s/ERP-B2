<?php

use App\Models\CandidatoReclutamiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    private const ZONA = 'America/Lima';

    /** Solo el equipo de reclutamiento (GTH) y administración reciben el recordatorio. */
    public function aplica(): bool
    {
        $usuario = Auth::user();

        return $usuario && ($usuario->esGth() || $usuario->esAdmin());
    }

    /**
     * Entrevistas de hoy (programadas o reprogramadas) que aún no tienen resultado y cuya hora no ha pasado.
     * La reprogramación no tiene hora propia: se usa la hora de la entrevista.
     *
     * @return array<int, array{id: int, hora: ?string, nombre: string, celular: ?string, reprogramada: bool}>
     */
    public function entrevistas(): array
    {
        if (! $this->aplica()) {
            return [];
        }

        $ahora = Carbon::now(self::ZONA);
        $hoy = $ahora->toDateString();
        $sinResultado = fn ($consulta, string $campo) => $consulta->where(fn ($q) => $q->whereNull($campo)->orWhere($campo, ''));

        $candidatos = CandidatoReclutamiento::query()
            ->where(function ($q) use ($hoy, $sinResultado) {
                $q->where(fn ($q) => $sinResultado($q->whereDate('fecha_entrevista', $hoy), 'asistio_entrevista'))
                    ->orWhere(fn ($q) => $sinResultado($q->whereDate('fecha_reprogramada', $hoy)->where('asistio_entrevista', 'REPROGRAMADO'), 'asistio_entrevista_reprogramada'));
            })
            ->get();

        return $candidatos
            ->map(function (CandidatoReclutamiento $c) use ($ahora, $hoy) {
                $hora = preg_match('/^(\d{1,2}):(\d{2})/', (string) $c->hora_entrevista, $m)
                    ? sprintf('%02d:%02d', $m[1], $m[2])
                    : null;

                return [
                    'id' => $c->id,
                    'hora' => $hora,
                    'nombre' => $c->nombreCompleto() ?: 'Sin nombre',
                    'celular' => $c->numero_celular,
                    'reprogramada' => $c->asistio_entrevista === 'REPROGRAMADO' && $c->fecha_reprogramada?->toDateString() === $hoy,
                    'vigente' => $hora ? Carbon::parse("{$hoy} {$hora}", self::ZONA)->gte($ahora) : $ahora->hour < 12,
                ];
            })
            ->filter(fn ($e) => $e['vigente'])
            ->sortBy(fn ($e) => ($e['hora'] ?? '99:99').$e['nombre'])
            ->map(fn ($e) => collect($e)->except('vigente')->all())
            ->values()
            ->all();
    }

    /** Cambia si se programa o reprograma alguien más: en ese caso el aviso vuelve a mostrarse. */
    private function firma(array $entrevistas): string
    {
        return Carbon::now(self::ZONA)->toDateString().'|'.md5(json_encode(array_map(fn ($e) => [$e['id'], $e['hora'], $e['reprogramada']], $entrevistas)));
    }

    public function descartar(): void
    {
        session(['recordatorio_entrevistas_firma' => $this->firma($this->entrevistas())]);
    }

    public function visibles(): array
    {
        $entrevistas = $this->entrevistas();

        return $entrevistas && session('recordatorio_entrevistas_firma') !== $this->firma($entrevistas) ? $entrevistas : [];
    }
};
?>

<div wire:poll.60s>
    @php($entrevistasHoy = $this->visibles())
    @if ($entrevistasHoy)
        <div class="recordatorio-popup fixed right-4 top-16 z-[60] w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl bg-white shadow-2xl ring-2 ring-amber-400" role="alert" aria-live="polite">
            <div class="flex items-center gap-3 bg-gradient-to-r from-amber-500 to-orange-500 px-4 py-3 text-white">
                <svg class="recordatorio-campana h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="text-sm font-bold uppercase tracking-wide">Entrevistas de hoy</p>
                    <p class="text-[11px] text-amber-50">{{ count($entrevistasHoy) }} {{ count($entrevistasHoy) === 1 ? 'postulante programado' : 'postulantes programados' }}</p>
                </div>
                <span class="flex h-8 min-w-8 items-center justify-center rounded-full bg-white px-2 text-sm font-extrabold text-orange-600">{{ count($entrevistasHoy) }}</span>
            </div>

            <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                @foreach ($entrevistasHoy as $entrevista)
                    <li wire:key="recordatorio-{{ $entrevista['id'] }}" class="flex items-center gap-3 px-4 py-2">
                        <span class="shrink-0 rounded-lg bg-amber-100 px-2 py-1 text-xs font-extrabold tabular-nums text-amber-800">{{ $entrevista['hora'] ?? '--:--' }}</span>
                        <div class="min-w-0 flex-1 leading-tight">
                            <p class="truncate text-[13px] font-bold uppercase text-slate-800">{{ $entrevista['nombre'] }}</p>
                            @if ($entrevista['celular'])
                                <p class="truncate text-[11px] text-slate-400">{{ $entrevista['celular'] }}</p>
                            @endif
                        </div>
                        @if ($entrevista['reprogramada'])
                            <span class="shrink-0 rounded-full bg-violet-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-violet-700">Reprog.</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-slate-100 bg-slate-50 px-4 py-2 text-right">
                <button type="button" wire:click="descartar" class="rounded-lg bg-orange-500 px-3 py-1 text-xs font-bold text-white transition hover:bg-orange-600">Entendido</button>
            </div>
        </div>
    @endif
</div>
