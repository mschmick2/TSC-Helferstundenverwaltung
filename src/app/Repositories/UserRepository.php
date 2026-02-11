<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;

/**
 * Repository für Benutzer-Datenbankzugriffe
 */
class UserRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Benutzer anhand ID finden
     */
    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();

        if ($data === false) {
            return null;
        }

        $user = User::fromArray($data);
        $user->setRoles($this->getUserRoleNames($id));
        return $user;
    }

    /**
     * Benutzer anhand E-Mail finden (für Login)
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE email = :email AND deleted_at IS NULL"
        );
        $stmt->execute(['email' => $email]);
        $data = $stmt->fetch();

        if ($data === false) {
            return null;
        }

        $user = User::fromArray($data);
        $user->setRoles($this->getUserRoleNames((int) $data['id']));
        return $user;
    }

    /**
     * Benutzer anhand Mitgliedsnummer finden
     */
    public function findByMitgliedsnummer(string $nummer): ?User
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE mitgliedsnummer = :nummer AND deleted_at IS NULL"
        );
        $stmt->execute(['nummer' => $nummer]);
        $data = $stmt->fetch();

        if ($data === false) {
            return null;
        }

        $user = User::fromArray($data);
        $user->setRoles($this->getUserRoleNames((int) $data['id']));
        return $user;
    }

    /**
     * Rollennamen eines Benutzers abrufen
     *
     * @return string[]
     */
    public function getUserRoleNames(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.name
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Passwort aktualisieren
     */
    public function updatePassword(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET password_hash = :hash, password_changed_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['hash' => $passwordHash, 'id' => $userId]);
    }

    /**
     * Fehlgeschlagene Login-Versuche erhöhen
     */
    public function incrementFailedAttempts(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);

        $stmt = $this->pdo->prepare(
            "SELECT failed_login_attempts FROM users WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fehlversuche zurücksetzen
     */
    public function resetFailedAttempts(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Account für angegebene Dauer sperren
     */
    public function lockAccount(int $userId, int $durationSeconds): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL :duration SECOND) WHERE id = :id"
        );
        $stmt->execute(['duration' => $durationSeconds, 'id' => $userId]);
    }

    /**
     * Letzten Login aktualisieren
     */
    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET last_login_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /**
     * TOTP-Secret speichern
     */
    public function updateTotpSecret(int $userId, string $secret): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET totp_secret = :secret, totp_enabled = TRUE WHERE id = :id"
        );
        $stmt->execute(['secret' => $secret, 'id' => $userId]);
    }

    /**
     * E-Mail-2FA aktivieren
     */
    public function enableEmail2fa(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET email_2fa_enabled = TRUE WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /**
     * E-Mail als verifiziert markieren
     */
    public function markEmailVerified(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET email_verified_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    // =========================================================================
    // Einladungen
    // =========================================================================

    /**
     * Benutzer anhand Einladungstoken finden
     */
    public function findByInvitationToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.*, ui.id AS invitation_id, ui.expires_at AS invitation_expires_at
             FROM user_invitations ui
             JOIN users u ON ui.user_id = u.id
             WHERE ui.token = :token AND ui.used_at IS NULL AND u.deleted_at IS NULL"
        );
        $stmt->execute(['token' => $token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Einladung als verwendet markieren
     */
    public function markInvitationUsed(int $invitationId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE user_invitations SET used_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $invitationId]);
    }

    // =========================================================================
    // Passwort-Reset
    // =========================================================================

    /**
     * Passwort-Reset-Token erstellen
     */
    public function createPasswordReset(int $userId, string $token, int $expiryHours = 1): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO password_resets (user_id, token, expires_at)
             VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL :hours HOUR))"
        );
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'hours' => $expiryHours,
        ]);
    }

    /**
     * Benutzer anhand Reset-Token finden
     */
    public function findByResetToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.*, pr.id AS reset_id, pr.expires_at AS reset_expires_at
             FROM password_resets pr
             JOIN users u ON pr.user_id = u.id
             WHERE pr.token = :token AND pr.used_at IS NULL AND u.deleted_at IS NULL"
        );
        $stmt->execute(['token' => $token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Reset-Token als verwendet markieren
     */
    public function markResetUsed(int $resetId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE password_resets SET used_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $resetId]);
    }

    /**
     * Alle aktiven Benutzer mit einer bestimmten Rolle finden
     *
     * @return User[]
     */
    public function findByRole(string $roleName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.*
             FROM users u
             JOIN user_roles ur ON u.id = ur.user_id
             JOIN roles r ON ur.role_id = r.id
             WHERE r.name = :role AND u.deleted_at IS NULL AND u.is_active = TRUE
             ORDER BY u.nachname, u.vorname"
        );
        $stmt->execute(['role' => $roleName]);

        $users = [];
        while ($data = $stmt->fetch()) {
            $user = User::fromArray($data);
            $user->setRoles($this->getUserRoleNames((int) $data['id']));
            $users[] = $user;
        }

        return $users;
    }

    // =========================================================================
    // Admin-Methoden
    // =========================================================================

    /**
     * Paginierte Benutzerliste für Admin
     *
     * @return array{users: User[], total: int}
     */
    public function findAllPaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $search = null,
        ?string $role = null,
        bool $includeInactive = false
    ): array {
        $where = [];
        $params = [];

        if (!$includeInactive) {
            $where[] = 'u.deleted_at IS NULL';
        }

        if ($search !== null && $search !== '') {
            $where[] = '(u.vorname LIKE :search OR u.nachname LIKE :search OR u.email LIKE :search OR u.mitgliedsnummer LIKE :search)';
            $params['search'] = "%{$search}%";
        }

        if ($role !== null && $role !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON ur2.role_id = r2.id WHERE ur2.user_id = u.id AND r2.name = :role)';
            $params['role'] = $role;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total zählen
        $countStmt = $this->pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Daten laden
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT u.*, GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ',') AS role_names
                FROM users u
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                LEFT JOIN roles r ON ur.role_id = r.id
                {$whereSql}
                GROUP BY u.id
                ORDER BY u.nachname ASC, u.vorname ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $users = [];
        while ($row = $stmt->fetch()) {
            $user = User::fromArray($row);
            $roles = !empty($row['role_names']) ? explode(',', $row['role_names']) : [];
            $user->setRoles($roles);
            $users[] = $user;
        }

        return ['users' => $users, 'total' => $total];
    }

    /**
     * Benutzer anhand Mitgliedsnummer finden (inkl. soft-deleted, für Import)
     */
    public function findByMitgliedsnummerIncludeDeleted(string $nummer): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE mitgliedsnummer = :nummer"
        );
        $stmt->execute(['nummer' => $nummer]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }

    /**
     * E-Mail suchen (inkl. geloeschter Benutzer, fuer Duplikatpruefung)
     */
    public function findByEmailIncludeDeleted(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE email = :email"
        );
        $stmt->execute(['email' => $email]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }

    /**
     * Neuen Benutzer erstellen
     */
    public function createUser(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (mitgliedsnummer, email, vorname, nachname, strasse, plz, ort, telefon, eintrittsdatum, is_active)
             VALUES (:mitgliedsnummer, :email, :vorname, :nachname, :strasse, :plz, :ort, :telefon, :eintrittsdatum, :is_active)"
        );
        $stmt->execute([
            'mitgliedsnummer' => $data['mitgliedsnummer'],
            'email' => $data['email'],
            'vorname' => $data['vorname'],
            'nachname' => $data['nachname'],
            'strasse' => $data['strasse'] ?? null,
            'plz' => $data['plz'] ?? null,
            'ort' => $data['ort'] ?? null,
            'telefon' => $data['telefon'] ?? null,
            'eintrittsdatum' => $data['eintrittsdatum'] ?? null,
            'is_active' => 1,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Stammdaten aktualisieren
     */
    public function updateStammdaten(int $userId, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET
                vorname = :vorname, nachname = :nachname, email = :email,
                strasse = :strasse, plz = :plz, ort = :ort,
                telefon = :telefon, eintrittsdatum = :eintrittsdatum
             WHERE id = :id"
        );
        $stmt->execute([
            'vorname' => $data['vorname'],
            'nachname' => $data['nachname'],
            'email' => $data['email'],
            'strasse' => $data['strasse'] ?? null,
            'plz' => $data['plz'] ?? null,
            'ort' => $data['ort'] ?? null,
            'telefon' => $data['telefon'] ?? null,
            'eintrittsdatum' => $data['eintrittsdatum'] ?? null,
            'id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Alle Rollen laden
     *
     * @return array[]
     */
    public function findAllRoles(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM roles ORDER BY id");
        return $stmt->fetchAll();
    }

    /**
     * Alle Rollen eines Benutzers ersetzen
     *
     * @param int[] $roleIds
     */
    public function replaceRoles(int $userId, array $roleIds, int $assignedBy): void
    {
        $this->pdo->beginTransaction();
        try {
            // Alte Rollen entfernen
            $stmt = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);

            // Neue Rollen zuweisen
            if (!empty($roleIds)) {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:user_id, :role_id, :assigned_by)"
                );
                foreach ($roleIds as $roleId) {
                    $stmt->execute([
                        'user_id' => $userId,
                        'role_id' => (int) $roleId,
                        'assigned_by' => $assignedBy,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Einladung erstellen
     */
    public function createInvitation(int $userId, string $token, int $expiryDays, int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_invitations (user_id, token, expires_at, created_by)
             VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL :days DAY), :created_by)"
        );
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'days' => $expiryDays,
            'created_by' => $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Einladung als gesendet markieren
     */
    public function markInvitationSent(int $invitationId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE user_invitations SET sent_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $invitationId]);
    }

    /**
     * Letzte Einladung eines Benutzers abrufen
     */
    public function getLatestInvitation(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM user_invitations WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }

    /**
     * Benutzer soft-löschen (deaktivieren)
     */
    public function softDeleteUser(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET deleted_at = NOW(), is_active = FALSE WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Benutzer wiederherstellen
     */
    public function restoreUser(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET deleted_at = NULL, is_active = TRUE WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Rohdaten für Audit-Trail
     */
    public function getRawById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }

    /**
     * Benutzer anhand ID finden (inkl. inaktive/gelöschte, für Admin)
     */
    public function findByIdForAdmin(int $id): ?User
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();

        if ($data === false) {
            return null;
        }

        $user = User::fromArray($data);
        $user->setRoles($this->getUserRoleNames($id));
        return $user;
    }

    /**
     * Mitglied-Rolle anhand Name finden
     */
    public function getRoleByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM roles WHERE name = :name");
        $stmt->execute(['name' => $name]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }
}
