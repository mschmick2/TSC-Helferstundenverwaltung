<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Template-spezifische Helper fuer Tree-Aktions-Controller.
 *
 * Parallel zu EventTreeActionHelpers::assertTaskBelongsToEvent, aber auf
 * einem anderen Repository (`templateRepo` statt `taskRepo`) und mit
 * `getTemplateId()` statt `getEventId()`. Extrahiert in I7e-B.0.1.
 *
 * Konventions-Voraussetzungen:
 *   - `$this->templateRepo` (EventTemplateRepository) fuer die
 *     findTaskById-Suche.
 */
trait TemplateTreeActionHelpers
{
    /**
     * IDOR-Scope-Check fuer den Template-Editor (G4 Dim 3, extrahiert
     * aus Commit 2a16823, Template-Variante).
     *
     * 404 bei Task aus fremdem Template — verdeckt Task-Existenz
     * (Information-Leak-Schutz).
     */
    protected function assertTaskBelongsToTemplate(
        int $taskId,
        int $templateId,
        Response $response
    ): ?Response {
        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null || $task->getTemplateId() !== $templateId) {
            return $response->withStatus(404);
        }
        return null;
    }
}
