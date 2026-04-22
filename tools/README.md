# tools/ — Entwickler-Tools fuer VAES

Dieses Verzeichnis enthaelt **Entwicklungs-Tools**, die waehrend der Arbeit am
VAES-Projekt nuetzlich sind, aber **nicht** Teil der Produktions-Anwendung
sind. Jedes Unterverzeichnis hat eine eigene `README.md` mit Details.

## Abgrenzung zu `scripts/`

- `scripts/` — Projekt-spezifische Operations-Skripte (DB-Seed, Anonymisierung,
  Handbuch-Bilder generieren, Rollout-Helfer). Werden im Deploy-/Wartungs-
  Prozess des laufenden VAES eingesetzt.
- `tools/` — Wiederverwendbare Entwickler-Tools (oft mit eigener Laufzeit,
  Python-venv, externer Binary). Werden ad-hoc waehrend der Entwicklung
  ausgefuehrt, nicht im Betrieb.

## Inventar

| Tool | Zweck | Start |
|------|-------|-------|
| [mailpit/](mailpit/README.md) | Lokaler SMTP-Catcher (Port 1025/8025). Faengt alle ausgehenden Mails der Dev-Instanz ab und zeigt sie in einer Web-UI. | `tools/mailpit/mailpit.exe` |
| [ux-analyzer/](ux-analyzer/README.md) | Python-Tool fuer UX-/Layout-Analyse auf Basis von Playwright-Screenshots und heuristischen Regeln. | `tools/ux-analyzer/setup.bat` → `python ux_analyzer.py` |
| [md-to-docx/](md-to-docx/README.md) | Markdown→DOCX-Konverter mit ASCII-Retransliteration (ae/oe/ue/ss → ä/ö/ü/ß via Wort-Whitelist). Erzeugt `docs/Benutzerhandbuch.docx` aus `docs/Benutzerhandbuch.md` inkl. Screenshots. | `python tools/md-to-docx/build-handbuch-docx.py` |

## Neue Tools hinzufuegen

1. Unterverzeichnis `tools/<name>/` anlegen.
2. Eigene `README.md` mit mindestens: Zweck, Voraussetzungen, Setup, Aufruf.
3. Falls Python: `requirements.txt` beilegen. `venv/` in die lokale
   `.gitignore` aufnehmen.
4. Falls externes Binary: Version im Readme festhalten; Binary in `.gitignore`
   (zu gross fuer Git), stattdessen Bezugsquelle (URL, Checksum) dokumentieren.
5. Diesen Inventar-Abschnitt um eine Zeile erweitern.
