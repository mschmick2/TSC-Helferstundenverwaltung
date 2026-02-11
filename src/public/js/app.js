/**
 * VAES - JavaScript
 */
document.addEventListener('DOMContentLoaded', function () {

    // =========================================================================
    // Flash-Messages automatisch nach 5 Sekunden ausblenden
    // =========================================================================
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });

    // =========================================================================
    // CSRF-Token für Fetch-Requests bereitstellen
    // =========================================================================
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        window.VAES = window.VAES || {};
        window.VAES.csrfToken = csrfMeta.getAttribute('content');
    }

    /**
     * Fetch-Wrapper mit CSRF-Token
     */
    window.VAES = window.VAES || {};
    window.VAES.fetch = function (url, options) {
        options = options || {};
        options.headers = options.headers || {};

        if (window.VAES.csrfToken) {
            options.headers['X-CSRF-Token'] = window.VAES.csrfToken;
        }

        return fetch(url, options);
    };

    // =========================================================================
    // 2FA-Code: Auto-Focus und Auto-Submit
    // =========================================================================
    var codeInput = document.getElementById('code');
    if (codeInput) {
        codeInput.addEventListener('input', function () {
            // Nur Ziffern zulassen
            this.value = this.value.replace(/[^0-9]/g, '');

            // Auto-Submit bei 6 Ziffern
            if (this.value.length === 6) {
                this.closest('form').submit();
            }
        });
    }

    // =========================================================================
    // Passwort-Stärke-Indikator
    // =========================================================================
    var passwordInput = document.getElementById('password');
    var confirmInput = document.getElementById('password_confirm');

    if (passwordInput && confirmInput) {
        passwordInput.addEventListener('input', function () {
            validatePasswordStrength(this);
        });

        confirmInput.addEventListener('input', function () {
            validatePasswordMatch(passwordInput, this);
        });
    }

    function validatePasswordStrength(input) {
        var password = input.value;
        var feedback = input.parentElement.querySelector('.password-feedback');

        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'password-feedback form-text';
            input.parentElement.appendChild(feedback);
        }

        var checks = [];
        if (password.length >= 8) checks.push('length');
        if (/[A-Z]/.test(password)) checks.push('upper');
        if (/[a-z]/.test(password)) checks.push('lower');
        if (/[0-9]/.test(password)) checks.push('digit');

        var strength = checks.length;
        var colors = ['text-danger', 'text-danger', 'text-warning', 'text-info', 'text-success'];
        var labels = ['Sehr schwach', 'Schwach', 'Mittel', 'Gut', 'Stark'];

        if (password.length > 0) {
            feedback.className = 'password-feedback form-text ' + colors[strength];
            feedback.textContent = labels[strength];
        } else {
            feedback.textContent = '';
        }
    }

    function validatePasswordMatch(passwordEl, confirmEl) {
        if (confirmEl.value.length > 0 && passwordEl.value !== confirmEl.value) {
            confirmEl.classList.add('is-invalid');
        } else {
            confirmEl.classList.remove('is-invalid');
        }
    }

    // =========================================================================
    // Polling: Ungelesene Dialog-Nachrichten
    // =========================================================================
    var unreadUrl = document.body.getAttribute('data-unread-url');
    if (unreadUrl) {
        var lastCount = -1;
        var pollInterval = null;
        var isDashboard = !!document.getElementById('dashboard-page');

        function updateUnreadBadge(count) {
            var badge = document.getElementById('nav-unread-badge');
            var countEl = document.getElementById('nav-unread-count');
            if (!badge || !countEl) return;

            if (count > 0) {
                countEl.textContent = count;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }

        function pollUnread() {
            var url = unreadUrl + (unreadUrl.indexOf('?') === -1 ? '?' : '&') + '_t=' + Date.now();
            fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (res) {
                    if (res.redirected || !res.ok) {
                        // Session abgelaufen oder Redirect auf Login
                        clearInterval(pollInterval);
                        return null;
                    }
                    var ct = res.headers.get('content-type') || '';
                    if (ct.indexOf('application/json') === -1) {
                        // Kein JSON (z.B. HTML Login-Seite) — Polling stoppen
                        clearInterval(pollInterval);
                        return null;
                    }
                    return res.json();
                })
                .then(function (data) {
                    if (!data) return;
                    var count = data.count || 0;

                    updateUnreadBadge(count);

                    // Dashboard: Seite automatisch neu laden wenn sich die Anzahl aendert
                    if (isDashboard && lastCount >= 0 && count !== lastCount) {
                        location.reload();
                        return;
                    }

                    lastCount = count;
                })
                .catch(function () {
                    // Netzwerkfehler — Polling stoppen
                    clearInterval(pollInterval);
                });
        }

        // Sofort abfragen, dann alle 60 Sekunden
        pollUnread();
        pollInterval = setInterval(pollUnread, 60000);
    }
});
