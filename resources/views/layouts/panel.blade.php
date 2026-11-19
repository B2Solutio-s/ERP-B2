@php($embebido = request()->boolean('embed'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? 'Panel RRHH - ' . config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Color de tema del usuario (solo valores #rrggbb validados). --}}
        <style>{!! auth()->user()->temaCss() !!}</style>

        <script>try { if (localStorage.getItem('sidebar-collapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}</script>

        @if ($embebido)
            <style>html, body { background: transparent !important; }</style>
        @endif

        @livewireStyles
    </head>
    <body class="{{ $embebido ? 'embebido' : 'bg-app' }}">
        <div class="flex h-screen overflow-hidden">
            @unless ($embebido)
            <aside class="sidebar">
                <div class="sidebar-brand">
                    <span class="sidebar-label">{{ config('app.name') }}</span>
                    <span class="sidebar-brand-short">{{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}</span>
                </div>

                <nav class="sidebar-nav">
                    @if (auth()->user()->esAdmin())
                        <a href="{{ route('control-gth') }}" title="Control GTH" class="sidebar-item {{ request()->routeIs('control-gth') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                            </svg>
                            <span class="sidebar-label">Control GTH</span>
                        </a>
                    @endif

                    @if (auth()->user()->esAdmin() || auth()->user()->esGth())
                        <a href="{{ route('control-ingresos') }}" title="Control de ingresos" class="sidebar-item {{ request()->routeIs('control-ingresos') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                            <span class="sidebar-label">Control de ingresos</span>
                        </a>
                    @endif

                    @if (auth()->user()->esAdmin() || auth()->user()->esGth())
                        <a href="{{ route('gestion-candidatos') }}" title="Gestión de candidatos" class="sidebar-item {{ request()->routeIs('gestion-candidatos') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                            <span class="sidebar-label">Gestión de candidatos</span>
                        </a>
                    @endif

                    @if (auth()->user()->esGth() || auth()->user()->esAdmin())
                        <a href="{{ route('registro-ingresos') }}" title="Registro de ingresos" class="sidebar-item {{ request()->routeIs('registro-ingresos') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="sidebar-label">Registro de ingresos</span>
                        </a>
                    @endif

                    @if (auth()->user()->esGth() || auth()->user()->esAdmin())
                        <a href="{{ route('asistencia') }}" title="Asistencia" class="sidebar-item {{ request()->routeIs('asistencia', 'asistencia-mensual') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="sidebar-label">Asistencia</span>
                        </a>
                    @endif

                    @if (auth()->user()->esAdmin())
                        <a href="{{ route('vacaciones') }}" title="Vacaciones" class="sidebar-item {{ request()->routeIs('vacaciones') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                            </svg>
                            <span class="sidebar-label">Vacaciones</span>
                        </a>
                    @endif

                    @if (auth()->user()->esAdmin() || auth()->user()->esGth())
                        <a href="{{ route('delivery-ejecutivos') }}" title="Seguimiento de ejecutivos" class="sidebar-item {{ request()->routeIs('delivery-ejecutivos') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                            </svg>
                            <span class="sidebar-label">Seguimiento de ejecutivos</span>
                        </a>
                    @endif

                    @if (auth()->user()->esMkt() || auth()->user()->esAdmin())
                        <a href="{{ route('resultados-campana') }}" title="Resultados de campaña" class="sidebar-item {{ request()->routeIs('resultados-campana') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M0 11.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5zm4-3a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 .5.5v5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5zm4-3a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 .5.5v8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5zm4-3a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5z"/>
                            </svg>
                            <span class="sidebar-label">Resultados de campaña</span>
                        </a>
                    @endif

                    @if (auth()->user()->esAdmin())
                        <a href="{{ route('catalogos') }}" title="Catálogos" class="sidebar-item {{ request()->routeIs('catalogos') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a7.996 7.996 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span class="sidebar-label">Catálogos</span>
                        </a>
                    @endif

                    @if (auth()->user()->esAdmin())
                        <a href="{{ route('usuarios') }}" title="Usuarios" class="sidebar-item {{ request()->routeIs('usuarios') ? 'sidebar-item-active' : '' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                            </svg>
                            <span class="sidebar-label">Usuarios</span>
                        </a>
                    @endif
                </nav>

                <div class="sidebar-pie">
                    <a href="{{ route('perfil') }}" title="Perfil" class="sidebar-item {{ request()->routeIs('perfil') ? 'sidebar-item-active' : '' }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        <span class="sidebar-label">Perfil</span>
                    </a>
                </div>
            </aside>
            @endunless

            <div class="flex flex-1 flex-col overflow-hidden">
                @unless ($embebido)
                <header class="topbar shrink-0">
                    <div class="flex items-center gap-3">
                        <button type="button" data-sidebar-toggle class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Mostrar u ocultar el menú" title="Mostrar u ocultar el menú">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                            </svg>
                        </button>
                        <span class="text-lg font-semibold text-slate-900">{{ $title ?? 'Panel RRHH' }}</span>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-3">
                            @if (auth()->user()->tieneFoto())
                                <img src="{{ route('perfil.foto', ['usuario' => auth()->id(), 'v' => auth()->user()->updated_at?->timestamp]) }}" alt="Foto de {{ auth()->user()->name }}" class="avatar-foto">
                            @else
                                <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                            @endif
                            <span class="text-sm text-slate-500">{{ auth()->user()->name }}</span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn-secondary">Salir</button>
                        </form>
                    </div>
                </header>
                @endunless

                <main class="flex-1 overflow-y-auto {{ $embebido ? '' : 'px-6 py-6' }}">
                    <div class="mx-auto max-w-7xl">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        @unless ($embebido)
            @if (auth()->user()->esGth() || auth()->user()->esAdmin())
                <livewire:panel.recordatorio-entrevistas />
            @endif

            @if (auth()->user()->esAdmin() || auth()->user()->esGth())
                <livewire:panel.recordatorio-llamadas />
            @endif
        @endunless

        @livewireScripts
    </body>
</html>
