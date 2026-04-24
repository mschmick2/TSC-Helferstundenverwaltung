<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\OptimisticLockException;
use App\Exceptions\ValidationException;
use App\Models\EventTask;
use App\Repositories\EventTaskRepository;
use App\Repositories\SettingsRepository;
use PDO;

/**
 * Service fuer den hierarchischen Aufgabenbaum eines Events (Modul 6 I7a).
 *
 * Operiert ausschliesslich auf event_tasks. Templates haben einen analogen,
 * aber separaten Service (kommt in I7c). Der Tree-Editor selbst ist hinter
 * dem Settings-Flag `events.tree_editor_enabled` versteckt; assertEnabled()
 * macht das hart, falls der Service versehentlich aus einem freigegebenen
 * Pfad aufgerufen wird, bevor das UI lebt.
 *
 * Validierungs-Invarianten (gespiegelt zu chk_et_group_shape und
 * chk_et_fix_times in Migration 009):
 *   - Maximaltiefe (Settings, Default 4) wird Service-enforced.
 *   - parent muss eine Gruppe (is_group=1) sein und zum gleichen Event gehoeren.
 *   - Top-Level (parent=NULL) ist erlaubt.
 *   - Zyklen werden durch Walks geprueft.
 *   - Group-Shape: keine Helfer-/Slot-Felder.
 *   - Convert/Delete-Regeln: strikte Ablehnung bei aktiven Kindern bzw. aktiven
 *     Assignments.
 *
 * Audit-Mapping (siehe G1-Plan, alle ohne neuen ENUM-Wert):
 *   - createNode      -> create
 *   - move            -> update (oldValues/newValues = parent_task_id, sort_order)
 *   - reorderSiblings -> update (recordId=NULL, alles in metadata)
 *   - convertToGroup  -> update (oldValues/newValues = is_group, slot_mode, ...)
 *   - convertToLeaf   -> update
 *   - softDeleteNode  -> delete
 *
 * Audit-Reihenfolge: innerhalb der Transaction, NACH dem Business-Write,
 * VOR dem Commit (Lesson 17.04. Audit-Reihenfolge).
 */
final class TaskTreeService
{
    public const SETTING_ENABLED   = 'events.tree_editor_enabled';
    public const SETTING_MAX_DEPTH = 'events.tree_max_depth';
    public const DEFAULT_MAX_DEPTH = 4;

    public function __construct(
        private readonly PDO $pdo,
        private readonly EventTaskRepository $taskRepo,
        private readonly SettingsRepository $settings,
        private readonly AuditService $auditService,
    ) {
    }

    // =========================================================================
    // Operationen
    // =========================================================================

    public function createNode(int $eventId, array $data, int $actorId): int
    {
        $this->assertEnabled();

        $isGroup = !empty($data['is_group']);
        $parentId = $this->normalizeParentId($data['parent_task_id'] ?? null);

        if ($parentId !== null) {
            $this->assertParentIsGroupOfEvent($parentId, $eventId);
            $newDepth = $this->taskRepo->getDepth($parentId) + 1;
        } else {
            $newDepth = 0;
        }
        $this->assertWithinMaxDepth($newDepth);

        $payload = $isGroup
            ? $this->buildGroupPayload($eventId, $parentId, $data)
            : $this->buildLeafPayload($eventId, $parentId, $data);

        $this->pdo->beginTransaction();
        try {
            $taskId = $this->taskRepo->create($payload);

            $this->auditService->log(
                action: 'create',
                tableName: 'event_tasks',
                recordId: $taskId,
                newValues: $payload,
                description: $isGroup
                    ? "Aufgaben-Knoten (Gruppe) angelegt: '{$payload['title']}'"
                    : "Aufgaben-Knoten (Aufgabe) angelegt: '{$payload['title']}'",
                metadata: [
                    'event_id'       => $eventId,
                    'parent_task_id' => $parentId,
                    'is_group'       => $isGroup ? 1 : 0,
                    'depth'          => $newDepth,
                    'actor_id'       => $actorId,
                ],
            );

            $this->pdo->commit();
            return $taskId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function move(int $taskId, ?int $newParentId, int $newSortOrder, int $actorId, ?int $expectedVersion = null): void
    {
        $this->assertEnabled();

        $task = $this->taskRepo->findById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Aufgaben-Knoten nicht gefunden.');
        }

        $newParentId = $this->normalizeParentId($newParentId);
        $oldParentId = $task->getParentTaskId();
        $oldSortOrder = $task->getSortOrder();

        if ($newParentId !== null) {
            $this->assertParentIsGroupOfEvent($newParentId, $task->getEventId());

            // Zyklus-Check: neuer Parent darf nicht der Knoten selbst sein
            // und nicht in seinem Subtree liegen.
            if ($newParentId === $taskId) {
                throw new BusinessRuleException(
                    'Knoten kann nicht sich selbst als Eltern haben.'
                );
            }
            if ($this->taskRepo->isDescendantOf($newParentId, $taskId)) {
                throw new BusinessRuleException(
                    'Verschieben wuerde einen Zyklus erzeugen (Ziel liegt im Subtree des Knotens).'
                );
            }
            $newDepth = $this->taskRepo->getDepth($newParentId) + 1;
        } else {
            $newDepth = 0;
        }

        // Tiefe inklusive vollstaendigem Subtree pruefen
        $subtreeDepth = $this->taskRepo->maxSubtreeDepth($taskId);
        $this->assertWithinMaxDepth($newDepth + $subtreeDepth);

        $oldDepth = $oldParentId !== null ? $this->taskRepo->getDepth($oldParentId) + 1 : 0;

        $this->pdo->beginTransaction();
        try {
            $ok = $this->taskRepo->move($taskId, $newParentId, $newSortOrder, $expectedVersion);
            if (!$ok && $expectedVersion !== null) {
                throw new OptimisticLockException($taskId, $expectedVersion);
            }

            $this->auditService->log(
                action: 'update',
                tableName: 'event_tasks',
                recordId: $taskId,
                oldValues: ['parent_task_id' => $oldParentId, 'sort_order' => $oldSortOrder],
                newValues: ['parent_task_id' => $newParentId, 'sort_order' => $newSortOrder],
                description: 'Aufgaben-Knoten verschoben',
                metadata: [
                    'event_id'  => $task->getEventId(),
                    'old_depth' => $oldDepth,
                    'new_depth' => $newDepth,
                    'actor_id'  => $actorId,
                ],
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Geschwister einer Ebene neu sortieren. orderedTaskIds muessen exakt die
     * aktuellen Geschwister unter dem gleichen parent enthalten — kein
     * Hinzufuegen/Entfernen, nur Reihenfolge.
     *
     * @param int[] $orderedTaskIds
     */
    public function reorderSiblings(int $eventId, ?int $parentId, array $orderedTaskIds, int $actorId): void
    {
        $this->assertEnabled();

        $parentId = $this->normalizeParentId($parentId);

        $current = $this->taskRepo->findChildren($eventId, $parentId);
        $currentIds = array_map(fn(EventTask $t) => $t->getId(), $current);
        $proposed = array_map('intval', array_values($orderedTaskIds));

        if (count($proposed) !== count($currentIds)) {
            throw new ValidationException([
                'Reorder-Liste passt nicht zur aktuellen Ebene (Anzahl unterschiedlich).'
            ]);
        }
        if (array_diff($currentIds, $proposed) !== [] || array_diff($proposed, $currentIds) !== []) {
            throw new ValidationException([
                'Reorder-Liste enthaelt fremde IDs oder vermisst Geschwister.'
            ]);
        }

        $this->pdo->beginTransaction();
        try {
            $this->taskRepo->reorderSiblings($eventId, $proposed);

            $this->auditService->log(
                action: 'update',
                tableName: 'event_tasks',
                recordId: null,
                description: 'Reihenfolge der Aufgaben geaendert',
                metadata: [
                    'event_id'        => $eventId,
                    'parent_task_id'  => $parentId,
                    'children_order'  => $proposed,
                    'operation'       => 'reorder',
                    'actor_id'        => $actorId,
                ],
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function convertToGroup(int $taskId, int $actorId, ?int $expectedVersion = null): void
    {
        $this->assertEnabled();

        $task = $this->taskRepo->findById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Aufgaben-Knoten nicht gefunden.');
        }
        if ($task->isGroup()) {
            throw new BusinessRuleException('Knoten ist bereits eine Gruppe.');
        }
        if ($this->taskRepo->countActiveAssignments($taskId) > 0) {
            throw new BusinessRuleException(
                'Konvertieren in Gruppe nicht moeglich: aktive Zusagen vorhanden. '
                . 'Bitte zuerst Zusagen stornieren.'
            );
        }

        $oldShape = [
            'is_group'        => 0,
            'task_type'       => $task->getTaskType(),
            'slot_mode'       => $task->getSlotMode(),
            'capacity_mode'   => $task->getCapacityMode(),
            'capacity_target' => $task->getCapacityTarget(),
            'hours_default'   => $task->getHoursDefault(),
            'start_at'        => $task->getStartAt(),
            'end_at'          => $task->getEndAt(),
        ];
        $newShape = [
            'is_group'        => 1,
            'task_type'       => EventTask::TYPE_AUFGABE,
            'slot_mode'       => null,
            'capacity_mode'   => EventTask::CAP_UNBEGRENZT,
            'capacity_target' => null,
            'hours_default'   => 0.0,
            'start_at'        => null,
            'end_at'          => null,
        ];

        $this->pdo->beginTransaction();
        try {
            $ok = $this->taskRepo->convertToGroup($taskId, $expectedVersion);
            if (!$ok && $expectedVersion !== null) {
                throw new OptimisticLockException($taskId, $expectedVersion);
            }

            $this->auditService->log(
                action: 'update',
                tableName: 'event_tasks',
                recordId: $taskId,
                oldValues: $oldShape,
                newValues: $newShape,
                description: "Knoten in Gruppe konvertiert: '{$task->getTitle()}'",
                metadata: [
                    'event_id' => $task->getEventId(),
                    'actor_id' => $actorId,
                ],
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function convertToLeaf(int $taskId, array $leafData, int $actorId, ?int $expectedVersion = null): void
    {
        $this->assertEnabled();

        $task = $this->taskRepo->findById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Aufgaben-Knoten nicht gefunden.');
        }
        if (!$task->isGroup()) {
            throw new BusinessRuleException('Knoten ist bereits eine Aufgabe (kein Gruppenknoten).');
        }
        if ($this->taskRepo->countActiveChildren($taskId) > 0) {
            throw new BusinessRuleException(
                'Konvertieren in Aufgabe nicht moeglich: Gruppe enthaelt Kinder. '
                . 'Bitte zuerst Kinder verschieben oder loeschen.'
            );
        }

        $leafPayload = $this->buildLeafPayload(
            $task->getEventId(),
            $task->getParentTaskId(),
            $leafData
        );
        // Beim Leaf-Update sind nur Shape-Felder relevant; sort_order bleibt.
        $convertData = [
            'task_type'       => $leafPayload['task_type'],
            'slot_mode'       => $leafPayload['slot_mode'],
            'start_at'        => $leafPayload['start_at'],
            'end_at'          => $leafPayload['end_at'],
            'capacity_mode'   => $leafPayload['capacity_mode'],
            'capacity_target' => $leafPayload['capacity_target'],
            'hours_default'   => $leafPayload['hours_default'],
        ];

        $oldShape = [
            'is_group'        => 1,
            'task_type'       => EventTask::TYPE_AUFGABE,
            'slot_mode'       => null,
            'capacity_mode'   => EventTask::CAP_UNBEGRENZT,
            'capacity_target' => null,
            'hours_default'   => 0.0,
        ];
        $newShape = ['is_group' => 0] + $convertData;

        $this->pdo->beginTransaction();
        try {
            $ok = $this->taskRepo->convertToLeaf($taskId, $convertData, $expectedVersion);
            if (!$ok && $expectedVersion !== null) {
                throw new OptimisticLockException($taskId, $expectedVersion);
            }

            $this->auditService->log(
                action: 'update',
                tableName: 'event_tasks',
                recordId: $taskId,
                oldValues: $oldShape,
                newValues: $newShape,
                description: "Gruppe in Aufgabe konvertiert: '{$task->getTitle()}'",
                metadata: [
                    'event_id' => $task->getEventId(),
                    'actor_id' => $actorId,
                ],
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Soft-Delete eines Knotens. Strikte Regel (G1-Entscheidung):
     *   - Gruppen mit aktiven Kindern: Ablehnung.
     *   - Leaves mit aktiven Zusagen: Ablehnung.
     *
     * Kaskadierender Soft-Delete ist NICHT vorgesehen und wird, falls jemals
     * gewuenscht, in einem eigenen Inkrement mit explizitem Bestaetigungs-
     * Flow nachgereicht.
     */
    public function softDeleteNode(int $taskId, int $actorId, ?int $expectedVersion = null): void
    {
        $this->assertEnabled();

        $task = $this->taskRepo->findById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Aufgaben-Knoten nicht gefunden.');
        }
        if ($task->isGroup() && $this->taskRepo->countActiveChildren($taskId) > 0) {
            throw new BusinessRuleException(
                'Loeschen abgelehnt: Gruppe enthaelt aktive Aufgaben. '
                . 'Bitte zuerst Kinder loeschen oder verschieben.'
            );
        }
        if (!$task->isGroup() && $this->taskRepo->countActiveAssignments($taskId) > 0) {
            throw new BusinessRuleException(
                'Loeschen abgelehnt: Aufgabe hat aktive Zusagen.'
            );
        }

        $oldSnapshot = [
            'event_id'       => $task->getEventId(),
            'parent_task_id' => $task->getParentTaskId(),
            'is_group'       => $task->isGroup() ? 1 : 0,
            'title'          => $task->getTitle(),
            'task_type'      => $task->getTaskType(),
            'slot_mode'      => $task->getSlotMode(),
            'capacity_mode'  => $task->getCapacityMode(),
            'capacity_target' => $task->getCapacityTarget(),
            'hours_default'  => $task->getHoursDefault(),
        ];

        $this->pdo->beginTransaction();
        try {
            // E1 — Soft-Delete-Zombie-Referenz (G3 2026-04-22):
            // RESTRICT auf event_tasks.parent_task_id greift nur bei DELETE FROM,
            // nicht bei einem UPDATE deleted_at. Wenn diese Gruppe soft-deletet wird,
            // bleiben soft-deletete Kinder mit parent_task_id = $taskId in der Tabelle
            // bestehen. Bei einem zukuenftigen Restore-Feature muss bewusst entschieden
            // werden, ob Kinder mit ihrem Parent reaktiviert werden oder nicht. Aktuell
            // (I7a) ohne Restore-Feature unkritisch. Siehe lessons-learned.md
            // Eintrag 2026-04-22 — Soft-Delete vs FK RESTRICT bei Self-References.
            $ok = $this->taskRepo->softDelete($taskId, $actorId, $expectedVersion);
            if (!$ok && $expectedVersion !== null) {
                throw new OptimisticLockException($taskId, $expectedVersion);
            }

            $this->auditService->log(
                action: 'delete',
                tableName: 'event_tasks',
                recordId: $taskId,
                oldValues: $oldSnapshot,
                description: $task->isGroup()
                    ? "Gruppen-Knoten geloescht: '{$task->getTitle()}'"
                    : "Aufgaben-Knoten geloescht: '{$task->getTitle()}'",
                metadata: [
                    'event_id' => $task->getEventId(),
                    'is_group' => $task->isGroup() ? 1 : 0,
                    'actor_id' => $actorId,
                ],
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Attribute eines Knotens aktualisieren — ohne Shape-Wechsel zwischen Gruppe
     * und Aufgabe. is_group im Payload wird defensiv entfernt; Wechsel der Shape
     * laeuft ueber convertToGroup()/convertToLeaf().
     *
     * Audit-Eintrag enthaelt nur die tatsaechlich geaenderten Felder (Feld-Diff).
     * Sind keine Felder veraendert, wird weder DB-Write noch Audit-Eintrag
     * ausgeloest.
     */
    public function updateNode(int $taskId, array $data, int $actorId, ?int $expectedVersion = null): void
    {
        $this->assertEnabled();

        $task = $this->taskRepo->findById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Aufgaben-Knoten nicht gefunden.');
        }

        // Shape-Wechsel ist nicht Scope dieser Methode — defensiv entfernen.
        unset($data['is_group']);

        $base = [
            'title'           => $task->getTitle(),
            'description'     => $task->getDescription(),
            'category_id'     => $task->getCategoryId(),
            'task_type'       => $task->getTaskType(),
            'slot_mode'       => $task->getSlotMode(),
            'start_at'        => $task->getStartAt(),
            'end_at'          => $task->getEndAt(),
            'capacity_mode'   => $task->getCapacityMode(),
            'capacity_target' => $task->getCapacityTarget(),
            'hours_default'   => $task->getHoursDefault(),
            'sort_order'      => $task->getSortOrder(),
        ];
        // $data + $base: Keys aus $data gewinnen, $base fuellt Luecken.
        $merged = $data + $base;

        $payload = $task->isGroup()
            ? $this->buildGroupPayload($task->getEventId(), $task->getParentTaskId(), $merged)
            : $this->buildLeafPayload($task->getEventId(), $task->getParentTaskId(), $merged);

        // Feld-Diff: nur tatsaechlich veraenderte Felder loggen.
        $oldValues = [];
        $newValues = [];
        foreach ($base as $field => $oldVal) {
            $newVal = $payload[$field] ?? null;
            if ($oldVal !== $newVal) {
                $oldValues[$field] = $oldVal;
                $newValues[$field] = $newVal;
            }
        }

        if ($oldValues === []) {
            // Nichts veraendert — kein DB-Write, kein Audit-Eintrag.
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $ok = $this->taskRepo->update($taskId, $payload, $expectedVersion);
            if (!$ok && $expectedVersion !== null) {
                throw new OptimisticLockException($taskId, $expectedVersion);
            }

            $this->auditService->log(
                action: 'update',
                tableName: 'event_tasks',
                recordId: $taskId,
                oldValues: $oldValues,
                newValues: $newValues,
                description: $task->isGroup()
                    ? "Gruppen-Knoten aktualisiert: '{$payload['title']}'"
                    : "Aufgaben-Knoten aktualisiert: '{$payload['title']}'",
                metadata: [
                    'event_id'       => $task->getEventId(),
                    'parent_task_id' => $task->getParentTaskId(),
                    'is_group'       => $task->isGroup() ? 1 : 0,
                    'actor_id'       => $actorId,
                ],
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // Validierungs-Helfer
    // =========================================================================

    /**
     * Verhindert Service-Aufrufe, solange der Editor noch nicht freigeschaltet
     * ist. Schutz fuer den I7a-Merge ohne Sichtbarkeit.
     */
    private function assertEnabled(): void
    {
        $value = $this->settings->getValue(self::SETTING_ENABLED, '0');
        if ($value !== '1' && $value !== 'true') {
            throw new BusinessRuleException(
                'Aufgabenbaum-Editor ist nicht freigeschaltet '
                . '(Setting events.tree_editor_enabled).'
            );
        }
    }

    private function assertWithinMaxDepth(int $depth): void
    {
        $maxDepth = (int) ($this->settings->getValue(self::SETTING_MAX_DEPTH, (string) self::DEFAULT_MAX_DEPTH) ?? self::DEFAULT_MAX_DEPTH);
        if ($maxDepth < 1) {
            $maxDepth = self::DEFAULT_MAX_DEPTH;
        }
        if ($depth > $maxDepth) {
            throw new BusinessRuleException(
                "Maximaltiefe des Aufgabenbaums ueberschritten "
                . "(Tiefe {$depth} > erlaubt {$maxDepth})."
            );
        }
    }

    private function assertParentIsGroupOfEvent(int $parentId, int $eventId): void
    {
        $parent = $this->taskRepo->findById($parentId);
        if ($parent === null) {
            throw new BusinessRuleException('Eltern-Knoten nicht gefunden.');
        }
        if ($parent->getEventId() !== $eventId) {
            throw new BusinessRuleException(
                'Eltern-Knoten gehoert zu einem anderen Event.'
            );
        }
        if (!$parent->isGroup()) {
            throw new BusinessRuleException(
                'Eltern-Knoten ist keine Gruppe — Aufgaben koennen keine Kinder haben.'
            );
        }
    }

    private function normalizeParentId(?int $value): ?int
    {
        if ($value === null || $value === 0) {
            return null;
        }
        return $value;
    }

    /**
     * Datenpaket fuer einen Gruppen-Knoten: Shape-Felder werden erzwungen,
     * damit chk_et_group_shape niemals beruehrt wird. Helfer-/Slot-Felder
     * im Input werden bewusst ignoriert.
     */
    private function buildGroupPayload(int $eventId, ?int $parentId, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(['Knoten-Titel ist Pflichtfeld.']);
        }
        $description = isset($data['description']) ? trim((string) $data['description']) : '';

        return [
            'event_id'        => $eventId,
            'parent_task_id'  => $parentId,
            'is_group'        => 1,
            'category_id'     => null,
            'title'           => $title,
            'description'     => $description !== '' ? $description : null,
            // Shape-Pflicht fuer Gruppen
            'task_type'       => EventTask::TYPE_AUFGABE,
            'slot_mode'       => null,
            'start_at'        => null,
            'end_at'          => null,
            'capacity_mode'   => EventTask::CAP_UNBEGRENZT,
            'capacity_target' => null,
            'hours_default'   => 0.0,
            'sort_order'      => max(0, (int) ($data['sort_order'] ?? 0)),
        ];
    }

    /**
     * Datenpaket fuer einen Leaf-Knoten: ENUM-Allowlist + fix-slot-Offset-Pflicht
     * (gleiche Regel wie EventTemplateService::validateTaskData; Lesson vom
     * 18.04. — Service-Validation ist die erste, DB-Check die letzte
     * Verteidigungslinie).
     */
    private function buildLeafPayload(int $eventId, ?int $parentId, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(['Aufgaben-Titel ist Pflichtfeld.']);
        }

        $taskType = (string) ($data['task_type'] ?? EventTask::TYPE_AUFGABE);
        if (!in_array($taskType, [EventTask::TYPE_AUFGABE, EventTask::TYPE_BEIGABE], true)) {
            throw new ValidationException(['Ungueltiger task_type.']);
        }

        $slotMode = (string) ($data['slot_mode'] ?? EventTask::SLOT_FIX);
        if (!in_array($slotMode, [EventTask::SLOT_FIX, EventTask::SLOT_VARIABEL], true)) {
            throw new ValidationException(['Ungueltiger slot_mode.']);
        }

        $capacityMode = (string) ($data['capacity_mode'] ?? EventTask::CAP_UNBEGRENZT);
        if (!in_array(
            $capacityMode,
            [EventTask::CAP_UNBEGRENZT, EventTask::CAP_ZIEL, EventTask::CAP_MAXIMUM],
            true
        )) {
            throw new ValidationException(['Ungueltiger capacity_mode.']);
        }

        $startAt = $data['start_at'] ?? null;
        $endAt = $data['end_at'] ?? null;
        if ($slotMode === EventTask::SLOT_FIX && ($startAt === null || $endAt === null)) {
            throw new ValidationException([
                'Bei Slot-Modus "fix" muessen Start- und Endzeit gesetzt sein.'
            ]);
        }
        if ($slotMode === EventTask::SLOT_VARIABEL) {
            $startAt = null;
            $endAt = null;
        }

        $hours = (float) ($data['hours_default'] ?? 0.0);
        if ($hours < 0 || $hours > 24) {
            throw new ValidationException(['Stunden muessen zwischen 0 und 24 liegen.']);
        }

        $capTarget = $data['capacity_target'] ?? null;
        if ($capTarget !== null && $capTarget !== '') {
            $capTarget = (int) $capTarget;
            if ($capTarget < 0) {
                throw new ValidationException(['Capacity-Target darf nicht negativ sein.']);
            }
        } else {
            $capTarget = null;
        }
        if ($capacityMode === EventTask::CAP_UNBEGRENZT) {
            $capTarget = null;
        } elseif ($capTarget === null) {
            throw new ValidationException(
                ['Capacity-Target ist Pflichtfeld bei ziel/maximum.']
            );
        }

        $categoryId = $data['category_id'] ?? null;
        if ($categoryId !== null && $categoryId !== '') {
            $categoryId = (int) $categoryId;
        } else {
            $categoryId = null;
        }

        $description = isset($data['description']) ? trim((string) $data['description']) : '';

        return [
            'event_id'        => $eventId,
            'parent_task_id'  => $parentId,
            'is_group'        => 0,
            'category_id'     => $categoryId,
            'title'           => $title,
            'description'     => $description !== '' ? $description : null,
            'task_type'       => $taskType,
            'slot_mode'       => $slotMode,
            'start_at'        => $startAt,
            'end_at'          => $endAt,
            'capacity_mode'   => $capacityMode,
            'capacity_target' => $capTarget,
            'hours_default'   => $hours,
            'sort_order'      => max(0, (int) ($data['sort_order'] ?? 0)),
        ];
    }
}
