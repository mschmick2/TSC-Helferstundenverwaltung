import { test, expect, Browser, BrowserContext, Page } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { WorkEntryEditPage } from '../pages/WorkEntryEditPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { MemberEventsPage } from '../pages/MemberEventsPage';
import { ADMIN, ALICE, BOB, PRUEFER, EVENT_ADMIN } from '../fixtures/users';

/**
 * Modul 8 — Inkrement 8: E2E Multitab / Multisession.
 *
 * Zeigt Modul 7 in der Praxis: zwei `BrowserContext`-Instanzen = zwei
 * voneinander unabhaengige Sessions (zwei User gleichzeitig). Zwei `Page`
 * im gleichen Context = zwei Tabs derselben Session.
 *
 * Enthaelt 5 Szenarien:
 *   1. Pessimistic Lock (Modul 7 I1): zweite Session sieht "bereits
 *      bearbeitet"-Banner.
 *   2. BroadcastChannel (Modul 7 I2): Logout in Tab B redirectet Tab A
 *      auf /login.
 *   3. Cross-Session Dialog-Badge: Prueferin stellt Rueckfrage, Alice-Tab
 *      zeigt das Nav-Badge hochgezaehlt.
 *   4. Optimistic Lock + Conflict-Diff-UI (Modul 7 I3/I4): zweiter Save
 *      landet auf der Diff-Seite mit "Dein Stand / DB-Stand"-Tabelle.
 *   5. Capacity=1 Race: Alice und Bob klicken Uebernehmen simultan, nur
 *      einer bekommt den Slot.
 *
 * Jede describe-Gruppe ist in sich serial und voll self-contained (eigener
 * Admin/Event, frische User-Kontexte). Keine Top-Level-serial-Konfig, damit
 * ein fehlgeschlagener Test die uebrigen vier nicht ueberspringt.
 */

/**
 * Formatiert ein Date als datetime-local-String 'YYYY-MM-DDTHH:mm' in
 * lokaler Zeitzone. Identisch zu spec 04 — beide Specs bewegen sich um
 * Event-Start/Ende-Zeiten.
 */
function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

/**
 * Oeffnet einen frischen BrowserContext, loggt den Seed-User ein und liefert
 * Context+Page. Ruft der Aufrufer selbst auf `context.close()` am Ende.
 */
async function loginNewContext(
  browser: Browser,
  user: Parameters<LoginPage['loginAs']>[0],
): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await new LoginPage(page).loginAs(user);
  return { context, page };
}

/**
 * Liest Alice' user_id aus dem Admin-User-Listing. Spart dem Test das
 * Hardcoden der Seed-ID (die sich aendert, wenn Migrationen einen weiteren
 * Service-User davor einfuegen).
 */
async function discoverUserIdByMnr(page: Page, mnr: string): Promise<number> {
  await page.goto('/admin/users');
  const row = page.locator('tbody tr', { hasText: mnr });
  await expect(row).toBeVisible();
  const href = await row.locator('a[href*="/admin/users/"]').first().getAttribute('href');
  if (!href) throw new Error(`Kein Link in Admin-Users-Row fuer ${mnr}`);
  const match = href.match(/\/admin\/users\/(\d+)/);
  if (!match) throw new Error(`Kein Id in href: ${href}`);
  return Number(match[1]);
}

/**
 * Admin erstellt ueber den regulaeren `/entries`-POST einen Entwurf fuer
 * einen anderen User (canCreateForOthers=true). Dadurch ist der Entry von
 * ZWEI unterschiedlichen User-IDs editierbar:
 *
 *   - `user_id` = Alice (Owner)
 *   - `created_by_user_id` = Admin (Creator)
 *
 * Das brauchen wir fuer Test 1: nur bei unterschiedlicher `user_id`
 * triggert die Lock-SQL den "bereits-bearbeitet"-Zweig — zwei Sessions
 * desselben Users wuerden den Lock einfach flipflop-uebernehmen.
 *
 * Der Admin kennt die Entry-ID nach dem POST nicht — der Controller
 * redirectet auf `/entries` (ohne ID) und die Admin-Liste filtert auf
 * `user_id=admin`, blendet also diesen Entry aus. Der Aufrufer muss die
 * ID anschliessend in der Owner-Liste ermitteln
 * (`findLatestEntryIdAsOwner`).
 */
async function adminPostEntryForUser(
  page: Page,
  targetUserId: number,
  marker: string,
  opts: { submitImmediately?: boolean } = {},
): Promise<void> {
  // 1. CSRF + Kategorie aus /entries/create holen
  await page.goto('/entries/create');
  const csrf = await page.locator('input[name="csrf_token"]').first().getAttribute('value');
  if (!csrf) throw new Error('CSRF-Token in /entries/create nicht gefunden.');
  const categoryId = await page.locator('select[name="category_id"] option').nth(1)
    .getAttribute('value');
  if (!categoryId) throw new Error('Keine aktive Kategorie in /entries/create.');

  // 2. POST /entries mit user_id=target (createForOthers-Pfad im Controller).
  // Optional `submit_immediately=1` → WorkflowService.submit() wird direkt
  // im Anschluss aufgerufen, Entry ist nach dem POST im Status 'eingereicht'.
  const today = new Date().toISOString().slice(0, 10);
  const form: Record<string, string> = {
    csrf_token: csrf,
    user_id: String(targetUserId),
    work_date: today,
    hours: '2.00',
    category_id: categoryId,
    description: marker,
    project: 'E2E-Multitab',
  };
  if (opts.submitImmediately) {
    form.submit_immediately = '1';
  }
  const resp = await page.request.post('/entries', { form, maxRedirects: 0 });
  expect([302, 303]).toContain(resp.status());
}

/**
 * Liefert die Entry-ID des neusten Eintrags in der `/entries`-Liste der
 * aktuell eingeloggten Session. `sort=created_at&dir=DESC` garantiert
 * "frisch erstellt steht oben", weil die `/entries`-Listenansicht die
 * Description nicht rendert und ein hasText-Filter auf einen Marker
 * daher ins Leere laeuft.
 *
 * Optional kann der Aufrufer einen Marker mitgeben; dann wird die Detail-
 * Seite des obersten Entrys besucht und geprueft, dass dessen Beschreibung
 * den Marker enthaelt (Sanity-Check gegen Cross-Spec-Altlast, z.B. wenn
 * Spec 02 vor 08 in der Full-Suite lief und einen neueren Entry erzeugt
 * hat).
 */
async function findLatestEntryIdAsOwner(page: Page, marker?: string): Promise<number> {
  await page.goto('/entries?sort=created_at&dir=DESC');
  const topRow = page.locator('tbody tr').first();
  await expect(topRow).toBeVisible();
  const href = await topRow.locator('a[href*="/entries/"]').first().getAttribute('href');
  if (!href) throw new Error('Kein Link in erster Row der Owner-Liste.');
  const match = href.match(/\/entries\/(\d+)/);
  if (!match) throw new Error(`Kein ID in href: ${href}`);
  const id = Number(match[1]);
  if (marker) {
    await page.goto(`/entries/${id}`);
    await expect(page.locator('body')).toContainText(marker);
  }
  return id;
}

// ============================================================================
// Test 1 — Pessimistic Lock: zweite Session sieht "bereits bearbeitet"
// ============================================================================
test.describe('Pessimistic Lock: zweiter Tab/zweite Session sieht Sperre', () => {
  test('Alice haelt Lock, Admin sieht "gesperrt durch Alice"-Banner', async ({ browser }) => {
    // Admin legt einen Entwurf fuer Alice an — damit Alice Owner und Admin
    // Creator ist. Dadurch duerfen BEIDE /edit aufrufen (isOwnerOrCreator).
    const adminSession = await loginNewContext(browser, ADMIN);
    try {
      const aliceId = await discoverUserIdByMnr(adminSession.page, 'E2E-ALI');
      expect(aliceId).toBeGreaterThan(0);
      const marker = `E2E Multitab Lock ${Date.now()}`;
      await adminPostEntryForUser(adminSession.page, aliceId, marker);

      // Alice loggt sich ein und findet die frisch erstellte Entry-ID in
      // ihrer eigenen /entries-Liste (Admin-Liste filtert auf user_id=admin
      // und zeigt den Entry nicht an). Marker dient als Sanity-Check
      // gegen Cross-Spec-Altlast.
      const aliceSession = await loginNewContext(browser, ALICE);
      try {
        const entryId = await findLatestEntryIdAsOwner(aliceSession.page, marker);
        expect(entryId).toBeGreaterThan(0);

        // Alice holt sich den Lock in ihrer eigenen Session.
        const aliceEdit = new WorkEntryEditPage(aliceSession.page);
        await aliceEdit.goto(entryId);
        // Alice ist Owner — kein Lock-Banner. Wir pruefen positiv, dass
        // genau kein Banner mit "bereits bearbeitet" im DOM steht; ein
        // negatives not.toContainText wuerde hier fehlschlagen, weil
        // Playwright erst auf die Existenz des Locators wartet.
        await expect(aliceSession.page.locator('.alert-warning', { hasText: 'bereits bearbeitet' }))
          .toHaveCount(0);
        // Save-Button von Alice muss aktiv sein — positives Signal fuer
        // "Lock erfolgreich akquiriert".
        const aliceSave = aliceSession.page.getByRole('button', { name: /Speichern/i }).first();
        await expect(aliceSave).toBeEnabled();

        // Admin (Creator) oeffnet dieselbe Edit-Route in SEINER Session.
        // Alice haelt bereits den Lock → Admin sieht Read-Only-Banner.
        await adminSession.page.goto(`/entries/${entryId}/edit`);
        await expect(adminSession.page.locator('.alert-warning'))
          .toContainText('Eintrag wird bereits bearbeitet');
        await expect(adminSession.page.locator('.alert-warning'))
          .toContainText('Alice Mitglied');
        // Save-Button ist disabled.
        const save = adminSession.page.getByRole('button', { name: /Speichern/i }).first();
        await expect(save).toBeDisabled();
      } finally {
        await aliceSession.context.close();
      }
    } finally {
      await adminSession.context.close();
    }
  });
});

// ============================================================================
// Test 2 — BroadcastChannel: Logout in Tab B redirectet Tab A
// ============================================================================
test.describe('BroadcastChannel: auth:logout propagiert Cross-Tab', () => {
  test('Logout in Tab B redirectet Tab A ohne Reload-Wartezeit', async ({ browser }) => {
    const ctx = await browser.newContext();
    const tabA = await ctx.newPage();
    await new LoginPage(tabA).loginAs(ALICE);
    // Tab A bleibt auf /dashboard (Landing-Page nach Login).
    await expect(tabA.locator('h1, h2').first()).toBeVisible();

    const tabB = await ctx.newPage();
    // Zweiter Tab in DERSELBEN Session: Cookies werden geteilt, BroadcastChannel
    // 'vaes' verbindet die Tabs ueber Same-Origin.
    await tabB.goto('/entries?sort=created_at&dir=DESC');
    await expect(tabB.locator('h1')).toContainText('Meine Arbeitsstunden');

    // Tab B loggt aus → broadcast.js schickt 'auth:logout' durch den
    // Channel, Tab A reagiert per window.location.href='/login'.
    await tabB.goto('/logout');

    // Tab A muss binnen Sekunden auf /login sein — NICHT ueber das native
    // waitForURL-Negativ-Predikat, sondern ueber positives DOM-Signal
    // (Login-Formular), weil waitForURL im Headed/SlowMo-Modus den
    // Redirect verpassen kann.
    await expect(tabA.locator('input[name="email"]'))
      .toBeVisible({ timeout: 15_000 });
    await expect(tabA).toHaveURL(/\/login/);

    await ctx.close();
  });
});

// ============================================================================
// Test 3 — Cross-Session Dialog-Badge via Polling
// ============================================================================
test.describe('Cross-Session Dialog-Badge zaehlt hoch nach Rueckfrage', () => {
  test('Prueferin stellt Rueckfrage, Alice-Navbar zeigt 1 nach Polling-Refresh', async ({ browser }) => {
    // Vorbedingung: Alice hat einen frischen Entry im Status "eingereicht".
    // Schritt 1: Admin POSTet den Entwurf fuer Alice (user_id=Alice).
    // Schritt 2: Alice findet die ID in ihrer eigenen Liste und reicht
    // ihn selbst ein — dadurch bleibt die Spec unabhaengig von anderen
    // Seed-Daten und Spec-Reihenfolgen.
    const marker = `E2E Dialog-Badge ${Date.now()}`;
    const adminSession = await loginNewContext(browser, ADMIN);
    try {
      const aliceId = await discoverUserIdByMnr(adminSession.page, 'E2E-ALI');
      // submit_immediately=1 → Entry ist nach dem POST schon 'eingereicht',
      // spart Alice den extra Submit-Klick und verhindert einen
      // Timing-Fallstrick (Submit-Form kann je nach Status fehlen).
      await adminPostEntryForUser(adminSession.page, aliceId, marker, {
        submitImmediately: true,
      });
    } finally {
      await adminSession.context.close();
    }

    const aliceSession = await loginNewContext(browser, ALICE);
    const pruferSession = await loginNewContext(browser, PRUEFER);
    try {
      // Entry-ID ohne Marker-Sanity-Check holen — wuerde sonst /entries/{id}
      // besuchen und `DialogReadStatus.last_read_at=NOW()` fuer Alice
      // setzen. `findUnreadDialogsForUser` prueft strikt
      // `wed.created_at > last_read_at` mit Sekunden-Granularitaet; wenn
      // Alice- und Pruefer-Aktion in dieselbe MySQL-Sekunde fallen,
      // zaehlt die Rueckfrage nicht und der Badge bleibt bei 0.
      const entryId = await findLatestEntryIdAsOwner(aliceSession.page);
      expect(entryId).toBeGreaterThan(0);

      // Alice oeffnet Dashboard → Badge-Poll startet, Anzahl ist 0.
      await aliceSession.page.goto('/');
      const badge = aliceSession.page.locator('#nav-unread-badge');
      await expect(badge).toBeAttached();
      await expect(badge).toBeHidden();

      // Prueferin stellt eine Rueckfrage. Der UI-Weg waere ein
      // button[type="submit"]-Klick; wir machen den POST explizit per
      // page.request, damit ein fehlschlagender Request nicht schweigend
      // in einem Redirect-Match verschwindet. Status 302/303 bestaetigt,
      // dass die Nachricht tatsaechlich in der DB gelandet ist.
      await pruferSession.page.goto(`/entries/${entryId}`);
      const msgCsrf = await pruferSession.page.locator(
        'form[action$="/message"] input[name="csrf_token"]'
      ).first().getAttribute('value');
      expect(msgCsrf).toBeTruthy();
      const msgResp = await pruferSession.page.request.post(`/entries/${entryId}/message`, {
        form: {
          csrf_token: msgCsrf!,
          message: 'E2E Rueckfrage an Alice',
          is_question: '1',
        },
        maxRedirects: 0,
      });
      expect([302, 303]).toContain(msgResp.status());

      // Alice reload → Polling feuert auf DOMContentLoaded sofort; der Badge
      // wird auf 1 hochgezaehlt. Das ist die realistische User-Experience:
      // "ich lade die Seite neu und sehe die neue Nachricht".
      await aliceSession.page.reload();
      await expect(aliceSession.page.locator('#nav-unread-count'))
        .toContainText('1', { timeout: 15_000 });
      await expect(aliceSession.page.locator('#nav-unread-badge')).toBeVisible();
    } finally {
      await aliceSession.context.close();
      await pruferSession.context.close();
    }
  });
});

// ============================================================================
// Test 4 — Optimistic Lock + Conflict-Diff-UI
// ============================================================================
test.describe('Optimistic Lock: zweiter Save zeigt Conflict-Diff-UI', () => {
  test('Tab B speichert nach Tab A, sieht Diff-Tabelle mit Dein Stand vs DB-Stand', async ({ browser }) => {
    const ctx = await browser.newContext();
    const tabA = await ctx.newPage();
    await new LoginPage(tabA).loginAs(EVENT_ADMIN);

    // Event fuer diesen Test frisch anlegen (keine Daten-Abhaengigkeit).
    const adminEventsA = new AdminEventsPage(tabA);
    const now = new Date();
    const startAt = toLocalDateTime(new Date(now.getTime() + 24 * 60 * 60 * 1000));
    const endAt = toLocalDateTime(new Date(now.getTime() + 26 * 60 * 60 * 1000));
    const baseTitle = `E2E Optimistic ${now.getTime()}`;
    const eventId = await adminEventsA.createEvent({
      title: baseTitle,
      description: 'Multitab-Optimistic-Lock-Test',
      location: 'Test',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);

    // Tab B in DERSELBEN Session oeffnet /edit parallel — beide Tabs
    // haben jetzt die gleiche version=1.
    const tabB = await ctx.newPage();
    await tabA.goto(`/admin/events/${eventId}/edit`);
    await tabB.goto(`/admin/events/${eventId}/edit`);
    await expect(tabA.locator('input[name="title"]')).toHaveValue(baseTitle);
    await expect(tabB.locator('input[name="title"]')).toHaveValue(baseTitle);

    // Tab A speichert zuerst mit neuem Titel → version 1 -> 2.
    const titleA = `${baseTitle} — Aenderung A`;
    await tabA.locator('input[name="title"]').fill(titleA);
    await tabA.getByRole('button', { name: /speichern/i }).first().click();
    await tabA.waitForURL(/\/admin\/events\/\d+$/);

    // Tab B speichert danach mit eigenem Titel und version=1 → Konflikt.
    const titleB = `${baseTitle} — Aenderung B`;
    await tabB.locator('input[name="title"]').fill(titleB);
    await tabB.getByRole('button', { name: /speichern/i }).first().click();

    // Conflict-Diff-UI: alert-warning + Tabelle "Dein Stand / DB-Stand".
    await expect(tabB.locator('.alert-warning')).toContainText('Gleichzeitige Aenderung erkannt');
    await expect(tabB.locator('.alert-warning'))
      .toContainText('Dein Stand (nicht gespeichert)');
    await expect(tabB.locator('.alert-warning'))
      .toContainText('Aktueller DB-Stand (im Formular vorbelegt)');
    // Diff enthaelt beide Werte.
    const diffTable = tabB.locator('.alert-warning table.table-sm.table-bordered');
    await expect(diffTable).toContainText('Aenderung B');
    await expect(diffTable).toContainText('Aenderung A');
    // Formular ist mit DB-Stand (Aenderung A) vorbelegt, damit der User
    // sauber ueberschreiben kann.
    await expect(tabB.locator('input[name="title"]')).toHaveValue(titleA);

    await ctx.close();
  });
});

// ============================================================================
// Test 5 — Capacity=1 Race: nur eine Zusage gewinnt
// ============================================================================
test.describe('Capacity=1 Race: simultaner Uebernehmen-Klick liefert genau einen Gewinner', () => {
  test('Alice und Bob klicken parallel, einer bekommt Erfolg, der andere Kapazitaets-Fehler', async ({ browser }) => {
    // Event + Task mit capacity_target=1 von Event-Admin anlegen.
    const eventAdmin = await loginNewContext(browser, EVENT_ADMIN);
    const adminEvents = new AdminEventsPage(eventAdmin.page);
    const now = new Date();
    const startAt = toLocalDateTime(new Date(now.getTime() + 24 * 60 * 60 * 1000));
    const endAt = toLocalDateTime(new Date(now.getTime() + 26 * 60 * 60 * 1000));
    const eventTitle = `E2E Race ${now.getTime()}`;
    const taskTitle = 'Einzelplatz';

    let eventId = 0;
    try {
      eventId = await adminEvents.createEvent({
        title: eventTitle,
        description: 'Race-Test: genau ein Slot',
        location: 'Test',
        startAt,
        endAt,
        organizerEmail: EVENT_ADMIN.email,
        cancelDeadlineHours: 2,
      });
      expect(eventId).toBeGreaterThan(0);
      await adminEvents.addTask(eventId, {
        title: taskTitle,
        description: 'Genau 1 Helfer',
        slotMode: 'fix',
        capacityMode: 'maximum',
        capacityTarget: 1,
        hoursDefault: 1.0,
        taskType: 'aufgabe',
        taskStartAt: startAt,
        taskEndAt: endAt,
      });
      await adminEvents.gotoShow(eventId);
      await adminEvents.publish();
      await adminEvents.expectStatus('Veroeffentlicht');
    } finally {
      await eventAdmin.context.close();
    }

    // Alice und Bob oeffnen die Event-Seite in eigenen Contexts.
    const alice = await loginNewContext(browser, ALICE);
    const bob = await loginNewContext(browser, BOB);
    try {
      const aliceEvents = new MemberEventsPage(alice.page);
      const bobEvents = new MemberEventsPage(bob.page);
      await aliceEvents.gotoShow(eventId);
      await bobEvents.gotoShow(eventId);

      // Beide sehen den "Uebernehmen"-Button.
      const aliceBtn = alice.page.locator(
        '.card', { has: alice.page.locator('h3', { hasText: taskTitle }) }
      ).getByRole('button', { name: /uebernehmen/i });
      const bobBtn = bob.page.locator(
        '.card', { has: bob.page.locator('h3', { hasText: taskTitle }) }
      ).getByRole('button', { name: /uebernehmen/i });
      await expect(aliceBtn).toBeVisible();
      await expect(bobBtn).toBeVisible();

      // Simultan klicken via Promise.all. EventAssignmentService haelt
      // per TRANSACTION + SELECT ... FOR UPDATE die Kapazitaet atomar.
      await Promise.all([
        aliceBtn.click(),
        bobBtn.click(),
      ]);
      await alice.page.waitForURL(/\/events\/\d+/);
      await bob.page.waitForURL(/\/events\/\d+/);

      // Genau EINE Seite zeigt "Bereits zugesagt", die andere den Fehler.
      const aliceCard = alice.page.locator(
        '.card', { has: alice.page.locator('h3', { hasText: taskTitle }) }
      );
      const bobCard = bob.page.locator(
        '.card', { has: bob.page.locator('h3', { hasText: taskTitle }) }
      );
      const aliceWon = (await aliceCard.getByRole('button', { name: /bereits zugesagt/i }).count()) > 0;
      const bobWon = (await bobCard.getByRole('button', { name: /bereits zugesagt/i }).count()) > 0;
      expect(aliceWon || bobWon).toBe(true);
      expect(aliceWon && bobWon).toBe(false);

      // Der Verlierer sieht die Kapazitaets-Fehlermeldung als Flash.
      const loserPage = aliceWon ? bob.page : alice.page;
      await expect(loserPage.locator('.alert-danger, .alert-warning'))
        .toContainText('maximale Anzahl Helfer');
    } finally {
      await alice.context.close();
      await bob.context.close();
    }
  });
});
