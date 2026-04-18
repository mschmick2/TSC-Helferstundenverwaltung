# FullCalendar v6 — lokale Assets

Dieses Verzeichnis enthält die FullCalendar-Dateien (JavaScript + CSS), die lokal
ausgeliefert werden, damit VAES offline und ohne externe CDN-Aufrufe funktioniert
(Strato Shared Hosting: keine garantierten Outbound-Calls).

## Benoetigte Dateien

- `index.global.min.js` (~230 KB) — FullCalendar Core-Bundle (enthaelt auch CSS)
- `locales/de.global.min.js`      — Deutsche Lokalisierung

**Hinweis:** Ab FullCalendar v6 ist KEIN separates `main.min.css` mehr noetig —
das JS-Bundle injected die Styles selbst ueber Shadow-DOM / Style-Tags.

## Download (einmalig)

### Option A — Direktlinks (FullCalendar v6.1.x)

```bash
# Im Verzeichnis src/public/js/vendor/fullcalendar/
curl -O https://cdn.jsdelivr.net/npm/fullcalendar@6.1.17/index.global.min.js
mkdir -p locales
curl -o locales/de.global.min.js https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.17/locales/de.global.min.js
```

### Option B — Manuell (PowerShell auf Windows)

```powershell
cd e:\TSC-Helferstundenverwaltung\src\public\js\vendor\fullcalendar
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/fullcalendar@6.1.17/index.global.min.js" -OutFile "index.global.min.js"
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/fullcalendar@6.1.17/main.min.css"        -OutFile "main.min.css"
New-Item -ItemType Directory -Force -Path "locales" | Out-Null
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.17/locales/de.global.min.js" -OutFile "locales/de.global.min.js"
```

## Verifikation

Nach dem Download sollte dieses Verzeichnis enthalten:
```
fullcalendar/
├── README.md                  (diese Datei)
├── index.global.min.js        (~230 KB)
├── main.min.css               (~20 KB)
└── locales/
    └── de.global.min.js       (~2 KB)
```

## Upgrade

FullCalendar-Version in den URLs aendern und neu herunterladen.
Kein `npm install` noetig — VAES nutzt FullCalendar rein statisch.
