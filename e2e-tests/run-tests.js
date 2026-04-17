#!/usr/bin/env node
// Einzelne Spec-Datei gezielt ausfuehren.
// Verwendung: node run-tests.js tests/05-members.spec.js

const { spawnSync } = require('child_process');

const target = process.argv[2];
if (!target) {
    console.error('Usage: node run-tests.js <spec-file>');
    process.exit(1);
}

const result = spawnSync('npx', ['playwright', 'test', target, '--timeout=60000'], {
    stdio: 'inherit',
    shell: true,
});
process.exit(result.status ?? 1);
