<?php
/**
 * Modul 6 I7e-A Phase 2 — Non-modaler Admin-Editor.
 * Zweispaltiges Layout identisch zum Organisator-Editor, unterscheidet
 * sich nur im URL-Prefix (/admin/events/{id}/tasks/ statt
 * /organizer/events/{id}/tasks/).
 *
 * Der Modal-Editor unter /admin/events/{id}/edit bleibt unveraendert.
 * Dieser non-modale Editor ist Feature-Parity-Gegenstueck zum neuen
 * Organisator-Flow.
 *
 * @var \App\Models\Event $event
 * @var array $treeData        Tree-Root-Nodes aus TaskTreeAggregator::buildTree
 * @var array $flatList        Chronologisch sortierte Leafs (fuer Sidebar)
 * @var array $organizers      Datensaetze aus EventOrganizerRepository::listForEvent
 * @var array $summary         Belegungs-Summary (computeBelegungsSummary)
 * @var array $taskCategories  Liste der Kategorien
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

$urlPrefixAdmin = '/admin/events/' . $eventIdForTree . '/tasks/';

$renderTaskNode = function (array $node, int $depth) use (
    &$renderTaskNode, $csrfTokenString, $eventIdForTree, $urlPrefixAdmin
): void {
    $csrfToken = $csrfTokenString;
    $eventId   = $eventIdForTree;
    $entityId  = $eventIdForTree;
    $context   = 'event';
    $urlPrefix = $urlPrefixAdmin;
    include __DIR__ . '/_task_tree_node.php';
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

    <div class="col-12 col-lg-8">
        <section class="task-tree-editor"
                 id="task-tree-editor"
                 data-event-id="<?= $eventIdForTree ?>"
                 data-csrf-token="<?= ViewHelper::e($csrfTokenString) ?>"
                 data-endpoint-tree="<?= ViewHelper::url($urlPrefixAdmin . 'tree') ?>"
                 data-endpoint-create="<?= ViewHelper::url($urlPrefixAdmin . 'node') ?>"
                 data-endpoint-reorder="<?= ViewHelper::url($urlPrefixAdmin . 'reorder') ?>"
                 data-categories="<?= ViewHelper::e($categoriesJson) ?>">

            <div class="d-flex align-items-center justify-content-between mb-2 gap-2 flex-wrap">
                <h2 class="h4 mb-0">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                    Aufgabenbaum
                </h2>
                <div class="task-tree-editor__toolbar d-flex gap-2">
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm d-lg-none"
                            data-bs-toggle="offcanvas"
                            data-bs-target="#editorSidebarOffcanvas"
                            aria-controls="editorSidebarOffcanvas"
                            title="Sidebar einblenden"
                            aria-label="Sidebar einblenden">
                        <i class="bi bi-layout-sidebar-inset-reverse" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">Sidebar</span>
                    </button>

                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-action="expand-all"
                            title="Alle Gruppen ausklappen"
                            aria-label="Alle Gruppen ausklappen">
                        <i class="bi bi-arrows-expand" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">Alle ausklappen</span>
                    </button>

                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-action="collapse-all"
                            title="Alle Gruppen einklappen"
                            aria-label="Alle Gruppen einklappen">
                        <i class="bi bi-arrows-collapse" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">Alle einklappen</span>
                    </button>

                    <button type="button" class="btn btn-primary btn-sm"
                            data-action="add-child"
                            data-parent-task-id=""
                            title="Top-Level-Knoten anlegen"
                            aria-label="Top-Level-Knoten anlegen">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">Knoten anlegen</span>
                    </button>
                </div>
            </div>

            <?php include __DIR__ . '/_task_edit_modal.php'; ?>

            <?php if (empty($treeData)): ?>
                <p class="text-muted mb-0">
                    Noch keine Aufgaben. Lege den ersten Knoten oben an &mdash; ein
                    Gruppen-Knoten fasst weitere Aufgaben zusammen, ein Aufgaben-
                    Knoten steht fuer eine konkrete Helfer-Taetigkeit.
                </p>
            <?php else: ?>
                <ul class="task-tree-root list-unstyled mb-0"
                    data-parent-task-id=""
                    data-endpoint-reorder="<?= ViewHelper::url($urlPrefixAdmin . 'reorder') ?>">
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

    <div class="col-lg-4 d-none d-lg-block">
        <div class="editor-sidebar-sticky">
            <?php require __DIR__ . '/../../events/_editor_sidebar.php'; ?>
        </div>
    </div>
</div>

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
