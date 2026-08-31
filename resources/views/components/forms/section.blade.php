@props(['title'])

<fieldset class="space-y-4">
    <legend class="form-section-title">{{ $title }}</legend>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {{ $slot }}
    </div>
</fieldset>
