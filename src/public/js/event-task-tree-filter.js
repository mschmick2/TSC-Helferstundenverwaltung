// event-task-tree-filter.js — Mitglieder-Accordion Filter-Toggle (Modul 6 I7b2)
//
// Erwartet im DOM:
//   input#filter-open-only     — Bootstrap-5-Switch (checkbox).
//   .task-group-accordion      — Container mit data-open-count-Attributen
//                                an Leaves und Gruppen.
//
// Verhalten:
//   Checkbox an   → Klasse .filter-open-only auf Accordion-Container.
//                   CSS (in app.css) blendet Elemente mit
//                   data-open-count="0" aus und zeigt Empty-State,
//                   wenn nichts mehr sichtbar ist.
//   Checkbox aus  → Klasse entfernt, alle Elemente wieder sichtbar.
//
// Kein Fetch, keine Toasts, keine Abhaengigkeit von Bootstrap-JS.
// Reine Vanilla-Listener. Noscript-Fallback: Filter-Toggle ist ohne
// JS funktionslos, der Accordion bleibt vollstaendig bedienbar.

(function () {
    'use strict';

    function initFilter() {
        const toggle = document.getElementById('filter-open-only');
        const accordion = document.querySelector('.task-group-accordion');
        if (!toggle || !accordion) {
            return;
        }

        toggle.addEventListener('change', function () {
            accordion.classList.toggle('filter-open-only', toggle.checked);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFilter);
    } else {
        initFilter();
    }
})();
