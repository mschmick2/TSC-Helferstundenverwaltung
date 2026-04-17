#!/usr/bin/env node
// Playwright-Browser gezielt installieren.
// Auf Windows schlaegt `npx playwright install` manchmal aufgrund von Pfaden /
// Rechten fehl. Dieses Script verwendet die API direkt.

const { execSync } = require('child_process');

const BROWSERS = ['chromium'];

console.log(`Installing Playwright browsers: ${BROWSERS.join(', ')}`);
try {
    execSync(`npx playwright install ${BROWSERS.join(' ')}`, {
        stdio: 'inherit',
    });
    console.log('\n✓ Browser installation complete.');
} catch (err) {
    console.error('\n✗ Browser installation failed.');
    console.error(err.message || err);
    process.exit(1);
}
