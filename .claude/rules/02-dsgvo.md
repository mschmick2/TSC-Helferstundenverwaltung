# 🔐 Rules: DSGVO (VAES — Vereinsdaten)

Geladen von: `dsgvo.md` (G5), bei Bedarf `architect.md` (G1) bei neuen PII-Feldern.

---

## Rechtsgrundlage

- **Art. 6 Abs. 1 lit. b DSGVO** (Vertragserfuellung): Vereinsmitgliedschaft rechtfertigt Verarbeitung der Mitglieds-Stammdaten und Arbeitsstunden.
- **Art. 6 Abs. 1 lit. c DSGVO** (gesetzliche Pflicht): Steuer-/Vereinsrecht verlangt Aufbewahrung.
- **Art. 6 Abs. 1 lit. f DSGVO** (berechtigtes Interesse): Audit-Log, Sicherheits-Logs.

Darueber hinausgehende Daten benoetigen Einwilligung (Art. 6 Abs. 1 lit. a).

---

## PII-Felder im System

| Tabelle / Feld | Kategorie | Aufbewahrung | Zweck |
|----------------|-----------|--------------|-------|
| `users.vorname`, `nachname` | Stammdaten | Dauer Mitgliedschaft + 10J (Steuer) | Identifikation, Reports |
| `users.email` | Kontakt | s.o. | Login, Benachrichtigungen |
| `users.mitgliedsnummer` | ID | s.o. | Eindeutige Zuordnung |
| `users.strasse`, `plz`, `ort` | Anschrift | s.o. | Vereinsverwaltung |
| `users.telefon` | Kontakt | s.o. | optional |
| `users.eintrittsdatum` | Vertrag | s.o. | Mitgliedschaft |
| `users.password_hash` | Auth | waehrend Mitgliedschaft | Login |
| `users.totp_secret` | Auth | waehrend Mitgliedschaft | 2FA |
| `sessions.*` | Technisch | Session-TTL | Session-Management |
| `login_attempts.*` | Sicherheit | 90 Tage | Brute-Force-Schutz |
| `work_entries.description` | Inhalt | s.o. | Antragsinhalt |
| `dialog_messages.message` | Inhalt | s.o. | Rueckfragen-Dialog |
| `audit_log.*` | Revisionssicher | 10 Jahre | Nachvollziehbarkeit |
| `events.created_by`, `deleted_by` | Funktionsdaten | 10 Jahre | Event-Urheber / Loescher |
| `events.location`, `description` | Freitext (optional PII) | 10 Jahre | Event-Details |
| `event_organizers.user_id` | Funktionsdaten | 10 Jahre | Organisator-Historie (ON DELETE RESTRICT - Audit-Integritaet) |
| `event_organizers.assigned_by` | Funktionsdaten | 10 Jahre | Wer hat zugewiesen |
| `event_task_assignments.user_id` | Teilnahmedaten | 10 Jahre (Steuer/Helferstunden) | Helferstunden-Nachweis |
| `event_task_assignments.replacement_suggested_user_id` | Funktionsdaten | 10 Jahre | Ersatz-Vorschlag |
| `event_tasks.description` | Freitext (optional PII) | 10 Jahre | Aufgaben-Details |
| `event_templates.created_by` | Funktionsdaten | Dauer Mitgliedschaft | Template-Urheber |

---

## Datensparsamkeit (Art. 5 Abs. 1 lit. c)

**DO:**
```php
// Export enthaelt nur notwendige Felder
$sql = 'SELECT id, vorname, nachname, mitgliedsnummer FROM users';
```

**DON'T:**
```php
// Export enthaelt PII "fuer alle Faelle"
$sql = 'SELECT * FROM users';  // inkl. password_hash!
```

---

## Betroffenenrechte

### Auskunft (Art. 15)
- Mitglied sieht im Profil: eigene Stammdaten, alle eigenen Antraege, Dialoge
- Admin kann im Namen des Mitglieds ein vollstaendiges Datenprotokoll erstellen

### Berichtigung (Art. 16)
- Mitglied kann eigene Stammdaten aendern (oder Admin fragt)
- Korrekturen im Audit-Log sichtbar

### Loeschung (Art. 17)
- Soft-Delete bei Austritt
- Nach Aufbewahrungsfrist (10J Steuerpflicht) → Anonymisierung oder physische Loeschung durch Admin
- Audit-Log wird NICHT geloescht, aber User-ID kann anonymisiert werden

### Datenuebertragbarkeit (Art. 20)
- CSV-Export eigener Antraege + Stammdaten moeglich
- Export in Prueferreports: nicht personenbezogen ohne Einwilligung

### Widerspruch (Art. 21)
- Benachrichtigungen: opt-out in Settings, soweit nicht transaktional
- Transaktionale Mails (Passwort-Reset, 2FA-Code) sind nicht widersprechbar

---

## Mail-Versand

**DO:**
```php
// Einzel-Empfaenger
$mail->addAddress($member->email);

// Mehrere Empfaenger (z.B. Admins) mit BCC
foreach ($admins as $admin) {
    $mail->addBCC($admin->email);
}
```

**DON'T:**
- CC/To mit mehreren Mitgliedsadressen
- PII im Subject (`Subject: Antrag von Max Mustermann`)
- Anhang mit voller Mitgliederliste in Massen-Mails

---

## Logging

**DO:**
```php
$logger->info('User login', ['user_id' => $user->id, 'ip' => $ip]);
```

**DON'T:**
```php
$logger->info('User login', [
    'email' => $user->email,   // PII
    'password' => $password,   // KRITISCH
    'totp' => $code            // KRITISCH
]);
```

---

## Anonymisierung bei Austritt

```php
// Nach Loeschfrist
public function anonymizeUser(int $userId): void
{
    $stmt = $this->pdo->prepare(
        'UPDATE users SET
            vorname = :anon_vn,
            nachname = :anon_nn,
            email = :anon_mail,
            strasse = NULL, plz = NULL, ort = NULL,
            telefon = NULL,
            anonymized_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'anon_vn' => 'Anonymisiert',
        'anon_nn' => 'M' . $userId,
        'anon_mail' => 'anonym+' . $userId . '@geloescht.local',
        'id' => $userId,
    ]);
    // Audit-Log bleibt, verweist nur noch auf anonymisierten User
}
```

---

## Auftragsverarbeitung (organisatorisch)

- **Strato Hosting:** AVV mit Strato vorhanden (nicht im Code abbildbar)
- **SMTP-Provider:** AVV mit Mail-Provider pruefen
- **TCPDF/PHPMailer:** keine Datenweitergabe an Dritte (rein lokal)

---

## Verbotenes

- PII in URL-Parametern (`/profile?email=foo@bar.de`)
- PII im Klartext in Logs
- Export inklusive `password_hash`/`totp_secret`
- Volltext-PII in Fehlermeldungen an User B
- Newsletter an `To:`-Liste mit Adressen aller Mitglieder
- Passwort-Reset ohne Rate-Limit
