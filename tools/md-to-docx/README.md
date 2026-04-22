# md-to-docx — Markdown → DOCX mit ASCII-Retransliteration

Konvertiert `docs/Benutzerhandbuch.md` in eine `.docx`-Datei mit eingebetteten
Screenshots. Hauptzweck: das ASCII-transliterierte Handbuch (ae/oe/ue/ss) in
eine typografisch korrekte deutsche Word-Datei (ä/ö/ü/ß) zu uebersetzen, ohne
unsichere Pauschalersetzung.

## Wozu das Tool?

Die Markdown-Quelle ist ueber Jahre mit ASCII-Transliterationen gewachsen
(CLI-Input, Harness-Regeln). Das Tool loest zwei Probleme in einem Schritt:

1. **Retransliteration per Wort-Whitelist:** `WORD_MAP` enthaelt >300 manuell
   kuratierte Eintraege. Nur Woerter in der Map werden ersetzt. Woerter wie
   *neue*, *aktuell*, *dass*, *muss* bleiben unangetastet. Eine pauschale
   Regex `ue→ü` wuerde Dutzende Woerter zerstoeren — siehe
   `.claude/lessons-learned.md` (Eintrag 2026-04-22).
2. **Markdown → DOCX:** Headings (H1–H4), Tabellen, Code-Bloecke, Listen,
   Bild-Einbettung, Blockquotes, Horizontal-Rules, Bold/Italic/Inline-Code.

## Voraussetzungen

- Python 3.10+
- Abhaengigkeiten: `python-docx`, `markdown`

## Setup (einmalig)

```powershell
cd tools/md-to-docx
python -m venv venv
venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

## Lauf

Aus dem Repo-Root:

```powershell
python tools/md-to-docx/build-handbuch-docx.py
```

Ergebnis: `docs/Benutzerhandbuch.docx` (ueberschreibt ggf. existierende).

## Audit-Modus

Vor dem ersten Lauf auf einer neuen Markdown-Quelle: Audit-Modus listet alle
Kandidaten-Token (mit `ae/oe/ue/ss` im String), die **nicht** in `WORD_MAP`
vermerkt sind. So erkennt man neue Woerter, die ggf. eine Retransliteration
brauchen.

```powershell
python tools/md-to-docx/build-handbuch-docx.py --audit
```

Die Ausgabe zeigt Token + Vorkommens-Zaehlung. Neue echte Umlaut-Woerter in
`WORD_MAP` eintragen, dann echten Lauf.

## Pattern — warum Wort-Whitelist statt Regex?

Naive Ersetzung zerstoert Woerter, in denen `ue`/`ss` zufaellig vorkommen:

| Falsche Regex | Zerstoert |
|---------------|-----------|
| `ue → ü` | neue, aktuell, manuell, individuelle, Grauer, Blauer |
| `ss → ß` | dass, muss, passiert, lassen, Session, password, Permissions |
| `oe → ö` | Poet, Coexistenz |
| `ae → ä` | Rafael, Israel |

Die Wort-Whitelist enthaelt exakt die Woerter, die umgestellt werden sollen,
und nichts darueber hinaus.

## Erweitern

Neue Begriffe kommen in `WORD_MAP` im Script-Header (alphabetisch sortiert).
Pro Eintrag **eine** Zeile:

```python
"urspruenglich": "ursprünglich",
```

Beide Flexionsformen (`urspruenglich`, `Urspruenglich`, `urspruengliche` …)
separat mappen, wenn sie im Handbuch vorkommen. `--audit` hilft beim
Komplettieren.

## Verwandte Lessons Learned

- `.claude/lessons-learned.md` → Eintrag **2026-04-22** (ASCII-Retransliteration
  per Regex zerstoert Woerter wie *neue*, *dass*)
