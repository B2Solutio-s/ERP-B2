@props(['label', 'name', 'type' => 'text', 'span' => false, 'live' => false, 'min' => null, 'max' => null])

<div {{ $attributes->class(['sm:col-span-2' => $span]) }}>
    <label class="form-label">{{ $label }}</label>
    <input
        type="{{ $type }}"
        wire:model{{ $live ? '.live' : '' }}="{{ $name }}"
        class="form-input"
        @if (! is_null($min)) min="{{ $min }}" @endif
        @if (! is_null($max)) max="{{ $max }}" @endif
    >
    @error($name)
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>
