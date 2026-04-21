import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { MemberEventsPage } from '../pages/MemberEventsPage';
import { OrganizerEventsPage } from '../pages/OrganizerEventsPage';
import { EVENT_ADMIN, ALICE } from '../fixtures/users';

/**
 * Modul 8 — Inkrement 6: E2E Event mit variablem Zeitfenster.
 *
 * Flow:
 *   1. EVENT_ADMIN legt Event + Task (slot_mode=variabel) an, veroeffentlicht.
 *   2. ALICE schlaegt ein Zeitfenster innerhalb des Events vor
 *      → Status "vorgeschlagen" (noch nicht bestaetigt).
 *   3. EVENT_ADMIN sieht den Vorschlag in der Review-Queue
 *      (Zeitfenster-Vorschlag mit Start/Ende-Anzeige) und genehmigt.
 *   4. ALICE sieht Status "Bestaetigt" in /my-events.
 *
 * Serial: alle Tests arbeiten auf derselben Assignment.
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

function toGermanDate(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}`;
}

test.describe('Event variabel-Slot — ALICE schlaegt Zeit vor, Organizer bestaetigt', () => {
  const now = new Date();
  // Event morgen 18:00 bis 20:00 (Deadline-Risiko irrelevant bei variabel).
  const eventStart = new Date(now.getTime() + 24 * 60 * 60 * 1000);
  eventStart.setHours(18, 0, 0, 0);
  const eventEnd = new Date(eventStart.getTime() + 2 * 60 * 60 * 1000);
  // ALICE schlaegt ein engeres Fenster innerhalb des Events vor: 18:30 — 19:30.
  const proposedStart = new Date(eventStart.getTime() + 30 * 60 * 1000);
  const proposedEnd = new Date(eventStart.getTime() + 90 * 60 * 1000);

  const eventTitle = `E2E VarSlot ${now.getTime()}`;
  const taskTitle = 'Betreuung';

  let eventId = 0;

  test('EVENT_ADMIN legt Event + Task (variabel) an und veroeffentlicht', async ({ page }) => {
    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);

    eventId = await events.createEvent({
      title: eventTitle,
      description: 'E2E-Testlauf: variabler Zeitfenster-Vorschlag',
      location: 'Vereinsheim',
      startAt: toLocalDateTime(eventStart),
      endAt: toLocalDateTime(eventEnd),
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);

    // Task ohne fixen Slot — Zeitfenster handelt das Mitglied selbst aus.
    await events.addTask(eventId, {
      title: taskTitle,
      description: 'Helfer fuer flexible Slots',
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1.0,
      taskType: 'aufgabe',
    });

    await events.gotoShow(eventId);
    await events.publish();
    await events.expectStatus('Veroeffentlicht');
  });

  test('ALICE schlaegt Zeitfenster vor — Status "Zeitfenster vorgeschlagen"', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const memberEvents = new MemberEventsPage(page);

    await login.loginAs(ALICE);
    await memberEvents.gotoShow(eventId);

    await memberEvents.assignToTaskWithProposal(
      taskTitle,
      toLocalDateTime(proposedStart),
      toLocalDateTime(proposedEnd)
    );

    // Im /my-events-Listing steht die Zusage auf "Zeitfenster vorgeschlagen"
    // — bis der Organisator entschieden hat.
    await memberEvents.gotoMyAssignments();
    await memberEvents.expectAssignmentStatus(eventTitle, 'Zeitfenster vorgeschlagen');
  });

  test('EVENT_ADMIN sieht Zeit-Vorschlag in Review-Queue und genehmigt', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const organizer = new OrganizerEventsPage(page);

    await login.loginAs(EVENT_ADMIN);
    await organizer.gotoIndex();

    // Review-Card muss "Zeitfenster-Vorschlag" + "Vorgeschlagen: <Start> - <Ende>"
    // zeigen. Wir verankern uns am Datum (d.m.Y), die Stunden-Anzeige ist
    // lokalzeit-abhaengig und daher kein robustes Fragment fuer den Test.
    await organizer.expectTimeReview(eventTitle, taskTitle, toGermanDate(proposedStart));

    await organizer.approve(eventTitle, taskTitle);

    // Nach Approve nicht mehr in der Queue.
    const block = page
      .locator('div.border-start', { hasText: eventTitle })
      .filter({ hasText: taskTitle });
    await expect(block).toHaveCount(0);
  });

  test('ALICE sieht Status "Bestaetigt" in /my-events', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const memberEvents = new MemberEventsPage(page);

    await login.loginAs(ALICE);
    await memberEvents.gotoMyAssignments();
    await memberEvents.expectAssignmentStatus(eventTitle, 'Bestaetigt');
  });
});
