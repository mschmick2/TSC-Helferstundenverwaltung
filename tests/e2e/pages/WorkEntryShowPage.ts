import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /entries/{id} inkl. Dialog-Partial.
 *
 * Enthaelt Owner-Aktionen (Bearbeiten/Einreichen/Loeschen/Zurueckziehen/
 * Stornieren/Reaktivieren) sowie Reviewer-Aktionen (Freigeben/Rueckfrage/
 * Ablehnen/Korrektur) und die Dialog-Nachrichten-Funktion.
 */
export class WorkEntryShowPage {
  constructor(private readonly page: Page) {}

  async goto(entryId: number, fromReview = false): Promise<void> {
    const url = fromReview ? `/entries/${entryId}?from=review` : `/entries/${entryId}`;
    await this.page.goto(url);
    await expect(this.page.locator('h1')).toContainText('Antrag');
  }

  /**
   * Prueft den Status-Badge neben dem H1 (ignoriert Status-Badges aus
   * der Dialog-Historie, falls vorhanden).
   */
  async expectStatus(label: string): Promise<void> {
    await expect(this.page.locator('h1 .badge').first()).toContainText(label);
  }

  // ------------------------- Owner-Aktionen -------------------------

  async submit(): Promise<void> {
    await this.page.locator('form[action*="/submit"] button[type="submit"]').click();
    await this.page.waitForURL(/\/entries/);
  }

  async withdraw(): Promise<void> {
    this.page.once('dialog', (d) => d.accept());
    await this.page.locator('form[action*="/withdraw"] button[type="submit"]').click();
    await this.page.waitForURL(/\/entries/);
  }

  async delete(): Promise<void> {
    this.page.once('dialog', (d) => d.accept());
    await this.page.locator('form[action*="/delete"] button[type="submit"]').click();
    await this.page.waitForURL(/\/entries/);
  }

  // ------------------------- Reviewer-Aktionen ----------------------

  approveButton() {
    return this.page.locator('form[action*="/approve"] button[type="submit"]');
  }

  async hasApproveButton(): Promise<boolean> {
    return (await this.approveButton().count()) > 0;
  }

  async approve(): Promise<void> {
    this.page.once('dialog', (d) => d.accept());
    await this.approveButton().click();
    await this.page.waitForURL(/\/entries/);
  }

  async askQuestion(reason: string): Promise<void> {
    await this.page.getByRole('button', { name: /R.+ckfrage/i }).click();
    const modal = this.page.locator('#returnModal');
    await expect(modal).toBeVisible();
    await modal.locator('textarea[name="reason"]').fill(reason);
    await modal.getByRole('button', { name: /R.+ckfrage senden/i }).click();
    await this.page.waitForURL(/\/entries/);
  }

  async reject(reason: string): Promise<void> {
    await this.page.locator('button[data-bs-target="#rejectModal"]').click();
    const modal = this.page.locator('#rejectModal');
    await expect(modal).toBeVisible();
    await modal.locator('textarea[name="reason"]').fill(reason);
    await modal.locator('button[type="submit"]').click();
    await this.page.waitForURL(/\/entries/);
  }

  // ------------------------- Dialog ---------------------------------

  async sendDialogMessage(message: string): Promise<void> {
    const form = this.page.locator('form[action*="/message"]');
    await expect(form).toBeVisible();
    await form.locator('textarea[name="message"]').fill(message);
    await form.locator('button[type="submit"]').click();
    await this.page.waitForURL(/\/entries/);
  }

  async expectDialogContains(text: string): Promise<void> {
    await expect(this.page.locator('#dialog-container')).toContainText(text);
  }

  async expectDialogFormVisible(visible: boolean): Promise<void> {
    const form = this.page.locator('form[action*="/message"]');
    if (visible) {
      await expect(form).toBeVisible();
    } else {
      await expect(form).toHaveCount(0);
    }
  }

  async expectReturnReason(text: string): Promise<void> {
    // Warnungs-Alert mit Rueckfrage-Text, nur in status=in_klaerung gerendert
    await expect(this.page.locator('.alert-warning')).toContainText(text);
  }

  async expectRejectionReason(text: string): Promise<void> {
    // In der Detailansicht steht der Grund in der td.text-danger-Zelle
    // neben der th "Ablehnungsgrund". Der generische .text-danger-Selector
    // wuerde auch das "Abmelden"-Dropdown in der Navbar treffen.
    await expect(this.page.locator('td.text-danger')).toContainText(text);
  }
}
