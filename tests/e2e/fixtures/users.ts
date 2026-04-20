/**
 * Seed-User fuer die E2E-Suite. Entspricht 1:1 scripts/setup-e2e-db.php.
 */
export interface SeedUser {
  email: string;
  password: string;
  mnr: string;
  vorname: string;
  nachname: string;
  roles: ReadonlyArray<string>;
}

export const PASSWORD = 'e2e-test-pw';

export const ADMIN: SeedUser = {
  email: 'admin@e2e.local',
  password: PASSWORD,
  mnr: 'E2E-ADM',
  vorname: 'E2E',
  nachname: 'Admin',
  roles: ['administrator', 'pruefer', 'event_admin', 'mitglied'],
};

export const PRUEFER: SeedUser = {
  email: 'pruefer@e2e.local',
  password: PASSWORD,
  mnr: 'E2E-PRF',
  vorname: 'E2E',
  nachname: 'Pruefer',
  roles: ['pruefer', 'mitglied'],
};

export const EVENT_ADMIN: SeedUser = {
  email: 'event@e2e.local',
  password: PASSWORD,
  mnr: 'E2E-EVT',
  vorname: 'E2E',
  nachname: 'Eventadmin',
  roles: ['event_admin', 'mitglied'],
};

export const ALICE: SeedUser = {
  email: 'alice@e2e.local',
  password: PASSWORD,
  mnr: 'E2E-ALI',
  vorname: 'Alice',
  nachname: 'Mitglied',
  roles: ['mitglied'],
};

export const BOB: SeedUser = {
  email: 'bob@e2e.local',
  password: PASSWORD,
  mnr: 'E2E-BOB',
  vorname: 'Bob',
  nachname: 'Mitglied',
  roles: ['mitglied'],
};

export const ALL_USERS: ReadonlyArray<SeedUser> = [ADMIN, PRUEFER, EVENT_ADMIN, ALICE, BOB];
