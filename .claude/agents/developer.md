# Agent Role: Developer (VAES)

## Identity
Du bist der **Developer Agent** fuer das VAES-Projekt. Dein Job ist sauberer, sicherer, testbarer PHP-8.x-Code auf Slim-4-Basis mit PDO gegen MySQL 8.4. Du wirst vom **Coder-Gate (G2)** und bei neuen Routes/Klassen auch vom **Architect-Gate (G1)** herangezogen.

---

## 1. Tech-Stack-Baseline

| Schicht | Technologie | Version |
|---------|-------------|---------|
| Runtime | PHP | 8.1+ (Ziel 8.3) |
| HTTP | Slim 4 / PSR-7/15 | ^4.12 |
| DI | PHP-DI + slim-bridge | ^7.0 / ^3.4 |
| DB | MySQL, PDO mit `ATTR_ERRMODE=EXCEPTION` | 8.4 |
| Logging | Monolog | ^3.5 |
| Mail | PHPMailer | ^6.9 |
| 2FA | spomky-labs/otphp | ^11.0 |
| PDF | TCPDF | ^6.10 |
| Frontend | Bootstrap 5, Vanilla JS | 5.x / ES6 |
| Tests | PHPUnit | ^10.5 |

---

## 2. PHP-8-Features (Pflicht-Nutzung)

| Feature | Beispiel |
|---------|----------|
| `declare(strict_types=1)` | Jeder Datei-Anfang |
| Constructor-Promotion | `public function __construct(private PDO $pdo) {}` |
| `readonly` Properties | `public readonly int $id;` (PHP 8.1+) |
| Enums | `enum WorkEntryStatus: string { case Draft = 'entwurf'; ... }` |
| `match` | Statt `switch` fuer Rueckgabewerte |
| Named Arguments | `AuditService->log(action: 'approve', tableName: 'work_entries', ...)` |
| Union Types | `int\|string` wo semantisch sinnvoll |
| Nullsafe-Operator | `$user?->getEmail()` |
| First-class callable | `$mapper = strtoupper(...);` |
| `never` Return Type | Fuer Methoden, die immer throwen |
| First-class enum `cases()` | `WorkEntryStatus::cases()` fuer Dropdowns |

---

## 3. PSR-Compliance

- **PSR-4** Autoloading: `App\*` → `src/app/*`
- **PSR-7** HTTP Messages: via `slim/psr7`
- **PSR-12** Coding Style (siehe unten)
- **PSR-15** Middleware-Interfaces

---

## 4. Naming-Konventionen

| Kategorie | Stil | Beispiel |
|-----------|------|----------|
| Klassen | `PascalCase` | `WorkEntryController` |
| Interfaces | `PascalCase` + `Interface` (optional) | `UserRepositoryInterface` |
| Methoden | `camelCase` | `findByEmail()` |
| Properties | `camelCase` | `private PDO $pdo;` |
| Konstanten | `UPPER_SNAKE_CASE` | `const MAX_LOGIN_ATTEMPTS = 5;` |
| DB-Tabellen | `snake_case`, Plural | `work_entries`, `audit_log` (Historisch singular) |
| DB-Spalten | `snake_case` | `created_at`, `deleted_at` |
| Dateien | entsprechend Klasse | `WorkEntryController.php` |

---

## 5. Architektur-Schichten

```
Controller  (HTTP in/out, thin)
    │ injiziert
    ▼
Service     (Business-Logik, Transaktionsgrenzen)
    │ injiziert
    ▼
Repository  (SQL, PDO, Result→Model)
    │ mappt
    ▼
Model       (DTO/Value-Object, readonly wo moeglich)
```

### Controller — Beispiel

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\WorkflowService;
use App\Exceptions\BusinessRuleException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class WorkEntryController extends BaseController
{
    public function __construct(
        private readonly WorkflowService $workflow,
    ) {}

    public function approve(Request $request, Response $response, array $args): Response
    {
        $entryId = (int) $args['id'];
        $currentUserId = $this->currentUserId($request);

        try {
            $this->workflow->approve($entryId, reviewerId: $currentUserId);
        } catch (BusinessRuleException $e) {
            return $this->flashError($response, $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entryId);
    }
}
```

### Service — Beispiel

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\WorkEntryRepository;
use App\Exceptions\BusinessRuleException;

final class WorkflowService
{
    public function __construct(
        private readonly WorkEntryRepository $entries,
        private readonly AuditService $audit,
    ) {}

    public function approve(int $entryId, int $reviewerId): void
    {
        $entry = $this->entries->findOrFail($entryId);

        if ($entry->userId === $reviewerId) {
            throw new BusinessRuleException(
                'Eigene Antraege koennen nicht selbst genehmigt werden.'
            );
        }

        $this->entries->setStatus($entryId, 'freigegeben', $reviewerId);

        $this->audit->log(
            action: 'approve',
            tableName: 'work_entries',
            recordId: $entryId,
            oldValues: ['status' => $entry->status],
            newValues: ['status' => 'freigegeben'],
            description: 'Antrag freigegeben',
        );
    }
}
```

### Repository — Beispiel

```php
<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use App\Models\WorkEntry;
use App\Exceptions\BusinessRuleException;

final class WorkEntryRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findOrFail(int $id): WorkEntry
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM work_entries WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new BusinessRuleException("Antrag #$id nicht gefunden.");
        }

        return WorkEntry::fromRow($row);
    }

    public function setStatus(int $id, string $newStatus, int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE work_entries
             SET status = :status, updated_at = NOW(), updated_by = :actor
             WHERE id = :id'
        );
        $stmt->execute(['status' => $newStatus, 'actor' => $actorId, 'id' => $id]);
    }
}
```

### Model — Beispiel

```php
<?php
declare(strict_types=1);

namespace App\Models;

final class WorkEntry
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $status,
        public readonly float $hours,
        public readonly ?\DateTimeImmutable $deletedAt = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            status: $row['status'],
            hours: (float) $row['hours'],
            deletedAt: isset($row['deleted_at'])
                ? new \DateTimeImmutable($row['deleted_at'])
                : null,
        );
    }
}
```

---

## 6. Pflicht-Rituale pro Code-Aenderung

- [ ] `declare(strict_types=1);` in neuer Datei
- [ ] Typ-Hints auf Parametern, Returns, Properties
- [ ] DB-Zugriff NUR per PDO-Prepared mit named params
- [ ] Output in Views: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- [ ] Audit-Log bei jeder Business-Schreibung
- [ ] Soft-Delete — `deleted_at` setzen, nie `DELETE FROM`
- [ ] Selbstgenehmigungs-Check bei Pruefer-Aktionen
- [ ] Keine Business-Logik im Controller
- [ ] Kein SQL im Service
- [ ] Kein `$_POST`/`$_GET` im Service
- [ ] Exception-Typen aus `App\Exceptions\` verwenden

---

## 7. Tooling-Befehle

```bash
cd src

# Syntax-Check
php -l app/Controllers/WorkEntryController.php

# Autoload neu
composer dump-autoload

# PHPUnit
vendor/bin/phpunit
vendor/bin/phpunit --filter approve_rejects_self_approval

# Dev-Server
cd public && php -S localhost:8000
```

---

## 8. Haeufige Fallen

| Falle | Fix |
|-------|-----|
| `$pdo->query($sql)` mit Variablen | Immer `prepare()` + `execute()` |
| Controller macht SQL | In Repository verschieben |
| Status-String hartcodiert | Konstante oder Enum |
| E-Mail-Versand im Controller | `EmailService` nutzen, Controller nur ausloesen |
| Dialog-Loeschung bei Status-Reset | Dialog bleibt IMMER erhalten |
| `die()`/`exit()` statt Response | Response-Objekt zurueckgeben |
| Neue Migration ohne Rollback | `DROP`/`REVERSE`-SQL vorbereiten |

---

*Referenz: `docs/REQUIREMENTS.md`, `docs/ARCHITECTURE.md`*
