// 01-login.spec.js - SETUP-PROJECT: Login + Session-Speicherung
const { test, expect } = require('@playwright/test');
const { login, TEST_USER_EMAIL } = require('../helpers/auth');

test.describe('Login', () => {
    test('Login mit gueltigen Zugangsdaten', async ({ page }) => {
        await login(page);
        // Dashboard oder Startseite erreicht (VAES nutzt "/" als Dashboard-Route)
        await expect(page).not.toHaveURL(/\/login$/);
        // Navigation sichtbar = authentifiziert
        await expect(page.locator('nav')).toBeVisible();
        await page.context().storageState({ path: './auth-state.json' });
    });

    test('Login mit falschem Passwort zeigt Fehler', async ({ page }) => {
        await page.goto('login');
        await page.fill('input[name="email"], input[name="username"]', TEST_USER_EMAIL);
        await page.fill('input[name="password"]', 'wrong-password-xyz');
        await page.click('button[type="submit"]');
        // Entweder bleibt auf /login mit Fehler ODER wird umgeleitet (je nach Impl.)
        const body = await page.textContent('body');
        expect(body.toLowerCase()).toMatch(/fehler|ungueltig|ungültig|falsch/);
    });

    test('Login-Form enthaelt CSRF-Token', async ({ page }) => {
        await page.goto('login');
        const csrfInput = page.locator('input[name="csrf_token"]');
        await expect(csrfInput).toHaveCount(1);
        const value = await csrfInput.inputValue();
        expect(value.length).toBeGreaterThan(10);
    });
});
