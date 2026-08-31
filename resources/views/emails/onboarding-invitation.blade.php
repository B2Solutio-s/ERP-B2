<x-mail::message>
# Bienvenido a {{ config('app.name') }}

Hola{{ $nombreCandidato ? ' ' . $nombreCandidato : '' }},

Para completar tu proceso de alta como nuevo colaborador, por favor llena el siguiente formulario con tus datos.

<x-mail::button :url="$url">
Llenar mi formulario
</x-mail::button>

Este enlace es personal y de un solo uso. Vence el {{ $expiraEn->format('d/m/Y H:i') }}.

Si el boton no funciona, copia y pega este enlace en tu navegador:<br>
{{ $url }}

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
