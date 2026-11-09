# Vendored: wordpress/ai-provider-for-google

Official Google (Gemini) provider for the WordPress PHP AI Client SDK, vendored
as a Composer-package-mode library. MainWP registers it with the AI Client on
`init` priority 6, guarded by `hasProvider('google')`, so a site running the
real ai-provider-for-google plugin always wins (see class-mainwp-assistant-onboarding.php).

- Upstream: https://github.com/WordPress/ai-provider-for-google
- Version: 1.1.1 (commit c6344cbb7d34c140c9ecd811a182bbd23b6fe049, 2026-08-31)
- License: GPL-2.0-or-later (LICENSE in this directory)
- Contents: upstream src/ and assets/images/google.svg (referenced by createProviderMetadata); the plugin bootstrap, tests, and tooling are omitted.

To update: replace src/ with upstream's, update the version line above, and re-run
the assistant onboarding test suite (tests/assistant/test-assistant-onboarding-*.php).
