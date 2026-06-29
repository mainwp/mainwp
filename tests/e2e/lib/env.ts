/**
 * Remote testbed configuration, read from REMOTE_* environment variables.
 *
 * Source network-testbed/.env before running, or use
 * `run.sh test-live e2e`, which sources it. Nothing here throws when unset;
 * specs gate themselves on the `*Configured` flags below.
 */

function trimSlash(value: string | undefined): string {
  return (value ?? '').trim().replace(/\/+$/, '');
}

export const ENV = {
  dashUrl: trimSlash(process.env.REMOTE_DASH_URL),
  dashUser: (process.env.REMOTE_DASH_USER ?? '').trim(),
  dashPass: process.env.REMOTE_DASH_PASS ?? '',

  childUser: (process.env.REMOTE_CHILD_USER ?? '').trim(),
  childPass: process.env.REMOTE_CHILD_PASS ?? '',
  childUrls: (process.env.REMOTE_TEST_CHILD_URLS ?? '')
    .split(',')
    .map((s) => trimSlash(s))
    .filter(Boolean),
  backupChildUrl: trimSlash(process.env.REMOTE_TEST_BACKUP_CHILD_URL),

  // Plugin file toggled on/off for the child-induced break/recover scenario.
  togglePlugin: (process.env.REMOTE_TEST_TOGGLE_PLUGIN ?? 'hello.php').trim(),
};

/** Dashboard login is fully configured (URL + creds). */
export const dashConfigured = Boolean(ENV.dashUrl && ENV.dashUser && ENV.dashPass);

/** At least one child + shared child creds are configured. */
export const childConfigured = Boolean(
  ENV.childUser && ENV.childPass && ENV.childUrls.length > 0,
);
