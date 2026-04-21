/*
 * Modul 7 I2 - Cross-Tab-Sync via BroadcastChannel.
 *
 * Haelt mehrere geoeffnete Browser-Tabs derselben Session konsistent:
 *  - Logout in einem Tab laesst alle anderen Tabs auf /login redirecten.
 *  - Gespeicherter work_entries-Eintrag informiert andere Tabs, die den
 *    gleichen Eintrag im Read-Only-Lock anzeigen.
 *
 * Transport primaer ueber die native BroadcastChannel-API. Fallback auf
 * localStorage-storage-Event fuer Browser ohne Broadcast-Unterstuetzung.
 * Payloads enthalten nur event/id/at - KEINE PII.
 *
 * Senden:
 *  - Das Backend setzt per ViewHelper::broadcast(...) Events in die Session,
 *    das Layout rendert sie als JSON ins data-vaes-broadcasts-Attribut des
 *    body-Tags. broadcast.js liest das beim Laden genau einmal und sendet.
 *
 * Empfangen:
 *  - Jede eingebundene Seite registriert sich ueber
 *    VAES.broadcast.on(event, callback). Standard-Handler fuer 'auth:logout'
 *    ist eingebaut (redirect auf /login), wenn man nicht bereits dort ist.
 */
(function () {
    'use strict';

    var CHANNEL_NAME = 'vaes';
    var STORAGE_KEY = 'vaes:broadcast';
    var DEDUP_WINDOW_MS = 5000;

    var bc = null;
    try {
        if (typeof BroadcastChannel !== 'undefined') {
            bc = new BroadcastChannel(CHANNEL_NAME);
        }
    } catch (e) {
        bc = null;
    }

    var listeners = {};
    var lastSeen = {};

    function dispatch(msg) {
        if (!msg || typeof msg.event !== 'string') {
            return;
        }
        var key = msg.event + ':' + (msg.id || '') + ':' + (msg.at || 0);
        if (lastSeen[key]) {
            return;
        }
        lastSeen[key] = true;
        // Self-dispatch als DOM-Event, damit andere Skripte ueber
        // document.addEventListener('vaes:<event>', ...) reagieren koennen.
        try {
            var domEvent = new CustomEvent('vaes:' + msg.event, { detail: msg });
            document.dispatchEvent(domEvent);
        } catch (e) {
            // aelterer Browser ohne CustomEvent - egal, Listener-API funktioniert trotzdem
        }
        var arr = listeners[msg.event] || [];
        for (var i = 0; i < arr.length; i++) {
            try {
                arr[i](msg);
            } catch (err) {
                if (window.console && console.error) {
                    console.error('VAES broadcast listener error:', err);
                }
            }
        }
    }

    function send(event, payload) {
        var msg = { event: event, at: Date.now() };
        if (payload && typeof payload === 'object') {
            for (var k in payload) {
                if (Object.prototype.hasOwnProperty.call(payload, k) && k !== 'event' && k !== 'at') {
                    msg[k] = payload[k];
                }
            }
        }
        if (bc) {
            try {
                bc.postMessage(msg);
                return;
            } catch (e) {
                // Fallback weiter unten
            }
        }
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(msg));
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // localStorage nicht verfuegbar (Private Mode etc.) - stillschweigend
        }
    }

    function on(event, callback) {
        if (typeof event !== 'string' || typeof callback !== 'function') {
            return;
        }
        if (!listeners[event]) {
            listeners[event] = [];
        }
        listeners[event].push(callback);
    }

    if (bc) {
        bc.addEventListener('message', function (ev) {
            dispatch(ev.data);
        });
    }

    window.addEventListener('storage', function (ev) {
        if (ev.key !== STORAGE_KEY || !ev.newValue) {
            return;
        }
        try {
            var parsed = JSON.parse(ev.newValue);
            dispatch(parsed);
        } catch (e) {
            // ungueltige JSON ignorieren
        }
    });

    // Standard-Handler: Logout -> alle Tabs redirecten, die nicht bereits
    // im Auth-Bereich sind. Verhindert Endlos-Schleifen auf /login.
    on('auth:logout', function () {
        var path = window.location.pathname || '';
        if (path === '/login' || path.indexOf('/login') === 0) {
            return;
        }
        if (path.indexOf('/logout') === 0 || path.indexOf('/2fa') === 0) {
            return;
        }
        window.location.href = '/login';
    });

    // Beim Laden: Eingehende Broadcasts aus dem Backend abfeuern.
    document.addEventListener('DOMContentLoaded', function () {
        var body = document.body;
        if (!body) {
            return;
        }
        var raw = body.getAttribute('data-vaes-broadcasts');
        if (!raw) {
            return;
        }
        body.removeAttribute('data-vaes-broadcasts');
        var list;
        try {
            list = JSON.parse(raw);
        } catch (e) {
            return;
        }
        if (!Array.isArray(list)) {
            return;
        }
        for (var i = 0; i < list.length; i++) {
            var msg = list[i];
            if (msg && typeof msg.event === 'string') {
                var payload = {};
                for (var k in msg) {
                    if (Object.prototype.hasOwnProperty.call(msg, k) && k !== 'event') {
                        payload[k] = msg[k];
                    }
                }
                send(msg.event, payload);
            }
        }
    });

    window.VAES = window.VAES || {};
    window.VAES.broadcast = {
        send: send,
        on: on
    };
})();
