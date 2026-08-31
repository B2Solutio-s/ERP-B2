@props(['centered' => false])

<div {{ $attributes->class(['card-panel', 'text-center' => $centered]) }}>
    {{ $slot }}
</div>
