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

const childUrls = (process.env.REMOTE_TEST_CHILD_URLS ?? '')
  .split(',')
  .map((s) => trimSlash(s))
  .filter(Boolean);

export const ENV = {
  dashUrl: trimSlash(process.env.REMOTE_DASH_URL),
  dashUser: (process.env.REMOTE_DASH_USER ?? '').trim(),
  dashPass: process.env.REMOTE_DASH_PASS ?? '',

  childUser: (process.env.REMOTE_CHILD_USER ?? '').trim(),
  childPass: process.env.REMOTE_CHILD_PASS ?? '',
  childUrls,
  backupChildUrl: trimSlash(process.env.REMOTE_TEST_BACKUP_CHILD_URL),

  // Plugin file toggled on/off for the child-induced break/recover scenario.
  // Must be installed on `toggleChildUrl`; default Hello Dolly.
  togglePlugin: (process.env.REMOTE_TEST_TOGGLE_PLUGIN ?? 'hello.php').trim(),

  // Child that the reversible plugin-toggle test runs against. Needs the toggle
  // plugin installed. Falls back to the first configured child.
  toggleChildUrl: trimSlash(process.env.REMOTE_TEST_TOGGLE_CHILD_URL) || (childUrls[0] ?? ''),

  // One designated disposable child for the destructive connection-break test
  // (deactivate mainwp-child -> dashboard disconnected -> reconnect). Falls back
  // to the first configured child. Keep this OFF the data-bearing backup child.
  breakChildUrl: trimSlash(process.env.REMOTE_TEST_BREAK_CHILD_URL) || (childUrls[0] ?? ''),
};

/** Dashboard login is fully configured (URL + creds). */
export const dashConfigured = Boolean(ENV.dashUrl && ENV.dashUser && ENV.dashPass);

/** At least one child + shared child creds are configured. */
export const childConfigured = Boolean(
  ENV.childUser && ENV.childPass && ENV.childUrls.length > 0,
);
