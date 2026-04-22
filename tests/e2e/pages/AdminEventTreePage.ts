import { expect, Locator, Page } from '@playwright/test';

/**
 * Page-Object fuer den Aufgabenbaum-Editor auf /admin/events/{id}/edit
 * (Modul 6 I7b1 Phase 3). Das Widget lebt unterhalb des Event-Formulars,
 * wenn das Settings-Flag events.tree_editor_enabled auf '1' steht.
 *
 * Bedienungs-Pattern aus der DOM-Struktur:
 *   #task-tree-editor                               Wrapper
 *     ul.task-tree-root > li.task-node              Top-Level-Knoten
 *       div.task-node__row                          Titel-Zeile
 *         span.task-node__handle[data-sortable-handle]
 *         button.task-node__edit-trigger            Titel-Klick oeffnet Modal
 *         span.task-node__actions                   Action-Buttons
 *           button[data-action="add-child"]         nur bei Gruppen
 *           button[data-action="edit"]
 *           button[data-action="convert"]           disabled bei Blocker
 *           button[data-action="delete"]            disabled bei Blocker
 *       ul.task-tree-children > li.task-node        rekursiv
 *
 *   #task-edit-modal                                Bootstrap-Modal
 *     #task-edit-modal-breadcrumb                   Breadcrumb-Liste
 *     #task-edit-modal-body                         Form-Body, per JS gefuellt
 *       form#task-edit-form
 *     #task-edit-modal-save                         Speichern-Button
 *
 * Alle Mutationen triggern nach Erfolg ein window.location.reload(), damit
 * die Aggregator-Summen konsistent bleiben (Phase-3-Entscheidung). Beim
 * reload warten wir auf die neu gerenderte Knoten-Zeile.
 */
export class AdminEventTreePage {
  constructor(private readonly page: Page) {}

  // =========================================================================
  // Navigation / Sichtbarkeit
  // =========================================================================

  async gotoEdit(eventId: number): Promise<void> {
    await this.page.goto(`/admin/events/${eventId}/edit`);
    await expect(this.page.locator('h1')).toContainText('Event bearbeiten');
  }

  async gotoShow(eventId: number): Promise<void> {
    await this.page.goto(`/admin/events/${eventId}`);
    await expect(this.page.locator('h1')).toBeVisible();
  }

  editorWrapper(): Locator {
    return this.page.locator('#task-tree-editor');
  }

  async expectEditorVisible(): Promise<void> {
    await expect(this.editorWrapper()).toBeVisible();
  }

  async expectEditorAbsent(): Promise<void> {
    await expect(this.editorWrapper()).toHaveCount(0);
  }

  /**
   * Flat-UI-Fallback auf der Show-Seite (Card mit Titel "Aufgaben und Beigaben").
   */
  async expectFlatUiOnShow(): Promise<void> {
    await expect(
      this.page.locator('.card-header h2', { hasText: /Aufgaben und Beigaben/i })
    ).toBeVisible();
  }

  /**
   * Read-Only-Baum auf der Show-Seite (Card mit Titel "Aufgabenbaum",
   * Phase 3c).
   */
  async expectReadonlyTreeOnShow(): Promise<void> {
    await expect(
      this.page.locator('.card-header h2', { hasText: /Aufgabenbaum/i })
    ).toBeVisible();
    await expect(this.page.locator('.task-tree-readonly').first()).toBeVisible();
  }

  async expectReadonlyHinweisAlert(): Promise<void> {
    const alert = this.page.locator('.alert.alert-info', {
      hasText: /hierarchisch strukturiert/i,
    });
    await expect(alert).toBeVisible();
  }

  // =========================================================================
  // Node-Locator-Helfer
  // =========================================================================

  nodeByTitle(title: string): Locator {
    // Wir finden den spezifischen Edit-Trigger-Button mit dem Text und steigen
    // dann per XPath auf das naechstliegende task-node-LI. Ein naiver Filter
    // `li.task-node { has: ...edit-trigger mit Text }` wuerde auch den
    // aeusseren Gruppen-LI matchen, weil ein Kind-LI den Titel als Nachkomme
    // hat — .first() greift dann den Vorfahren, nicht den Zielknoten.
    return this.page
      .locator('#task-tree-editor button.task-node__edit-trigger', { hasText: title })
      .first()
      .locator('xpath=ancestor::li[contains(concat(" ", normalize-space(@class), " "), " task-node ")][1]');
  }

  async expectNodeVisible(title: string): Promise<void> {
    await expect(this.nodeByTitle(title)).toBeVisible();
  }

  async expectNodeAbsent(title: string): Promise<void> {
    await expect(this.nodeByTitle(title)).toHaveCount(0);
  }

  async expectNodeIsGroup(title: string): Promise<void> {
    const node = this.nodeByTitle(title);
    await expect(node).toHaveAttribute('data-is-group', '1');
  }

  async expectNodeIsLeaf(title: string): Promise<void> {
    const node = this.nodeByTitle(title);
    await expect(node).toHaveAttribute('data-is-group', '0');
  }

  async expectActionDisabled(title: string, action: 'convert' | 'delete' | 'add-child' | 'edit'): Promise<void> {
    const btn = this.nodeByTitle(title).locator(`[data-action="${action}"]`).first();
    await expect(btn).toBeDisabled();
  }

  // =========================================================================
  // Create-Flow
  // =========================================================================

  /**
   * Klickt "Knoten anlegen" oben am Widget (Top-Level) und fuellt das Modal.
   */
  async createTopLevelNode(input: {
    isGroup: boolean;
    title: string;
    description?: string;
    slotMode?: 'fix' | 'variabel';
    hoursDefault?: number;
    capacityMode?: 'unbegrenzt' | 'ziel' | 'maximum';
    capacityTarget?: number;
    startAt?: string;
    endAt?: string;
  }): Promise<void> {
    await this.editorWrapper()
      .locator('[data-action="add-child"][data-parent-task-id=""]')
      .first()
      .click();
    await this.fillCreateModal(input);
    await this.saveModalAndWaitReload(input.title);
  }

  /**
   * Klickt das Plus-Icon einer Gruppe und fuellt das Modal.
   */
  async createChildUnder(parentTitle: string, input: {
    isGroup: boolean;
    title: string;
    description?: string;
    slotMode?: 'fix' | 'variabel';
    hoursDefault?: number;
    capacityMode?: 'unbegrenzt' | 'ziel' | 'maximum';
    capacityTarget?: number;
    startAt?: string;
    endAt?: string;
  }): Promise<void> {
    await this.nodeByTitle(parentTitle)
      .locator('[data-action="add-child"]')
      .first()
      .click();
    await this.fillCreateModal(input);
    await this.saveModalAndWaitReload(input.title);
  }

  private async fillCreateModal(input: {
    isGroup: boolean;
    title: string;
    description?: string;
    slotMode?: 'fix' | 'variabel';
    hoursDefault?: number;
    capacityMode?: 'unbegrenzt' | 'ziel' | 'maximum';
    capacityTarget?: number;
    startAt?: string;
    endAt?: string;
  }): Promise<void> {
    const modal = this.page.locator('#task-edit-modal');
    await expect(modal).toBeVisible();

    // is_group per Select im Create-Modus waehlen.
    await modal.locator('select[name="is_group"]')
      .selectOption(input.isGroup ? '1' : '0');
    await modal.locator('input[name="title"]').fill(input.title);
    if (input.description !== undefined) {
      await modal.locator('textarea[name="description"]').fill(input.description);
    }
    if (!input.isGroup) {
      if (input.slotMode) {
        await modal.locator('select[name="slot_mode"]').selectOption(input.slotMode);
      }
      if (input.hoursDefault !== undefined) {
        await modal.locator('input[name="hours_default"]').fill(String(input.hoursDefault));
      }
      if (input.capacityMode) {
        await modal.locator('select[name="capacity_mode"]').selectOption(input.capacityMode);
      }
      if (input.capacityTarget !== undefined) {
        await modal.locator('input[name="capacity_target"]').fill(String(input.capacityTarget));
      }
      if (input.startAt !== undefined) {
        await modal.locator('input[name="start_at"]').fill(input.startAt);
      }
      if (input.endAt !== undefined) {
        await modal.locator('input[name="end_at"]').fill(input.endAt);
      }
    }
  }

  /**
   * Speichert das Modal und wartet auf den Reload-zurueck-Zustand: das Modal
   * verschwindet, die neue/bearbeitete Knoten-Zeile ist sichtbar.
   */
  private async saveModalAndWaitReload(expectedTitle: string): Promise<void> {
    await this.page.locator('#task-edit-modal-save').click();
    // Nach Success triggert JS location.reload(). Wir warten auf die neu
    // gerenderte Titel-Zeile im Tree.
    await expect(this.nodeByTitle(expectedTitle)).toBeVisible({ timeout: 15_000 });
    // Modal sollte durch den Reload weg sein.
    await expect(this.page.locator('#task-edit-modal.show')).toHaveCount(0);
  }

  // =========================================================================
  // Edit-Flow
  // =========================================================================

  async openEditModal(title: string): Promise<void> {
    await this.nodeByTitle(title)
      .locator('[data-action="edit"]')
      .first()
      .click();
    await expect(this.page.locator('#task-edit-modal')).toBeVisible();
    await expect(this.page.locator('#task-edit-modal-body input[name="title"]'))
      .toBeVisible();
  }

  async expectBreadcrumbContains(segment: string): Promise<void> {
    await expect(
      this.page.locator('#task-edit-modal-breadcrumb')
    ).toContainText(segment);
  }

  async cancelModal(): Promise<void> {
    await this.page.locator('#task-edit-modal .btn-close').click();
    await expect(this.page.locator('#task-edit-modal.show')).toHaveCount(0);
  }

  /**
   * Versucht das Modal zu speichern, erwartet aber einen Inline-Fehler-
   * Block (Validation-Fehler). Kein location.reload().
   */
  async saveModalExpectError(textPattern: RegExp): Promise<void> {
    await this.page.locator('#task-edit-modal-save').click();
    const errBlock = this.page.locator('#task-edit-form-errors .alert-danger');
    await expect(errBlock).toBeVisible({ timeout: 5_000 });
    await expect(errBlock).toContainText(textPattern);
  }

  // =========================================================================
  // Convert / Delete
  // =========================================================================

  async convertNode(title: string): Promise<void> {
    // Convert loest window.confirm aus — auto-accepten.
    this.page.once('dialog', (dialog) => { void dialog.accept(); });
    await this.nodeByTitle(title)
      .locator('[data-action="convert"]')
      .first()
      .click();
    // Nach Erfolg reload → Knoten-Data-Attribut is_group flipt.
    await this.page.waitForLoadState('load');
  }

  async deleteNode(title: string): Promise<void> {
    this.page.once('dialog', (dialog) => { void dialog.accept(); });
    await this.nodeByTitle(title)
      .locator('[data-action="delete"]')
      .first()
      .click();
    // Nach Erfolg reload → Knoten verschwindet.
    await this.page.waitForLoadState('load');
  }

  // =========================================================================
  // Toast
  // =========================================================================

  async expectErrorToast(textPattern: RegExp): Promise<void> {
    const toast = this.page
      .locator('.toast.text-bg-danger')
      .filter({ hasText: textPattern });
    await expect(toast).toBeVisible({ timeout: 5_000 });
  }

  async expectSuccessToast(textPattern: RegExp): Promise<void> {
    const toast = this.page
      .locator('.toast.text-bg-success')
      .filter({ hasText: textPattern });
    await expect(toast).toBeVisible({ timeout: 5_000 });
  }
}
