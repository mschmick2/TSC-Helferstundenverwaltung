<?php
/**
 * Modul 6 I7e-A Phase 2 — Non-modaler Organisator-Editor.
 * Zweispaltiges Layout: links Tree-Widget (col-lg-8), rechts Sidebar
 * (col-lg-4). Unter dem lg-Breakpoint wird die Sidebar in ein Bootstrap-5
 * Offcanvas verschoben, das ueber einen Button im Tree-Header geoeffnet
 * wird.
 *
 * Die acht Tree-Action-POST-Routen hinter diesem Editor liegen unter
 * /organizer/events/{id}/tasks/*. Der Partial _task_tree_node.php
 * bekommt per $urlPrefix den passenden Prefix gesetzt und generiert
 * damit die data-endpoint-*-Attribute, die event-task-tree.js liest.
 *
 * @var \App\Models\Event $event
 * @var array $treeData        Tree-Root-Nodes aus TaskTreeAggregator::buildTree
 * @var array $flatList        Chronologisch sortierte Leafs (fuer Sidebar)
 * @var array $organizers      Datensaetze aus EventOrganizerRepository::listForEvent
 * @var array $summary         Belegungs-Summary (computeBelegungsSummary)
 * @var array $taskCategories  Liste der Kategorien (CategoryRepository)
 * @var string $csrfTokenString
 */
use App\Helpers\ViewHelper;

$eventIdForTree  = (int) $event->getId();
$csrfTokenString = $csrfTokenString ?? ($_SESSION['csrf_token'] ?? '');
$taskCategories  = $taskCategories ?? [];
$treeData        = $treeData ?? [];
$flatList        = $flatList ?? [];
$organizers      = $organizers ?? [];
$summary         = $summary ?? null;

// URL-Prefix Organizer-Kontext (muss auf '/tasks/' enden, weil das
// Partial direkt Endpunkt-Segmente anhaengt).
$urlPrefixOrganizer = '/organizer/events/' . $eventIdForTree . '/tasks/';

// Rekursiver Renderer: Closure faengt pro Aufruf $node/$depth und
// reicht die Organizer-URL-Prefix-Variable durch. Verhindert Scope-
// Leak (Projekt-Standard fuer rekursive Partials, siehe 05-frontend.md).
$renderTaskNode = function (array $node, int $depth) use (
    &$renderTaskNode, $csrfTokenString, $eventIdForTree, $urlPrefixOrganizer
): void {
    $csrfToken = $csrfTokenString;
    $eventId   = $eventIdForTree;
    $entityId  = $eventIdForTree;
    $context   = 'event';
    $urlPrefix = $urlPrefixOrganizer;
    include __DIR__ . '/../../admin/events/_task_tree_node.php';
};

$categoriesJson = json_encode(
    array_map(
        static fn ($c) => [
            'id'              => (int) $c->getId(),
            'name'            => $c->getName(),
            'is_contribution' => $c->isContribution(),
        ],
        $taskCategories
    ),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
?>

<h1 class="h3 mb-3">
    <i class="bi bi-tools" aria-hidden="true"></i>
    Editor: <?= ViewHelper::e($event->getTitle()) ?>
</h1>

<div class="row g-3">

    <!-- Hauptspalte: Tree-Widget -->
    <div class="col-12 col-lg-8">
        <section class="task-tree-editor"
                 id="task-tree-editor"
                 data-event-id="<?= $eventIdForTree ?>"
                 data-csrf-token="<?= ViewHelper::e($csrfTokenString) ?>"
                 data-endpoint-tree="<?= ViewHelper::url($urlPrefixOrganizer . 'tree') ?>"
                 data-endpoint-create="<?= ViewHelper::url($urlPrefixOrganizer . 'node') ?>"
                 data-endpoint-reorder="<?= ViewHelper::url($urlPrefixOrganizer . 'reorder') ?>"
                 data-categories="<?= ViewHelper::e($categoriesJson) ?>">

            <div class="d-flex align-items-center justify-content-between mb-2 gap-2 flex-wrap">
                <h2 class="h4 mb-0">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                    Aufgabenbaum
                </h2>
                <div class="d-flex gap-2">
                    <!-- Offcanvas-Trigger nur unter lg-Breakpoint -->
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm d-lg-none"
                            data-bs-toggle="offcanvas"
                            data-bs-target="#editorSidebarOffcanvas"
                            aria-controls="editorSidebarOffcanvas"
                            title="Sidebar einblenden"
                            aria-label="Sidebar einblenden">
                        <i class="bi bi-layout-sidebar-inset-reverse" aria-hidden="true"></i>
                        Sidebar
                    </button>

                    <button type="button" class="btn btn-primary btn-sm"
                            data-action="add-child"
                            data-parent-task-id=""
                            title="Top-Level-Knoten anlegen">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                        Knoten anlegen
                    </button>
                </div>
            </div>

            <?php include __DIR__ . '/../../admin/events/_task_edit_modal.php'; ?>

            <?php if (empty($treeData)): ?>
                <p class="text-muted mb-0">
                    Noch keine Aufgaben. Lege den ersten Knoten oben an &mdash; ein
                    Gruppen-Knoten fasst weitere Aufgaben zusammen, ein Aufgaben-
                    Knoten steht fuer eine konkrete Helfer-Taetigkeit.
                </p>
            <?php else: ?>
                <ul class="task-tree-root list-unstyled mb-0"
                    data-parent-task-id=""
                    data-endpoint-reorder="<?= ViewHelper::url($urlPrefixOrganizer . 'reorder') ?>">
                    <?php foreach ($treeData as $topNode): ?>
                        <?php $renderTaskNode($topNode, 0); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <noscript>
                <div class="alert alert-warning mt-3" role="alert">
                    <strong>JavaScript aus:</strong>
                    Der Aufgabenbaum-Editor (Drag &amp; Drop, Modal) braucht JavaScript.
                    Bitte die Event-Detailseite fuer die Aufgaben-Pflege nutzen.
                </div>
            </noscript>
        </section>
    </div>

    <!-- Sidebar (Desktop ab lg, rechts sticky) -->
    <div class="col-lg-4 d-none d-lg-block">
        <div class="editor-sidebar-sticky">
            <?php require __DIR__ . '/../../events/_editor_sidebar.php'; ?>
        </div>
    </div>
</div>

<!-- Offcanvas-Sidebar fuer Mobile / Tablet (unter lg) -->
<div class="offcanvas offcanvas-end d-lg-none"
     tabindex="-1"
     id="editorSidebarOffcanvas"
     aria-labelledby="editorSidebarOffcanvasLabel">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title h5 mb-0" id="editorSidebarOffcanvasLabel">
            <i class="bi bi-layout-sidebar-inset-reverse" aria-hidden="true"></i>
            Sidebar
        </h2>
        <button type="button"
                class="btn-close"
                data-bs-dismiss="offcanvas"
                aria-label="Schliessen"></button>
    </div>
    <div class="offcanvas-body">
        <?php require __DIR__ . '/../../events/_editor_sidebar.php'; ?>
    </div>
</div>

<script src="<?= ViewHelper::url('/js/vendor/sortablejs/Sortable.min.js') ?>"></script>
<script src="<?= ViewHelper::url('/js/event-task-tree.js') ?>"></script>
