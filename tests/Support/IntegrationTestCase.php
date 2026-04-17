<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Basis fuer Integration-Tests mit echter MySQL-Verbindung zur Test-DB.
 *
 * Jeder Test laeuft in einer Transaktion, die in tearDown zurueckgerollt wird.
 * Somit keine Daten-Persistenz zwischen Tests.
 *
 * Verwendet wird eine singleton-PDO-Verbindung (self::$pdo), damit Feature-Tests
 * und die App-Instanz dieselbe Verbindung teilen koennen.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$pdo === null) {
            self::$pdo = self::createPdo();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$pdo === null) {
            self::$pdo = self::createPdo();
        }
        if (self::$pdo->inTransaction()) {
            // Defensiv: falls ein Vorlauf-Test nicht korrekt rollbackte
            self::$pdo->rollBack();
        }
        self::$pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        parent::tearDown();
    }

    /**
     * Die aktive PDO-Testverbindung.
     */
    protected function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::createPdo();
        }
        return self::$pdo;
    }

    /**
     * PDO fuer die Test-DB aufbauen.
     * Synchronisiert Zeitzone zwischen PHP und MySQL auf UTC.
     */
    private static function createPdo(): PDO
    {
        $host   = getenv('DB_HOST')          ?: '127.0.0.1';
        $port   = (int) (getenv('DB_PORT')   ?: 3306);
        $user   = getenv('DB_USERNAME')      ?: 'root';
        $pass   = getenv('DB_PASSWORD')      ?: '';
        $target = getenv('DB_TEST_DATABASE') ?: 'vaes_test';

        try {
            $pdo = new PDO(
                "mysql:host=$host;port=$port;dbname=$target;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (\PDOException $e) {
            throw new RuntimeException(
                "Test-DB '$target' nicht erreichbar. Bitte 'php scripts/setup-test-db.php' ausfuehren. "
                . 'Details: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // Zeitzone sync (verhindert NOW()-Abweichungen)
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
