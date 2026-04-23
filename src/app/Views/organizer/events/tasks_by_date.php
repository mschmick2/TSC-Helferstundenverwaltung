<?php
/**
 * Modul 6 I7b4 Phase 2 — Sortierbare Task-Liste, Organisator-Container.
 *
 * Read-only Chronologie der Leaves eines Events. Rendert das gemeinsame
 * Partial _task_list_by_date.php mit linkTaskTitles=false, weil die
 * Admin-Detail-Seite /admin/events/{id} per RoleMiddleware
 * event_admin-gebunden ist (Organisator wuerde 403 bekommen). Der
 * non-modale Organisator-Editor kommt in I7e.
 *
 * @var \App\Models\Event $event
 * @var list<array{
 *     task: \App\Models\EventTask,
 *     status: ?\App\Models\TaskStatus,
 *     helpers: int,
 *     open_slots: ?int,
 *     ancestor_path: list<string>
 * }> $flatList
 * @var bool $linkTaskTitles
 */
use App\Helpers\ViewHelper;
?>

<h1 class="h3 mb-3">
    <i class="bi bi-calendar-event" aria-hidden="true"></i>
    <?= ViewHelper::e($event->getTitle()) ?>
</h1>
<p class="text-muted mb-4">Aufgaben nach Datum (Read-Only)</p>

<?php include __DIR__ . '/../../events/_task_list_by_date.php'; ?>
