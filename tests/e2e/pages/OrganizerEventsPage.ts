import { expect, Page } from '@playwright/test';

/**
 * Page-Object fuer /organizer/events (Review-Queue + Eigene Events).
 *
 * Die Review-Queue listet Zeitfenster-Vorschlaege und Storno-Anfragen
 * aller Events, bei denen der eingeloggte User als Organisator eingetragen ist.
 */
export class OrganizerEventsPage {
  constructor(private readonly page: Page) {}

  async gotoIndex(): Promise<void> {
    await this.page.goto('/organizer/events');
    await expect(this.page.locator('h1')).toContainText('Als Organisator');
  }

  /**
   * Gibt den Review-Block (border-start-Container) zurueck, in dem
   * der Event- und Task-Titel kombiniert vorkommen. Robust auch,
   * wenn mehrere Reviews in der Queue stehen.
   */
  private reviewBlock(eventTitle: string, taskTitle: string) {
    return this.page
      .locator('div.border-start', { hasText: eventTitle })
      .filter({ hasText: taskTitle })
      .first();
  }

  /**
   * Prueft, dass eine Storno-Anfrage fuer (eventTitle, taskTitle) in der Queue steht
   * und ein Ersatz-Vorschlag mit dem angegebenen Namen angezeigt wird.
   */
  async expectCancelReviewWithReplacement(
    eventTitle: string,
    taskTitle: string,
    replacementLabel: string
  ): Promise<void> {
    const block = this.reviewBlock(eventTitle, taskTitle);
    await expect(block).toBeVisible();
    await expect(block).toContainText('Storno-Anfrage');
    await expect(block).toContainText('Vorgeschlagener Ersatz');
    await expect(block).toContainText(replacementLabel);
  }

  /**
   * Prueft, dass ein Zeitfenster-Vorschlag fuer (eventTitle, taskTitle) in der
   * Queue steht. Optional wird ein Teilstring aus der formatierten Zeitausgabe
   * (z. B. das Datum) abgeprueft — _review_card.php rendert Start/Ende via
   * ViewHelper::formatDateTime().
   */
  async expectTimeReview(
    eventTitle: string,
    taskTitle: string,
    proposedTimeFragment?: string
  ): Promise<void> {
    const block = this.reviewBlock(eventTitle, taskTitle);
    await expect(block).toBeVisible();
    await expect(block).toContainText('Zeitfenster-Vorschlag');
    await expect(block).toContainText('Vorgeschlagen:');
    if (proposedTimeFragment) {
      await expect(block).toContainText(proposedTimeFragment);
    }
  }

  /**
   * Klickt den "Freigeben"-Button auf dem Review-Block fuer (eventTitle, taskTitle).
   * Fuer Storno-Anfragen mappt das auf POST /organizer/assignments/{id}/approve-cancel.
   */
  async approve(eventTitle: string, taskTitle: string): Promise<void> {
    const block = this.reviewBlock(eventTitle, taskTitle);
    await expect(block).toBeVisible();
    await block.getByRole('button', { name: /freigeben/i }).click();
    await this.page.waitForURL(/\/organizer\/events/);
  }

  /**
   * Oeffnet den Ablehnen-Collapse fuer (eventTitle, taskTitle), traegt eine
   * Pflicht-Begruendung ein und schickt das Formular ab.
   */
  async reject(eventTitle: string, taskTitle: string, reason: string): Promise<void> {
    const block = this.reviewBlock(eventTitle, taskTitle);
    await expect(block).toBeVisible();
    await block.getByRole('button', { name: /^ablehnen$/i }).click();
    const rejectForm = block.locator('form[action*="reject-"]');
    await rejectForm.locator('input[name="reason"]').fill(reason);
    await rejectForm.getByRole('button', { name: /ablehnen mit begruendung/i }).click();
    await this.page.waitForURL(/\/organizer\/events/);
  }
}
