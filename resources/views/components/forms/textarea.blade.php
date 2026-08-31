@props(['label', 'name', 'span' => false, 'rows' => 3])

<div {{ $attributes->class(['sm:col-span-2' => $span]) }}>
    <label class="form-label">{{ $label }}</label>
    <textarea wire:model="{{ $name }}" rows="{{ $rows }}" class="form-input"></textarea>
    @error($name)
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>
