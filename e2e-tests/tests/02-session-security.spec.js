// 02-session-security.spec.js - NO-AUTH-PROJECT: Tests ohne Session
const { test, expect } = require('@playwright/test');

test.describe('Session-Security', () => {
    test('Geschuetzte Route ohne Login leitet zu /login', async ({ page }) => {
        const response = await page.goto('/dashboard');
        // Erwartung: Redirect-Status oder Landung auf Login-Seite
        await expect(page).toHaveURL(/\/login/);
    });

    test('POST auf geschuetzte Route ohne Login wird abgefangen', async ({ request }) => {
        const res = await request.post('/entries', {
            form: { hours: '2.0', description: 'test' },
            failOnStatusCode: false,
        });
        // Erwartung: 302/303 (Redirect) oder 401/403
        expect([301, 302, 303, 401, 403]).toContain(res.status());
    });
});
