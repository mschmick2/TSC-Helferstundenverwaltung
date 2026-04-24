# VAES — Gates, Rollen, Inkremente: Begriffe und Ablauf

> **Zweck:** Referenz-Dokument für alle, die im VAES-Entwicklungsprozess mitarbeiten. Erklärt die Gate-Pipeline, die Rollen, die Nummerierungs-Konventionen und die wiederkehrenden Abkürzungen.
>
> **Zielgruppe:** Projekt-Lead, Coder, Architect, spätere Entwickler, externe Reviewer.
>
> **Pflege:** Bei wesentlichen Prozess-Änderungen aktualisieren.
> **Stand:** 2026-04-24

---

## 1. Das Gesamt-Bild: wie ein Inkrement entsteht

VAES wird in **Inkrementen** gebaut. Jedes Inkrement durchläuft eine **Gate-Pipeline** (G1 bis G9), gegliedert in **Phasen**. Jedes Inkrement endet mit einem **Tag** in Git und einem dokumentierten Stand.

**Vereinfachter Ablauf:**

1. **Projekt-Lead** formuliert die Anforderung.
2. **Architect (G1)** macht einen Plan mit Klärungsfragen und Korrekturen.
3. **Projekt-Lead** bestätigt oder widerspricht.
4. **Coder (G2)** setzt Phase für Phase um.
5. **Sanity-Gate** (G3+G5+G6+G7 gebündelt) prüft breit.
6. **G4 Security-Review** prüft tief auf Sicherheit.
7. **G8 Architect-Final** bewertet das Gesamtergebnis.
8. **G9 Dokumentar** aktualisiert Dokumentation und setzt den Tag.

Nach dem Tag gibt es ggf. **Nach-Tag-Bündel** (kleine Nachbesserungen) oder **Follow-up-Inkremente** (neue, eigene Iterationen für offene Punkte).

---

## 2. Die Gates im Detail

### G1 — Architect-Plan

**Rolle:** Architect.

**Aufgabe:** Aus einer Anforderung einen strukturierten Umsetzungsplan machen. Keine Code-Änderung, reine Konzept-Arbeit.

**Output:**
- Bewertung der Projekt-Lead-Vorentscheidungen (bestätigen, präzisieren, korrigieren).
- Architect-Korrekturen (C-Nummern, siehe Abkürzungen).
- Klärungsfragen (Q-Nummern), die der Projekt-Lead vor Phase-1-Start beantworten muss.
- Risiko-Matrix (R-Nummern) mit Mitigationen.
- Phasen-Plan mit Aufwands-Schätzung.
- Empfehlung: G1-Runde-2 nötig oder nicht?

**Typische Dauer:** 1 Arbeitssitzung des Projekt-Leads mit einer G1-Runde.

### G2 — Coder

**Rolle:** Coder (implementiert Code und Tests).

**Aufgabe:** Den G1-Plan in Code umsetzen, Phase für Phase. Bei Abweichungen vom Prompt diese im Commit-Trailer dokumentieren.

**Output pro Phase:**
- Git-Commit auf dem Feature-Branch.
- Pre-Flight-Bericht (was der Coder im Bestand gefunden hat).
- Liste der Abweichungen vom Prompt mit Begründung.
- Test-Status (PHPUnit, Playwright).
- Übergabe an den nächsten Schritt.

**Typische Phasen eines Feature-Inkrements:**
- Phase 1: Backend (Migration, Model, Repository, Service).
- Phase 2: HTTP-Schicht (Controller, Routes, Views).
- Phase 3: Frontend (JavaScript, CSS).
- Phase 4: End-to-End-Tests, Gates, Tag.

### Sanity-Check-Gate (G3 + G5 + G6 + G7 gebündelt)

**Rolle:** Vier Perspektiven in einem Gate.

**Aufgabe:** Breite, aber nicht tiefe Qualitätsprüfung. Findings, die nachgebessert werden müssen, als Follow-ups dokumentieren.

**Die vier Teil-Gates:**

#### G3 — Reviewer
Code-Qualität, PSR-12, strict_types, DRY, Naming, Kommentare. Prüft keine Security-Details (das macht G4).

#### G5 — DSGVO
Daten-Minimierung, Audit-Log-Semantik, Retention, Rechtsgrundlage (Art. 6 DSGVO). Prüft, ob PII sauber behandelt wird.

#### G6 — Tester
Test-Abdeckung: PHPUnit (Unit + Invariants), Playwright (End-to-End). Bewertet Coverage-Adäquanz.

#### G7 — Integrator
Regressions-Oberfläche. Brechen bestehende Features? Bleibt der Login-Pfad sauber? Deploy-Reihenfolge korrekt?

**Output:** Tabellarischer Report mit GRÜN / GELB / ROT pro Dimension. Follow-up-Liste.

### G4 — Security-Review

**Rolle:** Security-Auditor.

**Aufgabe:** Tiefer Sicherheits-Review. Dimensionen wie Authorization, CSRF, XSS, IDOR, Rate-Limit-Umgehung, Audit-Leakage.

**Output:** Dimensions-basierter Report. ROT-Findings müssen vor G8 gefixt werden.

### G8 — Architect-Final-Review

**Rolle:** Architect (wie bei G1, aber am Ende).

**Aufgabe:** Strukturelle Gesamtbewertung. Wurde der G1-Plan eingehalten? Sind die Abweichungen kohärent? Sind die Follow-ups sauber einsortiert? Ist das Inkrement tag-reif?

**Output:** Fünf-Dimensionen-Bericht (G1-Konformität, Phasen-Disziplin, Abweichungs-Kohärenz, Follow-up-Hygiene, Tag-Reife). Hinweise an G9.

### G9 — Dokumentar + Tag

**Rolle:** Dokumentar (Coder-Rolle mit Fokus auf Doku-Pflege).

**Aufgabe:** Alle G9-Pflicht-Follow-ups abarbeiten, Dokumentation aktualisieren, Tag setzen.

**Typische G9-Deliverables:**
- Benutzerhandbuch-Abschnitte für neue Features.
- Lessons-Learned-Einträge.
- Versions-Bump in `CLAUDE.md`.
- Ergänzung der `ARCHITECTURE.md` bei Pattern-Neuheit.
- DSGVO-Nachweis-Dokument aktualisieren, wenn PII-Flows betroffen sind.
- Follow-up-Register pflegen.
- Deploy-Reihenfolge-Notizen (z.B. Migration-First vor App-Update).
- Annotated Git-Tag mit ausführlicher Commit-Message.

---

## 3. Inkrement-Nummerierung

### Module

Das Projekt hat nummerierte Module:
- **Modul 1-5:** Kern-Funktionen (Login, Arbeitsstunden, Workflow, Reports, Administration).
- **Modul 6:** Events & Helferplanung (der aktuelle Arbeitsschwerpunkt).

### Inkremente innerhalb eines Moduls

Inkremente heißen **I1, I2, I3, ...**. Innerhalb eines Moduls inhaltlich thematisch gruppiert.

Beispiel Modul 6:
- **I1:** Event-CRUD.
- **I2:** Aufgaben-Zuweisung / Mitglieder-Sicht.
- **I3:** Event-Abschluss & Storno.
- **I4:** Event-Vorlagen.
- **I5:** iCal-Abonnement.
- **I6:** Notifications & Scheduler.
- **I7:** Tree-Editor (in mehreren Unter-Inkrementen).
- **I8:** Systemisches Audit + Rate-Limit (ausnahmsweise modul-übergreifend).

### Unter-Inkremente

Bei großen Inkrementen werden Unter-Buchstaben genutzt: **I7a, I7b, I7c, ...**. Innerhalb von Unter-Inkrementen ggf. Ziffern: **I7b1, I7b2, ...**.

Beispiel:
- **I7a:** Aufgabenbaum-Service-Schicht.
- **I7b1-b5:** Tree-Editor für Admin und Organizer.
- **I7c:** Template-Aufgabenbaum-Editor.
- **I7e-A/B/C:** Edit-Erfahrung (non-modaler Editor, Optimistic Lock, Edit-Session-Hinweis).

**Hinweis:** Nicht jede Nummer wird verwendet. **I7d** wurde z.B. übersprungen — das ist zulässig und wird im Follow-up-Register dokumentiert.

### Verworfene Inkremente

Ein Inkrement kann geplant, aber **verworfen** werden, wenn Aufwand/Nutzen nicht gerechtfertigt ist. Beispiel: **I7e-C.2** (Template-Edit-Sessions) wurde nach G1-Runde-1 verworfen und als Follow-up-Eintrag dokumentiert.

---

## 4. Tags und Versionen

### Tag-Format

Jedes abgeschlossene Inkrement bekommt einen **annotierten Git-Tag**:

```
v<major>.<minor>.<patch>-local-<inkrement>
```

Beispiele:
- `v1.4.7-local-i7e-a`
- `v1.4.8-local-i7e-b`
- `v1.4.9-local-i7e-c`
- `v1.4.10-local-i8` (geplant)

### Der `-local-`-Suffix

Bedeutet: **diese Version wurde noch nicht auf die Strato-Produktion deployed**. Sobald ein Deploy erfolgt, kann eine separate Tag-Strategie ohne `-local-` genutzt werden.

### Die Versionsnummer

- **Major (1):** Grundlegende Architektur-Version. Ändert sich selten.
- **Minor (4):** Inhaltlich bedeutender Sprung. Ändert sich bei neuen Modulen.
- **Patch (7, 8, 9, 10):** Jedes Inkrement erhöht den Patch-Zähler um mindestens 1.

---

## 5. Phasen innerhalb eines Inkrements

Die klassische Vier-Phasen-Struktur eines Feature-Inkrements:

| Phase | Typischer Scope | Coder-Arbeit |
|-------|-----------------|--------------|
| **Phase 1** | Backend-Schicht | Migration, Model, Repository, Service, Invariants |
| **Phase 2** | HTTP-Schicht | Controller, Routes, Views, Permission-Checks |
| **Phase 3** | Frontend | JavaScript, CSS, UI-Elemente |
| **Phase 4** | Validierung | End-to-End-Tests (Playwright), Gate-Pipeline, Tag |

**Ausnahmen:**
- Systemische Inkremente (z.B. I8) haben oft weniger Phasen.
- Hygiene-Inkremente (z.B. Test-Breite-Bündel) haben nur eine Phase.
- Mini-Iterationen (z.B. Follow-up-Fix) sind oft Ein-Commit-Arbeit.

### Phase 4 als Meta-Phase

Phase 4 ist oft in **Teile** aufgegliedert:
- **Teil 1:** Playwright-Spec schreiben.
- **Teil 2:** Sanity-Gate.
- **Teil 3:** G4 Security.
- **Teil 4:** G8 Architect-Final.
- **Teil 5:** G9 Dokumentar + Tag.

---

## 6. Branch-Konvention

Neue Inkremente arbeiten auf eigenen Feature-Branches:

```
feature/<thema>-<inkrement>
```

Beispiele:
- `feature/event-task-tree-i7e`
- `feature/event-task-tree-i7e-b`
- `feature/edit-session-i7e-c`
- `feature/i8-audit-rate-limit`

**Nach-Tag-Branches** für kleine Nachbesserungen:

```
feature/<inkrement>-<thema>
```

Beispiele:
- `feature/i7e-c-test-breite`
- `feature/i7e-c-follow-up-z`

---

## 7. Abkürzungen und Nummerierungen

### C-Nummern — Architect-Korrekturen

Korrekturen, die der Architect an Projekt-Lead-Vorschlägen vorschlägt. Fortlaufend nummeriert über alle Inkremente.

Beispiele:
- **C1** (I7e-C): sessionStorage statt In-Memory.
- **C2** (I7e-C): Flag-Kopplung hart im Code.
- **C3** (I7e-C): Lazy-Cleanup statt Cron.
- **C11** (I8): Dedizierte `logAccessDenied`-Methode.

### Q-Nummern — Klärungsfragen

Fragen, die der Architect dem Projekt-Lead stellt und die vor Phase-1-Start beantwortet werden müssen. Pro Inkrement neu nummeriert (Q1, Q2, ...).

### R-Nummern — Risiken

Risiken und ihre Mitigation in der Risiko-Matrix. Pro Inkrement neu nummeriert (R1, R2, ...). Oft ergänzt durch **NR-Nummern** für "Neue Risiken", die der Architect während G1 entdeckt.

### K-Nummern — Projekt-Lead-Entscheidungen

Vorentscheidungen des Projekt-Leads vor G1 (K1, K2, ...). Werden vom Architect bestätigt, präzisiert oder korrigiert (dann als `K1b` bzw. mit zugehöriger C-Nummer).

### FU-Nummern — Follow-ups

Offene Punkte, die nicht im aktuellen Inkrement abgearbeitet werden, aber dokumentiert bleiben.

**Zwei Nummerierungs-Konventionen parallel:**

**Inkrement-lokal:** `FU-G<gate>-<n>` — z.B. `FU-G6-1` (erstes Tester-Gate-Follow-up dieses Inkrements).

**Buchstaben-global:** Ein Buchstabe pro Follow-up, fortlaufend über alle Inkremente: `v`, `w`, `x`, `y`, `z`, ... Das `docs/follow-ups.md`-Register pflegt beide Schemata.

### D-Nummern — Design-Entscheidungen

Selten genutzt. Gelegentlich für UI/UX-Design-Entscheidungen im Frontend-Bereich.

### NR-Nummern — Neue Risiken

Vom Architect während der Plan-Erstellung entdeckte Risiken, die über die Projekt-Lead-Vorgabe hinausgehen (NR1, NR2, ...).

---

## 8. Dokument-Register

Die wichtigsten Dokumente, die im Prozess entstehen oder gepflegt werden:

| Datei | Zweck |
|-------|-------|
| `CLAUDE.md` | Top-Level-Regeln für Claude im Projekt |
| `ARCHITECTURE.md` | Architektur-Pattern und Schichten-Modell |
| `Benutzerhandbuch.md` | Endnutzer-Dokumentation |
| `.claude/rules/0X-*.md` | Kleinere Regel-Dokumente pro Thema |
| `.claude/<rolle>.md` | Rollen-Definition pro Gate-Rolle |
| `.claude/lessons-learned.md` | Erfahrungsberichte datiert |
| `docs/follow-ups.md` | Offenes Follow-up-Register |
| `docs/DSGVO_und_Security_Nachweis.md` | DSGVO-Audit-Dokument |
| `REQUIREMENTS_*.md` | Anforderungs-Dokumente pro Modul |

---

## 9. Gate-Farben und Bedeutung

| Farbe | Bedeutung |
|-------|-----------|
| **GRÜN** | Keine Findings, Gate bestanden |
| **GELB** | Findings vorhanden, aber nicht blockierend — als Follow-up dokumentiert |
| **ROT** | Blockierender Befund — muss vor dem nächsten Gate gefixt werden |

**Regel:** ROT-Findings werden **vor** dem nächsten Gate abgearbeitet. GELB-Findings werden **als Follow-up** im Register eingetragen und **später** (im selben Inkrement als Nach-Tag-Bündel oder als eigenes Follow-up-Inkrement) bearbeitet.

---

## 10. Typische Artefakte pro Gate

### Nach G1

- Architect-Plan-Dokument (im Gespräch, oder als Datei bei Bedarf).

### Nach G2 (pro Phase)

- Git-Commit mit ausführlicher Commit-Message.
- Übergabe-Report im Gespräch.

### Nach Sanity-Gate (G3+G5+G6+G7)

- Tabellarischer Sanity-Report.
- Follow-up-Liste.

### Nach G4

- Dimensions-basierter Security-Report.
- ROT-Findings (falls vorhanden) mit Fix-Vorschlag.

### Nach G8

- Fünf-Dimensionen-Architect-Final-Report.
- Hinweise an G9.

### Nach G9

- Aktualisierte Dokumentation.
- Annotierter Git-Tag.
- Push auf origin.

---

## 11. Deploy-Kontext (für später)

Bisher sind alle Tags **lokal** (`-local-`-Suffix). Ein echter Produktions-Deploy erfordert:

1. Strato-Zugang vorbereiten (FTP, phpMyAdmin).
2. Alle Migrationen in Reihenfolge auf Strato-DB einspielen.
3. App-Code via FTP hochladen (inkl. `vendor/`).
4. `config.php` mit Produktions-Daten erstellen (SMTP, DB-Credentials).
5. Admin-User anlegen, Kategorien definieren.
6. Feature-Flags auf sinnvolle Werte setzen.
7. Datenschutzerklärung des Vereins auf das neue System anpassen (Vorstands-Thema).

Details dazu stehen in `.claude/integrator.md` und im Benutzerhandbuch-Abschnitt "Verfügbarkeit".

---

## 12. Lesehinweise

- Dieses Dokument ist **kein Ersatz** für die Rollen-Definitionen in `.claude/`. Es ist eine **Übersicht**, die den Einstieg erleichtert.
- Bei Widersprüchen zwischen diesem Dokument und `.claude/<rolle>.md`: die Rollen-Definitionen sind autoritativ.
- Bei Widersprüchen zwischen Dokumentation und Code: der Code ist autoritativ, die Dokumentation wird nachgezogen.

---

*Letzte Aktualisierung: 2026-04-24 (nach I7e-Phase-Abschluss und I8-Start).*