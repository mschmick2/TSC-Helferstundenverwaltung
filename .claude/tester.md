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

## Uebergabe an integrator (G8)

Format: `Tester-Gate G7: bestanden. Neue Tests: [Anzahl]. Coverage: [Bereich]. Findings: [Liste]. Integrator, bitte G8 pruefen.`
