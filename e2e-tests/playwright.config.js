// @ts-check
const { defineConfig } = require('@playwright/test');

/**
 * Playwright-Konfiguration VAES.
 *
 * Drei Projekte:
 *  - setup:         01-login.spec.js   erzeugt auth-state.json
 *  - no-auth:       02-session-security.spec.js   Tests OHNE Login
 *  - authenticated: alle uebrigen      nutzen auth-state.json (depends on setup)
 *
 * Umgebung:
 *   BASE_URL (Default http://localhost:8000/) — auf WAMP z.B.
 *   http://localhost/helferstunden/
 */
const BASE_URL = process.env.BASE_URL || 'http://localhost:8000/';

module.exports = defineConfig({
    testDir: './tests',
    timeout: 30_000,
    retries: 0,
    fullyParallel: false,
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ],
    use: {
        baseURL: BASE_URL,
        viewport: { width: 1280, height: 900 },
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        ignoreHTTPSErrors: true,
    },
    projects: [
        {
            name: 'setup',
            testMatch: /01-login\.spec\.js/,
        },
        {
            name: 'no-auth',
            testMatch: /02-session-security\.spec\.js/,
        },
        {
            name: 'authenticated',
            testMatch: /(0[3-9]|1[0-9])-.*\.spec\.js/,
            dependencies: ['setup'],
            use: {
                storageState: './auth-state.json',
            },
        },
    ],
});
