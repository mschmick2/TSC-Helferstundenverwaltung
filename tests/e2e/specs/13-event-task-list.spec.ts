import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { AdminEventTreePage } from '../pages/AdminEventTreePage';
import { EventTaskListPage } from '../pages/EventTaskListPage';
import { EVENT_ADMIN, BOB } from '../fixtures/users';
import { setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7b4 Phase 3 — Playwright-E2E fuer die sortierbare Task-Liste.
 *
 * Deckt ab:
 *   - Rendering in Admin- und Organizer-Kontext.
 *   - Chronologische Sortierung und Datums-Gruppierung.
 *   - "Ohne feste Zeitvorgabe"-Sektion fuer variable-Slot-Tasks.
 *   - Farbkodierung aus I7b3 greift auch hier.
 *   - Titel-Link-Kontext: Admin hat Link, Organizer nicht (G1-Entscheidung B).
 *   - Auth: Flag=0 liefert 404; Nicht-Organizer bekommt 403.
 *
 * Serial: alle Tests bauen auf demselben Setup-Event auf.
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

test.describe('Sortierbare Task-Liste (I7b4)', () => {
  const now = new Date();
  // Event-Fenster zwei Tage lang: Start morgen, Ende uebermorgen.
  const day1Start = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
  const day1End   = new Date(day1Start.getTime() + 12 * 60 * 60 * 1000);
  const day2Start = new Date(day1Start.getTime() + 24 * 60 * 60 * 1000);
  const day2End   = new Date(day2Start.getTime() + 4 * 60 * 60 * 1000);
  const eventStartAt = toLocalDateTime(day1Start);
  const eventEndAt   = toLocalDateTime(day2End);

  // Drei fix-Slot-Tasks an zwei verschiedenen Tagen + ein variable-Slot-Task.
  // Slot 1: Tag 1, 10:00-12:00 ("Aufbau")
  const slot1Start = toLocalDateTime(new Date(day1Start.getTime() + 60 * 60 * 1000));
  const slot1End   = toLocalDateTime(new Date(day1Start.getTime() + 3 * 60 * 60 * 1000));
  // Slot 2: Tag 1, 14:00-16:00 ("Mittagsschicht") — spaeter am gleichen Tag
  const slot2Start = toLocalDateTime(new Date(day1Start.getTime() + 5 * 60 * 60 * 1000));
  const slot2End   = toLocalDateTime(new Date(day1Start.getTime() + 7 * 60 * 60 * 1000));
  // Slot 3: Tag 2, 10:00-12:00 ("Abbau")
  const slot3Start = toLocalDateTime(new Date(day2Start.getTime() + 60 * 60 * 1000));
  const slot3End   = toLocalDateTime(new Date(day2Start.getTime() + 3 * 60 * 60 * 1000));

  const eventTitle = `E2E Task-Liste ${now.getTime()}`;
  let eventId = 0;

  test.beforeAll(() => {
    setE2eSetting('events.tree_editor_enabled', '1');
  });

  test.afterAll(() => {
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  // =========================================================================
  // Setup
  // =========================================================================

  test('Setup: Event mit gemischten Fix-/Variable-Slot-Tasks an zwei Tagen', async ({ page }) => {
    const login  = new LoginPage(page);
    const events = new AdminEventsPage(page);
    const tree   = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    eventId = await events.createEvent({
      title: eventTitle,
      description: 'Sortierbare Task-Liste E2E',
      location: 'Vereinsheim',
      startAt: eventStartAt,
      endAt:   eventEndAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);

    // Struktur:
    //   Tag1 (Gruppe)
    //     Aufbau      (fix, slot1 — ziel=2)
    //     Mittagsschicht (fix, slot2 — ziel=3)
    //   Abbau          (Top-Level, fix, slot3 — ziel=2)
    //   Kuchen backen  (Top-Level, variabel, unbegrenzt)
    await tree.gotoEdit(eventId);

    await tree.createTopLevelNode({ isGroup: true, title: 'Tag1' });
    await tree.createChildUnder('Tag1', {
      isGroup: false,
      title: 'Aufbau',
      slotMode: 'fix',
      startAt: slot1Start,
      endAt:   slot1End,
      capacityMode: 'ziel',
      capacityTarget: 2,
      hoursDefault: 2,
    });
    await tree.createChildUnder('Tag1', {
      isGroup: false,
      title: 'Mittagsschicht',
      slotMode: 'fix',
      startAt: slot2Start,
      endAt:   slot2End,
      capacityMode: 'ziel',
      capacityTarget: 3,
      hoursDefault: 2,
    });
    await tree.createTopLevelNode({
      isGroup: false,
      title: 'Abbau',
      slotMode: 'fix',
      startAt: slot3Start,
      endAt:   slot3End,
      capacityMode: 'ziel',
      capacityTarget: 2,
      hoursDefault: 2,
    });
    await tree.createTopLevelNode({
      isGroup: false,
      title: 'Kuchen backen',
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
    });
  });

  // =========================================================================
  // Admin-Kontext
  // =========================================================================

  test('Admin sieht Liste mit mindestens einem Datums-Header', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    await login.loginAs(EVENT_ADMIN);
    await list.gotoAdmin(eventId);
    await list.expectVisible();

    // Zwei Datums-Sektionen (Tag 1 und Tag 2) plus eine "Ohne feste
    // Zeitvorgabe" = drei Header.
    await expect(list.dateHeaders()).toHaveCount(3);
  });

  test('Tasks erscheinen in chronologischer Reihenfolge', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    await login.loginAs(EVENT_ADMIN);
    await list.gotoAdmin(eventId);

    // Reihenfolge im DOM: Aufbau (Tag1 10:00) → Mittagsschicht (Tag1 14:00)
    // → Abbau (Tag2 10:00) → Kuchen backen (Ohne feste Zeit).
    const texts = await list.items().allTextContents();
    const titles = texts.map((t) => {
      if (t.includes('Aufbau')) return 'Aufbau';
      if (t.includes('Mittagsschicht')) return 'Mittagsschicht';
      if (t.includes('Abbau')) return 'Abbau';
      if (t.includes('Kuchen backen')) return 'Kuchen backen';
      return '?';
    });

    expect(titles).toEqual(['Aufbau', 'Mittagsschicht', 'Abbau', 'Kuchen backen']);
  });

  test('Tasks werden nach Datum gruppiert (Tag1 und Tag2 in separaten Sektionen)', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    await login.loginAs(EVENT_ADMIN);
    await list.gotoAdmin(eventId);

    // Tag1-Sektion enthaelt Aufbau und Mittagsschicht, Tag2-Sektion nur
    // Abbau. Die erste .task-list-date-section betrifft Tag 1.
    const firstSection = page.locator('.task-list-date-section').first();
    await expect(firstSection.locator('.task-list-item')).toHaveCount(2);

    // Die zweite Sektion (Tag 2) hat genau einen Task.
    const secondSection = page.locator('.task-list-date-section').nth(1);
    await expect(secondSection.locator('.task-list-item')).toHaveCount(1);
  });

  test('Variable-Slot-Task erscheint in eigener "Ohne feste Zeitvorgabe"-Sektion', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    await login.loginAs(EVENT_ADMIN);
    await list.gotoAdmin(eventId);

    await expect(list.noTimeSection()).toBeVisible();
    await expect(list.noTimeSection()).toContainText('Ohne feste Zeitvorgabe');
    // In der Sektion sitzt genau der Kuchen-backen-Task.
    await expect(list.noTimeItems()).toHaveCount(1);
    await expect(list.noTimeItems().first()).toContainText('Kuchen backen');
  });

  test('Farbkodierung aus I7b3 greift an leerer Aufgabe', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    await login.loginAs(EVENT_ADMIN);
    await list.gotoAdmin(eventId);

    // Aufbau: ziel=2, 0 Zusagen → EMPTY (rot).
    await expect(list.itemByTitle('Aufbau')).toHaveClass(/task-status-empty/);
    await expect(
      list.itemByTitle('Aufbau').locator('.task-status-badge--empty').first()
    ).toContainText(/keine Zusage/);
  });

  test('Admin-Kontext: Task-Titel als Link auf Admin-Event-Detail', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    await login.loginAs(EVENT_ADMIN);
    await list.gotoAdmin(eventId);

    await list.expectTitleLinked('Aufbau', `/admin/events/${eventId}`);
  });

  // =========================================================================
  // Organizer-Kontext
  // =========================================================================

  test('Organizer (=Event-Admin als Owner) sieht dieselbe Liste ohne Titel-Links', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    // EVENT_ADMIN ist laut createEvent auch Organisator dieses Events.
    await login.loginAs(EVENT_ADMIN);
    await list.gotoOrganizer(eventId);
    await list.expectVisible();

    // Gleiche Struktur wie Admin-Kontext — 3 Datums-Header, 4 Items.
    await expect(list.dateHeaders()).toHaveCount(3);
    await expect(list.items()).toHaveCount(4);

    // Aber: Titel sind NICHT als Link gerendert.
    await list.expectTitleNotLinked('Aufbau');
    await list.expectTitleNotLinked('Mittagsschicht');
    await list.expectTitleNotLinked('Abbau');
    await list.expectTitleNotLinked('Kuchen backen');
  });

  // =========================================================================
  // Auth-Regressionen
  // =========================================================================

  test('Fremder Nutzer bekommt 403 auf Organizer-Route', async ({ page }) => {
    const login = new LoginPage(page);
    const list  = new EventTaskListPage(page);

    // BOB ist kein Organisator dieses Events — muss 403 bekommen.
    await login.loginAs(BOB);
    const response = await list.requestOrganizer(eventId);
    expect(response.status()).toBe(403);
  });

  test('Flag=0 liefert 404 auf beiden Routen', async ({ page }) => {
    setE2eSetting('events.tree_editor_enabled', '0');
    try {
      const login = new LoginPage(page);
      const list  = new EventTaskListPage(page);

      await login.loginAs(EVENT_ADMIN);

      const adminResponse = await list.requestAdmin(eventId);
      expect(adminResponse.status()).toBe(404);

      const organizerResponse = await list.requestOrganizer(eventId);
      expect(organizerResponse.status()).toBe(404);
    } finally {
      // Flag wieder an, damit afterAll nicht auf einem erwarteten 0 steht.
      setE2eSetting('events.tree_editor_enabled', '1');
    }
  });
});
