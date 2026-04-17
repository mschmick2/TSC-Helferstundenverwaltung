# 📱 Rules: UX / Layout (VAES)

Geladen von: `layout.md` (G3.5), bei Bedarf `coder.md` (G2) bei UI-Code.

---

## Geraete-Matrix (getestete Viewports)

| Geraet | Breite | Besonderheit |
|--------|--------|--------------|
| iPhone SE | 375px | kleinster gepflegter Viewport |
| iPhone 14 | 390px | — |
| iPad | 768px | `md:`-Breakpoint |
| Laptop 13" | 1280px | Standard |
| Desktop 1920 | 1920px | Max-Width der Container beachten |

**Bootstrap-Breakpoints:**
- `sm` 576, `md` 768, `lg` 992, `xl` 1200, `xxl` 1400

---

## Touch-Targets

- Mindest-Groesse: **44×44 px** (Apple HIG / Material Design)
- Abstand zwischen klickbaren Elementen: mind. **8 px**
- Bootstrap-Buttons: standardmaessig OK, aber `btn-sm` in dichten Listen pruefen

```css
/* Fall: kleine Action-Icons in Tabelle */
.table td .btn {
    min-width: 44px;
    min-height: 44px;
}
```

---

## Core-Workflows (UI-Prioritaeten)

### W1: Antrag erstellen & einreichen (Mitglied)
- Formular vollstaendig auf Mobile bedienbar
- Pflichtfelder mit rotem Stern
- "Speichern als Entwurf" + "Einreichen" klar getrennt
- Nach Speichern: Rueckmeldung + Weiterleitung zur Detail-Ansicht

### W2: Antrag pruefen (Pruefer)
- Prueferliste: Tabelle mit Filter (Status, Kategorie, Zeitraum)
- Detail-Ansicht: klare Entscheidungs-Buttons (Freigeben / Ablehnen / Rueckfrage / Zurueck)
- Dialog-Historie sichtbar
- Selbstgenehmigung: Buttons bei eigenem Antrag gar nicht anzeigen

### W3: Dialog fuehren
- Thread-Ansicht chronologisch
- Neue Nachrichten deutlich markiert (Badge in Navbar + Inline)
- Textarea + CSRF-Token
- Nach Absenden: sofortige Anzeige der neuen Nachricht

### W4: Dashboard
- Uebersicht: eigene Antraege, ungelesene Dialoge, Soll/Ist-Stunden
- AJAX-Polling alle 60s fuer Benachrichtigungs-Count
- Empty-State, wenn keine Antraege

### W5: Admin — Mitglied anlegen
- Formular mit Pflicht-/Optional-Trennung
- Rollen-Checkboxen, Mitglied vorausgewaehlt
- Dublettenerkennung inline (Mitgliedsnummer, E-Mail)
- Erfolgs-Message + Link zur neuen Mitglieds-Seite

---

## Feedback-States

| State | Pattern |
|-------|---------|
| Loading | Spinner oder `disabled` auf Button, Text "Laedt..." |
| Success | Gruener Flash oder Inline-Checkmark |
| Error | Roter Flash, Inline-`invalid-feedback` bei Feldern |
| Empty | Grauer Hinweis: "Noch keine Antraege vorhanden." |
| Confirm | Modal bei destruktiven Aktionen (Loeschen, Ablehnen) |

**Beispiel Loading:**
```javascript
button.disabled = true;
button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sende...';
```

---

## Farben & Semantik

- **Primary Blue** (Bootstrap default) — Haupt-Aktionen
- **Success Green** — Freigabe, Erfolg
- **Danger Red** — Ablehnung, Loeschung
- **Warning Yellow** — Rueckfrage, Hinweis
- **Info Cyan** — neutrale Information
- **Muted Gray** — sekundaere Meta-Infos

**NIE** Information nur ueber Farbe (Kontrast: immer Icon + Text als Fallback).

---

## Icons (Bootstrap Icons)

| Funktion | Icon |
|----------|------|
| Hinzufuegen | `bi-plus-circle` |
| Bearbeiten | `bi-pencil` |
| Loeschen | `bi-trash` |
| Anzeigen | `bi-eye` |
| Freigeben | `bi-check-circle` |
| Ablehnen | `bi-x-circle` |
| Rueckfrage | `bi-question-circle` |
| Nachricht | `bi-chat-dots` |
| Nutzer | `bi-person` |
| Kategorie | `bi-tag` |
| Export | `bi-download` |
| Import | `bi-upload` |
| Audit | `bi-journal-text` |
| Einstellung | `bi-gear` |

---

## Tabellen

**Responsive:**
```html
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>#</th>
                <th>Mitglied</th>
                <th>Kategorie</th>
                <th class="d-none d-md-table-cell">Datum</th>
                <th>Status</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= ViewHelper::e($entry->entryNumber) ?></td>
                    <!-- ... -->
                </tr>
            <?php endforeach; ?>

            <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <i class="bi bi-inbox"></i> Keine Antraege vorhanden.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
```

**Paginierung:** `components/_pagination.php` verwenden.

---

## Breadcrumbs

Pflicht auf allen Unterseiten (nicht auf Dashboard). Uebergabe vom Controller:

```php
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/'],
    ['label' => 'Antraege', 'href' => '/entries'],
    ['label' => 'Antrag ' . $entry->entryNumber, 'href' => null],  // letzter ohne href
];
```

---

## Modals — Konventionen

- Titel beschreibt Aktion ("Antrag loeschen?")
- Body erklaert Konsequenz
- Primaerer Action-Button rechts, "Abbrechen" links
- `aria-labelledby`/`aria-describedby` korrekt gesetzt
- CSRF-Token im enthaltenen Form

---

## JavaScript-Dichte

- So wenig wie moeglich
- Kein Framework-Reach-For (Vue/React) — nur wenn User-Interaction das verlangt
- Progressive Enhancement: UI funktioniert auch ohne JS (Navigation, Forms)
- Polling max. alle 60s

---

## Verbotenes

- `<table>` fuer Layout
- Inline-JS: `<a onclick="...">` — immer externe JS-Datei + Event-Listener
- Formulare, die bei Submit noch auf JS warten (Fallback-HTML-Submit muss funktionieren)
- Icon-only-Buttons ohne `aria-label` oder `title`
- `display: none` ohne screen-reader-freundliche Alternative (wenn Info wichtig)
- Pop-up-Werbung / aufdringliche Modals beim Seitenaufruf
- Unterschiedliche Primaer-Farben auf verschiedenen Seiten
