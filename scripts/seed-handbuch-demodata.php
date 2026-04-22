<?php

declare(strict_types=1);

/**
 * seed-handbuch-demodata.php
 *
 * Legt Demo-Daten fuer die Benutzerhandbuch-Screenshots in die E2E-DB.
 * Erwartet: helferstunden_e2e bereits per setup-e2e-db.php aufgesetzt.
 *
 * Erzeugt:
 *   - 4 work_entries fuer Alice in verschiedenen Status (Entwurf, Eingereicht,
 *     In Klaerung, Freigegeben)
 *   - Dialog-Nachrichten fuer den In-Klaerung-Eintrag
 *   - 1 Event "Sommerfest 2026" mit zwei Aufgaben (Aufbau, Theke)
 *   - 1 Event-Vorlage "Saison-Abschlussfest"
 *   - Yearly-Target fuer Alice (Soll 30h)
 */

$host = getenv('DB_HOST')         ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT')  ?: 3306);
$user = getenv('DB_USERNAME')     ?: 'root';
$pass = getenv('DB_PASSWORD')     ?: '';
$db   = getenv('DB_E2E_DATABASE') ?: 'helferstunden_e2e';

$pdo = new PDO(
    "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// User-IDs
$byEmail = [];
foreach ($pdo->query('SELECT id, email FROM users') as $row) {
    $byEmail[$row['email']] = (int) $row['id'];
}
$alice   = $byEmail['alice@e2e.local'] ?? null;
$bob     = $byEmail['bob@e2e.local'] ?? null;
$pruefer = $byEmail['pruefer@e2e.local'] ?? null;
$admin   = $byEmail['admin@e2e.local'] ?? null;
if (!$alice || !$pruefer || !$admin) {
    fwrite(STDERR, "Seed-User fehlen. Bitte erst setup-e2e-db.php ausfuehren.\n");
    exit(1);
}

$catIds = [];
foreach ($pdo->query('SELECT id, name FROM categories') as $row) {
    $catIds[$row['name']] = (int) $row['id'];
}
$catRasen = $catIds['Rasenpflege'] ?? 1;
$catGeb   = $catIds['Gebäudepflege'] ?? 2;
$catVer   = $catIds['Veranstaltungen'] ?? 3;

// ----- 1. work_entries fuer Alice -----
$pdo->exec('DELETE FROM work_entries WHERE user_id = ' . $alice);

$year = (int) date('Y');
$mkNumber = fn(int $n): string => sprintf('%04d-%05d', $year, $n);

$entries = [
    [
        'nr' => 1, 'cat' => $catRasen, 'date' => date('Y-m-d', strtotime('-2 days')),
        'tf' => '09:00:00', 'tt' => '11:30:00', 'h' => 2.50,
        'p' => 'Rasen vor Vereinsheim',
        'd' => 'Wiese gemaeht und vertikutiert, Kanten nachgeschnitten.',
        's' => 'entwurf',
    ],
    [
        'nr' => 2, 'cat' => $catGeb, 'date' => date('Y-m-d', strtotime('-5 days')),
        'tf' => '14:00:00', 'tt' => '17:00:00', 'h' => 3.00,
        'p' => 'Umkleide streichen',
        'd' => 'Waende gestrichen und Boden gereinigt.',
        's' => 'eingereicht',
    ],
    [
        'nr' => 3, 'cat' => $catVer, 'date' => date('Y-m-d', strtotime('-10 days')),
        'tf' => '18:00:00', 'tt' => '22:30:00', 'h' => 4.50,
        'p' => 'Mitgliederversammlung',
        'd' => 'Auf- und Abbau, Getraenke-Ausgabe.',
        's' => 'in_klaerung',
    ],
    [
        'nr' => 4, 'cat' => $catRasen, 'date' => date('Y-m-d', strtotime('-20 days')),
        'tf' => '10:00:00', 'tt' => '12:00:00', 'h' => 2.00,
        'p' => 'Spielfeld-Pflege',
        'd' => 'Lines neu gezogen, Loecher ausgefuellt.',
        's' => 'freigegeben',
    ],
];

$ins = $pdo->prepare(
    'INSERT INTO work_entries (entry_number, user_id, created_by_user_id, category_id,
        work_date, time_from, time_to, hours, project, description, status,
        reviewed_by_user_id, reviewed_at, created_at)
     VALUES (:nr, :uid, :cb, :cat, :d, :tf, :tt, :h, :p, :desc, :s,
             :rev, :revAt, NOW())'
);

$entryIds = [];
foreach ($entries as $e) {
    $rev   = $e['s'] === 'freigegeben' ? $pruefer : null;
    $revAt = $rev ? date('Y-m-d H:i:s', strtotime($e['date'] . ' +1 day 09:00:00')) : null;
    $ins->execute([
        'nr'    => $mkNumber($e['nr']),
        'uid'   => $alice,
        'cb'    => $alice,
        'cat'   => $e['cat'],
        'd'     => $e['date'],
        'tf'    => $e['tf'],
        'tt'    => $e['tt'],
        'h'     => $e['h'],
        'p'     => $e['p'],
        'desc'  => $e['d'],
        's'     => $e['s'],
        'rev'   => $rev,
        'revAt' => $revAt,
    ]);
    $entryIds[$e['nr']] = (int) $pdo->lastInsertId();
}
echo "  OK 4 work_entries fuer Alice angelegt\n";

// Dialog: Rueckfrage + Antwort fuer in-klaerung Eintrag
$pdo->exec('DELETE FROM work_entry_dialogs WHERE work_entry_id = ' . $entryIds[3]);
$dlg = $pdo->prepare(
    'INSERT INTO work_entry_dialogs (work_entry_id, user_id, message, is_question, is_answered, created_at)
     VALUES (:eid, :uid, :msg, :q, :a, :at)'
);
$dlg->execute([
    'eid' => $entryIds[3], 'uid' => $pruefer,
    'msg' => 'Hallo Alice, koenntest du kurz erlaeutern, wieviele Personen bei der Versammlung geholfen haben? Danke.',
    'q'   => 1, 'a' => 1,
    'at'  => date('Y-m-d H:i:s', strtotime('-9 days')),
]);
$dlg->execute([
    'eid' => $entryIds[3], 'uid' => $alice,
    'msg' => 'Wir waren zu dritt. Die Aufgaben habe ich mir mit Bob und Carsten geteilt.',
    'q'   => 0, 'a' => 0,
    'at'  => date('Y-m-d H:i:s', strtotime('-8 days')),
]);
echo "  OK Dialog-Nachrichten fuer In-Klaerung-Eintrag angelegt\n";

// ----- 2. Yearly-Target fuer Alice -----
$pdo->exec("DELETE FROM yearly_targets WHERE user_id = $alice AND year = $year");
$pdo->prepare(
    'INSERT INTO yearly_targets (user_id, year, target_hours, is_exempt, created_at)
     VALUES (:uid, :y, 30.00, 0, NOW())'
)->execute(['uid' => $alice, 'y' => $year]);
echo "  OK Soll-Stunden-Ziel fuer Alice (30h $year) gesetzt\n";

// ----- 3. Event "Sommerfest 2026" -----
$pdo->exec("DELETE FROM events WHERE title LIKE 'Sommerfest%'");

$startAt = date('Y-m-d', strtotime('+14 days')) . ' 10:00:00';
$endAt   = date('Y-m-d', strtotime('+14 days')) . ' 23:00:00';

$pdo->prepare(
    "INSERT INTO events (title, description, location, start_at, end_at,
        status, cancel_deadline_hours, created_by, created_at, updated_at)
     VALUES (:t, :d, :loc, :sa, :ea, 'veroeffentlicht', 24, :cb, NOW(), NOW())"
)->execute([
    't'   => 'Sommerfest 2026',
    'd'   => "Grosses Vereinsfest mit Essen, Live-Musik und Tombola.\nAufbau ab 10 Uhr, Feier ab 16 Uhr.",
    'loc' => 'Vereinsheim TSC, Festwiese',
    'sa'  => $startAt,
    'ea'  => $endAt,
    'cb'  => $admin,
]);
$eventId = (int) $pdo->lastInsertId();

// Task 1: Aufbau (fix, ziel=3, 4h)
$pdo->prepare(
    "INSERT INTO event_tasks (event_id, title, description, task_type, slot_mode,
        start_at, end_at, capacity_mode, capacity_target, hours_default, sort_order, created_at)
     VALUES (:eid, :t, :d, 'aufgabe', 'fix', :sa, :ea, 'ziel', 3, 4.00, 1, NOW())"
)->execute([
    'eid' => $eventId,
    't'   => 'Aufbau',
    'd'   => 'Festzelt, Tische und Buehne aufbauen.',
    'sa'  => date('Y-m-d', strtotime('+14 days')) . ' 10:00:00',
    'ea'  => date('Y-m-d', strtotime('+14 days')) . ' 14:00:00',
]);
$task1 = (int) $pdo->lastInsertId();

// Task 2: Theke (fix, ziel=4, 6h)
$pdo->prepare(
    "INSERT INTO event_tasks (event_id, title, description, task_type, slot_mode,
        start_at, end_at, capacity_mode, capacity_target, hours_default, sort_order, created_at)
     VALUES (:eid, :t, :d, 'aufgabe', 'fix', :sa, :ea, 'ziel', 4, 6.00, 2, NOW())"
)->execute([
    'eid' => $eventId,
    't'   => 'Theke & Getraenke',
    'd'   => 'Getraenke-Ausgabe waehrend des Festes.',
    'sa'  => date('Y-m-d', strtotime('+14 days')) . ' 16:00:00',
    'ea'  => date('Y-m-d', strtotime('+14 days')) . ' 22:00:00',
]);

// Bob meldet sich fuer Aufbau an
if ($bob) {
    $pdo->prepare(
        "INSERT INTO event_task_assignments (task_id, user_id, status, created_at)
         VALUES (:tid, :uid, 'bestaetigt', NOW())"
    )->execute(['tid' => $task1, 'uid' => $bob]);
}

// Pruefer als Organisator
$pdo->prepare(
    "INSERT INTO event_organizers (event_id, user_id, assigned_at, assigned_by)
     VALUES (:eid, :uid, NOW(), :ab)"
)->execute(['eid' => $eventId, 'uid' => $pruefer, 'ab' => $admin]);
echo "  OK Event 'Sommerfest 2026' mit 2 Aufgaben (ID $eventId)\n";

// ----- 4. Event-Vorlage -----
$pdo->exec("DELETE FROM event_templates WHERE name LIKE 'Saison-Abschluss%'");
$pdo->prepare(
    "INSERT INTO event_templates (name, description, version, is_current, created_by, created_at)
     VALUES (:n, :d, 1, 1, :cb, NOW())"
)->execute([
    'n'  => 'Saison-Abschlussfest',
    'd'  => 'Wiederkehrende Saison-Abschluss-Veranstaltung mit Standard-Aufgaben.',
    'cb' => $admin,
]);
$tplId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO event_template_tasks (template_id, title, description, task_type,
        slot_mode, capacity_mode, capacity_target, hours_default, sort_order, created_at)
     VALUES (:t, :title, :d, 'aufgabe', 'variabel', 'ziel', 2, 3.00, 1, NOW())"
)->execute([
    't' => $tplId, 'title' => 'Grill bedienen',
    'd' => 'Fleisch und Wuerste grillen.',
]);
$pdo->prepare(
    "INSERT INTO event_template_tasks (template_id, title, description, task_type,
        slot_mode, capacity_mode, capacity_target, hours_default, sort_order, created_at)
     VALUES (:t, :title, :d, 'aufgabe', 'variabel', 'ziel', 2, 2.50, 2, NOW())"
)->execute([
    't' => $tplId, 'title' => 'Salatbuffet',
    'd' => 'Salate vorbereiten und am Buffet betreuen.',
]);
echo "  OK Event-Vorlage 'Saison-Abschlussfest' (ID $tplId)\n";

echo "\nDemo-Daten fuer Handbuch-Screenshots angelegt.\n";
