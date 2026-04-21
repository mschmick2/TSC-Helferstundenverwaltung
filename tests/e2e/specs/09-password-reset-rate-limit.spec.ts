import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import * as path from 'path';
import { MailpitClient } from '../fixtures/mailpit-client';
import { ALICE } from '../fixtures/users';

/**
 * Modul CLAUDE.md §8 Nr. 2: Zwei-Bucket-Rate-Limiting fuer POST /forgot-password.
 *
 * Zweck dieser Spec: beweist am laufenden System, dass der Schutz gegen verteilte
 * Flood-Angriffe auf ein einzelnes Opfer-Postfach tatsaechlich greift — und zwar
 * OHNE Information-Leak (gleiche Erfolgsmeldung, gleiche Mail-Zahl ab Bucket-Voll).
 *
 * Konfiguration (aus src/config/config.e2e.php):
 *   forgot_password_rate_limit_max_per_ip    = 5     (15 Min)
 *   forgot_password_rate_limit_max_per_email = 3     (1 Std)
 *
 * Erwarteter Ablauf bei 5 konsekutiven Submits fuer ALICE von ::1 (Dev-Server):
 *   Submit 1..3 : Email-Bucket frei    -> Mail raus (Mailpit zaehlt 3)
 *   Submit 4..5 : Email-Bucket voll    -> silent: gleiche Flash, KEINE Mail
 *   Submit 6    : IP-Bucket voll       -> sichtbarer 429-Flash, redirect /forgot-password
 *
 * Vor jedem Test wird die rate_limits-Tabelle geleert — alle Requests dieser
 * Suite kommen vom selben lokalen PHP-Server und wuerden sich sonst den Bucket
 * ueber die Testgrenzen hinweg teilen.
 */

const repoRoot = path.resolve(__dirname, '..', '..', '..');
const truncateScript = path.join(repoRoot, 'scripts', 'e2e-truncate-rate-limits.php');

async function truncateRateLimits(): Promise<void> {
  execSync(`php "${truncateScript}"`, { cwd: repoRoot, stdio: ['ignore', 'pipe', 'pipe'] });
}

async function submitForgotPassword(page: import('@playwright/test').Page, email: string): Promise<void> {
  await page.goto('/forgot-password');
  await page.locator('input[name="email"]').fill(email);
  await page.getByRole('button', { name: /link anfordern|senden|zurücksetzen|zuruecksetzen/i }).first().click();
}

test.describe('Forgot-Password — Zwei-Bucket-Rate-Limiting', () => {
  test.beforeEach(async () => {
    await truncateRateLimits();
  });

  test('Email-Bucket sperrt ab 4. Submit silent; IP-Bucket ab 6. Submit sichtbar', async ({ page }) => {
    const mailpit = new MailpitClient();
    if (!(await mailpit.isAvailable())) {
      test.skip(true, 'Mailpit nicht erreichbar — bitte mailpit auf 127.0.0.1:8025 starten');
    }
    await mailpit.deleteAll();

    // --- Phase 1: Submits 1..3 gehen durch, 3 Mails landen im Postfach ---
    for (let i = 1; i <= 3; i++) {
      await submitForgotPassword(page, ALICE.email);
      // Controller redirected auf /login mit info-Flash.
      await page.waitForURL(/\/login/, { timeout: 5_000 });
      await expect(page.locator('.alert-info')).toBeVisible();
    }

    // Mailpit bekommt die Mails mit leichtem Lag — gib dem letzten Versand Zeit.
    const thirdMail = await mailpit.waitForMessage({
      to: ALICE.email,
      subject: 'Passwort',
      timeoutMs: 8_000,
    });
    expect(thirdMail, 'Dritte Reset-Mail sollte bei Mailpit ankommen').not.toBeNull();

    const afterPhase1 = await mailpit.getMessages();
    const aliceMailsAfterPhase1 = afterPhase1.filter((m) =>
      (m.To ?? []).some((r) => r.Address.toLowerCase() === ALICE.email.toLowerCase())
    );
    expect(aliceMailsAfterPhase1.length).toBe(3);

    // --- Phase 2: Submits 4..5 -> Email-Bucket voll, silent ---
    // Kritisch: Der User-Flow zeigt die GLEICHE info-Flash wie im Normalfall
    // (kein Information-Leak), aber es darf keine weitere Mail rausgehen.
    for (let i = 4; i <= 5; i++) {
      await submitForgotPassword(page, ALICE.email);
      await page.waitForURL(/\/login/, { timeout: 5_000 });
      await expect(page.locator('.alert-info')).toBeVisible();
      // Explizit: keine Fehlermeldung zu sehen — sonst waere der Leak da.
      await expect(page.locator('.alert-danger')).toHaveCount(0);
    }

    // Kurz warten, damit eine etwaige (ungewollte) Mail noch auflaufen koennte.
    await page.waitForTimeout(1_000);
    const afterPhase2 = await mailpit.getMessages();
    const aliceMailsAfterPhase2 = afterPhase2.filter((m) =>
      (m.To ?? []).some((r) => r.Address.toLowerCase() === ALICE.email.toLowerCase())
    );
    expect(
      aliceMailsAfterPhase2.length,
      'Nach Submits 4+5 darf die Mail-Zahl nicht gestiegen sein'
    ).toBe(3);

    // --- Phase 3: Submit 6 -> IP-Bucket voll, sichtbarer 429 ---
    await submitForgotPassword(page, ALICE.email);
    await expect(page).toHaveURL(/\/forgot-password/);
    await expect(page.locator('.alert-danger')).toContainText(/zu viele/i);
  });

  test('IP-Bucket sperrt nicht, wenn verschiedene Emails vertauscht werden (IP zaehlt, Email nicht)', async ({ page }) => {
    // Dieselbe IP submittet 5× fuer verschiedene Emails. IP-Bucket fuellt sich
    // auf 5 (Limit), 6. Submit — egal fuer welche Email — muss sichtbar blocken.
    const emails = [
      'a@example.local', 'b@example.local', 'c@example.local',
      'd@example.local', 'e@example.local',
    ];

    for (const email of emails) {
      await submitForgotPassword(page, email);
      await page.waitForURL(/\/login/, { timeout: 5_000 });
    }

    // 6. Submit mit neuer Email -> trotzdem blockiert durch IP-Bucket.
    await submitForgotPassword(page, 'f@example.local');
    await expect(page).toHaveURL(/\/forgot-password/);
    await expect(page.locator('.alert-danger')).toContainText(/zu viele/i);
  });
});
