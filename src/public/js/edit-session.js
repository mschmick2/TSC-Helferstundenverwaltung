// edit-session.js — Edit-Session-Lifecycle (Modul 6 I7e-C.1 Phase 3).
//
// Verantwortlich fuer:
//   1. Session beim Server starten, sobald die Editor-Seite geladen ist.
//      Falls die laufende Browser-Session bereits eine Session-ID hat
//      (sessionStorage), wird per Heartbeat versucht, sie zu reanimieren.
//   2. Heartbeat alle 30 s, damit der Server die Session als aktiv
//      kennt (Architect-K3 Polling-Intervall).
//   3. Polling der aktiven Sessions des Events alle 30 s, damit der
//      Indicator-Container den aktuellen Stand der anderen Editoren
//      zeigt.
//   4. Initial-State direkt aus dem data-initial-sessions-Attribut
//      des Containers rendern (Architect-C4: kein Polling-Delay beim
//      ersten Render).
//   5. Beim beforeunload/pagehide Best-Effort-Close per
//      navigator.sendBeacon. Bei verlorener Session greift der
//      2-Minuten-Server-Timeout aus Phase 1 als Fallback (NR1).
//
// Erwartet im DOM:
//   - <meta name="csrf-token" content="..."> (im Layout-Head)
//   - <meta name="current-user-id" content="..."> (im Layout-Head)
//   - <div id="edit-sessions-indicator"
//          data-event-id="..."
//          data-initial-sessions='[ ... JSON ... ]'>
//
// Persistenz (Architect-C1): die Session-ID wird im sessionStorage
// gehalten, damit sie einen window.location.reload() (z.B. nach Lock-
// Konflikt aus I7e-B) ueberlebt. sessionStorage ist tab-scoped — zwei
// Tabs desselben Users haben ihre eigenen IDs (Multi-Tab via R2).
//
// Feature-Flag: wird serverseitig gepruefta. Bei deaktiviertem Flag
// liefert /start einen 410 — der Client hoert dann komplett auf.
//
// Sicherheit / Konsistenz mit dem Bestand:
//   - sendBeacon-Pattern aus entry-lock.js (URL-encoded form body
//     mit csrf_token). Gleiche Server-Konvention.
//   - CSRF-Header in fetch via X-CSRF-Token, analog
//     event-task-tree.js.

(function () {
    'use strict';

    // =====================================================================
    // Konstanten
    // =====================================================================

    var HEARTBEAT_INTERVAL_MS = 30000;
    var STORAGE_KEY_SESSION_ID = 'vaes_edit_session_id';
    var STORAGE_KEY_BROWSER_ID = 'vaes_browser_session_id';

    // =====================================================================
    // Module-State
    // =====================================================================

    var sessionId = null;
    var browserSessionId = null;
    var eventId = null;
    var heartbeatTimer = null;
    var indicatorContainer = null;
    var currentUserId = 0;
    var stopped = false;

    // =====================================================================
    // Helper
    // =====================================================================

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function getCurrentUserId() {
        var meta = document.querySelector('meta[name="current-user-id"]');
        var raw = meta ? meta.content : '0';
        var n = parseInt(raw, 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function generateBrowserSessionId() {
        return 'bs_'
            + Math.random().toString(36).slice(2)
            + Date.now().toString(36);
    }

    // =====================================================================
    // API-Client
    // =====================================================================

    async function apiPost(url, body) {
        try {
            var res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: body ? JSON.stringify(body) : null,
            });
            return res;
        } catch (e) {
            // Netzwerk-Fehler: still bleiben, beim naechsten Tick wieder
            // versuchen.
            return null;
        }
    }

    async function apiGet(url) {
        try {
            var res = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            return res;
        } catch (e) {
            return null;
        }
    }

    // =====================================================================
    // Session-Lifecycle
    // =====================================================================

    async function startSession() {
        // browser_session_id im sessionStorage halten — survivt Lock-Reload,
        // ist aber pro Tab unique (sessionStorage-Scope).
        browserSessionId = sessionStorage.getItem(STORAGE_KEY_BROWSER_ID)
            || generateBrowserSessionId();
        sessionStorage.setItem(STORAGE_KEY_BROWSER_ID, browserSessionId);

        var res = await apiPost('/api/edit-sessions/start', {
            event_id: eventId,
            browser_session_id: browserSessionId,
        });
        if (res === null) {
            return false;
        }

        if (res.status === 201) {
            var data = await res.json().catch(function () { return null; });
            if (data && typeof data.session_id === 'number') {
                sessionId = data.session_id;
                sessionStorage.setItem(STORAGE_KEY_SESSION_ID, String(sessionId));
                return true;
            }
        }

        if (res.status === 410) {
            // Feature deaktiviert. Polling und Indicator stilllegen.
            stopped = true;
        }
        return false;
    }

    async function resumeOrStartSession() {
        // Architect-C1: sessionStorage-Reuse nach Lock-Reload aus I7e-B.
        var stored = sessionStorage.getItem(STORAGE_KEY_SESSION_ID);
        if (stored) {
            var storedId = parseInt(stored, 10);
            if (Number.isFinite(storedId) && storedId > 0) {
                var res = await apiPost(
                    '/api/edit-sessions/' + storedId + '/heartbeat',
                    null
                );
                if (res !== null && res.status === 200) {
                    sessionId = storedId;
                    return true;
                }
                // 404 / 410 / Netzwerk-Fehler: alte ID wegwerfen, frisch starten.
                sessionStorage.removeItem(STORAGE_KEY_SESSION_ID);
                if (res !== null && res.status === 410) {
                    stopped = true;
                    return false;
                }
            }
        }
        return startSession();
    }

    async function sendHeartbeat() {
        if (stopped || sessionId === null) {
            return;
        }
        var res = await apiPost(
            '/api/edit-sessions/' + sessionId + '/heartbeat',
            null
        );
        if (res === null) {
            return;
        }
        if (res.status === 404) {
            // Server kennt unsere Session nicht mehr (Timeout-Reaper oder
            // fremder Schliess-Vorgang). Versuchen wir, eine neue zu
            // starten — der Nutzer ist ja noch hier.
            sessionStorage.removeItem(STORAGE_KEY_SESSION_ID);
            sessionId = null;
            await startSession();
        } else if (res.status === 410) {
            stopped = true;
            stopHeartbeatTimer();
            renderSessionAlerts([]);
        }
    }

    function closeSessionBestEffort() {
        if (sessionId === null) {
            return;
        }
        // Follow-up z: Bei programmatischem Reload (z.B. aus
        // event-task-tree.js::handleLockConflict nach Optimistic-Lock-
        // Konflikt) wird die Edit-Session NICHT geschlossen. Der
        // aufrufende Code setzt sessionStorage['vaes_programmatic_
        // reload'] = '1' direkt vor window.location.reload(). Hier
        // NUR lesen, NICHT entfernen — beforeunload und pagehide feuern
        // beide, beide Handler muessen das Flag sehen und ueberspringen.
        // Das Flag wird erst in boot() der neu geladenen Seite geraeumt
        // (self-cleanup nach Reload). Nach dem Reload findet
        // resumeOrStartSession die Session noch offen, Probe-Heartbeat
        // liefert 200, C1-Invariante haelt.
        if (sessionStorage.getItem('vaes_programmatic_reload') === '1') {
            return;
        }
        // Konsistent zu entry-lock.js: URL-encoded form body mit
        // csrf_token-Field. Der Server akzeptiert das ueber
        // CsrfMiddleware (form-data-Pfad), und navigator.sendBeacon
        // liefert es synchron auch beim beforeunload aus.
        if (navigator.sendBeacon) {
            var body = new URLSearchParams();
            body.append('csrf_token', getCsrfToken());
            var blob = new Blob(
                [body.toString()],
                { type: 'application/x-www-form-urlencoded' }
            );
            navigator.sendBeacon(
                '/api/edit-sessions/' + sessionId + '/close',
                blob
            );
        } else {
            // Browser ohne sendBeacon: keepalive-fetch als Fallback.
            // Wenn auch das scheitert, faengt der 2-Minuten-Server-Timeout
            // die Sache ab (Architect-NR1).
            try {
                fetch('/api/edit-sessions/' + sessionId + '/close', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    keepalive: true,
                });
            } catch (e) {
                // Best-Effort. Ignorieren.
            }
        }
    }

    function startHeartbeatTimer() {
        if (heartbeatTimer !== null) {
            return;
        }
        heartbeatTimer = setInterval(async function () {
            await sendHeartbeat();
            await refreshSessionsList();
        }, HEARTBEAT_INTERVAL_MS);
    }

    function stopHeartbeatTimer() {
        if (heartbeatTimer !== null) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    // =====================================================================
    // Polling und Rendering
    // =====================================================================

    async function refreshSessionsList() {
        if (stopped) {
            return;
        }
        var res = await apiGet('/api/edit-sessions?event_id=' + eventId);
        if (res === null || res.status !== 200) {
            return;
        }
        var data = await res.json().catch(function () { return null; });
        if (!data || !Array.isArray(data.sessions)) {
            return;
        }
        renderSessionAlerts(data.sessions);
    }

    function renderSessionAlerts(sessions) {
        if (!indicatorContainer) {
            return;
        }
        // Filter: eigene Session des Viewers ausblenden. Der Server filtert
        // das ohnehin (EditSessionView::toJsonReadyArray), aber bei einem
        // Initial-Render mit aelteren Daten oder Race-Conditions wollen wir
        // doppelt sicher sein — und sparen das eine fehlerhafte
        // "Sie bearbeiten dieses Event"-Anzeige-Risiko.
        var others = sessions.filter(function (s) {
            return s && Number(s.user_id) !== currentUserId;
        });

        // Container vollstaendig leer-rendern, dann fuellen.
        while (indicatorContainer.firstChild) {
            indicatorContainer.removeChild(indicatorContainer.firstChild);
        }
        if (others.length === 0) {
            return;
        }

        for (var i = 0; i < others.length; i++) {
            indicatorContainer.appendChild(buildAlert(others[i]));
        }
    }

    function buildAlert(session) {
        var alert = document.createElement('div');
        alert.className = 'alert alert-info alert-sm py-2 mb-2 small d-flex align-items-center gap-2';
        alert.setAttribute('role', 'status');

        var icon = document.createElement('i');
        icon.className = 'bi bi-person-check';
        icon.setAttribute('aria-hidden', 'true');

        var span = document.createElement('span');
        // Sicherheit: textContent (NICHT innerHTML), damit Display-Namen
        // mit Sonderzeichen nicht zu XSS werden.
        span.textContent = formatSessionMessage(session);

        alert.appendChild(icon);
        alert.appendChild(span);
        return alert;
    }

    function formatSessionMessage(session) {
        var name = String(session.display_name || 'Jemand');
        var seconds = Number(session.duration_seconds) || 0;
        return name + ' bearbeitet dieses Event seit ' + formatDuration(seconds) + '.';
    }

    function formatDuration(seconds) {
        if (seconds < 60) {
            return 'weniger als einer Minute';
        }
        var minutes = Math.floor(seconds / 60);
        if (minutes === 1) {
            return 'einer Minute';
        }
        return minutes + ' Minuten';
    }

    // =====================================================================
    // Boot
    // =====================================================================

    function boot() {
        indicatorContainer = document.getElementById('edit-sessions-indicator');
        if (!indicatorContainer) {
            // Keine Editor-Seite mit Session-Anker.
            return;
        }

        // Follow-up z: wenn die vorige Seite einen programmatischen
        // Reload ausgeloest hat (z.B. nach Lock-Konflikt), hat der
        // close-Handler das Flag gelesen und den sendBeacon uebersprungen.
        // Jetzt -- nach dem Reload -- ist das Flag obsolet und wird
        // hier geraeumt, damit ein nachfolgender echter User-Close den
        // Standard-Pfad nimmt. Cleanup im boot() statt im Close-Handler,
        // weil beforeunload UND pagehide beide feuern und beide das Flag
        // brauchen.
        sessionStorage.removeItem('vaes_programmatic_reload');

        eventId = parseInt(indicatorContainer.dataset.eventId || '0', 10);
        if (!Number.isFinite(eventId) || eventId <= 0) {
            console.warn('edit-session: kein event_id am Indicator-Container.');
            return;
        }

        currentUserId = getCurrentUserId();

        // Initial-State sofort rendern (Architect-C4): die Server-seitig
        // mitgegebenen aktiven Sessions werden direkt im DOM angezeigt,
        // ohne auf den ersten Polling-Tick zu warten.
        var initialAttr = indicatorContainer.dataset.initialSessions;
        if (initialAttr) {
            try {
                var initial = JSON.parse(initialAttr);
                if (Array.isArray(initial)) {
                    renderSessionAlerts(initial);
                }
            } catch (e) {
                console.warn('edit-session: data-initial-sessions nicht parsbar.', e);
            }
        }

        // Lifecycle starten — Heartbeat erst nach erfolgreichem Start
        // (oder Resume), damit wir keinen Timer ohne Session laufen lassen.
        resumeOrStartSession().then(function (started) {
            if (started) {
                startHeartbeatTimer();
                refreshSessionsList();
            }
        });

        // Best-Effort-Close beim Verlassen der Seite. pagehide ist die
        // mobile-freundlichere Variante (beforeunload feuert auf iOS/
        // Android Chrome unzuverlaessig — Architect-NR1).
        window.addEventListener('beforeunload', closeSessionBestEffort);
        window.addEventListener('pagehide', closeSessionBestEffort);
    }

    document.addEventListener('DOMContentLoaded', boot);
})();
