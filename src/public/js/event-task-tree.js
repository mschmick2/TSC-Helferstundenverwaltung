// event-task-tree.js — Aufgabenbaum-Editor (Modul 6 I7b1,
// kontext-aware seit I7c Phase 2)
//
// Erwartet im DOM:
//   <section class="task-tree-editor" id="task-tree-editor"
//            data-context="event|template"  (Default: event)
//            data-entity-id="..."           (event_id oder template_id)
//            data-event-id="..."            (Rueckwaerts-Fallback)
//            data-csrf-token="..."
//            data-endpoint-tree="..."
//            data-endpoint-create="..."
//            data-endpoint-reorder="..."
//            data-categories="[{id,name,is_contribution},...]">
//   ... <ul class="task-tree-root"> / <ul class="task-tree-children">
//   ... <li class="task-node" data-task-id data-is-group data-endpoint-*> ...
//   ... <div id="task-edit-modal"> (Partial _task_edit_modal.php)
//
// Erwartet global: SortableJS, bootstrap (Bootstrap 5 Modal/Toast).
//
// Strategie:
//   - Drag & Drop laesst SortableJS das UI sofort aktualisieren (optimistic).
//     Bei Server-Fehler: Revert ueber evt-Context.
//   - Alle anderen Mutationen (Create/Update/Convert/Delete): auf Erfolg
//     ein location.reload(), weil die Aggregator-Felder (helpers_subtree etc.)
//     sonst inkonsistent bleiben.
//   - XSS-Schutz: User-Freitext (title, description, ancestor_path) immer
//     per textContent oder escapeHtml() — nie innerHTML mit Template-String.
//
// I7c-Kontext-Awareness:
//   - URLs kommen komplett aus data-endpoint-*-Attributen (partial-gerendert),
//     das JS kennt keine URL-Pfade fuer 'event' vs. 'template'.
//   - Zwei Punkte sind kontext-abhaengig:
//     (a) Zeit-Felder im Modal-Formular (datetime-local vs. number-Offset).
//     (b) parent-ID-Feldname (parent_task_id vs. parent_template_task_id).
//   - Der context-String wird in einer geschlossenen Variable gehalten und
//     an buildForm / showCreateModal / collectFormData durchgereicht.

(function () {
    'use strict';

    let treeRoot = null;
    let csrfToken = '';
    let context = 'event';
    let entityId = 0;
    let categories = [];

    // =====================================================================
    // Init
    // =====================================================================

    function initTaskTree() {
        treeRoot = document.getElementById('task-tree-editor');
        if (!treeRoot) {
            return;
        }
        if (typeof Sortable === 'undefined') {
            console.error('SortableJS nicht geladen — Aufgabenbaum-Editor inaktiv.');
            return;
        }

        csrfToken = treeRoot.dataset.csrfToken || '';
        context = treeRoot.dataset.context === 'template' ? 'template' : 'event';
        entityId = parseInt(treeRoot.dataset.entityId || treeRoot.dataset.eventId, 10) || 0;
        categories = parseCategories(treeRoot.dataset.categories);

        initSortables();
        treeRoot.addEventListener('click', handleClick);
    }

    // Feldnamen je Kontext. parentIdField ist der Form-Key fuer die
    // Parent-ID beim Create; eventIdField-Relikt entfaellt im Template-
    // Kontext, weil der Controller den Context aus der Route kennt.
    function parentIdField() {
        return context === 'template' ? 'parent_template_task_id' : 'parent_task_id';
    }

    function parseCategories(raw) {
        if (!raw) return [];
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            console.warn('Kategorien-Parse-Fehler:', e);
            return [];
        }
    }

    function initSortables() {
        const lists = document.querySelectorAll('.task-tree-root, .task-tree-children');
        lists.forEach(ul => {
            Sortable.create(ul, {
                group: 'event-tasks',
                handle: '[data-sortable-handle]',
                animation: 150,
                delay: 200,                    // Mobile: Long-Press, kein Scroll-Konflikt
                delayOnTouchOnly: true,
                touchStartThreshold: 5,
                ghostClass: 'task-node--ghost',
                chosenClass: 'task-node--chosen',
                dragClass: 'task-node--drag',
                // fallbackOnBody und emptyInsertThreshold sind fuer nested
                // Sortable-ULs noetig: erstere bindet den Ghost an document.body
                // (sonst kommt der Drag nicht aus der Quell-Ebene raus), letztere
                // macht leere Ziel-Gruppen (ohne Kinder, daher ohne Hoehe) ueber-
                // haupt erst zum gueltigen Drop-Target.
                fallbackOnBody: true,
                emptyInsertThreshold: 12,
                onEnd: handleSortEnd,
            });
        });
    }

    // =====================================================================
    // Drag & Drop
    // =====================================================================

    async function handleSortEnd(evt) {
        const item = evt.item;
        const targetUl = evt.to;
        const sourceUl = evt.from;
        const taskId = parseInt(item.dataset.taskId, 10);
        if (!taskId) return;

        const newParentRaw = targetUl.dataset.parentTaskId;
        const newParentId = (newParentRaw === '' || newParentRaw === undefined)
            ? null
            : parseInt(newParentRaw, 10);
        const newSortOrder = Array.from(targetUl.children).indexOf(item);

        const revert = () => {
            // SortableJS hat den Knoten bereits verschoben — manuell zurueck.
            const beforeNode = sourceUl.children[evt.oldIndex] || null;
            sourceUl.insertBefore(item, beforeNode);
        };

        if (sourceUl !== targetUl) {
            const endpoint = item.dataset.endpointMove;
            const result = await postJson(endpoint, {
                new_parent_id: newParentId,
                new_sort_order: newSortOrder,
            });
            if (!result.ok) {
                revert();
                showErrorToast(result.message || 'Verschieben fehlgeschlagen.');
            } else {
                showSuccessToast('Verschoben.');
            }
        } else {
            const orderedIds = Array.from(targetUl.children)
                .map(child => parseInt(child.dataset.taskId, 10))
                .filter(id => Number.isFinite(id) && id > 0);
            const endpoint = targetUl.dataset.endpointReorder
                || treeRoot.dataset.endpointReorder;
            const result = await postJson(endpoint, {
                parent_id: newParentId,
                ordered_task_ids: orderedIds,
            });
            if (!result.ok) {
                revert();
                showErrorToast(result.message || 'Reihenfolge nicht gespeichert.');
            } else {
                showSuccessToast('Reihenfolge gespeichert.');
            }
        }
    }

    // =====================================================================
    // Click-Dispatcher (Event-Delegation)
    // =====================================================================

    function handleClick(evt) {
        const target = evt.target;

        const editTrigger = target.closest('[data-action="edit"]');
        if (editTrigger && !editTrigger.disabled) {
            evt.preventDefault();
            const endpoint = editTrigger.dataset.endpointEdit
                || editTrigger.closest('.task-node')?.dataset.endpointEdit;
            if (endpoint) {
                openEditModal(endpoint);
            }
            return;
        }

        const convertBtn = target.closest('[data-action="convert"]');
        if (convertBtn && !convertBtn.disabled) {
            evt.preventDefault();
            handleConvert(convertBtn);
            return;
        }

        const deleteBtn = target.closest('[data-action="delete"]');
        if (deleteBtn && !deleteBtn.disabled) {
            evt.preventDefault();
            handleDelete(deleteBtn);
            return;
        }

        const addChildBtn = target.closest('[data-action="add-child"]');
        if (addChildBtn && !addChildBtn.disabled) {
            evt.preventDefault();
            openCreateModal(addChildBtn.dataset.parentTaskId || '');
            return;
        }
    }

    // =====================================================================
    // Edit-Modal (fetch + Render)
    // =====================================================================

    async function openEditModal(endpointEdit) {
        const modalEl = document.getElementById('task-edit-modal');
        if (!modalEl) return;

        showSpinner();
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        const result = await fetchJson(endpointEdit);
        if (!result.ok) {
            showErrorToast(result.message || 'Daten konnten nicht geladen werden.');
            modal.hide();
            return;
        }
        renderEditModalBody(result.data, {mode: 'edit'});
    }

    function openCreateModal(parentTaskId) {
        const modalEl = document.getElementById('task-edit-modal');
        if (!modalEl) return;

        const data = {
            task: {
                id: null,
                // Bezugs-ID (event_id oder template_id) ist nur fuer die
                // Breadcrumb-Anzeige relevant — die Route kennt den Kontext
                // bereits. Wir belassen das redundante Feld aus Kompatibilitaet.
                event_id: context === 'event' ? entityId : undefined,
                template_id: context === 'template' ? entityId : undefined,
                [parentIdField()]: parentTaskId === '' ? null : parseInt(parentTaskId, 10),
                is_group: 0,
                category_id: null,
                title: '',
                description: '',
                task_type: 'aufgabe',
                slot_mode: 'fix',
                // Zeit-Defaults kontext-abhaengig: event -> start_at/end_at,
                // template -> default_offset_minutes_start/end.
                start_at: context === 'event' ? null : undefined,
                end_at:   context === 'event' ? null : undefined,
                default_offset_minutes_start: context === 'template' ? null : undefined,
                default_offset_minutes_end:   context === 'template' ? null : undefined,
                capacity_mode: 'unbegrenzt',
                capacity_target: null,
                hours_default: 0,
                sort_order: 0,
            },
            ancestor_path: parentTaskId === '' ? '' : lookupAncestorPathFromDom(parseInt(parentTaskId, 10)),
        };

        renderEditModalBody(data, {mode: 'create'});
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    function lookupAncestorPathFromDom(parentTaskId) {
        // Best-effort: rekonstruiere Pfad aus title-Links der Vorfahren im DOM.
        // Nicht perfekt (nur title, kein category), aber ausreichend fuer
        // Create-Modal-Breadcrumb.
        const segments = [];
        let current = treeRoot?.querySelector(
            `.task-node[data-task-id="${parentTaskId}"]`
        );
        while (current) {
            const link = current.querySelector('.task-node__edit-trigger');
            if (link) segments.unshift(link.textContent.trim());
            const parentLi = current.parentElement?.closest('.task-node');
            current = parentLi;
        }
        return segments.join(' > ');
    }

    function showSpinner() {
        const body = document.getElementById('task-edit-modal-body');
        if (!body) return;
        body.innerHTML = '';
        const wrap = document.createElement('div');
        wrap.className = 'text-center py-4';
        const spinner = document.createElement('div');
        spinner.className = 'spinner-border';
        spinner.setAttribute('role', 'status');
        const sr = document.createElement('span');
        sr.className = 'visually-hidden';
        sr.textContent = 'Laedt...';
        spinner.appendChild(sr);
        wrap.appendChild(spinner);
        body.appendChild(wrap);

        const saveBtn = document.getElementById('task-edit-modal-save');
        if (saveBtn) saveBtn.disabled = true;
    }

    function renderEditModalBody(data, opts) {
        const mode = (opts && opts.mode) || 'edit';
        fillBreadcrumb(data.ancestor_path || '', data.task.title || '', mode);

        const body = document.getElementById('task-edit-modal-body');
        if (!body) return;
        body.innerHTML = '';
        body.appendChild(buildForm(data.task, mode));

        const titleEl = document.getElementById('task-edit-modal-title');
        if (titleEl) {
            titleEl.textContent = mode === 'create'
                ? 'Neuen Knoten anlegen'
                : (data.task.is_group ? 'Gruppe bearbeiten' : 'Aufgabe bearbeiten');
        }

        const saveBtn = document.getElementById('task-edit-modal-save');
        if (saveBtn) saveBtn.disabled = false;
    }

    function fillBreadcrumb(ancestorPath, currentTitle, mode) {
        const el = document.getElementById('task-edit-modal-breadcrumb');
        if (!el) return;
        el.innerHTML = '';

        const segments = ancestorPath ? ancestorPath.split(' > ').filter(Boolean) : [];
        segments.forEach(seg => {
            const li = document.createElement('li');
            li.className = 'breadcrumb-item';
            li.textContent = seg;
            el.appendChild(li);
        });
        if (mode === 'create') {
            const li = document.createElement('li');
            li.className = 'breadcrumb-item active';
            li.textContent = '(neuer Knoten)';
            el.appendChild(li);
        }
    }

    // =====================================================================
    // Form-Aufbau (kein innerHTML mit User-Freitext — alles ueber DOM-APIs)
    // =====================================================================

    function buildForm(task, mode) {
        const form = document.createElement('form');
        form.id = 'task-edit-form';
        form.className = 'row g-3';
        form.method = 'POST';
        form.action = (mode === 'create')
            ? treeRoot.dataset.endpointCreate
            : (task.id
                ? document.querySelector(`.task-node[data-task-id="${task.id}"]`)?.dataset.endpointUpdate
                : '');
        form.addEventListener('submit', (evt) => handleFormSubmit(evt, task, mode));

        appendCsrf(form);

        // is_group-Umschaltung nur im Create-Modus; Edit-Modus erbt den
        // Shape-Zustand vom Server (Shape-Wechsel ist Convert, nicht Update).
        if (mode === 'create') {
            appendSelect(form, 'is_group', 'Typ', String(task.is_group ? 1 : 0), [
                {value: '0', label: 'Aufgabe (Leaf)'},
                {value: '1', label: 'Gruppe'},
            ], 'col-md-12');
            form.querySelector('[name="is_group"]').addEventListener('change', (e) => {
                const isGroup = e.target.value === '1';
                toggleLeafFields(form, !isGroup);
            });
        } else {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'is_group_readonly';
            hidden.value = task.is_group ? '1' : '0';
            form.appendChild(hidden);
        }

        appendInput(form, 'title', 'Titel *', task.title || '', 'text', 'col-md-12', true);
        appendTextarea(form, 'description', 'Beschreibung', task.description || '', 'col-md-12');

        // Leaf-only-Felder als Gruppe (werden bei is_group=1 versteckt).
        const leafWrap = document.createElement('div');
        leafWrap.className = 'row g-3 task-edit-leaf-fields';
        leafWrap.dataset.leafFields = '1';
        form.appendChild(leafWrap);

        appendSelect(leafWrap, 'task_type', 'Art', task.task_type || 'aufgabe', [
            {value: 'aufgabe', label: 'Aufgabe'},
            {value: 'beigabe', label: 'Beigabe'},
        ], 'col-md-4');

        appendSelect(leafWrap, 'slot_mode', 'Slot-Modus', task.slot_mode || 'fix', [
            {value: 'fix', label: 'Fixes Zeitfenster'},
            {value: 'variabel', label: 'Variabel'},
        ], 'col-md-4');

        appendSelect(leafWrap, 'capacity_mode', 'Kapazitaet', task.capacity_mode || 'unbegrenzt', [
            {value: 'unbegrenzt', label: 'unbegrenzt'},
            {value: 'ziel',       label: 'Ziel-Anzahl'},
            {value: 'maximum',    label: 'Maximum (hart)'},
        ], 'col-md-4');

        appendInput(leafWrap, 'capacity_target', 'Anzahl (bei ziel/maximum)',
            task.capacity_target === null || task.capacity_target === undefined ? '' : String(task.capacity_target),
            'number', 'col-md-4');

        appendInput(leafWrap, 'hours_default', 'Standard-Stunden',
            String(task.hours_default ?? 0), 'number', 'col-md-4', false, {step: '0.25', min: '0', max: '24'});

        const catOptions = [{value: '', label: '- keine -'}].concat(
            categories.map(c => ({
                value: String(c.id),
                label: c.name + (c.is_contribution ? ' (Beigabe)' : ''),
            }))
        );
        appendSelect(leafWrap, 'category_id', 'Kategorie',
            task.category_id ? String(task.category_id) : '',
            catOptions, 'col-md-4');

        // I7c Phase 2: Zeit-Felder kontext-abhaengig rendern.
        // - Event:    start_at / end_at   (datetime-local, DATETIME-String)
        // - Template: default_offset_minutes_start / ...end (number, Minuten
        //   relativ zum Event-Start, negativ fuer Vorbereitungs-Tasks)
        if (context === 'template') {
            appendInput(
                leafWrap,
                'default_offset_minutes_start',
                'Offset-Start in Minuten (relativ zum Event-Start)',
                task.default_offset_minutes_start === null || task.default_offset_minutes_start === undefined
                    ? ''
                    : String(task.default_offset_minutes_start),
                'number', 'col-md-6', false, {step: '1'}
            );
            appendInput(
                leafWrap,
                'default_offset_minutes_end',
                'Offset-Ende in Minuten (relativ zum Event-Start)',
                task.default_offset_minutes_end === null || task.default_offset_minutes_end === undefined
                    ? ''
                    : String(task.default_offset_minutes_end),
                'number', 'col-md-6', false, {step: '1'}
            );
        } else {
            appendInput(leafWrap, 'start_at', 'Start (bei Slot=fix)',
                formatDateTimeLocal(task.start_at), 'datetime-local', 'col-md-6');

            appendInput(leafWrap, 'end_at', 'Ende (bei Slot=fix)',
                formatDateTimeLocal(task.end_at), 'datetime-local', 'col-md-6');
        }

        // Parent-ID als hidden — Feldname kontext-abhaengig, damit der
        // Controller die bestehende normalizeTreeFormInputs-Logik greifen
        // laesst (event: parent_task_id, template: parent_template_task_id).
        if (mode === 'create') {
            const parentField = parentIdField();
            const parentHidden = document.createElement('input');
            parentHidden.type = 'hidden';
            parentHidden.name = parentField;
            const parentVal = task[parentField];
            parentHidden.value = (parentVal === null || parentVal === undefined)
                ? ''
                : String(parentVal);
            form.appendChild(parentHidden);
        }

        // Initial-Zustand: Leaf-Felder bei is_group=1 verstecken.
        if (task.is_group) {
            toggleLeafFields(form, false);
        }

        // Fehlerbereich fuer ValidationException-Feedback
        const errBlock = document.createElement('div');
        errBlock.id = 'task-edit-form-errors';
        errBlock.className = 'col-md-12';
        form.appendChild(errBlock);

        return form;
    }

    function toggleLeafFields(form, show) {
        const wrap = form.querySelector('[data-leaf-fields="1"]');
        if (!wrap) return;
        wrap.classList.toggle('d-none', !show);
    }

    function formatDateTimeLocal(value) {
        if (!value) return '';
        // "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM" fuer datetime-local
        const s = String(value).replace(' ', 'T');
        return s.length >= 16 ? s.substring(0, 16) : s;
    }

    function appendCsrf(form) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = csrfToken;
        form.appendChild(input);
    }

    function appendInput(parent, name, label, value, type, colClass, required, extraAttrs) {
        const col = document.createElement('div');
        col.className = colClass || 'col-md-6';
        const id = 'task-edit-' + name;
        const lbl = document.createElement('label');
        lbl.setAttribute('for', id);
        lbl.className = 'form-label';
        lbl.textContent = label;
        const input = document.createElement('input');
        input.type = type || 'text';
        input.className = 'form-control';
        input.id = id;
        input.name = name;
        input.value = value;
        if (required) input.required = true;
        if (extraAttrs) {
            Object.entries(extraAttrs).forEach(([k, v]) => input.setAttribute(k, v));
        }
        col.appendChild(lbl);
        col.appendChild(input);
        parent.appendChild(col);
    }

    function appendTextarea(parent, name, label, value, colClass) {
        const col = document.createElement('div');
        col.className = colClass || 'col-md-12';
        const id = 'task-edit-' + name;
        const lbl = document.createElement('label');
        lbl.setAttribute('for', id);
        lbl.className = 'form-label';
        lbl.textContent = label;
        const ta = document.createElement('textarea');
        ta.className = 'form-control';
        ta.id = id;
        ta.name = name;
        ta.rows = 3;
        ta.value = value;  // textContent via .value — XSS-sicher
        col.appendChild(lbl);
        col.appendChild(ta);
        parent.appendChild(col);
    }

    function appendSelect(parent, name, label, selectedValue, options, colClass) {
        const col = document.createElement('div');
        col.className = colClass || 'col-md-6';
        const id = 'task-edit-' + name;
        const lbl = document.createElement('label');
        lbl.setAttribute('for', id);
        lbl.className = 'form-label';
        lbl.textContent = label;
        const sel = document.createElement('select');
        sel.className = 'form-select';
        sel.id = id;
        sel.name = name;
        options.forEach(opt => {
            const o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.label;  // XSS-sicher: textContent
            if (String(opt.value) === String(selectedValue)) o.selected = true;
            sel.appendChild(o);
        });
        col.appendChild(lbl);
        col.appendChild(sel);
        parent.appendChild(col);
    }

    // =====================================================================
    // Submit
    // =====================================================================

    async function handleFormSubmit(evt, task, mode) {
        evt.preventDefault();
        const form = evt.currentTarget;
        const body = collectFormData(form);
        const url = form.action;

        const saveBtn = document.getElementById('task-edit-modal-save');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.dataset.origLabel = saveBtn.textContent;
            saveBtn.textContent = 'Speichere...';
        }

        const result = await postJson(url, body);

        if (result.ok) {
            showSuccessToast(mode === 'create' ? 'Knoten angelegt.' : 'Knoten aktualisiert.');
            const modalEl = document.getElementById('task-edit-modal');
            if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
            // Aggregator-Felder (helpers_subtree usw.) muessen neu berechnet
            // werden — einfachster Weg: Server rendert frisch.
            window.location.reload();
            return;
        }

        if (saveBtn) {
            saveBtn.disabled = false;
            if (saveBtn.dataset.origLabel) saveBtn.textContent = saveBtn.dataset.origLabel;
        }
        showFormErrors(result.errors || [result.message || 'Speichern fehlgeschlagen.']);
    }

    function collectFormData(form) {
        const out = {};
        const fd = new FormData(form);
        for (const [k, v] of fd.entries()) {
            if (k === 'is_group_readonly') continue;  // Client-only-Marker
            out[k] = v;
        }
        // Numerische Felder als Zahlen senden — Service nimmt sie sonst als Strings.
        if (out.hours_default !== undefined && out.hours_default !== '') {
            const n = parseFloat(out.hours_default);
            if (Number.isFinite(n)) out.hours_default = n;
        }
        if (out.capacity_target !== undefined && out.capacity_target === '') {
            delete out.capacity_target;
        }
        if (out.parent_task_id !== undefined && out.parent_task_id === '') {
            out.parent_task_id = null;
        }
        // Analog fuer Template-Kontext (Feldname seit I7c Phase 2).
        if (out.parent_template_task_id !== undefined && out.parent_template_task_id === '') {
            out.parent_template_task_id = null;
        }
        // Template-Offsets: leere Strings zu null, damit der Service-Null-
        // Check greift (statt "" als ungueltiger Integer zum INSERT zu gehen).
        ['default_offset_minutes_start', 'default_offset_minutes_end'].forEach((f) => {
            if (out[f] === '') {
                out[f] = null;
            } else if (out[f] !== undefined && out[f] !== null) {
                const n = parseInt(out[f], 10);
                if (Number.isFinite(n)) out[f] = n;
            }
        });
        // is_group aus dem Create-Select als int
        if (out.is_group !== undefined) {
            out.is_group = out.is_group === '1' ? 1 : 0;
        }
        return out;
    }

    function showFormErrors(errors) {
        const block = document.getElementById('task-edit-form-errors');
        if (!block) return;
        block.innerHTML = '';
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger mb-0';
        alert.setAttribute('role', 'alert');
        const ul = document.createElement('ul');
        ul.className = 'mb-0';
        const items = Array.isArray(errors) ? errors : Object.values(errors);
        items.forEach(msg => {
            const li = document.createElement('li');
            li.textContent = typeof msg === 'string' ? msg : JSON.stringify(msg);
            ul.appendChild(li);
        });
        alert.appendChild(ul);
        block.appendChild(alert);
    }

    // =====================================================================
    // Convert / Delete
    // =====================================================================

    async function handleConvert(btn) {
        const target = btn.dataset.target;
        const endpoint = btn.dataset.endpointConvert;
        if (!target || !endpoint) return;

        const confirmMsg = target === 'group'
            ? 'Knoten wirklich in eine Gruppe konvertieren? Leaf-Attribute (Slot, Kapazitaet, Stunden) gehen dabei verloren.'
            : 'Knoten wirklich in eine Aufgabe konvertieren?';
        if (!window.confirm(confirmMsg)) return;

        const result = await postJson(endpoint, {target: target});
        if (!result.ok) {
            showErrorToast(result.message || 'Konvertieren fehlgeschlagen.');
            return;
        }
        showSuccessToast('Konvertiert.');
        window.location.reload();
    }

    async function handleDelete(btn) {
        const endpoint = btn.dataset.endpointDelete;
        if (!endpoint) return;

        if (!window.confirm('Knoten wirklich loeschen? Diese Aktion ist nur erlaubt, wenn keine aktiven Kinder / Zusagen existieren.')) {
            return;
        }

        const result = await postJson(endpoint, {});
        if (!result.ok) {
            showErrorToast(result.message || 'Loeschen fehlgeschlagen.');
            return;
        }
        showSuccessToast('Geloescht.');
        window.location.reload();
    }

    // =====================================================================
    // HTTP
    // =====================================================================

    async function fetchJson(url) {
        try {
            const res = await fetch(url, {
                method: 'GET',
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin',
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                return {ok: false, message: data.error || ('HTTP ' + res.status)};
            }
            const data = await res.json();
            return {ok: true, data: data};
        } catch (e) {
            return {ok: false, message: 'Netzwerkfehler'};
        }
    }

    async function postJson(url, body) {
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                return {
                    ok: false,
                    status: res.status,
                    errors: data.errors || null,
                    message: data.error || data.status || ('HTTP ' + res.status),
                };
            }
            const data = await res.json().catch(() => ({}));
            return {ok: true, data: data};
        } catch (e) {
            return {ok: false, message: 'Netzwerkfehler'};
        }
    }

    // =====================================================================
    // Toasts
    // =====================================================================

    function showSuccessToast(msg) {
        showToast(msg, 'success');
    }

    function showErrorToast(msg) {
        showToast(msg, 'danger');
    }

    function showToast(msg, variant) {
        let container = document.getElementById('task-tree-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'task-tree-toast-container';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1100';
            document.body.appendChild(container);
        }
        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-' + (variant || 'secondary') + ' border-0';
        toastEl.setAttribute('role', variant === 'danger' ? 'alert' : 'status');
        toastEl.setAttribute('aria-live', variant === 'danger' ? 'assertive' : 'polite');
        toastEl.setAttribute('aria-atomic', 'true');

        const flex = document.createElement('div');
        flex.className = 'd-flex';
        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = msg;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-close btn-close-white me-2 m-auto';
        btn.setAttribute('data-bs-dismiss', 'toast');
        btn.setAttribute('aria-label', 'Schliessen');

        flex.appendChild(body);
        flex.appendChild(btn);
        toastEl.appendChild(flex);
        container.appendChild(toastEl);

        const toast = bootstrap.Toast.getOrCreateInstance(toastEl, {
            delay: variant === 'danger' ? 6000 : 2500,
        });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    // =====================================================================
    // I7e-A Phase 2 — Sidebar-zu-Tree-Scroll-Highlight
    //
    // Die Editor-Sidebar rendert pro Leaf einen Button mit
    // data-sidebar-scroll-target="<task_id>". Ein Klick scrollt den
    // zugehoerigen Knoten im Tree-Widget in den Viewport und pulst
    // die CSS-Klasse .task-node--highlighted fuer 1.5 Sekunden. Das
    // gibt visuelles Feedback, wo der Knoten im Tree steht.
    //
    // Event-Delegation am document — funktioniert fuer die Desktop-
    // Sidebar (col-lg-4) und fuer die Offcanvas-Instanz gleichzeitig,
    // ohne dass wir wissen muessen, welche DOM-Instanz geklickt wurde.
    // =====================================================================

    function initSidebarScrollHighlight() {
        const HIGHLIGHT_MS = 1500;
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-sidebar-scroll-target]');
            if (!trigger) {
                return;
            }
            const taskId = trigger.getAttribute('data-sidebar-scroll-target');
            if (!taskId) {
                return;
            }
            const treeRoot = document.getElementById('task-tree-editor');
            if (!treeRoot) {
                return;
            }
            // Auf exakten Treffer im Tree zielen — der selector deckt
            // beliebige Verschachtelung im Baum ab.
            const targetNode = treeRoot.querySelector(
                '.task-node[data-task-id="' + CSS.escape(taskId) + '"]'
            );
            if (!targetNode) {
                return;
            }

            // Offcanvas (Mobile/Tablet) vor dem Scroll schliessen, sonst
            // verdeckt das Canvas das Ziel. Bootstrap-5 liefert die API.
            const offcanvasEl = document.getElementById('editorSidebarOffcanvas');
            if (offcanvasEl && offcanvasEl.classList.contains('show')
                && typeof window.bootstrap !== 'undefined'
                && window.bootstrap.Offcanvas) {
                const inst = window.bootstrap.Offcanvas.getInstance(offcanvasEl);
                if (inst) {
                    inst.hide();
                }
            }

            // Auto-Expand der Ancestor-Gruppen (Follow-up u, G6). Wenn das
            // Ziel in einer eingeklappten Gruppe liegt, ist das <ul> der
            // Kind-Liste per CSS display:none — scrollIntoView traefe ein
            // unsichtbares Element. Alle Eltern-Gruppen aufklappen, bis das
            // Ziel erreicht ist; per-Node-Chevron dreht sich automatisch
            // mit (CSS-Rotation reagiert auf das Fehlen von
            // .task-node--collapsed).
            let ancestor = targetNode.parentElement;
            while (ancestor && ancestor !== treeRoot) {
                if (ancestor.classList
                    && ancestor.classList.contains('task-node--collapsed')) {
                    ancestor.classList.remove('task-node--collapsed');
                }
                ancestor = ancestor.parentElement;
            }

            targetNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetNode.classList.add('task-node--highlighted');
            window.setTimeout(() => {
                targetNode.classList.remove('task-node--highlighted');
            }, HIGHLIGHT_MS);
        });
    }

    // =====================================================================
    // I7e-A Phase 2c — Per-Node-Collapse + Expand/Collapse-All
    //
    // Dreifacher Mechanismus im gleichen Event-Delegation-Listener:
    //
    //   1. [data-action="toggle-node"] innerhalb einer Task-Zeile:
    //      togglt die Klasse .task-node--collapsed auf dem umgebenden
    //      Gruppen-<li>. CSS blendet die Kind-UL ein/aus.
    //   2. [data-action="expand-all"] im Tree-Header: entfernt
    //      .task-node--collapsed von allen Gruppen-<li> im Editor.
    //   3. [data-action="collapse-all"] im Tree-Header: setzt
    //      .task-node--collapsed auf allen Gruppen-<li>.
    //
    // Keine Animation, kein Zustand-Persistieren — ein Page-Reload
    // zeigt immer den expandierten Default. Das ist bewusst: der Tree
    // hat pro Event eine andere Struktur; State-Persistenz pro Event-ID
    // waere Scope-Creep.
    // =====================================================================

    function initTreeCollapseControls() {
        const editor = document.getElementById('task-tree-editor');
        if (!editor) {
            return;
        }

        editor.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-action="toggle-node"]');
            if (!toggle) {
                return;
            }
            const groupLi = toggle.closest('.task-node--group');
            if (!groupLi) {
                return;
            }
            groupLi.classList.toggle('task-node--collapsed');
        });

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest(
                '[data-action="expand-all"], [data-action="collapse-all"]'
            );
            if (!trigger) {
                return;
            }
            const action = trigger.getAttribute('data-action');
            const groups = editor.querySelectorAll('.task-node--group');
            if (action === 'expand-all') {
                groups.forEach((li) => li.classList.remove('task-node--collapsed'));
            } else if (action === 'collapse-all') {
                groups.forEach((li) => li.classList.add('task-node--collapsed'));
            }
        });
    }

    // =====================================================================
    // Bootstrap
    // =====================================================================

    function boot() {
        initTaskTree();
        initSidebarScrollHighlight();
        initTreeCollapseControls();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
