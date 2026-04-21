import { expect, Page } from '@playwright/test';

export interface NewEventInput {
  title: string;
  description?: string;
  location?: string;
  startAt: string; // 'YYYY-MM-DDTHH:mm' — datetime-local
  endAt: string;
  organizerEmail: string;
  cancelDeadlineHours?: number;
}

export interface NewEventTaskInput {
  title: string;
  description?: string;
  slotMode?: 'fix' | 'variabel';
  capacityMode?: 'unbegrenzt' | 'ziel' | 'maximum';
  capacityTarget?: number;
  hoursDefault?: number;
  taskType?: 'aufgabe' | 'beigabe';
  // Bei slot_mode='fix' Pflicht — Format 'YYYY-MM-DDTHH:mm'.
  taskStartAt?: string;
  taskEndAt?: string;
}

/**
 * Page-Object fuer /admin/events*.
 */
export class AdminEventsPage {
  constructor(private readonly page: Page) {}

  async gotoList(): Promise<void> {
    await this.page.goto('/admin/events');
    await expect(this.page.locator('h1')).toContainText('Events');
  }

  async gotoCreate(): Promise<void> {
    await this.page.goto('/admin/events/create');
    await expect(this.page.locator('input[name="title"]')).toBeVisible();
  }

  /**
   * Erstellt Event und gibt die Detail-URL-ID zurueck.
   */
  async createEvent(input: NewEventInput): Promise<number> {
    await this.gotoCreate();
    await this.page.locator('input[name="title"]').fill(input.title);
    if (input.description) {
      await this.page.locator('textarea[name="description"]').fill(input.description);
    }
    if (input.location) {
      await this.page.locator('input[name="location"]').fill(input.location);
    }
    if (input.cancelDeadlineHours !== undefined) {
      await this.page.locator('input[name="cancel_deadline_hours"]').fill(String(input.cancelDeadlineHours));
    }
    await this.page.locator('input[name="start_at"]').fill(input.startAt);
    await this.page.locator('input[name="end_at"]').fill(input.endAt);

    // Organisator per Email aus dem multi-select holen.
    const option = this.page.locator(
      `select[name="organizer_ids[]"] option`,
      { hasText: input.organizerEmail }
    );
    const value = await option.getAttribute('value');
    if (!value) {
      throw new Error(`Organisator nicht gefunden: ${input.organizerEmail}`);
    }
    await this.page.locator('select[name="organizer_ids[]"]').selectOption([value]);

    await this.page.getByRole('button', { name: /speichern/i }).click();

    await this.page.waitForURL(/\/admin\/events\/\d+$/);
    const match = this.page.url().match(/\/admin\/events\/(\d+)/);
    if (!match) {
      throw new Error(`Keine Event-ID im Redirect: ${this.page.url()}`);
    }
    return parseInt(match[1], 10);
  }

  async gotoShow(eventId: number): Promise<void> {
    await this.page.goto(`/admin/events/${eventId}`);
    await expect(this.page.locator('h1')).toBeVisible();
  }

  async expectStatus(label: 'Entwurf' | 'Veroeffentlicht' | 'Abgeschlossen' | 'Abgesagt'): Promise<void> {
    await expect(this.page.locator('h1').locator('..').locator('.badge').first()).toContainText(label);
  }

  async publish(): Promise<void> {
    await this.page.getByRole('button', { name: /veroeffentlichen/i }).click();
    await this.page.waitForURL(/\/admin\/events\/\d+$/);
  }

  async complete(): Promise<void> {
    // Complete hat ein confirm() — vorab auto-accepten.
    this.page.once('dialog', (dialog) => dialog.accept());
    await this.page.getByRole('button', { name: /event abschliessen/i }).click();
    await this.page.waitForURL(/\/admin\/events\/\d+$/);
  }

  async addTask(eventId: number, input: NewEventTaskInput): Promise<void> {
    await this.gotoShow(eventId);
    // Collapse oeffnen
    await this.page.getByRole('button', { name: /aufgabe hinzufuegen/i }).click();

    const form = this.page.locator(`form[action*="/admin/events/${eventId}/tasks"]`).first();
    await form.locator('input[name="title"]').fill(input.title);
    if (input.description) {
      await form.locator('textarea[name="description"]').fill(input.description);
    }
    if (input.slotMode) {
      await form.locator('select[name="slot_mode"]').selectOption(input.slotMode);
    }
    if (input.capacityMode) {
      await form.locator('select[name="capacity_mode"]').selectOption(input.capacityMode);
    }
    if (input.capacityTarget !== undefined) {
      await form.locator('input[name="capacity_target"]').fill(String(input.capacityTarget));
    }
    if (input.hoursDefault !== undefined) {
      await form.locator('input[name="hours_default"]').fill(String(input.hoursDefault));
    }
    if (input.taskType) {
      await form.locator('select[name="task_type"]').selectOption(input.taskType);
    }
    if (input.taskStartAt) {
      await form.locator('input[name="task_start_at"]').fill(input.taskStartAt);
    }
    if (input.taskEndAt) {
      await form.locator('input[name="task_end_at"]').fill(input.taskEndAt);
    }
    await form.getByRole('button', { name: /aufgabe anlegen/i }).click();
    await this.page.waitForURL(/\/admin\/events\/\d+/);
  }

  async expectTaskRow(title: string): Promise<void> {
    await expect(this.page.locator('table tbody')).toContainText(title);
  }

  async expectRowStatusInList(title: string, statusLabel: string): Promise<void> {
    await this.gotoList();
    const row = this.page.locator('tbody tr', { hasText: title });
    await expect(row.locator('.badge')).toContainText(statusLabel);
  }
}
