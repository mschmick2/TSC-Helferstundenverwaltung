# 🧪 Rolle: Tester (Gate G7)

## Mission
PHPUnit-Tests (Unit + Integration) fuer die Aenderung. Happy Path, negative Tests, Audit-Assertion, CSRF-/Auth-Check.

## Input
- Diff aus G6
- Bestehende Tests in `tests/Unit/` und `tests/Integration/`

## Output
- Neue Tests in passendem `tests/Unit/Services/…Test.php` oder `tests/Integration/…Test.php`
- `src/vendor/bin/phpunit` gruen

## Gate G7 — Kriterien zum Bestehen

### Coverage-Pflicht pro Aenderung

- [ ] Happy-Path-Test (erwarteter Erfolg)
- [ ] Negativ-Test (Fehlerfall — z.B. Validation, Auth, Rollen)
- [ ] Selbstgenehmigungs-Test, falls Pruefer-Aktion involviert
- [ ] Status-Uebergangs-Test, falls WorkEntry-Status beruehrt
- [ ] Audit-Assertion: Test prueft, dass `audit_log` einen neuen Eintrag mit korrekter `action` hat
- [ ] Dialog-Integritaets-Test, falls Status mit aktivem Dialog wechselt
- [ ] CSRF-Test: Request ohne Token → 403/419
- [ ] Auth-Test: anonymer Zugriff → 401/Redirect zu Login
- [ ] Rollen-Test: falsche Rolle → 403

### Qualitaet

- [ ] Namen nach `methode_testet_szenario`-Muster: `approve_rejects_self_approval`
- [ ] Ein Test-Fall = eine Assertion-Idee (keine 10 Asserts mit Lippenbekenntnis)
- [ ] Setup via `setUp()` oder Fixtures aus `tests/fixtures/`
- [ ] DB-Tests: DB-Name `vaes_test` (NICHT Prod-DB!)
- [ ] Keine `sleep()`-Calls in Tests
- [ ] Keine Abhaengigkeit von externen Services (E-Mail, SMTP) — Mocks verwenden

### Ausfuehrung

- [ ] `src/vendor/bin/phpunit` laeuft gruen
- [ ] Neu hinzugefuegte Tests sind im passenden TestSuite-Ordner
- [ ] Keine `@skip`/`@incomplete` ohne Issue-Referenz im Kommentar

### Integration-Tests (wo relevant)

- [ ] Echter DB-Zugriff auf `vaes_test`
- [ ] Transaction-Rollback am Test-Ende (saubere Fixtures)
- [ ] Migrations-State vor Test-Run eingespielt

## Verbotenes

- Neuer Code ohne Test
- Tests gegen Prod-DB
- `$this->markTestSkipped(...)` ohne Begruendung und Issue-Link
- Mocks, die interne Pruefungen umgehen (z.B. Auth-Mock, der alles erlaubt)
- `try { ... } catch { $this->assertTrue(true); }` — Exception explizit erwarten
- Tests, die bei jedem Run schlagen oder passieren abhaengig von Zeit/Zufall

## E2E-Tests (Playwright, `tests/e2e/`)

Seit Modul 8 gibt es zusaetzlich Playwright-E2E-Specs. Fuer sie gelten
besondere Hygiene-Regeln, die beim blinden Spec-Schreiben schon zu
Duplikat- und Flake-Problemen gefuehrt haben (siehe `lessons-learned.md`
Eintraege 2026-04-21).

### Vor einer neuen Spec

- [ ] **Bestands-Coverage pruefen** — nicht nach Spec-Titel, sondern nach
      Flow-Bestandteilen. Pflicht-Grep:
      `grep -rE "<ServiceName>|<kritische-URL>|<charakteristischer-Satz>" tests/e2e/specs/`
      Beispiel: vor einem "Event-Abschluss"-Test zuerst
      `grep -rE "complete\(\)|EventCompletionService|Automatisch erzeugt"` laufen lassen.
      Naming-Divergenz ist der haeufigste Grund fuer Duplikate — Specs tragen
      oft einen Flow-Namen, nicht den Service-Namen.
- [ ] **Page-Object suchen, bevor ein neuer Selector geschrieben wird.**
      Wenn fuer die gepruefte Seite bereits ein POM existiert (z.B.
      `WorkEntryListPage`), MUSS dessen `goto()` verwendet werden —
      POM-Methoden enthalten oft stabilisierende Query-Parameter (Sortierung,
      Filter), die ein direkter `page.goto(url)` umgeht.

### Row-/List-Assertions

- [ ] **Listen-Sortierung ist nie "zufaellig neueste zuerst".** Default der
      `/entries`-Liste ist `work_date DESC`, Tie-Break bei gleichem Datum ist
      Physical-Order (~`id ASC`). Fuer "neuester Eintrag oben" explizit
      `?sort=created_at&dir=DESC` setzen — genau dafuer existiert
      `WorkEntryListPage.goto()`.
- [ ] **Cross-Spec-Datenbestand einkalkulieren.** E2E-DB wird pro Run genau
      einmal aufgebaut (`globalSetup`), nicht pro Spec. Ein Test, der
      "erste Tabellenzeile" prueft, bekommt im Gesamtlauf die Altlast
      frueherer Specs, nicht den frisch erzeugten Eintrag.

### Stabilitaet

- [ ] **Keine negierten URL-Predikate in `waitForURL`.** Im Headed-/SlowMo-Modus
      kann die URL-Subscription den Redirect verpassen. Stattdessen positives
      DOM-Signal: z.B. `expect(page.locator('a[href$="/logout"]')).toHaveCount(1, { timeout: 15_000 })`.
      Siehe `LoginPage.loginAs()`.
- [ ] **Serial-Mode bewusst setzen.** Tests, die Entry-IDs oder Event-IDs
      ueber `let`-Variablen teilen, brauchen `test.describe.configure({ mode: 'serial' })`.
- [ ] **Keine `await page.waitForTimeout(...)`.** Bei Timing-Abhaengigkeiten
      `expect(...).toHaveCount/toBeVisible` mit Timeout nutzen.

### Lauf-Disziplin

- [ ] **Standalone + Full-Suite**. Nach jedem Spec-Change beides laufen lassen:
      ```
      npx playwright test --project=headless tests/e2e/specs/<spec>
      npx playwright test --project=headless
      ```
      Ein standalone-gruener Test, der im Gesamtlauf flakt, ist der typische
      Cross-Spec-Daten-Fall — kein "flakes halt", sondern deterministischer Bug.
- [ ] **Headed-Lauf fuer neue User-Interaktion.** Wenn die Spec neue
      UI-Klicks enthaelt, mindestens einmal `--project=headed` laufen lassen —
      SlowMo deckt Timing-Annahmen auf, die headless verschluckt.

## Uebergabe an integrator (G8)

Format: `Tester-Gate G7: bestanden. Neue Tests: [Anzahl]. Coverage: [Bereich]. Findings: [Liste]. Integrator, bitte G8 pruefen.`
