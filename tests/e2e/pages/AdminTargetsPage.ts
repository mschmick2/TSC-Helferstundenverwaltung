import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /admin/targets (Uebersicht + /admin/targets/{userId} Edit).
 */
export class AdminTargetsPage {
  constructor(private readonly page: Page) {}

  async gotoList(): Promise<void> {
    await this.page.goto('/admin/targets');
    await expect(this.page.locator('h1')).toContainText('Soll-Stunden');
  }

  async isEnabled(): Promise<boolean> {
    const disabled = await this.page
      .locator('.alert-warning', { hasText: 'Soll-Stunden-Funktion ist deaktiviert' })
      .count();
    return disabled === 0;
  }

  async gotoEditByUserId(userId: number): Promise<void> {
    await this.page.goto(`/admin/targets/${userId}`);
    await expect(this.page.locator('input[name="target_hours"]')).toBeVisible();
  }

  async setTargetHours(hours: string): Promise<void> {
    await this.page.locator('input[name="target_hours"]').fill(hours);
    await this.page.getByRole('button', { name: /Speichern/i }).click();
    await this.page.waitForURL(/\/admin\/targets/);
  }

  rowByMitgliedsnummer(mnr: string) {
    return this.page.locator('tbody tr').filter({ hasText: mnr });
  }

  async expectTargetForMember(mnr: string, targetHours: string): Promise<void> {
    const row = this.rowByMitgliedsnummer(mnr);
    await expect(row.locator('td').nth(2)).toContainText(targetHours);
  }

  async expectProgressBarForMember(mnr: string): Promise<void> {
    const row = this.rowByMitgliedsnummer(mnr);
    await expect(row.locator('.progress')).toBeVisible();
    await expect(row.locator('.progress .progress-bar')).toHaveCount(1);
  }
}
