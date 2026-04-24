import { test, expect, Browser, BrowserContext, Page } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { AdminEventTreePage } from '../pages/AdminEventTreePage';
import { ADMIN, EVENT_ADMIN } from '../fixtures/users';
import { clearE2eEditSessions, setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7e-C.1 Phase 4 Teil 1 — End-zu-End-Validierung des
 * Edit-Session-Hinweises.
 *
 * Multi-Context-Pattern analog zu Spec 16 (I7e-B Optimistic Lock):
 * zwei separate browser.newContext()-Instanzen mit unterschiedlichen
 * Logins, Serial-Mode, gemeinsamer Setup-Event.
 *
 * Tests:
 *   1. Initial-State: ADMIN oeffnet Editor zuerst, EVENT_ADMIN
 *      oeffnet danach und sieht den Hinweis SOFORT (server-seitig
 *      gerendertes data-initial-sessions-Attribut, Architect-C4).
 *      Nach 35 s Polling sieht ADMIN auch EVENT_ADMINs Session.
 *   2. Close: ADMIN navigiert zu about:blank (triggert beforeunload-
 *      sendBeacon). EVENT_ADMIN sieht den Hinweis innerhalb des
 *      naechsten Polling-Tick (35 s) verschwinden.
 *   3. Feature-Flag aus: Mit events.edit_sessions_enabled=0 wird
 *      kein Hinweis gerendert, und das Polling laeuft leer.
 *
 * Display-Namen aus dem E2E-Seed:
 *   - ADMIN        -> "E2E Admin"
 *   - EVENT_ADMIN  -> "E2E Eventadmin"
 *
 * Edit-Berechtigung pro User:
 *   - EVENT_ADMIN ist Organisator des Setup-Events (im Setup-Schritt
 *     als organizerEmail uebergeben).
 *   - ADMIN hat die Rolle administrator (canEditEvent gibt true,
 *     auch ohne Organizer-Mitgliedschaft).
 *
 * Beide nutzen /admin/events/{id}/editor — RoleMiddleware verlangt
 * event_admin oder administrator, beide haben das.
 *
 * Polling-Wait: HEARTBEAT_INTERVAL_MS in edit-session.js ist 30 s.
 * Tests, die auf Polling-Updates warten, brauchen ~35 s Buffer.
 * Spec-Gesamtlaufzeit damit ca. 2 Minuten — akzeptabel fuer eine
 * Multi-Context-Spec (Spec 16 hat aehnliche Dimensionen).
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

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
 * Wartet, bis das Edit-Session-JS gebootet hat. Boot ist zwei
 * Schritte: (1) DOM-Element #edit-sessions-indicator existiert
 * (kommt aus dem Server-HTML), (2) der Initial-State wurde
 * gerendert oder ist explizit leer. Wir pruefen nur (1) hier --
 * dass das JS lebt, signalisieren wir durch das Vorhandensein
 * des window.fetch-Calls in den naechsten Schritten.
 */
async function waitForIndicator(page: Page): Promise<void> {
  await expect(page.locator('#edit-sessions-indicator')).toBeAttached();
}

test.describe('Edit-Session-Hinweis (I7e-C.1)', () => {
  const now = new Date();
  const eventStart = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
  const eventEnd = new Date(eventStart.getTime() + 6 * 60 * 60 * 1000);
  const startAt = toLocalDateTime(eventStart);
  const endAt = toLocalDateTime(eventEnd);

  const eventTitle = `E2E EditSessions ${now.getTime()}`;
  // Task-Titel fuer Test 5 (Lock-Reload-Integration). Lang-eindeutig,
  // damit Locator-Treffer bei Substring-Kollision sicher sind.
  const taskTitle = 'E2E-EditSession-Lock-Reload-Task';
  let eventId = 0;

  test.beforeAll(() => {
    // Beide Flags sind hart gekoppelt (Architect-C2): editSessionsEnabled
    // = treeEditorEnabled && events.edit_sessions_enabled. Ohne beide
    // bleibt das Feature aus, und die /api/edit-sessions/start-Route
    // antwortet mit 410.
    setE2eSetting('events.tree_editor_enabled', '1');
    setE2eSetting('events.edit_sessions_enabled', '1');
  });

  test.afterAll(() => {
    // Beide Flags wieder abschalten, damit nachfolgende Specs nicht
    // unbeabsichtigt eine Edit-Session-Anzeige bekommen.
    setE2eSetting('events.edit_sessions_enabled', '0');
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  /**
   * Vor jedem Test edit_sessions leeren. Hintergrund: in serial-Mode
   * ueberlappen sich die DB-State-Fenster der Tests -- der Server
   * haelt Sessions bis 120 s als aktiv, ein Folge-Test wuerde sonst
   * die Sessions des Vorgaenger-Tests sehen. Da
   * EditSessionView::toJsonReadyArray pro user_id dedupliziert,
   * koennte z.B. Test 2 (A schliesst, B sieht weg) nie sehen, dass
   * A's Session weg ist, weil A's Vor-Test-Session noch im Active-
   * Window haengt. Truncate ist sauber und schnell -- die Tabelle
   * ist klein (max ein paar Zeilen pro Test).
   */
  test.beforeEach(() => {
    clearE2eEditSessions();
  });

  // =========================================================================
  // Setup
  // =========================================================================

  test('Setup: Event mit zwei Berechtigten (Organizer + Admin) und einem Task fuer Test 5', async ({ page }) => {
    const login = new LoginPage(page);
    const events = new AdminEventsPage(page);
    const tree = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    eventId = await events.createEvent({
      title: eventTitle,
      description: 'I7e-C.1 Phase 4 Setup-Event',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);

    // Ein Task wird nur fuer Test 5 (Lock-Reload-Integration) gebraucht.
    // Tests 1-4 ignorieren den Task und pruefen nur den Indicator-Container.
    // Variabel/unbegrenzt: keine Slot-/Capacity-Regeln, die den Lock-
    // Konflikt maskieren wuerden.
    await tree.gotoEdit(eventId);
    await tree.createTopLevelNode({
      isGroup: false,
      title: taskTitle,
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
    });
  });

  // =========================================================================
  // Test 1 — Initial-State und Polling-Crossing
  // =========================================================================

  test('A oeffnet Editor zuerst, B sieht Hinweis sofort, A sieht B nach Polling', async ({ browser }) => {
    // Per-Test-Timeout hochsetzen: der Polling-Tick im Frontend ist
    // 30 s, der Cross-User-Sichtbarkeits-Test braucht einen vollen
    // Tick (35 s Buffer). Default 30 s reicht nicht.
    test.setTimeout(60_000);

    const a = await loginNewContext(browser, ADMIN);
    const b = await loginNewContext(browser, EVENT_ADMIN);

    try {
      // Schritt 1: A oeffnet den Editor. Das JS startet die Session
      // beim Server (POST /api/edit-sessions/start). Wir warten auf
      // einen sichtbaren Indikator, dass der Boot durchlief.
      await a.page.goto(`/admin/events/${eventId}/editor`);
      await waitForIndicator(a.page);

      // A's Indicator ist leer -- es gibt noch keine fremden Editoren.
      await expect(
        a.page.locator('#edit-sessions-indicator .alert')
      ).toHaveCount(0);

      // Kurzer Sicherheits-Wait, damit A's POST /start sicher angekommen
      // ist bevor B den Editor laedt. waitForLoadState('networkidle')
      // ist hier ungeeignet, weil die Polling-Heartbeats das Network
      // periodisch stoeren.
      await a.page.waitForTimeout(500);

      // Schritt 2: B oeffnet den Editor. Server-seitiger Initial-State
      // (Architect-C4) liefert A's Session bereits beim Page-Render mit,
      // damit B's Indicator den Hinweis SOFORT zeigt -- ohne Polling-
      // Wartezeit.
      await b.page.goto(`/admin/events/${eventId}/editor`);
      await waitForIndicator(b.page);

      const bAlert = b.page.locator('#edit-sessions-indicator .alert');
      await expect(bAlert).toHaveCount(1, { timeout: 5000 });
      await expect(bAlert).toContainText(/E2E Admin/);
      await expect(bAlert).toContainText(/bearbeitet dieses Event/);

      // Schritt 3: nach <=35 s sieht A's Polling-Refresh ebenfalls
      // B's Session. (A's Initial-State war leer; nur der erste
      // Polling-Tick bringt das Update.)
      const aAlert = a.page.locator('#edit-sessions-indicator .alert');
      await expect(aAlert).toHaveCount(1, { timeout: 35_000 });
      await expect(aAlert).toContainText(/E2E Eventadmin/);
    } finally {
      await a.context.close();
      await b.context.close();
    }
  });

  // =========================================================================
  // Test 2 — Close-Verhalten ueber sendBeacon
  // =========================================================================

  test('A schliesst Seite, B sieht Hinweis nach Polling verschwinden', async ({ browser }) => {
    test.setTimeout(60_000);

    const a = await loginNewContext(browser, ADMIN);
    const b = await loginNewContext(browser, EVENT_ADMIN);

    try {
      // Beide oeffnen den Editor. Nach Test 1 sind die alten Contexts
      // geschlossen, sessionStorage ist leer -- frische Sessions.
      await a.page.goto(`/admin/events/${eventId}/editor`);
      await waitForIndicator(a.page);
      await a.page.waitForTimeout(500);

      await b.page.goto(`/admin/events/${eventId}/editor`);
      await waitForIndicator(b.page);

      // B sieht A's Session sofort (Initial-State).
      const bAlert = b.page.locator('#edit-sessions-indicator .alert');
      await expect(bAlert).toHaveCount(1, { timeout: 5000 });

      // A navigiert zu about:blank. Das triggert beforeunload und
      // pagehide; navigator.sendBeacon liefert den /close-Request
      // synchron aus, bevor die Seite verschwindet.
      await a.page.goto('about:blank');

      // B's Polling tickt alle 30 s. Innerhalb des naechsten
      // Tick-Fensters muss der Alert verschwinden -- spaetestens
      // 35 s nach dem Close.
      await expect(bAlert).toHaveCount(0, { timeout: 35_000 });
    } finally {
      await a.context.close();
      await b.context.close();
    }
  });

  // =========================================================================
  // Test 3 — Feature-Flag aus: kein Hinweis, kein Polling-Effekt
  // =========================================================================

  test('Bei Feature-Flag=0 wird kein Hinweis gerendert', async ({ browser }) => {
    test.setTimeout(60_000);
    setE2eSetting('events.edit_sessions_enabled', '0');

    try {
      const a = await loginNewContext(browser, EVENT_ADMIN);

      try {
        // Editor laedt, Indicator-Container kommt vom Server (immer
        // gerendert), aber data-initial-sessions ist leeres Array
        // (Service liefert [] bei deaktiviertem Flag).
        await a.page.goto(`/admin/events/${eventId}/editor`);
        await waitForIndicator(a.page);

        // Keine Alerts -- weder beim Initial-State noch nach Boot.
        await expect(
          a.page.locator('#edit-sessions-indicator .alert')
        ).toHaveCount(0);

        // Kurzer Wait, damit das JS-Boot durch ist und ein
        // eventueller POST /start einen 410 vom Server bekommen hat.
        // (Bei Flag=0 startet edit-session.js den Polling-Timer
        // erst gar nicht, weil resumeOrStartSession() false liefert.)
        await a.page.waitForTimeout(2000);
        await expect(
          a.page.locator('#edit-sessions-indicator .alert')
        ).toHaveCount(0);
      } finally {
        await a.context.close();
      }
    } finally {
      // Flag wieder auf 1, damit nachfolgende Tests in dieser Spec
      // (falls jemals welche dazukommen) den Feature-Pfad bekommen.
      // afterAll setzt am Spec-Ende ohnehin auf 0 zurueck.
      setE2eSetting('events.edit_sessions_enabled', '1');
    }
  });

  // =========================================================================
  // Test 5 — Architect-C1 end-zu-end: Session-ID ueberlebt Lock-Reload (FU-G6-1)
  //
  // Beweist Architect-C1 aus I7e-C G1: nach einem Optimistic-Lock-
  // Konflikt aus I7e-B reloadet event-task-tree.js die Seite
  // (handleLockConflict), und edit-session.js findet seine Session-ID
  // im sessionStorage wieder -- Probe-Heartbeat ergibt 200, keine neue
  // Session-Zeile.
  //
  // **Historie (siehe Follow-up z im Register):**
  // In der ersten Test-Fassung (FU-G6-1 Mini-Iteration) schlug die
  // strenge Assertion fehl, weil der beforeunload-Handler in
  // edit-session.js auch bei programmatischen Reloads einen sendBeacon-
  // Close absetzte. Der Probe-Heartbeat nach dem Reload fand die
  // Session geschlossen (404), sessionStorage wurde geleert, eine neue
  // Session mit neuer ID wurde gestartet. Die user-sichtbare
  // Praesenz-Invariante hielt (Server dedupliziert per user_id), aber
  // die strenge C1-Intent-Invariante nicht.
  //
  // Follow-up-z-Fix: event-task-tree.js setzt vor
  // window.location.reload() das Flag sessionStorage[
  // 'vaes_programmatic_reload'] = '1'. edit-session.js prueft dieses
  // Flag in closeSessionBestEffort und ueberspringt den sendBeacon-
  // Close bei gesetztem Flag. Nach dem Fix greift C1 end-zu-end.
  //
  // Strategie:
  //   1. ADMIN (E2E Admin) und EVENT_ADMIN (E2E Eventadmin) oeffnen
  //      /admin/events/{id}/edit -- die Seite enthaelt sowohl den
  //      Modal-Editor als auch den Edit-Sessions-Indicator.
  //   2. Beide oeffnen den Edit-Modal fuer denselben Task.
  //   3. ADMIN speichert erfolgreich. event-task-tree.js ruft danach
  //      window.location.reload() (Standard-Erfolgs-Pfad, OHNE Flag).
  //   4. EVENT_ADMIN haelt die alte Modal-Version, speichert -> 409 +
  //      Warn-Toast. handleLockConflict setzt das Flag und ruft nach
  //      1.5 s window.location.reload() auf.
  //   5. KERN-ASSERTION: EVENT_ADMINs sessionStorage.vaes_edit_session_id
  //      ist nach dem Reload IDENTISCH zu vorher.
  // =========================================================================

  test('Lock-Reload aus I7e-B: Session-ID bleibt identisch (FU-G6-1)', async ({ browser }) => {
    test.setTimeout(60_000);

    const a = await loginNewContext(browser, ADMIN);
    const b = await loginNewContext(browser, EVENT_ADMIN);

    try {
      const treeA = new AdminEventTreePage(a.page);
      const treeB = new AdminEventTreePage(b.page);

      // Beide oeffnen die Event-Bearbeiten-Seite (Modal-Editor-Variante).
      // Diese Seite enthaelt SOWOHL den Tree-Editor mit Modal-Lock-Pfad
      // ALS AUCH den Indicator-Container, also boots auch edit-session.js.
      await treeA.gotoEdit(eventId);
      await treeB.gotoEdit(eventId);

      await expect(a.page.locator('#edit-sessions-indicator')).toBeAttached();
      await expect(b.page.locator('#edit-sessions-indicator')).toBeAttached();

      // Kurz warten, damit beide Sessions beim Server angekommen sind --
      // sonst ist EVENT_ADMINs sessionStorage beim ersten Lesen leer
      // (POST /start noch unterwegs).
      await a.page.waitForTimeout(800);
      await b.page.waitForTimeout(800);

      // Eingangsbedingung: EVENT_ADMIN hat eine gueltige Session-ID
      // im sessionStorage (POST /start war erfolgreich).
      const sessionIdBefore = await b.page.evaluate(
        () => sessionStorage.getItem('vaes_edit_session_id')
      );
      expect(
        sessionIdBefore,
        'EVENT_ADMINs sessionStorage.vaes_edit_session_id muss vor dem '
          + 'Lock-Konflikt gesetzt sein (POST /start gelaufen).'
      ).toBeTruthy();
      expect(Number(sessionIdBefore)).toBeGreaterThan(0);

      // Beide oeffnen den Edit-Modal fuer denselben Task.
      // openEditModal triggert AJAX-fetch fuer task-Daten -- inkl.
      // version-Hidden-Field aus I7e-B.
      await treeA.openEditModal(taskTitle);
      await treeB.openEditModal(taskTitle);

      // ADMIN speichert mit neuem Titel -> Erfolg, automatischer Reload.
      const titleAfterA = `${taskTitle}-AendA`;
      await a.page.locator('#task-edit-modal input[name="title"]').fill(titleAfterA);
      await a.page.locator('#task-edit-modal-save').click();
      await expect(treeA.nodeByTitle(titleAfterA)).toBeVisible({ timeout: 15_000 });

      // EVENT_ADMIN speichert mit veralteter Version -> 409 + Toast +
      // 1.5 s Delay + Reload.
      await b.page.locator('#task-edit-modal input[name="title"]').fill('B-Update');
      await b.page.locator('#task-edit-modal-save').click();

      const warnToast = b.page.locator(
        '.toast.text-bg-warning',
        { hasText: /zwischenzeitlich.*geaendert/i }
      );
      await expect(warnToast).toBeVisible({ timeout: 5000 });

      // Auf den Post-Reload-Zustand warten: edit-session.js bootet
      // erneut, der Indicator-Container ist wieder im DOM, edit-
      // session.js liest sessionStorage. Dank Follow-up-z-Fix wurde
      // die alte Session NICHT via sendBeacon geschlossen, der
      // Probe-Heartbeat findet sie weiter offen und behaelt die ID.
      await expect(treeB.nodeByTitle(titleAfterA)).toBeVisible({ timeout: 15_000 });
      await expect(b.page.locator('#edit-sessions-indicator')).toBeAttached();

      // Kurz warten, damit resumeOrStartSession den Post-Reload-Flow
      // abgeschlossen hat (Probe-Heartbeat bestaetigt gueltige Session).
      await b.page.waitForTimeout(800);

      // KERN-ASSERTION (C1 end-zu-end): Session-ID bleibt IDENTISCH.
      const sessionIdAfter = await b.page.evaluate(
        () => sessionStorage.getItem('vaes_edit_session_id')
      );
      expect(
        sessionIdAfter,
        'sessionStorage.vaes_edit_session_id muss nach dem Lock-Reload '
          + 'identisch zur ID vor dem Konflikt sein -- das beweist '
          + 'Architect-C1 end-zu-end: sessionStorage-Persistenz + '
          + 'Server-Session ueberleben den Lock-Reload. Fix aus '
          + 'Follow-up z: event-task-tree.js setzt vor dem Reload ein '
          + 'sessionStorage-Flag, das edit-session.js im beforeunload-'
          + 'Handler liest und den sendBeacon-Close ueberspringt.'
      ).toBe(sessionIdBefore);

      // Zusatz-Pruefung: das Flag wurde nach dem Reload konsumiert
      // (self-cleanup in closeSessionBestEffort), damit ein echter
      // User-Close nicht versehentlich auch uebersprungen wird.
      const flagAfter = await b.page.evaluate(
        () => sessionStorage.getItem('vaes_programmatic_reload')
      );
      expect(
        flagAfter,
        'vaes_programmatic_reload muss nach der Flag-Konsumption '
          + '(beim ersten closeSessionBestEffort-Aufruf beim Reload) '
          + 'wieder null sein, damit ein echter User-Close anschliessend '
          + 'den Standard-sendBeacon-Pfad nimmt.'
      ).toBeNull();
    } finally {
      await a.context.close();
      await b.context.close();
    }
  });
});
