<?php
/**
 * VAES - Admin-Passwort korrigieren
 * 
 * Dieses Script setzt das Passwort für den Admin-Benutzer neu.
 * NACH DER VERWENDUNG SOFORT LÖSCHEN!
 */

// Datenbank-Konfiguration (ANPASSEN!)
$dbConfig = [
    'host'     => '***REMOVED***',  // IONOS DB-Host anpassen!
    'port'     => 3306,
    'name'     => '***REMOVED***',                    // DB-Name anpassen!
    'user'     => '***REMOVED***',                     // DB-User anpassen!
    'password' => '',                               // DB-Passwort eintragen!
];

// Admin-Daten
$adminEmail = '***REMOVED***';
$newPassword = '***REMOVED***';

// ============================================================================

header('Content-Type: text/html; charset=utf-8');
echo "<h1>VAES - Admin-Passwort korrigieren</h1>";

try {
    // Verbindung herstellen
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "<p style='color:green'>✓ Datenbankverbindung erfolgreich</p>";
    
    // Korrekten Hash generieren
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    
    echo "<p><strong>Generierter Hash:</strong><br><code>{$hash}</code></p>";
    
    // Prüfen ob Benutzer existiert
    $stmt = $pdo->prepare("SELECT id, email, password_hash FROM users WHERE email = ?");
    $stmt->execute([$adminEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "<p>✓ Benutzer gefunden: ID {$user['id']}, E-Mail: {$user['email']}</p>";
        echo "<p>Alter Hash: <code>" . substr($user['password_hash'] ?? 'LEER', 0, 30) . "...</code></p>";
        
        // Passwort aktualisieren
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE email = ?");
        $updateStmt->execute([$hash, $adminEmail]);
        
        echo "<p style='color:green; font-weight:bold'>✓ Passwort erfolgreich aktualisiert!</p>";
        
        // Verifizieren
        $verifyStmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = ?");
        $verifyStmt->execute([$adminEmail]);
        $newHash = $verifyStmt->fetchColumn();
        
        if (password_verify($newPassword, $newHash)) {
            echo "<p style='color:green'>✓ Passwort-Verifikation erfolgreich!</p>";
            echo "<hr>";
            echo "<p><strong>Login-Daten:</strong></p>";
            echo "<ul>";
            echo "<li>E-Mail: <code>{$adminEmail}</code></li>";
            echo "<li>Passwort: <code>{$newPassword}</code></li>";
            echo "</ul>";
        } else {
            echo "<p style='color:red'>✗ Passwort-Verifikation fehlgeschlagen!</p>";
        }
        
    } else {
        echo "<p style='color:orange'>⚠ Benutzer nicht gefunden. Lege neuen Benutzer an...</p>";
        
        // Neuen Benutzer anlegen
        $insertStmt = $pdo->prepare("
            INSERT INTO users (mitgliedsnummer, email, password_hash, vorname, nachname, is_active, email_verified_at)
            VALUES (?, ?, ?, ?, ?, TRUE, NOW())
        ");
        $insertStmt->execute(['ADMIN001', $adminEmail, $hash, 'Manfred', 'Schmickler']);
        
        $userId = $pdo->lastInsertId();
        echo "<p style='color:green'>✓ Benutzer angelegt mit ID: {$userId}</p>";
        
        // Rollen zuweisen
        $roleStmt = $pdo->prepare("
            INSERT INTO user_roles (user_id, role_id)
            SELECT ?, id FROM roles WHERE name IN ('mitglied', 'administrator')
        ");
        $roleStmt->execute([$userId]);
        
        echo "<p style='color:green'>✓ Rollen (mitglied, administrator) zugewiesen</p>";
    }
    
    // Rollen anzeigen
    $rolesStmt = $pdo->prepare("
        SELECT r.name 
        FROM user_roles ur 
        JOIN roles r ON ur.role_id = r.id 
        JOIN users u ON ur.user_id = u.id 
        WHERE u.email = ?
    ");
    $rolesStmt->execute([$adminEmail]);
    $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>Zugewiesene Rollen:</strong> " . implode(', ', $roles) . "</p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>✗ Datenbankfehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p style='color:red; font-weight:bold'>⚠️ WICHTIG: Diese Datei jetzt LÖSCHEN!</p>";