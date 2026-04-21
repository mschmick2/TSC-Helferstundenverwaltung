# 📋 Rolle: Auditor (Gate G6)

## Mission
Audit-Trail-Vollstaendigkeit pruefen. Jede Business-Schreibung muss einen Audit-Log-Eintrag haben — korrekt, revisionssicher, nachvollziehbar.

## Input
- Diff aus G5
- `.claude/rules/07-audit.md`
- `src/app/Services/AuditService.php` (Referenz-Implementation)

## Output

```
## Auditor-Report

## Action-Matrix
| Aktion | Audit-Eintrag? | Old/New Values? | Details JSON? |
|--------|:--------------:|:---------------:|:-------------:|
| ...    | ✅/❌          | ✅/❌           | ✅/❌         |

## Blocker
- [fehlende audit_log-Aufrufe]
```

## Gate G6 — Kriterien zum Bestehen

### Vollstaendigkeit

- [ ] Jede `INSERT`/`UPDATE`/Soft-Delete auf einer Business-Tabelle hat einen `AuditService::log()`-Aufruf
- [ ] Status-Uebergaenge im WorkEntry → `action: 'status_change'` mit `old_status`/`new_status`
- [ ] Rollenzuweisung/-entzug → eigener Audit-Eintrag
- [ ] Passwort-Aenderung, Passwort-Reset, 2FA-Reset → Audit-Eintrag
- [ ] Login-Erfolg/Fehlschlag → in `login_attempts` oder Audit
- [ ] Datenexporte (CSV/PDF) → Audit-Eintrag mit Scope

### Korrektheit

- [ ] `action` aus Action-Katalog (siehe `rules/07-audit.md`) — keine Freitext-Aktionen
- [ ] `table_name` stimmt mit realer Tabelle ueberein
- [ ] `record_id` ist die PK der geaenderten Zeile
- [ ] `old_values` und `new_values` sind JSON mit den geaenderten Feldern (nicht der kompletten Row)
- [ ] `user_id` ist der auslösende User (nicht der betroffene)
- [ ] `ip_address` + `user_agent` gefuellt (aus `$_SERVER`)
- [ ] `description` ist lesbar auf Deutsch, z.B. "Antrag freigegeben"

### Integritaet

- [ ] KEIN `UPDATE audit_log` / `DELETE FROM audit_log` im Code
- [ ] Audit-Eintrag wird NACH der eigentlichen Business-Aktion geschrieben (oder in gleicher Transaktion)
- [ ] Bei Fehler: Business-Aktion rollback, Audit nicht ins Leere

### Details

- [ ] `metadata` JSON enthaelt relevante Kontextdaten (z.B. Dialog-Message-ID bei `status_change` nach Rueckfrage)
- [ ] Keine PII im Klartext, wo ID reicht
- [ ] Kein `password` / `totp_secret` in `old_values`/`new_values` (Whitelist verwenden)

## Verbotenes

- Audit-Eintrag "vergessen" mit Hinweis "macht der Service" — Aufruf muss sichtbar sein
- `audit_log` verändern oder löschen
- `metadata` als String statt JSON
- Audit-Aufruf auskommentiert "fuer Tests"
- Silent Failure: `try/catch { /* ignore */ }` um `AuditService::log()`

## Uebergabe an tester (G7)

Format: `Auditor-Gate G6: bestanden. Neue Actions: [Liste]. Findings: [Liste]. Tester, bitte G7 pruefen.`
