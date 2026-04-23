<?php
/**
 * Modul 6 I7b4 Phase 1 — Sortierbare Task-Liste, Admin-Container.
 *
 * Read-only Chronologie der Leaves eines Events. Phase 1 rendert die Liste
 * inline und minimal; Phase 2 extrahiert das in ein gemeinsames Partial
 * mit Datums-Sektionierung und erweitert das Styling.
 *
 * Unterschied zum Organisator-Container: Task-Titel als Link auf
 * /admin/events/{id} (dort ist die volle Bearbeitungs-UI).
 *
 * @var \App\Models\Event $event
 * @var array<int, array{
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

<?php if (empty($flatList)): ?>
    <div class="alert alert-info">Dieses Event hat noch keine Aufgaben.</div>
<?php else: ?>
    <ul class="list-group">
        <?php foreach ($flatList as $item):
            $task = $item['task'];
        ?>
            <li class="list-group-item d-flex flex-column">
                <div class="d-flex align-items-center gap-2">
                    <?php if ($task->hasFixedSlot() && $task->getStartAt() !== null): ?>
                        <span class="badge bg-light text-dark border">
                            <?= ViewHelper::e($task->getStartAt()) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Ohne feste Zeit</span>
                    <?php endif; ?>
                    <?php if ($linkTaskTitles): ?>
                        <strong>
                            <a href="/admin/events/<?= (int) $event->getId() ?>">
                                <?= ViewHelper::e($task->getTitle()) ?>
                            </a>
                        </strong>
                    <?php else: ?>
                        <strong><?= ViewHelper::e($task->getTitle()) ?></strong>
                    <?php endif; ?>
                </div>
                <?php if (!empty($item['ancestor_path'])): ?>
                    <small class="text-muted mt-1">
                        <?php foreach ($item['ancestor_path'] as $i => $ancestorTitle): ?>
                            <?php if ($i > 0): ?> &gt; <?php endif; ?>
                            <?= ViewHelper::e($ancestorTitle) ?>
                        <?php endforeach; ?>
                    </small>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
