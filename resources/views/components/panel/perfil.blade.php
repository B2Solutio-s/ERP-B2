<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.panel', ['title' => 'Perfil'])] class extends Component
{
    use WithFileUploads;

    /** Foto elegida (archivo temporal). */
    public $foto = null;

    public string $color = '';

    public string $mensaje = '';

    private const LADO_FOTO = 320;

    public function mount(): void
    {
        $this->color = Auth::user()->temaColor();
        $this->mensaje = (string) session('perfil_mensaje', '');
    }

    /** Se recarga la página para que el menú, la barra superior y todo lo demás tomen el cambio. */
    private function recargar(string $mensaje): void
    {
        session()->flash('perfil_mensaje', $mensaje);
        $this->redirectRoute('perfil');
    }

    public function elegirColor(string $color): void
    {
        $this->guardarColor($color);
    }

    /** El selector de color libre. */
    public function updatedColor(): void
    {
        $this->guardarColor($this->color);
    }

    private function guardarColor(string $color): void
    {
        $this->resetErrorBag();

        if (! User::colorValido($color)) {
            $this->addError('color', 'El color no es válido.');

            return;
        }

        Auth::user()->forceFill(['tema_color' => strtolower($color)])->save();
        $this->recargar('Color del tema actualizado.');
    }

    public function restablecerColor(): void
    {
        Auth::user()->forceFill(['tema_color' => null])->save();
        $this->recargar('Se restableció el color original.');
    }

    public function updatedFoto(): void
    {
        $this->resetErrorBag();
        $this->validate(['foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [], ['foto' => 'foto']);

        $bytes = $this->foto->get();
        $info = @getimagesizefromstring($bytes);

        // Evita imágenes enormes que agotarían la memoria al procesarlas.
        if (! $info || $info[0] * $info[1] > 25_000_000) {
            $this->addError('foto', 'La imagen es demasiado grande o no se puede leer.');
            $this->foto = null;

            return;
        }

        $origen = @imagecreatefromstring($bytes);

        if (! $origen) {
            $this->addError('foto', 'No se pudo leer la imagen.');
            $this->foto = null;

            return;
        }

        // Las fotos de celular traen la orientación en los metadatos.
        $orientacion = $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')
            ? (@exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes))['Orientation'] ?? 1)
            : 1;
        $origen = match ((int) $orientacion) {
            3 => imagerotate($origen, 180, 0),
            6 => imagerotate($origen, -90, 0),
            8 => imagerotate($origen, 90, 0),
            default => $origen,
        };

        // Recorte cuadrado centrado y tamaño fijo; al volver a codificarla se descarta cualquier metadato.
        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        $lado = min($ancho, $alto);
        $cuadrada = imagecreatetruecolor(self::LADO_FOTO, self::LADO_FOTO);
        imagefill($cuadrada, 0, 0, imagecolorallocate($cuadrada, 255, 255, 255));
        imagecopyresampled($cuadrada, $origen, 0, 0, intdiv($ancho - $lado, 2), intdiv($alto - $lado, 2), self::LADO_FOTO, self::LADO_FOTO, $lado, $lado);

        ob_start();
        imagejpeg($cuadrada, null, 86);
        $jpeg = (string) ob_get_clean();

        $usuario = Auth::user();
        $ruta = 'perfiles/'.$usuario->id.'.jpg';
        Storage::disk('local')->put($ruta, $jpeg);
        $usuario->forceFill(['foto_ruta' => $ruta])->save();

        $this->foto = null;
        $this->recargar('Foto de perfil actualizada.');
    }

    public function quitarFoto(): void
    {
        $usuario = Auth::user();

        if ($usuario->tieneFoto()) {
            Storage::disk('local')->delete($usuario->foto_ruta);
        }

        $usuario->forceFill(['foto_ruta' => null])->save();
        $this->recargar('Se quitó la foto de perfil.');
    }
};
?>

<div class="mx-auto max-w-3xl space-y-5">
    @php($usuario = auth()->user())

    @if ($mensaje !== '')
        <div class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $mensaje }}</div>
    @endif

    <section class="card-panel-compact">
        <h2 class="form-section-title">Foto de perfil</h2>
        <div class="mt-4 flex flex-wrap items-center gap-6">
            @if ($usuario->tieneFoto())
                <img src="{{ route('perfil.foto', ['usuario' => $usuario->id, 'v' => $usuario->updated_at?->timestamp]) }}" alt="Tu foto de perfil" class="h-24 w-24 rounded-full object-cover ring-2 ring-slate-200">
            @else
                <span class="flex h-24 w-24 items-center justify-center rounded-full bg-primary text-3xl font-semibold text-primary-content">{{ strtoupper(substr($usuario->name, 0, 1)) }}</span>
            @endif

            <div class="space-y-2">
                <p class="text-sm font-semibold text-slate-800">{{ $usuario->name }}</p>
                <p class="text-xs text-slate-500">Aparece en la esquina superior derecha. JPG, PNG o WebP de hasta 5 MB; se recorta en cuadrado.</p>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="gc-btn gc-btn-primary cursor-pointer">
                        <span wire:loading.remove wire:target="foto">{{ $usuario->tieneFoto() ? 'Cambiar foto' : 'Subir foto' }}</span>
                        <span wire:loading wire:target="foto">Subiendo…</span>
                        <input type="file" wire:model="foto" accept="image/jpeg,image/png,image/webp" class="hidden">
                    </label>
                    @if ($usuario->tieneFoto())
                        <button type="button" wire:click="quitarFoto" wire:confirm="¿Quitar tu foto de perfil?" class="gc-btn gc-btn-ghost">Quitar foto</button>
                    @endif
                </div>
                @error('foto')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="card-panel-compact">
        <h2 class="form-section-title">Color del tema</h2>
        <p class="mt-1 text-xs text-slate-500">Cambia el color de los botones, del menú y de los elementos destacados en todo el sistema. Solo se aplica a tu usuario.</p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            @foreach (\App\Models\User::TEMAS as $nombre => $hex)
                <button type="button" wire:key="tema-{{ $loop->index }}" wire:click="elegirColor('{{ $hex }}')" class="perfil-color {{ strtolower($color) === $hex ? 'perfil-color-activo' : '' }}" style="background-color: {{ $hex }}" title="{{ $nombre }}" aria-label="{{ $nombre }}" aria-pressed="{{ strtolower($color) === $hex ? 'true' : 'false' }}">
                    @if (strtolower($color) === $hex)
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    @endif
                </button>
            @endforeach

            <label class="ml-2 inline-flex items-center gap-2 text-sm text-slate-600">
                Otro color
                <input type="color" wire:model.live.debounce.700ms="color" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1" aria-label="Elegir otro color">
            </label>
        </div>

        @error('color')
            <p class="form-error">{{ $message }}</p>
        @enderror

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <span class="gc-btn gc-btn-primary pointer-events-none" aria-hidden="true">Así se verá un botón</span>
            <span class="badge" style="background-color: color-mix(in oklab, var(--color-primary) 15%, white); color: var(--color-primary)">Elemento destacado</span>
            @if ($usuario->tema_color)
                <button type="button" wire:click="restablecerColor" class="text-sm font-semibold text-primary">Volver al color original</button>
            @endif
        </div>
    </section>
</div>
