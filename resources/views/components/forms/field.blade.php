@props(['label', 'name', 'type' => 'text', 'span' => false, 'live' => false, 'min' => null, 'max' => null, 'readonly' => false, 'hint' => null])

<div {{ $attributes->class(['sm:col-span-2' => $span]) }}>
    <label class="form-label">{{ $label }}</label>
    <input
        type="{{ $type }}"
        wire:model{{ $live ? '.live' : '' }}="{{ $name }}"
        @class(['form-input', 'cursor-not-allowed bg-slate-100 text-slate-500' => $readonly])
        @readonly($readonly)
        @if (! is_null($min)) min="{{ $min }}" @endif
        @if (! is_null($max)) max="{{ $max }}" @endif
    >
    @if ($readonly && $hint)
        <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>
