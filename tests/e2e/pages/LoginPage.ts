import { expect, Page } from '@playwright/test';
import { SeedUser } from '../fixtures/users';

/**
 * Page-Object fuer /login.
 */
export class LoginPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/login');
    await expect(this.page.locator('form')).toBeVisible();
  }

  async fill(email: string, password: string): Promise<void> {
    await this.page.locator('input[name="email"]').fill(email);
    await this.page.locator('input[name="password"]').fill(password);
  }

  async submit(): Promise<void> {
    await this.page.getByRole('button', { name: /anmelden/i }).click();
  }

  async loginAs(user: SeedUser): Promise<void> {
    await this.goto();
    await this.fill(user.email, user.password);
    await this.submit();
    // Wartet auf ein positives Post-Login-Signal: den Logout-Link in der
    // Navbar (im DOM, auch wenn Dropdown eingeklappt). Robuster als
    // waitForURL — bei sehr schnellen Server-Redirects kann die URL-
    // Subscription den Wechsel verpassen, toHaveCount() prueft immer
    // wieder den aktuellen Zustand.
    await expect(this.page.locator('a[href$="/logout"]'))
      .toHaveCount(1, { timeout: 15_000 });
  }

  async expectLoginSuccess(user: SeedUser): Promise<void> {
    // Logout-Link existiert im DOM (eingeklappter Navbar-Dropdown) —
    // dient hier gleichzeitig als Post-Login-Signal. Robuster als
    // waitForURL mit negiertem Pfad-Predikat (siehe loginAs()).
    await expect(this.page.locator('a[href$="/logout"]'))
      .toHaveCount(1, { timeout: 15_000 });
    // Flash-Begruessung enthaelt Vornamen
    await expect(this.page.locator('body')).toContainText(user.vorname);
  }

  async expectLoginError(): Promise<void> {
    await expect(this.page).toHaveURL(/\/login/);
    await expect(this.page.locator('.alert-danger, .alert-warning')).toBeVisible();
  }

  async logout(): Promise<void> {
    // Logout-Link per direkter Navigation auslosen — das Navbar-Dropdown
    // braucht sonst einen Klick auf die Benutzer-Schaltflaeche zuerst.
    await this.page.goto('/logout');
    await this.page.waitForURL((url) => url.pathname.startsWith('/login'));
  }
}
