import { defineConfig, devices } from '@playwright/test';
import * as path from 'path';

/**
 * Playwright-Konfig fuer VAES-E2E-Suite (Modul 8).
 *
 * Zwei Projects:
 *   - `headed`   (Default): Browser sichtbar, slowMo 250ms, fuer lokale Entwicklung.
 *   - `headless` (fuer CI): unsichtbar, schneller.
 *
 * Dev-Server:
 *   Playwright startet `php -S localhost:8001 -t src/public` im Repo-Root.
 *   Umgebungsvariable VAES_CONFIG_FILE zeigt auf config.e2e.php.
 */
const repoRoot = path.resolve(__dirname, '..', '..');
const configE2E = path.join(repoRoot, 'src', 'config', 'config.e2e.php');
const publicDir = path.join(repoRoot, 'src', 'public');

export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
  ],
  globalSetup: require.resolve('./fixtures/global-setup'),
  use: {
    baseURL: 'http://localhost:8001',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
  },
  projects: [
    {
      name: 'headed',
      use: {
        ...devices['Desktop Chrome'],
        headless: false,
        launchOptions: { slowMo: 250 },
        viewport: { width: 1280, height: 800 },
      },
    },
    {
      name: 'headless',
      use: {
        ...devices['Desktop Chrome'],
        headless: true,
        viewport: { width: 1280, height: 800 },
      },
    },
  ],
  webServer: {
    command: `php -S localhost:8001 -t "${publicDir}" "${path.join(publicDir, 'router.php')}"`,
    url: 'http://localhost:8001/login',
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
    env: {
      VAES_CONFIG_FILE: configE2E,
    },
  },
});
