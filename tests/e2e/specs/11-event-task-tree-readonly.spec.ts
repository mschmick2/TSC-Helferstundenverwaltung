import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { AdminEventTreePage } from '../pages/AdminEventTreePage';
import { EVENT_ADMIN } from '../fixtures/users';
import { setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7b1 Phase 4 — Playwright-E2E fuer die Read-Only-Baumansicht
 * auf /admin/events/{id} (Phase 3c).
 *
 * Deckt ab:
 *   - Baumansicht erscheint nur bei Flag=1 UND existierender Baumstruktur.
 *   - Hinweis-Alert verweist auf den Editor ("Struktur bearbeiten").
 *   - Bearbeiten-Button fuehrt auf die Editor-Seite.
 *   - Event ohne Baumstruktur (flache Tasks, keine Gruppen/Parents)
 *     rendert die alte flache Tabelle unveraendert.
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

test.describe('Aufgabenbaum-Editor — Read-Only-Detail-Ansicht (I7b1 Phase 3c)', () => {
  const now = new Date();
  const startDate = new Date(now.getTime() + 8 * 24 * 60 * 60 * 1000);
  const endDate   = new Date(startDate.getTime() + 3 * 60 * 60 * 1000);
  const startAt   = toLocalDateTime(startDate);
  const endAt     = toLocalDateTime(endDate);

  // Zwei Events: eines mit Baumstruktur, eines mit flachen Tasks.
  const treeEventTitle = `E2E Tree-ReadOnly ${now.getTime()}`;
  const flatEventTitle = `E2E Flat ${now.getTime()}`;

  let treeEventId = 0;
  let flatEventId = 0;

  test.beforeAll(() => {
    setE2eSetting('events.tree_editor_enabled', '1');
  });

  test.afterAll(() => {
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  test('Setup: Event mit Baumstruktur (Gruppe + Leaf) anlegen', async ({ page }) => {
    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    treeEventId = await events.createEvent({
      title: treeEventTitle,
      description: 'Baumstruktur-Beispiel',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(treeEventId).toBeGreaterThan(0);

    await tree.gotoEdit(treeEventId);
    await tree.createTopLevelNode({
      isGroup: true,
      title: 'Hallenaufbau',
    });
    await tree.createChildUnder('Hallenaufbau', {
      isGroup: false,
      title: 'Musik',
      slotMode: 'variabel',
      capacityMode: 'ziel',
      capacityTarget: 2,
      hoursDefault: 2,
    });
  });

  test('Setup: Event mit flachen Tasks (keine Gruppen, keine Parents)', async ({ page }) => {
    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);
    flatEventId = await events.createEvent({
      title: flatEventTitle,
      description: 'Flaches Beispiel',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(flatEventId).toBeGreaterThan(0);

    // Eine einzelne Top-Level-Aufgabe anlegen ueber die Bestand-Flach-UI
    // auf der Show-Seite. Das soll KEINE Baumstruktur erzeugen (kein
    // is_group, keine parent_task_id).
    await events.addTask(flatEventId, {
      title: 'Kuchen backen',
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
      taskType: 'aufgabe',
    });
  });

  test('Event mit Baumstruktur: Read-Only-Tree erscheint auf Detail-Seite', async ({ page }) => {
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    await tree.gotoShow(treeEventId);
    await tree.expectReadonlyTreeOnShow();
    // Beide Knoten sind sichtbar, eingerueckt via CSS. .first() greift die
    // Root-UL; .task-tree-readonly matched sowohl Root als auch verschachtelte
    // Kinder-ULs, deshalb strict-mode-sicher per first().
    await expect(page.locator('.task-tree-readonly').first()).toContainText('Hallenaufbau');
    await expect(page.locator('.task-tree-readonly').first()).toContainText('Musik');
  });

  test('Hinweis-Alert verweist auf "Struktur bearbeiten"', async ({ page }) => {
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    await tree.gotoShow(treeEventId);
    await tree.expectReadonlyHinweisAlert();
    await expect(
      page.locator('a', { hasText: /Struktur bearbeiten/i })
    ).toBeVisible();
  });

  test('Bearbeiten-Link fuehrt auf Editor-Seite', async ({ page }) => {
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    await tree.gotoShow(treeEventId);
    await page.locator('a', { hasText: /Struktur bearbeiten/i }).click();
    await page.waitForURL(/\/admin\/events\/\d+\/edit$/);
    await tree.expectEditorVisible();
  });

  test('I7b3: Read-Only-Baum zeigt Status-Klassen und Badges an Leaves', async ({ page }) => {
    // Setup: Event hat Hallenaufbau (Gruppe) > Musik (Leaf, cap=ziel-2,
    // keine Zusagen). Beide sollten Status EMPTY haben — kein Mitglied hat
    // bisher uebernommen. Dies testet, dass die Read-Only-View die
    // Status-Klassen und Badges genauso rendert wie der Editor (Konsistenz).
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    await tree.gotoShow(treeEventId);

    // Leaf Musik: hat capacity_target=2, current_count=0 → EMPTY.
    const readonlyRoot = page.locator('.task-tree-readonly').first();
    await expect(readonlyRoot.locator('.task-status-empty').first()).toBeVisible();
    await expect(readonlyRoot.locator('.task-status-badge--empty').first()).toContainText(/keine Zusage/);
    // Gruppe Hallenaufbau: Rollup → EMPTY.
    await expect(readonlyRoot).toContainText(/keine Zusage/);
  });

  test('Event ohne Baumstruktur rendert flache Tabelle unveraendert', async ({ page }) => {
    const login = new LoginPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    await tree.gotoShow(flatEventId);
    // Keine Tree-Card.
    await expect(
      page.locator('.card-header h2', { hasText: /Aufgabenbaum/i })
    ).toHaveCount(0);
    // Stattdessen die Bestand-Tabelle.
    await tree.expectFlatUiOnShow();
    await expect(page.locator('table tbody')).toContainText('Kuchen backen');
  });
});
