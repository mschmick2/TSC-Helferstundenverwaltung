<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\Event;
use App\Models\EventTask;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\EventTemplateRepository;
use PDO;

/**
 * Service fuer Event-Templates (Modul 6 I4):
 *   - Task-Editor auf aktueller Template-Version
 *   - Save-as-new-Version (atomar: alte is_current=0, neue mit kopierten Tasks)
 *   - Event-Ableitung aus Template (Task-Snapshot mit absoluten Zeiten)
 *
 * Policy:
 *   - Template mit bereits abgeleiteten Events ist fuer In-Place-Edit gesperrt
 *     (Task-CRUD wirft BusinessRuleException). Nur Save-as-new-Version moeglich.
 *   - saveAsNewVersion kopiert ALLE Tasks des Parents; Aenderungen werden
 *     anschliessend am neuen Template vorgenommen (zweiter Request).
 *   - deriveEvent speichert source_template_id + source_template_version als
 *     Snapshot-Referenz. Spaetere Template-Updates propagieren NICHT.
 */
final class EventTemplateService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EventTemplateRepository $templateRepo,
        private readonly EventRepository $eventRepo,
        private readonly EventTaskRepository $eventTaskRepo,
        private readonly AuditService $auditService,
    ) {
    }

    // =========================================================================
    // Task-Editor (in-place auf aktueller Version, wenn noch keine Events derived)
    // =========================================================================

    public function addTask(int $templateId, array $taskData, int $actorId): int
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

        $validated = $this->validateTaskData($taskData);
        $validated['template_id'] = $templateId;

        $taskId = $this->templateRepo->addTask($templateId, $validated);

        $this->auditService->log(
            action: 'create',
            tableName: 'event_template_tasks',
            recordId: $taskId,
            newValues: [
                'template_id'   => $templateId,
                'title'         => $validated['title'],
                'description'   => $validated['description'],
                'task_type'     => $validated['task_type'],
                'slot_mode'     => $validated['slot_mode'],
                'default_offset_minutes_start' => $validated['default_offset_minutes_start'],
                'default_offset_minutes_end'   => $validated['default_offset_minutes_end'],
                'capacity_mode' => $validated['capacity_mode'],
                'capacity_target' => $validated['capacity_target'],
                'hours_default' => $validated['hours_default'],
                'sort_order'    => $validated['sort_order'],
                'category_id'   => $validated['category_id'],
            ],
            description: "Task '{$validated['title']}' zu Template v{$template->getVersion()} hinzugefuegt",
            metadata: ['template_id' => $templateId],
        );

        return $taskId;
    }

    public function updateTask(int $taskId, array $taskData, int $actorId): void
    {
        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Task nicht gefunden.');
        }

        $template = $this->templateRepo->findById($task->getTemplateId());
        if ($template === null || !$template->isCurrent()) {
            throw new BusinessRuleException(
                'Nur Tasks der aktuellen Template-Version koennen bearbeitet werden.'
            );
        }
        if ($this->templateRepo->hasDerivedEvents($task->getTemplateId())) {
            throw new BusinessRuleException(
                'Template ist gesperrt. Bitte zuerst "Als neue Version speichern".'
            );
        }

        $validated = $this->validateTaskData($taskData);
        $this->templateRepo->updateTask($taskId, $validated);

        $oldSnapshot = [
            'title'                        => $task->getTitle(),
            'description'                  => $task->getDescription(),
            'task_type'                    => $task->getTaskType(),
            'slot_mode'                    => $task->getSlotMode(),
            'default_offset_minutes_start' => $task->getDefaultOffsetMinutesStart(),
            'default_offset_minutes_end'   => $task->getDefaultOffsetMinutesEnd(),
            'capacity_mode'                => $task->getCapacityMode(),
            'capacity_target'              => $task->getCapacityTarget(),
            'hours_default'                => $task->getHoursDefault(),
            'sort_order'                   => $task->getSortOrder(),
            'category_id'                  => $task->getCategoryId(),
        ];

        $diffOld = [];
        $diffNew = [];
        foreach ($oldSnapshot as $field => $oldValue) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }
            $newValue = $validated[$field];
            if ($oldValue === $newValue) {
                continue;
            }
            // Float-Toleranz fuer hours_default (0.25-Step)
            if (is_float($oldValue) && is_float($newValue) && abs($oldValue - $newValue) < 0.001) {
                continue;
            }
            $diffOld[$field] = $oldValue;
            $diffNew[$field] = $newValue;
        }

        if ($diffOld !== []) {
            $this->auditService->log(
                action: 'update',
                tableName: 'event_template_tasks',
                recordId: $taskId,
                oldValues: $diffOld,
                newValues: $diffNew,
                description: 'Template-Task aktualisiert',
                metadata: ['template_id' => $template->getId()],
            );
        }
    }

    public function deleteTask(int $taskId, int $actorId): void
    {
        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Task nicht gefunden.');
        }

        $template = $this->templateRepo->findById($task->getTemplateId());
        if ($template === null || !$template->isCurrent()) {
            throw new BusinessRuleException(
                'Nur Tasks der aktuellen Template-Version koennen geloescht werden.'
            );
        }
        if ($this->templateRepo->hasDerivedEvents($task->getTemplateId())) {
            throw new BusinessRuleException(
                'Template ist gesperrt. Bitte zuerst "Als neue Version speichern".'
            );
        }

        $this->templateRepo->deleteTask($taskId);

        $this->auditService->log(
            action: 'delete',
            tableName: 'event_template_tasks',
            recordId: $taskId,
            oldValues: ['title' => $task->getTitle()],
            description: 'Template-Task geloescht',
            metadata: ['template_id' => $template->getId()],
        );
    }

    // =========================================================================
    // Versionierung
    // =========================================================================

    /**
     * Template als neue Version speichern.
     *   - parent.is_current=0
     *   - neue Version mit is_current=1, parent_template_id=parent.id
     *   - alle Tasks kopieren
     * Returns: neue Template-ID.
     */
    public function saveAsNewVersion(
        int $parentTemplateId,
        string $name,
        ?string $description,
        int $actorId
    ): int {
        $parent = $this->templateRepo->findById($parentTemplateId);
        if ($parent === null) {
            throw new BusinessRuleException('Parent-Template nicht gefunden.');
        }
        if (!$parent->isCurrent()) {
            throw new BusinessRuleException(
                'Nur die aktuelle Version darf als neue Version gespeichert werden.'
            );
        }

        $name = trim($name);
        if ($name === '') {
            throw new ValidationException(['Template-Name ist Pflichtfeld.']);
        }

        $this->pdo->beginTransaction();
        try {
            $newId = $this->templateRepo->saveAsNewVersion(
                $parentTemplateId,
                $name,
                $description !== '' ? $description : null,
                $actorId
            );

            $this->templateRepo->copyTasks($parentTemplateId, $newId);

            $newVersion = $parent->getVersion() + 1;

            // Neue Template-Version als eigener create-Eintrag
            $this->auditService->log(
                action: 'create',
                tableName: 'event_templates',
                recordId: $newId,
                newValues: [
                    'name'               => $name,
                    'version'            => $newVersion,
                    'parent_template_id' => $parentTemplateId,
                    'is_current'         => 1,
                ],
                description: "Template '{$name}' als neue Version v{$newVersion} gespeichert",
            );

            // Parent-Deaktivierung als separater update-Eintrag
            $this->auditService->log(
                action: 'update',
                tableName: 'event_templates',
                recordId: $parentTemplateId,
                oldValues: ['is_current' => 1],
                newValues: ['is_current' => 0],
                description: "Template-Version v{$parent->getVersion()} durch v{$newVersion} abgeloest",
                metadata: ['successor_id' => $newId],
            );

            $this->pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // Event-Ableitung
    // =========================================================================

    /**
     * Event aus Template ableiten: Event-Kopf + alle Tasks als Snapshot.
     *
     * @param array $eventData title, description, location, start_at, end_at,
     *                         cancel_deadline_hours
     * @return int neue Event-ID
     */
    public function deriveEvent(int $templateId, array $eventData, int $actorId): int
    {
        $template = $this->templateRepo->findById($templateId);
        if ($template === null) {
            throw new BusinessRuleException('Template nicht gefunden.');
        }
        if ($template->getDeletedAt() !== null) {
            throw new BusinessRuleException('Geloeschte Templates koennen nicht abgeleitet werden.');
        }

        $title = trim((string) ($eventData['title'] ?? ''));
        $startAt = trim((string) ($eventData['start_at'] ?? ''));
        $endAt = trim((string) ($eventData['end_at'] ?? ''));

        if ($title === '' || $startAt === '' || $endAt === '') {
            throw new ValidationException(['Titel, Start und Ende sind Pflichtfelder.']);
        }

        $startTs = strtotime($startAt);
        $endTs = strtotime($endAt);
        if ($startTs === false || $endTs === false) {
            throw new ValidationException(['Datum/Zeit-Format ungueltig.']);
        }
        if ($endTs < $startTs) {
            throw new ValidationException(['Ende darf nicht vor Start liegen.']);
        }

        // Normalisieren auf MySQL-Format (datetime-local liefert T-getrennt)
        $startAtSql = date('Y-m-d H:i:s', $startTs);
        $endAtSql   = date('Y-m-d H:i:s', $endTs);

        $templateTasks = $this->templateRepo->findTasksByTemplate($templateId);
        if (count($templateTasks) === 0) {
            throw new BusinessRuleException(
                'Template hat keine Tasks. Event kann nicht abgeleitet werden.'
            );
        }

        $this->pdo->beginTransaction();
        try {
            $eventId = $this->eventRepo->create([
                'title' => $title,
                'description' => $eventData['description'] ?? null,
                'location' => $eventData['location'] ?? null,
                'start_at' => $startAtSql,
                'end_at' => $endAtSql,
                'cancel_deadline_hours' => $eventData['cancel_deadline_hours']
                    ?? Event::DEFAULT_CANCEL_DEADLINE_HOURS,
                'created_by' => $actorId,
                'source_template_id' => $templateId,
                'source_template_version' => $template->getVersion(),
            ]);

            foreach ($templateTasks as $tt) {
                $taskStart = null;
                $taskEnd = null;
                if ($tt->getSlotMode() === EventTask::SLOT_FIX) {
                    if ($tt->getDefaultOffsetMinutesStart() !== null) {
                        $taskStart = date(
                            'Y-m-d H:i:s',
                            $startTs + (int) $tt->getDefaultOffsetMinutesStart() * 60
                        );
                    }
                    if ($tt->getDefaultOffsetMinutesEnd() !== null) {
                        $taskEnd = date(
                            'Y-m-d H:i:s',
                            $startTs + (int) $tt->getDefaultOffsetMinutesEnd() * 60
                        );
                    }
                }

                $this->eventTaskRepo->create([
                    'event_id' => $eventId,
                    'category_id' => $tt->getCategoryId(),
                    'title' => $tt->getTitle(),
                    'description' => $tt->getDescription(),
                    'task_type' => $tt->getTaskType(),
                    'slot_mode' => $tt->getSlotMode(),
                    'start_at' => $taskStart,
                    'end_at' => $taskEnd,
                    'capacity_mode' => $tt->getCapacityMode(),
                    'capacity_target' => $tt->getCapacityTarget(),
                    'hours_default' => $tt->getHoursDefault(),
                    'sort_order' => $tt->getSortOrder(),
                ]);
            }

            $this->auditService->log(
                action: 'create',
                tableName: 'events',
                recordId: $eventId,
                newValues: [
                    'title' => $title,
                    'source_template_id' => $templateId,
                    'source_template_version' => $template->getVersion(),
                ],
                description: "Event '{$title}' aus Template '{$template->getName()}' v{$template->getVersion()} abgeleitet",
                metadata: [
                    'tasks_copied' => count($templateTasks),
                ],
            );

            $this->pdo->commit();
            return $eventId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * ENUM-Allowlist-Validierung fuer Task-Inputs (G4-Muster aus I1).
     */
    private function validateTaskData(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(['Task-Titel ist Pflichtfeld.']);
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

        $offsetStart = $data['default_offset_minutes_start'] ?? null;
        $offsetEnd = $data['default_offset_minutes_end'] ?? null;
        if ($offsetStart !== null && $offsetStart !== '') {
            $offsetStart = (int) $offsetStart;
        } else {
            $offsetStart = null;
        }
        if ($offsetEnd !== null && $offsetEnd !== '') {
            $offsetEnd = (int) $offsetEnd;
        } else {
            $offsetEnd = null;
        }
        if ($offsetStart !== null && $offsetEnd !== null && $offsetEnd < $offsetStart) {
            throw new ValidationException(['Offset-Ende liegt vor Offset-Start.']);
        }

        // Bei slot_mode='fix' sind beide Offsets Pflicht, sonst verletzt
        // die abgeleitete event_tasks-Row den Check-Constraint chk_et_fix_times.
        if ($slotMode === EventTask::SLOT_FIX && ($offsetStart === null || $offsetEnd === null)) {
            throw new ValidationException([
                'Bei Slot-Modus "fix" muessen Offset-Start und Offset-Ende gesetzt sein.'
            ]);
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
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'task_type' => $taskType,
            'slot_mode' => $slotMode,
            'default_offset_minutes_start' => $offsetStart,
            'default_offset_minutes_end' => $offsetEnd,
            'capacity_mode' => $capacityMode,
            'capacity_target' => $capTarget,
            'hours_default' => $hours,
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
            'category_id' => $categoryId,
        ];
    }
}
