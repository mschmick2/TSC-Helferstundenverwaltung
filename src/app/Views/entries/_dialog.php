<?php
/**
 * Dialog-Komponente (Chat-artige Nachrichten)
 *
 * Variablen: $entry, $dialogs, $user
 */

use App\Helpers\ViewHelper;
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-chat-dots"></i> Dialog</span>
        <?php if ($entry->getOpenQuestionsCount() > 0): ?>
            <span class="badge bg-warning text-dark">
                <?= $entry->getOpenQuestionsCount() ?> offene Rückfrage(n)
            </span>
        <?php endif; ?>
    </div>

    <div class="card-body" style="max-height: 500px; overflow-y: auto;" id="dialog-container">
        <?php if (empty($dialogs)): ?>
            <p class="text-muted text-center small mb-0">
                <i class="bi bi-chat"></i> Noch keine Nachrichten.
            </p>
        <?php else: ?>
            <?php foreach ($dialogs as $msg): ?>
                <?php
                $isCurrentUser = (int) $msg['user_id'] === $user->getId();
                $align = $isCurrentUser ? 'text-end' : 'text-start';
                $bgClass = $isCurrentUser ? 'bg-primary text-white' : 'bg-light';
                if ($msg['is_question'] && !$msg['is_answered']) {
                    $bgClass = 'bg-warning text-dark';
                }
                ?>
                <div class="mb-3 <?= $align ?>">
                    <div class="d-inline-block rounded-3 px-3 py-2 <?= $bgClass ?>" style="max-width: 85%;">
                        <div class="small fw-bold">
                            <?= ViewHelper::e($msg['user_name']) ?>
                            <?php if ($msg['is_question']): ?>
                                <i class="bi bi-question-circle" title="Rückfrage"></i>
                            <?php endif; ?>
                            <?php if ($msg['is_question'] && $msg['is_answered']): ?>
                                <i class="bi bi-check-circle" title="Beantwortet"></i>
                            <?php endif; ?>
                        </div>
                        <div><?= nl2br(ViewHelper::e($msg['message'])) ?></div>
                        <div class="small opacity-75 mt-1">
                            <?= ViewHelper::formatDateTime($msg['created_at']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Nachricht senden -->
    <?php if (in_array($entry->getStatus(), ['eingereicht', 'in_klaerung'])): ?>
    <div class="card-footer">
        <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/message') ?>">
            <?= ViewHelper::csrfField() ?>
            <div class="input-group">
                <textarea name="message" class="form-control form-control-sm" rows="2"
                          placeholder="Nachricht schreiben..." required></textarea>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-send"></i>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
// Dialog automatisch nach unten scrollen
document.addEventListener('DOMContentLoaded', function() {
    var container = document.getElementById('dialog-container');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
});
</script>
