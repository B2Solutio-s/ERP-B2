@props(['label', 'name', 'options', 'placeholder' => 'Selecciona...', 'span' => false])

<div {{ $attributes->class(['sm:col-span-2' => $span]) }}>
    <label class="form-label">{{ $label }}</label>
    <select wire:model="{{ $name }}" class="form-input">
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $valor => $texto)
            <option value="{{ $valor }}">{{ $texto }}</option>
        @endforeach
    </select>
    @error($name)
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>
