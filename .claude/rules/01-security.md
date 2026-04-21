# 🛡️ Rules: Security (PHP 8.x / VAES)

Geladen von: `security.md` (G4), bei Bedarf `coder.md` (G2).

---

## SQL-Injection — PDO-Pflicht

**DO:**
```php
$stmt = $pdo->prepare(
    'SELECT * FROM work_entries WHERE user_id = :uid AND deleted_at IS NULL'
);
$stmt->execute(['uid' => $userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

**DON'T:**
```php
$pdo->query("SELECT * FROM work_entries WHERE user_id = $userId");
$pdo->query("... WHERE name = '" . $name . "'");
```

**Dynamische Spaltennamen — Allowlist:**
```php
$allowed = ['created_at', 'hours', 'status'];
$sort = in_array($req, $allowed, true) ? $req : 'created_at';
$order = $dir === 'DESC' ? 'DESC' : 'ASC';
$sql = "SELECT * FROM work_entries ORDER BY $sort $order";
```

---

## XSS — Output-Escaping

**DO (in Views):**
```php
<?= htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8') ?>

<!-- Kuerzer via ViewHelper -->
<?= ViewHelper::e($user->name) ?>

<!-- URLs -->
<a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">...</a>

<!-- Attribute -->
<input value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">

<!-- JSON in JS -->
<script>
  const data = <?= json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG) ?>;
</script>
```

**DON'T:**
```php
<?= $user->name ?>
<?= $_GET['q'] ?>
echo "Hi " . $name;
```

---

## CSRF — Pflicht auf allen State-Changing Requests

**DO (im Formular):**
```php
<form method="POST" action="/entries/<?= (int)$id ?>/approve">
  <input type="hidden" name="csrf_token"
         value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
  <button type="submit" class="btn btn-success">Freigeben</button>
</form>
```

**Middleware-Pruefung:**
```php
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    throw new AuthorizationException('CSRF-Token ungueltig.');
}
```

**AJAX-Requests:**
```javascript
fetch('/entries/' + id + '/approve', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        'Content-Type': 'application/json'
    }
});
```

---

## Authentifizierung

**Passwort-Hashing:**
```php
// Erstellen
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Pruefen
if (password_verify($input, $storedHash)) {
    // login OK
}

// Rehash, wenn cost angehoben wird
if (password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => 12])) {
    $newHash = password_hash($input, PASSWORD_BCRYPT, ['cost' => 12]);
    // in DB aktualisieren
}
```

**Session-Regenerierung:**
```php
// Nach Login
session_regenerate_id(true);

// Nach 2FA-Freigabe
session_regenerate_id(true);

// Beim Logout
$_SESSION = [];
session_destroy();
```

**Token-Generierung:**
```php
$token = bin2hex(random_bytes(32));  // 64-Zeichen hex
$tokenHash = hash('sha256', $token); // DB speichert nur Hash
```

---

## Rollen-Pruefung

**DO (im Service, nicht nur im View):**
```php
public function approve(int $entryId, int $reviewerId): void
{
    $reviewer = $this->users->findOrFail($reviewerId);

    if (!$reviewer->hasRole('pruefer') && !$reviewer->hasRole('administrator')) {
        throw new AuthorizationException('Nur Pruefer koennen freigeben.');
    }
    // ...
}
```

**Im Controller via Middleware:**
```php
$app->post('/entries/{id}/approve', [WorkEntryController::class, 'approve'])
    ->add(new RoleMiddleware(['pruefer', 'administrator']));
```

---

## Selbstgenehmigung — IMMER pruefen

```php
public function approve(int $entryId, int $reviewerId): void
{
    $entry = $this->entries->findOrFail($entryId);

    if ($entry->userId === $reviewerId) {
        throw new BusinessRuleException(
            'Eigene Antraege koennen nicht selbst genehmigt werden.'
        );
    }
    // Gleiches fuer reject(), returnToDraft(), askQuestion()
}
```

---

## Secrets / Config

**DO:**
- `src/config/config.php` ist in `.gitignore`
- `src/config/config.example.php` ist das Template (OHNE reale Werte)
- Zugangsdaten rotieren, wenn sie in die History geraten sind (siehe Lessons-Learned)

**DON'T:**
- `git add src/config/config.php`
- API-Keys/Passwoerter im Code oder in Kommentaren
- Credentials in Test-Fixtures (`tests/fixtures/`)

---

## Uploads (falls noch nicht implementiert)

```php
// MIME-Check
$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detected = finfo_file($finfo, $_FILES['doc']['tmp_name']);
if (!in_array($detected, $allowedMimes, true)) {
    throw new ValidationException('Dateityp nicht erlaubt.');
}

// Dateiname saeubern
$safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $original);
$finalName = bin2hex(random_bytes(8)) . '_' . $safeName;

// Ausserhalb Web-Root speichern
$target = __DIR__ . '/../storage/uploads/' . $finalName;
```

---

## HTTP-Header (Strato `.htaccess` + PHP-Middleware)

Header werden **doppelt** gesetzt — `.htaccess` fuer Apache, `SecurityHeadersMiddleware`
fuer lokalen Dev-Server (`php -S` liest `.htaccess` nicht). Beide Seiten muessen
synchron bleiben.

**Apache (`src/public/.htaccess`):**
```apache
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=(), payment=(), usb=()"
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests"
</IfModule>
```

**PHP (`src/app/Middleware/SecurityHeadersMiddleware.php`):** identische Policy,
HSTS nur wenn HTTPS erkannt (HTTPS-Server oder `X-Forwarded-Proto=https`).

**Offener Punkt:** `'unsafe-inline'` in `script-src` bleibt, bis die Inline-
Skripte mit Per-Request-Nonce umgebaut sind (eigene Iteration).

---

## Verbotenes

- Raw SQL mit Variablen-Konkatenation
- `echo` von User-Daten ohne `htmlspecialchars`
- `exec`/`shell_exec`/`system`/`passthru` auf User-Input
- `md5`/`sha1` fuer Passwoerter oder Security-Tokens
- `unserialize($_POST[...])` ohne `allowed_classes`
- GET-Parameter mit Passwort/Token (immer POST/Session)
- Fehlermeldung mit Stack-Trace an User
- `config.php` in Git
