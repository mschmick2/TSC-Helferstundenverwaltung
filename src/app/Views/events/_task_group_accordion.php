<?php
/**
 * Partial: ein Knoten im Mitglieder-Accordion (Modul 6 I7b2 Phase 2).
 *
 * Rekursion via Container-Closure (G9-I7b1-Muster):
 * Der Container (events/show.php) definiert $renderAccordionNode als
 * Closure mit use-by-reference und inkludiert dieses Partial. Die
 * Kinder-Schleife hier unten ruft wiederum die Closure.
 *
 * Erwartet im Scope:
 *   $node                  — Aggregator-Knoten aus TaskTreeAggregator::buildTree:
 *                            ['task' => EventTask, 'children' => array,
 *                             'helpers_subtree' => int, 'hours_subtree' => float,
 *                             'leaves_subtree' => int,
 *                             'open_slots_subtree' => int|null].
 *   $depth                 — int (0 fuer Top-Level). Wird als CSS-Klasse
 *                            depth-N ausgegeben.
 *   $renderAccordionNode   — Closure fuer rekursiven Aufruf.
 *   $taskMeta              — array<int, array{current_count:int,
 *                            user_has_assignment:bool}> (vom Controller).
 *   $event                 — Event-Objekt (wird im _assign_form.php
 *                            referenziert).
 *   $user                  — User-Objekt (Bestand; aktuell nicht direkt
 *                            genutzt, aber im Scope fuer Subpartials).
 *
 * Informationsdichte pro Leaf ist 1:1 zur bestehenden Karten-Ansicht
 * in events/show.php (G1-Architect-Vorgabe): Titel+Typ-Badge, Stunden,
 * Description, Slot-Zeit/"Freies Zeitfenster", Helfer-Count,
 * Status-Button (Bereits zugesagt / Ausgebucht / Uebernehmen-Form).
 *
 * Helfer-Count-Formulierung "X von Y offen" (Rollup-konsistent zur
 * Gruppen-Badge) ersetzt das Bestand-"X/Y Helfer". Architect-
 * Entscheidung aus G1.
 *
 * XSS-Schutz: alle Freitext-Felder via ViewHelper::e() bzw.
 * htmlspecialchars mit ENT_QUOTES/UTF-8.
 *
 * @var array $node
 * @var int $depth
 * @var \Closure $renderAccordionNode
 * @var array $taskMeta
 * @var \App\Models\Event $event
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;

/** @var EventTask $task */
$task    = $node['task'];
$isGroup = $task->isGroup();
?>

<?php if ($isGroup): ?>
    <?php
    $openSlotsSubtree = $node['open_slots_subtree'] ?? null;
    $leavesSubtree    = (int) ($node['leaves_subtree'] ?? 0);
    $hoursSubtree     = (float) ($node['hours_subtree'] ?? 0.0);
    // Architect-Entscheidung G1 C: Gruppen mit Offenen-Zaehler > 0 starten
    // offen; sonst eingeklappt (Nutzer kann manuell auffalten).
    $startOpen = $openSlotsSubtree !== null && $openSlotsSubtree > 0;
    ?>
    <details class="task-group-accordion-group depth-<?= (int) $depth ?>"
             <?= $startOpen ? 'open' : '' ?>
             <?php if ($openSlotsSubtree !== null): ?>
             data-open-count="<?= (int) $openSlotsSubtree ?>"
             <?php endif; ?>>
        <summary class="task-group-accordion-summary">
            <span class="task-group-accordion-title">
                <i class="bi bi-folder" aria-hidden="true"></i>
                <strong><?= ViewHelper::e($task->getTitle()) ?></strong>
            </span>
            <span class="task-group-accordion-badges">
                <?php if ($openSlotsSubtree !== null && $openSlotsSubtree > 0): ?>
                    <span class="badge bg-warning text-dark"
                          title="Offene Plaetze im Teilbaum">
                        <?= (int) $openSlotsSubtree ?> offen
                    </span>
                <?php elseif ($openSlotsSubtree === 0): ?>
                    <span class="badge bg-light text-muted border"
                          title="Alle Plaetze belegt">
                        voll
                    </span>
                <?php endif; ?>
                <span class="small text-muted">
                    <?= $leavesSubtree ?>
                    <?= $leavesSubtree === 1 ? 'Aufgabe' : 'Aufgaben' ?>
                    &middot;
                    <?= ViewHelper::formatHours($hoursSubtree) ?> h
                </span>
            </span>
        </summary>

        <?php if ($task->getDescription() !== null && $task->getDescription() !== ''): ?>
            <p class="task-group-accordion-group-description small text-muted">
                <?= nl2br(ViewHelper::e($task->getDescription())) ?>
            </p>
        <?php endif; ?>

        <div class="task-group-accordion-children">
            <?php foreach ($node['children'] as $child): ?>
                <?php $renderAccordionNode($child, $depth + 1); ?>
            <?php endforeach; ?>
        </div>
    </details>
<?php else: /* Leaf */ ?>
    <?php
    $meta = $taskMeta[$task->getId()] ?? ['current_count' => 0, 'user_has_assignment' => false];
    $capTarget = $task->getCapacityTarget();
    $currentCount = (int) $meta['current_count'];
    // openCount nur gesetzt, wenn capacity_target konkret ist. Bei
    // unbegrenzt wird das data-open-count-Attribut bewusst weggelassen —
    // CSS-Filter [data-open-count="0"] trifft das nicht, der Leaf bleibt
    // im Filter-aktiven Zustand sichtbar (Architect-Antwort B).
    $openCount = $capTarget === null ? null : max(0, $capTarget - $currentCount);
    $isFull = $task->getCapacityMode() === EventTask::CAP_MAXIMUM
        && $capTarget !== null
        && $currentCount >= $capTarget;
    ?>
    <div class="task-group-accordion-leaf depth-<?= (int) $depth ?>"
         <?php if ($openCount !== null): ?>
         data-open-count="<?= (int) $openCount ?>"
         <?php endif; ?>>

        <div class="task-group-accordion-leaf-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="task-group-accordion-title">
                <i class="bi bi-card-checklist text-primary" aria-hidden="true"></i>
                <strong><?= ViewHelper::e($task->getTitle()) ?></strong>
                <?php if ($task->isContribution()): ?>
                    <span class="badge bg-info">Beigabe</span>
                <?php else: ?>
                    <span class="badge bg-primary">Aufgabe</span>
                <?php endif; ?>
            </span>
            <small class="text-muted">
                <?= ViewHelper::formatHours($task->getHoursDefault()) ?> h
            </small>
        </div>

        <?php if ($task->getDescription() !== null && $task->getDescription() !== ''): ?>
            <p class="task-group-accordion-leaf-description small text-muted mb-2">
                <?= nl2br(ViewHelper::e($task->getDescription())) ?>
            </p>
        <?php endif; ?>

        <div class="task-group-accordion-leaf-meta small text-muted mb-2">
            <?php if ($task->hasFixedSlot()): ?>
                <span>
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <?= ViewHelper::formatDateTime($task->getStartAt()) ?>
                    &ndash; <?= ViewHelper::formatDateTime($task->getEndAt()) ?>
                </span>
            <?php else: ?>
                <span>
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    <em>Freies Zeitfenster</em>
                </span>
            <?php endif; ?>
            &middot;
            <span>
                <?php if ($capTarget === null): ?>
                    Beliebig viele Helfer
                <?php else: ?>
                    <?= (int) $openCount ?> von <?= (int) $capTarget ?> offen
                <?php endif; ?>
            </span>
        </div>

        <div class="task-group-accordion-leaf-actions">
            <?php if ($meta['user_has_assignment']): ?>
                <button type="button" class="btn btn-success btn-sm w-100" disabled>
                    <i class="bi bi-check-circle" aria-hidden="true"></i> Bereits zugesagt
                </button>
            <?php elseif ($isFull): ?>
                <button type="button" class="btn btn-secondary btn-sm w-100" disabled>
                    <i class="bi bi-slash-circle" aria-hidden="true"></i> Ausgebucht
                </button>
            <?php else: ?>
                <?php
                // _assign_form.php erwartet $t + $event im Scope.
                $t = $task;
                include __DIR__ . '/_assign_form.php';
                ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
