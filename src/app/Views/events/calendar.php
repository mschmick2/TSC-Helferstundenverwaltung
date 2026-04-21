<?php
/**
 * @var string $feedUrl  URL zum JSON-Endpoint fuer FullCalendar (/api/events/calendar)
 */
use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-calendar3"></i> Event-Kalender</h1>
    <div class="btn-group">
        <a href="<?= ViewHelper::url('/events') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-list-ul"></i> Listenansicht
        </a>
        <a href="<?= ViewHelper::url('/my-events/ical') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-link-45deg"></i> iCal-Abo
        </a>
    </div>
</div>

<div id="calendar" data-feed-url="<?= ViewHelper::e($feedUrl) ?>"></div>

<!-- FullCalendar v6: Styles werden vom JS-Bundle selbst injectet, kein separates CSS noetig -->
<script src="<?= ViewHelper::url('/js/vendor/fullcalendar/index.global.min.js') ?>"></script>
<script src="<?= ViewHelper::url('/js/vendor/fullcalendar/locales/de.global.min.js') ?>"></script>
<script src="<?= ViewHelper::url('/js/calendar.js') ?>"></script>
