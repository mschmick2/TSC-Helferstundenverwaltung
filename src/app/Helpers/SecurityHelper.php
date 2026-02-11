<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Sicherheits-Hilfsfunktionen
 */
class SecurityHelper
{
    /**
     * CSRF-Token generieren (einmal pro Session)
     */
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * CSRF-Token validieren (timing-safe)
     */
    public static function validateCsrfToken(?string $token): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if ($sessionToken === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }

    /**
     * Passwort hashen (bcrypt, cost 12)
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Passwort verifizieren
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Sicheren Token generieren (für Einladungen, Passwort-Reset)
     */
    public static function generateToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Zufälligen numerischen Code generieren (für E-Mail-2FA)
     */
    public static function generateNumericCode(int $digits = 6): string
    {
        $min = (int) str_pad('1', $digits, '0');
        $max = (int) str_pad('', $digits, '9');
        return (string) random_int($min, $max);
    }

    /**
     * Passwort-Validierung gem. Anforderungen
     * Min. 8 Zeichen, Groß-/Kleinbuchstaben, Ziffern
     *
     * @return string[] Array mit Fehlermeldungen (leer = gültig)
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Das Passwort muss mindestens einen Großbuchstaben enthalten.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Das Passwort muss mindestens einen Kleinbuchstaben enthalten.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Das Passwort muss mindestens eine Ziffer enthalten.';
        }

        return $errors;
    }
}
