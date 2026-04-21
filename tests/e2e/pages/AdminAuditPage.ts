import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /admin/audit.
 */
export class AdminAuditPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/audit');
    await expect(this.page.locator('h1')).toContainText('Audit-Trail');
  }

  /**
   * Filtert auf einen Action-Typ (Wert des `action`-Selects).
   * z.B. 'status_change', 'config_change', 'create'.
   */
  async filterByAction(action: string): Promise<void> {
    await this.page.locator('select[name="action"]').selectOption(action);
    await this.page.getByRole('button', { name: /Filtern/i }).click();
    await this.page.waitForURL(/\/admin\/audit/);
  }

  /**
   * Filtert zusaetzlich nach Antragsnummer.
   */
  async filterByEntryNumber(entryNumber: string): Promise<void> {
    await this.page.locator('input[name="entry_number"]').fill(entryNumber);
    await this.page.getByRole('button', { name: /Filtern/i }).click();
    await this.page.waitForURL(/\/admin\/audit/);
  }

  /**
   * Oeffnet den ersten Eintrag der aktuellen Liste.
   */
  async openFirstEntry(): Promise<void> {
    await this.page.locator('tbody tr a[href^="/admin/audit/"]').first().click();
    await this.page.waitForURL(/\/admin\/audit\/\d+/);
    await expect(this.page.locator('h1')).toContainText('Audit-Detail');
  }

  async expectDetailShowsAction(actionLabel: string): Promise<void> {
    await expect(this.page.locator('.card-header', { hasText: 'Aktion' }).locator('..'))
      .toContainText(actionLabel);
  }

  async expectOldValuesContain(text: string): Promise<void> {
    await expect(
      this.page.locator('.card.border-danger', { hasText: 'Alte Werte' })
    ).toContainText(text);
  }

  async expectNewValuesContain(text: string): Promise<void> {
    await expect(
      this.page.locator('.card.border-success', { hasText: 'Neue Werte' })
    ).toContainText(text);
  }

  async expectAnyRowVisible(): Promise<void> {
    await expect(this.page.locator('tbody tr').first()).toBeVisible();
  }
}
