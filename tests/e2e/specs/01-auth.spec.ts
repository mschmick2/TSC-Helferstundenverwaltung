import { test, expect } from '@playwright/test';
import { LoginPage } from '../pages/LoginPage';
import { ADMIN, ALICE } from '../fixtures/users';

test.describe('Auth — Login-Smoke', () => {
  test('login mit richtigen Credentials fuehrt auf Dashboard', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(ALICE);
    await login.expectLoginSuccess(ALICE);
    // Dashboard-Indikator: irgendein Link auf /entries oder /dashboard-Header
    await expect(page).not.toHaveURL(/\/login/);
  });

  test('login mit falschem Passwort zeigt Fehler und bleibt auf /login', async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.fill(ALICE.email, 'voellig-falsch');
    await login.submit();
    await login.expectLoginError();
  });

  test('logout leitet auf /login zurueck und Session ist tot', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginAs(ADMIN);
    await login.expectLoginSuccess(ADMIN);

    await login.logout();
    await expect(page).toHaveURL(/\/login/);

    // Nach Logout muss eine geschuetzte Seite wieder zum Login fuehren
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/);
  });
});
