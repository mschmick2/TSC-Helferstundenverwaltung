import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventTemplatesPage } from '../pages/AdminEventTemplatesPage';
import { EVENT_ADMIN } from '../fixtures/users';
import { setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7c Phase 4 — Playwright-E2E fuer den Template-Aufgabenbaum-
 * Editor.
 *
 * Deckt ab:
 *   - Editor-Rendering bei Flag=1 und editierbarem Template.
 *   - Readonly-Fallback bei gesperrtem Template (nach deriveEvent).
 *   - Legacy-Flache-Liste bei Flag=0.
 *   - Knoten anlegen, editieren, konvertieren, loeschen.
 *   - Offset-Felder statt datetime-local im Template-Kontext.
 *   - Read-Preview in show.php.
 *   - Regression: Event-Editor unveraendert.
 *
 * Serial: Setup-Schritte bauen das Template Schritt fuer Schritt auf.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Template-Aufgabenbaum-Editor (I7c)', () => {
  const now = new Date();
  const templateName = `E2E Template-Tree ${now.getTime()}`;
  let templateId = 0;

  test.beforeAll(() => {
    setE2eSetting('events.tree_editor_enabled', '1');
  });

  test.afterAll(() => {
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  // ---------------------------------------------------------------------------
  // Setup
  // ---------------------------------------------------------------------------

  test('Setup: Admin legt Template an', async ({ page }) => {
    const login = new LoginPage(page);
    const tpls  = new AdminEventTemplatesPage(page);

    await login.loginAs(EVENT_ADMIN);
    templateId = await tpls.createTemplate({
      name: templateName,
      description: 'Tree-Editor-Smoke',
    });
    expect(templateId).toBeGreaterThan(0);
  });

  // ---------------------------------------------------------------------------
  // Editor-Mode
  // ---------------------------------------------------------------------------

  test('Editor rendert bei Flag=1 und editierbarem Template', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}/edit`);

    const editor = page.locator('#task-tree-editor');
    await expect(editor).toBeVisible();
    await expect(editor).toHaveAttribute('data-context', 'template');
    await expect(editor).toHaveAttribute('data-entity-id', String(templateId));
    // Legacy-Flach-UI ist NICHT sichtbar.
    await expect(page.locator('.card', { hasText: /Task-Vorlagen \(\d+\)/ })).toHaveCount(0);
  });

  test('Top-Level-Gruppe anlegen', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}/edit`);

    await page.locator('[data-action="add-child"][data-parent-task-id=""]').first().click();
    const modal = page.locator('#task-edit-modal');
    await expect(modal).toBeVisible();
    await modal.locator('select[name="is_group"]').selectOption('1');
    await modal.locator('input[name="title"]').fill('Hallenaufbau');
    await page.locator('#task-edit-modal-save').click();

    await expect(page.locator('.task-node', { hasText: 'Hallenaufbau' })).toBeVisible({
      timeout: 15_000,
    });
  });

  test('Child-Leaf unter Gruppe mit Offset-Minuten anlegen', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}/edit`);

    const group = page.locator('.task-node', { hasText: 'Hallenaufbau' }).first();
    await group.locator('[data-action="add-child"]').first().click();

    const modal = page.locator('#task-edit-modal');
    await expect(modal).toBeVisible();
    // is_group-Select steht im Create-Modus auf Leaf (0) als Default.
    await modal.locator('select[name="is_group"]').selectOption('0');
    await modal.locator('input[name="title"]').fill('Musikanlage aufbauen');
    await modal.locator('select[name="slot_mode"]').selectOption('fix');

    // I7c: Im Template-Kontext rendert das JS Offset-Number-Felder,
    // NICHT datetime-local.
    await expect(modal.locator('input[name="start_at"]')).toHaveCount(0);
    await expect(modal.locator('input[name="end_at"]')).toHaveCount(0);
    await modal.locator('input[name="default_offset_minutes_start"]').fill('30');
    await modal.locator('input[name="default_offset_minutes_end"]').fill('150');

    await modal.locator('select[name="capacity_mode"]').selectOption('ziel');
    await modal.locator('input[name="capacity_target"]').fill('2');
    await modal.locator('input[name="hours_default"]').fill('2');

    await page.locator('#task-edit-modal-save').click();

    // strict-mode-sicher: Leaf ist auch im Subtree der Gruppe enthalten,
    // daher matcht der plain .task-node-Selektor zwei Elemente.
    await expect(
      page.locator('.task-node--leaf', { hasText: 'Musikanlage aufbauen' }).first()
    ).toBeVisible({ timeout: 15_000 });
  });

  test('Leaf mit positivem Offset: Show-Seite formatiert "+2 h 30 min"', async ({ page }) => {
    // Tree-Editor rendert die Leaf-Node ohne Offsets im Editor-Node-
    // Partial (das ist Editor-Info-Daten, nicht dasselbe wie der
    // readonly Preview). Die Offset-Formatierung greift auf der
    // Show-Seite und im readonly-Branch von edit.php.
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}`);

    // Read-Preview-Card ist sichtbar, weil Template eine Hierarchie hat.
    const preview = page.locator('.event-template-tree-preview');
    await expect(preview).toBeVisible();

    // Leaf "Musikanlage aufbauen" zeigt die Offset-Formatierung.
    // strict-mode-sicher: Gruppen-<li> enthaelt den Leaf-Text auch, darum
    // praeziser auf --leaf.
    const leaf = preview.locator('.task-node-readonly--leaf', {
      hasText: 'Musikanlage aufbauen',
    }).first();
    await expect(leaf).toBeVisible();
    // Offset-Start=30 + Offset-Ende=150 → Anzeige "+30 min – +2 h 30 min"
    await expect(leaf).toContainText('+30 min');
    await expect(leaf).toContainText('+2 h 30 min');
  });

  test('Konvertieren Gruppe mit Kindern → Leaf wird geblockt', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}/edit`);

    const group = page.locator('.task-node', { hasText: 'Hallenaufbau' }).first();
    const convertBtn = group.locator('[data-action="convert"]').first();
    // Bei Gruppe mit aktiven Kindern: disabled.
    await expect(convertBtn).toBeDisabled();
  });

  test('Leeren Leaf anlegen und zu Gruppe konvertieren', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}/edit`);

    // Top-Level Leaf anlegen.
    await page.locator('[data-action="add-child"][data-parent-task-id=""]').first().click();
    const modal = page.locator('#task-edit-modal');
    await expect(modal).toBeVisible();
    await modal.locator('select[name="is_group"]').selectOption('0');
    await modal.locator('input[name="title"]').fill('WirdZurGruppe');
    await modal.locator('select[name="slot_mode"]').selectOption('variabel');
    await modal.locator('select[name="capacity_mode"]').selectOption('unbegrenzt');
    await modal.locator('input[name="hours_default"]').fill('1');
    await page.locator('#task-edit-modal-save').click();
    await expect(page.locator('.task-node', { hasText: 'WirdZurGruppe' })).toBeVisible({
      timeout: 15_000,
    });

    // Jetzt konvertieren.
    const node = page.locator('.task-node', { hasText: 'WirdZurGruppe' }).first();
    page.once('dialog', dialog => dialog.accept());
    await node.locator('[data-action="convert"]').first().click();
    await page.waitForLoadState('load');

    // Nach Reload: der Knoten ist eine Gruppe (task-node--group).
    await expect(
      page.locator('.task-node--group', { hasText: 'WirdZurGruppe' })
    ).toBeVisible();
  });

  test('Leere Gruppe loeschen funktioniert', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}/edit`);

    const node = page.locator('.task-node', { hasText: 'WirdZurGruppe' }).first();
    page.once('dialog', dialog => dialog.accept());
    await node.locator('[data-action="delete"]').first().click();
    await page.waitForLoadState('load');

    await expect(
      page.locator('.task-node', { hasText: 'WirdZurGruppe' })
    ).toHaveCount(0);
  });

  // ---------------------------------------------------------------------------
  // Show-Seite (Read-Preview)
  // ---------------------------------------------------------------------------

  test('Show-Seite zeigt Read-Preview bei hierarchischem Template', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/event-templates/${templateId}`);

    await expect(page.locator('.event-template-tree-preview')).toBeVisible();
    // Flache Legacy-Task-Vorlagen-Card ist NICHT sichtbar, weil Struktur
    // vorhanden ist.
    await expect(
      page.locator('.card-header h2', { hasText: /^Task-Vorlagen$/ })
    ).toHaveCount(0);
  });

  // ---------------------------------------------------------------------------
  // Readonly-Fallback nach Derive
  // ---------------------------------------------------------------------------

  test('Readonly-Mode greift nach deriveEvent', async ({ page }) => {
    const login = new LoginPage(page);
    const tpls  = new AdminEventTemplatesPage(page);

    await login.loginAs(EVENT_ADMIN);
    const inEightDays = new Date(now.getTime() + 8 * 24 * 60 * 60 * 1000);
    const pad = (n: number) => String(n).padStart(2, '0');
    const startAt = `${inEightDays.getFullYear()}-${pad(inEightDays.getMonth() + 1)}-${pad(inEightDays.getDate())}T10:00`;
    const endAt   = `${inEightDays.getFullYear()}-${pad(inEightDays.getMonth() + 1)}-${pad(inEightDays.getDate())}T14:00`;

    const eventId = await tpls.deriveEvent(templateId, {
      title: `Derived ${now.getTime()}`,
      startAt,
      endAt,
      location: 'Vereinsheim',
    });
    expect(eventId).toBeGreaterThan(0);

    // Nun ist das Template gesperrt — Edit-Seite rendert readonly-Branch.
    await page.goto(`/admin/event-templates/${templateId}/edit`);
    await expect(page.locator('.event-template-tree-readonly')).toBeVisible();
    await expect(page.locator('.event-template-tree-readonly')).toContainText(
      /Als neue Version speichern/
    );
    // Kein interaktiver Editor.
    await expect(page.locator('#task-tree-editor')).toHaveCount(0);
  });

  // ---------------------------------------------------------------------------
  // Flag=0 Fallback
  // ---------------------------------------------------------------------------

  test('Flag=0 zeigt flache Legacy-Liste', async ({ page }) => {
    setE2eSetting('events.tree_editor_enabled', '0');
    try {
      const login = new LoginPage(page);
      await login.loginAs(EVENT_ADMIN);
      await page.goto(`/admin/event-templates/${templateId}/edit`);

      // Kein Tree-Editor, kein Readonly-Card.
      await expect(page.locator('#task-tree-editor')).toHaveCount(0);
      await expect(page.locator('.event-template-tree-readonly')).toHaveCount(0);

      // Flache Legacy-Task-Vorlagen-Card ist sichtbar.
      await expect(
        page.locator('.card-header h2', { hasText: /Task-Vorlagen/ })
      ).toBeVisible();
    } finally {
      setE2eSetting('events.tree_editor_enabled', '1');
    }
  });

  // ---------------------------------------------------------------------------
  // Regression: Event-Editor unveraendert
  // ---------------------------------------------------------------------------

  test('Regression: Event-Editor-Routen liefern weiterhin 200', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    // Ein existing Event finden oder einfach die Liste aufrufen.
    const response = await page.goto('/admin/events');
    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText(/Events/);
  });
});
