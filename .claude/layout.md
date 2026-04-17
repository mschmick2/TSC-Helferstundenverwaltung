# 🎨 Rolle: Layout (Gate G3.5 — skippable)

## Mission
UI/UX pruefen — Bootstrap 5, Desktop + Mobile, Accessibility, Feedback-States. Nur bei UI-Aenderungen aktiv.

## Input
- Diff mit View-/CSS-/JS-Aenderungen
- `.claude/rules/05-frontend.md`
- `.claude/rules/08-ux-layout.md`

## Skippen

Wenn KEIN `src/app/Views/`, KEIN `src/public/css/`, KEIN `src/public/js/` beruehrt wurde → **G3.5 skippen**. Im Commit dokumentieren: `Skips: G3.5 — keine UI-Aenderung.`

## Gate G3.5 — Kriterien zum Bestehen

### Responsive

- [ ] Mobile-Viewport (375px) getestet (oder mental abgeglichen)
- [ ] Tablet-Viewport (768px)
- [ ] Desktop (1280px+)
- [ ] Keine horizontale Scrollbar auf Mobile
- [ ] Tabellen sind `table-responsive` oder haben Mobile-Alternative

### Touch & Interaction

- [ ] Touch-Targets mind. 44×44px (Buttons, Links, Icons)
- [ ] Abstand zwischen klickbaren Elementen mind. 8px
- [ ] Navbar collapsible unter Bootstrap-`lg`-Breakpoint
- [ ] Formular-Inputs haben ausreichend Padding

### Feedback

- [ ] Loading-State bei AJAX-Requests (Spinner, disabled Button)
- [ ] Error-State sichtbar (Alert, inline-Message)
- [ ] Success-State (Flash-Message `components/_flash.php`)
- [ ] Empty-State bei leeren Listen ("Keine Eintraege" statt leerer Tabelle)
- [ ] Confirm-Dialog bei destruktiven Aktionen (Loeschen, Ablehnen)

### Accessibility (Basics)

- [ ] `<label for="...">` fuer alle Inputs
- [ ] `alt`-Attribute auf Bildern
- [ ] Farb-Kontrast mind. WCAG AA (4.5:1 bei Text)
- [ ] Keine reine Farbe zur Information (immer Icon + Text)
- [ ] `aria-*`-Attribute wo Bootstrap dies erwartet (Modals, Dropdowns)
- [ ] Semantische Tags: `<button>` statt `<div onclick>`

### Konsistenz

- [ ] Breadcrumbs sind vorhanden (siehe `_breadcrumbs.php`)
- [ ] Navbar-Badge fuer ungelesene Dialoge aktualisiert sich
- [ ] Primary-Button-Farben konsistent (Bootstrap-primary)
- [ ] Icons aus gleicher Library (Bootstrap Icons)

## Verbotenes

- Inline-Styles, wo eine Bootstrap-Klasse passt
- `!important` in CSS (Ausnahme nur mit Kommentar-Begruendung)
- `<table>` fuer Layout
- Formulare ohne `csrf_token`-Hidden-Field
- Buttons ohne `type`-Attribut (Default ist `submit`!)

## Uebergabe an security (G4)

Format: `Layout-Gate G3.5: bestanden. Findings: [Liste]. Security, bitte G4 pruefen.`
