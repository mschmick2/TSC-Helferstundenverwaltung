<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\EventTask;
use App\Models\EventTemplateTask;
use App\Repositories\EventTemplateRepository;
use App\Repositories\SettingsRepository;
use PDO;

/**
 * Service fuer den hierarchischen Aufgabenbaum eines Event-Templates
 * (Modul 6 I7c). Parallel-Implementation zu TaskTreeService — die beiden
 * Services teilen sich die Feature-Flag-Konstante und delegieren Validation
 * (G1-Entscheidung A: Service-Delegation statt Duplikat).
 *
 * Unterschiede zum Event-TaskTreeService:
 *   - Keine Assignments → countActiveAssignments entfaellt, softDelete ist
 *     hartes DELETE (wie der bestehende EventTemplateRepository::deleteTask).
 *   - Kein version-Feld am Task → kein Optimistic Lock (I7c-Scope).
 *   - Zusaetzliche Policy-Locks: nur isCurrent()-Versionen editierbar;
 *     Templates mit hasDerivedEvents() sind gesperrt, bis eine neue Version
 *     via EventTemplateService::saveAsNewVersion angelegt wird.
 *
 * Audit-Mapping (analog zu TaskTreeService):
 *   - createNode      -> create  (event_template_tasks)
 *   - move            -> update  (oldValues/newValues: parent, sort_order)
 *   - reorderSiblings -> update  (recordId=null, metadata.operation=reorder)
 *   - convertToGroup  -> update
 *   - convertToLeaf   -> update
 *   - deleteNode      -> delete  (hart, kein Soft-Delete in Templates)
 *   - updateNode      -> update  (Feld-Diff)
 *
 * Zeit-Semantik: defaultOffsetMinutesStart/End (Integer Minuten) statt
 * start_at/end_at. Die Validation delegiert an
 * EventTemplateService::validateTaskData, das die Offset-Feldnamen und
 * die fix-slot-Offset-Pflicht bereits kennt.
 */
final class TemplateTaskTreeService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EventTemplateRepository $templateRepo,
        private readonly SettingsRepository $settings,
        private readonly AuditService $auditService,
        private readonly EventTemplateService $templateService,
    ) {
    }

    // =========================================================================
    // Operationen
    // =========================================================================

    public function createNode(int $templateId, array $data, int $actorId): int
    {
        $this->assertEnabled();
        $this->assertTemplateEditable($templateId);

        $isGroup = !empty($data['is_group']);
        $parentId = $this->normalizeParentId($data['parent_template_task_id'] ?? null);

        if ($parentId !== null) {
            $this->assertParentIsGroupOfTemplate($parentId, $templateId);
            $newDepth = $this->templateRepo->getTaskDepth($parentId) + 1;
        } else {
            $newDepth = 0;
        }
        $this->assertWithinMaxDepth($newDepth);

        $payload = $isGroup
            ? $this->buildGroupPayload($templateId, $parentId, $data)
            : $this->buildLeafPayload($templateId, $parentId, $data);

        $this->pdo->beginTransaction();
        try {
            $taskId = $this->templateRepo->addTask($templateId, $payload);

            $this->auditService->log(
                action: 'create',
                tableName: 'event_template_tasks',
                recordId: $taskId,
                newValues: $payload,
                description: $isGroup
                    ? "Template-Knoten (Gruppe) angelegt: '{$payload['title']}'"
                    : "Template-Knoten (Aufgabe) angelegt: '{$payload['title']}'",
                metadata: [
                    'template_id'             => $templateId,
                    'parent_template_task_id' => $parentId,
                    'is_group'                => $isGroup ? 1 : 0,
                    'depth'                   => $newDepth,
                    'actor_id'                => $actorId,
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

    public function move(int $taskId, ?int $newParentId, int $newSortOrder, int $actorId): void
    {
        $this->assertEnabled();

        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Template-Knoten nicht gefunden.');
        }
        $this->assertTemplateEditable($task->getTemplateId());

        $newParentId = $this->normalizeParentId($newParentId);
        $oldParentId = $task->getParentTemplateTaskId();
        $oldSortOrder = $task->getSortOrder();

        if ($newParentId !== null) {
            $this->assertParentIsGroupOfTemplate($newParentId, $task->getTemplateId());

            if ($newParentId === $taskId) {
                throw new BusinessRuleException(
                    'Knoten kann nicht sich selbst als Eltern haben.'
                );
            }
            // isTaskDescendantOf(taskId, ancestorCandidate): ist der
            // aktuelle newParent-Kandidat ein Nachfahre von taskId?
            if ($this->templateRepo->isTaskDescendantOf($newParentId, $taskId)) {
                throw new BusinessRuleException(
                    'Verschieben wuerde einen Zyklus erzeugen (Ziel liegt im Subtree des Knotens).'
                );
            }
            $newDepth = $this->templateRepo->getTaskDepth($newParentId) + 1;
        } else {
            $newDepth = 0;
        }

        $subtreeDepth = $this->templateRepo->maxSubtreeDepth($taskId);
        $this->assertWithinMaxDepth($newDepth + $subtreeDepth);

        $oldDepth = $oldParentId !== null
            ? $this->templateRepo->getTaskDepth($oldParentId) + 1
            : 0;

        $this->pdo->beginTransaction();
        try {
            $this->templateRepo->moveTask($taskId, $newParentId, $newSortOrder);

            $this->auditService->log(
                action: 'update',
                tableName: 'event_template_tasks',
                recordId: $taskId,
                oldValues: [
                    'parent_template_task_id' => $oldParentId,
                    'sort_order'              => $oldSortOrder,
                ],
                newValues: [
                    'parent_template_task_id' => $newParentId,
                    'sort_order'              => $newSortOrder,
                ],
                description: 'Template-Knoten verschoben',
                metadata: [
                    'template_id' => $task->getTemplateId(),
                    'old_depth'   => $oldDepth,
                    'new_depth'   => $newDepth,
                    'actor_id'    => $actorId,
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
     * @param int[] $orderedTaskIds Task-IDs in gewuenschter Reihenfolge
     */
    public function reorderSiblings(
        int $templateId,
        ?int $parentId,
        array $orderedTaskIds,
        int $actorId
    ): void {
        $this->assertEnabled();
        $this->assertTemplateEditable($templateId);

        $parentId = $this->normalizeParentId($parentId);

        $current = $this->templateRepo->findTaskChildren($templateId, $parentId);
        $currentIds = array_map(fn(EventTemplateTask $t) => (int) $t->getId(), $current);
        $proposed = array_map('intval', array_values($orderedTaskIds));

        if (count($proposed) !== count($currentIds)) {
            throw new ValidationException([
                'Reorder-Liste passt nicht zur aktuellen Ebene (Anzahl unterschiedlich).',
            ]);
        }
        if (array_diff($currentIds, $proposed) !== []
            || array_diff($proposed, $currentIds) !== []
        ) {
            throw new ValidationException([
                'Reorder-Liste enthaelt fremde IDs oder vermisst Geschwister.',
            ]);
        }

        $this->pdo->beginTransaction();
        try {
            $this->templateRepo->reorderTaskSiblings($templateId, $proposed);

            $this->auditService->log(
                action: 'update',
                tableName: 'event_template_tasks',
                recordId: null,
                description: 'Reihenfolge der Template-Tasks geaendert',
                metadata: [
                    'template_id'             => $templateId,
                    'parent_template_task_id' => $parentId,
                    'children_order'          => $proposed,
                    'operation'               => 'reorder',
                    'actor_id'                => $actorId,
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

    public function convertToGroup(int $taskId, int $actorId): void
    {
        $this->assertEnabled();

        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Template-Knoten nicht gefunden.');
        }
        $this->assertTemplateEditable($task->getTemplateId());

        if ($task->isGroup()) {
            throw new BusinessRuleException('Knoten ist bereits eine Gruppe.');
        }

        $oldShape = [
            'is_group'                     => 0,
            'task_type'                    => $task->getTaskType(),
            'slot_mode'                    => $task->getSlotMode(),
            'capacity_mode'                => $task->getCapacityMode(),
            'capacity_target'              => $task->getCapacityTarget(),
            'hours_default'                => $task->getHoursDefault(),
            'default_offset_minutes_start' => $task->getDefaultOffsetMinutesStart(),
            'default_offset_minutes_end'   => $task->getDefaultOffsetMinutesEnd(),
        ];
        $newShape = [
            'is_group'                     => 1,
            'task_type'                    => EventTask::TYPE_AUFGABE,
            'slot_mode'                    => null,
            'capacity_mode'                => EventTask::CAP_UNBEGRENZT,
            'capacity_target'              => null,
            'hours_default'                => 0.0,
            'default_offset_minutes_start' => null,
            'default_offset_minutes_end'   => null,
        ];

        $this->pdo->beginTransaction();
        try {
            $this->templateRepo->convertTaskToGroup($taskId);

            $this->auditService->log(
                action: 'update',
                tableName: 'event_template_tasks',
                recordId: $taskId,
                oldValues: $oldShape,
                newValues: $newShape,
                description: "Template-Knoten in Gruppe konvertiert: '{$task->getTitle()}'",
                metadata: [
                    'template_id' => $task->getTemplateId(),
                    'actor_id'    => $actorId,
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

    public function convertToLeaf(int $taskId, array $leafData, int $actorId): void
    {
        $this->assertEnabled();

        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Template-Knoten nicht gefunden.');
        }
        $this->assertTemplateEditable($task->getTemplateId());

        if (!$task->isGroup()) {
            throw new BusinessRuleException(
                'Knoten ist bereits eine Aufgabe (kein Gruppenknoten).'
            );
        }
        if ($this->templateRepo->countActiveTaskChildren($taskId) > 0) {
            throw new BusinessRuleException(
                'Konvertieren in Aufgabe nicht moeglich: Gruppe enthaelt Kinder. '
                . 'Bitte zuerst Kinder verschieben oder loeschen.'
            );
        }

        $leafPayload = $this->buildLeafPayload(
            $task->getTemplateId(),
            $task->getParentTemplateTaskId(),
            $leafData
        );
        $convertData = [
            'task_type'                    => $leafPayload['task_type'],
            'slot_mode'                    => $leafPayload['slot_mode'],
            'default_offset_minutes_start' => $leafPayload['default_offset_minutes_start'],
            'default_offset_minutes_end'   => $leafPayload['default_offset_minutes_end'],
            'capacity_mode'                => $leafPayload['capacity_mode'],
            'capacity_target'              => $leafPayload['capacity_target'],
            'hours_default'                => $leafPayload['hours_default'],
        ];

        $oldShape = [
            'is_group'                     => 1,
            'task_type'                    => EventTask::TYPE_AUFGABE,
            'slot_mode'                    => null,
            'capacity_mode'                => EventTask::CAP_UNBEGRENZT,
            'capacity_target'              => null,
            'hours_default'                => 0.0,
            'default_offset_minutes_start' => null,
            'default_offset_minutes_end'   => null,
        ];
        $newShape = ['is_group' => 0] + $convertData;

        $this->pdo->beginTransaction();
        try {
            $this->templateRepo->convertTaskToLeaf($taskId, $convertData);

            $this->auditService->log(
                action: 'update',
                tableName: 'event_template_tasks',
                recordId: $taskId,
                oldValues: $oldShape,
                newValues: $newShape,
                description: "Template-Gruppe in Aufgabe konvertiert: '{$task->getTitle()}'",
                metadata: [
                    'template_id' => $task->getTemplateId(),
                    'actor_id'    => $actorId,
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
     * Knoten loeschen. Strikte Regel:
     *   - Gruppen mit Kindern: Ablehnung (analog zu Event-Tree).
     *
     * Hart-DELETE (kein Soft-Delete auf Template-Task-Ebene im Schema).
     */
    public function deleteNode(int $taskId, int $actorId): void
    {
        $this->assertEnabled();

        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Template-Knoten nicht gefunden.');
        }
        $this->assertTemplateEditable($task->getTemplateId());

        if ($task->isGroup() && $this->templateRepo->countActiveTaskChildren($taskId) > 0) {
            throw new BusinessRuleException(
                'Loeschen abgelehnt: Gruppe enthaelt Tasks. '
                . 'Bitte zuerst Kinder loeschen oder verschieben.'
            );
        }

        $oldSnapshot = [
            'template_id'             => $task->getTemplateId(),
            'parent_template_task_id' => $task->getParentTemplateTaskId(),
            'is_group'                => $task->isGroup() ? 1 : 0,
            'title'                   => $task->getTitle(),
            'task_type'               => $task->getTaskType(),
            'slot_mode'               => $task->getSlotMode(),
            'capacity_mode'           => $task->getCapacityMode(),
            'capacity_target'         => $task->getCapacityTarget(),
            'hours_default'           => $task->getHoursDefault(),
        ];

        $this->pdo->beginTransaction();
        try {
            $this->templateRepo->deleteTask($taskId);

            $this->auditService->log(
                action: 'delete',
                tableName: 'event_template_tasks',
                recordId: $taskId,
                oldValues: $oldSnapshot,
                description: $task->isGroup()
                    ? "Template-Gruppen-Knoten geloescht: '{$task->getTitle()}'"
                    : "Template-Aufgaben-Knoten geloescht: '{$task->getTitle()}'",
                metadata: [
                    'template_id' => $task->getTemplateId(),
                    'is_group'    => $task->isGroup() ? 1 : 0,
                    'actor_id'    => $actorId,
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
     * Attribute eines Knotens aktualisieren — ohne Shape-Wechsel. is_group
     * wird defensiv aus dem Payload entfernt; Shape-Wechsel laeuft ueber
     * convertToGroup()/convertToLeaf().
     */
    public function updateNode(int $taskId, array $data, int $actorId): void
    {
        $this->assertEnabled();

        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Template-Knoten nicht gefunden.');
        }
        $this->assertTemplateEditable($task->getTemplateId());

        unset($data['is_group']);

        $base = [
            'title'                        => $task->getTitle(),
            'description'                  => $task->getDescription(),
            'category_id'                  => $task->getCategoryId(),
            'task_type'                    => $task->getTaskType(),
            'slot_mode'                    => $task->getSlotMode(),
            'default_offset_minutes_start' => $task->getDefaultOffsetMinutesStart(),
            'default_offset_minutes_end'   => $task->getDefaultOffsetMinutesEnd(),
            'capacity_mode'                => $task->getCapacityMode(),
            'capacity_target'              => $task->getCapacityTarget(),
            'hours_default'                => $task->getHoursDefault(),
            'sort_order'                   => $task->getSortOrder(),
        ];
        $merged = $data + $base;

        $payload = $task->isGroup()
            ? $this->buildGroupPayload($task->getTemplateId(), $task->getParentTemplateTaskId(), $merged)
            : $this->buildLeafPayload($task->getTemplateId(), $task->getParentTemplateTaskId(), $merged);

        // Feld-Diff: nur geaenderte Felder loggen.
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
            return; // nichts veraendert
        }

        $this->pdo->beginTransaction();
        try {
            $this->templateRepo->updateTask($taskId, $payload);

            $this->auditService->log(
                action: 'update',
                tableName: 'event_template_tasks',
                recordId: $taskId,
                oldValues: $oldValues,
                newValues: $newValues,
                description: $task->isGroup()
                    ? "Template-Gruppe aktualisiert: '{$payload['title']}'"
                    : "Template-Task aktualisiert: '{$payload['title']}'",
                metadata: [
                    'template_id'             => $task->getTemplateId(),
                    'parent_template_task_id' => $task->getParentTemplateTaskId(),
                    'is_group'                => $task->isGroup() ? 1 : 0,
                    'actor_id'                => $actorId,
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
     * Gemeinsames Flag mit TaskTreeService — Events und Templates gehoeren
     * zum selben Rollout-Switch (G1-Entscheidung Flag).
     */
    private function assertEnabled(): void
    {
        $value = $this->settings->getValue(TaskTreeService::SETTING_ENABLED, '0');
        if ($value !== '1' && $value !== 'true') {
            throw new BusinessRuleException(
                'Aufgabenbaum-Editor ist nicht freigeschaltet '
                . '(Setting events.tree_editor_enabled).'
            );
        }
    }

    private function assertWithinMaxDepth(int $depth): void
    {
        $maxDepth = (int) ($this->settings->getValue(
            TaskTreeService::SETTING_MAX_DEPTH,
            (string) TaskTreeService::DEFAULT_MAX_DEPTH
        ) ?? TaskTreeService::DEFAULT_MAX_DEPTH);
        if ($maxDepth < 1) {
            $maxDepth = TaskTreeService::DEFAULT_MAX_DEPTH;
        }
        if ($depth > $maxDepth) {
            throw new BusinessRuleException(
                "Maximaltiefe des Template-Baums ueberschritten "
                . "(Tiefe {$depth} > erlaubt {$maxDepth})."
            );
        }
    }

    /**
     * Template-Editier-Sperre: nur aktuelle Versionen ohne abgeleitete Events
     * sind editierbar. Analog zu EventTemplateService::addTask et al.
     */
    private function assertTemplateEditable(int $templateId): void
    {
        $template = $this->templateRepo->findById($templateId);
        if ($template === null) {
            throw new BusinessRuleException('Template nicht gefunden.');
        }
        if (!$template->isCurrent()) {
            throw new BusinessRuleException(
                'Nur aktuelle Template-Version kann bearbeitet werden.'
            );
        }
        if ($this->templateRepo->hasDerivedEvents($templateId)) {
            throw new BusinessRuleException(
                'Template ist gesperrt, da bereits Events daraus abgeleitet wurden. '
                . 'Bitte zuerst "Als neue Version speichern".'
            );
        }
    }

    private function assertParentIsGroupOfTemplate(int $parentId, int $templateId): void
    {
        $parent = $this->templateRepo->findTaskById($parentId);
        if ($parent === null) {
            throw new BusinessRuleException('Eltern-Knoten nicht gefunden.');
        }
        if ($parent->getTemplateId() !== $templateId) {
            throw new BusinessRuleException(
                'Eltern-Knoten gehoert zu einem anderen Template.'
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
     * Datenpaket fuer einen Gruppen-Knoten: Shape-Felder erzwungen.
     * Template-spezifisch: default_offset_minutes_start/end = null.
     */
    private function buildGroupPayload(int $templateId, ?int $parentId, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(['Knoten-Titel ist Pflichtfeld.']);
        }
        $description = isset($data['description']) ? trim((string) $data['description']) : '';

        return [
            'template_id'                  => $templateId,
            'parent_template_task_id'      => $parentId,
            'is_group'                     => 1,
            'category_id'                  => null,
            'title'                        => $title,
            'description'                  => $description !== '' ? $description : null,
            'task_type'                    => EventTask::TYPE_AUFGABE,
            'slot_mode'                    => null,
            'default_offset_minutes_start' => null,
            'default_offset_minutes_end'   => null,
            'capacity_mode'                => EventTask::CAP_UNBEGRENZT,
            'capacity_target'              => null,
            'hours_default'                => 0.0,
            'sort_order'                   => max(0, (int) ($data['sort_order'] ?? 0)),
        ];
    }

    /**
     * Datenpaket fuer einen Leaf-Knoten: delegiert an
     * EventTemplateService::validateTaskData (gemeinsame Validation,
     * G1-Entscheidung A). Die Rueckgabe wird um die Template-Hierarchie-
     * Felder template_id und parent_template_task_id angereichert — diese
     * beiden sind kontext-abhaengig und nicht Teil der Task-Daten.
     */
    private function buildLeafPayload(int $templateId, ?int $parentId, array $data): array
    {
        $validated = $this->templateService->validateTaskData($data);
        $validated['template_id']             = $templateId;
        $validated['parent_template_task_id'] = $parentId;
        $validated['is_group']                = 0;
        return $validated;
    }
}
