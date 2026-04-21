/*
 * Modul 7 I1 — Client-Heartbeat fuer Bearbeitungssperren.
 *
 * Initialisiert sich aus den data-Attributen des eigenen <script>-Tags.
 * Sendet alle 60 s einen Heartbeat, damit der Lock nicht abläuft, solange
 * der Tab offen ist. Beim Verlassen der Seite (Navigation, Reload, Tab-zu)
 * wird der Lock per navigator.sendBeacon() freigegeben.
 *
 * Bei 409-Antwort (fremder Lock wurde uebernommen) wird der Nutzer
 * informiert und die Submit-Buttons deaktiviert.
 *
 * Im Read-Only-Modus (data-poll-only="1") wird nur alle 30 s geprueft,
 * ob der Lock frei geworden ist; wenn ja, zeigt ein Banner den Hinweis,
 * dass neu geladen werden kann.
 */
(function () {
    'use strict';

    var currentScript = document.currentScript;
    if (!currentScript) {
        return;
    }

    var entryId = parseInt(currentScript.getAttribute('data-entry-id') || '0', 10);
    var heartbeatUrl = currentScript.getAttribute('data-heartbeat-url') || '';
    var releaseUrl = currentScript.getAttribute('data-release-url') || '';
    var statusUrl = currentScript.getAttribute('data-status-url') || '';
    var csrfToken = currentScript.getAttribute('data-csrf-token') || '';
    var pollOnly = currentScript.getAttribute('data-poll-only') === '1';

    if (!entryId) {
        return;
    }

    var HEARTBEAT_MS = 60 * 1000;
    var POLL_READONLY_MS = 30 * 1000;
    var released = false;

    function post(url, opts) {
        opts = opts || {};
        var body = new URLSearchParams();
        body.append('csrf_token', csrfToken);
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString(),
            keepalive: !!opts.keepalive
        });
    }

    function showConflictBanner(heldBy) {
        if (document.getElementById('entry-lock-conflict')) {
            return;
        }
        var banner = document.createElement('div');
        banner.id = 'entry-lock-conflict';
        banner.className = 'alert alert-danger mt-3';
        banner.setAttribute('role', 'alert');
        var safeHolder = document.createElement('strong');
        safeHolder.textContent = heldBy || 'ein anderer Nutzer';
        banner.appendChild(document.createTextNode('Ihr Bearbeitungs-Lock ist abgelaufen. '));
        banner.appendChild(safeHolder);
        banner.appendChild(document.createTextNode(' bearbeitet den Eintrag jetzt. Ihre Eingaben werden nicht gespeichert — bitte Seite neu laden.'));
        var container = document.querySelector('.card .card-body') || document.body;
        container.insertBefore(banner, container.firstChild);

        var buttons = document.querySelectorAll('form button[type="submit"]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = true;
        }
    }

    function showFreeBanner() {
        if (document.getElementById('entry-lock-free')) {
            return;
        }
        var banner = document.createElement('div');
        banner.id = 'entry-lock-free';
        banner.className = 'alert alert-success mt-3';
        banner.setAttribute('role', 'alert');
        banner.innerHTML = 'Der Eintrag ist jetzt frei. <a href="" onclick="window.location.reload();return false;">Seite neu laden</a>, um zu bearbeiten.';
        var container = document.querySelector('.container-fluid') || document.body;
        container.insertBefore(banner, container.firstChild);
    }

    function heartbeat() {
        if (released) {
            return;
        }
        post(heartbeatUrl).then(function (res) {
            if (res.status === 409) {
                return res.json().then(function (data) {
                    showConflictBanner((data && data.held_by) || '');
                    released = true;
                });
            }
            if (!res.ok && res.status !== 200) {
                // 401/403/5xx: stillschweigend — beim nächsten Heartbeat erneut versuchen.
                return null;
            }
            return null;
        }).catch(function () {
            // Netzwerkfehler: stillschweigend.
        });
    }

    function pollReadOnly() {
        if (!statusUrl) {
            return;
        }
        fetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (res) {
            return res.ok ? res.json() : null;
        }).then(function (data) {
            if (data && data.ok && data.held_by_other === false) {
                showFreeBanner();
            }
        }).catch(function () {
            // stillschweigend
        });
    }

    function releaseSync() {
        if (released || !releaseUrl) {
            return;
        }
        released = true;
        if (navigator.sendBeacon) {
            var body = new URLSearchParams();
            body.append('csrf_token', csrfToken);
            var blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
            navigator.sendBeacon(releaseUrl, blob);
        } else {
            post(releaseUrl, { keepalive: true });
        }
    }

    if (pollOnly) {
        setInterval(pollReadOnly, POLL_READONLY_MS);
        // Modul 7 I2: Wenn ein anderer Tab den Eintrag speichert, schneller
        // reagieren als der 30-s-Poll.
        document.addEventListener('vaes:entry:updated', function (ev) {
            var detail = ev && ev.detail;
            if (detail && parseInt(detail.id, 10) === entryId) {
                showFreeBanner();
            }
        });
    } else {
        setInterval(heartbeat, HEARTBEAT_MS);
        window.addEventListener('beforeunload', releaseSync);
        window.addEventListener('pagehide', releaseSync);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                heartbeat();
            }
        });
        // Wenn das Formular erfolgreich abgeschickt wird, gibt das Backend
        // den Lock serverseitig frei. Wir unterdruecken den beforeunload-Release
        // in diesem Fall nicht — doppeltes Release ist idempotent.
    }
})();
