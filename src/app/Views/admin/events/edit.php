<?php
/**
 * @var \App\Models\Event $event
 * @var \App\Models\User[] $users
 * @var int[] $organizerIds
 * @var array|null $conflictMyState   Modul 7 I4: gesetzt, wenn der letzte POST
 *                                     einen Versions-Konflikt ausgeloest hat.
 *                                     Enthaelt title/description/location/
 *                                     start_at/end_at/cancel_deadline_hours/
 *                                     organizer_ids (die Nutzer-Eingaben).
 */
use App\Helpers\ViewHelper;

$conflict = $conflictMyState ?? null;

// Diff-Zeilen fuer die Konflikt-Tabelle aufbauen: nur Felder zeigen, die
// tatsaechlich abweichen. Typen werden stringifiziert, damit z.B. DB-'0' und
// PHP-0 als gleich zaehlen.
$conflictRows = [];
if ($conflict !== null) {
    $fields = [
        'title'                 => ['label' => 'Titel', 'current' => $event->getTitle()],
        'description'           => ['label' => 'Beschreibung', 'current' => $event->getDescription()],
        'location'              => ['label' => 'Ort', 'current' => $event->getLocation()],
        'start_at'              => ['label' => 'Start', 'current' => $event->getStartAt()],
        'end_at'                => ['label' => 'Ende', 'current' => $event->getEndAt()],
        'cancel_deadline_hours' => ['label' => 'Storno-Deadline (h)', 'current' => $event->getCancelDeadlineHours()],
    ];
    foreach ($fields as $key => $meta) {
        $mine = $conflict[$key] ?? null;
        $curr = $meta['current'];
        if ((string) ($mine ?? '') === (string) ($curr ?? '')) {
            continue;
        }
        $conflictRows[] = [
            'label'   => $meta['label'],
            'mine'    => $mine,
            'current' => $curr,
        ];
    }

    // Organizer-Vergleich: Mengenvergleich der User-IDs.
    $myOrg = array_map('intval', (array) ($conflict['organizer_ids'] ?? []));
    $curOrg = array_map('intval', $organizerIds);
    sort($myOrg);
    sort($curOrg);
    if ($myOrg !== $curOrg) {
        $nameOf = function (int $uid) use ($users): string {
            foreach ($users as $u) {
                if ((int) $u->getId() === $uid) {
                    return $u->getNachname() . ', ' . $u->getVorname();
                }
            }
            return '#' . $uid;
        };
        $conflictRows[] = [
            'label'   => 'Organisator(en)',
            'mine'    => implode('; ', array_map($nameOf, $myOrg)),
            'current' => implode('; ', array_map($nameOf, $curOrg)),
        ];
    }
}
?>

<h1 class="h3 mb-3"><i class="bi bi-pencil-square"></i> Event bearbeiten</h1>

<?php if ($treeEditorEnabled ?? false): ?>
    <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-info-circle-fill me-2 fs-5" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <strong>Neue Editor-Ansicht verfuegbar.</strong>
            Der non-modale Editor bietet Sidebar-Zusammenfassung und
            verbesserte Navigation durch den Aufgabenbaum.
        </div>
        <a href="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/editor') ?>"
           class="btn btn-primary btn-sm ms-3">
            Editor oeffnen
        </a>
    </div>
<?php endif; ?>

<?php if ($conflict !== null): ?>
    <div class="alert alert-warning" role="alert">
        <h2 class="h5 alert-heading">
            <i class="bi bi-exclamation-triangle-fill"></i>
            Gleichzeitige Aenderung erkannt
        </h2>
        <p class="mb-2">
            Waehrend du das Formular bearbeitet hast, hat ein anderer Tab oder
            Admin den Datensatz bereits geaendert. Die Eingabemaske zeigt jetzt
            den <strong>aktuellen Stand aus der Datenbank</strong>. Unten
            siehst du, was du gerade eingetippt hattest &mdash; uebernimm
            Feld fuer Feld, was du behalten willst, und speichere erneut.
        </p>
        <?php if ($conflictRows !== []): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Feld</th>
                            <th style="width: 39%;">Dein Stand (nicht gespeichert)</th>
                            <th style="width: 39%;">Aktueller DB-Stand (im Formular vorbelegt)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conflictRows as $row): ?>
                            <tr>
                                <th scope="row"><?= ViewHelper::e($row['label']) ?></th>
                                <td class="text-break"><?= ViewHelper::e((string) ($row['mine'] ?? '')) ?: '<em class="text-muted">leer</em>' ?></td>
                                <td class="text-break"><?= ViewHelper::e((string) ($row['current'] ?? '')) ?: '<em class="text-muted">leer</em>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="mb-0 text-muted">
                Keine konkreten Feld-Abweichungen &mdash; offenbar wurde nur
                ein Folge-Schreibvorgang registriert. Einfach erneut speichern.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="POST" action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId()) ?>"
      class="row g-3 needs-validation" novalidate>
    <?= ViewHelper::csrfField() ?>
    <input type="hidden" name="version" value="<?= (int) $event->getVersion() ?>"><!-- Modul 7 I3: Optimistic Locking -->

    <div class="col-md-12">
        <label for="title" class="form-label">Titel <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="title" name="title"
               value="<?= ViewHelper::e($event->getTitle()) ?>" maxlength="200" required>
    </div>

    <div class="col-md-12">
        <label for="description" class="form-label">Beschreibung</label>
        <textarea class="form-control" id="description" name="description" rows="3"><?= ViewHelper::e($event->getDescription()) ?></textarea>
    </div>

    <div class="col-md-6">
        <label for="location" class="form-label">Ort</label>
        <input type="text" class="form-control" id="location" name="location"
               value="<?= ViewHelper::e($event->getLocation()) ?>" maxlength="500">
    </div>

    <div class="col-md-6">
        <label for="cancel_deadline_hours" class="form-label">Storno-Deadline (h)</label>
        <input type="number" class="form-control" id="cancel_deadline_hours"
               name="cancel_deadline_hours" value="<?= (int) $event->getCancelDeadlineHours() ?>" min="0">
    </div>

    <div class="col-md-6">
        <label for="start_at" class="form-label">Start <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="start_at" name="start_at"
               value="<?= ViewHelper::e(substr($event->getStartAt(), 0, 16)) ?>" required>
    </div>

    <div class="col-md-6">
        <label for="end_at" class="form-label">Ende <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="end_at" name="end_at"
               value="<?= ViewHelper::e(substr($event->getEndAt(), 0, 16)) ?>" required>
    </div>

    <div class="col-md-12">
        <label for="organizer_ids" class="form-label">Organisator(en) <span class="text-danger">*</span></label>
        <select multiple class="form-select" id="organizer_ids" name="organizer_ids[]" size="6" required>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u->getId() ?>"
                    <?= in_array((int) $u->getId(), $organizerIds, true) ? 'selected' : '' ?>>
                    <?= ViewHelper::e($u->getNachname() . ', ' . $u->getVorname()) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> Speichern
        </button>
        <a href="<?= ViewHelper::url('/admin/events/' . (int) $event->getId()) ?>" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>

<?php
// =============================================================================
// Modul 6 I7b1 - Aufgabenbaum-Editor (hinter Settings-Flag tree_editor_enabled).
// Wenn Flag aus: dieser Abschnitt rendert gar nicht — die flache Task-UI auf
// der Detail-Seite (show.php) bleibt Single-Source. Wenn Flag an: Tree-Editor-
// Widget als eigener Abschnitt unterhalb des Event-Formulars.
// =============================================================================
$treeEditorEnabled = !empty($treeEditorEnabled);
$treeData          = $treeData ?? [];
$taskCategories    = $taskCategories ?? [];
$csrfTokenString   = $_SESSION['csrf_token'] ?? '';
$eventIdForTree    = (int) $event->getId();

if ($treeEditorEnabled):
    // Rekursiver Renderer: Closure faengt $node/$depth pro Aufruf, vermeidet
    // Scope-Leak, den ein nacktes include im foreach sonst haette. Partial
    // _task_tree_node.php erwartet $renderTaskNode im Scope fuer den Kinder-
    // Loop.
    $renderTaskNode = function (array $node, int $depth) use (
        &$renderTaskNode, $csrfTokenString, $eventIdForTree
    ): void {
        $csrfToken = $csrfTokenString;
        $eventId   = $eventIdForTree;
        include __DIR__ . '/_task_tree_node.php';
    };

    // Kategorien als JSON ins data-Attribut — das Modal-JS liest sie beim
    // Form-Aufbau (Alternative waere ein zweiter Fetch, aber die Liste ist
    // ohnehin pro Render stabil und klein).
    $categoriesJson = json_encode(
        array_map(
            static fn($c) => [
                'id'               => (int) $c->getId(),
                'name'             => $c->getName(),
                'is_contribution'  => $c->isContribution(),
            ],
            $taskCategories
        ),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    );
?>

<hr class="my-4">

<?php include __DIR__ . '/../../components/_edit_sessions_indicator.php'; ?>

<section class="task-tree-editor"
         id="task-tree-editor"
         data-event-id="<?= $eventIdForTree ?>"
         data-csrf-token="<?= ViewHelper::e($csrfTokenString) ?>"
         data-endpoint-tree="<?= ViewHelper::url('/admin/events/' . $eventIdForTree . '/tasks/tree') ?>"
         data-endpoint-create="<?= ViewHelper::url('/admin/events/' . $eventIdForTree . '/tasks/node') ?>"
         data-endpoint-reorder="<?= ViewHelper::url('/admin/events/' . $eventIdForTree . '/tasks/reorder') ?>"
         data-categories="<?= ViewHelper::e($categoriesJson) ?>">

    <div class="d-flex align-items-center justify-content-between mb-2">
        <h2 class="h4 mb-0">
            <i class="bi bi-diagram-3" aria-hidden="true"></i>
            Aufgabenbaum
        </h2>
        <button type="button" class="btn btn-primary btn-sm"
                data-action="add-child"
                data-parent-task-id=""
                title="Top-Level-Knoten anlegen">
            <i class="bi bi-plus-circle" aria-hidden="true"></i>
            Knoten anlegen
        </button>
    </div>

    <?php include __DIR__ . '/_task_edit_modal.php'; ?>

    <?php if (empty($treeData)): ?>
        <p class="text-muted mb-0">
            Noch keine Aufgaben. Lege den ersten Knoten oben an &mdash; ein Gruppen-
            Knoten fasst weitere Aufgaben zusammen, ein Aufgaben-Knoten steht fuer
            eine konkrete Helfer-Taetigkeit.
        </p>
    <?php else: ?>
        <ul class="task-tree-root list-unstyled mb-0"
            data-parent-task-id=""
            data-endpoint-reorder="<?= ViewHelper::url('/admin/events/' . $eventIdForTree . '/tasks/reorder') ?>">
            <?php foreach ($treeData as $topNode): ?>
                <?php $renderTaskNode($topNode, 0); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <noscript>
        <div class="alert alert-warning mt-3" role="alert">
            <strong>JavaScript aus:</strong>
            Der Aufgabenbaum-Editor (Drag &amp; Drop, Modal) braucht JavaScript.
            Bitte nutze die Detail-Seite des Events fuer die Aufgaben-Pflege.
        </div>
    </noscript>
</section>

<script src="<?= ViewHelper::url('/js/vendor/sortablejs/Sortable.min.js') ?>"></script>
<script src="<?= ViewHelper::url('/js/event-task-tree.js') ?>"></script>
<script src="<?= ViewHelper::url('/js/edit-session.js') ?>"></script>
<?php endif; // $treeEditorEnabled ?>
