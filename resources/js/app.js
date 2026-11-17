import './signature-pad.js';

// Menú lateral desplegable: alterna la clase en <html> y recuerda la elección (el script del layout la aplica antes de pintar).
document.addEventListener('click', (evento) => {
    if (! evento.target.closest('[data-sidebar-toggle]')) {
        return;
    }

    const colapsado = document.documentElement.classList.toggle('sidebar-collapsed');

    try {
        localStorage.setItem('sidebar-collapsed', colapsado ? '1' : '0');
    } catch (e) {
        // sin almacenamiento: el estado solo dura la visita
    }
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
