// event-task-tree.js — Aufgabenbaum-Editor (Modul 6 I7b1)
//
// Erwartet im DOM:
//   <section class="task-tree-editor" id="task-tree-editor"
//            data-event-id="..."
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
//     sonst inkonsistent bleiben. Weniger elegant als Patch-Render, aber
//     robust und passt zum Phase-3-Scope.
//   - XSS-Schutz: User-Freitext (title, description, ancestor_path) immer
//     per textContent oder escapeHtml() — nie innerHTML mit Template-String.

(function () {
    'use strict';

    let treeRoot = null;
    let csrfToken = '';
    let eventId = 0;
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
        eventId = parseInt(treeRoot.dataset.eventId, 10) || 0;
        categories = parseCategories(treeRoot.dataset.categories);

        initSortables();
        treeRoot.addEventListener('click', handleClick);
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
                delay: 200,            // Mobile: Long-Press, kein Scroll-Konflikt
                delayOnTouchOnly: true,
                touchStartThreshold: 5,
                ghostClass: 'task-node--ghost',
                chosenClass: 'task-node--chosen',
                dragClass: 'task-node--drag',
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
                event_id: eventId,
                parent_task_id: parentTaskId === '' ? null : parseInt(parentTaskId, 10),
                is_group: 0,
                category_id: null,
                title: '',
                description: '',
                task_type: 'aufgabe',
                slot_mode: 'fix',
                start_at: null,
                end_at: null,
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

        appendInput(leafWrap, 'start_at', 'Start (bei Slot=fix)',
            formatDateTimeLocal(task.start_at), 'datetime-local', 'col-md-6');

        appendInput(leafWrap, 'end_at', 'Ende (bei Slot=fix)',
            formatDateTimeLocal(task.end_at), 'datetime-local', 'col-md-6');

        // parent_task_id als hidden — wichtig fuer Create, beim Edit nur
        // Kontext-Anzeige. Backend entscheidet letztlich aus der Route.
        if (mode === 'create') {
            const parentHidden = document.createElement('input');
            parentHidden.type = 'hidden';
            parentHidden.name = 'parent_task_id';
            parentHidden.value = (task.parent_task_id === null || task.parent_task_id === undefined)
                ? ''
                : String(task.parent_task_id);
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
    // Bootstrap
    // =====================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTaskTree);
    } else {
        initTaskTree();
    }
})();
