import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { WorkEntryListPage } from '../pages/WorkEntryListPage';
import { WorkEntryCreatePage } from '../pages/WorkEntryCreatePage';
import { WorkEntryShowPage } from '../pages/WorkEntryShowPage';
import { ReviewListPage } from '../pages/ReviewListPage';
import { ADMIN, ALICE, PRUEFER } from '../fixtures/users';

/**
 * Modul 8 — Inkrement 2: E2E Antrag-Workflow.
 *
 * Deckt den WorkEntry-Lebenszyklus aus Mitglied- und Pruefer-Sicht ab:
 *   entwurf → eingereicht → (freigegeben | in_klaerung → + | abgelehnt)
 *
 * Zwischen den Tests teilen wir Entry-IDs ueber modul-scope Variablen.
 * Daher zwingend `serial`, damit die Reihenfolge eingehalten wird.
 *
 * Die globale DB wird einmal pro Run via globalSetup frisch aufgebaut,
 * nicht zwischen Tests. Neue Eintraege pro Test vermeiden, dass sich
 * Zustaende ueberschreiben.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Antrag-Workflow — Mitglied + Pruefer', () => {
  // Entry-IDs werden in den Tests gesetzt und weitergegeben.
  let aliceDraftId = 0;
  let aliceDraftNumber = '';
  let aliceKlaerungId = 0;
  let aliceKlaerungNumber = '';
  let aliceRejectId = 0;

  test('mitglied legt Entwurf an und speichert ihn', async ({ page }) => {
    const login = new LoginPage(page);
    const list = new WorkEntryListPage(page);
    const create = new WorkEntryCreatePage(page);

    await login.loginAs(ALICE);
    await login.expectLoginSuccess(ALICE);

    await list.goto();
    await list.clickCreate();
    await create.fill({
      hours: '1.5',
      categoryLabel: 'Verwaltung',
      description: 'E2E-Test Entwurf (Test 1).',
    });
    await create.saveAsDraft();

    // Nach Redirect auf /entries: top-row = Alice's Entwurf.
    await list.goto();
    aliceDraftId = await list.topEntryId();
    aliceDraftNumber = await list.topEntryNumber();
    expect(aliceDraftId).toBeGreaterThan(0);
    expect(aliceDraftNumber).toMatch(/^\d{4}-\d+$/);

    await list.expectRowStatus(aliceDraftNumber, 'Entwurf');
  });

  test('mitglied reicht Entwurf ein — Status wechselt auf Eingereicht', async ({ page }) => {
    const login = new LoginPage(page);
    const show = new WorkEntryShowPage(page);

    await login.loginAs(ALICE);
    await show.goto(aliceDraftId);
    await show.expectStatus('Entwurf');
    await show.submit();

    // Nach Submit landet die App auf /entries/{id}; Status ist jetzt
    // "Eingereicht".
    await show.goto(aliceDraftId);
    await show.expectStatus('Eingereicht');
  });

  test('pruefer sieht eingereichten Antrag in der Review-Liste', async ({ page }) => {
    const login = new LoginPage(page);
    const review = new ReviewListPage(page);

    await login.loginAs(PRUEFER);
    await review.goto();
    await review.expectEntryVisible(aliceDraftNumber);
  });

  test('pruefer kann nicht den eigenen Antrag freigeben (Selbstgenehmigungs-Schutz)', async ({ page }) => {
    const login = new LoginPage(page);
    const list = new WorkEntryListPage(page);
    const create = new WorkEntryCreatePage(page);
    const show = new WorkEntryShowPage(page);

    // Admin ist zugleich Pruefer UND Mitglied.
    await login.loginAs(ADMIN);
    await list.goto();
    await list.clickCreate();
    await create.fill({
      hours: '2.0',
      categoryLabel: 'Verwaltung',
      description: 'E2E-Test Selbstantrag (Test 4).',
    });
    await create.saveAndSubmit();

    await list.goto();
    const adminEntryId = await list.topEntryId();
    const adminEntryNumber = await list.topEntryNumber();
    await list.expectRowStatus(adminEntryNumber, 'Eingereicht');

    await show.goto(adminEntryId);
    await show.expectStatus('Eingereicht');

    // Weder Freigabe- noch Rueckfrage- noch Ablehnen-Button duerfen
    // sichtbar sein, wenn der Pruefer sein eigener Antragsteller ist.
    expect(await show.hasApproveButton()).toBe(false);
    await expect(page.locator('button[data-bs-target="#returnModal"]')).toHaveCount(0);
    await expect(page.locator('button[data-bs-target="#rejectModal"]')).toHaveCount(0);
  });

  test('pruefer gibt Antrag frei — Status Freigegeben, Dialog bleibt sichtbar', async ({ page }) => {
    const login = new LoginPage(page);
    const show = new WorkEntryShowPage(page);

    await login.loginAs(PRUEFER);
    await show.goto(aliceDraftId);
    await show.expectStatus('Eingereicht');

    await show.approve();

    await show.goto(aliceDraftId);
    await show.expectStatus('Freigegeben');
    // Dialog-Karte ist gerendert, aber das Nachrichten-Formular nicht
    // mehr — Status ist nicht 'eingereicht'/'in_klaerung'.
    await expect(page.locator('#dialog-container')).toBeVisible();
    await show.expectDialogFormVisible(false);
  });

  test('pruefer stellt Rueckfrage — Status "In Klärung", Mitglied sieht Dialog', async ({ page }) => {
    const login = new LoginPage(page);
    const list = new WorkEntryListPage(page);
    const create = new WorkEntryCreatePage(page);
    const show = new WorkEntryShowPage(page);

    // Zweiten Antrag von Alice anlegen + direkt einreichen.
    await login.loginAs(ALICE);
    await list.goto();
    await list.clickCreate();
    await create.fill({
      hours: '3.0',
      categoryLabel: 'Veranstaltungen',
      description: 'E2E-Test Klaerungs-Flow (Test 6).',
    });
    await create.saveAndSubmit();
    await list.goto();
    aliceKlaerungId = await list.topEntryId();
    aliceKlaerungNumber = await list.topEntryNumber();

    // Pruefer stellt Rueckfrage.
    await login.logout();
    await login.loginAs(PRUEFER);
    await show.goto(aliceKlaerungId);
    await show.askQuestion('Bitte praeziser beschreiben, was an dem Abend gemacht wurde.');

    await show.goto(aliceKlaerungId);
    await show.expectStatus('In Klärung');

    // Alice sieht den Klaerungstext als Warnungsalert + als Dialog-Eintrag.
    await login.logout();
    await login.loginAs(ALICE);
    await show.goto(aliceKlaerungId);
    await show.expectStatus('In Klärung');
    await show.expectReturnReason('praeziser beschreiben');
    await show.expectDialogContains('praeziser beschreiben');
  });

  test('mitglied antwortet im Dialog — Nachricht erscheint, Status bleibt In Klärung', async ({ page }) => {
    const login = new LoginPage(page);
    const show = new WorkEntryShowPage(page);

    await login.loginAs(ALICE);
    await show.goto(aliceKlaerungId);
    await show.expectStatus('In Klärung');

    await show.sendDialogMessage('Antwort: Aufbau fuer Sommerfest, insg. 3 Stunden inkl. Transport.');

    await show.goto(aliceKlaerungId);
    await show.expectDialogContains('Aufbau fuer Sommerfest');
    // Kein Auto-Transition: Status bleibt "In Klärung", bis der Pruefer
    // erneut entscheidet.
    await show.expectStatus('In Klärung');
  });

  test('pruefer lehnt einen neuen Antrag ab — Status Abgelehnt, Begruendung sichtbar', async ({ page }) => {
    const login = new LoginPage(page);
    const list = new WorkEntryListPage(page);
    const create = new WorkEntryCreatePage(page);
    const show = new WorkEntryShowPage(page);

    // Dritten Antrag von Alice anlegen + direkt einreichen.
    await login.loginAs(ALICE);
    await list.goto();
    await list.clickCreate();
    await create.fill({
      hours: '0.5',
      categoryLabel: 'Sonstiges',
      description: 'E2E-Test Ablehnung (Test 8).',
    });
    await create.saveAndSubmit();
    await list.goto();
    aliceRejectId = await list.topEntryId();

    // Pruefer lehnt ab.
    await login.logout();
    await login.loginAs(PRUEFER);
    await show.goto(aliceRejectId);
    await show.reject('Dauer passt nicht zur beschriebenen Taetigkeit.');

    await show.goto(aliceRejectId);
    await show.expectStatus('Abgelehnt');
    await show.expectRejectionReason('passt nicht');
  });
});
