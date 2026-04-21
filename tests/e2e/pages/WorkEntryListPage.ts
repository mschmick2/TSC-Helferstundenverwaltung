import { expect, Page, Locator } from '@playwright/test';

/**
 * Page-Object fuer /entries (eigene Antraege).
 *
 * Die Liste sortiert per Default nach work_date DESC. Fuer deterministische
 * Tests rufen wir sie immer mit sort=created_at&dir=DESC auf, damit der
 * zuletzt erstellte Antrag oben steht.
 */
export class WorkEntryListPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/entries?sort=created_at&dir=DESC');
    await expect(this.page.locator('h1')).toContainText('Meine Arbeitsstunden');
  }

  async clickCreate(): Promise<void> {
    await this.page.getByRole('link', { name: /Neuer Eintrag/i }).first().click();
    await expect(this.page).toHaveURL(/\/entries\/create/);
  }

  /**
   * Extrahiert die Entry-ID des obersten Tabellen-Eintrags aus dessen
   * Nr.-Link (href="/entries/{id}"). Wirft, wenn Tabelle leer ist.
   */
  async topEntryId(): Promise<number> {
    const firstLink = this.page.locator('tbody tr').first()
      .locator('a[href*="/entries/"]').first();
    const href = await firstLink.getAttribute('href');
    if (!href) throw new Error('Kein Eintrag in Liste gefunden.');
    const match = href.match(/\/entries\/(\d+)/);
    if (!match) throw new Error(`Kein ID in href: ${href}`);
    return Number(match[1]);
  }

  async topEntryNumber(): Promise<string> {
    const firstLink = this.page.locator('tbody tr').first()
      .locator('a[href*="/entries/"]').first();
    return (await firstLink.textContent())?.trim() ?? '';
  }

  rowByEntryNumber(entryNumber: string): Locator {
    return this.page.locator('tbody tr').filter({ hasText: entryNumber });
  }

  async expectRowStatus(entryNumber: string, statusLabel: string): Promise<void> {
    const row = this.rowByEntryNumber(entryNumber);
    await expect(row).toBeVisible();
    await expect(row.locator('.badge').first()).toContainText(statusLabel);
  }
}
