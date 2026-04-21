import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /entries/{id}/edit. Wird von I2 nicht voll ausgereizt,
 * deckt aber die Basis-Flows (Stunden anpassen + speichern) ab.
 */
export class WorkEntryEditPage {
  constructor(private readonly page: Page) {}

  async goto(entryId: number): Promise<void> {
    await this.page.goto(`/entries/${entryId}/edit`);
    await expect(this.page.locator('input[name="hours"]')).toBeVisible();
  }

  async setHours(hours: string): Promise<void> {
    await this.page.locator('input[name="hours"]').fill(hours);
  }

  async setDescription(description: string): Promise<void> {
    await this.page.locator('textarea[name="description"]').fill(description);
  }

  async save(): Promise<void> {
    await this.page.getByRole('button', { name: /Speichern/i }).first().click();
    await this.page.waitForURL(/\/entries/);
  }
}
