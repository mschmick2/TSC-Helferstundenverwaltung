// calendar.js - FullCalendar v6 Init fuer VAES (Modul 6 I5)
// Erwartet: <div id="calendar" data-feed-url="..."></div> + FullCalendar global verfuegbar.

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('calendar');
        if (!el || typeof FullCalendar === 'undefined') {
            return;
        }
        var feedUrl = el.getAttribute('data-feed-url');
        if (!feedUrl) {
            return;
        }

        var calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            locale: 'de',
            firstDay: 1, // Montag
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            buttonText: {
                today: 'Heute',
                month: 'Monat',
                week:  'Woche',
                list:  'Liste'
            },
            height: 'auto',
            nowIndicator: true,
            dayMaxEvents: 3,
            events: feedUrl,
            eventClick: function (info) {
                // FullCalendar oeffnet url-Felder standardmaessig in neuem Tab;
                // wir wollen gleiche Tab.
                if (info.event.url) {
                    info.jsEvent.preventDefault();
                    window.location.href = info.event.url;
                }
            }
        });
        calendar.render();
    });
})();
