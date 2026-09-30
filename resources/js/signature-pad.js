const initFirmaPad = (canvas) => {
    if (!canvas || canvas.dataset.firmaReady === 'true') return;
    canvas.dataset.firmaReady = 'true';

    const input = document.querySelector('[data-firma-input]');
    const limpiarBtn = document.querySelector('[data-firma-limpiar]');
    if (!input) return;

    const ctx = canvas.getContext('2d');
    let dibujando = false;

    const dimensionar = () => {
        const ratio = window.devicePixelRatio || 1;
        const { width, height } = canvas.getBoundingClientRect();
        if (!width || !height) return;

        canvas.width = width * ratio;
        canvas.height = height * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1e293b';

        if (input.value) {
            const img = new Image();
            img.onload = () => ctx.drawImage(img, 0, 0, width, height);
            img.src = input.value;
        }
    };

    dimensionar();

    const posicionDesdeEvento = (event) => {
        const rect = canvas.getBoundingClientRect();
        const punto = event.touches ? event.touches[0] : event;
        return { x: punto.clientX - rect.left, y: punto.clientY - rect.top };
    };

    const empezarTrazo = (event) => {
        event.preventDefault();
        dibujando = true;
        const { x, y } = posicionDesdeEvento(event);
        ctx.beginPath();
        ctx.moveTo(x, y);
    };

    const dibujarTrazo = (event) => {
        if (!dibujando) return;
        event.preventDefault();
        const { x, y } = posicionDesdeEvento(event);
        ctx.lineTo(x, y);
        ctx.stroke();
    };

    const terminarTrazo = () => {
        if (!dibujando) return;
        dibujando = false;
        input.value = canvas.toDataURL('image/png');
        input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    canvas.addEventListener('mousedown', empezarTrazo);
    canvas.addEventListener('mousemove', dibujarTrazo);
    window.addEventListener('mouseup', terminarTrazo);

    canvas.addEventListener('touchstart', empezarTrazo, { passive: false });
    canvas.addEventListener('touchmove', dibujarTrazo, { passive: false });
    canvas.addEventListener('touchend', terminarTrazo);

    limpiarBtn?.addEventListener('click', () => {
        const { width, height } = canvas.getBoundingClientRect();
        ctx.clearRect(0, 0, width, height);
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
};

const buscarYActivarFirmaPad = () => {
    const canvas = document.querySelector('[data-firma-canvas]');
    if (canvas) initFirmaPad(canvas);
};

document.addEventListener('DOMContentLoaded', buscarYActivarFirmaPad);
document.addEventListener('livewire:navigated', buscarYActivarFirmaPad);

if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver(buscarYActivarFirmaPad);
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }
}
