// 05-entry-create.spec.js - Antrag erstellen
const { test, expect } = require('@playwright/test');

test.describe('Antrag erstellen', () => {
    test('Formular laedt mit Pflichtfeldern', async ({ page }) => {
        await page.goto('/entries/create');
        await expect(page.locator('input[name="hours"]')).toBeVisible();
        await expect(page.locator('select[name="category_id"], input[name="category_id"]')).toBeVisible();
        await page.screenshot({ path: 'screenshots/05-entry-create.png', fullPage: true });
    });

    test('Pflichtfeldmarkierung mit rotem Stern vorhanden', async ({ page }) => {
        await page.goto('/entries/create');
        const asterisks = page.locator('.text-danger:has-text("*")');
        await expect(asterisks.first()).toBeVisible();
    });

    test('CSRF-Token im Formular', async ({ page }) => {
        await page.goto('/entries/create');
        const csrfInput = page.locator('input[name="csrf_token"]');
        await expect(csrfInput).toHaveCount(1);
    });
});
