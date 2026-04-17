// 04-entries-list.spec.js - Antraege-Liste
const { test, expect } = require('@playwright/test');

test.describe('Antraege - Liste', () => {
    test('Liste laedt', async ({ page }) => {
        await page.goto('/entries');
        await expect(page.locator('table, .entries-list')).toBeVisible();
        await page.screenshot({ path: 'screenshots/04-entries-list.png', fullPage: true });
    });

    test('Button "Neuer Antrag" ist sichtbar', async ({ page }) => {
        await page.goto('/entries');
        const createBtn = page.getByRole('link', { name: /neu|erstellen|anlegen/i });
        await expect(createBtn.first()).toBeVisible();
    });
});
