<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Benutzer-/Mitglieder-Modell
 */
class User
{
    private ?int $id = null;
    private string $mitgliedsnummer = '';
    private string $email = '';
    private ?string $passwordHash = null;
    private string $vorname = '';
    private string $nachname = '';
    private ?string $strasse = null;
    private ?string $plz = null;
    private ?string $ort = null;
    private ?string $telefon = null;
    private ?string $eintrittsdatum = null;

    // 2FA
    private ?string $totpSecret = null;
    private bool $totpEnabled = false;
    private bool $email2faEnabled = false;

    // Status
    private bool $isActive = true;
    private ?string $emailVerifiedAt = null;
    private ?string $passwordChangedAt = null;
    private ?string $lastLoginAt = null;
    private int $failedLoginAttempts = 0;
    private ?string $lockedUntil = null;

    // Timestamps
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;

    /** @var string[] */
    private array $roles = [];

    /**
     * User aus Datenbank-Array erstellen
     */
    public static function fromArray(array $data): self
    {
        $user = new self();
        $user->id = isset($data['id']) ? (int) $data['id'] : null;
        $user->mitgliedsnummer = $data['mitgliedsnummer'] ?? '';
        $user->email = $data['email'] ?? '';
        $user->passwordHash = $data['password_hash'] ?? null;
        $user->vorname = $data['vorname'] ?? '';
        $user->nachname = $data['nachname'] ?? '';
        $user->strasse = $data['strasse'] ?? null;
        $user->plz = $data['plz'] ?? null;
        $user->ort = $data['ort'] ?? null;
        $user->telefon = $data['telefon'] ?? null;
        $user->eintrittsdatum = $data['eintrittsdatum'] ?? null;
        $user->totpSecret = $data['totp_secret'] ?? null;
        $user->totpEnabled = (bool) ($data['totp_enabled'] ?? false);
        $user->email2faEnabled = (bool) ($data['email_2fa_enabled'] ?? false);
        $user->isActive = (bool) ($data['is_active'] ?? true);
        $user->emailVerifiedAt = $data['email_verified_at'] ?? null;
        $user->passwordChangedAt = $data['password_changed_at'] ?? null;
        $user->lastLoginAt = $data['last_login_at'] ?? null;
        $user->failedLoginAttempts = (int) ($data['failed_login_attempts'] ?? 0);
        $user->lockedUntil = $data['locked_until'] ?? null;
        $user->createdAt = $data['created_at'] ?? null;
        $user->updatedAt = $data['updated_at'] ?? null;
        $user->deletedAt = $data['deleted_at'] ?? null;

        return $user;
    }

    // =========================================================================
    // Getter
    // =========================================================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMitgliedsnummer(): string
    {
        return $this->mitgliedsnummer;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function getVorname(): string
    {
        return $this->vorname;
    }

    public function getNachname(): string
    {
        return $this->nachname;
    }

    public function getVollname(): string
    {
        return $this->vorname . ' ' . $this->nachname;
    }

    public function getStrasse(): ?string
    {
        return $this->strasse;
    }

    public function getPlz(): ?string
    {
        return $this->plz;
    }

    public function getOrt(): ?string
    {
        return $this->ort;
    }

    public function getTelefon(): ?string
    {
        return $this->telefon;
    }

    public function getEintrittsdatum(): ?string
    {
        return $this->eintrittsdatum;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function isEmail2faEnabled(): bool
    {
        return $this->email2faEnabled;
    }

    public function is2faEnabled(): bool
    {
        return $this->totpEnabled || $this->email2faEnabled;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getEmailVerifiedAt(): ?string
    {
        return $this->emailVerifiedAt;
    }

    public function getPasswordChangedAt(): ?string
    {
        return $this->passwordChangedAt;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function getLockedUntil(): ?string
    {
        return $this->lockedUntil;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    /**
     * Prüft ob der Account aktuell gesperrt ist
     */
    public function isLocked(): bool
    {
        if ($this->lockedUntil === null) {
            return false;
        }
        return new \DateTime($this->lockedUntil) > new \DateTime();
    }

    // =========================================================================
    // Rollen
    // =========================================================================

    /**
     * @param string[] $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('administrator');
    }

    public function isPruefer(): bool
    {
        return $this->hasRole('pruefer');
    }

    public function isErfasser(): bool
    {
        return $this->hasRole('erfasser');
    }

    public function isAuditor(): bool
    {
        return $this->hasRole('auditor');
    }

    /**
     * Prüft ob der Benutzer Stunden für andere erfassen darf
     */
    public function canCreateForOthers(): bool
    {
        return $this->isErfasser() || $this->isPruefer() || $this->isAdmin();
    }

    /**
     * Prüft ob der Benutzer Anträge prüfen darf
     */
    public function canReview(): bool
    {
        return $this->isPruefer() || $this->isAdmin();
    }

    // =========================================================================
    // Setter
    // =========================================================================

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    public function setTotpSecret(?string $secret): void
    {
        $this->totpSecret = $secret;
    }

    public function setTotpEnabled(bool $enabled): void
    {
        $this->totpEnabled = $enabled;
    }

    public function setEmail2faEnabled(bool $enabled): void
    {
        $this->email2faEnabled = $enabled;
    }
}
