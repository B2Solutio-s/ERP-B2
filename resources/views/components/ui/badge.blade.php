@props(['color' => 'slate'])

<span {{ $attributes->class(['badge', "badge-{$color}"]) }}>
    {{ $slot }}
</span>
