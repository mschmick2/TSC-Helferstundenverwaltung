import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { MemberEventsPage } from '../pages/MemberEventsPage';
import { OrganizerEventsPage } from '../pages/OrganizerEventsPage';
import { EVENT_ADMIN, ALICE, BOB } from '../fixtures/users';

/**
 * Modul 8 — Inkrement 5: E2E Event-Storno + Ersatz-Vorschlag.
 *
 * Deckt den Absprung-Pfad ab:
 *   1. EVENT_ADMIN legt zukuenftiges Event an, Task fix, veroeffentlicht.
 *   2. ALICE uebernimmt die Task (auto-bestaetigt, weil slot_mode=fix).
 *   3. ALICE stellt Storno-Anfrage und schlaegt BOB als Ersatz vor.
 *   4. EVENT_ADMIN sieht die Anfrage mit Ersatz-Kontext in der Review-Queue
 *      und genehmigt sie.
 *   5. ALICE sieht den finalen Status "Storniert" in /my-events.
 *
 * Serial: alle Tests arbeiten auf demselben Event / derselben Assignment.
 */
test.describe.configure({ mode: 'serial' });

/**
 * 'YYYY-MM-DDTHH:mm' in lokaler Zeitzone fuer datetime-local-Inputs.
 */
function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

test.describe('Event-Storno — Anfrage mit Ersatz, Organizer genehmigt', () => {
  const now = new Date();
  // Event morgen 18:00 bis 20:00. Weit genug in der Zukunft, damit die
  // Cancel-Deadline (2h vor Start) nicht bereits beim Storno-Request
  // verletzt ist.
  const startDate = new Date(now.getTime() + 24 * 60 * 60 * 1000);
  startDate.setHours(18, 0, 0, 0);
  const endDate = new Date(startDate.getTime() + 2 * 60 * 60 * 1000);
  const startAt = toLocalDateTime(startDate);
  const endAt = toLocalDateTime(endDate);

  const eventTitle = `E2E Storno ${now.getTime()}`;
  const taskTitle = 'Einlasskontrolle';

  let eventId = 0;

  test('EVENT_ADMIN legt Event + Task an und veroeffentlicht', async ({ page }) => {
    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);

    eventId = await events.createEvent({
      title: eventTitle,
      description: 'E2E-Testlauf: Storno-Workflow mit Ersatz-Vorschlag',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);

    await events.addTask(eventId, {
      title: taskTitle,
      description: 'Eingang checken',
      slotMode: 'fix',
      capacityMode: 'unbegrenzt',
      hoursDefault: 2.0,
      taskType: 'aufgabe',
      taskStartAt: startAt,
      taskEndAt: endAt,
    });

    await events.gotoShow(eventId);
    await events.publish();
    await events.expectStatus('Veroeffentlicht');
  });

  test('ALICE uebernimmt Task und stellt Storno-Anfrage mit Ersatz=BOB', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const memberEvents = new MemberEventsPage(page);

    await login.loginAs(ALICE);
    await memberEvents.gotoShow(eventId);
    await memberEvents.assignToTask(taskTitle);

    // Bestaetigt-Status in /my-events pruefen, bevor wir Storno ausloesen.
    await memberEvents.gotoMyAssignments();
    await memberEvents.expectAssignmentStatus(eventTitle, 'Bestaetigt');

    // Storno-Anfrage mit BOB als Ersatz. Das Dropdown zeigt
    // "Nachname, Vorname" — wir matchen per Nachname-Substring.
    await memberEvents.requestCancellation(eventTitle, {
      reason: 'Kurzfristig verhindert',
      replacementLabel: BOB.nachname,
    });

    await memberEvents.expectAssignmentStatus(eventTitle, 'Storno angefragt');
  });

  test('EVENT_ADMIN sieht Anfrage mit Ersatz in Review-Queue und genehmigt', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const organizer = new OrganizerEventsPage(page);

    await login.loginAs(EVENT_ADMIN);
    await organizer.gotoIndex();

    // Der Review-Block muss "Storno-Anfrage" und "Vorgeschlagener Ersatz: Bob Mitglied"
    // enthalten — Organizer-UI rendert Vorname + Nachname.
    await organizer.expectCancelReviewWithReplacement(
      eventTitle,
      taskTitle,
      `${BOB.vorname} ${BOB.nachname}`
    );

    await organizer.approve(eventTitle, taskTitle);

    // Nach Approve steht der Eintrag nicht mehr in der Queue.
    const block = page
      .locator('div.border-start', { hasText: eventTitle })
      .filter({ hasText: taskTitle });
    await expect(block).toHaveCount(0);
  });

  test('ALICE sieht Status "Storniert" in /my-events', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const memberEvents = new MemberEventsPage(page);

    await login.loginAs(ALICE);
    await memberEvents.gotoMyAssignments();
    await memberEvents.expectAssignmentStatus(eventTitle, 'Storniert');
  });
});
