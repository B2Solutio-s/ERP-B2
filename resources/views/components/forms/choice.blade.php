@props(['label', 'name', 'options', 'span' => false, 'live' => false])

<div {{ $attributes->class(['sm:col-span-2' => $span]) }}>
    <label class="form-label">{{ $label }}</label>
    <div class="flex flex-wrap gap-2">
        @foreach ($options as $valor => $texto)
            <label class="form-choice-pill">
                <input type="radio" wire:model{{ $live ? '.live' : '' }}="{{ $name }}" value="{{ $valor }}">
                <span>{{ $texto }}</span>
            </label>
        @endforeach
    </div>
    @error($name)
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>
