import { test, expect, Browser, BrowserContext, Page } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { AdminEventTreePage } from '../pages/AdminEventTreePage';
import { EVENT_ADMIN } from '../fixtures/users';
import { setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7e-B.1 Phase 3 Teil 2 — Ende-zu-Ende-Beweis fuer den
 * Optimistic Lock auf event_tasks.
 *
 * Zwei BrowserContext-Instanzen = zwei unabhaengige Sessions,
 * beide als EVENT_ADMIN eingeloggt. Muster analog zu
 * specs/08-multitab.spec.ts Test 4 (Optimistic Lock auf Event-
 * Metadaten in Modul 7 I3). Unterschied hier: die Konflikt-UX
 * ist NICHT das Diff-Panel, sondern ein Warn-Toast + Seiten-
 * Reload (G1-B3).
 *
 * Enthaltene Tests:
 *   1. Update-Konflikt — zwei Admins editieren denselben Task,
 *      zweiter bekommt 409 mit Warn-Toast und sieht nach Reload
 *      den DB-Stand von A.
 *   2. Konflikt-freies Paralleles — zwei Admins editieren
 *      verschiedene Tasks, beide Aenderungen landen in der DB.
 *   3. Delete-nach-Update-Konflikt — Admin 1 updated Task A,
 *      Admin 2 versucht mit alter version Task A zu loeschen
 *      und bekommt 409.
 *
 * Scope-Disziplin: wir testen nur Desktop-Viewport. Das
 * Konflikt-Szenario ist ein Modal-Flow (Update) bzw. ein
 * Button-Click mit window.confirm (Delete) — in beiden Faellen
 * ist das mobile Offcanvas nicht relevant. Move-Konflikt (Drag-
 * and-Drop) wird ausgelassen, weil SortableJS-Mouse-Simulation
 * analog Spec 10 flaky ist; die JS-seitige Lock-Weitergabe im
 * Move-Handler ist statisch durch die PHPUnit-Invariants
 * abgedeckt (js_move_convert_delete_send_version +
 * js_handle_lock_conflict_called_by_all_four_mutation_handlers).
 *
 * Serial: die drei Tests teilen sich ein Setup-Event mit zwei
 * Tasks; der Setup-Test legt sie an.
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

async function loginNewContext(
  browser: Browser,
  user: Parameters<LoginPage['loginAs']>[0],
): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await new LoginPage(page).loginAs(user);
  return { context, page };
}

test.describe('Optimistic Lock auf event_tasks (I7e-B.1)', () => {
  const now     = new Date();
  const eventStart = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
  const eventEnd   = new Date(eventStart.getTime() + 6 * 60 * 60 * 1000);
  const startAt = toLocalDateTime(eventStart);
  const endAt   = toLocalDateTime(eventEnd);

  const eventTitle = `E2E Lock ${now.getTime()}`;
  // Die zwei Task-Titel sind lang-eindeutig, damit nodeByTitle
  // nicht mit Substring-Collision (hasText-Pattern) die falsche
  // Zeile trifft.
  const taskATitle = 'E2E-Lock-Konflikt-Task-A';
  const taskBTitle = 'E2E-Lock-Konflikt-Task-B';

  let eventId = 0;

  test.beforeAll(() => {
    setE2eSetting('events.tree_editor_enabled', '1');
  });

  test.afterAll(() => {
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  // =========================================================================
  // Setup
  // =========================================================================

  test('Setup: Event mit zwei variabel-Slot-Tasks', async ({ page }) => {
    const login  = new LoginPage(page);
    const events = new AdminEventsPage(page);
    const tree   = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    eventId = await events.createEvent({
      title: eventTitle,
      description: 'I7e-B.1 Phase 3 Teil 2 Setup-Event',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);

    await tree.gotoEdit(eventId);

    // Zwei unabhaengige Top-Level-Leafs, beide variabel/unbegrenzt —
    // so entstehen keine Slot-Konflikte oder Kapazitaetsregeln, die
    // die Konflikt-Logik maskieren wuerden.
    await tree.createTopLevelNode({
      isGroup: false,
      title: taskATitle,
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
    });
    await tree.createTopLevelNode({
      isGroup: false,
      title: taskBTitle,
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
    });
  });

  // =========================================================================
  // Test 1 — Update-Konflikt
  // =========================================================================

  test('Zwei Admins editieren denselben Task: zweiter bekommt 409 + Warn-Toast', async ({ browser }) => {
    const a = await loginNewContext(browser, EVENT_ADMIN);
    const b = await loginNewContext(browser, EVENT_ADMIN);

    try {
      const treeA = new AdminEventTreePage(a.page);
      const treeB = new AdminEventTreePage(b.page);
      await treeA.gotoEdit(eventId);
      await treeB.gotoEdit(eventId);

      // Beide oeffnen den Edit-Modal fuer denselben Task. Die JS-Seite
      // fetcht task.version=1 in beide Modals.
      await treeA.openEditModal(taskATitle);
      await treeB.openEditModal(taskATitle);

      // A aendert den Titel und speichert. Bei Erfolg macht das JS
      // window.location.reload(); wir warten auf den neuen Tree.
      const titleA = `${taskATitle}-AendA`;
      await a.page.locator('#task-edit-modal input[name="title"]').fill(titleA);
      await a.page.locator('#task-edit-modal-save').click();
      await expect(treeA.nodeByTitle(titleA)).toBeVisible({ timeout: 15_000 });

      // B aendert mit der veralteten version=1 und versucht zu speichern.
      const titleB = `${taskATitle}-AendB`;
      await b.page.locator('#task-edit-modal input[name="title"]').fill(titleB);
      await b.page.locator('#task-edit-modal-save').click();

      // Erwartung B: Warn-Toast mit optimistic-lock-conflict-Text. Die
      // Bootstrap-5-Klasse `.text-bg-warning` stammt aus showToast(msg,
      // 'warning').
      const warnToast = b.page.locator(
        '.toast.text-bg-warning',
        { hasText: /zwischenzeitlich.*geaendert/i }
      );
      await expect(warnToast).toBeVisible({ timeout: 5000 });

      // Nach handleLockConflict schedulet das JS einen window.location
      // .reload() mit 1.5 s Verzoegerung. Wir warten stattdessen auf
      // ein DOM-Signal: A's neuer Titel ist im Tree sichtbar, B's
      // Versuch NICHT.
      await expect(treeB.nodeByTitle(titleA)).toBeVisible({ timeout: 15_000 });
      await expect(treeB.nodeByTitle(titleB)).toHaveCount(0);

      // DB-Final-Check: ein frischer Reload in A zeigt ebenfalls den
      // finalen Zustand (titleA, nicht titleB).
      await a.page.reload();
      await expect(treeA.nodeByTitle(titleA)).toBeVisible();
      await expect(treeA.nodeByTitle(titleB)).toHaveCount(0);
    } finally {
      await a.context.close();
      await b.context.close();
    }
  });

  // =========================================================================
  // Test 2 — Konflikt-freies Paralleles
  // =========================================================================

  test('Zwei Admins editieren verschiedene Tasks: beide gespeichert', async ({ browser }) => {
    const a = await loginNewContext(browser, EVENT_ADMIN);
    const b = await loginNewContext(browser, EVENT_ADMIN);

    try {
      const treeA = new AdminEventTreePage(a.page);
      const treeB = new AdminEventTreePage(b.page);
      await treeA.gotoEdit(eventId);
      await treeB.gotoEdit(eventId);

      // Start-Zustand nach Test 1: taskATitle ist inzwischen umbenannt
      // zu `${taskATitle}-AendA`. Wir arbeiten hier mit frisch
      // ermittelten aktuellen Titeln.
      const titleAOld = `${taskATitle}-AendA`;
      const titleBOld = taskBTitle;
      await expect(treeA.nodeByTitle(titleAOld)).toBeVisible();
      await expect(treeA.nodeByTitle(titleBOld)).toBeVisible();

      // A editiert Task A.
      await treeA.openEditModal(titleAOld);
      const titleANext = `${taskATitle}-FreiA`;
      await a.page.locator('#task-edit-modal input[name="title"]').fill(titleANext);

      // B editiert Task B parallel.
      await treeB.openEditModal(titleBOld);
      const titleBNext = `${taskBTitle}-FreiB`;
      await b.page.locator('#task-edit-modal input[name="title"]').fill(titleBNext);

      // Beide speichern. Keine Version-Kollision, weil verschiedene Tasks.
      await a.page.locator('#task-edit-modal-save').click();
      await expect(treeA.nodeByTitle(titleANext)).toBeVisible({ timeout: 15_000 });

      await b.page.locator('#task-edit-modal-save').click();
      await expect(treeB.nodeByTitle(titleBNext)).toBeVisible({ timeout: 15_000 });

      // Beide Reloads: A und B sehen beide Aenderungen.
      await a.page.reload();
      await b.page.reload();
      await expect(treeA.nodeByTitle(titleANext)).toBeVisible();
      await expect(treeA.nodeByTitle(titleBNext)).toBeVisible();
      await expect(treeB.nodeByTitle(titleANext)).toBeVisible();
      await expect(treeB.nodeByTitle(titleBNext)).toBeVisible();
    } finally {
      await a.context.close();
      await b.context.close();
    }
  });

  // =========================================================================
  // Test 3 — Delete-nach-Update-Konflikt (anderer Mutations-Pfad)
  // =========================================================================

  test('Admin 2 will Task loeschen, Admin 1 hat ihn vorher geupdated: 409', async ({ browser }) => {
    const a = await loginNewContext(browser, EVENT_ADMIN);
    const b = await loginNewContext(browser, EVENT_ADMIN);

    try {
      const treeA = new AdminEventTreePage(a.page);
      const treeB = new AdminEventTreePage(b.page);
      await treeA.gotoEdit(eventId);
      await treeB.gotoEdit(eventId);

      // Aktueller Titel von Task A nach Test 2.
      const titleACurrent = `${taskATitle}-FreiA`;
      await expect(treeA.nodeByTitle(titleACurrent)).toBeVisible();
      await expect(treeB.nodeByTitle(titleACurrent)).toBeVisible();

      // A updated Task A → version inkrementiert in der DB, A's Seite
      // reloaded mit neuer version im data-task-version-Attribut.
      // B's Seite aendert sich nicht — B behaelt die alte version im DOM.
      await treeA.openEditModal(titleACurrent);
      const titleAAfterUpdate = `${taskATitle}-VerUp`;
      await a.page.locator('#task-edit-modal input[name="title"]').fill(titleAAfterUpdate);
      await a.page.locator('#task-edit-modal-save').click();
      await expect(treeA.nodeByTitle(titleAAfterUpdate)).toBeVisible({ timeout: 15_000 });

      // B versucht jetzt via Tree-Button den Task zu loeschen. Der JS-
      // handleDelete liest data-task-version aus B's DOM (alte Version)
      // und sendet es an den Server — 409.
      b.page.once('dialog', (dialog) => { void dialog.accept(); });
      await treeB
        .nodeByTitle(titleACurrent)
        .locator('[data-action="delete"]')
        .first()
        .click();

      // Erwartung B: Warn-Toast wie in Test 1.
      const warnToast = b.page.locator(
        '.toast.text-bg-warning',
        { hasText: /zwischenzeitlich.*geaendert/i }
      );
      await expect(warnToast).toBeVisible({ timeout: 5000 });

      // Nach Reload: Task existiert weiter (nicht geloescht), mit
      // A's neuem Titel.
      await expect(treeB.nodeByTitle(titleAAfterUpdate)).toBeVisible({ timeout: 15_000 });

      // DB-Final-Check.
      await a.page.reload();
      await expect(treeA.nodeByTitle(titleAAfterUpdate)).toBeVisible();
    } finally {
      await a.context.close();
      await b.context.close();
    }
  });

  // =========================================================================
  // Test 4 — Organizer-Pfad (Follow-up q aus Sanity-Gate)
  //
  // Die ersten drei Tests fuhren ueber /admin/events/{id}/edit — Lock-
  // Verkabelung im EventAdminController. Dieser Test dupliziert das
  // Update-Konflikt-Szenario ueber /organizer/events/{id}/editor, damit
  // der OrganizerEventEditController-Pfad end-zu-end abgedeckt ist.
  //
  // Zwei EVENT_ADMIN-Kontexte reichen: EVENT_ADMIN ist durch den Setup-
  // Test als Organisator des Events eingetragen, und die Lock-Semantik
  // ist user-unabhaengig (Konflikt entsteht durch zwei parallele
  // Sessions, nicht durch zwei verschiedene User). Der Organizer-
  // Controller prueft isOrganizer, und beide Kontexte bestehen diese
  // Pruefung.
  // =========================================================================

  test('Organizer-Route: zwei Kontexte editieren denselben Task, zweiter bekommt 409 + Warn-Toast', async ({ browser }) => {
    const a = await loginNewContext(browser, EVENT_ADMIN);
    const b = await loginNewContext(browser, EVENT_ADMIN);

    try {
      const treeA = new AdminEventTreePage(a.page);
      const treeB = new AdminEventTreePage(b.page);

      // Direkt auf den Organizer-Editor. DOM (Tree-Widget, Edit-Modal)
      // ist mit dem Admin-Editor identisch, nur die data-endpoint-*-
      // URLs zeigen auf /organizer/events/... — AdminEventTreePage-
      // Selektoren greifen damit unveraendert.
      await a.page.goto(`/organizer/events/${eventId}/editor`);
      await b.page.goto(`/organizer/events/${eventId}/editor`);
      await expect(a.page.locator('#task-tree-editor')).toBeVisible();
      await expect(b.page.locator('#task-tree-editor')).toBeVisible();

      // Aktueller Titel von Task A nach Test 3.
      const titleACurrent = `${taskATitle}-VerUp`;
      await expect(treeA.nodeByTitle(titleACurrent)).toBeVisible();
      await expect(treeB.nodeByTitle(titleACurrent)).toBeVisible();

      // Beide oeffnen den Edit-Modal fuer denselben Task. Die JS-Seite
      // fetcht task.version=n in beide Modals.
      await treeA.openEditModal(titleACurrent);
      await treeB.openEditModal(titleACurrent);

      // A aendert den Titel und speichert. Request geht ueber
      // /organizer/events/{id}/tasks/{taskId} -- also durch den
      // OrganizerEventEditController::updateTaskNode.
      const titleA = `${taskATitle}-OrgA`;
      await a.page.locator('#task-edit-modal input[name="title"]').fill(titleA);
      await a.page.locator('#task-edit-modal-save').click();
      await expect(treeA.nodeByTitle(titleA)).toBeVisible({ timeout: 15_000 });

      // B aendert mit veralteter Version und versucht zu speichern.
      const titleB = `${taskATitle}-OrgB`;
      await b.page.locator('#task-edit-modal input[name="title"]').fill(titleB);
      await b.page.locator('#task-edit-modal-save').click();

      // Warn-Toast wie in Test 1, aber ausgeloest vom Organizer-
      // Controller-Pfad.
      const warnToast = b.page.locator(
        '.toast.text-bg-warning',
        { hasText: /zwischenzeitlich.*geaendert/i }
      );
      await expect(warnToast).toBeVisible({ timeout: 5000 });

      // Nach handleLockConflict reloadet B die Seite. Im Tree steht
      // A's neuer Titel, B's Versuch ist nicht persistiert.
      await expect(treeB.nodeByTitle(titleA)).toBeVisible({ timeout: 15_000 });
      await expect(treeB.nodeByTitle(titleB)).toHaveCount(0);
    } finally {
      await a.context.close();
      await b.context.close();
    }
  });
});
