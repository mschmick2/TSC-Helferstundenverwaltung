<?php
/**
 * Modul 6 I7b4 Phase 2 — Gemeinsames Partial fuer die chronologische
 * Task-Liste. Wird von admin/events/tasks_by_date.php und
 * organizer/events/tasks_by_date.php eingebunden.
 *
 * Input (als Scope-Variablen):
 *   @var \App\Models\Event $event
 *   @var list<array{
 *       task: \App\Models\EventTask,
 *       status: ?\App\Models\TaskStatus,
 *       helpers: int,
 *       open_slots: ?int,
 *       ancestor_path: list<string>
 *   }> $flatList
 *   @var bool $linkTaskTitles  true = Admin-Kontext (Titel-Link auf
 *       /admin/events/{id}); false = Organisator-Kontext (Read-Only).
 *
 * Annahmen ueber $flatList:
 *   - Bereits nach start_at sortiert (Controller erledigt das; nulls last).
 *   - Leaves mit start_at !== null (fix-Slot) kommen zuerst.
 *   - Leaves mit start_at === null (variable-Slot) am Ende, in
 *     Depth-First-Baum-Reihenfolge (DFS bleibt durch stabile
 *     usort-Sortierung in Phase 1 erhalten).
 *
 * EventTask::getStartAt() liefert ?string im MySQL-DATETIME-Format
 * "YYYY-MM-DD HH:MM:SS". Gruppierung nach YYYY-MM-DD via substr() —
 * keine DateTime-Instanz pro Leaf noetig. Das Datums-Label im Header
 * wird einmal pro Gruppe gerendert und dort als DateTime konvertiert.
 */

use App\Helpers\ViewHelper;

/** @var \App\Models\Event $event */
/** @var array $flatList */
/** @var bool $linkTaskTitles */

// 1. Fix- und Variable-Slot-Leaves trennen.
$fixSlotLeaves      = [];
$variableSlotLeaves = [];
foreach ($flatList as $leaf) {
    if ($leaf['task']->getStartAt() !== null) {
        $fixSlotLeaves[] = $leaf;
    } else {
        $variableSlotLeaves[] = $leaf;
    }
}

// 2. Fix-Slot-Leaves nach Datum (YYYY-MM-DD) gruppieren. Reihenfolge
//    innerhalb und zwischen den Gruppen ist vom Controller bereits
//    korrekt sortiert.
$groupedByDate = [];
foreach ($fixSlotLeaves as $leaf) {
    $dateKey = substr((string) $leaf['task']->getStartAt(), 0, 10);
    $groupedByDate[$dateKey][] = $leaf;
}

/**
 * Formatiert ein YYYY-MM-DD-Datum als "Wochentag, 15. Mai 2026".
 *
 * Erste Wahl: IntlDateFormatter (verfuegbar auf dev und vermutlich auf
 * Strato; laut Phase-1-Pre-Flight-Check ja). Fallback: manuelle
 * Wochentag- und Monats-Tabelle ohne PHP-intl-Abhaengigkeit, fuer
 * den Fall dass Strato die intl-Extension doch nicht aktiviert hat.
 */
$formatDateHeader = static function (string $dateKey): string {
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dateKey);
    if ($dt === false) {
        return $dateKey; // Defensive: unerwartetes Format durchreichen
    }

    if (extension_loaded('intl') && class_exists(\IntlDateFormatter::class)) {
        $fmt = new \IntlDateFormatter(
            'de_DE',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::LONG,
            'Europe/Berlin'
        );
        $label = $fmt->format($dt);
        if (is_string($label) && $label !== '') {
            return $label;
        }
    }

    // Fallback ohne intl: manuelle Tabellen fuer Wochentag und Monat.
    $wochentage = [
        0 => 'Sonntag', 1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch',
        4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag',
    ];
    $monate = [
        1 => 'Januar', 2 => 'Februar', 3 => 'Maerz', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];
    return sprintf(
        '%s, %d. %s %d',
        $wochentage[(int) $dt->format('w')],
        (int) $dt->format('j'),
        $monate[(int) $dt->format('n')],
        (int) $dt->format('Y')
    );
};
?>

<?php if (empty($fixSlotLeaves) && empty($variableSlotLeaves)): ?>
    <p class="text-muted fst-italic">Keine Aufgaben in diesem Event.</p>
<?php endif; ?>

<?php foreach ($groupedByDate as $dateKey => $leaves): ?>
    <section class="task-list-date-section">
        <h3 class="task-list-date-header">
            <?= ViewHelper::e($formatDateHeader($dateKey)) ?>
        </h3>
        <ul class="task-list-group">
            <?php foreach ($leaves as $leaf): ?>
                <?php include __DIR__ . '/_task_list_item.php'; ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>

<?php if (!empty($variableSlotLeaves)): ?>
    <section class="task-list-date-section task-list-no-time">
        <h3 class="task-list-date-header">Ohne feste Zeitvorgabe</h3>
        <ul class="task-list-group">
            <?php foreach ($variableSlotLeaves as $leaf): ?>
                <?php include __DIR__ . '/_task_list_item.php'; ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
