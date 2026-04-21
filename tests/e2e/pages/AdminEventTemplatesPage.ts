import { expect, Page } from '@playwright/test';

export interface NewTemplateInput {
  name: string;
  description?: string;
}

export interface NewTemplateTaskInput {
  title: string;
  description?: string;
  slotMode?: 'fix' | 'variabel';
  capacityMode?: 'unbegrenzt' | 'ziel' | 'maximum';
  capacityTarget?: number;
  hoursDefault?: number;
  taskType?: 'aufgabe' | 'beigabe';
  offsetStartMinutes?: number;
  offsetEndMinutes?: number;
}

export interface DeriveEventInput {
  title: string;
  startAt: string; // 'YYYY-MM-DDTHH:mm'
  endAt: string;
  location?: string;
  description?: string;
  cancelDeadlineHours?: number;
}

/**
 * Page-Object fuer /admin/event-templates*.
 */
export class AdminEventTemplatesPage {
  constructor(private readonly page: Page) {}

  async gotoList(): Promise<void> {
    await this.page.goto('/admin/event-templates');
    await expect(this.page.locator('h1')).toContainText('Event-Templates');
  }

  /**
   * Legt ein Template an und gibt die ID zurueck.
   */
  async createTemplate(input: NewTemplateInput): Promise<number> {
    await this.gotoList();
    // Collapse oeffnen
    await this.page.getByRole('button', { name: /neues template/i }).click();

    const form = this.page.locator('form[action$="/admin/event-templates"]').first();
    await form.locator('input[name="name"]').fill(input.name);
    if (input.description) {
      await form.locator('input[name="description"]').fill(input.description);
    }
    await form.getByRole('button', { name: /anlegen/i }).click();

    // Redirect auf /admin/event-templates/{id}/edit (nach store)
    await this.page.waitForURL(/\/admin\/event-templates\/\d+(\/edit)?$/);
    const match = this.page.url().match(/\/admin\/event-templates\/(\d+)/);
    if (!match) {
      throw new Error(`Keine Template-ID im Redirect: ${this.page.url()}`);
    }
    return parseInt(match[1], 10);
  }

  async gotoShow(templateId: number): Promise<void> {
    await this.page.goto(`/admin/event-templates/${templateId}`);
    await expect(this.page.locator('h1')).toBeVisible();
  }

  async gotoEdit(templateId: number): Promise<void> {
    await this.page.goto(`/admin/event-templates/${templateId}/edit`);
    await expect(this.page.locator('h1')).toBeVisible();
  }

  async addTask(templateId: number, input: NewTemplateTaskInput): Promise<void> {
    await this.gotoEdit(templateId);
    // Collapse oeffnen
    await this.page.getByRole('button', { name: /neue task/i }).click();

    const form = this.page.locator(`form[action$="/admin/event-templates/${templateId}/tasks"]`).first();
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
    if (input.offsetStartMinutes !== undefined) {
      await form.locator('input[name="default_offset_minutes_start"]').fill(String(input.offsetStartMinutes));
    }
    if (input.offsetEndMinutes !== undefined) {
      await form.locator('input[name="default_offset_minutes_end"]').fill(String(input.offsetEndMinutes));
    }
    await form.getByRole('button', { name: /task hinzufuegen/i }).click();
    await this.page.waitForURL(/\/admin\/event-templates\/\d+/);
  }

  async expectTaskListed(title: string): Promise<void> {
    await expect(this.page.locator('body')).toContainText(title);
  }

  /**
   * Leitet aus dem Template ein neues Event ab. Liefert die neue Event-ID.
   */
  async deriveEvent(templateId: number, input: DeriveEventInput): Promise<number> {
    await this.page.goto(`/admin/event-templates/${templateId}/derive`);
    await expect(this.page.locator('input[name="title"]')).toBeVisible();

    await this.page.locator('input[name="title"]').fill(input.title);
    await this.page.locator('input[name="start_at"]').fill(input.startAt);
    await this.page.locator('input[name="end_at"]').fill(input.endAt);
    if (input.location) {
      await this.page.locator('input[name="location"]').fill(input.location);
    }
    if (input.description) {
      await this.page.locator('textarea[name="description"]').fill(input.description);
    }
    if (input.cancelDeadlineHours !== undefined) {
      await this.page.locator('input[name="cancel_deadline_hours"]').fill(String(input.cancelDeadlineHours));
    }

    await this.page.getByRole('button', { name: /event erzeugen/i }).click();
    await this.page.waitForURL(/\/admin\/events\/\d+/);

    const match = this.page.url().match(/\/admin\/events\/(\d+)/);
    if (!match) {
      throw new Error(`Keine Event-ID nach Ableitung: ${this.page.url()}`);
    }
    return parseInt(match[1], 10);
  }
}
