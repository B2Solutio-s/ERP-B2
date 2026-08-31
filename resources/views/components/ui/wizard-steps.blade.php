@props(['pasos', 'actual'])

<div class="wizard-tabs">
    @foreach ($pasos as $indice => $etiqueta)
        @php $numero = $indice + 1; @endphp

        <div @class(['wizard-tab', 'wizard-tab-active' => $numero === $actual])>
            <span @class([
                'wizard-tab-circle',
                'wizard-tab-circle-active' => $numero === $actual,
                'wizard-tab-circle-done' => $numero < $actual,
            ])>
                @if ($numero < $actual)
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                @else
                    {{ $numero }}
                @endif
            </span>

            {{ $etiqueta }}
        </div>
    @endforeach
</div>
