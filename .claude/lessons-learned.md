# Lessons Learned — VAES

Wachsende Sammlung von Erkenntnissen, die in diesem Projekt teuer waren. Jeder Eintrag muss in kuenftigen Sessions zugaenglich sein — deshalb kein `git rm`, nur `APPEND`.

## Wann Eintrag anlegen?

- Bug hat > 30 Min gedauert
- Library/Framework verhielt sich unerwartet
- Annahme ueber bestehenden Code war falsch
- Security-Fix, der haette verhindert werden koennen
- Deploy scheiterte an Umgebungs-Differenz

## Wann NICHT?

- Normale Iterationen
- Tippfehler
- Allgemein-Wissen nicht projektbezogen

---

## Eintrags-Format

```
### YYYY-MM-DD — [Kurz-Titel]

**Kontext:**
Was war das Ziel? Welche Rolle/Gate?

**Problem / Ueberraschung:**
Was ging schief? Welche Annahme war falsch?

**Loesung:**
Wie wurde es gefixt? Was war der korrekte Weg?

**Praevention:**
Welche Rule ist zu ergaenzen/updaten?
→ konkreter Hinweis in .claude/rules/XX-...md
```

---

## Eintraege

### 2026-04-17 — Zugangsdaten im Repo (Initialer Eintrag aus Commit 273de47)

**Kontext:**
Bei einer fruehen Commit-Welle wurde `src/config/config.php` versehentlich mit realen Zugangsdaten committet. Der spaetere Fix-Commit `273de47` hat sie entfernt.

**Problem / Ueberraschung:**
`config.php` stand im Git-Tracking, obwohl `.gitignore` sie ausschliessen sollte. `.gitignore` greift nicht rueckwirkend — wenn eine Datei bereits getrackt ist, muss sie mit `git rm --cached` entfernt werden.

**Loesung:**
- `git filter-repo` zum nachtraeglichen Entfernen der Secrets aus der History
- Zugangsdaten rotiert (DB, SMTP)
- `config.example.php` als Template, nur diese gehoert in Git

**Praevention:**
- `.gitignore` beinhaltet `src/config/config.php` (bereits aktiv)
- Integrator-Gate G8 prueft explizit, dass `config.php` nicht im Diff ist
- Dokumentar-Gate G9 prueft "Kein `config.php` / Secrets im Diff"
- Vor `git add -A` IMMER `git status` lesen — nie blind alles stagen
→ Rule `01-security.md` — Section "Secrets / Config"

---

### 2026-04-17 — Rules-Doku divergiert vom DB-Schema (Audit-Log)

**Kontext:**
Im Rahmen der Test-Environment-Pipeline (Gates G2-G8) wurde in `scripts/anonymize-db.sql`
ein `UPDATE audit_log SET description=..., details=NULL, ...` geschrieben. Der Code kam
problemlos durch Syntax-Check und Code-Review (G3). Erst im **G6 Auditor-Gate** fielen
zwei Runtime-Bugs auf:

1. Der DB-Trigger `audit_log_no_update` blockiert JEDES UPDATE auf audit_log mit
   `SIGNAL SQLSTATE '45000'`. Das Script wuerde beim ersten Lauf hart scheitern.
2. Die Spalte `details` existiert im Schema gar nicht — sie heisst `metadata`.

**Problem / Ueberraschung:**
Ich hatte mich beim SQL-Schreiben auf `.claude/rules/07-audit.md` verlassen. Die Rule
dokumentierte einen Action-Katalog mit ~30 Werten (z.B. `entry_approve`, `role_assign`,
`entry_submit`) und ein Feld `details`. Beides war **fiktiv**: Schema-ENUM hat nur 12
Werte, Feld heisst `metadata`.

Die Rule-Doku war offenbar aus einem anderen Projekt uebertragen worden (Harness-
Template), ohne Schema-Abgleich. Syntax-Check + Code-Review konnten das nicht fangen,
weil das SQL syntaktisch valide ist — erst an der Datenbank bricht es.

**Loesung:**
- `UPDATE audit_log` durch `TRUNCATE TABLE audit_log` ersetzt (DDL bypasst Row-Level-
  Trigger; fuer Test-DB ist eine leere Audit-Historie vertretbar).
- `.claude/rules/07-audit.md` komplett an echtes Schema angepasst: ENUM-Katalog,
  Mapping-Tabelle Business-Aktion → action-Wert, Signatur `AuditService::log()` aus
  `src/app/Services/AuditService.php:43-52` uebernommen.
- `.claude/auditor.md`: `details` → `metadata` in Checklisten-Items.
- Neuer Regressions-Test `tests/Unit/Scripts/ScriptInvariantsTest.php` mit 14
  Checks, der kuenftig solche Rueckfaelle automatisch fangen wuerde.

**Praevention:**
Vor jedem SQL/Service-Code, der eine konkrete Tabelle beruehrt, **immer zuerst**
das wahre Schema grep'en:

```
scripts/database/create_database.sql   (Wahrheitsquelle Schema)
src/app/Services/AuditService.php      (Wahrheitsquelle Service-API)
```

Rules in `.claude/rules/` sind **Sekundaerquelle**. Bei Widerspruch: Schema/Code gewinnt,
Rule wird synchron nachgezogen.

→ Kein neuer Rule-Eintrag noetig — die 3 relevanten Rule-/Gate-Dateien sind bereits
auf Schema ausgerichtet. Der neue `ScriptInvariantsTest` sichert die kritischen
Script-Invarianten zusaetzlich strukturell ab.

---

<!-- Neue Eintraege hier unten anfuegen, nicht oben. Append-Only. -->
