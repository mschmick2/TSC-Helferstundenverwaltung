<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repository für Systemeinstellungen
 */
class SettingsRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Alle Einstellungen laden
     *
     * @return array<string, array{setting_key: string, setting_value: ?string, setting_type: string, description: ?string, is_public: bool}>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM settings ORDER BY setting_key ASC"
        );

        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row;
        }
        return $settings;
    }

    /**
     * Einzelnen Wert abrufen
     */
    public function getValue(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT setting_value FROM settings WHERE setting_key = :key"
        );
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        return $row !== false ? $row['setting_value'] : $default;
    }

    /**
     * Mehrere Werte auf einmal abrufen
     *
     * @param string[] $keys
     * @return array<string, ?string>
     */
    public function getMultiple(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ({$placeholders})"
        );
        $stmt->execute(array_values($keys));

        $result = [];
        while ($row = $stmt->fetch()) {
            $result[$row['setting_key']] = $row['setting_value'];
        }

        // Fehlende Keys mit null auffüllen
        foreach ($keys as $key) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = null;
            }
        }

        return $result;
    }

    /**
     * Einstellung aktualisieren
     */
    public function update(string $key, ?string $value, int $updatedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE settings SET setting_value = :value, updated_by = :updated_by
             WHERE setting_key = :key"
        );
        $stmt->execute([
            'value' => $value,
            'updated_by' => $updatedBy,
            'key' => $key,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Nur öffentliche Einstellungen laden (für Frontend)
     *
     * @return array<string, ?string>
     */
    public function getPublicSettings(): array
    {
        $stmt = $this->pdo->query(
            "SELECT setting_key, setting_value FROM settings WHERE is_public = TRUE"
        );

        $result = [];
        while ($row = $stmt->fetch()) {
            $result[$row['setting_key']] = $row['setting_value'];
        }
        return $result;
    }
}
