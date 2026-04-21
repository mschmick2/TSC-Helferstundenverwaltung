# 🔀 Rules: Git & Deployment (VAES — Strato)

Geladen von: `dokumentar.md` (G9), bei Bedarf `integrator.md` (G8).

---

## Branching

- `main` — Produktionsreif, deploybar
- `feature/<kurzname>` — neue Features
- `fix/<issue>` — Bugfixes
- `docs/<thema>` — reine Doku

Keine `dev`/`develop`-Branch. Direkte Arbeit auf `main` ist verboten.

---

## Conventional Commits

### Typen

| Type | Wann |
|------|------|
| `feat` | neues Feature |
| `fix` | Bugfix |
| `refactor` | Umbau ohne Verhaltensaenderung |
| `perf` | Performance-Verbesserung |
| `docs` | nur Dokumentation |
| `test` | nur Tests (oder Testinfrastruktur) |
| `chore` | Build, Tooling, Deps |
| `security` | Security-Fix |
| `style` | Formatierung |

### Scopes (haeufig im Projekt)

`auth`, `workflow`, `dialog`, `audit`, `reports`, `import`, `admin`, `ui`, `db`, `config`, `docs`, `tests`

### Template

```
<type>(<scope>): <subject in imperativ, max 72 Zeichen>

<body: Warum (der Code zeigt das Was)>

Durchlaufene Gates: G1✓ G2✓ G3✓ G4✓ G5✓ G6✓ G7✓ G8✓
Skips: [z.B. G3.5 — keine UI-Aenderung. G5 — keine PII.]

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

### Beispiele

```
feat(workflow): Pruefer kann freigegebene Antraege korrigieren

Ermoeglicht Prueferrollen die nachtraegliche Korrektur von
freigegebenen Antraegen mit Pflicht-Begruendung. Alte/neue Werte
im Audit-Log.

Durchlaufene Gates: G1✓ G2✓ G3✓ G3.5✓ G4✓ G5-skip G6✓ G7✓ G8✓
Skips: G5 — keine neuen PII-Felder.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

```
fix(auth): Session bei Passwortwechsel auf allen Geraeten invalidieren

SessionRepository::invalidateAllForUser() wird jetzt nach erfolgreicher
Passwortaenderung aufgerufen. Behebt Issue, bei dem alte Sessions nach
Passwortwechsel aktiv blieben.

Durchlaufene Gates: G1✓ G2✓ G3✓ G3.5-skip G4✓ G5-skip G6✓ G7✓ G8✓
Skips: G3.5 — keine UI-Aenderung. G5 — keine neuen PII.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

---

## Staging

- `git add <konkrete Datei>` bevorzugt
- `git add -A` nur wenn `git status` vorher gelesen wurde
- **NIEMALS:** `config.php` / Secrets stagen

---

## Pre-Commit-Checks (manuell)

Vor `git commit`:
```bash
# 1. Syntax-Check neuer/geaenderter PHP-Dateien
cd src && php -l path/to/file.php

# 2. Tests gruen
src/vendor/bin/phpunit

# 3. Status pruefen
git status
git diff --cached

# 4. Keine Debug-Reste / Credentials
git diff --cached | grep -E '(var_dump|print_r|die\(|password\s*=\s*["'\''])'
```

---

## Commit-Flow (Claude Code)

```bash
# Gate G9: Commit erstellen
git add <files>
git commit -m "$(cat <<'EOF'
feat(scope): kurzes Subject

Body: Warum.

Durchlaufene Gates: G1✓ G2✓ G3✓ G4✓ G5✓ G6✓ G7✓ G8✓
Skips: [ggf.]

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Push-Policy

- `git push origin feature/xyz` — normal
- `git push origin main` — nur nach Merge + User-Freigabe
- `git push --force` — **NIEMALS** auf `main`, nur auf eigenen Feature-Branches mit Begruendung

---

## Deployment — Strato Shared Hosting

**Konstanten:**
- KEIN SSH → kein `git pull` auf Server
- KEIN Cron → keine geplanten Jobs
- KEIN Node.js → kein Build-Tool auf Server
- KEIN Composer auf Server → `vendor/` wird MIT hochgeladen

**Deploy-Schritte:**

1. Lokal: `cd src && composer install --no-dev --optimize-autoloader`
2. Lokal: `src/vendor/bin/phpunit` gruen
3. FTP-Upload:
   ```
   /httpdocs/vaes/
   ├── public/          → Web-Root-Ziel auf Strato
   ├── app/
   ├── vendor/
   ├── config/          → NUR config.php (einmalig, nicht ueberschreiben!)
   └── storage/
   ```
4. `.htaccess` pruefen (Rewrites, Security-Header)
5. SQL-Migrationen von Admin per phpMyAdmin einspielen
6. Smoke-Test: Login, Dashboard, Neues-Antrags-Flow

**Schutz vor versehentlichem Ueberschreiben:**
- `src/config/config.php` auf Server NIE per FTP ueberschreiben (lokale Kopie = Dev-Werte)
- `src/storage/` nicht loeschen (Logs)

---

## Release-Markierung

```bash
git tag -a v1.4.1 -m "Release 1.4.1: [Kurzbeschreibung]"
git push origin v1.4.1
```

`docs/version.json` erhoehen:
```json
{ "version": "1.4.1" }
```

---

## Verbotenes

- `git commit --no-verify` (Hooks umgehen)
- `git push --force` auf `main`
- `git rebase -i` auf bereits gepushte Commits ohne Ruecksprache
- `config.php` in Commits
- `chore: update`, `fix: bug`, `wip` — keine Blind-Commits
- Server-seitig Dateien bearbeiten (immer lokal + Upload)
- `vendor/` vom Server loeschen ohne direkten Re-Upload
