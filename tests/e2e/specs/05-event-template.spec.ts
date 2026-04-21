import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventTemplatesPage } from '../pages/AdminEventTemplatesPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { EVENT_ADMIN } from '../fixtures/users';

/**
 * Modul 8 — Inkrement 4b: E2E Event-Template-Ableitung.
 *
 * Deckt den Template-Workflow ab:
 *   1. EVENT_ADMIN legt ein Template an.
 *   2. EVENT_ADMIN fuegt dem Template eine Task-Vorlage hinzu.
 *   3. EVENT_ADMIN leitet aus dem Template ein neues Event ab
 *      — das erzeugte Event traegt die "aus Template"-Markierung.
 *
 * Serial, weil alle Tests auf derselben Template-ID arbeiten.
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

test.describe('Event-Templates — Anlegen, Task ergaenzen, Event ableiten', () => {
  const now = new Date();
  const templateName = `E2E Template ${now.getTime()}`;
  const templateTaskTitle = 'Aufbau';

  let templateId = 0;
  let derivedEventId = 0;

  test('EVENT_ADMIN legt Template an', async ({ page }) => {
    const login = new LoginPage(page);
    const templates = new AdminEventTemplatesPage(page);

    await login.loginAs(EVENT_ADMIN);

    templateId = await templates.createTemplate({
      name: templateName,
      description: 'E2E-Testlauf: Template-Ableitungsflow',
    });

    expect(templateId).toBeGreaterThan(0);
  });

  test('EVENT_ADMIN ergaenzt Task-Vorlage (Slot=fix benoetigt Offset)', async ({ page }) => {
    expect(templateId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const templates = new AdminEventTemplatesPage(page);

    await login.loginAs(EVENT_ADMIN);
    // Slot=fix erfordert default_offset_minutes_start/_end (Pruefung in
    // EventTemplateService::validate()). Null und 60 spannen eine Stunde
    // ab Event-Start auf.
    await templates.addTask(templateId, {
      title: templateTaskTitle,
      description: 'Tische und Stuehle aufstellen',
      slotMode: 'fix',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1.5,
      taskType: 'aufgabe',
      offsetStartMinutes: 0,
      offsetEndMinutes: 60,
    });

    await templates.gotoEdit(templateId);
    await templates.expectTaskListed(templateTaskTitle);
  });

  test('EVENT_ADMIN leitet Event aus Template ab — Badge "aus Template" sichtbar', async ({ page }) => {
    expect(templateId).toBeGreaterThan(0);

    const login = new LoginPage(page);
    const templates = new AdminEventTemplatesPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);

    // Zukuenftiges Fenster: morgen 18:00 bis morgen 20:00.
    const start = new Date(now.getTime() + 24 * 60 * 60 * 1000);
    start.setHours(18, 0, 0, 0);
    const end = new Date(start.getTime() + 2 * 60 * 60 * 1000);

    const eventTitle = `Abgeleitet ${now.getTime()}`;
    derivedEventId = await templates.deriveEvent(templateId, {
      title: eventTitle,
      startAt: toLocalDateTime(start),
      endAt: toLocalDateTime(end),
      location: 'Vereinsheim',
      description: 'Aus Template abgeleitet fuer E2E-Test',
      cancelDeadlineHours: 24,
    });

    expect(derivedEventId).toBeGreaterThan(0);

    // Das erzeugte Event laeuft in der Detail-Ansicht mit dem
    // "aus Template"-Badge + Verweis auf den Quell-Template-Namen.
    await events.gotoShow(derivedEventId);
    await events.expectStatus('Entwurf');
    await expect(page.locator('body')).toContainText('aus Template');
    await expect(page.locator('body')).toContainText(templateName);
    // Die Task-Vorlage wurde als Snapshot uebernommen.
    await events.expectTaskRow(templateTaskTitle);
  });
});
