import { expect, Page } from '@playwright/test';

export interface EntryInput {
  workDate?: string;        // YYYY-MM-DD, leer = default (heute)
  hours: string;            // z.B. '1.5'
  categoryLabel: string;    // z.B. 'Verwaltung'
  description?: string;
  project?: string;
}

/**
 * Page-Object fuer /entries/create.
 *
 * Zwei Submit-Varianten:
 *   - saveAsDraft()      -> "Als Entwurf speichern" (status = entwurf)
 *   - saveAndSubmit()    -> "Speichern & Einreichen" (status = eingereicht)
 *
 * Beide landen per Redirect auf /entries.
 */
export class WorkEntryCreatePage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/entries/create');
    await expect(this.page.locator('input[name="work_date"]')).toBeVisible();
  }

  async fill(input: EntryInput): Promise<void> {
    if (input.workDate) {
      await this.page.locator('input[name="work_date"]').fill(input.workDate);
    }
    await this.page.locator('input[name="hours"]').fill(input.hours);
    await this.page.locator('select[name="category_id"]')
      .selectOption({ label: input.categoryLabel });
    if (input.description !== undefined) {
      await this.page.locator('textarea[name="description"]').fill(input.description);
    }
    if (input.project !== undefined) {
      await this.page.locator('input[name="project"]').fill(input.project);
    }
  }

  async saveAsDraft(): Promise<void> {
    await this.page.getByRole('button', { name: /Als Entwurf speichern/i }).click();
    await this.page.waitForURL(/\/entries(\?|$)/);
  }

  async saveAndSubmit(): Promise<void> {
    await this.page.getByRole('button', { name: /Speichern & Einreichen/i }).click();
    await this.page.waitForURL(/\/entries(\?|$)/);
  }
}
