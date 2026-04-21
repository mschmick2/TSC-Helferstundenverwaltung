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
