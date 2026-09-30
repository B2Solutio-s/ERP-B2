import * as XLSX from 'xlsx';

const FIELD_KEYS = [
    'mes', 'fecha_gestion', 'agente_reclutador', 'campana', 'dni_ce', 'edad', 'nombres',
    'numero_celular', 'distrito', 'observaciones', 'tipificacion', 'subtipificacion_rechazo',
    'aceptacion_entrevista', 'fecha_entrevista', 'hora_entrevista', 'asistio_entrevista',
    'fecha_capacitacion', 'entrego_documentos',
];

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
    'agente_reclutador', 'campana', 'dni_ce', 'nombres', 'numero_celular', 'distrito',
    'observaciones', 'tipificacion', 'subtipificacion_rechazo', 'entrego_documentos',
]);

const DEFAULT_MINIMUM_COLUMN_WIDTHS = {
    mes: 140,
};

const getCampanas = () => {
    const element = document.getElementById('reclutamiento-campanas');
    if (!element) return [];

    try {
        const values = JSON.parse(element.textContent || '[]');
        return Array.isArray(values) ? values.filter(Boolean).map((value) => String(value).toUpperCase()) : [];
    } catch {
        return [];
    }
};

const getCurrentUser = () => {
    const element = document.getElementById('reclutamiento-usuario');
    if (!element) return { id: null, nombre: '' };

    try {
        return JSON.parse(element.textContent || '{}');
    } catch {
        return { id: null, nombre: '' };
    }
};

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
        removeButton.disabled = shouldLock;
        removeButton.title = shouldLock
            ? `No disponible: ${userName || 'otro usuario'} está editando esta fila`
            : 'Eliminar candidato';
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
    const nombre = String(row.nombres ?? '').trim().toUpperCase();
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
            const hasPersistedRows = rows.some((row) => isPersistedRow(row));

            if (hasPersistedRows) {
                return rows;
            }

            if (rows.length) {
                return rows;
            }

            return fallbackRows;
        }
    } catch {
        // no-op
    }

    return fallbackRows;
};

const createCell = (key, value = '') => {
    const selectFields = new Set(['mes', 'campana', 'distrito', 'aceptacion_entrevista', 'asistio_entrevista']);
    const cell = document.createElement(selectFields.has(key) ? 'select' : 'input');
    cell.value = TEXT_FIELDS.has(key) ? String(value).toUpperCase() : value;
    cell.className = 'w-full border-0 bg-transparent px-2 py-2 text-sm text-slate-700 outline-none';

    if (selectFields.has(key)) {
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
            : ['fecha_gestion', 'fecha_entrevista', 'fecha_capacitacion'].includes(key)
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
            const monthField = input.closest('tr')?.children[FIELD_KEYS.indexOf('mes')]?.querySelector('select');
            if (monthField) monthField.value = month;
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

const applyRowFilter = (rows, tbody) => {
    const column = document.getElementById('reclutamiento-filtro-columna')?.value || '';
    const term = normalizeForSearch(document.getElementById('reclutamiento-filtro-texto')?.value || '').trim();

    rows.forEach((row) => {
        const rowElement = tbody.querySelector(`tr[data-candidato-id="${row.id}"]`);
        if (!rowElement) return;

        const matches = !term || !column || normalizeForSearch(row[column]).includes(term);
        rowElement.classList.toggle('hidden', !matches);
    });
};

const renderTable = (rows, tbody, hiddenInput, table) => {
    tbody.innerHTML = '';

    rows.forEach((row) => {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-slate-200 align-top';
        tr.dataset.candidatoId = row.id;

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
        removeButton.title = 'Eliminar candidato';
        removeButton.className = 'inline-flex h-8 w-8 items-center justify-center rounded bg-rose-50 text-rose-600 transition hover:bg-rose-100';
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
                window.__reclutamientoNotify?.('Esta fila está siendo editada por otro usuario.', 'warning');
                return;
            }

            const index = rows.indexOf(row);
            if (index < 0) return;

            const persisted = isPersistedRow(row);
            if (persisted) {
                const timer = window.__reclutamientoSaveTimers?.get(String(row.id));
                if (timer) window.clearTimeout(timer);
                window.__reclutamientoSaveTimers?.delete(String(row.id));
                callLivewire('eliminarFila', Number(row.id));
            }

            rows.splice(index, 1);
            renderTable(rows, tbody, hiddenInput, table);
            setupColumnResizing(table);
            syncHiddenInput(rows, hiddenInput);
        });
        removeCell.appendChild(removeButton);
        const lockStatus = document.createElement('span');
        lockStatus.dataset.lockStatus = 'true';
        lockStatus.className = 'reclutamiento-lock-indicator mt-1 hidden';
        lockStatus.textContent = 'En edición';
        lockStatus.setAttribute('role', 'status');
        removeCell.appendChild(lockStatus);
        tr.appendChild(removeCell);
        tbody.appendChild(tr);

        applyRowLockState(row);
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
                    nombres: row['NOMBRES Y APELLIDOS'] ?? row.nombres ?? '',
                    numero_celular: row['NÚMERO DE CELULAR'] ?? row.numero_celular ?? '',
                    distrito: row['DISTRITO'] ?? row.distrito ?? '',
                    observaciones: row['OBSERVACIONES'] ?? row.observaciones ?? '',
                    tipificacion: row['TIPIFICACIÓN'] ?? row.tipificacion ?? '',
                    subtipificacion_rechazo: row['SUBTIFIPICACIÓN POR RECHAZO'] ?? row['SUBTIPIFICACIÓN POR RECHAZO'] ?? row.subtipificacion_rechazo ?? '',
                    aceptacion_entrevista: row['ACEPTACIÓN PARA ENTREVISTA'] ?? row.aceptacion_entrevista ?? '',
                    fecha_entrevista: normalizeExcelDate(row['FECHA DE ENTREVISTA'] ?? row.fecha_entrevista ?? ''),
                    hora_entrevista: row['HORA DE ENTREVISTA'] ?? row.hora_entrevista ?? '',
                    asistio_entrevista: row['ASISTIÓ A ENTREVISTA'] ?? row.asistio_entrevista ?? '',
                    fecha_capacitacion: normalizeExcelDate(row['FECHA DE CAPACITACIÓN'] ?? row.fecha_capacitacion ?? ''),
                    entrego_documentos: row['ENTREGO DOCUMENTOS'] ?? row.entrego_documentos ?? '',
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

    if (!table || !tbody || !hiddenInput) {
        return;
    }

    const rows = mountRowsFromStorage();

    window.__reclutamientoDirty = false;
    renderTable(rows, tbody, hiddenInput, table);
    setupLockNotifications();
    setupLockHoverTooltip();
    setupLockExpiryWatcher(rows);
    setupColumnResizing(table);
    setupExpandedTable();
    syncHiddenInput(rows, hiddenInput);

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

    const filtroColumnaInputs = [
        document.getElementById('reclutamiento-filtro-columna'),
        document.getElementById('reclutamiento-filtro-columna-ampliada'),
    ].filter(Boolean);
    const filtroTextoInputs = [
        document.getElementById('reclutamiento-filtro-texto'),
        document.getElementById('reclutamiento-filtro-texto-ampliada'),
    ].filter(Boolean);

    const handleColumnFilterChange = (event) => {
        filtroColumnaInputs.forEach((input) => {
            if (input !== event.target) input.value = event.target.value;
        });
        applyRowFilter(rows, tbody);
    };

    const handleTextFilterChange = (event) => {
        filtroTextoInputs.forEach((input) => {
            if (input !== event.target) input.value = event.target.value;
        });
        applyRowFilter(rows, tbody);
    };

    filtroColumnaInputs.forEach((input) => input.addEventListener('change', handleColumnFilterChange));
    filtroTextoInputs.forEach((input) => input.addEventListener('input', handleTextFilterChange));

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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeGrid, { once: true });
} else {
    initializeGrid();
}

document.addEventListener('livewire:navigated', initializeGrid);
