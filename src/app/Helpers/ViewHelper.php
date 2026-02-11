<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Helper-Funktionen für View-Templates
 */
class ViewHelper
{
    /**
     * HTML-Escaping (XSS-Schutz)
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * CSRF Hidden-Input generieren
     */
    public static function csrfField(): string
    {
        $token = $_SESSION['csrf_token'] ?? '';
        return '<input type="hidden" name="csrf_token" value="' . self::e($token) . '">';
    }

    /**
     * Alten Formularwert abrufen (nach Validation-Error)
     */
    public static function old(string $field, string $default = ''): string
    {
        return self::e($_SESSION['_old_input'][$field] ?? $default);
    }

    /**
     * Flash-Messages abrufen und aus Session entfernen
     *
     * @return array<string, string[]>
     */
    public static function getFlashMessages(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $messages;
    }

    /**
     * Flash-Message setzen
     */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /**
     * Datum formatieren (deutsches Format)
     */
    public static function formatDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $dt = new \DateTime($date);
        return $dt->format('d.m.Y');
    }

    /**
     * Datum+Zeit formatieren (deutsches Format)
     */
    public static function formatDateTime(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }
        $dt = new \DateTime($datetime);
        return $dt->format('d.m.Y H:i');
    }

    /**
     * Dezimalstunden formatieren
     */
    public static function formatHours(float|string|null $hours): string
    {
        if ($hours === null) {
            return '0,00';
        }
        return number_format((float) $hours, 2, ',', '.');
    }

    /**
     * Alte Formulardaten in Session speichern
     */
    public static function flashOldInput(array $data): void
    {
        $_SESSION['_old_input'] = $data;
    }

    /**
     * Alte Formulardaten aus Session löschen
     */
    public static function clearOldInput(): void
    {
        unset($_SESSION['_old_input']);
    }

    // =========================================================================
    // URL-Generierung (Unterverzeichnis-Support)
    // =========================================================================

    /**
     * Basis-Pfad fuer Unterverzeichnis-Installation
     */
    private static string $basePath = '';

    /**
     * Basis-Pfad setzen (einmalig in index.php aufrufen)
     */
    public static function setBasePath(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    /**
     * URL mit Basis-Pfad generieren
     *
     * @param string $path Pfad relativ zum App-Root, z.B. '/entries' oder '/css/app.css'
     * @return string Vollstaendiger Pfad, z.B. '/helferstunden/entries'
     */
    public static function url(string $path): string
    {
        if ($path === '' || $path === '/') {
            return self::$basePath . '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return self::$basePath . $path;
    }

    /**
     * Basis-Pfad abrufen
     */
    public static function getBasePath(): string
    {
        return self::$basePath;
    }
}
