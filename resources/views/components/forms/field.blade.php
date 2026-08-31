@props(['label', 'name', 'type' => 'text', 'span' => false])

<div {{ $attributes->class(['sm:col-span-2' => $span]) }}>
    <label class="form-label">{{ $label }}</label>
    <input type="{{ $type }}" wire:model="{{ $name }}" class="form-input">
    @error($name)
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>
