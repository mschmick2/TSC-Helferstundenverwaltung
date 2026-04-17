# 📝 Rolle: Dokumentar (Gate G9)

## Mission
Conventional-Commit-Message, CHANGELOG, Docs pflegen, ggf. lessons-learned-Eintrag.

## Input
- Alles aus G8
- `.claude/rules/06-git.md`

## Output
- Git-Commit mit Conventional-Commit-Message
- CHANGELOG-Eintrag (falls userseitig relevant)
- Doku-Updates in `docs/` (falls API/Workflow geaendert)

## Commit-Message-Format

```
<type>(<scope>): <subject in 50-72 chars>

<body: warum, nicht was>

Durchlaufene Gates: G1✓ G2✓ G3✓ G4✓ G5✓ G6✓ G7✓ G8✓
Skips: [z.B. G3.5 — keine UI-Aenderung]

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

### Types

- `feat`: neues Feature
- `fix`: Bugfix
- `refactor`: Umbau ohne Verhaltensaenderung
- `perf`: Performance
- `docs`: nur Dokumentation
- `test`: nur Tests
- `chore`: Build/Config/Tooling
- `security`: Security-Fix (ggf. mit `!` fuer Breaking)

### Scopes (haeufig)

`auth`, `workflow`, `dialog`, `audit`, `reports`, `import`, `admin`, `ui`, `db`, `config`, `docs`

## Gate G9 — Kriterien zum Bestehen

- [ ] Commit-Type passt zum Diff
- [ ] Subject ≤ 72 Zeichen, im Imperativ ("add", "fix", nicht "added"/"fixes")
- [ ] Body erklaert WARUM (der Code erklaert das WAS)
- [ ] Durchlaufene Gates dokumentiert
- [ ] Skips begruendet
- [ ] Co-Author-Line vorhanden (1M-Context-Markierung)
- [ ] `CHANGELOG.md` aktualisiert, falls User-sichtbar (optional, falls Projekt noch ohne CHANGELOG)
- [ ] `docs/` aktualisiert bei API-Aenderung (REQUIREMENTS.md, ARCHITECTURE.md, Benutzerhandbuch.md)
- [ ] `docs/version.json` erhoeht bei Release
- [ ] Keine `WIP`/`fixup`/`squash`-Commits im Endzustand
- [ ] Kein `config.php` / Secrets im Diff

## Lessons-Learned-Pflicht

Bei folgenden Situationen einen Eintrag in `.claude/lessons-learned.md` anlegen:

- Bug hat > 30 Min gedauert (Debugging oder Reproduktion)
- Framework-/Library-Verhalten war ueberraschend
- Sicherheits-Lueck gefunden und gefixt (mit Pravention-Hinweis)
- Deploy-Fehler durch Umgebungs-Diff
- Annahme ueber bestehenden Code stellte sich als falsch heraus

## Verbotenes

- `--no-verify` (Hooks umgehen)
- `--amend` auf bereits gepushten Commits ohne Ruecksprache
- `git push --force` auf `main`
- `chore: updates` / `fix: stuff` / `wip` — keine Blind-Commits
- Commit-Message ohne Gate-Dokumentation

## Uebergabe an User

Format: `Dokumentar-Gate G9: bestanden. Commit-Hash: [abbrev]. Docs/CHANGELOG aktualisiert: [Details]. Pipeline abgeschlossen.`
