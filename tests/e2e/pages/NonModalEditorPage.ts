import { expect, Locator, Page, APIResponse } from '@playwright/test';

/**
 * Page-Object fuer den non-modalen Editor (Modul 6 I7e-A Phase 2/2c).
 *
 * Deckt beide Routen ab:
 *   - /admin/events/{id}/editor
 *   - /organizer/events/{id}/editor
 *
 * DOM-Grundstruktur (aus src/app/Views/admin|organizer/events/editor.php):
 *
 *   .row.g-3
 *     .col-12.col-lg-8              - Hauptspalte (Tree-Widget)
 *       #task-tree-editor
 *         .task-tree-editor__toolbar
 *           button[data-bs-target="#editorSidebarOffcanvas"] (nur d-lg-none)
 *           button[data-action="expand-all"]
 *           button[data-action="collapse-all"]
 *           button[data-action="add-child"][data-parent-task-id=""]
 *         ul.task-tree-root > li.task-node[data-task-id="N"]
 *           .task-node--group | .task-node--leaf
 *           .task-node--collapsed          (Phase-2c-Toggle-State)
 *           .task-node--highlighted        (Phase-2-Scroll-Highlight)
 *
 *     .col-lg-4.d-none.d-lg-block   - Desktop-Sidebar-Spalte
 *       .editor-sidebar-sticky > aside.editor-sidebar
 *         section.card (Panel-1: Event-Meta)
 *         section.card (Panel-2: Belegung)
 *         section.card (Panel-3: Aufgaben-Liste)
 *
 *   .offcanvas#editorSidebarOffcanvas    - Mobile-Offcanvas
 *     aside.editor-sidebar (zweite Instanz fuer Mobile)
 *
 * Wichtig: auf Desktop-Viewport sind BEIDE aside.editor-sidebar im DOM —
 * die Desktop-Spalte ist sichtbar, das Offcanvas ist display:none. Auf
 * Mobile ist es umgekehrt. Scoped Locator-Methoden unten beruecksichtigen
 * das, damit Tests nicht versehentlich auf die unsichtbare Instanz
 * zugreifen.
 */
export class NonModalEditorPage {
  constructor(private readonly page: Page) {}

  // =========================================================================
  // Navigation
  // =========================================================================

  async gotoAdmin(eventId: number): Promise<void> {
    await this.page.goto(`/admin/events/${eventId}/editor`);
  }

  async gotoOrganizer(eventId: number): Promise<void> {
    await this.page.goto(`/organizer/events/${eventId}/editor`);
  }

  async requestAdmin(eventId: number): Promise<APIResponse> {
    return this.page.request.get(`/admin/events/${eventId}/editor`);
  }

  async requestOrganizer(eventId: number): Promise<APIResponse> {
    return this.page.request.get(`/organizer/events/${eventId}/editor`);
  }

  // =========================================================================
  // Struktur-Locators
  // =========================================================================

  /** Die Hauptspalte mit dem Tree-Widget (auf allen Viewports sichtbar). */
  mainColumn(): Locator {
    return this.page.locator('#task-tree-editor');
  }

  /**
   * Die Desktop-Sidebar-Spalte (d-none d-lg-block). Nur sichtbar ab lg-
   * Breakpoint (992 px).
   */
  sidebarDesktop(): Locator {
    return this.page.locator('.editor-sidebar-sticky aside.editor-sidebar');
  }

  /** Die Offcanvas-Sidebar (nur unter lg verfuegbar). */
  offcanvas(): Locator {
    return this.page.locator('#editorSidebarOffcanvas');
  }

  /** Die aside.editor-sidebar innerhalb des Offcanvas. */
  sidebarOffcanvas(): Locator {
    return this.offcanvas().locator('aside.editor-sidebar');
  }

  /** Offcanvas-Trigger-Button aus der Tree-Toolbar (d-lg-none). */
  offcanvasTrigger(): Locator {
    return this.page.locator(
      '.task-tree-editor__toolbar [data-bs-target="#editorSidebarOffcanvas"]'
    );
  }

  /**
   * Gibt den passenden Sidebar-Locator fuer den aktuellen Viewport zurueck:
   * auf Desktop die sticky-Spalte, auf Mobile die Offcanvas-Instanz.
   * Caller muss bei Mobile vorher openOffcanvas() rufen, damit der
   * Offcanvas tatsaechlich sichtbar ist.
   */
  async currentSidebar(): Promise<Locator> {
    const viewportWidth = this.page.viewportSize()?.width ?? 0;
    return viewportWidth >= 992 ? this.sidebarDesktop() : this.sidebarOffcanvas();
  }

  // =========================================================================
  // Tree-Node-Locator
  // =========================================================================

  /** Tree-Knoten als <li.task-node> per data-task-id. */
  treeNodeById(taskId: number): Locator {
    return this.page.locator(`#task-tree-editor li.task-node[data-task-id="${taskId}"]`);
  }

  /**
   * Tree-Knoten per Titel (sucht den Edit-Trigger-Button und steigt auf den
   * zugehoerigen Task-Node-<li> auf — gleiches XPath-Pattern wie in
   * AdminEventTreePage).
   */
  treeNodeByTitle(title: string): Locator {
    return this.page
      .locator('#task-tree-editor button.task-node__edit-trigger', { hasText: title })
      .first()
      .locator(
        'xpath=ancestor::li[contains(concat(" ", normalize-space(@class), " "), " task-node ")][1]'
      );
  }

  /** Gruppe nach Titel. */
  groupNodeByTitle(title: string): Locator {
    return this.treeNodeByTitle(title);
  }

  // =========================================================================
  // Toolbar-Aktionen
  // =========================================================================

  expandAllButton(): Locator {
    return this.page.locator('#task-tree-editor [data-action="expand-all"]');
  }

  collapseAllButton(): Locator {
    return this.page.locator('#task-tree-editor [data-action="collapse-all"]');
  }

  async clickExpandAll(): Promise<void> {
    await this.expandAllButton().click();
  }

  async clickCollapseAll(): Promise<void> {
    await this.collapseAllButton().click();
  }

  // =========================================================================
  // Per-Node-Chevron (Phase 2c)
  // =========================================================================

  /**
   * Chevron-Toggle-Button an einer Gruppen-Row. Nur Gruppen haben den
   * Button — Leaves haben einen unsichtbaren Spacer.
   */
  toggleButtonForGroup(title: string): Locator {
    return this.groupNodeByTitle(title)
      .locator(':scope > .task-node__row [data-action="toggle-node"]')
      .first();
  }

  async toggleGroup(title: string): Promise<void> {
    await this.toggleButtonForGroup(title).click();
  }

  // =========================================================================
  // Sidebar-Panels (Assertion-Helper)
  // =========================================================================

  /** Alle <section class="card"> innerhalb des aktuellen Sidebars. */
  async sidebarPanels(): Promise<Locator> {
    const sidebar = await this.currentSidebar();
    return sidebar.locator('section.card');
  }

  async expectThreeSidebarPanels(): Promise<void> {
    const panels = await this.sidebarPanels();
    await expect(panels).toHaveCount(3);
  }

  /**
   * Liest eine beschriftete Zahl aus Panel 2 (Belegungs-dl). Fuer jedes
   * <dt>-Label gibt es ein folgendes <dd>-Element mit dem Wert. Nutzt den
   * nth-of-type-Ansatz: Panel 2 ist die zweite card-Section in der
   * aktuellen Sidebar.
   */
  async readSummaryValue(label: string): Promise<string> {
    const sidebar = await this.currentSidebar();
    const panel2 = sidebar.locator('section.card').nth(1);
    // <dt>Label</dt><dd>Wert</dd>: Wert = naechstes Geschwister.
    const dd = panel2.locator('dt', { hasText: label }).locator('xpath=following-sibling::dd[1]');
    return (await dd.first().textContent() ?? '').trim();
  }

  // =========================================================================
  // Panel 3 — Sidebar-Scroll-Targets
  // =========================================================================

  /**
   * Alle Scroll-Target-Buttons im aktuellen Sidebar (Panel 3). Auf Desktop
   * scopen wir die Desktop-Sidebar, auf Mobile das Offcanvas — sonst
   * wuerde die Desktop-Instanz (d-none) matchen und der Klick ginge ins
   * Leere.
   */
  async scrollTargetButtons(): Promise<Locator> {
    const sidebar = await this.currentSidebar();
    return sidebar.locator('[data-sidebar-scroll-target]');
  }

  async clickScrollTarget(taskId: number): Promise<void> {
    const sidebar = await this.currentSidebar();
    await sidebar.locator(`[data-sidebar-scroll-target="${taskId}"]`).first().click();
  }

  // =========================================================================
  // Offcanvas (Mobile) — Open/Close
  // =========================================================================

  async openOffcanvas(): Promise<void> {
    await this.offcanvasTrigger().click();
    await expect(this.offcanvas()).toHaveClass(/show/);
  }

  async expectOffcanvasClosed(): Promise<void> {
    // Entweder nicht vorhanden (falls Bootstrap es nach hide() aus dem DOM
    // entfernt) oder ohne .show-Klasse.
    await expect(this.offcanvas()).not.toHaveClass(/show/);
  }
}
