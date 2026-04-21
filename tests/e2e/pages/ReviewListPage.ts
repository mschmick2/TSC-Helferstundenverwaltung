import { expect, Page, Locator } from '@playwright/test';

/**
 * Page-Object fuer /review (Pruefer-Eingangsliste).
 */
export class ReviewListPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/review');
    await expect(this.page.locator('h1')).toContainText('Anträge prüfen');
  }

  rowByEntryNumber(entryNumber: string): Locator {
    return this.page.locator('tbody tr').filter({ hasText: entryNumber });
  }

  async expectEntryVisible(entryNumber: string): Promise<void> {
    await expect(this.rowByEntryNumber(entryNumber)).toBeVisible();
  }

  async openEntry(entryNumber: string): Promise<void> {
    await this.rowByEntryNumber(entryNumber)
      .getByRole('link', { name: /Pr.+fen/i })
      .click();
    await this.page.waitForURL(/\/entries\/\d+/);
  }
}
