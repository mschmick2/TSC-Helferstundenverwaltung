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

  /**
   * Fuer slot_mode='variabel': Mitglied schlaegt ein Zeitfenster vor.
   * Fuellt proposed_start und proposed_end aus und schickt das Formular ab.
   * Status der Zusage ist danach "vorgeschlagen" — Organisator muss pruefen.
   */
  async assignToTaskWithProposal(
    taskTitle: string,
    proposedStart: string,
    proposedEnd: string
  ): Promise<void> {
    const card = this.page.locator('.card', { has: this.page.locator('h3', { hasText: taskTitle }) });
    await expect(card).toBeVisible();
    await card.locator('input[name="proposed_start"]').fill(proposedStart);
    await card.locator('input[name="proposed_end"]').fill(proposedEnd);
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

  /**
   * Oeffnet den Storno-Collapse in der /my-events-Zeile mit `eventTitle`,
   * waehlt optional einen Ersatz per sichtbarer Nachname-Vorname-Option und
   * schickt ab. Wirft, wenn die Zeile nicht existiert oder keinen Storno-Button hat
   * (z. B. wenn die Zusage noch "vorgeschlagen" oder bereits "storno angefragt" ist).
   */
  async requestCancellation(
    eventTitle: string,
    options: { reason?: string; replacementLabel?: string } = {}
  ): Promise<void> {
    await this.page.goto('/my-events');
    await expect(this.page.locator('h1')).toContainText('Meine Zusagen');

    const row = this.page.locator('tbody tr', { hasText: eventTitle }).first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: /storno/i }).click();

    // Der Collapse-tr folgt direkt auf die Event-Zeile.
    const form = this.page.locator('form[action*="/my-events/assignments/"][action$="/cancel"]').first();
    await expect(form).toBeVisible();

    if (options.reason) {
      await form.locator('input[name="reason"]').fill(options.reason);
    }
    if (options.replacementLabel) {
      const option = form.locator('select[name="replacement_user_id"] option', {
        hasText: options.replacementLabel,
      });
      const value = await option.first().getAttribute('value');
      if (!value) {
        throw new Error(`Ersatz-Kandidat nicht gefunden: ${options.replacementLabel}`);
      }
      await form.locator('select[name="replacement_user_id"]').selectOption(value);
    }

    await form.getByRole('button', { name: /storno-anfrage senden/i }).click();
    await this.page.waitForURL(/\/my-events/);
  }

  /**
   * Verifiziert das Status-Badge in der /my-events-Tabelle fuer die Zeile mit `eventTitle`.
   */
  async expectAssignmentStatus(
    eventTitle: string,
    statusLabel: 'Bestaetigt' | 'Storno angefragt' | 'Storniert' | 'Zeitfenster vorgeschlagen'
  ): Promise<void> {
    const row = this.page.locator('tbody tr', { hasText: eventTitle }).first();
    await expect(row.locator('.badge').first()).toContainText(statusLabel);
  }
}
