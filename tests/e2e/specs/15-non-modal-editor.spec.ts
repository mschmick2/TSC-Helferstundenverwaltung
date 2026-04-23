import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { AdminEventsPage } from '../pages/AdminEventsPage';
import { AdminEventTreePage } from '../pages/AdminEventTreePage';
import { NonModalEditorPage } from '../pages/NonModalEditorPage';
import { EVENT_ADMIN, BOB } from '../fixtures/users';
import { setE2eSetting } from '../fixtures/db-helper';

/**
 * Modul 6 I7e-A Phase 3 Teil 2 — Playwright-E2E fuer den non-modalen Editor.
 *
 * Deckt die Phase-2/2c-Ergaenzungen ab:
 *   - Zwei-Spalten-Layout mit Tree + Sidebar (Desktop).
 *   - Sidebar-Panels: Event-Meta, Belegung (Phase-2c-Bug-Fix:
 *     zusagen_aktiv !== helpers_total), Aufgaben chronologisch.
 *   - Scroll-Highlight per Sidebar-Klick (Phase 2).
 *   - Per-Node-Toggle + Expand/Collapse-All (Phase 2c).
 *   - Editor-Entdeckbarkeit: Links auf Admin-Show, Admin-Edit,
 *     Organizer-Index.
 *   - Mobile-Offcanvas-Flow.
 *
 * Serial: Setup-Event wird einmal angelegt, spaetere Tests wiederverwenden
 * die Event-ID (Pattern wie Specs 10/13).
 */
test.describe.configure({ mode: 'serial' });

function toLocalDateTime(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}`
  );
}

test.describe('Non-modaler Editor (I7e-A Phase 2/2c)', () => {
  const now = new Date();
  const eventStart = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
  const eventEnd   = new Date(eventStart.getTime() + 12 * 60 * 60 * 1000);
  const startAt    = toLocalDateTime(eventStart);
  const endAt      = toLocalDateTime(eventEnd);

  // Slot-Zeiten innerhalb des Event-Fensters.
  const slot1Start = toLocalDateTime(new Date(eventStart.getTime() +  1 * 60 * 60 * 1000));
  const slot1End   = toLocalDateTime(new Date(eventStart.getTime() +  3 * 60 * 60 * 1000));
  const slot2Start = toLocalDateTime(new Date(eventStart.getTime() +  5 * 60 * 60 * 1000));
  const slot2End   = toLocalDateTime(new Date(eventStart.getTime() +  7 * 60 * 60 * 1000));
  const slot3Start = toLocalDateTime(new Date(eventStart.getTime() +  8 * 60 * 60 * 1000));
  const slot3End   = toLocalDateTime(new Date(eventStart.getTime() + 10 * 60 * 60 * 1000));

  const eventTitle = `E2E Editor ${now.getTime()}`;
  let eventId = 0;

  test.beforeAll(() => {
    setE2eSetting('events.tree_editor_enabled', '1');
  });

  test.afterAll(() => {
    setE2eSetting('events.tree_editor_enabled', '0');
  });

  // =========================================================================
  // Setup
  // =========================================================================

  test('Setup: Event mit zwei Gruppen, vier Leaves und einem variable-Slot-Leaf', async ({ page }) => {
    const login  = new LoginPage(page);
    const events = new AdminEventsPage(page);
    const tree   = new AdminEventTreePage(page);

    await login.loginAs(EVENT_ADMIN);
    eventId = await events.createEvent({
      title: eventTitle,
      description: 'I7e-A Phase 3 Teil 2 Setup-Event',
      location: 'Vereinsheim',
      startAt,
      endAt,
      organizerEmail: EVENT_ADMIN.email,
      cancelDeadlineHours: 2,
    });
    expect(eventId).toBeGreaterThan(0);

    // Struktur:
    //   Aufbau-/Abbau (Gruppe)
    //     Aufbau        (fix, slot1, ziel=2, 2h)
    //     Abbau         (fix, slot3, ziel=2, 2h)
    //   Kuechendienst (Gruppe)
    //     Essensausgabe (fix, slot2, ziel=3, 2h)
    //     Spuelen       (fix, slot2, ziel=1, 2h)
    //   Kuchen backen   (Top-Level, variabel, unbegrenzt, 1h)
    //
    // Summe Helfer-Soll: 2+2+3+1 = 8. Kuchen unbegrenzt → nicht im Soll.
    // Zusagen aktiv: 0 (wird im Test gegen die Summary-Zahlen geprueft).
    await tree.gotoEdit(eventId);

    await tree.createTopLevelNode({ isGroup: true, title: 'Aufbau-/Abbau' });
    await tree.createChildUnder('Aufbau-/Abbau', {
      isGroup: false,
      title: 'Aufbau',
      slotMode: 'fix',
      startAt: slot1Start,
      endAt:   slot1End,
      capacityMode: 'ziel',
      capacityTarget: 2,
      hoursDefault: 2,
    });
    await tree.createChildUnder('Aufbau-/Abbau', {
      isGroup: false,
      title: 'Abbau',
      slotMode: 'fix',
      startAt: slot3Start,
      endAt:   slot3End,
      capacityMode: 'ziel',
      capacityTarget: 2,
      hoursDefault: 2,
    });

    await tree.createTopLevelNode({ isGroup: true, title: 'Kuechendienst' });
    await tree.createChildUnder('Kuechendienst', {
      isGroup: false,
      title: 'Essensausgabe',
      slotMode: 'fix',
      startAt: slot2Start,
      endAt:   slot2End,
      capacityMode: 'ziel',
      capacityTarget: 3,
      hoursDefault: 2,
    });
    await tree.createChildUnder('Kuechendienst', {
      isGroup: false,
      title: 'Spuelen',
      slotMode: 'fix',
      startAt: slot2Start,
      endAt:   slot2End,
      capacityMode: 'ziel',
      capacityTarget: 1,
      hoursDefault: 2,
    });

    await tree.createTopLevelNode({
      isGroup: false,
      title: 'Kuchen backen',
      slotMode: 'variabel',
      capacityMode: 'unbegrenzt',
      hoursDefault: 1,
    });
  });

  // =========================================================================
  // Gruppe A — Editor-Laden und Layout
  // =========================================================================

  test('A1: Admin laedt /admin/events/{id}/editor, Hauptbereich + Desktop-Sidebar sichtbar', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'headed' && testInfo.project.name !== 'headless',
      'Desktop-Only: Zwei-Spalten-Layout ist ab lg (>=992 px) sichtbar.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);

    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    await expect(editor.mainColumn()).toBeVisible();
    await expect(editor.sidebarDesktop()).toBeVisible();
    // Breadcrumbs: letzter Eintrag ist "Editor".
    await expect(page.locator('.breadcrumb .breadcrumb-item').last()).toContainText('Editor');
  });

  test('A2: Organisator laedt /organizer/events/{id}/editor analog', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'headed' && testInfo.project.name !== 'headless',
      'Desktop-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);

    // EVENT_ADMIN ist Organisator (createEvent setzt ihn als solchen).
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoOrganizer(eventId);

    await expect(editor.mainColumn()).toBeVisible();
    await expect(editor.sidebarDesktop()).toBeVisible();
    // Organizer-Breadcrumb endet mit "... — Editor".
    await expect(page.locator('.breadcrumb .breadcrumb-item').last()).toContainText('Editor');
  });

  test('A3: Fremder User bekommt 403 auf /organizer/events/{id}/editor', async ({ page }) => {
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);

    // BOB ist weder Admin noch Organisator dieses Events.
    await login.loginAs(BOB);
    const response = await editor.requestOrganizer(eventId);
    expect(response.status()).toBe(403);
  });

  test('A4: Feature-Flag=0 liefert 404 auf beiden Editor-Routen', async ({ page }) => {
    setE2eSetting('events.tree_editor_enabled', '0');
    try {
      const login  = new LoginPage(page);
      const editor = new NonModalEditorPage(page);
      await login.loginAs(EVENT_ADMIN);

      const adminResp = await editor.requestAdmin(eventId);
      expect(adminResp.status()).toBe(404);

      const organizerResp = await editor.requestOrganizer(eventId);
      expect(organizerResp.status()).toBe(404);
    } finally {
      setE2eSetting('events.tree_editor_enabled', '1');
    }
  });

  // =========================================================================
  // Gruppe B — Sidebar-Panels
  // =========================================================================

  test('B1: Panel 1 zeigt Titel, Zeitraum und Organisator', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'headed' && testInfo.project.name !== 'headless',
      'Desktop-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    await editor.expectThreeSidebarPanels();

    const panel1 = editor.sidebarDesktop().locator('section.card').first();
    await expect(panel1).toContainText(eventTitle);
    await expect(panel1).toContainText(/\d{2}\.\d{2}\.\d{4}/); // Datum dd.mm.yyyy
    // EVENT_ADMIN ist Organisator; Panel 1 listet ihn mit Nachname, Vorname.
    await expect(panel1).toContainText(EVENT_ADMIN.nachname);
    await expect(panel1).toContainText(EVENT_ADMIN.vorname);
  });

  test('B2: Panel 2 zeigt Status-Verteilung (empty/partial/full)', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'headed' && testInfo.project.name !== 'headless',
      'Desktop-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    const panel2 = editor.sidebarDesktop().locator('section.card').nth(1);
    // Alle vier fix-Leaves haben ziel=2..3, 0 Zusagen → empty.
    // Kuchen hat unbegrenzte Kapazitaet und 0 Zusagen → empty (TaskStatus::forLeaf).
    // Also: 5 empty / 0 partial / 0 full.
    await expect(panel2.locator('.task-status-badge--empty')).toContainText('5');
    await expect(panel2.locator('.task-status-badge--partial')).toContainText('0');
    await expect(panel2.locator('.task-status-badge--full')).toContainText('0');
  });

  test('B3: Panel 2 zeigt "Aktive Zusagen" (0) und "Helfer-Soll" (8) als separate Werte', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'headed' && testInfo.project.name !== 'headless',
      'Desktop-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    // Phase-2c-Bug-Fix: "Aktive Zusagen" darf NICHT die Capacity-Soll-Summe
    // anzeigen. Seed: 0 Zusagen, Soll = 2+2+3+1 = 8 (Kuchen ist unbegrenzt).
    const aktiv = await editor.readSummaryValue('Aktive Zusagen');
    const soll  = await editor.readSummaryValue('Helfer-Soll');
    expect(aktiv).toBe('0');
    expect(soll).toBe('8');
  });

  test('B4: Panel 3 listet alle fuenf Leaves chronologisch', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'headed' && testInfo.project.name !== 'headless',
      'Desktop-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    const buttons = await editor.scrollTargetButtons();
    await expect(buttons).toHaveCount(5);

    // Reihenfolge aus Sort-by-StartAt (Kuchen backen hat kein start_at →
    // wandert ans Ende per sortFlatListByStart).
    const texts = await buttons.allTextContents();
    const titles = texts.map((t) => {
      if (t.includes('Aufbau') && !t.includes('Abbau-')) return 'Aufbau';
      if (t.includes('Essensausgabe')) return 'Essensausgabe';
      if (t.includes('Spuelen')) return 'Spuelen';
      if (t.includes('Abbau') && !t.includes('Aufbau-')) return 'Abbau';
      if (t.includes('Kuchen')) return 'Kuchen backen';
      return '?';
    });
    // Aufbau (slot1 +1h), Essensausgabe (+5h), Spuelen (+5h), Abbau (+8h),
    // Kuchen (kein Start → ans Ende).
    expect(titles[0]).toBe('Aufbau');
    expect(titles[titles.length - 1]).toBe('Kuchen backen');
  });

  // =========================================================================
  // Gruppe C — Sidebar-Scroll-Highlight
  // =========================================================================

  test('C1: Klick auf Sidebar-Eintrag setzt task-node--highlighted auf Tree-Node', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'headed' && testInfo.project.name !== 'headless',
      'Desktop-Only (Sidebar-Panel-3-Button).'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    // Task-ID aus dem ersten Scroll-Target-Button extrahieren.
    const firstBtn = editor.sidebarDesktop().locator('[data-sidebar-scroll-target]').first();
    const taskIdAttr = await firstBtn.getAttribute('data-sidebar-scroll-target');
    expect(taskIdAttr).not.toBeNull();
    const taskId = parseInt(taskIdAttr as string, 10);
    expect(taskId).toBeGreaterThan(0);

    await firstBtn.click();

    // Highlight-Klasse wird sofort gesetzt und nach 1500 ms wieder entfernt.
    await expect(editor.treeNodeById(taskId)).toHaveClass(/task-node--highlighted/);
  });

  test('C2: Scroll-Highlight auf Leaf in eingeklappter Gruppe (Follow-up — Skip)', async ({ page }, testInfo) => {
    // Der Phase-2-JS-Code ruft scrollIntoView aber expandiert die Eltern-
    // Gruppen nicht automatisch. Wenn die Gruppe kollabiert ist, bleibt der
    // Leaf weiter display:none und ist nicht sichtbar. Auto-Expand bei
    // Scroll-Target ist als Follow-up markiert; Test hier nur als
    // Platzhalter, damit das Fehlen bewusst dokumentiert bleibt.
    test.skip(true, 'Auto-Expand der Eltern-Gruppen beim Scroll-Highlight ist Follow-up.');
    await Promise.resolve(page); // eslint-disable-line @typescript-eslint/no-unused-expressions
    void testInfo;
  });

  test('C3: Mobile — Klick im Offcanvas schliesst Offcanvas und hebt Tree-Node hervor', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name === 'headed' || testInfo.project.name === 'headless',
      'Mobile-Only: Offcanvas-Trigger ist d-lg-none.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    await editor.openOffcanvas();

    const firstBtn = editor.sidebarOffcanvas().locator('[data-sidebar-scroll-target]').first();
    const taskIdAttr = await firstBtn.getAttribute('data-sidebar-scroll-target');
    const taskId = parseInt(taskIdAttr as string, 10);

    await firstBtn.click();

    // Offcanvas schliesst (Phase-2-JS ruft bootstrap.Offcanvas.hide()).
    await editor.expectOffcanvasClosed();
    // Highlight-Klasse ist am Tree-Node gesetzt.
    await expect(editor.treeNodeById(taskId)).toHaveClass(/task-node--highlighted/);
  });

  // =========================================================================
  // Gruppe D — Expand/Collapse-All und Per-Node-Toggle
  // =========================================================================

  test('D1: "Alle einklappen" setzt task-node--collapsed auf allen Gruppen', async ({ page }) => {
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    await editor.clickCollapseAll();

    await expect(editor.groupNodeByTitle('Aufbau-/Abbau')).toHaveClass(/task-node--collapsed/);
    await expect(editor.groupNodeByTitle('Kuechendienst')).toHaveClass(/task-node--collapsed/);
  });

  test('D2: "Alle ausklappen" entfernt task-node--collapsed von allen Gruppen', async ({ page }) => {
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    // Erst alle einklappen, dann wieder aufklappen — bestaetigt beide Richtungen.
    await editor.clickCollapseAll();
    await expect(editor.groupNodeByTitle('Aufbau-/Abbau')).toHaveClass(/task-node--collapsed/);

    await editor.clickExpandAll();
    await expect(editor.groupNodeByTitle('Aufbau-/Abbau')).not.toHaveClass(/task-node--collapsed/);
    await expect(editor.groupNodeByTitle('Kuechendienst')).not.toHaveClass(/task-node--collapsed/);
  });

  test('D3: Per-Node-Chevron togglet nur eine Gruppe', async ({ page }) => {
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    // Start: alle aufgeklappt.
    await editor.clickExpandAll();

    // Nur "Aufbau-/Abbau" einklappen.
    await editor.toggleGroup('Aufbau-/Abbau');

    await expect(editor.groupNodeByTitle('Aufbau-/Abbau')).toHaveClass(/task-node--collapsed/);
    await expect(editor.groupNodeByTitle('Kuechendienst')).not.toHaveClass(/task-node--collapsed/);

    // Wieder aufklappen, damit nachfolgende Tests einen deterministischen Zustand haben.
    await editor.toggleGroup('Aufbau-/Abbau');
  });

  test('D4: Drag-and-Drop auf eingeklappter Gruppe (Follow-up — Skip)', async ({ page }, testInfo) => {
    // Spec 10 skippt Drag-and-Drop bewusst (SortableJS via mouse.down/move/up
    // ist laut Erfahrung flaky). Der non-destruktive Collapse-Mechanismus
    // (display:none am <ul>) haelt die DOM-Struktur intakt; ein manueller
    // Browser-Smoke bestaetigt das bereits. Runtime-Test bleibt Follow-up.
    test.skip(true, 'D&D ist in Spec 10 bewusst ausgeschlossen; gleiches Rationale hier.');
    await Promise.resolve(page); // eslint-disable-line @typescript-eslint/no-unused-expressions
    void testInfo;
  });

  // =========================================================================
  // Gruppe E — Editor-Entdeckbarkeit (Phase 2c Links)
  // =========================================================================

  test('E1: /admin/events/{id} zeigt "Editor-Ansicht"-Button und verlinkt zum Editor', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/events/${eventId}`);

    const editorLink = page.locator(
      `a[href$="/admin/events/${eventId}/editor"]`,
      { hasText: /Editor-Ansicht/i }
    );
    await expect(editorLink).toBeVisible();

    await editorLink.click();
    await expect(page).toHaveURL(new RegExp(`/admin/events/${eventId}/editor$`));
  });

  test('E2: /organizer/events (Event-Card) zeigt "Editor-Ansicht"-Button', async ({ page }) => {
    // Der Prompt nahm eine /organizer/events/{id}-Detail-Seite an — die
    // existiert nicht. Phase-2c-Link sitzt in der Event-Card auf der
    // Organizer-Uebersicht.
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto('/organizer/events');

    const editorLink = page.locator(
      `a[href$="/organizer/events/${eventId}/editor"]`,
      { hasText: /Editor-Ansicht/i }
    );
    await expect(editorLink).toBeVisible();

    await editorLink.click();
    await expect(page).toHaveURL(new RegExp(`/organizer/events/${eventId}/editor$`));
  });

  test('E3: /admin/events/{id}/edit zeigt Info-Banner mit "Editor oeffnen"-Button', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(EVENT_ADMIN);
    await page.goto(`/admin/events/${eventId}/edit`);

    const banner = page.locator('.alert.alert-info', { hasText: /Neue Editor-Ansicht/i });
    await expect(banner).toBeVisible();

    const cta = banner.getByRole('link', { name: /Editor oeffnen/i });
    await expect(cta).toBeVisible();

    await cta.click();
    await expect(page).toHaveURL(new RegExp(`/admin/events/${eventId}/editor$`));
  });

  // =========================================================================
  // Gruppe F — Mobile-Offcanvas-Flow
  // =========================================================================

  test('F1: Mobile — Desktop-Sidebar versteckt, Offcanvas-Trigger in Toolbar sichtbar', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name === 'headed' || testInfo.project.name === 'headless',
      'Mobile-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    // Desktop-Spalte ist d-none ausserhalb lg → nicht sichtbar.
    await expect(editor.sidebarDesktop()).not.toBeVisible();
    // Offcanvas-Trigger-Button (d-lg-none) ist in der Toolbar sichtbar.
    await expect(editor.offcanvasTrigger()).toBeVisible();
  });

  test('F2: Mobile — Offcanvas oeffnet und zeigt alle drei Panels', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name === 'headed' || testInfo.project.name === 'headless',
      'Mobile-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    await editor.openOffcanvas();

    const panels = editor.sidebarOffcanvas().locator('section.card');
    await expect(panels).toHaveCount(3);
  });

  test('F3: Mobile — Offcanvas schliesst ueber Schliessen-Button', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name === 'headed' || testInfo.project.name === 'headless',
      'Mobile-Only.'
    );
    const login  = new LoginPage(page);
    const editor = new NonModalEditorPage(page);
    await login.loginAs(EVENT_ADMIN);
    await editor.gotoAdmin(eventId);

    await editor.openOffcanvas();
    await editor.offcanvas().locator('.btn-close').click();
    await editor.expectOffcanvasClosed();
  });
});
