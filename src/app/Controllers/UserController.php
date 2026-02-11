<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\SecurityHelper;
use App\Helpers\ViewHelper;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\EmailService;
use App\Services\ImportService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für Benutzerverwaltung (Admin)
 */
class UserController extends BaseController
{
    public function __construct(
        private UserRepository $userRepo,
        private ImportService $importService,
        private EmailService $emailService,
        private AuditService $auditService,
        private SettingsService $settingsService,
        private array $settings
    ) {
    }

    /**
     * Mitglieder-Liste (GET /admin/users)
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 20;
        $search = $params['search'] ?? null;
        $role = $params['role'] ?? null;
        $includeInactive = isset($params['inactive']) && $params['inactive'] === '1';

        $result = $this->userRepo->findAllPaginated($page, $perPage, $search, $role, $includeInactive);
        $roles = $this->userRepo->findAllRoles();

        return $this->render($response, 'admin/users/index', [
            'title' => 'Mitglieder verwalten',
            'user' => $user,
            'settings' => $this->settings,
            'users' => $result['users'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'roles' => $roles,
            'filters' => [
                'search' => $search,
                'role' => $role,
                'inactive' => $includeInactive,
            ],
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Mitglieder'],
            ],
        ]);
    }

    /**
     * Benutzer-Detail (GET /admin/users/{id})
     */
    public function show(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];

        $targetUser = $this->userRepo->findByIdForAdmin($id);
        if ($targetUser === null) {
            ViewHelper::flash('danger', 'Benutzer nicht gefunden.');
            return $this->redirect($response, '/admin/users');
        }

        $roles = $this->userRepo->findAllRoles();
        $invitation = $this->userRepo->getLatestInvitation($id);

        return $this->render($response, 'admin/users/show', [
            'title' => 'Benutzer: ' . $targetUser->getVollname(),
            'user' => $currentUser,
            'settings' => $this->settings,
            'targetUser' => $targetUser,
            'allRoles' => $roles,
            'invitation' => $invitation,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Mitglieder', 'url' => '/admin/users'],
                ['label' => $targetUser->getVollname()],
            ],
        ]);
    }

    /**
     * CSV-Import-Formular (GET /admin/users/import)
     */
    public function showImport(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        return $this->render($response, 'admin/users/import', [
            'title' => 'CSV-Import',
            'user' => $user,
            'settings' => $this->settings,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Mitglieder', 'url' => '/admin/users'],
                ['label' => 'CSV-Import'],
            ],
        ]);
    }

    /**
     * CSV-Import ausführen (POST /admin/users/import)
     */
    public function import(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $uploadedFiles = $request->getUploadedFiles();

        $csvFile = $uploadedFiles['csv_file'] ?? null;
        if ($csvFile === null || $csvFile->getError() !== UPLOAD_ERR_OK) {
            ViewHelper::flash('danger', 'Bitte wählen Sie eine gültige CSV-Datei aus.');
            return $this->redirect($response, '/admin/users/import');
        }

        $csvContent = (string) $csvFile->getStream();
        if (empty(trim($csvContent))) {
            ViewHelper::flash('danger', 'Die CSV-Datei ist leer.');
            return $this->redirect($response, '/admin/users/import');
        }

        $result = $this->importService->importCsv($csvContent, $user->getId());

        // Ergebnis in Session speichern für Anzeige
        $_SESSION['import_result'] = $result;

        return $this->redirect($response, '/admin/users/import-result');
    }

    /**
     * Import-Ergebnis anzeigen (GET /admin/users/import-result)
     */
    public function importResult(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $result = $_SESSION['import_result'] ?? null;
        unset($_SESSION['import_result']);

        if ($result === null) {
            return $this->redirect($response, '/admin/users/import');
        }

        return $this->render($response, 'admin/users/import-result', [
            'title' => 'Import-Ergebnis',
            'user' => $user,
            'settings' => $this->settings,
            'result' => $result,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Mitglieder', 'url' => '/admin/users'],
                ['label' => 'Import-Ergebnis'],
            ],
        ]);
    }

    /**
     * Rollen aktualisieren (POST /admin/users/{id}/roles)
     */
    public function updateRoles(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $targetUser = $this->userRepo->findByIdForAdmin($id);
        if ($targetUser === null) {
            ViewHelper::flash('danger', 'Benutzer nicht gefunden.');
            return $this->redirect($response, '/admin/users');
        }

        $oldRoles = $targetUser->getRoles();
        $newRoleIds = array_map('intval', $data['roles'] ?? []);

        $this->userRepo->replaceRoles($id, $newRoleIds, $currentUser->getId());

        // Neue Rollen-Namen für Audit laden
        $newRoleNames = $this->userRepo->getUserRoleNames($id);

        $this->auditService->log(
            'update',
            'user_roles',
            $id,
            oldValues: ['roles' => $oldRoles],
            newValues: ['roles' => $newRoleNames],
            description: "Rollen aktualisiert für {$targetUser->getVollname()}"
        );

        ViewHelper::flash('success', 'Rollen wurden aktualisiert.');
        return $this->redirect($response, '/admin/users/' . $id);
    }

    /**
     * Neue Einladung senden (POST /admin/users/{id}/reinvite)
     */
    public function reinvite(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];

        $targetUser = $this->userRepo->findByIdForAdmin($id);
        if ($targetUser === null) {
            ViewHelper::flash('danger', 'Benutzer nicht gefunden.');
            return $this->redirect($response, '/admin/users');
        }

        $token = SecurityHelper::generateToken();
        $expiryDays = $this->settingsService->getInvitationExpiryDays();
        $invitationId = $this->userRepo->createInvitation($id, $token, $expiryDays, $currentUser->getId());

        try {
            $baseUrl = rtrim($this->settings['app']['url'] ?? '', '/');
            $this->emailService->sendInvitation(
                $targetUser->getEmail(),
                $targetUser->getVorname(),
                $baseUrl . '/setup-password/' . $token
            );
            $this->userRepo->markInvitationSent($invitationId);
            ViewHelper::flash('success', 'Einladung wurde erneut gesendet.');
        } catch (\Throwable $e) {
            ViewHelper::flash('danger', 'Einladung konnte nicht gesendet werden: ' . $e->getMessage());
        }

        $this->auditService->log(
            'create',
            'user_invitations',
            $invitationId,
            description: "Neue Einladung für {$targetUser->getVollname()}"
        );

        return $this->redirect($response, '/admin/users/' . $id);
    }

    /**
     * Benutzer deaktivieren (POST /admin/users/{id}/deactivate)
     */
    public function deactivate(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];

        if ($id === $currentUser->getId()) {
            ViewHelper::flash('danger', 'Sie können sich nicht selbst deaktivieren.');
            return $this->redirect($response, '/admin/users/' . $id);
        }

        $targetUser = $this->userRepo->findByIdForAdmin($id);
        if ($targetUser === null) {
            ViewHelper::flash('danger', 'Benutzer nicht gefunden.');
            return $this->redirect($response, '/admin/users');
        }

        $this->userRepo->softDeleteUser($id);

        $this->auditService->log(
            'delete',
            'users',
            $id,
            description: "Benutzer deaktiviert: {$targetUser->getVollname()}"
        );

        ViewHelper::flash('success', 'Benutzer wurde deaktiviert.');
        return $this->redirect($response, '/admin/users');
    }

    /**
     * Benutzer reaktivieren (POST /admin/users/{id}/activate)
     */
    public function activate(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];

        $targetUser = $this->userRepo->findByIdForAdmin($id);
        if ($targetUser === null) {
            ViewHelper::flash('danger', 'Benutzer nicht gefunden.');
            return $this->redirect($response, '/admin/users');
        }

        $this->userRepo->restoreUser($id);

        $this->auditService->log(
            'restore',
            'users',
            $id,
            description: "Benutzer reaktiviert: {$targetUser->getVollname()}"
        );

        ViewHelper::flash('success', 'Benutzer wurde reaktiviert.');
        return $this->redirect($response, '/admin/users');
    }

    /**
     * Formular: Neues Mitglied anlegen (GET /admin/users/create)
     */
    public function showCreate(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $roles = $this->userRepo->findAllRoles();

        return $this->render($response, 'admin/users/create', [
            'title' => 'Neues Mitglied anlegen',
            'user' => $user,
            'settings' => $this->settings,
            'allRoles' => $roles,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Mitglieder', 'url' => '/admin/users'],
                ['label' => 'Neues Mitglied'],
            ],
        ]);
    }

    /**
     * Neues Mitglied speichern (POST /admin/users/create)
     */
    public function storeUser(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        $mitgliedsnummer = trim($data['mitgliedsnummer'] ?? '');
        $email = trim($data['email'] ?? '');
        $vorname = trim($data['vorname'] ?? '');
        $nachname = trim($data['nachname'] ?? '');
        $strasse = trim($data['strasse'] ?? '');
        $plz = trim($data['plz'] ?? '');
        $ort = trim($data['ort'] ?? '');
        $telefon = trim($data['telefon'] ?? '');
        $eintrittsdatum = trim($data['eintrittsdatum'] ?? '');
        $roleIds = array_map('intval', $data['roles'] ?? []);

        // Validierung
        $errors = [];

        if ($mitgliedsnummer === '') {
            $errors[] = 'Mitgliedsnummer ist ein Pflichtfeld.';
        } elseif (mb_strlen($mitgliedsnummer) > 50) {
            $errors[] = 'Mitgliedsnummer darf maximal 50 Zeichen lang sein.';
        }

        if ($vorname === '') {
            $errors[] = 'Vorname ist ein Pflichtfeld.';
        } elseif (mb_strlen($vorname) > 100) {
            $errors[] = 'Vorname darf maximal 100 Zeichen lang sein.';
        }

        if ($nachname === '') {
            $errors[] = 'Nachname ist ein Pflichtfeld.';
        } elseif (mb_strlen($nachname) > 100) {
            $errors[] = 'Nachname darf maximal 100 Zeichen lang sein.';
        }

        if ($email === '') {
            $errors[] = 'E-Mail ist ein Pflichtfeld.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        } elseif (mb_strlen($email) > 255) {
            $errors[] = 'E-Mail darf maximal 255 Zeichen lang sein.';
        }

        if ($eintrittsdatum !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eintrittsdatum)) {
            $errors[] = 'Eintrittsdatum muss im Format JJJJ-MM-TT sein.';
        }

        // Duplikatpruefung
        if ($mitgliedsnummer !== '' && $this->userRepo->findByMitgliedsnummerIncludeDeleted($mitgliedsnummer) !== null) {
            $errors[] = 'Ein Mitglied mit dieser Mitgliedsnummer existiert bereits.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->userRepo->findByEmailIncludeDeleted($email) !== null) {
            $errors[] = 'Ein Mitglied mit dieser E-Mail-Adresse existiert bereits.';
        }

        if (!empty($errors)) {
            ViewHelper::flashOldInput($data);
            foreach ($errors as $error) {
                ViewHelper::flash('danger', $error);
            }
            return $this->redirect($response, '/admin/users/create');
        }

        // Benutzer erstellen
        $userData = [
            'mitgliedsnummer' => $mitgliedsnummer,
            'email' => $email,
            'vorname' => $vorname,
            'nachname' => $nachname,
            'strasse' => $strasse !== '' ? $strasse : null,
            'plz' => $plz !== '' ? $plz : null,
            'ort' => $ort !== '' ? $ort : null,
            'telefon' => $telefon !== '' ? $telefon : null,
            'eintrittsdatum' => $eintrittsdatum !== '' ? $eintrittsdatum : null,
        ];

        $userId = $this->userRepo->createUser($userData);

        // Rollen zuweisen
        if (!empty($roleIds)) {
            $this->userRepo->replaceRoles($userId, $roleIds, $currentUser->getId());
        }

        // Einladung erstellen und senden
        $token = SecurityHelper::generateToken();
        $expiryDays = $this->settingsService->getInvitationExpiryDays();
        $invitationId = $this->userRepo->createInvitation($userId, $token, $expiryDays, $currentUser->getId());

        try {
            $baseUrl = rtrim($this->settings['app']['url'] ?? '', '/');
            $this->emailService->sendInvitation(
                $email,
                $vorname,
                $baseUrl . '/setup-password/' . $token
            );
            $this->userRepo->markInvitationSent($invitationId);
        } catch (\Throwable $e) {
            ViewHelper::flash('warning', 'Mitglied wurde angelegt, aber die Einladungs-E-Mail konnte nicht gesendet werden: ' . $e->getMessage());
        }

        $assignedRoleNames = !empty($roleIds) ? $this->userRepo->getUserRoleNames($userId) : [];

        $this->auditService->log(
            'create',
            'users',
            $userId,
            newValues: array_merge($userData, ['roles' => $assignedRoleNames]),
            description: "Manuell angelegt: {$vorname} {$nachname} ({$mitgliedsnummer})"
        );

        ViewHelper::flash('success', "Mitglied {$vorname} {$nachname} wurde erfolgreich angelegt.");
        return $this->redirect($response, '/admin/users/' . $userId);
    }
}
