import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /events und /my-events (Mitglied-Sicht).
 */
export class MemberEventsPage {
  constructor(private readonly page: Page) {}

  async gotoList(): Promise<void> {
    await this.page.goto('/events');
    await expect(this.page.locator('h1')).toContainText('Events');
  }

  async gotoShow(eventId: number): Promise<void> {
    await this.page.goto(`/events/${eventId}`);
    await expect(this.page.locator('h1')).toBeVisible();
  }

  async gotoMyAssignments(): Promise<void> {
    await this.page.goto('/my-events');
    await expect(this.page.locator('h1')).toBeVisible();
  }

  async expectEventVisible(title: string): Promise<void> {
    await expect(this.page.locator('body')).toContainText(title);
  }

  /**
   * Klickt den "Uebernehmen"-Button fuer die Task mit `taskTitle`.
   * Fuer Slot-Modus "fix" reicht das; fuer "variabel" muessen proposed_start/end gesetzt sein.
   */
  async assignToTask(taskTitle: string): Promise<void> {
    // Die Task-Card hat die h3 mit dem Titel; das Form liegt unter derselben card-body.
    const card = this.page.locator('.card', { has: this.page.locator('h3', { hasText: taskTitle }) });
    await expect(card).toBeVisible();
    await card.getByRole('button', { name: /uebernehmen/i }).click();
    await this.page.waitForURL(/\/events\/\d+/);
  }

  async expectAssignmentBadge(taskTitle: string): Promise<void> {
    const card = this.page.locator('.card', { has: this.page.locator('h3', { hasText: taskTitle }) });
    await expect(card.locator('button', { hasText: /bereits zugesagt/i })).toBeVisible();
  }

  async expectMyAssignmentContains(text: string): Promise<void> {
    await expect(this.page.locator('body')).toContainText(text);
  }
}
