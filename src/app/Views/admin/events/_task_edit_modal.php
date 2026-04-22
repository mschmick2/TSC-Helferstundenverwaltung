<?php
/**
 * Partial: Modal-Skelett fuer Task-Bearbeitung / Knoten-Neuanlage (Modul 6 I7b1).
 *
 * Initial-Zustand: leer mit Spinner. event-task-tree.js fuellt den Body,
 * nachdem editTaskNode() per fetch die Task-Daten geliefert hat. Das Formular
 * selbst wird per JS in #task-edit-modal-body gerendert, nicht hier — weil
 * Shape- und Feld-Entscheidungen (Gruppe vs. Aufgabe) erst nach dem Fetch
 * feststehen und die Kategorien-Liste ueber data-categories am Wrapper
 * kommt.
 *
 * Einmal pro Edit-Seite eingebunden (nicht pro Knoten).
 */
?>
<div class="modal fade" id="task-edit-modal" tabindex="-1"
     aria-labelledby="task-edit-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-md-down modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header flex-wrap">
                <h5 class="modal-title me-3" id="task-edit-modal-title">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    Aufgabe
                </h5>
                <nav aria-label="Pfad im Aufgabenbaum" class="flex-grow-1">
                    <ol class="breadcrumb mb-0" id="task-edit-modal-breadcrumb">
                        <!-- wird per JS aus response.ancestor_path gefuellt -->
                    </ol>
                </nav>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Schliessen"></button>
            </div>
            <div class="modal-body" id="task-edit-modal-body">
                <div class="text-center py-4">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Laedt...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Abbrechen</button>
                <button type="submit" form="task-edit-form"
                        class="btn btn-primary"
                        id="task-edit-modal-save"
                        disabled>
                    <i class="bi bi-save" aria-hidden="true"></i> Speichern
                </button>
            </div>
        </div>
    </div>
</div>
