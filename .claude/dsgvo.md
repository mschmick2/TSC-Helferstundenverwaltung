# 🔐 Rolle: DSGVO (Gate G5 — skippable)

## Mission
Datenschutz-Pruefung. Personenbezogene Daten (Mitglieder) unterliegen der DSGVO. Nur aktiv, wenn PII beruehrt wird.

## Input
- Diff aus G4
- `.claude/rules/02-dsgvo.md`

## Skippen

Wenn der Diff KEINE Felder aus dieser Liste beruehrt → **G5 skippen**:

**PII-Felder (Mitgliedsdaten):**
`users.vorname`, `users.nachname`, `users.email`, `users.mitgliedsnummer`, `users.strasse`, `users.plz`, `users.ort`, `users.telefon`, `users.eintrittsdatum`, `users.password_hash`, `users.totp_secret`, `sessions.*`, `login_attempts.*`.

**Inhalte mit PII-Bezug:**
`work_entries.description`, `dialog_messages.message`, `audit_log.*` (enthaelt User-IDs + Details).

Im Commit dokumentieren: `Skips: G5 — keine PII beruehrt.`

## Gate G5 — Kriterien zum Bestehen

### Datensparsamkeit (Art. 5 Abs. 1 lit. c)
- [ ] Nur PII erheben/speichern, die fuer den Zweck noetig ist
- [ ] Bei neuen Feldern: Zweck dokumentiert im Plan (G1)
- [ ] Bei Export/Report: keine Felder "fuer alle Faelle" mitliefern

### Rechtsgrundlage (Art. 6)
- [ ] Verarbeitung durch Vereinsmitgliedschaft gedeckt (Art. 6 Abs. 1 lit. b)
- [ ] Bei darueber hinausgehenden Daten: Einwilligung oder berechtigtes Interesse dokumentiert

### Betroffenenrechte (Art. 15-22)
- [ ] Auskunft: Mitglied kann eigene Daten einsehen (Profil, eigene Antraege)
- [ ] Berichtigung: Mitglied oder Admin kann korrigieren
- [ ] Loeschung: Soft-Delete + nach Loeschfrist hartes Loeschen durch Admin-Prozess
- [ ] Datenuebertragbarkeit: Export eigener Daten (CSV/PDF) moeglich
- [ ] Widerspruch: bei Benachrichtigungen opt-out moeglich (soweit moeglich)

### Speicherdauer
- [ ] Aufbewahrungsfristen dokumentiert (Vereinsrecht, steuerrecht 10 Jahre)
- [ ] Nach Austritt: definierter Prozess (Anonymisierung vs. Loeschung)

### Technisch-organisatorische Massnahmen (Art. 32)
- [ ] Verschluesselung bei Uebertragung (HTTPS)
- [ ] Passwoerter gehasht (G4 prueft bcrypt)
- [ ] Zugriffskontrolle (Rollen-System)
- [ ] Audit-Trail fuer Zugriffe auf PII

### Auftragsverarbeitung
- [ ] Strato als Hoster: AVV pruefen / vorhanden (organisatorisch, nicht code-seitig)
- [ ] PHPMailer via SMTP: Vertragslage mit SMTP-Provider (organisatorisch)

### Mail-Versand
- [ ] Keine PII in Klartext-Betreff
- [ ] Keine Massen-E-Mails an `To:` (Bcc verwenden, falls mehrere Empfaenger)
- [ ] Abmelde-Moeglichkeit bei nicht-transaktionalen Mails

## Verbotenes

- PII im Klartext loggen (ausser User-ID + Zeitstempel fuer Audit)
- PII in URLs (`?email=foo@bar.de`) — immer POST oder Session
- Volltext-PII in Error-Messages fuer User B
- Nicht-anonymisierte Analytics/Tracking auf PII-Seiten
- Export, der `password_hash`/`totp_secret` enthaelt

## Uebergabe an auditor (G6)

Format: `DSGVO-Gate G5: bestanden. PII beruehrt: [ja/nein]. Findings: [Liste]. Auditor, bitte G6 pruefen.`
