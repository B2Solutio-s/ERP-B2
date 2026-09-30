@props(['centered' => false, 'compact' => false])

<div {{ $attributes->class([$compact ? 'card-panel-compact' : 'card-panel', 'text-center' => $centered]) }}>
    {{ $slot }}
</div>
