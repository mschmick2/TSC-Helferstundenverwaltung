# SortableJS 1.15.6 — lokales Asset

Drag-and-Drop-Bibliothek fuer den Aufgabenbaum-Editor (Modul 6 I7b1).
Lokal ausgeliefert, damit VAES ohne externe CDN-Aufrufe funktioniert
(Strato Shared Hosting: keine garantierten Outbound-Calls).

## Datei

- `Sortable.min.js` (~44 KB minified, ~12 KB gzip)

## Metadaten

- Quelle:    https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js
- Lizenz:    MIT
- Vendored:  2026-04-22
- SHA-256:   6D0A831FC19B4BAE851797AD3393157E861AFB7862459C11226359B27E2C4337

## Download (einmalig)

### PowerShell (Windows)

```powershell
cd e:\TSC-Helferstundenverwaltung\src\public\js\vendor\sortablejs
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js" -OutFile "Sortable.min.js"
(Get-FileHash Sortable.min.js -Algorithm SHA256).Hash  # muss dem Wert oben entsprechen
```

### curl (Linux/macOS)

```bash
cd src/public/js/vendor/sortablejs
curl -fsSL -o Sortable.min.js https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js
sha256sum Sortable.min.js  # muss dem Wert oben entsprechen
```

## Verifikation

Nach dem Download muss `(Get-FileHash ...).Hash` (bzw. `sha256sum`) exakt dem
unter "Metadaten" dokumentierten SHA-256 entsprechen. Bei Abweichung: falsche
Version oder manipulierte Auslieferung — Datei verwerfen, Download wiederholen,
bei zweiter Abweichung Pipeline anhalten.

## Upgrade

Neue Version erst nach bewusster Entscheidung und in einem eigenen Inkrement:

1. Version in den URLs hier und in diesem README anheben.
2. Datei neu herunterladen, SHA-256 neu berechnen.
3. Changelog des neuen Releases auf Breaking Changes pruefen
   (besonders `onEnd`, `group`, `delay`/`touchStartThreshold` werden von
   event-task-tree.js genutzt).
4. Kein `npm install` noetig — VAES nutzt SortableJS rein statisch.
