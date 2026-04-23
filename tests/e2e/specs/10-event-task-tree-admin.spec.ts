import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { AdminEventTreePage } from '../pages/AdminEventTreePage';
import { EVENT_ADMIN } from '../fixtures/users';
import { setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7b1 Phase 4 — Playwright-E2E fuer den Aufgabenbaum-Editor (Admin).
 *
 * Deckt die Flows ab, die in Phase 3 als Runtime-Bugs erschienen sind:
 *   - Aggregator-Schnittstelle (Array vs. Objekt)     → Fix-Commit c5f78a2
 *   - parent_task_id-Typ-Cast                         → Fix-Commit e142d9d
 *   - leere Shape-Felder zu null                      → Fix-Commit d7ff41c
 *   - SortableJS-Nested-Config                        → Fix-Commit 1a530a6
 * plus die funktionalen Happy-Pfade (Create/Convert/Delete/Edit/Validation/
 * Flag-Toggle).
 *
 * Drag-and-Drop ist bewusst NICHT Teil dieser Spec — SortableJS-Drag laesst
 * sich in Playwright via mouse.down/move/up ausloesen, ist aber nach
 * Erfahrung aus vergleichbaren Setups flaky. Statische Invariants-Tests
 * und manueller Browser-Smoke decken das Drag-Verhalten ab; ein FeatureTest
 * mit echter DB-Persistenz (Follow-up-Ticket a) faengt die Server-Seite.
 *
 * Serial: alle Tests bauen auf demselben Event auf, eventId wird per
 * let-Variable geteilt (Pattern wie 04-event-workflow.spec.ts).
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

test.describe('Aufgabenbaum-Editor — Admin-Flows (I7b1)', () => {
  const now = new Date();
  // Event-Fenster 7 Tage in der Zukunft, 3h Dauer.
  const startDate = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
  const endDate   = new Date(startDate.getTime() + 3 * 60 * 60 * 1000);
  const startAt   = toLocalDateTime(startDate);
  const endAt     = toLocalDateTime(endDate);
  // Fix-Slot-Zeit innerhalb des Event-Fensters.
  const slotStart = toLocalDateTime(new Date(startDate.getTime() + 30 * 60 * 1000));
  const slotEnd   = toLocalDateTime(new Date(startDate.getTime() + 90 * 60 * 1000));

  const eventTitle = `E2E Tree ${now.getTime()}`;

  let eventId = 0;

  test.beforeAll(() => {
    setE2eSetting('events.tree_editor_enabled', '1');
  });

  test.afterAll(() => {
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  test('Event-Admin legt Event an', async ({ page }) => {
    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);
    eventId = await events.createEvent({
      title: eventTitle,
      description: 'Tree-Editor E2E',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);
  });

  test('Tree-Editor-Widget rendert bei Flag=1', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);
    await tree.expectEditorVisible();
  });

  test('Top-Level-Gruppe anlegen (Happy-Path)', async ({ page }) => {
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await tree.createTopLevelNode({
      isGroup: true,
      title: 'Thekendienst',
      description: 'Alle Dienste rund um die Theke',
    });
    await tree.expectNodeIsGroup('Thekendienst');
  });

  test('Kind-Aufgabe unter Gruppe anlegen (normalizeTreeFormInputs-Regression)', async ({ page }) => {
    // Regressions-Schutz fuer Fix-Commit e142d9d: parent_task_id kommt als
    // String aus dem Hidden-Input, der Service verlangt ?int.
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await tree.createChildUnder('Thekendienst', {
      isGroup: false,
      title: 'Essensausgabe',
      description: 'Helfer fuer die Essensausgabe',
      slotMode: 'fix',
      startAt: slotStart,
      endAt: slotEnd,
      capacityMode: 'ziel',
      capacityTarget: 2,
      hoursDefault: 1.5,
    });
    await tree.expectNodeIsLeaf('Essensausgabe');
  });

  test('Leaf mit fix-Slot ohne Zeiten zeigt lesbare Validation (nicht HTTP 500)', async ({ page }) => {
    // Regressions-Schutz fuer Fix-Commit d7ff41c: leere Start/Ende-Felder
    // durchlaufen den Service-Null-Check und wurden als ungueltige DATETIME
    // zur DB geschickt. Jetzt: normalizeTreeFormInputs macht "" → null,
    // der Service wirft ValidationException, JS zeigt sie im Toast/Form-Error.
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await page.locator('#task-tree-editor [data-action="add-child"]').first().click();
    const modal = page.locator('#task-edit-modal');
    await expect(modal).toBeVisible();
    await modal.locator('select[name="is_group"]').selectOption('0');
    await modal.locator('input[name="title"]').fill('Fix-Slot-ohne-Zeit');
    await modal.locator('select[name="slot_mode"]').selectOption('fix');
    // Kein startAt/endAt — dort absichtlich leer.
    await tree.saveModalExpectError(/Start.*Endzeit|Slot-Modus.*fix/i);
    await tree.cancelModal();
    await tree.expectNodeAbsent('Fix-Slot-ohne-Zeit');
  });

  test('Konvertieren Aufgabe → Gruppe funktioniert auf leerem Leaf', async ({ page }) => {
    // Neuen leeren Leaf anlegen, dann zu Gruppe konvertieren.
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await tree.createTopLevelNode({
      isGroup: false,
      title: 'WirdZurGruppe',
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
    });
    await tree.expectNodeIsLeaf('WirdZurGruppe');

    await tree.convertNode('WirdZurGruppe');
    await tree.expectNodeIsGroup('WirdZurGruppe');
  });

  test('Konvertieren Gruppe → Aufgabe bei Gruppe mit Kindern blockiert', async ({ page }) => {
    // Thekendienst hat Kind (Essensausgabe) → convert muss disabled sein.
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await tree.expectActionDisabled('Thekendienst', 'convert');
  });

  test('Loeschen Gruppe mit Kindern blockiert', async ({ page }) => {
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await tree.expectActionDisabled('Thekendienst', 'delete');
  });

  test('Loeschen einer leeren Gruppe funktioniert', async ({ page }) => {
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await tree.deleteNode('WirdZurGruppe');
    await tree.expectNodeAbsent('WirdZurGruppe');
  });

  test('Modal-Edit zeigt Breadcrumb-Pfad (Struktur-Kontext)', async ({ page }) => {
    // Essensausgabe liegt unter Thekendienst — Breadcrumb muss Thekendienst
    // enthalten.
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    await tree.openEditModal('Essensausgabe');
    await tree.expectBreadcrumbContains('Thekendienst');
    await tree.cancelModal();
  });

  test('I7b3: Leaf ohne Zusagen hat task-status-empty-Klasse + Badge', async ({ page }) => {
    // Essensausgabe hat capacity_target=2, current_count=0 → EMPTY.
    // Dieser Test deckt den Admin-Editor-Fall ab; PARTIAL/FULL werden in
    // Spec 12 (Mitglieder-Accordion) mit echten Zusagen getestet — der
    // Admin-Editor hat keinen Uebernehmen-Flow.
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    const leaf = tree.nodeByTitle('Essensausgabe');
    await expect(leaf).toHaveClass(/task-status-empty/);
    await expect(leaf).toHaveAttribute('aria-label', /Status: keine Zusagen/);
    await expect(leaf.locator('.task-status-badge--empty').first()).toContainText(/keine Zusage/);
  });

  test('I7b3: Gruppe mit EMPTY-Kind zeigt EMPTY-Status (Rollup)', async ({ page }) => {
    // Thekendienst enthaelt Essensausgabe (0/2, EMPTY). Schlechtester-
    // Kinderstatus-Rollup (G1-Entscheidung A Variante 1) → Gruppe ist EMPTY.
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);
    await login.loginAs(EVENT_ADMIN);
    await tree.gotoEdit(eventId);

    const group = tree.nodeByTitle('Thekendienst');
    await expect(group).toHaveClass(/task-status-empty/);
    await expect(group).toHaveAttribute('aria-label', /Status: keine Zusagen/);
  });

  test('Flag=0 → alte flache UI, Tree-Widget weg', async ({ page }) => {
    setE2eSetting('events.tree_editor_enabled', '0');
    try {
      const login = new LoginPage(page);
      const tree = new AdminEventTreePage(page);
      await login.loginAs(EVENT_ADMIN);

      await tree.gotoEdit(eventId);
      await tree.expectEditorAbsent();

      await tree.gotoShow(eventId);
      await tree.expectFlatUiOnShow();
    } finally {
      // Wieder an, damit afterAll konsistent runterfaehrt.
      setE2eSetting('events.tree_editor_enabled', '1');
    }
  });
});
