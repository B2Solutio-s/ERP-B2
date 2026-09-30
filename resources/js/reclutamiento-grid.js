import * as XLSX from 'xlsx';

const FIELD_KEYS = [
    'mes', 'fecha_gestion', 'agente_reclutador', 'campana', 'dni_ce', 'edad', 'nombres', 'apellidos',
    'numero_celular', 'distrito', 'observaciones', 'tipificacion', 'subtipificacion_rechazo',
    'aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
    'fecha_reprogramada', 'asistio_entrevista_reprogramada',
];

const FIELD_LABELS = {
    mes: 'Mes',
    fecha_gestion: 'Fecha gestión',
    agente_reclutador: 'Agente reclutador',
    campana: 'Campaña',
    dni_ce: 'DNI / C.E',
    edad: 'Edad',
    nombres: 'Nombres',
    apellidos: 'Apellidos',
    numero_celular: 'Número celular',
    distrito: 'Distrito',
    observaciones: 'Observaciones',
    tipificacion: 'Tipificación',
    subtipificacion_rechazo: 'Subtipificación rechazo',
    aceptacion_entrevista: 'Aceptación entrevista',
    fecha_entrevista: 'Fecha entrevista',
    hora_entrevista: 'Hora de entrevista',
    asistio_entrevista: 'Asistió entrevista',
    fecha_reprogramada: 'Fecha reprogramada',
    asistio_entrevista_reprogramada: 'Asistió ent. Rep',
};

const MONTHS = [
    'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
    'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE',
];

const DISTRICTS = [
    'ATE', 'BARRANCO', 'BREÑA', 'CARABAYLLO', 'CHACLACAYO', 'CHORRILLOS', 'CIENEGUILLA',
    'COMAS', 'EL AGUSTINO', 'INDEPENDENCIA', 'JESÚS MARÍA', 'LA MOLINA', 'LA VICTORIA',
    'LIMA', 'LINCE', 'LOS OLIVOS', 'LURIGANCHO', 'LURÍN', 'MAGDALENA DEL MAR', 'PUEBLO LIBRE',
    'MIRAFLORES', 'PACHACÁMAC', 'PUCUSANA', 'PUENTE PIEDRA', 'PUNTA HERMOSA', 'PUNTA NEGRA',
    'RÍMAC', 'SAN BARTOLO', 'SAN BORJA', 'SAN ISIDRO', 'SAN JUAN DE LURIGANCHO',
    'SAN JUAN DE MIRAFLORES', 'SAN LUIS', 'SAN MARTÍN DE PORRES', 'SAN MIGUEL', 'SANTA ANITA',
    'SANTA MARÍA DEL MAR', 'SANTA ROSA', 'SANTIAGO DE SURCO', 'SURQUILLO', 'VILLA EL SALVADOR',
    'VILLA MARÍA DEL TRIUNFO', 'OTROS',
];

const TEXT_FIELDS = new Set([
    'agente_reclutador', 'campana', 'dni_ce', 'nombres', 'apellidos', 'numero_celular', 'distrito',
    'observaciones', 'tipificacion', 'subtipificacion_rechazo',
]);

const SELECT_FIELDS = new Set([
    'mes', 'campana', 'distrito', 'tipificacion', 'subtipificacion_rechazo',
    'aceptacion_entrevista', 'asistio_entrevista', 'asistio_entrevista_reprogramada',
]);

const FILTERABLE_COLUMNS = FIELD_KEYS.filter((key) => key !== 'observaciones');

const DEFAULT_MINIMUM_COLUMN_WIDTHS = {
    mes: 140,
};

const getJsonScript = (id, fallback) => {
    const element = document.getElementById(id);
    if (!element) return fallback;

    try {
        const parsed = JSON.parse(element.textContent || 'null');
        return parsed === null ? fallback : parsed;
    } catch {
        return fallback;
    }
};

const getCampanas = () => getJsonScript('reclutamiento-campanas', []).filter(Boolean).map((value) => String(value).toUpperCase());
const getTipificacionOptions = () => getJsonScript('reclutamiento-tipificacion-options', ['INTERESADO - APTO', 'NO INTERESADO', 'NO PASA FILTRO', 'NO CONTESTA']);
const getSubtipificacionOptions = () => getJsonScript('reclutamiento-subtipificacion-options', ['NO CONTESTA', 'NO TIENE EXPERIENCIA', 'NO CALIFICA']);
const getAsistioOptions = () => getJsonScript('reclutamiento-asistio-options', ['SI, APTO', 'SI, NO APTO', 'NO', 'REPROGRAMADO']);
const getAsistioReprogramadaOptions = () => getAsistioOptions().filter((option) => option !== 'REPROGRAMADO');

const SUBTIPIFICACION_ENABLED_WHEN = new Set(['NO INTERESADO', 'NO PASA FILTRO']);

const getCurrentUser = () => getJsonScript('reclutamiento-usuario', { id: null, nombre: '' });

const isPersistedRow = (row) => row.id && /^\d+$/.test(String(row.id));

const callLivewire = (method, ...params) => {
    const root = document.querySelector('[wire\\:id]');
    const componentId = root?.getAttribute('wire:id');
    if (componentId && window.Livewire) {
        window.Livewire.find(componentId)?.call(method, ...params);
    }
};

const scheduleRowSave = (row) => {
    if (!window.__reclutamientoSaveTimers) window.__reclutamientoSaveTimers = new Map();

    const key = String(row.id);
    const previousTimer = window.__reclutamientoSaveTimers.get(key);
    if (previousTimer) window.clearTimeout(previousTimer);

    const timer = window.setTimeout(() => {
        window.__reclutamientoSaveTimers.delete(key);
        callLivewire('guardarFila', row);
    }, 900);

    window.__reclutamientoSaveTimers.set(key, timer);
};

const markRowDirty = (row) => {
    if (!row?.id) return;
    if (!window.__reclutamientoDirtyRows) window.__reclutamientoDirtyRows = new Set();
    window.__reclutamientoDirtyRows.add(String(row.id));
    window.__reclutamientoDirty = true;
};

const clearRowDirty = (rowId) => {
    if (!rowId) return;
    if (!window.__reclutamientoDirtyRows) return;
    window.__reclutamientoDirtyRows.delete(String(rowId));
    window.__reclutamientoDirty = window.__reclutamientoDirtyRows.size > 0;
};

const setRowLockState = (rowId, locked, userName = '', ownerId = null) => {
    const row = document.querySelector(`tr[data-candidato-id="${rowId}"]`);
    if (!row) return;
    if (row.dataset.graduated === 'true') return;

    const currentUser = getCurrentUser();
    const belongsToCurrentUser = ownerId !== null
        && ownerId !== ''
        && String(ownerId) === String(currentUser.id);
    const shouldLock = Boolean(locked) && !belongsToCurrentUser;

    row.dataset.locked = shouldLock ? 'true' : 'false';
    row.dataset.lockedBy = shouldLock ? (userName || 'otro usuario') : '';
    row.classList.toggle('reclutamiento-row-locked', shouldLock);
    row.querySelectorAll('input, select').forEach((field) => {
        field.disabled = shouldLock;
    });

    const removeButton = row.querySelector('[data-remove-row]');
    if (removeButton) {
        removeButton.disabled = shouldLock || isPersistedRow({ id: rowId });
    }

    const status = row.querySelector('[data-lock-status]');
    if (status) {
        status.textContent = shouldLock ? 'En edición' : '';
        status.title = shouldLock
            ? `${userName || 'Otro usuario'} está editando esta fila. El bloqueo se renueva durante 1 minuto con cada cambio.`
            : '';
        status.setAttribute('aria-label', shouldLock ? status.title : '');
        status.classList.toggle('hidden', !shouldLock);
    }

    if (!shouldLock && window.__reclutamientoLockTooltipRowId === String(rowId)) {
        window.__reclutamientoHideLockTooltip?.();
    }

    applyConditionalFieldLocks(row);
};

window.__reclutamientoNotify = (message, tone = 'info') => {
    const toast = document.createElement('div');
    toast.className = `fixed right-5 top-5 z-[70] max-w-sm rounded-lg px-4 py-3 text-sm font-medium shadow-lg ${tone === 'warning' ? 'bg-amber-100 text-amber-900' : 'bg-sky-100 text-sky-900'}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    window.setTimeout(() => toast.remove(), 5000);
};

const setupLockNotifications = () => {
    if (window.__reclutamientoLockNotifications) return;

    window.__reclutamientoLockNotifications = true;
    window.addEventListener('fila-bloqueada', (event) => {
        const { filaId, bloqueada, usuario } = event.detail || {};
        if (filaId) setRowLockState(filaId, Boolean(bloqueada), usuario || 'otro usuario');
    });
    window.addEventListener('fila-eliminacion-denegada', () => {
        window.__reclutamientoNotify?.('No es posible eliminar candidatos ya guardados.', 'warning');
    });
    window.addEventListener('reclutamiento-filas-actualizadas', (event) => {
        const { filas } = event.detail || {};
        if (filas) window.__reclutamientoApplyRows?.(filas);
    });
};

const setupLockHoverTooltip = () => {
    if (window.__reclutamientoLockTooltipReady) return;
    window.__reclutamientoLockTooltipReady = true;

    const tooltip = document.createElement('div');
    tooltip.className = 'reclutamiento-lock-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    document.body.appendChild(tooltip);

    const positionTooltip = (clientX, clientY) => {
        tooltip.style.left = `${clientX + 14}px`;
        tooltip.style.top = `${clientY + 14}px`;
    };

    window.__reclutamientoHideLockTooltip = () => {
        tooltip.classList.remove('is-visible');
        window.__reclutamientoLockTooltipRowId = null;
    };

    document.addEventListener('mouseover', (event) => {
        const row = event.target.closest('tr[data-candidato-id]');
        if (!row || row.dataset.locked !== 'true') return;

        tooltip.textContent = `Editando: ${row.dataset.lockedBy || 'otro usuario'}`;
        tooltip.classList.add('is-visible');
        window.__reclutamientoLockTooltipRowId = String(row.dataset.candidatoId);
        positionTooltip(event.clientX, event.clientY);
    });

    document.addEventListener('mousemove', (event) => {
        if (tooltip.classList.contains('is-visible')) positionTooltip(event.clientX, event.clientY);
    });

    document.addEventListener('mouseout', (event) => {
        const row = event.target.closest('tr[data-candidato-id]');
        if (!row) return;

        const related = event.relatedTarget?.closest?.('tr[data-candidato-id]');
        if (related === row) return;

        window.__reclutamientoHideLockTooltip();
    });
};

// Tooltip genérico para cualquier elemento con [data-tooltip]. Se usa `position: fixed`
// anclado al cursor/elemento (como el tooltip de bloqueo de fila) en vez de un `::after`
// con `position: absolute`, porque estos badges viven dentro de tablas con scroll/overflow
// hidden (#reclutamiento-grid-host, .reclutamiento-capacitacion-panel) que recortarían un
// tooltip posicionado de forma absoluta.
const setupDataTooltip = () => {
    if (window.__reclutamientoDataTooltipReady) return;
    window.__reclutamientoDataTooltipReady = true;

    const tooltip = document.createElement('div');
    tooltip.className = 'reclutamiento-lock-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    document.body.appendChild(tooltip);

    const positionNearPoint = (clientX, clientY) => {
        const left = Math.min(clientX + 14, window.innerWidth - tooltip.offsetWidth - 8);
        tooltip.style.left = `${Math.max(8, left)}px`;
        tooltip.style.top = `${clientY + 14}px`;
    };

    const show = (text) => {
        if (!text) return;
        tooltip.textContent = text;
        tooltip.classList.add('is-visible');
    };

    const hide = () => tooltip.classList.remove('is-visible');

    document.addEventListener('mouseover', (event) => {
        const el = event.target.closest('[data-tooltip]');
        if (!el) return;
        show(el.getAttribute('data-tooltip'));
        positionNearPoint(event.clientX, event.clientY);
    });

    document.addEventListener('mousemove', (event) => {
        if (tooltip.classList.contains('is-visible') && event.target.closest('[data-tooltip]')) {
            positionNearPoint(event.clientX, event.clientY);
        }
    });

    document.addEventListener('mouseout', (event) => {
        const el = event.target.closest('[data-tooltip]');
        if (!el) return;
        const related = event.relatedTarget?.closest?.('[data-tooltip]');
        if (related === el) return;
        hide();
    });

    document.addEventListener('focusin', (event) => {
        const el = event.target.closest('[data-tooltip]');
        if (!el) return;
        show(el.getAttribute('data-tooltip'));
        const rect = el.getBoundingClientRect();
        positionNearPoint(rect.left, rect.bottom);
    });

    document.addEventListener('focusout', (event) => {
        if (event.target.closest('[data-tooltip]')) hide();
    });
};

window.addEventListener('fila-guardada', (event) => {
    window.__reclutamientoHandleSavedRow?.(event.detail || {});
});

const getMonthFromDate = (value) => {
    if (!value) return '';
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? '' : MONTHS[date.getMonth()];
};

const normalizeExcelDate = (value) => {
    if (!value) return '';
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return value.toISOString().slice(0, 10);
    }

    if (typeof value === 'number') {
        const parsed = XLSX.SSF.parse_date_code(value);
        if (parsed) {
            return `${parsed.y}-${String(parsed.m).padStart(2, '0')}-${String(parsed.d).padStart(2, '0')}`;
        }
    }

    const text = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;

    const parts = text.split(/[/-]/).map(Number);
    if (parts.length === 3 && parts.every(Number.isFinite)) {
        const [first, second, third] = parts;
        const year = third < 100 ? 2000 + third : third;
        const month = first > 12 ? second : first;
        const day = first > 12 ? first : second;
        return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    return text;
};

const defaultRow = Object.fromEntries(FIELD_KEYS.map((key) => [key, '']));
const normalizeRow = (row = {}) => {
    const normalized = { ...defaultRow, ...row, id: row.id ?? `${Date.now()}-${Math.random()}` };

    TEXT_FIELDS.forEach((key) => {
        normalized[key] = String(normalized[key] ?? '').toUpperCase();
    });

    if (normalized.edad !== '') {
        normalized.edad = Math.min(Number.parseInt(normalized.edad, 10) || 0, 99).toString();
    }

    return normalized;
};

const serializeRows = (rows) => rows.map((row) => normalizeRow(row));

const getRowIdentityKey = (row = {}) => {
    const dni = String(row.dni_ce ?? '').trim().toUpperCase();
    const nombre = `${row.nombres ?? ''} ${row.apellidos ?? ''}`.trim().toUpperCase();
    const telefono = String(row.numero_celular ?? '').replace(/\D+/g, '');

    if (dni) return JSON.stringify(['dni', dni]);
    if (nombre && telefono) return JSON.stringify(['nombre-telefono', nombre, telefono]);
    return null;
};

const syncHiddenInput = (rows, hiddenInput) => {
    hiddenInput.value = JSON.stringify(serializeRows(rows));
};

const mountRowsFromStorage = () => {
    const raw = document.getElementById('reclutamiento-grid-data');
    const fallbackRows = [];

    if (!raw) return fallbackRows;

    try {
        const parsed = JSON.parse(raw.textContent || '[]');
        if (Array.isArray(parsed)) {
            const rows = serializeRows(parsed);
            return rows;
        }
    } catch {
        // no-op
    }

    return fallbackRows;
};

const createCell = (key, value = '') => {
    const cell = document.createElement(SELECT_FIELDS.has(key) ? 'select' : 'input');
    cell.dataset.field = key;
    cell.value = TEXT_FIELDS.has(key) ? String(value).toUpperCase() : value;
    cell.className = 'w-full border-0 bg-transparent px-2 py-2 text-sm text-slate-700 outline-none';

    if (SELECT_FIELDS.has(key)) {
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'Seleccionar';
        cell.appendChild(emptyOption);

        const options = key === 'mes'
            ? MONTHS
            : key === 'campana'
                ? getCampanas()
                : key === 'distrito'
                    ? DISTRICTS
                    : key === 'tipificacion'
                        ? getTipificacionOptions()
                        : key === 'subtipificacion_rechazo'
                            ? getSubtipificacionOptions()
                            : key === 'asistio_entrevista'
                                ? getAsistioOptions()
                                : key === 'asistio_entrevista_reprogramada'
                                    ? getAsistioReprogramadaOptions()
                                    : ['SI', 'NO'];

        const normalizedValue = TEXT_FIELDS.has(key) ? String(value).toUpperCase() : value;
        const availableOptions = options.includes(normalizedValue) || !normalizedValue
            ? options
            : [normalizedValue, ...options];

        availableOptions.forEach((optionValue) => {
            const option = document.createElement('option');
            option.value = optionValue;
            option.textContent = optionValue;
            cell.appendChild(option);
        });

        cell.value = TEXT_FIELDS.has(key) ? String(value).toUpperCase() : value;
    } else {
        cell.type = key === 'edad'
            ? 'number'
            : ['fecha_gestion', 'fecha_entrevista', 'fecha_reprogramada'].includes(key)
                ? 'date'
                : key === 'hora_entrevista' ? 'time' : 'text';

        if (key === 'edad') {
            cell.min = '0';
            cell.max = '99';
            cell.step = '1';
            cell.inputMode = 'numeric';
        }
    }

    return cell;
};

const applyConditionalFieldLocks = (rowElement) => {
    if (!rowElement) return;

    const graduated = rowElement.dataset.graduated === 'true';
    const rowLocked = rowElement.dataset.locked === 'true';

    const fieldAt = (key) => rowElement.querySelector(`[data-field="${key}"]`);

    if (graduated) {
        rowElement.classList.add('reclutamiento-row-graduated');
        rowElement.querySelectorAll('input, select').forEach((field) => { field.disabled = true; });
        const removeButton = rowElement.querySelector('[data-remove-row]');
        if (removeButton) removeButton.disabled = true;
        return;
    }

    rowElement.classList.remove('reclutamiento-row-graduated');

    const nombres = fieldAt('nombres');
    const tieneNombre = Boolean(nombres && nombres.value.trim() !== '');
    const ENTREVISTA_FIELDS = [
        'aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
        'fecha_reprogramada', 'asistio_entrevista_reprogramada',
    ];
    if (!rowLocked) {
        ENTREVISTA_FIELDS.forEach((key) => {
            const field = fieldAt(key);
            if (!field) return;
            field.disabled = !tieneNombre;
            if (!tieneNombre && field.value !== '') field.value = '';
        });
    }

    const tipificacion = fieldAt('tipificacion');
    const subtipificacion = fieldAt('subtipificacion_rechazo');
    if (tipificacion && subtipificacion && !rowLocked) {
        const enabled = SUBTIPIFICACION_ENABLED_WHEN.has(tipificacion.value);
        subtipificacion.disabled = !enabled;
        if (!enabled && subtipificacion.value !== '') {
            subtipificacion.value = '';
        }
    }

    const asistio = fieldAt('asistio_entrevista');
    const fechaReprogramada = fieldAt('fecha_reprogramada');
    const asistioReprogramada = fieldAt('asistio_entrevista_reprogramada');
    if (asistio && !rowLocked) {
        const enabled = asistio.value === 'REPROGRAMADO';

        if (fechaReprogramada) {
            fechaReprogramada.disabled = !enabled;
            if (!enabled && fechaReprogramada.value !== '') fechaReprogramada.value = '';
        }

        if (asistioReprogramada) {
            const asistioEnabled = enabled && Boolean(fechaReprogramada?.value);
            asistioReprogramada.disabled = !asistioEnabled;
            if (!asistioEnabled && asistioReprogramada.value !== '') asistioReprogramada.value = '';
        }
    }
};

const bindInput = (row, key, input, hiddenInput, rows) => {
    const update = () => {
        markRowDirty(row);
        if (isPersistedRow(row)) {
            callLivewire('bloquearFila', row.id);
        }

        if (key === 'edad') {
            const digits = input.value.replace(/\D/g, '').slice(0, 2);
            const age = digits === '' ? '' : Math.min(Number(digits), 99).toString();
            input.value = age;
            row[key] = age;
        } else {
            row[key] = TEXT_FIELDS.has(key) ? input.value.toUpperCase() : input.value;
            if (TEXT_FIELDS.has(key)) input.value = row[key];
        }

        if (key === 'fecha_gestion') {
            const month = getMonthFromDate(input.value);
            row.mes = month;
            const monthField = input.closest('tr')?.querySelector('[data-field="mes"]');
            if (monthField) monthField.value = month;
        }

        if (key === 'tipificacion' || key === 'asistio_entrevista' || key === 'nombres' || key === 'fecha_reprogramada') {
            applyConditionalFieldLocks(input.closest('tr'));
            const tr = input.closest('tr');
            if (key === 'tipificacion') {
                row.subtipificacion_rechazo = tr?.querySelector('[data-field="subtipificacion_rechazo"]')?.value || '';
            }
            if (key === 'asistio_entrevista') {
                row.fecha_reprogramada = tr?.querySelector('[data-field="fecha_reprogramada"]')?.value || '';
                row.asistio_entrevista_reprogramada = tr?.querySelector('[data-field="asistio_entrevista_reprogramada"]')?.value || '';
            }
            if (key === 'nombres') {
                ['aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista', 'fecha_reprogramada', 'asistio_entrevista_reprogramada'].forEach((entrevistaKey) => {
                    row[entrevistaKey] = tr?.querySelector(`[data-field="${entrevistaKey}"]`)?.value || '';
                });
            }
        }

        syncHiddenInput(rows, hiddenInput);
        scheduleRowSave(row);
    };

    input.addEventListener('input', update);
    input.addEventListener('change', update);
};

const getContentColumnWidth = (table, header, columnIndex) => {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    const headerStyle = window.getComputedStyle(header);

    const values = [{
        text: header.textContent.trim(),
        font: headerStyle.font,
    }];
    table.querySelectorAll(`tbody tr td:nth-child(${columnIndex + 1}) input, tbody tr td:nth-child(${columnIndex + 1}) select`).forEach((input) => {
        values.push({
            text: input.value,
            font: window.getComputedStyle(input).font,
        });
    });

    const widestValue = values.reduce((widest, value) => {
        context.font = value.font;
        return Math.max(widest, context.measureText(value.text).width);
    }, 0);

    const controlsPadding = 40;
    const selectArrowSpace = table.querySelector(`tbody tr td:nth-child(${columnIndex + 1}) select`) ? 28 : 0;
    return Math.max(72, Math.ceil(widestValue + controlsPadding + selectArrowSpace));
};

const setupColumnResizing = (table) => {
    if (!table.style.width) {
        table.style.width = 'max-content';
    }

    let colgroup = table.querySelector('colgroup');
    if (!colgroup) {
        colgroup = document.createElement('colgroup');
        table.insertBefore(colgroup, table.firstChild);
    }

    const detailHeaders = [...table.querySelectorAll('thead tr:last-child th')];
    const actionHeader = table.querySelector('thead tr:first-child th[rowspan]');
    const headers = actionHeader ? [...detailHeaders, actionHeader] : detailHeaders;
    headers.forEach((header, index) => {
        let column = colgroup.children[index];
        if (!column) {
            column = document.createElement('col');
            colgroup.appendChild(column);
        }

        const contentWidth = getContentColumnWidth(table, header, index);
        const currentWidth = Number.parseFloat(column.style.width) || 0;
        const defaultWidth = DEFAULT_MINIMUM_COLUMN_WIDTHS[FIELD_KEYS[index]] || 0;
        const minimumWidth = Math.max(contentWidth, defaultWidth);
        const width = Math.max(currentWidth, minimumWidth);
        column.style.width = `${width}px`;
        column.style.minWidth = `${minimumWidth}px`;

        if (header.dataset.resizable === 'true') return;

        header.dataset.resizable = 'true';
        header.classList.add('relative');
        const handle = document.createElement('span');
        handle.className = 'absolute right-0 top-0 h-full w-2 cursor-col-resize touch-none hover:bg-sky-200';
        handle.setAttribute('aria-label', 'Ajustar ancho de columna');
        handle.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            handle.setPointerCapture(event.pointerId);
            const startX = event.clientX;
            const startWidth = column.getBoundingClientRect().width;
            const startTableWidth = table.getBoundingClientRect().width;

            const resize = (moveEvent) => {
                const delta = moveEvent.clientX - startX;
                const nextWidth = Math.max(minimumWidth, startWidth + delta);
                column.style.width = `${nextWidth}px`;
                table.style.width = `${Math.max(table.scrollWidth, startTableWidth + delta)}px`;
            };
            const stop = () => {
                handle.removeEventListener('pointermove', resize);
                handle.removeEventListener('pointerup', stop);
                handle.removeEventListener('pointercancel', stop);
            };

            handle.addEventListener('pointermove', resize);
            handle.addEventListener('pointerup', stop);
            handle.addEventListener('pointercancel', stop);
        });
        header.appendChild(handle);
    });
};

const scrollRowIntoGridView = (rowElement) => {
    const container = rowElement?.closest('.reclutamiento-grid-scroll');
    if (!container) return;

    const rowRect = rowElement.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();
    const overflowBottom = rowRect.bottom - containerRect.bottom;
    const overflowTop = containerRect.top - rowRect.top;

    if (overflowBottom > 0) {
        container.scrollTo({ top: container.scrollTop + overflowBottom + 8, left: 0, behavior: 'smooth' });
    } else if (overflowTop > 0) {
        container.scrollTo({ top: container.scrollTop - overflowTop - 8, left: 0, behavior: 'smooth' });
    } else {
        container.scrollTo({ top: container.scrollTop, left: 0, behavior: 'smooth' });
    }
};

const normalizeForSearch = (value) => String(value ?? '')
    .toUpperCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '');

// ---------------------------------------------------------------------
// Filtros en chips ("Gestión de candidatos")
// ---------------------------------------------------------------------

window.__reclutamientoFiltros = window.__reclutamientoFiltros || [];

const closeAllFilterPopovers = () => {
    document.querySelectorAll('.reclutamiento-filter-popover').forEach((popover) => popover.remove());
    document.querySelectorAll('.reclutamiento-filter-chip-button.is-open').forEach((btn) => btn.classList.remove('is-open'));
};

const setupFilterPopoverDismissal = () => {
    if (window.__reclutamientoFilterDismissalReady) return;
    window.__reclutamientoFilterDismissalReady = true;

    document.addEventListener('click', (event) => {
        if (event.target.closest('.reclutamiento-filter-chip')) return;
        closeAllFilterPopovers();
    });
};

const createFilterIcon = () => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.classList.add('h-3.5', 'w-3.5');
    svg.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6h12M9 12h6M11 18h2" />';
    return svg;
};

const isSelectColumn = (column) => SELECT_FIELDS.has(column) && column !== 'campana' && column !== 'distrito'
    ? true
    : ['campana', 'distrito'].includes(column);

const isDateColumn = (column) => ['fecha_gestion', 'fecha_entrevista', 'fecha_reprogramada'].includes(column);

const getColumnOptionValues = (rows, column) => {
    if (column === 'mes') return MONTHS;
    if (column === 'campana') return getCampanas();
    if (column === 'distrito') return DISTRICTS;
    if (column === 'tipificacion') return getTipificacionOptions();
    if (column === 'subtipificacion_rechazo') return getSubtipificacionOptions();
    if (column === 'asistio_entrevista') return getAsistioOptions();
    if (column === 'asistio_entrevista_reprogramada') return getAsistioReprogramadaOptions();
    if (column === 'aceptacion_entrevista') return ['SI', 'NO'];

    const values = new Set();
    rows.forEach((row) => {
        const value = String(row[column] ?? '').trim();
        if (value) values.add(value.toUpperCase());
    });
    return [...values].sort();
};

const buildSelectFilterBody = (popover, filtro, rows, onChange) => {
    const search = document.createElement('input');
    search.type = 'text';
    search.placeholder = 'Buscar...';
    search.className = 'reclutamiento-filter-popover-search';
    popover.appendChild(search);

    const list = document.createElement('div');
    list.className = 'reclutamiento-filter-popover-list';
    popover.appendChild(list);

    const options = getColumnOptionValues(rows, filtro.column);

    const renderOptions = (term = '') => {
        list.innerHTML = '';
        options
            .filter((option) => option.toUpperCase().includes(term.toUpperCase()))
            .forEach((option) => {
                const label = document.createElement('label');
                label.className = 'reclutamiento-filter-popover-option';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = filtro.values.includes(option);
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        filtro.values.push(option);
                    } else {
                        filtro.values = filtro.values.filter((value) => value !== option);
                    }
                    onChange();
                });
                label.appendChild(checkbox);
                label.append(option);
                list.appendChild(label);
            });
    };

    search.addEventListener('input', () => renderOptions(search.value));
    renderOptions();
};

const buildSingleValueFilterBody = (popover, filtro, onChange) => {
    if (isDateColumn(filtro.column)) {
        const wrapper = document.createElement('div');
        wrapper.className = 'space-y-2';

        const desde = document.createElement('input');
        desde.type = 'date';
        desde.className = 'reclutamiento-filter-popover-search';
        desde.value = filtro.desde || '';
        desde.addEventListener('change', () => { filtro.desde = desde.value; onChange(); });

        const hasta = document.createElement('input');
        hasta.type = 'date';
        hasta.className = 'reclutamiento-filter-popover-search';
        hasta.value = filtro.hasta || '';
        hasta.addEventListener('change', () => { filtro.hasta = hasta.value; onChange(); });

        wrapper.appendChild(desde);
        wrapper.appendChild(hasta);
        popover.appendChild(wrapper);
        return;
    }

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'reclutamiento-filter-popover-search';
    input.value = filtro.texto || '';
    input.placeholder = 'Buscar...';
    input.addEventListener('input', () => { filtro.texto = input.value; onChange(); });
    popover.appendChild(input);
};

const filterMatchesRow = (filtro, row) => {
    const rawValue = row[filtro.column];

    if (isDateColumn(filtro.column)) {
        if (!filtro.desde && !filtro.hasta) return true;
        if (!rawValue) return false;
        if (filtro.desde && rawValue < filtro.desde) return false;
        if (filtro.hasta && rawValue > filtro.hasta) return false;
        return true;
    }

    if (filtro.values) {
        if (!filtro.values.length) return true;
        return filtro.values.includes(String(rawValue ?? '').toUpperCase());
    }

    if (!filtro.texto) return true;
    return normalizeForSearch(rawValue).includes(normalizeForSearch(filtro.texto).trim());
};

const applyRowFilter = (rows, tbody) => {
    const filtros = window.__reclutamientoFiltros;

    rows.forEach((row) => {
        const rowElement = tbody.querySelector(`tr[data-candidato-id="${row.id}"]`);
        if (!rowElement) return;

        const matches = filtros.every((filtro) => filterMatchesRow(filtro, row));
        rowElement.classList.toggle('hidden', !matches);
    });
};

const createFilterChip = (filtro, rows, tbody, container, onRemove, autoOpen = false) => {
    const chip = document.createElement('div');
    chip.className = 'reclutamiento-filter-chip';
    chip.dataset.filterChip = 'true';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'reclutamiento-filter-chip-button';
    chip.appendChild(button);

    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'reclutamiento-filter-chip-remove';
    removeButton.setAttribute('aria-label', 'Quitar filtro');
    removeButton.title = 'Quitar filtro';
    removeButton.innerHTML = `
        <svg aria-hidden="true" class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 6l12 12" /><path d="M18 6L6 18" />
        </svg>
    `;
    removeButton.addEventListener('click', (event) => {
        event.stopPropagation();
        closeAllFilterPopovers();
        onRemove();
    });
    chip.appendChild(removeButton);

    const renderButtonLabel = () => {
        button.innerHTML = '';
        button.appendChild(createFilterIcon());
        const label = document.createElement('span');
        label.textContent = FIELD_LABELS[filtro.column] || filtro.column;
        button.appendChild(label);

        const count = filtro.values ? filtro.values.length : (filtro.texto || filtro.desde || filtro.hasta) ? 1 : 0;
        if (count > 0) {
            const badge = document.createElement('span');
            badge.className = 'reclutamiento-filter-chip-count';
            badge.textContent = filtro.values ? String(count) : '1';
            button.appendChild(badge);
        }
        button.classList.toggle('is-active', count > 0);
    };

    const openPopover = () => {
        closeAllFilterPopovers();
        button.classList.add('is-open');

        const popover = document.createElement('div');
        popover.className = 'reclutamiento-filter-popover';

        const onChange = () => {
            renderButtonLabel();
            applyRowFilter(rows, tbody);
        };

        if (isSelectColumn(filtro.column) || SELECT_FIELDS.has(filtro.column)) {
            filtro.values = filtro.values || [];
            buildSelectFilterBody(popover, filtro, rows, onChange);
        } else {
            buildSingleValueFilterBody(popover, filtro, onChange);
        }

        chip.appendChild(popover);
    };

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        if (button.classList.contains('is-open')) {
            closeAllFilterPopovers();
            return;
        }
        openPopover();
    });

    renderButtonLabel();
    container.appendChild(chip);
    if (autoOpen) openPopover();
    return chip;
};

const createAddFilterChip = (rows, tbody, container, filtros, renderAll) => {
    const chip = document.createElement('div');
    chip.className = 'reclutamiento-filter-chip';
    chip.dataset.filterChip = 'true';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'reclutamiento-filter-chip-button';
    button.innerHTML = '<span>+ Añadir filtro</span>';
    chip.appendChild(button);

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        if (button.classList.contains('is-open')) {
            closeAllFilterPopovers();
            return;
        }

        closeAllFilterPopovers();
        button.classList.add('is-open');

        const popover = document.createElement('div');
        popover.className = 'reclutamiento-filter-popover';

        const search = document.createElement('input');
        search.type = 'text';
        search.placeholder = 'Buscar columna...';
        search.className = 'reclutamiento-filter-popover-search';
        popover.appendChild(search);

        const list = document.createElement('div');
        list.className = 'reclutamiento-filter-popover-list';
        popover.appendChild(list);

        const renderList = (term = '') => {
            list.innerHTML = '';
            FILTERABLE_COLUMNS
                .filter((column) => !filtros.some((filtro) => filtro.column === column))
                .filter((column) => (FIELD_LABELS[column] || column).toUpperCase().includes(term.toUpperCase()))
                .forEach((column) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'reclutamiento-filter-popover-option w-full text-left';
                    option.textContent = FIELD_LABELS[column] || column;
                    option.addEventListener('click', (event) => {
                        event.stopPropagation();
                        filtros.push({ column, values: [], texto: '', desde: '', hasta: '' });
                        closeAllFilterPopovers();
                        renderAll(column);
                    });
                    list.appendChild(option);
                });
        };

        search.addEventListener('input', () => renderList(search.value));
        renderList();
        chip.appendChild(popover);
    });

    container.appendChild(chip);
};

const renderFilterBar = (containerId, rows, tbody, filtros, autoOpenColumn = null) => {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '';

    filtros.forEach((filtro, index) => {
        createFilterChip(filtro, rows, tbody, container, () => {
            filtros.splice(index, 1);
            renderFilterBar(containerId, rows, tbody, filtros);
            applyRowFilter(rows, tbody);
        }, filtro.column === autoOpenColumn);
    });

    createAddFilterChip(rows, tbody, container, filtros, (autoOpenColumn2) => renderFilterBar(containerId, rows, tbody, filtros, autoOpenColumn2));

    if (filtros.length) {
        const clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'text-xs font-medium text-rose-600';
        clearButton.textContent = 'Limpiar filtros';
        clearButton.addEventListener('click', () => {
            filtros.splice(0, filtros.length);
            renderFilterBar(containerId, rows, tbody, filtros);
            applyRowFilter(rows, tbody);
        });
        container.appendChild(clearButton);
    }
};

const initReclutamientoFiltros = (rows, tbody) => {
    setupFilterPopoverDismissal();
    ['reclutamiento-filtros', 'reclutamiento-filtros-ampliada'].forEach((id) => {
        renderFilterBar(id, rows, tbody, window.__reclutamientoFiltros);
    });
};

// ---------------------------------------------------------------------
// Popover de invitación (QR + link)
// ---------------------------------------------------------------------

const positionPopover = (popover, trigger, width) => {
    popover.style.visibility = 'hidden';
    popover.classList.add('is-visible');

    const rect = trigger.getBoundingClientRect();
    const height = popover.offsetHeight;

    let top = rect.bottom + 8;
    if (top + height > window.innerHeight - 8) {
        top = Math.max(8, rect.top - height - 8);
    }

    popover.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
    popover.style.top = `${top}px`;
    popover.style.visibility = '';
};

const fallbackCopyToClipboard = (text) => {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }
    textarea.remove();

    window.__reclutamientoNotify?.(
        copied ? 'Link copiado al portapapeles.' : 'No se pudo copiar automáticamente, selecciona el link y cópialo manualmente.',
        copied ? 'info' : 'warning',
    );
};

const copyTextToClipboard = (text) => {
    if (!text) return;

    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(text)
            .then(() => window.__reclutamientoNotify?.('Link copiado al portapapeles.'))
            .catch(() => fallbackCopyToClipboard(text));
        return;
    }

    fallbackCopyToClipboard(text);
};

const setupInvitacionPopover = () => {
    if (window.__reclutamientoInvitacionPopoverReady) return;
    window.__reclutamientoInvitacionPopoverReady = true;

    const popover = document.getElementById('reclutamiento-invitacion-popover');
    if (!popover) return;

    const closePopover = () => popover.classList.remove('is-visible');

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-invitacion-toggle]');
        if (!trigger) {
            if (!event.target.closest('#reclutamiento-invitacion-popover')) closePopover();
            return;
        }

        const link = trigger.dataset.invitacionLink;
        const estado = trigger.dataset.invitacionEstado || '';
        const qr = trigger.dataset.invitacionQr || '';

        popover.innerHTML = `
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Invitación ${escapeHtml(estado)}</p>
            <div class="mb-3 flex justify-center" data-qr>
                ${qr ? `<img src="${escapeHtml(qr)}" alt="Código QR de la invitación" class="h-40 w-40 rounded-lg border border-slate-200 p-2">` : ''}
            </div>
            <input type="text" readonly value="${escapeHtml(link)}" class="form-input mb-2 text-xs" onclick="this.select()">
            <button type="button" class="btn-secondary w-full" data-copy>Copiar link</button>
        `;

        positionPopover(popover, trigger, 280);

        popover.querySelector('[data-copy]')?.addEventListener('click', () => {
            copyTextToClipboard(link);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePopover();
    });
};

// ---------------------------------------------------------------------
// Popover de observaciones (Obs. 1 / Obs. 2 / reprogramadas) en Proceso de capacitación
// ---------------------------------------------------------------------

const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const setupObsPopover = () => {
    if (window.__reclutamientoObsPopoverReady) return;
    window.__reclutamientoObsPopoverReady = true;

    const popover = document.getElementById('capacitacion-obs-popover');
    if (!popover) return;

    let activeTrigger = null;

    const guardarActivo = () => {
        if (!activeTrigger) return;
        const textarea = popover.querySelector('textarea');
        if (!textarea) return;

        const valor = textarea.value;
        if (valor !== (activeTrigger.dataset.obsValue || '')) {
            activeTrigger.dataset.obsValue = valor;
            activeTrigger.classList.toggle('has-value', valor.trim() !== '');
            callLivewire(
                'guardarObservacionCapacitacion',
                Number(activeTrigger.dataset.obsCandidato),
                activeTrigger.dataset.obsField,
                valor,
            );
        }
    };

    const closePopover = () => {
        guardarActivo();
        popover.classList.remove('is-visible');
        activeTrigger = null;
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-obs-toggle]');
        if (!trigger) {
            if (!event.target.closest('#capacitacion-obs-popover')) closePopover();
            return;
        }

        if (trigger.disabled) return;
        if (activeTrigger === trigger) return;
        if (activeTrigger) guardarActivo();

        activeTrigger = trigger;

        popover.innerHTML = `
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">${escapeHtml(trigger.dataset.obsLabel || 'Observación')}</p>
            <textarea class="reclutamiento-obs-textarea" placeholder="Escribe una observación...">${escapeHtml(trigger.dataset.obsValue || '')}</textarea>
        `;

        positionPopover(popover, trigger, 300);
        popover.querySelector('textarea')?.focus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePopover();
    });
};

const renderTable = (rows, tbody, hiddenInput, table) => {
    tbody.innerHTML = '';

    rows.forEach((row) => {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-slate-200 align-top';
        tr.dataset.candidatoId = row.id;
        tr.dataset.graduated = row.graduado ? 'true' : 'false';

        FIELD_KEYS.forEach((key) => {
            const td = document.createElement('td');
            td.className = 'border-r border-slate-200 align-top';
            const input = createCell(key, row[key] ?? '');
            bindInput(row, key, input, hiddenInput, rows);
            input.addEventListener('change', () => setupColumnResizing(table));
            input.addEventListener('input', () => setupColumnResizing(table));

            td.appendChild(input);
            tr.appendChild(td);
        });

        const removeCell = document.createElement('td');
        removeCell.className = 'px-2 py-2';
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.dataset.removeRow = 'true';
        removeButton.setAttribute('aria-label', 'Eliminar candidato');
        removeButton.title = isPersistedRow(row) ? 'No se pueden eliminar candidatos ya guardados' : 'Eliminar candidato';
        removeButton.disabled = isPersistedRow(row);
        removeButton.className = 'inline-flex h-8 w-8 items-center justify-center rounded bg-rose-50 text-rose-600 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-40';
        removeButton.innerHTML = `
            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18" />
                <path d="M8 6V4h8v2" />
                <path d="M19 6l-1 14H6L5 6" />
                <path d="M10 11v5" />
                <path d="M14 11v5" />
            </svg>
        `;
        removeButton.addEventListener('click', () => {
            if (removeButton.disabled || tr.dataset.locked === 'true') {
                if (isPersistedRow(row)) {
                    window.__reclutamientoNotify?.('No es posible eliminar candidatos ya guardados.', 'warning');
                } else {
                    window.__reclutamientoNotify?.('Esta fila está siendo editada por otro usuario.', 'warning');
                }
                return;
            }

            const index = rows.indexOf(row);
            if (index < 0) return;

            rows.splice(index, 1);
            renderTable(rows, tbody, hiddenInput, table);
            setupColumnResizing(table);
            syncHiddenInput(rows, hiddenInput);
        });
        removeCell.appendChild(removeButton);

        if (row.graduado) {
            const badge = document.createElement('span');
            badge.classList.add('reclutamiento-graduated-badge', 'mt-1', 'block', 'w-fit');

            if (row.no_apto_capacitacion) {
                badge.classList.add('reclutamiento-graduated-badge-rejected');
                badge.textContent = 'NO APTO';
                badge.setAttribute('tabindex', '0');
                badge.setAttribute('data-tooltip', row.no_apto_detalle || 'No apto en capacitación');
            } else {
                badge.textContent = 'En capacitación';
            }

            removeCell.appendChild(badge);
        }

        const lockStatus = document.createElement('span');
        lockStatus.dataset.lockStatus = 'true';
        lockStatus.className = 'reclutamiento-lock-indicator mt-1 hidden';
        lockStatus.textContent = 'En edición';
        lockStatus.setAttribute('role', 'status');
        removeCell.appendChild(lockStatus);
        tr.appendChild(removeCell);
        tbody.appendChild(tr);

        applyRowLockState(row);
        applyConditionalFieldLocks(tr);
    });

    applyRowFilter(rows, tbody);
};

const applyRowLockState = (row) => {
    const currentUser = getCurrentUser();
    const lockIsActive = row.bloqueado_hasta && new Date(row.bloqueado_hasta) > new Date();
    const lockedByOther = lockIsActive && String(row.bloqueado_por_id) !== String(currentUser.id);
    const lockOwnerName = lockedByOther ? row.bloqueado_por_nombre || 'otro usuario' : '';
    setRowLockState(row.id, lockedByOther, lockOwnerName, row.bloqueado_por_id);
};

const setupLockExpiryWatcher = (rows) => {
    if (window.__reclutamientoLockWatcher) window.clearInterval(window.__reclutamientoLockWatcher);

    window.__reclutamientoLockWatcher = window.setInterval(() => {
        rows.forEach((row) => {
            if (isPersistedRow(row)) applyRowLockState(row);
        });
    }, 5000);
};

const importExcel = (rows, tbody, hiddenInput, table, event) => {
    const [file] = event.target.files || [];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (loadEvent) => {
        try {
            const workbook = XLSX.read(loadEvent.target.result, { type: 'array' });
            const sheet = workbook.Sheets[workbook.SheetNames[0]];
            const data = XLSX.utils.sheet_to_json(sheet, { defval: '' });
            const mapped = data
                .filter((row) => Object.values(row).some((value) => String(value ?? '').trim() !== ''))
                .map((row) => normalizeRow({
                    mes: row['MES'] ?? row.mes ?? '',
                    fecha_gestion: normalizeExcelDate(row['FECHA DE GESTIÓN'] ?? row.fecha_gestion ?? ''),
                    agente_reclutador: row['AGENTE RECLUTADOR'] ?? row.agente_reclutador ?? '',
                    campana: row['CAMPAÑA'] ?? row.campana ?? '',
                    dni_ce: row['DNI / C.E'] ?? row.dni_ce ?? '',
                    edad: row['EDAD'] ?? row.edad ?? '',
                    nombres: row['NOMBRES'] ?? row['NOMBRES Y APELLIDOS'] ?? row.nombres ?? '',
                    apellidos: row['APELLIDOS'] ?? row.apellidos ?? '',
                    numero_celular: row['NÚMERO DE CELULAR'] ?? row.numero_celular ?? '',
                    distrito: row['DISTRITO'] ?? row.distrito ?? '',
                    observaciones: row['OBSERVACIONES'] ?? row.observaciones ?? '',
                    tipificacion: row['TIPIFICACIÓN'] ?? row.tipificacion ?? '',
                    subtipificacion_rechazo: row['SUBTIFIPICACIÓN POR RECHAZO'] ?? row['SUBTIPIFICACIÓN POR RECHAZO'] ?? row.subtipificacion_rechazo ?? '',
                    aceptacion_entrevista: row['ACEPTACIÓN PARA ENTREVISTA'] ?? row.aceptacion_entrevista ?? '',
                    fecha_entrevista: normalizeExcelDate(row['FECHA DE ENTREVISTA'] ?? row.fecha_entrevista ?? ''),
                    hora_entrevista: row['HORA DE ENTREVISTA'] ?? row.hora_entrevista ?? '',
                    asistio_entrevista: row['ASISTIÓ A ENTREVISTA'] ?? row.asistio_entrevista ?? '',
                }))
                .map((row) => ({
                    ...row,
                    mes: getMonthFromDate(row.fecha_gestion) || row.mes,
                    edad: row.edad === '' ? '' : Math.min(Number.parseInt(row.edad, 10) || 0, 99).toString(),
                    ...Object.fromEntries([...TEXT_FIELDS].map((key) => [key, String(row[key] ?? '').toUpperCase()])),
                }));

            const finalRows = mapped.length ? mapped : [normalizeRow()];
            renderTable(finalRows, tbody, hiddenInput, table);
            setupColumnResizing(table);
            syncHiddenInput(finalRows, hiddenInput);
            window.__reclutamientoDirty = true;
        } catch {
            // ignora archivos no válidos
        }

        event.target.value = '';
    };

    reader.readAsArrayBuffer(file);
};

const setupExpandedTable = () => {
    const expandButton = document.getElementById('ampliar-tabla');
    const closeButton = document.getElementById('cerrar-tabla-ampliada');
    const modal = document.getElementById('reclutamiento-grid-modal');
    const modalBody = document.getElementById('reclutamiento-grid-modal-body');
    const gridHost = document.getElementById('reclutamiento-grid-host');
    const gridContainer = gridHost?.firstElementChild;
    const decreaseButton = document.getElementById('reducir-tabla-ampliada');
    const resetButton = document.getElementById('restablecer-tabla-ampliada');
    const increaseButton = document.getElementById('ampliar-tabla-ampliada');

    if (!expandButton || !closeButton || !modal || !modalBody || !gridHost || !gridContainer || !decreaseButton || !resetButton || !increaseButton) return;
    if (expandButton.dataset.bound === 'true') return;

    const openModal = () => {
        modalBody.appendChild(gridContainer);
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        gridContainer.querySelector('input, select')?.focus();
    };

    const closeModal = () => {
        updateZoom(1);
        gridHost.appendChild(gridContainer);
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    };

    let zoom = 1;
    const updateZoom = (nextZoom) => {
        zoom = Math.min(1.5, Math.max(0.7, nextZoom));
        const percentage = `${Math.round(zoom * 100)}%`;
        gridContainer.style.zoom = zoom;
        resetButton.textContent = percentage;
        decreaseButton.disabled = zoom <= 0.7;
        increaseButton.disabled = zoom >= 1.5;
    };

    expandButton.dataset.bound = 'true';
    decreaseButton.addEventListener('click', () => updateZoom(zoom - 0.1));
    increaseButton.addEventListener('click', () => updateZoom(zoom + 0.1));
    resetButton.addEventListener('click', () => updateZoom(1));
    expandButton.addEventListener('click', openModal);
    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });
};

const initializeGrid = () => {
    const table = document.getElementById('reclutamiento-grid');
    const tbody = table?.querySelector('tbody');
    const hiddenInput = document.getElementById('rowsJson');
    const importar = document.getElementById('importar-candidatos');
    const addButton = document.getElementById('agregar-candidato');
    const expandedAddButton = document.getElementById('agregar-candidato-ampliado');

    setupInvitacionPopover();
    setupObsPopover();

    if (!table || !tbody || !hiddenInput) {
        return;
    }

    const rows = mountRowsFromStorage();

    window.__reclutamientoDirty = false;
    renderTable(rows, tbody, hiddenInput, table);
    setupLockNotifications();
    setupLockHoverTooltip();
    setupDataTooltip();
    setupLockExpiryWatcher(rows);
    setupColumnResizing(table);
    setupExpandedTable();
    syncHiddenInput(rows, hiddenInput);
    initReclutamientoFiltros(rows, tbody);

    const addRow = () => {
        const newRow = normalizeRow();
        markRowDirty(newRow);
        rows.push(newRow);
        renderTable(rows, tbody, hiddenInput, table);
        setupColumnResizing(table);
        syncHiddenInput(rows, hiddenInput);
        scheduleRowSave(newRow);

        const newRowElement = tbody.querySelector(`tr[data-candidato-id="${newRow.id}"]`);
        scrollRowIntoGridView(newRowElement);
    };

    addButton?.addEventListener('click', addRow);
    expandedAddButton?.addEventListener('click', addRow);

    importar?.addEventListener('change', (event) => importExcel(rows, tbody, hiddenInput, table, event));

    window.addEventListener('reclutamiento-guardado', () => {
        window.__reclutamientoDirty = false;
        if (window.__reclutamientoDirtyRows) {
            window.__reclutamientoDirtyRows.clear();
        }
    }, { once: true });

    window.__reclutamientoApplyRows = (remoteRows) => {
        if (!Array.isArray(remoteRows)) return;

        const normalizedRemote = remoteRows.map((row) => normalizeRow(row));
        const persistedRemote = normalizedRemote.filter((row) => isPersistedRow(row));
        const tempLocalRows = rows.filter((row) => !isPersistedRow(row));
        const mergedRows = [...persistedRemote];

        rows.forEach((localRow) => {
            const persistedLocal = isPersistedRow(localRow);
            if (!persistedLocal) return;

            const sameRemoteIndex = mergedRows.findIndex((remoteRow) => String(remoteRow.id) === String(localRow.id));
            if (sameRemoteIndex >= 0) {
                const sameRemoteRow = mergedRows[sameRemoteIndex];
                const dirtyIds = window.__reclutamientoDirtyRows || new Set();
                if (dirtyIds.has(String(localRow.id))) {
                    mergedRows[sameRemoteIndex] = { ...sameRemoteRow, ...localRow };
                }
                return;
            }

            const dirtyIds = window.__reclutamientoDirtyRows || new Set();
            if (dirtyIds.has(String(localRow.id))) {
                mergedRows.push(normalizeRow(localRow));
            }
        });

        const tempRowsToKeep = tempLocalRows.filter((localRow) => {
            const identity = getRowIdentityKey(localRow);
            if (!identity) return true;
            return !persistedRemote.some((remoteRow) => getRowIdentityKey(remoteRow) === identity);
        });

        rows.splice(0, rows.length, ...mergedRows, ...tempRowsToKeep);
        renderTable(rows, tbody, hiddenInput, table);
        setupColumnResizing(table);
        syncHiddenInput(rows, hiddenInput);
        window.__reclutamientoDirty = window.__reclutamientoDirtyRows?.size > 0;
    };

    window.__reclutamientoHandleSavedRow = ({ tempId, id }) => {
        const row = rows.find((candidate) => String(candidate.id) === String(tempId));
        if (!row || !id) return;

        const previousId = String(row.id);
        row.id = id;
        clearRowDirty(previousId);
        setRowLockState(id, false);
        syncHiddenInput(rows, hiddenInput);

        const rowElement = tbody.querySelector(`tr[data-candidato-id="${tempId}"]`);
        if (rowElement) rowElement.dataset.candidatoId = id;
    };
};

// ---------------------------------------------------------------------
// Filtros en chips ("Proceso de capacitación")
// ---------------------------------------------------------------------

const CAPACITACION_FILTER_DEFS = [
    { column: 'nombre', label: 'Nombre', type: 'text' },
    { column: 'fecha_capacitacion', label: 'Fecha capacitación', type: 'range' },
    { column: 'fecha_entrevista', label: 'Entrevista SI, APTO', type: 'range' },
    { column: 'apto', label: 'Apto', type: 'radio', options: ['SI', 'NO'] },
];

const CAPACITACION_FILTER_DEFS_BY_COLUMN = Object.fromEntries(
    CAPACITACION_FILTER_DEFS.map((def) => [def.column, def]),
);

const buildCapacitacionFiltrosPayload = (filtrosActivos) => {
    const find = (column) => filtrosActivos.find((filtro) => filtro.column === column) || {};

    return {
        nombre: find('nombre').texto || '',
        fecha_capacitacion_desde: find('fecha_capacitacion').desde || null,
        fecha_capacitacion_hasta: find('fecha_capacitacion').hasta || null,
        fecha_entrevista_desde: find('fecha_entrevista').desde || null,
        fecha_entrevista_hasta: find('fecha_entrevista').hasta || null,
        apto: find('apto').valor || null,
    };
};

const createCapacitacionFilterChip = (def, filtro, container, onChange, onRemove, autoOpen = false) => {
    const chip = document.createElement('div');
    chip.className = 'reclutamiento-filter-chip';
    chip.dataset.filterChip = 'true';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'reclutamiento-filter-chip-button';
    chip.appendChild(button);

    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'reclutamiento-filter-chip-remove';
    removeButton.setAttribute('aria-label', 'Quitar filtro');
    removeButton.title = 'Quitar filtro';
    removeButton.innerHTML = `
        <svg aria-hidden="true" class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 6l12 12" /><path d="M18 6L6 18" />
        </svg>
    `;
    removeButton.addEventListener('click', (event) => {
        event.stopPropagation();
        closeAllFilterPopovers();
        onRemove();
    });
    chip.appendChild(removeButton);

    const renderLabel = () => {
        button.innerHTML = '';
        button.appendChild(createFilterIcon());
        const label = document.createElement('span');
        label.textContent = def.label;
        button.appendChild(label);
        const active = Boolean(filtro.texto || filtro.desde || filtro.hasta || filtro.valor);
        button.classList.toggle('is-active', active);
    };

    const openPopover = () => {
        closeAllFilterPopovers();
        button.classList.add('is-open');

        const popover = document.createElement('div');
        popover.className = 'reclutamiento-filter-popover';

        if (def.type === 'text') {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'reclutamiento-filter-popover-search';
            input.value = filtro.texto || '';
            input.addEventListener('input', () => { filtro.texto = input.value; renderLabel(); onChange(); });
            popover.appendChild(input);
        } else if (def.type === 'range') {
            const desde = document.createElement('input');
            desde.type = 'date';
            desde.className = 'reclutamiento-filter-popover-search';
            desde.value = filtro.desde || '';
            desde.addEventListener('change', () => { filtro.desde = desde.value; renderLabel(); onChange(); });

            const hasta = document.createElement('input');
            hasta.type = 'date';
            hasta.className = 'reclutamiento-filter-popover-search';
            hasta.value = filtro.hasta || '';
            hasta.addEventListener('change', () => { filtro.hasta = hasta.value; renderLabel(); onChange(); });

            popover.appendChild(desde);
            popover.appendChild(hasta);
        } else if (def.type === 'radio') {
            ['Todos', ...def.options].forEach((option) => {
                const optionLabel = document.createElement('label');
                optionLabel.className = 'reclutamiento-filter-popover-option';
                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = `capacitacion-filtro-${def.column}`;
                radio.checked = (option === 'Todos' && !filtro.valor) || filtro.valor === option;
                radio.addEventListener('change', () => {
                    filtro.valor = option === 'Todos' ? null : option;
                    renderLabel();
                    onChange();
                });
                optionLabel.appendChild(radio);
                optionLabel.append(option);
                popover.appendChild(optionLabel);
            });
        }

        chip.appendChild(popover);
    };

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        if (button.classList.contains('is-open')) {
            closeAllFilterPopovers();
            return;
        }
        openPopover();
    });

    renderLabel();
    container.appendChild(chip);
    if (autoOpen) openPopover();
};

const createCapacitacionAddFilterChip = (container, filtrosActivos, renderAll) => {
    const chip = document.createElement('div');
    chip.className = 'reclutamiento-filter-chip';
    chip.dataset.filterChip = 'true';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'reclutamiento-filter-chip-button';
    button.innerHTML = '<span>+ Añadir filtro</span>';
    chip.appendChild(button);

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        if (button.classList.contains('is-open')) {
            closeAllFilterPopovers();
            return;
        }

        closeAllFilterPopovers();
        button.classList.add('is-open');

        const popover = document.createElement('div');
        popover.className = 'reclutamiento-filter-popover';

        const search = document.createElement('input');
        search.type = 'text';
        search.placeholder = 'Buscar columna...';
        search.className = 'reclutamiento-filter-popover-search';
        popover.appendChild(search);

        const list = document.createElement('div');
        list.className = 'reclutamiento-filter-popover-list';
        popover.appendChild(list);

        const renderList = (term = '') => {
            list.innerHTML = '';
            CAPACITACION_FILTER_DEFS
                .filter((def) => !filtrosActivos.some((filtro) => filtro.column === def.column))
                .filter((def) => def.label.toUpperCase().includes(term.toUpperCase()))
                .forEach((def) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'reclutamiento-filter-popover-option w-full text-left';
                    option.textContent = def.label;
                    option.addEventListener('click', (event) => {
                        event.stopPropagation();
                        filtrosActivos.push({ column: def.column });
                        closeAllFilterPopovers();
                        renderAll(def.column);
                    });
                    list.appendChild(option);
                });
        };

        search.addEventListener('input', () => renderList(search.value));
        renderList();
        chip.appendChild(popover);
    });

    container.appendChild(chip);
};

const renderCapacitacionFilterBar = (filtrosActivos, autoOpenColumn = null) => {
    const container = document.getElementById('capacitacion-filtros');
    if (!container) return;

    container.innerHTML = '';

    filtrosActivos.forEach((filtro, index) => {
        const def = CAPACITACION_FILTER_DEFS_BY_COLUMN[filtro.column];
        if (!def) return;

        createCapacitacionFilterChip(def, filtro, container, () => {
            callLivewire('actualizarFiltrosCapacitacion', buildCapacitacionFiltrosPayload(filtrosActivos));
        }, () => {
            filtrosActivos.splice(index, 1);
            renderCapacitacionFilterBar(filtrosActivos);
            callLivewire('actualizarFiltrosCapacitacion', buildCapacitacionFiltrosPayload(filtrosActivos));
        }, filtro.column === autoOpenColumn);
    });

    createCapacitacionAddFilterChip(container, filtrosActivos, (autoOpenColumn2) => renderCapacitacionFilterBar(filtrosActivos, autoOpenColumn2));

    if (filtrosActivos.length) {
        const clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'text-xs font-medium text-rose-600';
        clearButton.textContent = 'Limpiar filtros';
        clearButton.addEventListener('click', () => {
            filtrosActivos.splice(0, filtrosActivos.length);
            renderCapacitacionFilterBar(filtrosActivos);
            callLivewire('limpiarFiltrosCapacitacion');
        });
        container.appendChild(clearButton);
    }
};

const initCapacitacionFiltros = () => {
    const container = document.getElementById('capacitacion-filtros');
    if (!container) return;
    if (container.dataset.bound === 'true') return;
    container.dataset.bound = 'true';

    setupFilterPopoverDismissal();

    const estado = getJsonScript('capacitacion-filtros-estado', {});
    const filtrosActivos = [];

    if (estado.nombre) filtrosActivos.push({ column: 'nombre', texto: estado.nombre });
    if (estado.fecha_capacitacion_desde || estado.fecha_capacitacion_hasta) {
        filtrosActivos.push({
            column: 'fecha_capacitacion',
            desde: estado.fecha_capacitacion_desde || '',
            hasta: estado.fecha_capacitacion_hasta || '',
        });
    }
    if (estado.fecha_entrevista_desde || estado.fecha_entrevista_hasta) {
        filtrosActivos.push({
            column: 'fecha_entrevista',
            desde: estado.fecha_entrevista_desde || '',
            hasta: estado.fecha_entrevista_hasta || '',
        });
    }
    if (estado.apto) filtrosActivos.push({ column: 'apto', valor: estado.apto });

    renderCapacitacionFilterBar(filtrosActivos);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeGrid, { once: true });
} else {
    initializeGrid();
}

document.addEventListener('livewire:navigated', initializeGrid);
document.addEventListener('livewire:navigated', initCapacitacionFiltros);

// El contenedor de filtros de "Proceso de capacitación" solo existe en el DOM
// cuando esa pestaña está activa (se agrega/quita vía Livewire al cambiar de
// pestaña); un MutationObserver evita depender del nombre exacto del hook de
// ciclo de vida de Livewire para saber cuándo ya está disponible.
const capacitacionFiltrosObserver = new MutationObserver(() => {
    if (document.getElementById('capacitacion-filtros')) initCapacitacionFiltros();
});
capacitacionFiltrosObserver.observe(document.body, { childList: true, subtree: true });
initCapacitacionFiltros();
