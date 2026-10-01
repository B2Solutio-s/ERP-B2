import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

window.Echo.channel('reclutamiento.candidatos')
    .listen('.reclutamiento.actualizado', (payload) => {
        const currentUser = document.getElementById('reclutamiento-usuario');

        // Este canal se suscribe en todas las páginas (mismo bundle de JS), pero los
        // avisos ("fila bloqueada"/"cambios sincronizados") solo tienen sentido en
        // Proceso de reclutamiento — ese elemento solo existe en esa página.
        if (!currentUser) return;

        let userId = null;

        try {
            userId = JSON.parse(currentUser?.textContent || '{}').id;
        } catch {
            userId = null;
        }

        if (String(payload.usuarioId) === String(userId)) return;

        if (payload.tipo === 'fila-bloqueada') {
            window.dispatchEvent(new CustomEvent('fila-bloqueada', {
                detail: {
                    filaId: payload.filaId,
                    bloqueada: payload.bloqueada,
                    usuario: payload.usuarioNombre,
                },
            }));
            window.__reclutamientoNotify?.(`La fila está siendo editada por ${payload.usuarioNombre}.`, 'warning');
            return;
        }

        if (payload.tipo === 'tabla-actualizada') {
            const hasLocalDirtyRows = (window.__reclutamientoDirtyRows?.size || 0) > 0;

            window.__reclutamientoApplyRows?.(payload.filas);

            if (hasLocalDirtyRows) {
                window.__reclutamientoNotify?.(`${payload.usuarioNombre} guardó cambios. Se preservaron tus cambios locales en las filas que estabas editando.`, 'warning');
                return;
            }

            window.__reclutamientoNotify?.(`Cambios sincronizados desde ${payload.usuarioNombre}.`);
        }
    });
