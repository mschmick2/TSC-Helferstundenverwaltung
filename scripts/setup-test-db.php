<?php

declare(strict_types=1);

/**
 * setup-test-db.php
 *
 * Klont das Schema einer Entwickler-Datenbank in die Test-Datenbank.
 * Verwendung:
 *   php scripts/setup-test-db.php
 *
 * Environment-Variablen (mit Defaults):
 *   DB_HOST          127.0.0.1
 *   DB_PORT          3306
 *   DB_USERNAME      root
 *   DB_PASSWORD      ""
 *   DB_DATABASE      vaes         (Quelle - Dev-Schema)
 *   DB_TEST_DATABASE vaes_test    (Ziel)
 *
 * Prinzip:
 *   1. Ziel-DB anlegen (falls fehlt)
 *   2. Ziel-DB leeren (alle Tabellen droppen)
 *   3. Fuer jede Tabelle der Quelle: CREATE TABLE im Ziel replizieren
 *   4. FK-Checks waehrend Klonen deaktiviert
 *
 * Daten werden NICHT kopiert. Jeder Integration-/Feature-Test laeuft in einer
 * Transaktion und rollbackt am Ende. Somit keine Daten-Persistenz.
 */

$host   = getenv('DB_HOST')          ?: '127.0.0.1';
$port   = (int) (getenv('DB_PORT')   ?: 3306);
$user   = getenv('DB_USERNAME')      ?: 'root';
$pass   = getenv('DB_PASSWORD')      ?: '';
$source = getenv('DB_DATABASE')      ?: 'vaes';
$target = getenv('DB_TEST_DATABASE') ?: 'vaes_test';

echo "=== VAES Test-DB Setup ===\n";
echo "Host:   $host:$port\n";
echo "User:   $user\n";
echo "Quelle: $source\n";
echo "Ziel:   $target\n\n";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "FEHLER: MySQL-Verbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

// 1. Ziel-DB erzeugen
try {
    $pdo->exec(
        "CREATE DATABASE IF NOT EXISTS `$target`
         DEFAULT CHARACTER SET utf8mb4
         DEFAULT COLLATE utf8mb4_unicode_ci"
    );
    echo "✓ Ziel-DB '$target' existiert (oder wurde angelegt).\n";
} catch (PDOException $e) {
    fwrite(STDERR, "FEHLER: Ziel-DB konnte nicht erstellt werden: " . $e->getMessage() . "\n");
    exit(1);
}

// 2. Quelle vorhanden?
$stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n');
$stmt->execute(['n' => $source]);
if ($stmt->fetch() === false) {
    fwrite(STDERR, "FEHLER: Quell-DB '$source' existiert nicht. Bitte zuerst anlegen und\n");
    fwrite(STDERR, "        scripts/database/create_database.sql einspielen.\n");
    exit(1);
}

// 3. FK-Checks aus
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

// 4. Bestehende Tabellen im Ziel droppen
$pdo->exec("USE `$target`");
$tablesInTarget = $pdo
    ->query("SHOW TABLES FROM `$target`")
    ->fetchAll(PDO::FETCH_COLUMN);
foreach ($tablesInTarget as $t) {
    $pdo->exec("DROP TABLE IF EXISTS `$target`.`$t`");
}
echo "✓ Ziel-DB geleert (" . count($tablesInTarget) . " Tabellen entfernt).\n";

// 5. Tabellen aus Quelle klonen
$tablesInSource = $pdo
    ->query("SHOW TABLES FROM `$source`")
    ->fetchAll(PDO::FETCH_COLUMN);

$count = 0;
foreach ($tablesInSource as $table) {
    $row = $pdo->query("SHOW CREATE TABLE `$source`.`$table`")->fetch(PDO::FETCH_ASSOC);
    $ddl = $row['Create Table'] ?? null;
    if ($ddl === null) {
        fwrite(STDERR, "  ! Tabelle '$table' konnte nicht gelesen werden, uebersprungen.\n");
        continue;
    }

    // Falls DDL einen DEFINER hat, entfernen (Triggers koennen sonst beim Import blockieren).
    $ddl = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s+/i', '', $ddl);

    $pdo->exec($ddl);
    $count++;
}
echo "✓ $count Tabellen im Schema repliziert.\n";

// 6. Trigger und Views optional mitnehmen
$triggers = $pdo
    ->query("SHOW TRIGGERS FROM `$source`")
    ->fetchAll(PDO::FETCH_ASSOC);

foreach ($triggers as $trg) {
    $name = $trg['Trigger'];
    $stmt = $pdo->query("SHOW CREATE TRIGGER `$source`.`$name`");
    $trgRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $trgDdl = $trgRow['SQL Original Statement'] ?? null;
    if ($trgDdl === null) {
        continue;
    }
    $trgDdl = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s+/i', '', $trgDdl);
    // Trigger im Ziel droppen, dann anlegen (mit USE target davor)
    $pdo->exec("USE `$target`");
    $pdo->exec("DROP TRIGGER IF EXISTS `$name`");
    try {
        $pdo->exec($trgDdl);
    } catch (PDOException $e) {
        fwrite(STDERR, "  ! Trigger '$name' nicht uebernommen: " . $e->getMessage() . "\n");
    }
}
if ($triggers !== []) {
    echo "✓ " . count($triggers) . " Trigger uebernommen.\n";
}

// 7. FK-Checks wieder ein
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\n✓ Test-DB '$target' bereit.\n";
exit(0);
