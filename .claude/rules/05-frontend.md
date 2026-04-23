# 🎨 Rules: Frontend (Bootstrap 5 / Vanilla JS — VAES)

Geladen von: `coder.md` (G2), bei Bedarf `reviewer.md` (G3) bei UI-Code.

---

## Stack

- **Bootstrap 5** via CDN (oder lokal in `src/public/css/` / `src/public/js/`)
- **Bootstrap Icons**
- **Vanilla JavaScript** (ES6+) — keine jQuery, keine Frameworks
- **Fetch API** fuer AJAX

---

## Layout-Struktur

```
src/app/Views/
├── layouts/
│   ├── main.php              # Haupt-Layout mit Navbar
│   └── auth.php              # Login/Register-Layout ohne Navbar
├── components/
│   ├── _navbar.php
│   ├── _breadcrumbs.php
│   ├── _flash.php
│   └── _pagination.php
├── dashboard/index.php
├── entries/
│   ├── index.php             # Liste
│   ├── show.php              # Detail
│   ├── create.php
│   ├── edit.php
│   ├── review.php
│   └── _dialog.php           # Dialog-Partial
└── admin/
    ├── users/
    ├── categories/
    ├── targets/
    ├── settings/
    └── audit/
```

---

## Bootstrap 5 — Grid

**DO:**
```html
<div class="container-fluid">
    <div class="row">
        <div class="col-12 col-md-8">Hauptinhalt</div>
        <div class="col-12 col-md-4">Sidebar</div>
    </div>
</div>
```

**Mobile-First:** Default-Klassen sind mobil, `md:`/`lg:` skalieren nach oben.

---

## Formulare

**Standard-Pattern:**
```html
<form method="POST" action="/entries" class="needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?= ViewHelper::e($csrfToken) ?>">

    <div class="mb-3">
        <label for="hours" class="form-label">
            Stunden <span class="text-danger">*</span>
        </label>
        <input type="number" step="0.25" min="0" max="24"
               class="form-control" id="hours" name="hours"
               required
               value="<?= ViewHelper::e($entry->hours ?? '') ?>">
        <div class="invalid-feedback">
            Bitte Stunden zwischen 0 und 24 angeben.
        </div>
    </div>

    <div class="mb-3">
        <label for="category_id" class="form-label">
            Kategorie <span class="text-danger">*</span>
        </label>
        <select class="form-select" id="category_id" name="category_id" required>
            <option value="">-- bitte waehlen --</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat->id ?>"
                    <?= $selected === $cat->id ? 'selected' : '' ?>>
                    <?= ViewHelper::e($cat->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn btn-primary">Speichern</button>
    <a href="/entries" class="btn btn-secondary">Abbrechen</a>
</form>
```

**Pflichtfeld-Markierung:** Roter Stern `<span class="text-danger">*</span>` + Hinweistext unter dem Formular.

---

## Flash-Messages

```php
// Controller
$this->flash($request, 'success', 'Antrag gespeichert.');
$this->flash($request, 'danger', 'Fehler: ...');
$this->flash($request, 'warning', 'Achtung: ...');
$this->flash($request, 'info', 'Hinweis: ...');
```

```php
// src/app/Views/components/_flash.php
<?php foreach ($flashes ?? [] as $type => $msgs): ?>
    <?php foreach ($msgs as $msg): ?>
        <div class="alert alert-<?= ViewHelper::e($type) ?> alert-dismissible fade show" role="alert">
            <?= ViewHelper::e($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schliessen"></button>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
```

---

## Buttons — Semantik

| Aktion | Klasse | Icon |
|--------|--------|------|
| Primaere Aktion | `btn-primary` | je nach Kontext |
| Sekundaer | `btn-secondary` | — |
| Gefahr (Loeschen/Ablehnen) | `btn-danger` | `bi-trash` / `bi-x-circle` |
| Erfolg (Freigeben) | `btn-success` | `bi-check-circle` |
| Warnung (Rueckfrage) | `btn-warning` | `bi-question-circle` |
| Link-Style | `btn-link` | — |

**Pflicht:**
```html
<!-- type explizit, sonst submit in Form! -->
<button type="submit" class="btn btn-primary">Speichern</button>
<button type="button" class="btn btn-secondary">Abbrechen</button>
```

---

## Modals

```html
<!-- Trigger -->
<button type="button" class="btn btn-danger"
        data-bs-toggle="modal"
        data-bs-target="#confirmDelete<?= (int)$entry->id ?>">
    Loeschen
</button>

<!-- Modal -->
<div class="modal fade" id="confirmDelete<?= (int)$entry->id ?>" tabindex="-1"
     aria-labelledby="confirmDeleteLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmDeleteLabel">Wirklich loeschen?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Antrag <?= ViewHelper::e($entry->entry_number) ?> wird unwiderruflich geloescht.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <form method="POST" action="/entries/<?= (int)$entry->id ?>/delete">
                    <input type="hidden" name="csrf_token" value="<?= ViewHelper::e($csrfToken) ?>">
                    <button type="submit" class="btn btn-danger">Loeschen</button>
                </form>
            </div>
        </div>
    </div>
</div>
```

### Fullscreen + scrollbar auf Mobile — Flex-Hoehen-Falle

Wenn ein Modal auf schmalen Viewports Fullscreen gehen soll (typisch
fuer groessere Formulare) und gleichzeitig scrollbar sein muss, ist
die Kombination:

```html
<div class="modal-dialog modal-lg modal-fullscreen-md-down modal-dialog-scrollable">
```

Bootstrap 5.3 hat dabei eine Flex-Hoehen-Kollision unter Chromium-
Mobile-Emulation und echten iOS/Android-Browsern: das `.modal-body`
schrumpft **ohne** `min-height: 0` nicht unter seinen Content-
Anspruch, ueberfliesst den sticky Footer und deckt den Save-Button
visuell ab. Playwright-Symptom: `subtree intercepts pointer events`
beim Click auf den Footer-Button.

Loesung: in `src/public/css/app.css` ist bereits ein generischer
Fix vorhanden (gesucht mit `grep "I7b5"`). Wer die Klassen-
Kombination neu einfuehrt, muss nichts tun — der Selector greift.
**NIE** den Fix-Block entfernen, auch nicht als Aufraeumen
uninspiziert, ohne die Mobile-Regression zu pruefen.

**Begruendung:** der Bug ist subtil und nur auf Mobile sichtbar.
Desktop wirkt wie vorher; ein spaeteres Entfernen wuerde erst
Monate nach dem Merge in der Mobile-Praxis auffallen.

---

## AJAX mit Fetch API

```javascript
// src/public/js/dashboard.js
(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    try {
        const res = await fetch('/api/notifications/unread', {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const data = await res.json();
        updateBadge(data.count);
    } catch (e) {
        console.error('Polling-Fehler:', e);
    }
})();

// Polling alle 60 Sekunden
setInterval(pollNotifications, 60000);
```

**Pattern fuer POST-Requests:**
```javascript
const res = await fetch('/entries/' + id + '/approve', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': csrfToken,
        'Accept': 'application/json'
    },
    credentials: 'same-origin'
});
```

---

## Navbar-Badge (Ungelesene Dialoge)

```html
<a class="nav-link position-relative" href="/dialogs">
    <i class="bi bi-chat-dots"></i>
    Dialoge
    <?php if ($unreadCount > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle
                     badge rounded-pill bg-danger">
            <?= (int)$unreadCount ?>
            <span class="visually-hidden">ungelesene Nachrichten</span>
        </span>
    <?php endif; ?>
</a>
```

---

## Accessibility — Basics

- `<label for="id">` fuer alle Inputs
- `alt=""` auf Bildern (leer, wenn dekorativ)
- `aria-label` / `aria-labelledby` fuer Icon-Buttons ohne Text
- `visually-hidden`-Klasse fuer Screenreader-Hinweise
- Fokus-Ring bleibt sichtbar (kein `outline: none` ohne Ersatz)
- Kontrast mind. WCAG AA (4.5:1)

---

## Rekursive Partials (Tree/Nested-Listen)

Wenn eine View hierarchische Daten rendert (Aufgabenbaum, Templates-
Tree, ggf. Mitglieder-Accordion): **NICHT** per naked include im
foreach, weil das Container-`$node`/`$depth` ueberschreibt (Scope-Leak).

**DO (Container-Closure mit use-by-reference):**
```php
<?php
// Container (edit.php, show.php, ...)
$renderTaskNode = function (array $node, int $depth) use (&$renderTaskNode, $csrfToken, $eventId): void {
    include __DIR__ . '/_task_tree_node.php';
};
foreach ($treeData as $top) {
    $renderTaskNode($top, 0);
}
?>
```

Partial ruft die Closure im Kinder-Loop:
```php
<?php foreach ($children as $child): ?>
    <?php $renderTaskNode($child, $depth + 1); ?>
<?php endforeach; ?>
```

**DON'T:**
```php
<?php foreach ($node['children'] as $child): ?>
    <?php
        $node = $child;        // ueberschreibt Container-$node
        include __DIR__ . '/_task_tree_node.php';
    ?>
<?php endforeach; ?>
```

Invariants-Test (Projekt-Muster) prueft statisch, dass das Partial
nicht sich selbst per naked include einbindet und dass der Container
die Closure definiert.

---

## SortableJS — nested Listen

Fuer verschachtelte Sortable-ULs (UL enthaelt LI, LI enthaelt UL, alle
sortable) das **Minimal-Optionen-Set** nutzen und nur bei nachweislicher
Regression erweitern:

```javascript
Sortable.create(ul, {
    group: 'your-group',
    handle: '[data-sortable-handle]',
    animation: 150,
    delay: 200,
    delayOnTouchOnly: true,
    touchStartThreshold: 5,
    ghostClass: 'node--ghost',
    chosenClass: 'node--chosen',
    dragClass: 'node--drag',
    // Pflicht fuer nested: Ghost unter body statt in Quell-UL.
    fallbackOnBody: true,
    // Leere Ziel-Listen (z.B. leere Gruppe) als Drop-Target akzeptieren.
    emptyInsertThreshold: 12,
    onEnd: handleSortEnd,
});
```

**Verbotene Kombinationen** (aus I7b1-Erfahrung):
- `invertSwap: true` + `swapThreshold: 0.65` zerlegt die Drop-Zone und
  `onEnd` feuert nicht mehr. Ausschliesslich einzeln und mit
  Regressions-Nachweis einbauen.
- Ein explizites `draggable: 'li.xxx'` ist selten noetig; Default `>*`
  reicht, solange die UL nur die gewuenschten Kinder enthaelt.

Bei Drop-/Drag-Fehlern: zuerst `console.info` in
`onChoose`/`onStart`/`onMove`/`onEnd` einbauen und diagnostizieren,
eine Option pro Iteration aendern — nicht mehrere Options parallel.

---

## Verbotenes

- jQuery (Vanilla JS reicht)
- Inline-Styles, wo Bootstrap-Klasse passt (Ausnahme: dynamische Werte via JS)
- `!important` ohne Kommentar-Begruendung
- `<table>` fuer Layout
- `<button>` ohne `type`-Attribut (Default ist `submit`!)
- `<a href="javascript:...">` (immer `<button type="button">`)
- Formulare ohne CSRF-Token
- `innerHTML = userInput` in JS (XSS) — `textContent` verwenden
- Auto-Submit von Formularen bei Seitenaufruf
- Naked-include-Rekursion in rekursiven Partials (Scope-Leak) —
  immer Container-Closure mit `use(&...)` verwenden
- `invertSwap: true` in SortableJS ohne expliziten Regressions-Grund
