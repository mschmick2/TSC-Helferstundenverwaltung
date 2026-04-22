import { defineConfig, devices } from '@playwright/test';
import * as path from 'path';

/**
 * Playwright-Konfig fuer VAES-E2E-Suite (Modul 8).
 *
 * Vier Projects:
 *   - `headed`       (Default): Desktop 1280x800, sichtbar, slowMo 250ms.
 *   - `headless`     (CI): Desktop 1280x800, unsichtbar.
 *   - `mobile-se`    : iPhone SE (375x667), kleinster gepflegter Viewport.
 *   - `mobile-14`    : iPhone 14 (390x844), mittlerer Mobile-Viewport.
 *
 * Die Mobile-Projects ueberspringen `08-multitab.spec.ts`, weil
 * Zwei-Context-Szenarien auf einem 375px-Mobilgeraet kein realistischer
 * User-Flow sind.
 *
 * Mobile-Runs IMMER in getrennten Invocations starten, NICHT kombiniert:
 *   npx playwright test --project=mobile-se
 *   npx playwright test --project=mobile-14
 * Grund: `globalSetup` (siehe fixtures/global-setup.ts) baut die E2E-DB nur
 * einmal pro Suite-Run neu auf. In einem kombinierten Run teilen sich beide
 * Projects denselben DB-Zustand, wodurch Specs mit User-/Kategorie-Anlage
 * (z.B. 03-admin.spec.ts) im zweiten Project Namenskollisionen ausloesen.
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
      testIgnore: /_handbuch-screenshots\.spec\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        headless: false,
        launchOptions: { slowMo: 250 },
        viewport: { width: 1280, height: 800 },
      },
    },
    {
      name: 'headless',
      testIgnore: /_handbuch-screenshots\.spec\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        headless: true,
        viewport: { width: 1280, height: 800 },
      },
    },
    // Mobile-Projects nutzen Chromium mit iPhone-Viewport/Touch-Settings.
    // WebKit-Engine (echtes Safari) ist laut Plan §5 explizit spaeter. Wir
    // uebernehmen nur Viewport + deviceScaleFactor + isMobile + hasTouch,
    // damit Layout-/Touch-Regressionen sichtbar werden.
    {
      name: 'mobile-se',
      testIgnore: [/08-multitab\.spec\.ts/, /_handbuch-screenshots\.spec\.ts/],
      use: {
        ...devices['Desktop Chrome'],
        headless: true,
        viewport: { width: 375, height: 667 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
      },
    },
    {
      name: 'mobile-14',
      testIgnore: [/08-multitab\.spec\.ts/, /_handbuch-screenshots\.spec\.ts/],
      use: {
        ...devices['Desktop Chrome'],
        headless: true,
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 3,
        isMobile: true,
        hasTouch: true,
      },
    },
    // Handbuch-Screenshot-Project. Laeuft NICHT im Standard-Run. Invocation:
    //   npx playwright test --project=screenshots
    // Beim Start seeded die Spec (tests/e2e/specs/_handbuch-screenshots.spec.ts)
    // via scripts/seed-handbuch-demodata.php zusaetzliche Demo-Daten in die
    // frisch per globalSetup gebaute E2E-DB und legt PNGs unter
    // docs/images/handbuch/ ab.
    {
      name: 'screenshots',
      testMatch: /_handbuch-screenshots\.spec\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        headless: true,
        viewport: { width: 1280, height: 900 },
        deviceScaleFactor: 1,
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
