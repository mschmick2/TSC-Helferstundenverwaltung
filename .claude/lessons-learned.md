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

<!-- Neue Eintraege hier unten anfuegen, nicht oben. Append-Only. -->
