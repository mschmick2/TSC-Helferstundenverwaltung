import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';
import * as path from 'path';
import * as fs from 'fs';
import { LoginPage } from '../pages/LoginPage';
import { ADMIN, ALICE, PRUEFER, SeedUser } from '../fixtures/users';

/**
 * Handbuch-Screenshots (Modul H — docs/Benutzerhandbuch.md v1.4.0).
 *
 * Laeuft NICHT im normalen Test-Run. Nur via eigenem Project `screenshots`:
 *   npx playwright test --project=screenshots
 *
 * Der globalSetup der Suite baut die E2E-DB frisch auf; diese Spec seedet
 * anschliessend `scripts/seed-handbuch-demodata.php` nach, damit Demo-Daten
 * (4 Eintraege Alice, Dialog, Event Sommerfest, Vorlage) existieren.
 *
 * Ergebnis: docs/images/handbuch/*.png
 */

const repoRoot = path.resolve(__dirname, '..', '..', '..');
const shotsDir = path.join(repoRoot, 'docs', 'images', 'handbuch');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  if (!fs.existsSync(shotsDir)) {
    fs.mkdirSync(shotsDir, { recursive: true });
  }
  const seed = path.join(repoRoot, 'scripts', 'seed-handbuch-demodata.php');
  // eslint-disable-next-line no-console
  console.log('[shots] Seeding Handbuch-Demodaten ...');
  execSync(`php "${seed}"`, { stdio: 'inherit', cwd: repoRoot });
});

async function shot(page: Page, name: string): Promise<void> {
  // Kleiner Puffer fuer noch laufende Fonts/Icons.
  await page.waitForTimeout(200);
  await page.screenshot({
    path: path.join(shotsDir, name + '.png'),
    fullPage: true,
  });
}

async function login(page: Page, user: SeedUser): Promise<void> {
  const lp = new LoginPage(page);
  await lp.loginAs(user);
}

async function logout(page: Page): Promise<void> {
  await page.goto('/logout');
  await page.waitForURL((url) => url.pathname.startsWith('/login'));
}

/**
 * Findet die Entry-ID zu einem entry_number (z.B. "2026-00001") ueber die
 * Eintragsliste. Die Listen-Tabelle zeigt die Nummer als Link-Text; wir lesen
 * die numerische ID aus dem href aus.
 */
async function findEntryIdByNumber(page: Page, entryNumber: string): Promise<number> {
  await page.goto('/entries?sort=created_at&dir=DESC');
  const link = page.locator(`tbody tr a[href*="/entries/"]`, { hasText: entryNumber }).first();
  const href = await link.getAttribute('href');
  const match = href?.match(/\/entries\/(\d+)/);
  if (!match) throw new Error(`Entry mit Nummer ${entryNumber} nicht gefunden`);
  return Number(match[1]);
}

async function findAdminEventIdByTitle(page: Page, title: string): Promise<number> {
  await page.goto('/admin/events');
  const row = page.locator('tbody tr', { hasText: title }).first();
  const link = row.locator('a[href*="/admin/events/"]').first();
  const href = await link.getAttribute('href');
  const match = href?.match(/\/admin\/events\/(\d+)/);
  if (!match) throw new Error(`Event ${title} nicht gefunden`);
  return Number(match[1]);
}

async function findAdminTemplateIdByName(page: Page, name: string): Promise<number> {
  await page.goto('/admin/event-templates');
  const row = page.locator('tbody tr', { hasText: name }).first();
  const link = row.locator('a[href*="/admin/event-templates/"]').first();
  const href = await link.getAttribute('href');
  const match = href?.match(/\/admin\/event-templates\/(\d+)/);
  if (!match) throw new Error(`Template ${name} nicht gefunden`);
  return Number(match[1]);
}

async function findAdminUserIdByEmail(page: Page, email: string): Promise<number> {
  await page.goto('/admin/users');
  const row = page.locator('tbody tr', { hasText: email }).first();
  const link = row.locator('a[href^="/admin/users/"]').first();
  const href = await link.getAttribute('href');
  const match = href?.match(/\/admin\/users\/(\d+)/);
  if (!match) throw new Error(`User ${email} nicht gefunden`);
  return Number(match[1]);
}

// ==========================================================================
// Oeffentliche Seiten (kein Login)
// ==========================================================================
test.describe('Handbuch — oeffentliche Seiten', () => {
  test('01-login', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('form')).toBeVisible();
    await shot(page, '01-login');
  });

  test('02-forgot-password', async ({ page }) => {
    await page.goto('/forgot-password');
    await expect(page.locator('form')).toBeVisible();
    await shot(page, '02-forgot-password');
  });
});

// ==========================================================================
// Mitglied (Alice)
// ==========================================================================
test.describe('Handbuch — Mitglied (Alice)', () => {
  test('10-dashboard-mitglied', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/dashboard');
    await expect(page.locator('h1, h2').first()).toBeVisible();
    await shot(page, '10-dashboard-mitglied');
  });

  test('11-antragsliste', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/entries?sort=created_at&dir=DESC');
    await expect(page.locator('h1')).toContainText('Meine Arbeitsstunden');
    await shot(page, '11-antragsliste');
  });

  test('12-antrag-neu', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/entries/create');
    await expect(page.locator('input[name="work_date"]')).toBeVisible();
    await shot(page, '12-antrag-neu');
  });

  test('13-antrag-detail-entwurf', async ({ page }) => {
    await login(page, ALICE);
    const year = new Date().getFullYear();
    const id = await findEntryIdByNumber(page, `${year}-00001`);
    await page.goto(`/entries/${id}`);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '13-antrag-detail-entwurf');
  });

  test('14-antrag-detail-eingereicht', async ({ page }) => {
    await login(page, ALICE);
    const year = new Date().getFullYear();
    const id = await findEntryIdByNumber(page, `${year}-00002`);
    await page.goto(`/entries/${id}`);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '14-antrag-detail-eingereicht');
  });

  test('15-antrag-detail-in-klaerung-dialog', async ({ page }) => {
    await login(page, ALICE);
    const year = new Date().getFullYear();
    const id = await findEntryIdByNumber(page, `${year}-00003`);
    await page.goto(`/entries/${id}`);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '15-antrag-detail-in-klaerung-dialog');
  });

  test('16-antrag-detail-freigegeben', async ({ page }) => {
    await login(page, ALICE);
    const year = new Date().getFullYear();
    const id = await findEntryIdByNumber(page, `${year}-00004`);
    await page.goto(`/entries/${id}`);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '16-antrag-detail-freigegeben');
  });

  test('20-events-liste-mitglied', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/events');
    await expect(page.locator('h1')).toContainText('Events');
    await shot(page, '20-events-liste-mitglied');
  });

  test('21-event-detail-mitglied', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/events');
    const row = page.locator('a[href*="/events/"]', { hasText: 'Sommerfest 2026' }).first();
    await row.click();
    await page.waitForURL(/\/events\/\d+/);
    await shot(page, '21-event-detail-mitglied');
  });

  test('22-events-kalender', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/events/calendar');
    await expect(page.locator('h1, .fc-toolbar').first()).toBeVisible();
    // FullCalendar braucht Zeit, bis Events gerendert sind.
    await page.waitForTimeout(800);
    await shot(page, '22-events-kalender');
  });

  test('23-my-events', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/my-events');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '23-my-events');
  });

  test('24-my-events-ical', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/my-events/ical');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '24-my-events-ical');
  });

  test('25-reports-mitglied', async ({ page }) => {
    await login(page, ALICE);
    await page.goto('/reports');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '25-reports-mitglied');
  });
});

// ==========================================================================
// Pruefer
// ==========================================================================
test.describe('Handbuch — Pruefer', () => {
  test('30-prueferliste', async ({ page }) => {
    await login(page, PRUEFER);
    await page.goto('/review');
    await expect(page.locator('h1')).toContainText('Anträge prüfen');
    await shot(page, '30-prueferliste');
  });

  test('31-antrag-pruefen', async ({ page }) => {
    await login(page, PRUEFER);
    await page.goto('/review');
    // Den eingereichten Antrag von Alice (Nr. -00002) oeffnen.
    const year = new Date().getFullYear();
    const link = page
      .locator('tbody tr a[href*="/entries/"]', { hasText: `${year}-00002` })
      .first();
    const href = await link.getAttribute('href');
    if (!href) throw new Error('Pruefer-Row nicht gefunden');
    await page.goto(href);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '31-antrag-pruefen');
  });
});

// ==========================================================================
// Administration (Admin)
// ==========================================================================
test.describe('Handbuch — Administration', () => {
  test('40-admin-events-liste', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/events');
    await expect(page.locator('h1')).toContainText('Events');
    await shot(page, '40-admin-events-liste');
  });

  test('41-admin-event-erstellen', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/events/create');
    await expect(page.locator('input[name="title"]')).toBeVisible();
    await shot(page, '41-admin-event-erstellen');
  });

  test('42-admin-event-detail', async ({ page }) => {
    await login(page, ADMIN);
    const id = await findAdminEventIdByTitle(page, 'Sommerfest 2026');
    await page.goto(`/admin/events/${id}`);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '42-admin-event-detail');
  });

  test('43-admin-event-templates-liste', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/event-templates');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '43-admin-event-templates-liste');
  });

  test('44-admin-event-template-detail', async ({ page }) => {
    await login(page, ADMIN);
    const id = await findAdminTemplateIdByName(page, 'Saison-Abschlussfest');
    await page.goto(`/admin/event-templates/${id}`);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '44-admin-event-template-detail');
  });

  test('50-admin-mitglieder-liste', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/users');
    await expect(page.locator('h1')).toContainText('Mitglieder');
    await shot(page, '50-admin-mitglieder-liste');
  });

  test('51-admin-mitglied-anlegen', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/users/create');
    await expect(page.locator('h1')).toContainText('Neues Mitglied');
    await shot(page, '51-admin-mitglied-anlegen');
  });

  test('52-admin-mitglied-detail-rollen', async ({ page }) => {
    await login(page, ADMIN);
    const id = await findAdminUserIdByEmail(page, 'alice@e2e.local');
    await page.goto(`/admin/users/${id}`);
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '52-admin-mitglied-detail-rollen');
  });

  test('53-admin-mitglieder-import', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/users/import');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '53-admin-mitglieder-import');
  });

  test('60-admin-kategorien', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/categories');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '60-admin-kategorien');
  });

  test('61-admin-soll-stunden', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/targets');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '61-admin-soll-stunden');
  });

  test('62-admin-settings', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/settings');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '62-admin-settings');
  });

  test('63-admin-audit', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/admin/audit');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '63-admin-audit');
  });

  test('64-admin-reports', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/reports');
    await expect(page.locator('h1')).toBeVisible();
    await shot(page, '64-admin-reports');
  });
});
