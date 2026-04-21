import { expect, Page } from '@playwright/test';

export interface NewMemberInput {
  mitgliedsnummer: string;
  email: string;
  vorname: string;
  nachname: string;
}

/**
 * Page-Object fuer /admin/users (Liste, Anlage, Detail).
 */
export class AdminUsersPage {
  constructor(private readonly page: Page) {}

  async gotoList(): Promise<void> {
    await this.page.goto('/admin/users');
    await expect(this.page.locator('h1')).toContainText('Mitglieder');
  }

  async gotoCreate(): Promise<void> {
    await this.page.goto('/admin/users/create');
    await expect(this.page.locator('h1')).toContainText('Neues Mitglied');
  }

  async gotoShow(userId: number): Promise<void> {
    await this.page.goto(`/admin/users/${userId}`);
    await expect(this.page.locator('h1')).toBeVisible();
  }

  /**
   * Fuellt Pflichtfelder aus, roles[] bleibt auf dem Default "Mitglied".
   */
  async fillCreate(input: NewMemberInput): Promise<void> {
    await this.page.locator('input[name="mitgliedsnummer"]').fill(input.mitgliedsnummer);
    await this.page.locator('input[name="email"]').fill(input.email);
    await this.page.locator('input[name="vorname"]').fill(input.vorname);
    await this.page.locator('input[name="nachname"]').fill(input.nachname);
  }

  async submitCreate(): Promise<void> {
    await this.page.getByRole('button', { name: /Mitglied anlegen/i }).click();
    await this.page.waitForURL(/\/admin\/users/);
  }

  /**
   * Oeffnet die Detail-Seite per Klick auf den Namen in der Liste
   * und gibt die User-ID aus der URL zurueck.
   */
  async openUserByEmail(email: string): Promise<number> {
    const row = this.page.locator('tbody tr').filter({ hasText: email });
    await row.locator('a[href^="/admin/users/"]').first().click();
    await this.page.waitForURL(/\/admin\/users\/\d+$/);
    const url = this.page.url();
    const match = url.match(/\/admin\/users\/(\d+)/);
    if (!match) throw new Error(`Keine User-ID in URL: ${url}`);
    return Number(match[1]);
  }

  /**
   * Schaltet eine Rolle per Checkbox-Label an oder aus und speichert.
   * Label-Namen: "Mitglied", "Erfasser", "Prüfer", "Auditor", "Administrator".
   */
  async toggleRoleAndSave(roleLabel: string, checked: boolean): Promise<void> {
    const checkbox = this.page
      .locator('.form-check')
      .filter({ hasText: roleLabel })
      .locator('input[type="checkbox"]');

    if (checked) {
      await checkbox.check();
    } else {
      await checkbox.uncheck();
    }

    await this.page.getByRole('button', { name: /Rollen speichern/i }).click();
    await this.page.waitForURL(/\/admin\/users\/\d+/);
  }

  async expectUserHasRole(roleLabel: string): Promise<void> {
    const checkbox = this.page
      .locator('.form-check')
      .filter({ hasText: roleLabel })
      .locator('input[type="checkbox"]');
    await expect(checkbox).toBeChecked();
  }
}
