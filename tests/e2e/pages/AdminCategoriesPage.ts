import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /admin/categories.
 *
 * Anlegen und Deaktivieren geschehen ueber Bootstrap-Modals bzw.
 * Inline-Forms in der Tabellenzeile.
 */
export class AdminCategoriesPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/categories');
    await expect(this.page.locator('h1')).toContainText('Kategorien');
  }

  async createCategory(name: string, description?: string): Promise<void> {
    await this.page.getByRole('button', { name: /Neue Kategorie/i }).click();
    const modal = this.page.locator('#createModal');
    await expect(modal).toBeVisible();

    await modal.locator('input[name="name"]').fill(name);
    if (description !== undefined) {
      await modal.locator('textarea[name="description"]').fill(description);
    }
    await modal.getByRole('button', { name: /Erstellen/i }).click();
    await this.page.waitForURL(/\/admin\/categories/);
  }

  rowByName(name: string) {
    return this.page.locator('tbody tr').filter({ hasText: name });
  }

  async expectRowExists(name: string): Promise<void> {
    await expect(this.rowByName(name)).toBeVisible();
  }

  async expectRowStatus(name: string, statusLabel: 'Aktiv' | 'Inaktiv'): Promise<void> {
    const statusBadge = this.rowByName(name).locator('.badge', { hasText: /^(Aktiv|Inaktiv)$/ });
    await expect(statusBadge).toHaveText(statusLabel);
  }

  async deactivate(name: string): Promise<void> {
    const row = this.rowByName(name);
    await row.locator('form[action*="/deactivate"] button[type="submit"]').click();
    await this.page.waitForURL(/\/admin\/categories/);
  }
}
