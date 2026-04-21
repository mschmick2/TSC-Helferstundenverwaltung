import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminUsersPage } from '../pages/AdminUsersPage';
import { AdminCategoriesPage } from '../pages/AdminCategoriesPage';
import { AdminTargetsPage } from '../pages/AdminTargetsPage';
import { AdminAuditPage } from '../pages/AdminAuditPage';
import { SetupPasswordPage } from '../pages/SetupPasswordPage';
import { WorkEntryListPage } from '../pages/WorkEntryListPage';
import { WorkEntryCreatePage } from '../pages/WorkEntryCreatePage';
import { MailpitClient } from '../fixtures/mailpit-client';
import { ADMIN, ALICE, BOB } from '../fixtures/users';

/**
 * Modul 8 — Inkrement 3: E2E Admin-Flows.
 *
 * Deckt die Admin-Alltagsflows ab:
 *   - Mitglied anlegen + Einladungsmail landet in MailPit
 *   - Einladungslink nutzen + einloggen
 *   - Kategorie anlegen + deaktivieren (Dropdown-Effekt)
 *   - Soll-Stunden setzen + Dashboard-Fortschritt sichtbar
 *   - Rollen aendern (Bob wird Pruefer)
 *   - Audit-Trail zeigt status_change mit old/new values
 *
 * Serial, damit Einladungs-Token zwischen Test 1 und Test 2 geteilt werden
 * kann und die Flows nicht durcheinandergeraten.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Admin-Flows — Mitglied + Kategorie + Audit', () => {
  const newMember = {
    mitgliedsnummer: 'E2E-NEU',
    email: 'newbie@e2e.local',
    vorname: 'Neuling',
    nachname: 'Einsteiger',
  };
  const newMemberPassword = 'Welcome123xyz';

  let invitationToken = '';

  test.beforeAll(async () => {
    // MailPit leeren, damit unsere Pruefung in Test 1 deterministisch wird.
    const mail = new MailpitClient();
    if (await mail.isAvailable()) {
      await mail.deleteAll();
    }
  });

  test('admin legt neues Mitglied an — Einladungsmail landet in MailPit', async ({ page }) => {
    const login = new LoginPage(page);
    const admin = new AdminUsersPage(page);

    await login.loginAs(ADMIN);
    await admin.gotoCreate();
    await admin.fillCreate(newMember);
    await admin.submitCreate();

    // Flash-Message als Soft-Check: Liste wird angezeigt, Mitglied taucht auf.
    await admin.gotoList();
    await expect(page.locator('tbody')).toContainText(newMember.email);

    // MailPit: Einladungsmail mit Subject "VAES - Einladung zur Registrierung"
    // muss an newbie@e2e.local angekommen sein.
    const mail = new MailpitClient();
    expect(await mail.isAvailable()).toBe(true);

    const msg = await mail.waitForMessage({
      to: newMember.email,
      subject: 'Einladung zur Registrierung',
      timeoutMs: 15_000,
    });
    expect(msg).not.toBeNull();

    const body = (msg!.HTML ?? '') + '\n' + (msg!.Text ?? '');
    const token = SetupPasswordPage.extractToken(body);
    expect(token).not.toBeNull();
    invitationToken = token!;
  });

  test('neues Mitglied setzt Passwort ueber Einladungslink und kann sich anmelden', async ({ page }) => {
    expect(invitationToken).not.toEqual('');

    const setup = new SetupPasswordPage(page);
    const login = new LoginPage(page);

    await setup.gotoByToken(invitationToken);
    await setup.setPassword(newMemberPassword);

    // Nach Setup landen wir auf /login. Jetzt mit neuem Passwort einloggen.
    await login.fill(newMember.email, newMemberPassword);
    await login.submit();

    // Erfolgreicher Login: nicht mehr auf /login, Logout-Link vorhanden.
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await expect(page.locator('a[href$="/logout"]')).toHaveCount(1);
  });

  test('admin legt Kategorie an und deaktiviert sie — Dropdown verschwindet', async ({ page }) => {
    const login = new LoginPage(page);
    const cats = new AdminCategoriesPage(page);
    const entryList = new WorkEntryListPage(page);
    const entryCreate = new WorkEntryCreatePage(page);

    await login.loginAs(ADMIN);
    await cats.goto();

    const catName = 'E2E-Testkategorie';
    await cats.createCategory(catName, 'Nur fuer E2E-Tests');
    await cats.expectRowExists(catName);
    await cats.expectRowStatus(catName, 'Aktiv');

    // Im Antrag-Formular muss die Kategorie im Dropdown auftauchen.
    await entryList.goto();
    await entryList.clickCreate();
    const categorySelect = page.locator('select[name="category_id"]');
    await expect(categorySelect.locator('option', { hasText: catName })).toHaveCount(1);

    // Zurueck ins Admin, deaktivieren.
    await cats.goto();
    await cats.deactivate(catName);
    await cats.expectRowStatus(catName, 'Inaktiv');

    // Nach Deaktivierung verschwindet die Option aus dem Dropdown.
    await entryList.goto();
    await entryList.clickCreate();
    await expect(categorySelect.locator('option', { hasText: catName })).toHaveCount(0);
  });

  test('admin setzt Soll-Stunden fuer Alice — Dashboard zeigt Fortschritt', async ({ page }) => {
    const login = new LoginPage(page);
    const users = new AdminUsersPage(page);
    const targets = new AdminTargetsPage(page);

    await login.loginAs(ADMIN);

    // Alice's User-ID via Liste holen.
    await users.gotoList();
    const aliceId = await users.openUserByEmail(ALICE.email);
    expect(aliceId).toBeGreaterThan(0);

    // Soll-Stunden-Seite ist in der E2E-DB aktiviert (siehe setup-e2e-db.php).
    await targets.gotoList();
    expect(await targets.isEnabled()).toBe(true);

    // 50 Stunden als individuelles Soll setzen.
    await targets.gotoEditByUserId(aliceId);
    await targets.setTargetHours('50');

    // Uebersicht zeigt Alice jetzt mit 50,0 Stunden.
    await targets.gotoList();
    await targets.expectTargetForMember(ALICE.mnr, '50,0');
    await targets.expectProgressBarForMember(ALICE.mnr);

    // Alice sieht den Fortschritt auf ihrem Dashboard.
    await login.logout();
    await login.loginAs(ALICE);
    await page.goto('/');
    const sollCard = page.locator('.card', { hasText: 'Soll-Stunden' });
    await expect(sollCard).toBeVisible();
    await expect(sollCard.locator('.progress')).toBeVisible();
    await expect(sollCard.locator('.progress .progress-bar')).toHaveCount(1);
    await expect(sollCard).toContainText('50,0');
  });

  test('admin aendert Rolle auf Pruefer — Bob sieht danach die Review-Seite', async ({ page }) => {
    const login = new LoginPage(page);
    const users = new AdminUsersPage(page);

    await login.loginAs(ADMIN);
    await users.gotoList();
    const bobId = await users.openUserByEmail(BOB.email);
    expect(bobId).toBeGreaterThan(0);

    // Pruefer-Rolle anhaken + speichern.
    await users.toggleRoleAndSave('Prüfer', true);

    // Nach Reload ist die Rolle persistiert.
    await users.gotoShow(bobId);
    await users.expectUserHasRole('Prüfer');

    // Bob kann /review oeffnen (vorher war die Rolle nur mitglied).
    await login.logout();
    await login.loginAs(BOB);
    await page.goto('/review');
    await expect(page.locator('h1')).toContainText('Anträge prüfen');
  });

  test('audit zeigt status_change-Eintrag mit alten und neuen Werten', async ({ page }) => {
    const login = new LoginPage(page);
    const audit = new AdminAuditPage(page);

    await login.loginAs(ADMIN);
    await audit.goto();

    // Auf "Statusaenderung" filtern. Die vorherigen Specs (02-antrag-workflow)
    // haben reichlich status_change-Zeilen erzeugt (submit, approve, return, reject).
    await audit.filterByAction('status_change');
    await audit.expectAnyRowVisible();

    // Detail des ersten Eintrags ansehen.
    await audit.openFirstEntry();
    await audit.expectDetailShowsAction('Statusänderung');

    // Der Eintrag muss sowohl alte als auch neue Werte mit einem Status haben.
    // Beispiel: old_values = {status: eingereicht}, new_values = {status: freigegeben}.
    await audit.expectOldValuesContain('status');
    await audit.expectNewValuesContain('status');
  });
});
