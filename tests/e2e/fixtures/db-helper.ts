import { execSync } from 'child_process';
import * as path from 'path';

/**
 * DB-Helpers fuer Playwright-Specs. Wrapper um scripts/e2e-set-setting.php und
 * direkte PDO-Inline-Skripte. Bewusst dunn gehalten — keine eigene
 * DB-Connection im Node-Prozess, das ueberlassen wir PHP mit der existierenden
 * Projekt-Konfig.
 *
 * Nutzung aus einer Spec:
 *   import { setE2eSetting } from '../fixtures/db-helper';
 *   test.beforeAll(() => setE2eSetting('events.tree_editor_enabled', '1'));
 *   test.afterAll(()  => setE2eSetting('events.tree_editor_enabled', '0'));
 */

const repoRoot = path.resolve(__dirname, '..', '..', '..');

/**
 * Setzt/aktualisiert einen Settings-Key in der helferstunden_e2e-DB.
 * Fuer Feature-Flag-Toggles zwischen Spec-Phases.
 */
export function setE2eSetting(key: string, value: string): void {
  const script = path.join(repoRoot, 'scripts', 'e2e-set-setting.php');
  try {
    execSync(`php "${script}" "${key}" "${value}"`, {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (e: unknown) {
    const err = e as { stdout?: Buffer | string; stderr?: Buffer | string; message?: string };
    const out = typeof err.stdout === 'string' ? err.stdout : err.stdout?.toString() ?? '';
    const errTxt = typeof err.stderr === 'string' ? err.stderr : err.stderr?.toString() ?? '';
    throw new Error(
      `setE2eSetting(${key}=${value}) fehlgeschlagen.\nSTDOUT:\n${out}\nSTDERR:\n${errTxt}\n${err.message ?? ''}`
    );
  }
}

/**
 * Leert die `edit_sessions`-Tabelle in der helferstunden_e2e-DB.
 * Genutzt von Spec 17 (Edit-Session-Hinweis) im beforeEach, damit
 * Sessions aus dem vorigen Test nicht den Multi-Tab-Dedup-Filter
 * im EditSessionView verfaelschen — der Server haelt Sessions bis
 * 120 s als aktiv, mehrere Tests in serial Mode laufen schneller.
 */
export function clearE2eEditSessions(): void {
  const script = path.join(repoRoot, 'scripts', 'e2e-truncate-edit-sessions.php');
  try {
    execSync(`php "${script}"`, {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (e: unknown) {
    const err = e as { stdout?: Buffer | string; stderr?: Buffer | string; message?: string };
    const out = typeof err.stdout === 'string' ? err.stdout : err.stdout?.toString() ?? '';
    const errTxt = typeof err.stderr === 'string' ? err.stderr : err.stderr?.toString() ?? '';
    throw new Error(
      `clearE2eEditSessions fehlgeschlagen.\nSTDOUT:\n${out}\nSTDERR:\n${errTxt}\n${err.message ?? ''}`
    );
  }
}
