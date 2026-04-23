import { expect, Locator, Page } from '@playwright/test';

/**
 * Page-Object fuer die Mitglieder-Event-Detail-Seite mit Accordion-View
 * (Modul 6 I7b2). Zugang ueber /events/{eventId}.
 *
 * DOM-Struktur:
 *   .task-group-accordion-wrapper
 *     .task-group-accordion-filter    — Sticky-Toggle-Zeile
 *       input#filter-open-only        — Bootstrap-Switch
 *     .task-group-accordion           — Container; bekommt
 *                                        .filter-open-only wenn aktiv
 *       details.task-group-accordion-group
 *         summary.task-group-accordion-summary
 *         div.task-group-accordion-children
 *           div.task-group-accordion-leaf[data-open-count]
 *             div.task-group-accordion-leaf-actions
 *               form (_assign_form.php) oder
 *               button "Bereits zugesagt" / "Ausgebucht"
 *       p.task-group-accordion-empty  — Empty-State (CSS-gesteuert)
 *
 * Bei Flag=0 oder flachen Event-Aufgaben rendert die Seite die
 * bestehende Karten-Ansicht (`.card .card-body`) — kein Accordion.
 */
export class MemberEventAccordionPage {
  constructor(private readonly page: Page) {}

  async goto(eventId: number): Promise<void> {
    await this.page.goto(`/events/${eventId}`);
    await expect(this.page.locator('h1')).toBeVisible();
  }

  accordion(): Locator {
    return this.page.locator('.task-group-accordion');
  }

  filterToggle(): Locator {
    return this.page.locator('#filter-open-only');
  }

  async expectAccordionVisible(): Promise<void> {
    await expect(this.accordion()).toBeVisible();
  }

  async expectAccordionAbsent(): Promise<void> {
    await expect(this.accordion()).toHaveCount(0);
  }

  async expectFlatCardUiVisible(): Promise<void> {
    // Bestand: Bootstrap-Card pro Task. Kennzeichen: .card-body mit
    // Aufgaben-Badge.
    await expect(this.page.locator('.card .card-body').first()).toBeVisible();
  }

  // Locator fuer einen Leaf mit exaktem Titel. Wir gehen vom Titel-<strong>
  // innerhalb .task-group-accordion-leaf-header zum umschliessenden Leaf-DIV
  // — analog zum XPath-Pattern aus AdminEventTreePage (nodeByTitle).
  leafByTitle(title: string): Locator {
    return this.page
      .locator('.task-group-accordion-leaf-header strong', { hasText: title })
      .first()
      .locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " task-group-accordion-leaf ")][1]');
  }

  // Locator fuer eine Gruppe (<details>) mit exaktem Titel in der Summary.
  groupByTitle(title: string): Locator {
    return this.page
      .locator('summary.task-group-accordion-summary strong', { hasText: title })
      .first()
      .locator('xpath=ancestor::details[contains(concat(" ", normalize-space(@class), " "), " task-group-accordion-group ")][1]');
  }

  async expectLeafVisible(title: string): Promise<void> {
    await expect(this.leafByTitle(title)).toBeVisible();
  }

  async expectLeafHidden(title: string): Promise<void> {
    // display:none per CSS-Filter → toBeHidden greift.
    await expect(this.leafByTitle(title)).toBeHidden();
  }

  async expectGroupVisible(title: string): Promise<void> {
    await expect(this.groupByTitle(title)).toBeVisible();
  }

  async expectGroupHidden(title: string): Promise<void> {
    await expect(this.groupByTitle(title)).toBeHidden();
  }

  async enableFilter(): Promise<void> {
    const toggle = this.filterToggle();
    await toggle.check();
    // Bestaetige, dass der Container die Klasse bekommen hat.
    await expect(this.accordion()).toHaveClass(/filter-open-only/);
  }

  async disableFilter(): Promise<void> {
    const toggle = this.filterToggle();
    await toggle.uncheck();
    await expect(this.accordion()).not.toHaveClass(/filter-open-only/);
  }

  /**
   * Oeffnet eine zugeklappte Gruppe (details), damit die Kinder im DOM
   * sichtbar sind. Bei offenen Gruppen ist der Aufruf idempotent.
   */
  async openGroup(title: string): Promise<void> {
    const group = this.groupByTitle(title);
    const isOpen = await group.evaluate((el) => (el as HTMLDetailsElement).open);
    if (!isOpen) {
      await group.locator('summary').click();
      await expect(group).toHaveJSProperty('open', true);
    }
  }

  /**
   * Uebernimmt einen Leaf (klickt den Uebernehmen-Submit-Button) und
   * wartet auf den anschliessenden Redirect + Reload.
   */
  async takeOverLeaf(title: string): Promise<void> {
    const leaf = this.leafByTitle(title);
    await leaf.locator('form button[type="submit"]').first().click();
    // Nach Assignment-Save folgt Redirect (zurueck auf /events/{id}).
    await this.page.waitForLoadState('load');
  }

  async expectLeafAlreadyAssigned(title: string): Promise<void> {
    const leaf = this.leafByTitle(title);
    await expect(leaf).toContainText(/Bereits zugesagt/i);
  }
}
