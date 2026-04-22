# Harness-Template — Portierung der VAES-Arbeitsstruktur auf ein neues Projekt

> **Stand:** 2026-04-22
> **Quelle:** VAES (Vereins-Arbeitsstunden-Erfassungssystem), Repo
> `mschmick2/TSC-Helferstundenverwaltung`
>
> Dieses Dokument beschreibt, wie die in VAES gereifte **Claude-Code-Arbeits-
> struktur** auf ein **frisches Projekt ohne Harness** uebertragen wird. Ziel:
> in ≈ 30 Minuten ein neues Repo mit identischer 9-Gate-Pipeline, Agent-Struktur,
> Rules-System und Tools-Ordner aufgesetzt haben.

---

## 1. Was ist der Harness?

Der Harness ist eine Ueberlagerung aus vier Schichten, die in VAES bewusst
voneinander getrennt gehalten werden:

| Schicht | Zweck | Ort |
|---------|-------|-----|
| **Config** | Was Claude **immer** weiss (Stack, Regeln, Verbotenes) | `CLAUDE.md` (root + `.claude/CLAUDE.md`) |
| **Gates** | 9-Stufen-Pipeline von Anforderung bis Commit | `.claude/architect.md` … `.claude/dokumentar.md` |
| **Agents** | Rollen-Deep-Dives fuer Detailtechniken | `.claude/agents/developer.md`, `.../reviewer.md`, `.../security.md` |
| **Rules** | On-demand-Regeln, je nach Gate nachgeladen | `.claude/rules/01-…` bis `08-…` |

Ergaenzend:
- **Lessons Learned** (`.claude/lessons-learned.md`) — wachsende Erkenntnis-
  Datenbank, append-only.
- **Tools** (`tools/`) — projekt-agnostische Entwickler-Tools mit eigenen
  READMEs.
- **Memory** (auto, im Claude-Profile-Verzeichnis) — baut sich pro Session
  selbst auf, greift quer ueber Sessions.

---

## 2. Bootstrap-Sequenz fuer neues Projekt

### 2.1 Verzeichnisstruktur anlegen

Im neuen Projekt-Root:

```
<neues-projekt>/
├── CLAUDE.md                       # Master-Config (Schritt 3)
├── docs/                           # Projektdokumente
│   ├── REQUIREMENTS.md             # Anforderungs-Spezifikation
│   ├── ARCHITECTURE.md             # Architektur-Entscheidungen
│   └── Harness_Template.md         # dieses Dokument (Referenz)
├── tools/                          # Entwickler-Tools
│   └── README.md                   # Inventar
└── .claude/
    ├── CLAUDE.md                   # Stack-Supplement
    ├── lessons-learned.md          # initial leer, waechst
    ├── architect.md                # G1
    ├── coder.md                    # G2
    ├── reviewer.md                 # G3
    ├── layout.md                   # G3.5 (nur bei UI-Projekten)
    ├── security.md                 # G4
    ├── dsgvo.md                    # G5 (nur bei PII-Projekten)
    ├── auditor.md                  # G6
    ├── tester.md                   # G7
    ├── integrator.md               # G8
    ├── dokumentar.md               # G9
    ├── agents/
    │   ├── developer.md
    │   ├── reviewer.md
    │   └── security.md
    └── rules/
        ├── 01-security.md
        ├── 02-dsgvo.md
        ├── 03-framework.md
        ├── 04-database.md
        ├── 05-frontend.md
        ├── 06-git.md
        ├── 07-audit.md
        └── 08-ux-layout.md
```

### 2.2 Dateien uebernehmen

Aus dem VAES-Repo **1:1 kopieren** (danach inhaltlich anpassen, siehe Abschnitt 3):

| Quelle (VAES) | Ziel (neues Projekt) | Adapt-Bedarf |
|---------------|----------------------|--------------|
| `.claude/architect.md` | `.claude/architect.md` | Gering |
| `.claude/coder.md` | `.claude/coder.md` | Gering |
| `.claude/reviewer.md` | `.claude/reviewer.md` | Gering |
| `.claude/security.md` | `.claude/security.md` | Gering |
| `.claude/auditor.md` | `.claude/auditor.md` | Gering |
| `.claude/tester.md` | `.claude/tester.md` | Stack-spezifisch (Test-Commands) |
| `.claude/integrator.md` | `.claude/integrator.md` | Deploy-Weg (FTP vs. Kubernetes …) |
| `.claude/dokumentar.md` | `.claude/dokumentar.md` | Gering |
| `.claude/layout.md` | `.claude/layout.md` | Nur wenn UI-Projekt |
| `.claude/dsgvo.md` | `.claude/dsgvo.md` | Nur wenn PII-Projekt |
| `.claude/agents/developer.md` | `.claude/agents/developer.md` | Stack-spezifisch (PHP → deine Sprache) |
| `.claude/agents/reviewer.md` | `.claude/agents/reviewer.md` | Gering |
| `.claude/agents/security.md` | `.claude/agents/security.md` | Stack-spezifisch (PHP OWASP → …) |
| `.claude/rules/01-security.md` | `.claude/rules/01-security.md` | Mittel — Framework-Konventionen ersetzen |
| `.claude/rules/02-dsgvo.md` | `.claude/rules/02-dsgvo.md` | Hoch — PII-Tabelle projektspezifisch |
| `.claude/rules/03-framework.md` | `.claude/rules/03-framework.md` | Hoch — Framework (Slim → deines) |
| `.claude/rules/04-database.md` | `.claude/rules/04-database.md` | Hoch — DB-System (MySQL → deines) |
| `.claude/rules/05-frontend.md` | `.claude/rules/05-frontend.md` | Hoch (falls Frontend), sonst loeschen |
| `.claude/rules/06-git.md` | `.claude/rules/06-git.md` | Mittel — Deploy-Pfad anpassen |
| `.claude/rules/07-audit.md` | `.claude/rules/07-audit.md` | Hoch — nur wenn Audit-Log-Pflicht |
| `.claude/rules/08-ux-layout.md` | `.claude/rules/08-ux-layout.md` | Nur wenn UI-Projekt |
| `.claude/lessons-learned.md` | `.claude/lessons-learned.md` | Nur Header/Format uebernehmen, Eintraege leeren |
| `tools/README.md` (Template) | `tools/README.md` | Inventar von Null neu aufbauen |

**Nicht kopieren:**
- `.claude/settings.local.json` — das sind Permissions-Snapshots des aktuellen
  Users, projektfremd.
- `.claude/scheduled_tasks.lock` — Laufzeit-State.
- `.claude/roles/` — Legacy aus VAES-Pre-v2, soll im neuen Projekt gar nicht
  erst entstehen.

### 2.3 Root-`CLAUDE.md` anlegen

Das wichtigste Dokument. Kopiere die Struktur aus `CLAUDE.md` (VAES root) —
ersetze danach **jeden** projekt-spezifischen Abschnitt. Details in Abschnitt 3.

### 2.4 Erster Test

Claude Code im neuen Repo oeffnen und sagen: *"Lies CLAUDE.md und erklaere mir,
welche Gates ich durchlaufen muss."* Wenn die Antwort die G1–G9 korrekt
aufzaehlt und auf deinen Stack Bezug nimmt, ist das Grundgeruest okay.

---

## 3. Adaptierungs-Leitfaden pro Datei

Die nachfolgenden Bloecke zeigen, **welche Zeilen** in den kopierten Dateien
projekt-spezifisch ueberschrieben werden muessen. Jeder Block hat eine
`<<TBD:...>>`-Platzhalter-Konvention fuer Stellen, die du anfassen musst.

### 3.1 `CLAUDE.md` (root)

**Ueberschreiben:**
- Section 1 (Quick Reference Table): Projektname, Sprache/Runtime, Framework,
  DB, Frontend, Hosting, Deployment, lokaler Dev-Command, Test-Command, Repo-URL,
  Version.
- Section 2 (Projekt-Pfade): Gesamte Baumstruktur — entspricht deinem neuen
  Repo.
- Section 3 (Top 10 Regeln): Streng projekt-spezifisch. VAES-Regeln
  (Selbstgenehmigung, Audit-append-only, Dialog-Integritaet) sind **Domain-
  Regeln**, nicht generisch. Ersetze sie durch die Invarianten deiner Domain.
  Die universellen Blocker (Prepared Statements, Output-Escaping, CSRF,
  Secrets nicht im Git) kannst du 1:1 uebernehmen.
- Section 4 (9-Rollen-Pipeline): Tabelle anpassen, falls du G3.5/G5 skippen
  willst. Grundskelett bleibt.
- Section 5 (Rules-Tabelle): Entspricht den Dateien in `.claude/rules/`.
- Section 6 (Domain-Lifecycle): Komplett ersetzen. Das VAES-State-Diagramm
  (6 WorkEntry-Status) ist spezifisch.
- Section 7 (Verbotene Befehle): Universelles uebernehmen, projektspezifisches
  ergaenzen (z.B. "kein DELETE FROM users" wenn Soft-Delete-Pflicht).
- Section 8 (Bekannte Sicherheitsschuld): Initial **leer**. Wird bei G4-Findings
  gefuellt.
- Section 9 (Rollen-Agents): Auf deine Agent-Dateien verweisen.
- Section 10 (Berichte in Ingenieurs-Sprache): 1:1 uebernehmen — das ist
  eine Kommunikations-Regel, keine Projekt-Regel.
- Section `/compact BEHALTEN`: Essentials fuer deinen Stack neu formulieren.

### 3.2 `.claude/CLAUDE.md` (Stack-Supplement)

Das VAES-Stack-Supplement ist **PHP/Slim-spezifisch**. Bei anderem Stack:

- Composer-Tabelle → Paketmanager-Tabelle (npm / cargo / poetry …)
- Coding-Standards → Sprache + Community-Standard (PEP8 / ESLint Airbnb …)
- Architektur-Schichten → deines Stacks Layer (Controller/Service/Repo bleibt
  in vielen Stacks analog)
- Services/Repositories-Liste → nach dem ersten G1 leer, fuellt sich

### 3.3 Gate-Dateien (`.claude/*.md` ohne `CLAUDE.md`)

Die Gates sind **im Kern stack-agnostisch**. Die folgenden Stellen enthalten
VAES-Spezifika:

- `coder.md` — Code-Beispiele in PHP. Uebersetze in deine Sprache.
- `tester.md` — Test-Commands (`src/vendor/bin/phpunit`). Ersetze durch dein
  Test-Runner-Kommando.
- `integrator.md` — Deploy-Pfad (FTP/Strato). Beschreibe deinen realen
  Deploy-Weg (Docker, Kubernetes, Vercel …).
- `dsgvo.md` — Nur wenn PII-Daten verarbeitet werden. Sonst loeschen und
  Gate-Tabelle in CLAUDE.md anpassen.
- `layout.md` — Nur bei UI-Projekten. Sonst loeschen.

Die uebrigen Gates (Architect, Reviewer, Security, Auditor, Dokumentar) sind
Prozess-Dateien und benoetigen kaum Aenderung ausserhalb der Beispiel-Snippets.

### 3.4 Agents (`.claude/agents/`)

- `developer.md` — **Komplett** stack-spezifisch. Aus VAES kommt PHP 8 / Slim /
  PSR-12. Neu schreiben fuer deinen Stack.
- `reviewer.md` — Groesstenteils generisch (Code-Review-Checkliste). Beispiel-
  Snippets uebersetzen.
- `security.md` — OWASP-Threat-Model PHP-spezifisch. Fuer Node/Python/Go neu
  schreiben (die Kategorien bleiben, die Beispiele aendern sich).

### 3.5 Rules (`.claude/rules/`)

| Datei | Kopie-Anteil | Anpassungsfokus |
|-------|--------------|-----------------|
| `01-security.md` | ~60 % | DB-Zugriff-Beispiel, Template-Engine, HTTP-Header-Setup |
| `02-dsgvo.md` | ~30 % | Komplett neue PII-Tabelle fuer dein Datenmodell |
| `03-framework.md` | ~20 % | Slim/PHP-DI raus, dein Framework rein |
| `04-database.md` | ~50 % | SQL-Beispiele uebernehmbar, aber DB-spezifische Konventionen (TIMESTAMP vs DATETIME …) pruefen |
| `05-frontend.md` | ~40 % | Bootstrap 5 raus, dein CSS-System rein |
| `06-git.md` | ~80 % | Conventional Commits + Branch-Policy bleiben, Deploy-Schritte anpassen |
| `07-audit.md` | ~50 % | ENUM-Katalog + Mapping-Tabelle muessen zu deinem Audit-Schema passen |
| `08-ux-layout.md` | ~50 % | Geraete-Matrix + Touch-Target-Regeln generisch, Core-Workflows projektspezifisch |

### 3.6 `lessons-learned.md`

**Header + Format-Block 1:1 uebernehmen**, Eintraege loeschen. Das Eintrags-
Format (Kontext / Problem / Loesung / Praevention) ist projekt-unabhaengig
und hat sich bewaehrt.

### 3.7 `tools/` und `tools/README.md`

Komplettes `tools/README.md` als Template nehmen, Inventar auf die Tools
reduzieren, die im neuen Projekt tatsaechlich laufen. Faustregel:

- Mailpit brauchst du bei jedem Projekt, das Mails verschickt.
- UX-Analyzer nur bei Web-/Mobile-Frontends.
- MD→DOCX nur, wenn du ein Benutzerhandbuch aus Markdown in Word liefern musst.

---

## 4. Bootstrap-Skript (PowerShell)

Fuer den mechanischen Teil des Kopiervorgangs. Passe `$Source` und `$Target`
vor dem Lauf an.

```powershell
# bootstrap-harness.ps1
$Source = "E:\TSC-Helferstundenverwaltung"
$Target = "E:\neues-projekt"

$items = @(
    # Gates
    ".claude\architect.md",
    ".claude\coder.md",
    ".claude\reviewer.md",
    ".claude\security.md",
    ".claude\auditor.md",
    ".claude\tester.md",
    ".claude\integrator.md",
    ".claude\dokumentar.md",
    ".claude\layout.md",
    ".claude\dsgvo.md",
    # Agents
    ".claude\agents\developer.md",
    ".claude\agents\reviewer.md",
    ".claude\agents\security.md",
    # Rules
    ".claude\rules\01-security.md",
    ".claude\rules\02-dsgvo.md",
    ".claude\rules\03-framework.md",
    ".claude\rules\04-database.md",
    ".claude\rules\05-frontend.md",
    ".claude\rules\06-git.md",
    ".claude\rules\07-audit.md",
    ".claude\rules\08-ux-layout.md",
    # Template-Level
    "docs\Harness_Template.md"
)

foreach ($rel in $items) {
    $src = Join-Path $Source $rel
    $dst = Join-Path $Target $rel
    $dstDir = Split-Path $dst -Parent
    if (-not (Test-Path $dstDir)) {
        New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
    }
    Copy-Item -Path $src -Destination $dst -Force
    Write-Host "KOPIERT: $rel"
}

# CLAUDE.md + lessons-learned + tools/README NICHT blind kopieren — die sollen
# bewusst neu geschrieben werden. Stattdessen als .template.md ablegen:
Copy-Item "$Source\CLAUDE.md" "$Target\CLAUDE.template.md" -Force
Copy-Item "$Source\.claude\CLAUDE.md" "$Target\.claude\CLAUDE.template.md" -Force
Copy-Item "$Source\.claude\lessons-learned.md" "$Target\.claude\lessons-learned.template.md" -Force
Copy-Item "$Source\tools\README.md" "$Target\tools\README.template.md" -Force
Write-Host ""
Write-Host "CLAUDE.md, .claude\CLAUDE.md, lessons-learned.md und tools\README.md"
Write-Host "wurden als *.template.md abgelegt — jetzt manuell durchgehen und"
Write-Host "umbenennen, sobald projekt-spezifisch ausgefuellt."
```

Aufruf (PowerShell-Admin nicht noetig):

```powershell
powershell -ExecutionPolicy Bypass -File bootstrap-harness.ps1
```

### 4.1 Bootstrap-Skript (Bash/Linux/macOS)

```bash
#!/usr/bin/env bash
# bootstrap-harness.sh
set -euo pipefail

SOURCE="${1:-/path/to/TSC-Helferstundenverwaltung}"
TARGET="${2:-/path/to/neues-projekt}"

ITEMS=(
    .claude/architect.md
    .claude/coder.md
    .claude/reviewer.md
    .claude/security.md
    .claude/auditor.md
    .claude/tester.md
    .claude/integrator.md
    .claude/dokumentar.md
    .claude/layout.md
    .claude/dsgvo.md
    .claude/agents/developer.md
    .claude/agents/reviewer.md
    .claude/agents/security.md
    .claude/rules/01-security.md
    .claude/rules/02-dsgvo.md
    .claude/rules/03-framework.md
    .claude/rules/04-database.md
    .claude/rules/05-frontend.md
    .claude/rules/06-git.md
    .claude/rules/07-audit.md
    .claude/rules/08-ux-layout.md
    docs/Harness_Template.md
)

for rel in "${ITEMS[@]}"; do
    mkdir -p "$(dirname "$TARGET/$rel")"
    cp "$SOURCE/$rel" "$TARGET/$rel"
    echo "KOPIERT: $rel"
done

cp "$SOURCE/CLAUDE.md"                       "$TARGET/CLAUDE.template.md"
cp "$SOURCE/.claude/CLAUDE.md"               "$TARGET/.claude/CLAUDE.template.md"
cp "$SOURCE/.claude/lessons-learned.md"      "$TARGET/.claude/lessons-learned.template.md"
cp "$SOURCE/tools/README.md"                 "$TARGET/tools/README.template.md"

echo
echo "Template-Dateien abgelegt. Jetzt manuell durchgehen."
```

---

## 5. Rolle der Memory-Schicht

Die Memory liegt **ausserhalb** des Repos im Claude-Profile-Verzeichnis
(z.B. `C:\Users\<user>\.claude\projects\<projekt-id>\memory\`). Sie wird
nicht mit dem Repo kopiert — sie baut sich pro Projekt und pro User von
selbst auf.

**Erste Session eines neuen Projekts:** Nichts tun. Claude legt `MEMORY.md`
an, sobald die ersten nicht-trivialen Fakten anfallen.

**Typ-Unterscheidung** (laut CLAUDE-internen Regeln, nicht projekt-spezifisch):
- `user` — Rolle/Praeferenzen des Users
- `feedback` — Korrekturen und validierte Entscheidungen
- `project` — Bugs/Initiativen/Deadlines
- `reference` — Pointer zu externen Systemen oder Repo-internen Dokumenten

---

## 6. Validierungs-Checkliste nach Bootstrap

Bevor du mit echtem Coding startest, gehe diese Checkliste durch:

- [ ] `CLAUDE.md` (root): alle `<<TBD>>`-Platzhalter ersetzt.
- [ ] `.claude/CLAUDE.md`: Stack-Tabelle passt zum Paketmanager-Lockfile.
- [ ] Domain-Lifecycle in `CLAUDE.md`: Diagramm gezeichnet, Uebergaenge benannt.
- [ ] Top-10-Regeln in `CLAUDE.md`: enthalten **deine** Geschaeftsinvarianten,
      nicht die VAES-Standardliste.
- [ ] `.claude/rules/02-dsgvo.md`: PII-Tabelle fuer dein Datenmodell
      aktualisiert (oder Datei geloescht, wenn kein PII).
- [ ] `.claude/rules/07-audit.md`: ENUM-Katalog passt zu deiner audit_log-
      Tabelle (oder Datei geloescht, wenn kein Audit-Log).
- [ ] `.claude/tester.md`: Test-Command funktioniert im neuen Repo.
- [ ] `.claude/integrator.md`: Deploy-Weg beschreibt deinen Ziel-Host.
- [ ] `tools/README.md`: Inventar zeigt nur echt verfuegbare Tools.
- [ ] Claude Code oeffnen, erste Test-Anfrage stellen: *"Durchlauf die Gates
      fuer Hello-World-Endpoint."* — Claude sollte dir plausibel G1–G9 durch
      **deinen** Stack fuehren.

---

## 7. Was der Harness bewusst NICHT regelt

- **CI/CD.** Der Harness ist ein Prozess zwischen Mensch, Claude und Repo. CI
  (GitHub Actions, GitLab CI, Jenkins) ist orthogonal.
- **Ticket-/Projektmanagement.** Jira, Linear, GitHub Issues — nicht Teil des
  Harness.
- **Code-Review durch Menschen.** Die Gates enthalten AI-Reviews, ersetzen
  aber keinen Menschen-Review vor Merge auf `main`.
- **Sprach-/Framework-Tutorials.** Der Harness erwartet, dass du deinen Stack
  kennst. Die Rules geben Konventionen, keine Einfuehrung.

---

## 8. Pflege des Harness

Der Harness ist selbst ein lebendes Artefakt. Empfohlener Zyklus:

- **Nach jeder Session mit Gate-Findings:** in `lessons-learned.md`
  ergaenzen (append).
- **Nach jedem Modul-Abschluss:** `CLAUDE.md` §8 (Bekannte Sicherheitsschuld)
  aktualisieren.
- **Quartalsweise:** Rules-Dateien durchgehen — sind sie noch korrekt?
  Beispiel-Snippets noch gueltige API?
- **Bei Framework-Major-Update:** Agents (`developer.md`, `security.md`) auf
  neue Version anheben.

---

## 9. Credits / Historie

- **Pipeline-Struktur (9 Gates):** Initial-Entwurf im VAES-Projekt,
  2026-04-17 in v2-Harness ueberfuehrt (`.claude/agents/` + `.claude/rules/`).
- **Rules-Split 01–08:** Aus VAES-Modul-Reviews kristallisiert (Module 1–7,
  2026-04-17 bis 2026-04-21).
- **Lessons-Learned-Format:** Uebernommen und gehaertet nach vier konkreten
  Fall-Studien (Secret-Leak, Audit-ENUM-Divergenz, Playwright-baseURL,
  DSGVO-FK-Cascade).
- **Memory-Konvention:** Anthropic-interner Standard fuer Claude-Code-Auto-
  Memory, 2026-Q1.
