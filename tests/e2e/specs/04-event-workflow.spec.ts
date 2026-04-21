import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { MemberEventsPage } from '../pages/MemberEventsPage';
import { WorkEntryListPage } from '../pages/WorkEntryListPage';
import { EVENT_ADMIN, ALICE } from '../fixtures/users';

/**
 * Modul 8 — Inkrement 4: E2E Event-Komplettflow.
 *
 * Deckt den end-to-end Event-Lebenszyklus ab:
 *   1. EVENT_ADMIN legt Event in der Vergangenheit an (damit Abschluss moeglich ist).
 *   2. EVENT_ADMIN fuegt eine fixe Task mit Slot-Zeiten hinzu.
 *   3. EVENT_ADMIN veroeffentlicht; ALICE uebernimmt die Task (auto-bestaetigt).
 *   4. EVENT_ADMIN schliesst das Event ab — EventCompletionService erzeugt
 *      work_entries fuer alle bestaetigten Zusagen.
 *   5. ALICE sieht den automatisch erzeugten Antrag in /entries.
 *
 * Serial, weil alle Tests auf demselben Event arbeiten (eventId wird zwischen
 * Tests via outer let-Variable geteilt).
 */
test.describe.configure({ mode: 'serial' });

/**
 * Formatiert ein Date als datetime-local-String 'YYYY-MM-DDTHH:mm' in
 * lokaler Zeitzone (wie der Browser es bei type="datetime-local" sendet).
 */
function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

test.describe('Event-Komplettflow — Admin legt an, Alice uebernimmt, Admin schliesst ab', () => {
  const now = new Date();
  // Event-Fenster komplett in der Vergangenheit: 2h vor jetzt bis 1h vor jetzt.
  const startAt = toLocalDateTime(new Date(now.getTime() - 2 * 60 * 60 * 1000));
  const endAt = toLocalDateTime(new Date(now.getTime() - 1 * 60 * 60 * 1000));
  const eventTitle = `E2E Event ${now.getTime()}`;
  const taskTitle = 'Getraenkeausgabe';

  let eventId = 0;

  test('EVENT_ADMIN legt Event mit vergangener Laufzeit an', async ({ page }) => {
    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);

    eventId = await events.createEvent({
      title: eventTitle,
      description: 'E2E-Testlauf: Kompletter Event-Workflow',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });

    expect(eventId).toBeGreaterThan(0);
    await events.gotoShow(eventId);
    await events.expectStatus('Entwurf');
  });

  test('EVENT_ADMIN fuegt Task mit fixem Slot hinzu', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);
    await events.addTask(eventId, {
      title: taskTitle,
      description: 'Theke bemannen waehrend des Events',
      slotMode: 'fix',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1.0,
      taskType: 'aufgabe',
      taskStartAt: startAt,
      taskEndAt: endAt,
    });

    await events.gotoShow(eventId);
    await events.expectTaskRow(taskTitle);
  });

  test('EVENT_ADMIN veroeffentlicht, ALICE uebernimmt die Task', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const adminEvents = new AdminEventsPage(page);
    const memberEvents = new MemberEventsPage(page);

    // 1) Admin veroeffentlicht
    await login.loginAs(EVENT_ADMIN);
    await adminEvents.gotoShow(eventId);
    await adminEvents.publish();
    await adminEvents.expectStatus('Veroeffentlicht');

    // 2) Admin ausloggen, Alice einloggen
    await login.logout();
    await login.loginAs(ALICE);

    // 3) Alice uebernimmt die Task direkt auf der Event-Detail-Seite.
    //    /events (Liste) filtert auf zukuenftige Events (end_at >= NOW),
    //    unser Test-Event liegt absichtlich in der Vergangenheit, damit
    //    der Abschluss moeglich ist — die Detail-Route ist aber erreichbar.
    await memberEvents.gotoShow(eventId);
    await memberEvents.assignToTask(taskTitle);

    // 4) Nach dem Uebernehmen steht die Task auf "Bereits zugesagt"
    await memberEvents.gotoShow(eventId);
    await memberEvents.expectAssignmentBadge(taskTitle);
  });

  test('EVENT_ADMIN schliesst Event ab — automatischer Antrag wird erzeugt', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);
    await events.gotoShow(eventId);

    // Event-Ende liegt in der Vergangenheit → Abschliessen-Button ist sichtbar.
    await events.complete();
    await events.expectStatus('Abgeschlossen');
  });

  test('ALICE sieht automatisch erzeugten Antrag in /entries', async ({ page }) => {
    expect(eventId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const list = new WorkEntryListPage(page);

    await login.loginAs(ALICE);
    await list.goto();

    // Die Listenansicht zeigt keine Beschreibung. Wir oeffnen den obersten
    // Eintrag (sort=created_at&dir=DESC → der frisch erzeugte steht oben)
    // und pruefen die Herkunfts-Zeile.
    const topId = await list.topEntryId();
    await page.goto(`/entries/${topId}`);

    // EventCompletionService schreibt:
    //   'Automatisch erzeugt aus Event "<title>" / Aufgabe "<task>"'
    // Als robusten Anker nehmen wir den einmaligen Event-Titel.
    await expect(page.locator('body')).toContainText(eventTitle);
    await expect(page.locator('body')).toContainText(taskTitle);
  });
});
