# VAES — Master-Config fuer Claude Code

> **Zweck:** Vereins-Arbeitsstunden-Erfassungssystem (Helferstundenverwaltung) fuer TSC Mondial e.V.
> **Diese Datei wird bei jeder Session zuerst geladen. Halte sie kompakt.**

---

## 1. Quick Reference

| Aspekt | Wert |
|--------|------|
| **Projektname** | VAES — Vereins-Arbeitsstunden-Erfassungssystem |
| **Sprache / Runtime** | PHP 8.1+ (Ziel 8.3) |
| **Framework** | Slim 4 (Micro-Framework) + PHP-DI 7 |
| **Architektur** | MVC + Repository/Service-Pattern |
| **Datenbank** | MySQL 8.4, PDO + Prepared Statements |
| **Frontend** | Bootstrap 5, Vanilla JS (ES6+), Fetch API |
| **Hosting** | Strato Shared Webhosting (Apache) |
| **Deployment** | FTP/SFTP — KEIN SSH, KEINE Cron-Jobs, KEIN Node.js |
| **Lokale Dev** | `cd src/public && php -S localhost:8000` |
| **Tests** | PHPUnit 10.5 (`src/vendor/bin/phpunit`) |
| **Git-Workflow** | Feature-Branches → main, Conventional Commits Pflicht |
| **Repo** | https://github.com/mschmick2/TSC-Helferstundenverwaltung |
| **Version** | 1.4.0 |

---

## 2. Projekt-Pfade

```
E:\TSC-Helferstundenverwaltung\
├── CLAUDE.md                         # Diese Datei (Master)
├── README.md
├── .gitignore
│
├── .claude/                          # Harness
│   ├── CLAUDE.md                     # Stack-Supplement
│   ├── settings.local.json           # Permissions
│   ├── lessons-learned.md            # Wachsendes Wissensarchiv
│   ├── architect.md … dokumentar.md  # Gates G1-G9
│   ├── roles/                        # Legacy (bleibt fuer Referenz)
│   ├── agents/                       # Deep-Dive-Rollen
│   │   ├── developer.md
│   │   ├── reviewer.md
│   │   └── security.md
│   └── rules/                        # On-demand-Regeln
│       ├── 01-security.md
│       ├── 02-dsgvo.md
│       ├── 03-framework.md
│       ├── 04-database.md
│       ├── 05-frontend.md
│       ├── 06-git.md
│       ├── 07-audit.md
│       └── 08-ux-layout.md
│
├── docs/
│   ├── REQUIREMENTS.md               # Anforderungsspezifikation v1.3
│   ├── ARCHITECTURE.md
│   ├── Benutzerhandbuch.md
│   ├── Funktionsbeschreibung.md
│   └── Pflichtenheft_VAES_v1.3.docx
│
├── scripts/database/                 # create_database.sql, Migrationen
├── tests/                            # PHPUnit (Unit/ + Integration/)
└── src/
    ├── composer.json
    ├── vendor/
    ├── config/                       # config.php (NICHT im Git)
    ├── public/                       # Web-Root (index.php, .htaccess, css/, js/)
    ├── storage/                      # Logs, Cache
    └── app/                          # NEUER Code
        ├── Controllers/
        ├── Services/
        ├── Repositories/
        ├── Models/
        ├── Middleware/
        ├── Helpers/
        ├── Exceptions/
        └── Views/
```

---

## 3. Top 10 Regeln (immer aktiv)

1. **Prepared Statements** fuer JEDEN DB-Zugriff (PDO, named params). Niemals Konkatenation.
2. **Output-Escaping** mit `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` bei jeder User-Daten-Ausgabe.
3. **CSRF-Token** auf allen POST/PUT/DELETE via `CsrfMiddleware`. `hash_equals()` zum Vergleich.
4. **Auth-Check** am Anfang jeder geschuetzten Route (`AuthMiddleware` + `RoleMiddleware`).
5. **Soft-Delete** statt Hard-Delete (`deleted_at` + `deleted_by`). NIE physisch loeschen.
6. **Audit-Log ist APPEND-ONLY** — DB-Trigger verhindern UPDATE/DELETE. Jede Aenderung wird protokolliert.
7. **KEINE Selbstgenehmigung** — Pruefer/Admin darf eigene Antraege niemals selbst freigeben/ablehnen/rueckfragen.
8. **Dialog-Integritaet** — Bei Statusaenderungen bleibt der komplette Dialog-Verlauf erhalten, auch bei "Zurueck zur Ueberarbeitung" oder Reaktivierung.
9. **Secrets** in `src/config/config.php` (nicht im Git, `.gitignore`). Zugangsdaten NIEMALS committen.
10. **Strato-Kompatibilitaet** — Kein SSH, keine Cron-Jobs, kein Node.js, keine Shell-Execs in Produktion.

---

## 4. 9-Rollen-Pipeline

Jede Aenderung durchlaeuft diese Gates. Handoff-Format: `[Gate] G[N]: [bestanden/blockiert]. Findings: [Liste]. [Next], bitte G[N+1] pruefen.`

| Gate | Rolle | Datei | Skippable? |
|------|-------|-------|------------|
| G1 | Architect | `.claude/architect.md` | Nein |
| G2 | Coder | `.claude/coder.md` | Nein |
| G3 | Reviewer | `.claude/reviewer.md` | Nein |
| G3.5 | Layout (UX/UI) | `.claude/layout.md` | Ja — nur bei UI-Aenderung |
| G4 | Security | `.claude/security.md` | Nein |
| G5 | DSGVO | `.claude/dsgvo.md` | Ja — nur wenn PII beruehrt |
| G6 | Auditor | `.claude/auditor.md` | Nein |
| G7 | Tester | `.claude/tester.md` | Nein |
| G8 | Integrator | `.claude/integrator.md` | Nein |
| G9 | Dokumentar | `.claude/dokumentar.md` | Nein |

Skips IMMER im Commit dokumentieren: `Skips: G3.5 uebersprungen — keine UI-Aenderung.`

---

## 5. Rules nachladen — wann was?

Nicht alle Rules auf einmal laden. Kontext-Budget schonen.

| Rule | Primaergate | Sekundaer |
|------|-------------|-----------|
| `01-security.md` | G4 (security) | G2 (coder) bei User-Input |
| `02-dsgvo.md` | G5 (dsgvo) | G1 (architect) bei neuen PII-Feldern |
| `03-framework.md` | G2 (coder) | G1 (architect) bei neuen Routes/Klassen |
| `04-database.md` | G2 (coder) | G1 (architect) bei Schema-Aenderung |
| `05-frontend.md` | G2 (coder) | G3 (reviewer) bei UI-Code |
| `06-git.md` | G9 (dokumentar) | G8 (integrator) beim Deploy |
| `07-audit.md` | G6 (auditor) | G2 (coder) bei Audit-Log-Aufrufen |
| `08-ux-layout.md` | G3.5 (layout) | G2 (coder) bei UI-Code |

---

## 6. Domain-Lifecycle — WorkEntry (6 Status)

```
           [ entwurf ] ←─────┐
                │             │
           einreichen         │ zurueck_zur_ueberarbeitung
                │             │
                v             │
         [ eingereicht ] ─────┤
                │             │
     ┌──────────┼────────┐    │
rueckfrage  freigeben  ablehnen
     │          │        │    │
     v          v        v    │
[in_klaerung]  │    [abgelehnt]
     │          │
     ├──────────┤
     v          v
  [ freigegeben ]   [ storniert ] ──┐
     (Endstatus)                     │
                                  reaktivieren
                                     v
                                [ entwurf ]
```

**Endzustaende:** `freigegeben`, `abgelehnt`. `storniert` kann zu `entwurf` reaktiviert werden.
**Korrektur nach Freigabe:** Pruefer/Admin koennen freigegebene Antraege mit Begruendung korrigieren (Audit-Trail-pflichtig).

Erlaubte Uebergaenge pro Status siehe `src/app/Services/WorkflowService.php`.

---

## 7. Verbotene Befehle

- `git push --force` auf `main`
- `git commit --no-verify`
- `DELETE FROM audit_log` / `UPDATE audit_log` (DB-Trigger blockiert dies)
- `DELETE FROM work_entries` (Soft-Delete verwenden)
- Credentials im Repo committen (siehe Incident 273de47)
- Node.js / Cron / Shell-Execs in Produktion (Strato unterstuetzt das nicht)

---

## 8. Bekannte Sicherheitsschuld

| Nr | Thema | Status |
|----|-------|--------|
| 1 | Session-Cookie: `Secure` + `SameSite=Strict` in Produktion pruefen | offen |
| 2 | Rate-Limiting fuer Passwort-Reset-Endpunkt pruefen | offen |
| 3 | CSP-Header pruefen (Strato `.htaccess`) | offen |

Neue Eintraege bei G4-Findings hier ergaenzen.

---

## 9. Rollen-Agents (Deep-Dive)

Ergaenzen die Gates um technische Detailtiefe:

- `.claude/agents/developer.md` — PHP 8.x / Slim 4 / PSR-12 Coding-Standards
- `.claude/agents/reviewer.md` — Detaillierte Review-Checkliste
- `.claude/agents/security.md` — OWASP-Threat-Model PHP-spezifisch

Die Legacy-Rollen unter `.claude/roles/` bleiben als Referenz, werden aber nicht mehr aktiv geladen. Neue Arbeit orientiert sich an `.claude/agents/`.

---

## Bei /compact BEHALTEN (Absolute Essentials)

1. VAES = Helferstunden-Verein-System, PHP 8.1+/Slim 4/MySQL 8.4/Bootstrap 5, Strato Shared Hosting.
2. Neue Code-Heimat: `src/app/` mit PSR-4 (`App\*`). Views in `src/app/Views/`.
3. Audit-Log ist APPEND-ONLY (DB-Trigger). Jede Business-Aenderung protokolliert via `AuditService::log()`.
4. KEINE Selbstgenehmigung — Pruefer darf eigene Antraege nicht freigeben/ablehnen/rueckfragen.
5. Soft-Delete via `deleted_at` + `deleted_by`, niemals physisches DELETE.
6. Dialog-Verlauf bleibt bei allen Statuswechseln vollstaendig erhalten.
7. Jeder Change durchlaeuft 9 Gates (G1-G9), Skips im Commit dokumentiert.
8. Strato-Grenzen: kein SSH, kein Cron, kein Node in Produktion. Deployment via FTP.

---

*Letzte Aktualisierung: 2026-04-17 — Harness-Migration v2*
