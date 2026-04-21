// 07-password-reset.spec.js - Passwort-Reset-Flow mit MailPit
const { test, expect } = require('@playwright/test');
const { isMailPitRunning, deleteAllMessages, waitForMessage } = require('../helpers/mailpit');

test.describe('Passwort-Reset', () => {
    test.beforeEach(async () => {
        const running = await isMailPitRunning();
        test.skip(!running, 'MailPit nicht erreichbar (Port 8025) - Test uebersprungen');
        await deleteAllMessages();
    });

    test('Reset-Anfrage sendet E-Mail', async ({ page }) => {
        await page.goto('/forgot-password');
        const testEmail = 'admin@vaes.test';
        await page.fill('input[name="email"]', testEmail);
        await page.click('button[type="submit"]');

        const msg = await waitForMessage({
            to: testEmail,
            subject: 'Passwort',
            timeoutMs: 10_000,
        });
        expect(msg).not.toBeNull();
        expect(msg.Subject.toLowerCase()).toMatch(/passwort|reset/);
    });
});
