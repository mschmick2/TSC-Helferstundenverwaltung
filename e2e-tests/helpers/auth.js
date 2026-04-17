// helpers/auth.js - Login-Helper fuer Setup-Project.

/**
 * Erwartet Testbenutzer in der DB mit E-Mail/Passwort.
 * 2FA: Dieser Helper setzt voraus, dass der Testuser entweder ohne 2FA
 * angelegt oder mit bekanntem TOTP-Secret (aus Fixture) versehen ist.
 */
const TEST_USER_EMAIL    = process.env.TEST_USER_EMAIL    || 'admin@vaes.test';
const TEST_USER_PASSWORD = process.env.TEST_USER_PASSWORD || 'TestPass123!';

async function login(page) {
    await page.goto('/login');
    await page.fill('input[name="email"], input[name="username"]', TEST_USER_EMAIL);
    await page.fill('input[name="password"]', TEST_USER_PASSWORD);
    await page.click('button[type="submit"]');
    // Warten, bis wir /login verlassen (Redirect auf /, /dashboard oder /2fa-setup)
    await page.waitForURL(url => {
        const pathname = new URL(url).pathname;
        return !pathname.endsWith('/login');
    }, { timeout: 10_000 });
}

module.exports = {
    login,
    TEST_USER_EMAIL,
    TEST_USER_PASSWORD,
};
