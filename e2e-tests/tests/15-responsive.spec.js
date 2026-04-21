// 15-responsive.spec.js - Responsive-Tests fuer Mobile/Tablet/Desktop
const { test, expect } = require('@playwright/test');

const viewports = {
    handy:   { width: 375, height: 667 },
    tablet:  { width: 768, height: 1024 },
    desktop: { width: 1280, height: 900 },
};

const pages = [
    { name: 'login', path: '/login', skipAuth: true },
    { name: 'dashboard', path: '/dashboard' },
    { name: 'entries-list', path: '/entries' },
    { name: 'entry-create', path: '/entries/create' },
];

for (const [device, size] of Object.entries(viewports)) {
    for (const p of pages) {
        test(`${device} - ${p.name}`, async ({ page, browser }) => {
            if (p.skipAuth) {
                const ctx = await browser.newContext({ viewport: size });
                const fresh = await ctx.newPage();
                await fresh.goto(p.path);
                await fresh.screenshot({
                    path: `screenshots/15-${device}-${p.name}.png`,
                    fullPage: true,
                });
                await ctx.close();
            } else {
                await page.setViewportSize(size);
                await page.goto(p.path);
                await page.screenshot({
                    path: `screenshots/15-${device}-${p.name}.png`,
                    fullPage: true,
                });
            }
            // Keine horizontale Scrollbar auf Mobile
            if (device === 'handy') {
                const pageToCheck = p.skipAuth ? page : page;
                const hasOverflow = await pageToCheck.evaluate(() => {
                    return document.documentElement.scrollWidth > window.innerWidth + 2;
                }).catch(() => false);
                expect(hasOverflow).toBe(false);
            }
        });
    }
}
