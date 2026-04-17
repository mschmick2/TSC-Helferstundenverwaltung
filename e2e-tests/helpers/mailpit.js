// helpers/mailpit.js - Minimal MailPit-Client fuer Playwright-Tests.

const DEFAULT_URL = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';

async function apiRequest(method, path, baseUrl = DEFAULT_URL) {
    const res = await fetch(baseUrl + path, {
        method,
        headers: { Accept: 'application/json' },
    });
    if (!res.ok) {
        throw new Error(`MailPit ${method} ${path} failed: ${res.status}`);
    }
    return await res.json().catch(() => ({}));
}

async function isMailPitRunning(baseUrl = DEFAULT_URL) {
    try {
        await apiRequest('GET', '/api/v1/info', baseUrl);
        return true;
    } catch {
        return false;
    }
}

async function getMessages(limit = 50, baseUrl = DEFAULT_URL) {
    const data = await apiRequest('GET', `/api/v1/messages?limit=${limit}`, baseUrl);
    return data.messages || [];
}

async function deleteAllMessages(baseUrl = DEFAULT_URL) {
    await fetch(baseUrl + '/api/v1/messages', { method: 'DELETE' });
}

/**
 * Polling auf eine Nachricht mit optionalem to/subject-Filter.
 *
 * @param {{ to?: string, subject?: string, timeoutMs?: number, pollMs?: number, baseUrl?: string }} opts
 */
async function waitForMessage(opts = {}) {
    const {
        to,
        subject,
        timeoutMs = 10_000,
        pollMs = 500,
        baseUrl = DEFAULT_URL,
    } = opts;

    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const messages = await getMessages(50, baseUrl);
        for (const msg of messages) {
            if (to) {
                const matches = (msg.To || []).some(
                    r => (r.Address || '').toLowerCase() === to.toLowerCase()
                );
                if (!matches) continue;
            }
            if (subject && !(msg.Subject || '').toLowerCase().includes(subject.toLowerCase())) {
                continue;
            }
            return msg;
        }
        await new Promise(r => setTimeout(r, pollMs));
    }
    return null;
}

module.exports = {
    isMailPitRunning,
    getMessages,
    deleteAllMessages,
    waitForMessage,
};
