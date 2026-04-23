import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { AdminEventTreePage } from '../pages/AdminEventTreePage';
import { MemberEventAccordionPage } from '../pages/MemberEventAccordionPage';
import { EVENT_ADMIN, ALICE, BOB } from '../fixtures/users';
import { setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7b2 Phase 4 — Playwright-E2E fuer den Mitglieder-Accordion auf
 * /events/{id}.
 *
 * Deckt ab:
 *   - Accordion rendert bei Flag=1 und vorhandener Baumstruktur.
 *   - Filter-Toggle blendet volle Leaves und volle Gruppen per CSS aus.
 *   - Uebernehmen-Button im Leaf funktioniert weiter (bestehender
 *     MemberEventController::assign-Pfad unveraendert).
 *   - Flag=0 → bestehende Karten-Ansicht.
 *   - Event ohne Baumstruktur (nur Top-Level-Tasks, keine Gruppen) →
 *     bestehende Karten-Ansicht auch bei Flag=1.
 *
 * Der Empty-State-Fall (Filter aktiv + nichts sichtbar) ist per
 * Invariants-Test (CSS-Selector und HTML-Element-Existenz) abgedeckt
 * und wird nicht in Playwright getestet — das Datensetup "alle Tasks
 * voll" ist unverhaeltnismaessig aufwaendig fuer den Mehrwert.
 * Dokumentiert im G9-Trailer als Follow-up.
 *
 * Serial: Tests teilen sich das vorbereitete Event + Assignment-Setup.
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

test.describe('Mitglieder-Accordion — Tree-View (I7b2)', () => {
  const now = new Date();
  const startDate = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
  const endDate   = new Date(startDate.getTime() + 3 * 60 * 60 * 1000);
  const startAt   = toLocalDateTime(startDate);
  const endAt     = toLocalDateTime(endDate);
  // Fix-Slot-Fenster innerhalb des Events (30-90min ab Start). Vermeidet die
  // proposed_start/proposed_end-Pflicht-Felder aus _assign_form.php bei
  // slot_mode=variabel; Submit geht direkt durch.
  const slotStart = toLocalDateTime(new Date(startDate.getTime() + 30 * 60 * 1000));
  const slotEnd   = toLocalDateTime(new Date(startDate.getTime() + 90 * 60 * 1000));

  const treeEventTitle = `E2E Accordion ${now.getTime()}`;
  const flatEventTitle = `E2E Accordion Flat ${now.getTime()}`;

  let treeEventId = 0;
  let flatEventId = 0;

  test.beforeAll(() => {
    setE2eSetting('events.tree_editor_enabled', '1');
  });

  test.afterAll(() => {
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  // =========================================================================
  // Setup: zwei Events. Event A mit Baumstruktur (gemischte Leave-Stati),
  // Event B mit flachen Tasks (kein Gruppen-Knoten).
  // =========================================================================

  test('Setup: Admin legt Event mit Baumstruktur an und veroeffentlicht', async ({ page }) => {
    const login  = new LoginPage(page);
    const events = new AdminEventsPage(page);
    const tree   = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    treeEventId = await events.createEvent({
      title: treeEventTitle,
      description: 'Accordion-E2E',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(treeEventId).toBeGreaterThan(0);

    // Struktur aufbauen:
    //   Testbereich (Gruppe)
    //     Offen-Aufgabe (ziel=5, current=0 → data-open-count="5")
    //   Voll-Bereich (Gruppe)
    //     Voll-Aufgabe (ziel=1, ALICE uebernimmt unten → current=1 → "0")
    //   Unbegrenzt-Aufgabe (Top-Level-Leaf, unbegrenzt → kein Attribut)
    await tree.gotoEdit(treeEventId);

    await tree.createTopLevelNode({ isGroup: true, title: 'Testbereich' });
    await tree.createChildUnder('Testbereich', {
      isGroup: false,
      title: 'Offen-Aufgabe',
      slotMode: 'fix',
      startAt: slotStart,
      endAt: slotEnd,
      capacityMode: 'ziel',
      capacityTarget: 5,
      hoursDefault: 2,
    });

    await tree.createTopLevelNode({ isGroup: true, title: 'Voll-Bereich' });
    await tree.createChildUnder('Voll-Bereich', {
      isGroup: false,
      title: 'Voll-Aufgabe',
      slotMode: 'fix',
      startAt: slotStart,
      endAt: slotEnd,
      capacityMode: 'ziel',
      capacityTarget: 1,
      hoursDefault: 2,
    });

    await tree.createTopLevelNode({
      isGroup: false,
      title: 'Unbegrenzt-Aufgabe',
      slotMode: 'fix',
      startAt: slotStart,
      endAt: slotEnd,
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
    });

    // Event veroeffentlichen, damit Mitglieder es sehen.
    await events.gotoShow(treeEventId);
    await events.publish();
    await events.expectStatus('Veroeffentlicht');
  });

  test('Setup: ALICE macht Voll-Aufgabe voll (Assignment-Uebernahme)', async ({ page }) => {
    const login = new LoginPage(page);
    const acc   = new MemberEventAccordionPage(page);

    await login.loginAs(ALICE);
    await acc.goto(treeEventId);
    await acc.expectAccordionVisible();

    // Voll-Bereich ist als "alles voll"-Gruppe initial zugeklappt (Architect-
    // Entscheidung C: open_slots_subtree=1 beim ersten Aufruf -> open, wird
    // aber nach ALICE-Uebernahme 0). Beim ersten Aufruf ist open_slots=1,
    // also oeffnet sich die Gruppe standardmaessig.
    await acc.openGroup('Voll-Bereich');
    await acc.takeOverLeaf('Voll-Aufgabe');

    // Nach Reload ist Voll-Aufgabe fuer ALICE als "Bereits zugesagt" markiert.
    // Damit BOB den naechsten Test-Teil als neutraler Beobachter laufen kann.
    await acc.openGroup('Voll-Bereich');
    await acc.expectLeafAlreadyAssigned('Voll-Aufgabe');
  });

  // =========================================================================
  // Eigentliche Tests: BOB als unbeteiligtes Mitglied
  // =========================================================================

  test('Accordion rendert bei Flag=1 und vorhandener Baumstruktur', async ({ page }) => {
    const login = new LoginPage(page);
    const acc   = new MemberEventAccordionPage(page);

    await login.loginAs(BOB);
    await acc.goto(treeEventId);

    await acc.expectAccordionVisible();
    await expect(acc.filterToggle()).toBeVisible();

    // Alle drei Struktur-Elemente sind initial im Accordion.
    await acc.expectGroupVisible('Testbereich');
    await acc.expectGroupVisible('Voll-Bereich');
    await acc.expectLeafVisible('Unbegrenzt-Aufgabe');
  });

  test('Filter-Toggle blendet volle Leaves aus', async ({ page }) => {
    const login = new LoginPage(page);
    const acc   = new MemberEventAccordionPage(page);

    await login.loginAs(BOB);
    await acc.goto(treeEventId);

    // Beide Gruppen aufklappen, damit Leaves im DOM sichtbar sind.
    await acc.openGroup('Testbereich');
    await acc.openGroup('Voll-Bereich');

    // Vor Filter: Offen-Aufgabe und Voll-Aufgabe beide sichtbar.
    await acc.expectLeafVisible('Offen-Aufgabe');
    await acc.expectLeafVisible('Voll-Aufgabe');

    // Filter aktivieren.
    await acc.enableFilter();

    // Voll-Aufgabe (data-open-count="0") ist ausgeblendet, Offen-Aufgabe
    // (data-open-count="5") bleibt sichtbar.
    await acc.expectLeafHidden('Voll-Aufgabe');
    await acc.expectLeafVisible('Offen-Aufgabe');
    // Unbegrenzt-Aufgabe (kein data-open-count-Attribut) bleibt auch sichtbar.
    await acc.expectLeafVisible('Unbegrenzt-Aufgabe');

    // Filter wieder aus -> Voll-Aufgabe wieder sichtbar.
    await acc.disableFilter();
    await acc.expectLeafVisible('Voll-Aufgabe');
  });

  test('Filter-Toggle blendet komplett belegte Gruppen aus', async ({ page }) => {
    const login = new LoginPage(page);
    const acc   = new MemberEventAccordionPage(page);

    await login.loginAs(BOB);
    await acc.goto(treeEventId);

    // Vor Filter: Voll-Bereich-Gruppe sichtbar.
    await acc.expectGroupVisible('Voll-Bereich');

    // Filter aktivieren.
    await acc.enableFilter();

    // Voll-Bereich hat open_slots_subtree=0 und wird ausgeblendet.
    await acc.expectGroupHidden('Voll-Bereich');
    // Testbereich hat open_slots_subtree=5 und bleibt sichtbar.
    await acc.expectGroupVisible('Testbereich');
  });

  test('Uebernehmen-Button im Accordion funktioniert', async ({ page }) => {
    const login = new LoginPage(page);
    const acc   = new MemberEventAccordionPage(page);

    // BOB uebernimmt die Offen-Aufgabe. Nach Reload sollte der Leaf-Status
    // auf "Bereits zugesagt" stehen. Das verifiziert, dass das eingebettete
    // _assign_form.php im Leaf weiterhin korrekt den Assignment-Pfad ruft.
    await login.loginAs(BOB);
    await acc.goto(treeEventId);
    await acc.openGroup('Testbereich');

    await acc.takeOverLeaf('Offen-Aufgabe');

    // Nach Reload: Leaf zeigt "Bereits zugesagt".
    await acc.openGroup('Testbereich');
    await acc.expectLeafAlreadyAssigned('Offen-Aufgabe');
  });

  test('Flag=0 rendert flache Karten-Ansicht, kein Accordion', async ({ page }) => {
    setE2eSetting('events.tree_editor_enabled', '0');
    try {
      const login = new LoginPage(page);
      const acc   = new MemberEventAccordionPage(page);

      await login.loginAs(BOB);
      await acc.goto(treeEventId);

      await acc.expectAccordionAbsent();
      await acc.expectFlatCardUiVisible();
    } finally {
      // Flag wieder an, damit afterAll konsistent runterfaehrt.
      setE2eSetting('events.tree_editor_enabled', '1');
    }
  });

  test('Setup: Admin legt flaches Event ohne Baumstruktur an', async ({ page }) => {
    // Separates Event ohne Tree-Editor-Aufbau: nur ein Top-Level-Task via
    // Bestand-Flach-UI. Keine Gruppen, kein parent_task_id.
    const login  = new LoginPage(page);
    const events = new AdminEventsPage(page);

    await login.loginAs(EVENT_ADMIN);
    flatEventId = await events.createEvent({
      title: flatEventTitle,
      description: 'Flach ohne Baumstruktur',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    await events.addTask(flatEventId, {
      title: 'Einfach-Aufgabe',
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
      taskType: 'aufgabe',
    });

    // Event veroeffentlichen.
    await events.gotoShow(flatEventId);
    await events.publish();
  });

  test('Event ohne Baumstruktur rendert flache Karten-Ansicht auch bei Flag=1', async ({ page }) => {
    // Frische Session fuer BOB — Cross-User-Login im selben Test bricht am
    // /login-Redirect (eingeloggter EVENT_ADMIN wird weiterverwiesen).
    const login = new LoginPage(page);
    const acc   = new MemberEventAccordionPage(page);

    await login.loginAs(BOB);
    await acc.goto(flatEventId);
    await acc.expectAccordionAbsent();
    await acc.expectFlatCardUiVisible();
    await expect(page.locator('.card .card-body')).toContainText('Einfach-Aufgabe');
  });
});
