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

// Comentarios de la asistencia diaria: se guardan al dejar de escribir y NO dependen de que Livewire vuelva a pintar la página
// (los refrescos por marcaciones en vivo borraban el texto que se estaba escribiendo). Las cajas llevan wire:ignore.
const temporizadoresComentario = new WeakMap();

const cajaComentario = (elemento) => elemento?.closest?.('[data-comentario-asistencia], [data-comentario-dia]') ?? null;

// El estado se muestra junto a la caja con un texto corto; el detalle de un error va en el tooltip.
const mostrarEstadoComentario = (caja, texto, clases, detalle = '') => {
    const contenedor = caja.closest('[data-comentario-asistencia], [data-comentario-dia]')?.parentElement;
    const estado = contenedor?.querySelector('[data-comentario-estado]');
    if (!estado) return;
    estado.textContent = texto;
    estado.title = detalle;
    estado.className = `${estado.className.replace(/text-(emerald|rose|slate)-\d+/g, '').trim()} ${clases}`;
};

const guardarComentarioAsistencia = async (caja) => {
    temporizadoresComentario.delete(caja);

    const raiz = caja.closest('[wire\\:id]');
    const componente = raiz && window.Livewire?.find(raiz.getAttribute('wire:id'));
    if (!componente) return;

    mostrarEstadoComentario(caja, 'Guardando…', 'text-slate-400');

    try {
        const resultado = 'comentarioDia' in caja.dataset
            ? await componente.guardarComentarioDia(caja.dataset.comentarioDia, caja.value)
            : await componente.guardarComentario(caja.dataset.fecha, caja.dataset.comentarioAsistencia, caja.value);

        resultado?.ok
            ? mostrarEstadoComentario(caja, '✓ Guardado', 'text-emerald-600')
            : mostrarEstadoComentario(caja, '✕ No se guardó', 'text-rose-600', resultado?.mensaje || 'No se pudo guardar el comentario.');
    } catch (error) {
        mostrarEstadoComentario(caja, '✕ No se guardó', 'text-rose-600', 'Revisa tu conexión e inténtalo de nuevo.');
    }
};

document.addEventListener('input', (evento) => {
    const caja = cajaComentario(evento.target);
    if (!caja) return;

    clearTimeout(temporizadoresComentario.get(caja));
    mostrarEstadoComentario(caja, '', 'text-slate-400');
    temporizadoresComentario.set(caja, setTimeout(() => guardarComentarioAsistencia(caja), 700));
});

// Al salir de la caja se guarda de inmediato lo que quedara pendiente.
document.addEventListener('focusout', (evento) => {
    const caja = cajaComentario(evento.target);
    if (!caja || !temporizadoresComentario.has(caja)) return;

    clearTimeout(temporizadoresComentario.get(caja));
    guardarComentarioAsistencia(caja);
});
