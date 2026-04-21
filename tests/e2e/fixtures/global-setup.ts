import { execSync } from 'child_process';
import * as path from 'path';

/**
 * Einmal pro Test-Run: Baut die E2E-DB komplett neu.
 * Ruft scripts/setup-e2e-db.php. Braucht laufende MySQL auf 127.0.0.1:3306.
 */
export default async function globalSetup(): Promise<void> {
  const repoRoot = path.resolve(__dirname, '..', '..', '..');
  const script = path.join(repoRoot, 'scripts', 'setup-e2e-db.php');

  // eslint-disable-next-line no-console
  console.log('[e2e] Baue helferstunden_e2e neu …');
  try {
    const out = execSync(`php "${script}"`, {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    // eslint-disable-next-line no-console
    console.log(out);
  } catch (e: unknown) {
    const err = e as { stdout?: Buffer | string; stderr?: Buffer | string; message?: string };
    const out = typeof err.stdout === 'string' ? err.stdout : err.stdout?.toString() ?? '';
    const errTxt = typeof err.stderr === 'string' ? err.stderr : err.stderr?.toString() ?? '';
    throw new Error(
      `[e2e] setup-e2e-db.php fehlgeschlagen.\nSTDOUT:\n${out}\nSTDERR:\n${errTxt}\n${err.message ?? ''}`
    );
  }
}
