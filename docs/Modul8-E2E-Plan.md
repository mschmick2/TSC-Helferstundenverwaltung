# Modul 8 — E2E-Test-Suite (Playwright, headed)

> **Stand:** 2026-04-21 — abgeschlossen.
> **Zweck:** Schliesst die groesste offene Luecke im Testprotokoll (`docs/Testprotokoll_VAES.md §13.2`): Kein einziger Browser-Test, keine echten HTTP-Roundtrips, kein Layout-Regress-Schutz.
> **Scope:** Plan urspruenglich fuer fuenf Inkremente (I1–I5) geschrieben. In der Umsetzung um drei Ergaenzungen gewachsen (Event-Storno, variabler Slot-Approval, Spec-04-Haertung); der Multitab-Teil des urspruenglichen I5 ist als I8 ausgerollt. Endstand: **38 E2E-Tests, 8 Commits**, lauffaehig headed (lokal) und headless (CI-ready).

---

## 1. Rahmenentscheidungen (festgezurrt)

| Thema | Entscheidung | Begruendung |
|-------|-------------|-------------|
| Tool | **Playwright + TypeScript** | Bester Multi-Context-Support fuer Multitab-Tests, Trace-Viewer, Codegen. |
| Ort | **`tests/e2e/`** neuer Top-Level | Getrennt von PHPUnit, eigene Toolchain. |
| Node-Heimat | **nur lokal** | CLAUDE.md verbietet Node auf Strato. CI kommt spaeter. |
| Test-DB | **`helferstunden_e2e`** (getrennt von `helferstunden_test`) | PHPUnit-Suite bleibt parallel gruen. |
| 2FA | **deaktiviert** fuer Seed-User (`totp_secret = NULL`) | Vermeidet Flakiness; 2FA-Pfad separat via Micro-Test wenn noetig. |
| Artefakte | **nicht committen** (Traces, Videos, HTML-Report) | `.gitignore` deckt `playwright-report/`, `test-results/`, `node_modules/`. |
| Seed-User | Admin, Pruefer, Event-Admin, 2x Mitglied | Deckt alle Rollen-Uebergaenge ab. |
| Browser | **Chromium only** in I1–I5 | Firefox/Webkit spaeter, spart Install-Zeit. |
| Viewport | **Desktop 1280×800** standard | Mobile-Viewports spaeter als eigenes Project. |

---

## 2. Verzeichnisstruktur

```
tests/e2e/
├── package.json                  # Playwright-Deps
├── playwright.config.ts          # 2 Projects: headed (default), headless
├── tsconfig.json
├── fixtures/
│   ├── seed.ts                   # DB-Seed via PHP-CLI-Skript aufrufen
│   ├── reset-db.ts               # TRUNCATE zwischen Suites
│   ├── mailpit-client.ts         # TS-Port von tests/Support/MailPitClient.php
│   └── users.ts                  # Seed-User-Liste + Login-Helper
├── pages/                        # Page-Object-Modelle
│   ├── LoginPage.ts
│   ├── DashboardPage.ts
│   ├── WorkEntryEditPage.ts
│   ├── EventEditPage.ts
│   └── ...
└── specs/
    ├── 01-auth.spec.ts           # I1
    ├── 02-antrag-workflow.spec.ts # I2
    ├── 03-admin.spec.ts           # I3
    ├── 04-event-flow.spec.ts      # I4
    └── 05-multitab.spec.ts        # I5

scripts/
└── setup-e2e-db.php              # Neu — basierend auf setup-test-db.php
```

---

## 3. Inkremente

### 3.1 Inkrement 1 (I1) — Infrastruktur + Smoke-Test

**Ziel:** Ein einziger gruener Login-Test, alles drumherum funktioniert reproduzierbar.

**Lieferumfang:**
- `tests/e2e/package.json` mit `@playwright/test` ^1.50 als einzige Laufzeit-Dep.
- `playwright.config.ts` mit:
  - `webServer: { command: 'php -S localhost:8001 -t src/public', cwd: '../..', reuseExistingServer: !process.env.CI }`
  - `projects: [{ name: 'headed', use: { headless: false, slowMo: 250 }}, { name: 'headless', use: { headless: true }}]`
  - Default-Project: `headed`.
  - Trace: `retain-on-failure`.
- `scripts/setup-e2e-db.php` — liest Credentials aus `config/config.php` (oder separate `config.e2e.php`), dropt + recreated `helferstunden_e2e`, spielt Schema + Migrationen ein, seeded 5 User via direkter SQL (Passwort-Hash bcrypt cost=4 fuer Speed).
- `fixtures/seed.ts` ruft vor jeder Suite `php scripts/setup-e2e-db.php` auf.
- `fixtures/reset-db.ts` macht `TRUNCATE` der Business-Tabellen zwischen Tests (User + Rollen bleiben).
- `pages/LoginPage.ts` — erstes Page-Object.

**Tests (3):**
1. `login_mit_richtigen_credentials_fuehrt_auf_dashboard`
2. `login_mit_falschem_passwort_zeigt_fehler`
3. `logout_leitet_auf_login_zurueck_und_session_ist_tot`

**Akzeptanzkriterium:** `npm run e2e:headed` zeigt 3 gruene Tests im sichtbaren Chromium, Dauer unter 30s.

**Commit:** `feat(tests): Modul 8 I1 - Playwright-E2E-Infrastruktur + Login-Smoke`

---

### 3.2 Inkrement 2 (I2) — Antrag-Workflow

**Ziel:** Kompletter Lebenszyklus eines Helferstunden-Antrags, zwei Rollen.

**Tests (8):**
1. `mitglied_legt_entwurf_an_und_speichert` (Kategorie + Stunden + Beschreibung)
2. `mitglied_reicht_entwurf_ein_status_wechselt_auf_eingereicht`
3. `pruefer_sieht_eingereichten_antrag_in_reviewliste`
4. `pruefer_kann_nicht_eigenen_antrag_freigeben` — Selbstgenehmigungs-Buttons sind NICHT sichtbar
5. `pruefer_gibt_antrag_frei_dialog_bleibt_sichtbar`
6. `pruefer_stellt_rueckfrage_antrag_wechselt_auf_in_klaerung_mitglied_sieht_dialog`
7. `mitglied_antwortet_im_dialog_und_reicht_erneut_ein`
8. `pruefer_lehnt_ab_mit_begruendung_im_dialog`

**Pages:** `WorkEntryListPage`, `WorkEntryCreatePage`, `WorkEntryEditPage`, `WorkEntryShowPage` (inkl. Dialog-Partial), `ReviewListPage`.

**Commit:** `feat(tests): Modul 8 I2 - E2E Antrag-Workflow (Mitglied + Pruefer)`

---

### 3.3 Inkrement 3 (I3) — Admin-Workflows

**Ziel:** Admin-Bereich validieren, besonders Mitglied-Anlage + CSV-Import + Audit-Sicht.

**Tests (6):**
1. `admin_legt_neues_mitglied_an_und_einladungsmail_landet_in_mailpit`
2. `neues_mitglied_setzt_passwort_ueber_einladungslink_und_kann_sich_anmelden`
3. `admin_legt_kategorie_an_und_deaktiviert_sie_dropdown_verschwindet`
4. `admin_setzt_sollstunden_fuer_mitglied_und_dashboard_zeigt_fortschritt`
5. `admin_aendert_rolle_auf_pruefer_neuer_pruefer_sieht_review_seite`
6. `audit_zeigt_status_change_eintrag_mit_old_und_new_values`

**Pages:** `AdminUsersPage`, `AdminCategoriesPage`, `AdminTargetsPage`, `AdminAuditPage`, `SetupPasswordPage`.

**MailPit-Bedarf:** `GET /api/v1/messages` → finde Einladung an neue E-Mail, extrahiere Setup-Link-Token, Playwright navigiert dahin.

**Commit:** `feat(tests): Modul 8 I3 - E2E Admin-Flows (Mitglied + Kategorie + Audit)`

---

### 3.4 Inkrement 4 (I4) — Event-Komplettflow

**Ziel:** Modul 6 im echten Browser von Template bis Helferstunden-Auto-Generierung.

**Tests (6):**
1. `event_admin_legt_template_an_mit_zwei_tasks`
2. `event_admin_leitet_event_aus_template_ab_tasks_werden_kopiert`
3. `event_admin_veroeffentlicht_event_reminder_jobs_sind_geplant` (prueft via Admin-Audit oder DB-Query)
4. `mitglied_meldet_sich_fuer_task_an_kapazitaet_1_zweites_mitglied_wird_abgewiesen`
5. `organisator_genehmigt_zeiteintrag_helferstunden_antrag_wird_erzeugt`
6. `event_admin_schliesst_event_ab_mitgliedsdashboard_zeigt_freigegebenen_helferstunden_antrag`

**Pages:** `EventTemplateListPage`, `EventTemplateEditPage`, `EventAdminListPage`, `EventAdminEditPage`, `EventMemberShowPage`, `OrganizerEventsPage`, `MyEventsPage`.

**Commit:** `feat(tests): Modul 8 I4 - E2E Event-Komplettflow (Template -> Abschluss)`

---

### 3.5 Inkrement 8 (I8) — Multitab / Multisession

> **Hinweis:** Urspruenglich als I5 geplant. Weil sich in der Umsetzung drei kleinere Inkremente dazwischengeschoben haben (I5 Event-Storno, I6 variabler Slot-Approval, I7 Spec-04-Haertung — siehe §3.6), ist dieser Multitab-Teil am Ende als I8 gelandet.

**Ziel:** Modul 7 in der Praxis zeigen. Zwei `BrowserContext`-Instanzen = zwei unabhaengige Sessions (zwei User gleichzeitig) oder zwei Tabs innerhalb derselben Session.

**Tests (5):**

1. **Pessimistic Lock (Modul 7 I1):**
   - Alice oeffnet `/entries/42/edit` → Lock-Heartbeat startet.
   - Alice oeffnet im 2. Tab `/entries/42/edit` → Seite zeigt "gesperrt durch Alice" (UX-String pruefen).
   - Alice schliesst Tab 1 → nach `release`-Call wird Tab 2 editierbar.

2. **BroadcastChannel (Modul 7 I2):**
   - Alice hat Dashboard in Tab A offen, Tab B auch.
   - In Tab B reicht Alice einen neuen Antrag ein.
   - Tab A aktualisiert den Antrag-Count sichtbar ohne Polling-Wartezeit.

3. **Cross-Session Dialog-Badge:**
   - Alice Tab A auf Dashboard.
   - Pruefer (zweiter BrowserContext, zweite Session) stellt Rueckfrage zu Alices Antrag.
   - Alice Tab A zeigt binnen 60s (AJAX-Polling, nicht BroadcastChannel) das Badge hochzaehlen.

4. **Optimistic Lock + Conflict-Diff-UI (Modul 7 I3/I4):**
   - Event-Admin oeffnet `/admin/events/7/edit` in Tab A **und** Tab B.
   - Tab A aendert Titel auf "Titel A", speichert. Erfolgsmeldung.
   - Tab B aendert Titel auf "Titel B", speichert.
   - Tab B zeigt die Conflict-Diff-UI mit Tabelle "Dein Stand: Titel B / DB-Stand: Titel A". Formular ist mit "Titel A" vorbelegt.
   - Tab B uebernimmt "Titel B", speichert erneut → Erfolg.

5. **Zwei Mitglieder, selber Task, Capacity=1:**
   - Alice (Context 1) und Bob (Context 2) oeffnen gleichzeitig denselben Task.
   - Beide klicken "Anmelden" fast gleichzeitig (Playwright `Promise.all`).
   - Genau einer bekommt Erfolg, der andere bekommt "Task ist bereits vergeben"-Fehler.

**Alle I5-Tests laufen zwingend headed** — der User hat das explizit gewuenscht, und Fehlersuche ist visuell einfacher.

**Commit:** `feat(tests): Modul 8 I8 - E2E Multitab/Multisession (Modul 7 in action)`

---

### 3.6 Nachtraegliche Ergaenzungen (I5–I7)

Waehrend der Umsetzung sind drei Inkremente dazugekommen, die im urspruenglichen Plan nicht vorgesehen waren. Sie fuellen Luecken, die beim Durchspielen von I4 sichtbar wurden.

**I5 — Event-Storno mit Ersatz-Vorschlag** (Commit `d7e133e`)
Deckt den Flow ab, wenn ein Mitglied eine uebernommene Aufgabe zurueckgibt und dabei einen Ersatz-Kandidaten vorschlaegt. Vier serielle Tests in `06-event-cancel.spec.ts`: Uebernahme, Storno mit Ersatz, Review-Queue-Darstellung beim Event-Admin, Genehmigung + Sichtbarkeit beim Mitglied.

**I6 — Organizer-Entscheidung bei `slot_mode=variabel`** (Commit `d7e133e`, gleicher Commit wie I5)
Mitglied schlaegt ein Zeitfenster vor, Event-Admin entscheidet. Vier serielle Tests in `07-event-variable-slot.spec.ts`. Neue POM-Methoden `MemberEventsPage.assignToTaskWithProposal()` und `OrganizerEventsPage.expectTimeReview()`.

**I7 — Spec-04-Haertung: Status-Badge + Origin-Check** (Commit `c9fabe7`)
Zwei zusaetzliche Assertions im Event-Komplettflow-Spec — Badge "Eingereicht" in der Listenzeile und Text "Automatisch erzeugt aus Event" im Detail-Body. Urspruenglich als eigene Spec `08-event-completion.spec.ts` begonnen und als Duplikat zu Spec 04 Test 5 verworfen; die zwei ergaenzenden Checks sind daraus direkt in Spec 04 gewandert. Die Lessons-Learned daraus haben ihren Weg in `tester.md` (G7-E2E-Hygiene) gefunden.

---

## 4. `.gitignore`-Ergaenzungen

```
# Modul 8: Playwright
/tests/e2e/node_modules/
/tests/e2e/test-results/
/tests/e2e/playwright-report/
/tests/e2e/blob-report/
/tests/e2e/.playwright-cache/
```

---

## 5. Offene Punkte fuer nach Modul 8

Bewusst **nicht** Teil von I1–I5:

- **Mobile-Viewport-Project** (iPhone SE, iPhone 14) — eigenes Playwright-Project nach I5.
- **Firefox + WebKit** — erst wenn Chromium-Suite stabil.
- **CI-Integration** (GitHub Actions) — das Testprotokoll skizziert es, die Umsetzung wartet auf GitHub-Runner-Budget.
- **Visuelle Regression** (Screenshot-Vergleich) — hoher Wartungsaufwand, spaeter pruefen.
- **Accessibility-Tests** (axe-core) — eigener Schritt, nicht vermischt mit Workflow-Tests.
- **Performance-Audits** (Lighthouse) — irrelevant fuer Strato-Vereinsseite.
- **2FA-TOTP-Flow** — ein gezielter Micro-Test mit Node-TOTP-Library, wenn noetig.

---

## 6. Status-Tabelle

| Inkrement | Status | Commit |
|-----------|--------|--------|
| I1 — Infrastruktur + Login-Smoke | umgesetzt | `78c67e5` |
| I2 — Antrag-Workflow | umgesetzt | `b02269f` |
| I3 — Admin-Flows | umgesetzt | `9f649a0` |
| I4 — Event-Komplettflow | umgesetzt | `1194ede` |
| I5 — Event-Storno + Ersatz-Vorschlag (Plan-Ergaenzung) | umgesetzt | `d7e133e` |
| I6 — Organizer-Entscheidung `slot_mode=variabel` (Plan-Ergaenzung) | umgesetzt | `d7e133e` |
| I7 — Spec-04-Haertung (Status-Badge + Origin) (Plan-Ergaenzung) | umgesetzt | `c9fabe7` |
| I8 — Multitab / Multisession (urspr. I5 im Plan) | umgesetzt | `5b07242` |

---

*Letzte Aktualisierung: 2026-04-21 — Modul 8 abgeschlossen, Plan an Umsetzung angeglichen.*
