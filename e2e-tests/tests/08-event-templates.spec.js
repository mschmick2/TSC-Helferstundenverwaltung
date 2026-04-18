// 08-event-templates.spec.js - I4 Smoke-Test: Template-CRUD + Versionierung + Event-Ableitung
const { test, expect } = require('@playwright/test');

test.describe('Modul 6 I4: Event-Templates', () => {
    const suffix = Date.now().toString().slice(-6);
    const templateName = `Smoke-Template ${suffix}`;
    const taskTitle = `Smoke-Task ${suffix}`;
    const eventTitle = `Smoke-Event ${suffix}`;

    test('Template anlegen, Task hinzufuegen, Event ableiten, neue Version speichern', async ({ page }) => {
        test.setTimeout(60_000);

        let templateId = null;

        // ---------------------------------------------------------------------
        await test.step('1a. Template-Liste aufrufen', async () => {
            await page.goto('admin/event-templates');
            await expect(page.getByRole('heading', { name: /Event-Templates/i })).toBeVisible();
        });

        await test.step('1b. "Neues Template"-Button/Link finden und klicken', async () => {
            const newBtn = page.locator('a, button').filter({ hasText: /Neues Template/i }).first();
            await expect(newBtn).toBeVisible();
            await newBtn.click();
            // Entweder Collapse/Modal oeffnet sich (Name-Feld sichtbar) oder Navigation zu /create
            await expect(page.locator('input[name="name"]').first()).toBeVisible({ timeout: 10_000 });
        });

        await test.step('1c. Template-Name eintragen und speichern', async () => {
            await page.locator('input[name="name"]').first().fill(templateName);

            const descField = page.locator('textarea[name="description"], input[name="description"]').first();
            if (await descField.count() > 0 && await descField.isVisible()) {
                await descField.fill('Playwright-Smoke-Test');
            }

            // Submit im Form, das das Name-Feld enthaelt
            const form = page.locator('form').filter({ has: page.locator('input[name="name"]') }).first();
            await form.locator('button[type="submit"], input[type="submit"]').first().click();

            // Entweder Redirect auf /edit oder zurueck auf Liste
            await page.waitForLoadState('networkidle');
            console.log('[Smoke] Nach Template-Create URL:', page.url());
        });

        await test.step('1d. Template-ID bestimmen (via URL oder via Liste)', async () => {
            // Fall A: direkt auf edit-Seite
            let match = page.url().match(/\/event-templates\/(\d+)\/edit/);
            if (match) {
                templateId = match[1];
            } else {
                // Fall B: wir sind auf der Liste gelandet — Link zum neuen Template finden
                const tplLink = page.locator(`a`).filter({ hasText: templateName }).first();
                await expect(tplLink).toBeVisible({ timeout: 5000 });
                const href = await tplLink.getAttribute('href') ?? '';
                const m2 = href.match(/\/event-templates\/(\d+)/);
                expect(m2).not.toBeNull();
                templateId = m2[1];
                // Zur Edit-Seite navigieren
                await page.goto(`admin/event-templates/${templateId}/edit`);
            }
            expect(templateId).not.toBeNull();
            console.log(`[Smoke] Template angelegt: id=${templateId}, name="${templateName}"`);
        });

        // ---------------------------------------------------------------------
        await test.step('2. Task hinzufuegen', async () => {
            // "Neue Task"-Button oeffnet Collapse
            const newTaskBtn = page.locator('a, button').filter({ hasText: /Neue Task/i }).first();
            await expect(newTaskBtn).toBeVisible();
            await newTaskBtn.click();

            // Warten bis ein sichtbares title-Input im neuen Task-Form erscheint
            const titleInput = page.locator('input[name="title"]').first();
            await expect(titleInput).toBeVisible();
            await titleInput.fill(taskTitle);

            const hoursField = page.locator('input[name="hours_default"]').first();
            if (await hoursField.count() > 0 && await hoursField.isVisible()) {
                await hoursField.fill('2.5');
            }

            // slot_mode='fix' (Default) erfordert Offsets — sonst verletzt der
            // abgeleitete event_tasks-Eintrag chk_et_fix_times.
            const offStart = page.locator('input[name="default_offset_minutes_start"]').first();
            if (await offStart.count() > 0 && await offStart.isVisible()) {
                await offStart.fill('0');
            }
            const offEnd = page.locator('input[name="default_offset_minutes_end"]').first();
            if (await offEnd.count() > 0 && await offEnd.isVisible()) {
                await offEnd.fill('240');
            }

            // Das Form submitten, das das Task-Titel-Feld enthaelt
            const taskForm = page.locator('form').filter({ has: page.locator('input[name="title"]') }).first();
            await taskForm.locator('button[type="submit"], input[type="submit"]').first().click();
            await page.waitForLoadState('networkidle');
            await expect(page.getByText(taskTitle).first()).toBeVisible({ timeout: 10_000 });
            console.log(`[Smoke] Task hinzugefuegt: "${taskTitle}"`);
        });

        // ---------------------------------------------------------------------
        await test.step('3. Event aus Template ableiten', async () => {
            const deriveLink = page.locator('a, button').filter({ hasText: /Event ableiten/i }).first();
            await expect(deriveLink).toBeVisible();
            await deriveLink.click();
            await expect(page).toHaveURL(/\/derive/);

            await page.locator('input[name="title"]').first().fill(eventTitle);

            const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000);
            const iso = tomorrow.toISOString().slice(0, 10);
            await page.locator('input[name="start_at"]').fill(`${iso}T10:00`);
            await page.locator('input[name="end_at"]').fill(`${iso}T14:00`);

            const submitBtn = page.locator('button[type="submit"], input[type="submit"]')
                .filter({ hasText: /erzeugen|ableiten|speichern/i }).first();
            await submitBtn.click();
            await page.waitForLoadState('networkidle');
            await expect(page).toHaveURL(/\/admin\/events\/\d+/);
            await expect(page.getByText(eventTitle).first()).toBeVisible();
            console.log(`[Smoke] Event abgeleitet: "${eventTitle}"`);
        });

        // ---------------------------------------------------------------------
        await test.step('4. Save-as-new-Version', async () => {
            await page.goto(`admin/event-templates/${templateId}/edit`);
            await expect(page.getByText(/gesperrt|bereits Events/i)).toBeVisible({ timeout: 10_000 });

            const versionForm = page.locator('form[action*="save-as-new-version"]');
            await expect(versionForm).toBeVisible();
            await versionForm.locator('button[type="submit"], input[type="submit"]').first().click();
            await page.waitForLoadState('networkidle');

            const newMatch = page.url().match(/\/event-templates\/(\d+)\/edit/);
            expect(newMatch).not.toBeNull();
            const newTemplateId = newMatch[1];
            expect(newTemplateId).not.toEqual(templateId);
            console.log(`[Smoke] Neue Version: id=${newTemplateId} (parent=${templateId})`);
        });
    });
});
