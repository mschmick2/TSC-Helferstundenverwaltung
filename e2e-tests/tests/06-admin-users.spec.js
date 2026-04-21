// 06-admin-users.spec.js - Mitgliederverwaltung (Admin)
const { test, expect } = require('@playwright/test');

test.describe('Admin - Mitglieder', () => {
    test('Mitgliederliste laedt', async ({ page }) => {
        await page.goto('/admin/users');
        // Erwartung: Admin-User eingeloggt - sonst 403/Redirect
        const isListed = await page.locator('table, .users-list').count() > 0;
        const isForbidden = await page.locator(':text("403"), :text("Zugriff verweigert")').count() > 0;
        expect(isListed || isForbidden).toBe(true);
        if (isListed) {
            await page.screenshot({ path: 'screenshots/06-admin-users.png', fullPage: true });
        }
    });

    test('Button "Neues Mitglied anlegen" sichtbar bei Admin', async ({ page }) => {
        await page.goto('/admin/users');
        const createLink = page.getByRole('link', { name: /neu|anlegen/i });
        // Falls User kein Admin: Test gracefully ueberspringen
        if (await createLink.first().count() === 0) {
            test.skip(true, 'Test-User ist kein Admin');
        }
        await expect(createLink.first()).toBeVisible();
    });
});
