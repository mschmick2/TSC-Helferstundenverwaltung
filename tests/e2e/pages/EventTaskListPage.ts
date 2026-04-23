import { expect, Locator, Page, APIResponse } from '@playwright/test';

/**
 * Page-Object fuer die sortierbare Task-Liste (Modul 6 I7b4).
 *
 * Deckt beide Routen ab:
 *   - /admin/events/{id}/tasks-by-date       (event_admin-Kontext, Titel-Links)
 *   - /organizer/events/{id}/tasks-by-date   (Organizer-Kontext, ohne Links)
 *
 * Die Page-Object-Methoden sind kontext-neutral; der Caller waehlt pro Test,
 * welche Route er besucht.
 */
export class EventTaskListPage {
  constructor(private page: Page) {}

  // =========================================================================
  // Navigation
  // =========================================================================

  async gotoAdmin(eventId: number): Promise<void> {
    await this.page.goto(`/admin/events/${eventId}/tasks-by-date`);
  }

  async gotoOrganizer(eventId: number): Promise<void> {
    await this.page.goto(`/organizer/events/${eventId}/tasks-by-date`);
  }

  /**
   * Direkter HTTP-GET ueber APIRequestContext der Page — umgeht den Browser-
   * Redirect-Handler. Nutzt die aktive Login-Cookie-Session der Page.
   * Liefert eine APIResponse; der Caller prueft status().
   */
  async requestAdmin(eventId: number): Promise<APIResponse> {
    return this.page.request.get(`/admin/events/${eventId}/tasks-by-date`);
  }

  async requestOrganizer(eventId: number): Promise<APIResponse> {
    return this.page.request.get(`/organizer/events/${eventId}/tasks-by-date`);
  }

  // =========================================================================
  // Struktur-Checks
  // =========================================================================

  /**
   * Alle Datums-Header-Elemente (eine pro YYYY-MM-DD-Gruppe + ggf. Sektion
   * "Ohne feste Zeitvorgabe" am Ende).
   */
  dateHeaders(): Locator {
    return this.page.locator('.task-list-date-header');
  }

  /**
   * Die Sektion mit variable-Slot-Tasks.
   */
  noTimeSection(): Locator {
    return this.page.locator('.task-list-no-time');
  }

  /**
   * Alle Task-Items in der Liste (fix + variable).
   */
  items(): Locator {
    return this.page.locator('.task-list-item');
  }

  /**
   * Items innerhalb der "Ohne feste Zeitvorgabe"-Sektion.
   */
  noTimeItems(): Locator {
    return this.page.locator('.task-list-no-time .task-list-item');
  }

  /**
   * Item, das einen bestimmten Task-Titel enthaelt.
   */
  itemByTitle(title: string): Locator {
    return this.page.locator('.task-list-item', { hasText: title });
  }

  // =========================================================================
  // Assertions
  // =========================================================================

  async expectVisible(): Promise<void> {
    // Mindestens ein Datums-Header ODER die Empty-State-Nachricht.
    await expect(
      this.page.locator('.task-list-date-header, p:has-text("Keine Aufgaben")')
    ).not.toHaveCount(0);
  }

  async expectTitleNotLinked(title: string): Promise<void> {
    const item = this.itemByTitle(title);
    await expect(item).toBeVisible();
    // Im Organizer-Kontext ist <strong> OHNE umschliessenden <a>.
    await expect(item.locator('strong > a')).toHaveCount(0);
  }

  async expectTitleLinked(title: string, href: string | RegExp): Promise<void> {
    const item = this.itemByTitle(title);
    await expect(item).toBeVisible();
    const link = item.locator('strong a');
    await expect(link).toHaveAttribute('href', href);
  }
}
