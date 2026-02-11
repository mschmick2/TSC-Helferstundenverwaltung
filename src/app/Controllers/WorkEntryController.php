<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Helpers\ViewHelper;
use App\Models\User;
use App\Models\WorkEntry;
use App\Repositories\CategoryRepository;
use App\Repositories\DialogReadStatusRepository;
use App\Repositories\DialogRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use App\Services\AuditService;
use App\Services\EmailService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Controller für Arbeitsstunden-Einträge (CRUD + Workflow)
 */
class WorkEntryController extends BaseController
{
    public function __construct(
        private WorkEntryRepository $entryRepo,
        private CategoryRepository $categoryRepo,
        private DialogRepository $dialogRepo,
        private DialogReadStatusRepository $dialogReadStatusRepo,
        private WorkflowService $workflowService,
        private AuditService $auditService,
        private EmailService $emailService,
        private UserRepository $userRepo,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
        private array $settings
    ) {
    }

    // =========================================================================
    // CRUD: Eigene Einträge
    // =========================================================================

    /**
     * Liste eigener Einträge
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $params = $request->getQueryParams();

        $result = $this->entryRepo->findByUser(
            $user->getId(),
            (int) ($params['page'] ?? 1),
            20,
            $params['status'] ?? null,
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            isset($params['category_id']) ? (int) $params['category_id'] : null,
            $params['sort'] ?? 'work_date',
            $params['dir'] ?? 'DESC'
        );

        $categories = $this->categoryRepo->findAllActive();

        return $this->render($response, 'entries/index', [
            'title' => 'Meine Arbeitsstunden',
            'entries' => $result['entries'],
            'total' => $result['total'],
            'page' => (int) ($params['page'] ?? 1),
            'perPage' => 20,
            'categories' => $categories,
            'filters' => $params,
            'user' => $user,
        ]);
    }

    /**
     * Formular: Neuer Eintrag
     */
    public function create(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $categories = $this->categoryRepo->findAllActive();

        return $this->render($response, 'entries/create', [
            'title' => 'Neue Arbeitsstunden erfassen',
            'categories' => $categories,
            'user' => $user,
            'fieldConfig' => $this->settingsService->getFieldConfig(),
        ]);
    }

    /**
     * Neuen Eintrag speichern
     */
    public function store(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $data = (array) $request->getParsedBody();

        try {
            $validated = $this->validateEntry($data);

            // user_id = für wen (bei Erfasser: kann anderer Benutzer sein)
            $forUserId = $user->getId();
            if (isset($data['user_id']) && (int) $data['user_id'] !== $user->getId()) {
                if (!$user->canCreateForOthers()) {
                    throw new AuthorizationException('Keine Berechtigung, Einträge für andere zu erstellen.');
                }
                $forUserId = (int) $data['user_id'];
            }

            $entryId = $this->entryRepo->create([
                'user_id' => $forUserId,
                'created_by_user_id' => $user->getId(),
                'category_id' => $validated['category_id'],
                'work_date' => $validated['work_date'],
                'time_from' => $validated['time_from'],
                'time_to' => $validated['time_to'],
                'hours' => $validated['hours'],
                'project' => $validated['project'],
                'description' => $validated['description'],
                'status' => 'entwurf',
            ]);

            $entry = $this->entryRepo->findById($entryId);

            $this->auditService->log(
                'create',
                'work_entries',
                $entryId,
                null,
                $this->entryRepo->getRawById($entryId),
                'Arbeitsstunden-Eintrag erstellt',
                $entry ? $entry->getEntryNumber() : null
            );

            // Direkt einreichen wenn gewünscht
            if (isset($data['submit_immediately']) && $data['submit_immediately'] === '1' && $entry) {
                $this->workflowService->submit($entry, $user);
                ViewHelper::flash('success', 'Eintrag erstellt und eingereicht.');
            } else {
                ViewHelper::flash('success', 'Eintrag als Entwurf gespeichert.');
            }

            return $this->redirect($response, '/entries');
        } catch (ValidationException $e) {
            ViewHelper::flashOldInput($data);
            foreach ($e->getErrors() as $error) {
                ViewHelper::flash('error', $error);
            }
            return $this->redirect($response, '/entries/create');
        } catch (AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
            return $this->redirect($response, '/entries');
        }
    }

    /**
     * Eintrag anzeigen (Detail mit Dialog)
     */
    public function show(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        if (!$this->canViewEntry($entry, $user)) {
            ViewHelper::flash('error', 'Keine Berechtigung.');
            return $this->redirect($response, '/entries');
        }

        $dialogs = $this->dialogRepo->findByEntryId($entry->getId());

        // Dialog-Nachrichten als gelesen markieren
        $this->dialogReadStatusRepo->markAsRead($user->getId(), $entry->getId());

        return $this->render($response, 'entries/show', [
            'title' => 'Antrag ' . $entry->getEntryNumber(),
            'entry' => $entry,
            'dialogs' => $dialogs,
            'user' => $user,
        ]);
    }

    /**
     * Formular: Eintrag bearbeiten (nur Entwurf)
     */
    public function edit(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        if (!$entry->isEditable()) {
            ViewHelper::flash('error', 'Dieser Eintrag kann nicht bearbeitet werden.');
            return $this->redirect($response, '/entries/' . $entry->getId());
        }

        if (!$this->isOwnerOrCreator($entry, $user)) {
            ViewHelper::flash('error', 'Keine Berechtigung.');
            return $this->redirect($response, '/entries');
        }

        $categories = $this->categoryRepo->findAllActive();

        return $this->render($response, 'entries/edit', [
            'title' => 'Eintrag bearbeiten',
            'entry' => $entry,
            'categories' => $categories,
            'user' => $user,
            'fieldConfig' => $this->settingsService->getFieldConfig(),
        ]);
    }

    /**
     * Eintrag aktualisieren
     */
    public function update(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        if (!$entry->isEditable()) {
            ViewHelper::flash('error', 'Dieser Eintrag kann nicht bearbeitet werden.');
            return $this->redirect($response, '/entries/' . $entry->getId());
        }

        if (!$this->isOwnerOrCreator($entry, $user)) {
            ViewHelper::flash('error', 'Keine Berechtigung.');
            return $this->redirect($response, '/entries');
        }

        $data = (array) $request->getParsedBody();

        try {
            $validated = $this->validateEntry($data);
            $oldValues = $this->entryRepo->getRawById($entry->getId());

            $updated = $this->entryRepo->update(
                $entry->getId(),
                $validated,
                (int) ($data['version'] ?? $entry->getVersion())
            );

            if (!$updated) {
                throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
            }

            $this->auditService->log(
                'update',
                'work_entries',
                $entry->getId(),
                $oldValues,
                $this->entryRepo->getRawById($entry->getId()),
                'Arbeitsstunden-Eintrag bearbeitet',
                $entry->getEntryNumber()
            );

            // Direkt einreichen wenn gewünscht
            if (isset($data['submit_immediately']) && $data['submit_immediately'] === '1') {
                $freshEntry = $this->entryRepo->findById($entry->getId());
                if ($freshEntry) {
                    $this->workflowService->submit($freshEntry, $user);
                    ViewHelper::flash('success', 'Eintrag aktualisiert und eingereicht.');
                }
            } else {
                ViewHelper::flash('success', 'Eintrag aktualisiert.');
            }

            return $this->redirect($response, '/entries');
        } catch (ValidationException $e) {
            ViewHelper::flashOldInput($data);
            foreach ($e->getErrors() as $error) {
                ViewHelper::flash('error', $error);
            }
            return $this->redirect($response, '/entries/' . $entry->getId() . '/edit');
        } catch (BusinessRuleException $e) {
            ViewHelper::flash('error', $e->getMessage());
            return $this->redirect($response, '/entries/' . $entry->getId() . '/edit');
        }
    }

    /**
     * Eintrag löschen (Soft-Delete, nur Entwurf)
     */
    public function delete(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        if (!$entry->isEditable()) {
            ViewHelper::flash('error', 'Nur Entwürfe können gelöscht werden.');
            return $this->redirect($response, '/entries/' . $entry->getId());
        }

        if (!$this->isOwnerOrCreator($entry, $user)) {
            ViewHelper::flash('error', 'Keine Berechtigung.');
            return $this->redirect($response, '/entries');
        }

        $oldValues = $this->entryRepo->getRawById($entry->getId());
        $this->entryRepo->softDelete($entry->getId());

        $this->auditService->log(
            'delete',
            'work_entries',
            $entry->getId(),
            $oldValues,
            null,
            'Arbeitsstunden-Eintrag gelöscht',
            $entry->getEntryNumber()
        );

        ViewHelper::flash('success', 'Eintrag gelöscht.');
        return $this->redirect($response, '/entries');
    }

    // =========================================================================
    // Workflow-Aktionen (Eigentümer)
    // =========================================================================

    /**
     * Eintrag einreichen
     */
    public function submit(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        try {
            $this->workflowService->submit($entry, $user);
            ViewHelper::flash('success', 'Antrag eingereicht.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    /**
     * Eintrag zurückziehen
     */
    public function withdraw(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        try {
            $this->workflowService->withdraw($entry, $user);
            ViewHelper::flash('success', 'Antrag zurückgezogen.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    /**
     * Eintrag stornieren
     */
    public function cancel(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        try {
            $this->workflowService->cancel($entry, $user);
            ViewHelper::flash('success', 'Antrag storniert.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    /**
     * Stornierten Eintrag reaktivieren
     */
    public function reactivate(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        try {
            $this->workflowService->reactivate($entry, $user);
            ViewHelper::flash('success', 'Antrag reaktiviert.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    // =========================================================================
    // Dialog-Nachrichten
    // =========================================================================

    /**
     * Nachricht zum Dialog hinzufügen
     */
    public function addMessage(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/entries');
        }

        if (!$this->canViewEntry($entry, $user)) {
            ViewHelper::flash('error', 'Keine Berechtigung.');
            return $this->redirect($response, '/entries');
        }

        $data = (array) $request->getParsedBody();
        $message = trim($data['message'] ?? '');

        if ($message === '') {
            ViewHelper::flash('error', 'Bitte geben Sie eine Nachricht ein.');
            return $this->redirect($response, '/entries/' . $entry->getId());
        }

        $this->dialogRepo->create($entry->getId(), $user->getId(), $message);

        // Wenn Eigentümer antwortet, offene Fragen als beantwortet markieren
        if ($entry->getUserId() === $user->getId() || $entry->getCreatedByUserId() === $user->getId()) {
            $this->dialogRepo->markQuestionsAnswered($entry->getId());
        }

        $this->auditService->log(
            'dialog_message',
            'work_entry_dialogs',
            $entry->getId(),
            null,
            ['message' => $message],
            'Dialog-Nachricht hinzugefügt',
            $entry->getEntryNumber()
        );

        // E-Mail-Benachrichtigung an Gegenseite
        $this->notifyDialogRecipient($entry, $user);

        ViewHelper::flash('success', 'Nachricht gesendet.');
        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    // =========================================================================
    // Prüfer-Aktionen
    // =========================================================================

    /**
     * Liste der zu prüfenden Einträge
     */
    public function reviewList(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $params = $request->getQueryParams();

        $result = $this->entryRepo->findForReview(
            $user->getId(),
            (int) ($params['page'] ?? 1),
            20,
            $params['status'] ?? null,
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            isset($params['category_id']) ? (int) $params['category_id'] : null,
            $params['sort'] ?? 'submitted_at',
            $params['dir'] ?? 'ASC'
        );

        $categories = $this->categoryRepo->findAllActive();

        return $this->render($response, 'entries/review', [
            'title' => 'Anträge prüfen',
            'entries' => $result['entries'],
            'total' => $result['total'],
            'page' => (int) ($params['page'] ?? 1),
            'perPage' => 20,
            'categories' => $categories,
            'filters' => $params,
            'user' => $user,
        ]);
    }

    /**
     * Eintrag freigeben
     */
    public function approve(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/review');
        }

        try {
            $this->workflowService->approve($entry, $user);
            ViewHelper::flash('success', 'Antrag freigegeben.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    /**
     * Eintrag ablehnen
     */
    public function reject(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/review');
        }

        $data = (array) $request->getParsedBody();
        $reason = trim($data['reason'] ?? '');

        try {
            $this->workflowService->reject($entry, $user, $reason);
            ViewHelper::flash('success', 'Antrag abgelehnt.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    /**
     * Eintrag zur Klärung zurückgeben
     */
    public function returnForRevision(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/review');
        }

        $data = (array) $request->getParsedBody();
        $reason = trim($data['reason'] ?? '');

        try {
            $this->workflowService->returnForRevision($entry, $user, $reason);
            ViewHelper::flash('success', 'Antrag zur Klärung zurückgegeben.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    /**
     * Korrektur an freigegebenem Eintrag
     */
    public function correct(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $args = $this->routeArgs($request);
        $entry = $this->entryRepo->findById((int) $args['id']);

        if (!$entry) {
            ViewHelper::flash('error', 'Eintrag nicht gefunden.');
            return $this->redirect($response, '/review');
        }

        $data = (array) $request->getParsedBody();
        $newHours = (float) ($data['hours'] ?? 0);
        $reason = trim($data['reason'] ?? '');

        try {
            $this->workflowService->correct($entry, $user, $newHours, $reason);
            ViewHelper::flash('success', 'Korrektur durchgeführt.');
        } catch (BusinessRuleException | AuthorizationException $e) {
            ViewHelper::flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entry->getId());
    }

    // =========================================================================
    // Hilfsmethoden
    // =========================================================================

    /**
     * Aktuellen User aus Request-Attribut holen
     */
    private function getUser(Request $request): User
    {
        return $request->getAttribute('user');
    }

    /**
     * Validierung der Eintragsdaten (dynamisch basierend auf Pflichtfeld-Konfiguration)
     *
     * @throws ValidationException
     */
    private function validateEntry(array $data): array
    {
        $errors = [];
        $fieldConfig = $this->settingsService->getFieldConfig();

        // Datum - bei hidden: POST-Wert ignorieren (Sicherheit)
        $workDate = $fieldConfig['work_date'] === 'hidden' ? '' : trim($data['work_date'] ?? '');
        if ($fieldConfig['work_date'] !== 'hidden') {
            if ($workDate === '' && $fieldConfig['work_date'] === 'required') {
                $errors[] = 'Datum ist ein Pflichtfeld.';
            } elseif ($workDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
                $errors[] = 'Ungültiges Datumsformat.';
            }
        }

        // Stunden - bei hidden: POST-Wert ignorieren (Sicherheit)
        $hours = '';
        if ($fieldConfig['hours'] !== 'hidden') {
            $hours = trim($data['hours'] ?? '');
            $hours = str_replace(',', '.', $hours);
            if ($hours === '' && $fieldConfig['hours'] === 'required') {
                $errors[] = 'Stunden ist ein Pflichtfeld.';
            } elseif ($hours !== '' && (!is_numeric($hours) || (float) $hours <= 0)) {
                $errors[] = 'Stunden müssen eine positive Zahl sein.';
            } elseif ($hours !== '' && (float) $hours > 24) {
                $errors[] = 'Maximal 24 Stunden pro Eintrag.';
            }
        }

        // Kategorie - bei hidden: POST-Wert ignorieren (Sicherheit)
        $categoryId = $fieldConfig['category_id'] === 'hidden' ? '' : ($data['category_id'] ?? '');
        if ($fieldConfig['category_id'] !== 'hidden') {
            if (($categoryId === '' || $categoryId === null) && $fieldConfig['category_id'] === 'required') {
                $errors[] = 'Kategorie ist ein Pflichtfeld.';
            } elseif ($categoryId !== '' && $categoryId !== null) {
                $category = $this->categoryRepo->findById((int) $categoryId);
                if (!$category) {
                    $errors[] = 'Ungültige Kategorie.';
                }
            }
        }

        // Zeiten - bei hidden: POST-Wert ignorieren (Sicherheit)
        $timeFrom = $fieldConfig['time_from'] === 'hidden' ? '' : trim($data['time_from'] ?? '');
        $timeTo = $fieldConfig['time_to'] === 'hidden' ? '' : trim($data['time_to'] ?? '');
        if ($fieldConfig['time_from'] !== 'hidden' && $fieldConfig['time_from'] === 'required' && $timeFrom === '') {
            $errors[] = 'Uhrzeit von ist ein Pflichtfeld.';
        }
        if ($fieldConfig['time_to'] !== 'hidden' && $fieldConfig['time_to'] === 'required' && $timeTo === '') {
            $errors[] = 'Uhrzeit bis ist ein Pflichtfeld.';
        }
        // Wenn beide Felder sichtbar: Konsistenzprüfung
        if ($fieldConfig['time_from'] !== 'hidden' && $fieldConfig['time_to'] !== 'hidden') {
            if (($timeFrom !== '' && $timeTo === '') || ($timeFrom === '' && $timeTo !== '')) {
                $errors[] = 'Wenn eine Uhrzeit angegeben wird, müssen beide Zeiten (Von und Bis) angegeben werden.';
            }
        }

        // Projekt - bei hidden: POST-Wert ignorieren (Sicherheit)
        $project = $fieldConfig['project'] === 'hidden' ? '' : trim($data['project'] ?? '');
        if ($fieldConfig['project'] === 'required' && $project === '') {
            $errors[] = 'Projekt ist ein Pflichtfeld.';
        }

        // Beschreibung - bei hidden: POST-Wert ignorieren (Sicherheit)
        $description = $fieldConfig['description'] === 'hidden' ? '' : trim($data['description'] ?? '');
        if ($fieldConfig['description'] === 'required' && $description === '') {
            $errors[] = 'Beschreibung ist ein Pflichtfeld.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return [
            'work_date' => $workDate !== '' ? $workDate : null,
            'hours' => $hours !== '' ? (float) $hours : 0.0,
            'category_id' => $categoryId !== '' && $categoryId !== null ? (int) $categoryId : null,
            'time_from' => $timeFrom !== '' ? $timeFrom : null,
            'time_to' => $timeTo !== '' ? $timeTo : null,
            'project' => $project !== '' ? $project : null,
            'description' => $description !== '' ? $description : null,
        ];
    }

    /**
     * Prüft ob der User den Eintrag sehen darf
     */
    private function canViewEntry(WorkEntry $entry, User $user): bool
    {
        // Eigentümer oder Ersteller
        if ($entry->getUserId() === $user->getId() || $entry->getCreatedByUserId() === $user->getId()) {
            return true;
        }

        // Prüfer (eingereichte/in_klaerung Einträge)
        if ($user->hasRole('pruefer') || $user->hasRole('administrator')) {
            return true;
        }

        // Auditor (Lesezugriff auf alles)
        if ($user->hasRole('auditor')) {
            return true;
        }

        return false;
    }

    /**
     * Prüft ob der User Eigentümer oder Ersteller ist
     */
    private function isOwnerOrCreator(WorkEntry $entry, User $user): bool
    {
        return $entry->getUserId() === $user->getId()
            || $entry->getCreatedByUserId() === $user->getId();
    }

    /**
     * Dialog-Nachricht E-Mail an Gegenseite senden
     */
    private function notifyDialogRecipient(WorkEntry $entry, User $sender): void
    {
        try {
            $entryUrl = rtrim($this->settings['app']['url'] ?? '', '/') . '/entries/' . $entry->getId();
            $senderName = $sender->getVollname();
            $isOwnerOrCreator = ($entry->getUserId() === $sender->getId()
                || $entry->getCreatedByUserId() === $sender->getId());

            if ($isOwnerOrCreator) {
                // Absender = Owner/Creator → E-Mail an Prüfer
                if ($entry->getReviewedByUserId() !== null) {
                    $reviewer = $this->userRepo->findById($entry->getReviewedByUserId());
                    if ($reviewer !== null) {
                        $this->emailService->sendDialogMessage(
                            $reviewer->getEmail(),
                            $reviewer->getVorname(),
                            $entry->getEntryNumber(),
                            $senderName,
                            $entryUrl
                        );
                    }
                } else {
                    // Kein Prüfer zugewiesen → an alle Prüfer
                    $pruefer = $this->userRepo->findByRole('pruefer');
                    foreach ($pruefer as $p) {
                        if ($p->getId() !== $sender->getId()) {
                            try {
                                $this->emailService->sendDialogMessage(
                                    $p->getEmail(),
                                    $p->getVorname(),
                                    $entry->getEntryNumber(),
                                    $senderName,
                                    $entryUrl
                                );
                            } catch (\Throwable $e) {
                                $this->logger->warning('Dialog-E-Mail an Prüfer fehlgeschlagen: ' . $e->getMessage());
                            }
                        }
                    }
                }
            } else {
                // Absender = Prüfer → E-Mail an Owner
                $owner = $this->userRepo->findById($entry->getUserId());
                if ($owner !== null) {
                    $this->emailService->sendDialogMessage(
                        $owner->getEmail(),
                        $owner->getVorname(),
                        $entry->getEntryNumber(),
                        $senderName,
                        $entryUrl
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Dialog-E-Mail-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
