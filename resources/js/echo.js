import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// El formulario público de ficha (/alta/...) se abre fuera de la red de la oficina y no usa tiempo real.
if (!window.location.pathname.startsWith('/alta')) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    window.Echo.channel('asistencia.marcaciones')
        .listen('.asistencia.registrada', () => {
            window.Livewire?.dispatch('asistencia-registrada');
        });
}
