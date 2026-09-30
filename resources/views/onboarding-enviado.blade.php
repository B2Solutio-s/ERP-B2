<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>Registro enviado - {{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-app">
        <div class="page-shell onboarding-ficha">
            <div class="mx-auto max-w-xl">
                <x-ui.card centered>
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success/10">
                        <svg class="h-6 w-6 text-success" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>
                    <h1 class="mt-4 text-xl font-semibold text-slate-900">Registro enviado</h1>
                    <p class="mt-2 text-slate-500">
                        @if ($nombre)
                            Gracias, {{ $nombre }}. Tus datos fueron enviados a Recursos Humanos correctamente.
                        @else
                            Tus datos fueron enviados a Recursos Humanos correctamente.
                        @endif
                    </p>
                </x-ui.card>
            </div>
        </div>
    </body>
</html>
