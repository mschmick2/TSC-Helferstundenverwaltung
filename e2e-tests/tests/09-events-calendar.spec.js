// 09-events-calendar.spec.js - I5 Smoke-Test: Kalender-Views + iCal-Abo
const { test, expect } = require('@playwright/test');

/**
 * Smoke-Test Modul 6 I5:
 *   1. /events/calendar rendert FullCalendar-Container
 *   2. /api/events/calendar liefert JSON (200, array)
 *   3. /my-events/calendar rendert eigenen Kalender
 *   4. /my-events/ical zeigt Abo-URL (Token wird lazy generiert)
 *   5. Regenerate-Button erzeugt neuen Token
 *   6. /ical/subscribe/{token} liefert text/calendar (unauthenticated)
 *
 * Voraussetzungen:
 *   - Migration 005 eingespielt (users.ical_token, categories.color)
 *   - FullCalendar-Assets unter src/public/js/vendor/fullcalendar/
 *   - admin@vaes.test / Admin123! (2FA aus)
 */
test.describe('Modul 6 I5: Kalender + iCal', () => {
    test('Kalender-Views, iCal-Settings und Subscribe-Feed', async ({ page, request }) => {
        test.setTimeout(60_000);

        let subscribeUrl = null;

        await test.step('1. /events/calendar rendert Kalender-Container', async () => {
            await page.goto('events/calendar');
            await expect(page.getByRole('heading', { name: /Event-Kalender/i })).toBeVisible();
            // toBeAttached statt toBeVisible: das Container-DIV hat vor FullCalendar-Init
            // Hoehe 0 und gilt daher als "hidden". Der Smoke-Test prueft hier die
            // serverseitige Auslieferung der View; das FullCalendar-Rendering ist
            // Browser-JS-Sache und nicht Teil dieses Backend-Smokes.
            await expect(page.locator('#calendar')).toBeAttached();
            const feedUrl = await page.locator('#calendar').getAttribute('data-feed-url');
            expect(feedUrl).toContain('/api/events/calendar');
            console.log('[Smoke I5] Feed-URL:', feedUrl);
        });

        await test.step('2. /api/events/calendar liefert JSON-Array', async () => {
            const response = await page.goto('api/events/calendar');
            expect(response.status()).toBe(200);
            const body = await page.content();
            // FullCalendar-Feed MUSS ein Array sein (auch wenn leer)
            const jsonMatch = body.match(/\[.*\]/s);
            expect(jsonMatch, 'JSON-Array erwartet').not.toBeNull();
        });

        await test.step('3. /my-events/calendar rendert eigenen Kalender', async () => {
            await page.goto('my-events/calendar');
            await expect(page.getByRole('heading', { name: /Mein Kalender/i })).toBeVisible();
            // toBeAttached statt toBeVisible: das Container-DIV hat vor FullCalendar-Init
            // Hoehe 0 und gilt daher als "hidden". Der Smoke-Test prueft hier die
            // serverseitige Auslieferung der View; das FullCalendar-Rendering ist
            // Browser-JS-Sache und nicht Teil dieses Backend-Smokes.
            await expect(page.locator('#calendar')).toBeAttached();
        });

        await test.step('4. /my-events/ical zeigt Abo-URL', async () => {
            await page.goto('my-events/ical');
            await expect(page.getByRole('heading', { name: /iCal-Abo/i })).toBeVisible();

            const urlInput = page.locator('#icalUrl');
            await expect(urlInput).toBeVisible();
            subscribeUrl = await urlInput.inputValue();
            expect(subscribeUrl).toMatch(/\/ical\/subscribe\/[a-f0-9]{64}$/);
            console.log('[Smoke I5] Abo-URL:', subscribeUrl);
        });

        await test.step('5. Regenerate erzeugt neuen Token', async () => {
            // onsubmit-confirm mit Playwright: dialog-handler
            page.once('dialog', dialog => dialog.accept());
            await page.getByRole('button', { name: /Neuen Token erzeugen/i }).click();
            await page.waitForLoadState('networkidle');
            await expect(page.getByText(/Neuer iCal-Abo-Link erzeugt/i)).toBeVisible();

            const newUrl = await page.locator('#icalUrl').inputValue();
            expect(newUrl).not.toEqual(subscribeUrl);
            expect(newUrl).toMatch(/\/ical\/subscribe\/[a-f0-9]{64}$/);
            subscribeUrl = newUrl;
            console.log('[Smoke I5] Neue Abo-URL:', subscribeUrl);
        });

        await test.step('6. /ical/subscribe/{token} liefert text/calendar', async () => {
            // Die von der App generierte subscribeUrl zeigt auf die Produktions-URL
            // (aus config.app.url). Fuer den Smoke-Test gegen localhost tauschen wir
            // den Host aus und behalten nur den Token-Path.
            const pathPart = subscribeUrl.replace(/^https?:\/\/[^/]+/, '');
            const pageOrigin = new URL(page.url()).origin; // z.B. http://localhost:8000
            const localSubscribeUrl = pageOrigin + pathPart;
            console.log('[Smoke I5] Test-URL:', localSubscribeUrl);

            const resp = await request.get(localSubscribeUrl);
            if (resp.status() !== 200) {
                const errBody = await resp.text();
                console.log('[Smoke I5] FAIL-Body (erste 2000 Zeichen):',
                    errBody.substring(0, 2000));
            }
            expect(resp.status()).toBe(200);

            const contentType = resp.headers()['content-type'] ?? '';
            expect(contentType).toContain('text/calendar');

            const body = await resp.text();
            expect(body).toContain('BEGIN:VCALENDAR');
            expect(body).toContain('END:VCALENDAR');
            expect(body).toContain('TZID:Europe/Berlin');
            console.log('[Smoke I5] iCal-Feed-Response OK, Groesse:', body.length);
        });
    });
});
