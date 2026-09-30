@props(['pasos', 'actual'])

<nav {{ $attributes->class(['wizard-sidebar-steps']) }}>
    @foreach ($pasos as $indice => $etiqueta)
        @php $numero = $indice + 1; @endphp

        <div class="wizard-sidebar-step">
            <span @class([
                'wizard-sidebar-step-circle',
                'wizard-sidebar-step-circle-active' => $numero === $actual,
                'wizard-sidebar-step-circle-done' => $numero < $actual,
            ])>
                @if ($numero < $actual)
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                @else
                    {{ $numero }}
                @endif
            </span>

            <span @class(['wizard-sidebar-step-label', 'wizard-sidebar-step-label-active' => $numero === $actual])>
                {{ $etiqueta }}
            </span>
        </div>
    @endforeach
</nav>
