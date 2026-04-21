import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /setup-password/{token} — Einladungs-Passwort setzen.
 */
export class SetupPasswordPage {
  constructor(private readonly page: Page) {}

  async gotoByToken(token: string): Promise<void> {
    await this.page.goto(`/setup-password/${token}`);
    await expect(this.page.locator('input[name="password"]')).toBeVisible();
  }

  async gotoByUrl(fullUrl: string): Promise<void> {
    await this.page.goto(fullUrl);
    await expect(this.page.locator('input[name="password"]')).toBeVisible();
  }

  async setPassword(password: string): Promise<void> {
    await this.page.locator('input[name="password"]').fill(password);
    await this.page.locator('input[name="password_confirm"]').fill(password);
    await this.page.getByRole('button', { name: /Passwort setzen/i }).click();
    // Erfolgreiche Aktivierung leitet auf /login.
    await this.page.waitForURL(/\/login/);
  }

  /**
   * Extrahiert das Setup-Token aus einem Einladungslink.
   * Unterstuetzt sowohl absoluten als auch pfad-basierten Link.
   */
  static extractToken(link: string): string | null {
    const match = link.match(/\/setup-password\/([a-zA-Z0-9]+)/);
    return match ? match[1] : null;
  }
}
