# Dropbox native backups: owned-app recommendation

MWP-2148 · 2026-11-06 · Decision requested from Dennis

## Recommendation: offer both

After a separately approved implementation and Dropbox production approval, use a MainWP-owned app with a pasted authorization code and PKCE for new destinations. Retain the current per-user app flow for existing destinations and as an advanced option. Do not automatically reconnect or migrate existing destinations.

This removes developer-console setup from the normal connect experience, consistent with the October 31 pCloud product direction supplied in the ticket. Retaining custom apps protects existing backup access and gives administrators an alternative if the shared app cannot accept connections. Keeping only today's flow preserves its setup burden; replacing it outright risks stranding backups in another app's folder.

Approval should include the shared-app security trade-off: anyone can construct an authorization link using the public ID and their own verifier. Tricking a user into sharing that code grants access to their backups in the app folder. PKCE protects the legitimate attempt from code interception; it does not authenticate MainWP to the user. The connect screen and support guidance must tell users to start from their own Dashboard and paste the code only there. Custom apps remain an option for administrators who need their own app identity.

This PR changes documentation only. It registers no app, ships no credentials, changes no connect flow, and makes no release commitment. Investigation, no changelog. Dennis's explicit product decision is required before implementation or app registration.

## Evidence and limits

Source baseline: private `mainwp/mainwp-6`, `origin/main` at `18e637d3d5c852e1a30a5064ef80c760d07dbf5e`. Read-only pCloud comparison: `origin/feature/pcloud-destination` at `46372d4f39d7457b46e6644d3ca8faab48a9b485`. The storage directory and storage view have no diff between these revisions: pCloud's pattern is already on this main baseline. No branch merge was needed.

Official sources were read on November 6, 2026. No account authorization, token exchange, app-console change, or live backup test was performed. Feasibility rests on Dropbox documentation and its own executable SDK example, not a successful MainWP API probe. A later approved implementation must validate the entire lifecycle against a real authorized app before rollout.

### Code display and secretless PKCE

The [Dropbox OAuth guide](https://docs.dropboxapi.com/dropbox-api/docs/oauth) documents optional `redirect_uri`: omitting it displays a code. Registered redirects require exact matches, unsuitable for arbitrary self-hosted Dashboard URLs. It recommends PKCE for distributed software that cannot protect a static secret and supports offline refresh tokens. Enable the app's public-client setting for PKCE.

Stronger evidence for the combined flow is Dropbox's [official command-line PKCE example](https://github.com/dropbox/dropbox-sdk-python/blob/main/example/oauth/commandline-oauth-pkce.py). It constructs `DropboxOAuth2FlowNoRedirect` with an app key, `use_pkce=True`, and offline access, accepts a pasted code, then constructs a client with only the refresh token and app key. There is no app secret. The [SDK OAuth implementation](https://github.com/dropbox/dropbox-sdk-python/blob/main/dropbox/oauth.py) sends the client ID and verifier during exchange; its [refresh implementation](https://github.com/dropbox/dropbox-sdk-python/blob/main/dropbox/dropbox_client.py) includes a secret only if supplied. These are reference implementations, not proposed dependencies.

The guide's PKCE paragraph says to send the verifier instead of `client_id`; that conflicts with the official SDK, which keeps `client_id`. Follow the SDK request shape and verify it in the acceptance test rather than copying that sentence.

Proposed request contract (identifiers are field names, not credentials):

| Step | Request fields |
| --- | --- |
| Authorize | `client_id`, `response_type=code`, `token_access_type=offline`, `code_challenge`, `code_challenge_method=S256`; omit `redirect_uri` |
| Exchange | `grant_type=authorization_code`, `client_id`, pasted `code`, original `code_verifier`; omit `client_secret` and `redirect_uri` |
| Refresh | `grant_type=refresh_token`, `client_id`, stored `refresh_token`; omit `client_secret` |

Only a public client ID would need distribution in a later approved implementation. A shared secret must never be shipped or relayed through a MainWP token broker. Each Dashboard exchanges directly with Dropbox and stores its own encrypted refresh token.

Generate a fresh random verifier per connect attempt, retain it server-side in an encrypted, expiring, single-use stash tied to the initiating user and destination, and expose only its S256 challenge. Return a separate unpredictable attempt handle from the start call and require it on paste completion: no-redirect authorization does not round-trip state, and multiple new destinations all have ID zero. Require existing capability checks and a nonce when starting and completing the paste flow. Fail closed on absent, expired, replayed, cross-user, wrong-verifier, or changed-destination state. Consume the attempt before exchange; a mistyped or expired code intentionally requires restarting authorization. Never log codes, tokens, or verifiers. Paste UX needs explicit restart/cancel and expired-code guidance. The manual refresh-token field remains custom-app-only; owned mode accepts tokens solely through its bound PKCE exchange.

### Shared app approval and limits

The [Dropbox developer guide](https://docs.dropboxapi.com/dropbox-api/docs/developer-resources/developer-guide) says:

- Development starts with the owner's account. Additional users must be enabled; the ceiling is 500 users.
- At 50 linked users, approval must be received within two weeks or new linking freezes. Unlinking does not lift that freeze.
- Review normally starts at 50; early review can be requested with justification. Approved apps have no linked-user count limit.
- Submission needs an icon, API-use explanation, compliant branding/terms, a privacy policy, and least-privilege access. Production names cannot subsequently change.

MainWP should request early review for its distributed self-hosted product and prepare a controlled pilot if early review is declined. Treat approval as a release dependency, not a predictable two-week delivery estimate. Confirm that MainWP's backup feature qualifies as one distinct end-user app; Dropbox separately requires individual keys for developers using generic Dropbox wrappers.

Keep owned mode disabled in general releases until approval. A pilot build should require explicit local configuration, distribute to named testers, and track linked users in the app console. That limits accidental exposure, not third-party use of a known public ID; it cannot enforce a global user cap. Prepare the submission before approaching 50 links and arrange support for a new-link freeze. Dennis must approve the final app name and branding, including the user-visible App Folder name, before submission.

Prefer App Folder access with only `files.metadata.read`, `files.content.read`, and `files.content.write`, subject to checking every endpoint used. No team or sharing scopes are needed by the inventoried backup provider. Full Dropbox access would expand exposure and review burden. Shared-app suspension and aggregate service constraints create a common operational dependency; production user-count approval does not promise unlimited API throughput. Do not shard registrations to evade limits.

## Current code inventory

Paths below are relative to the repository baseline above. Method names provide stable search anchors.

| Surface | Dropbox today | pCloud comparison |
| --- | --- | --- |
| `class/backup/storage/class-mainwp-backup-storage-handler.php` | `ajax_dropbox_oauth_start`, `start_dropbox_oauth`: require key/secret, encrypt a ten-minute pending stash, request offline code with Dashboard redirect | `ajax_pcloud_connect`, `connect_pcloud`: receive pasted code, validate candidate, obtain server-selected app credentials, exchange and call normal save |
| Same handler | `consume_dropbox_oauth_state`, `complete_dropbox_oauth`, `handle_dropbox_oauth_callback`, `consume_dropbox_oauth_notice`: consume state, enforce initiating user, exchange, test/save, return notice | `stash_pcloud_connection`, `consume_pcloud_connection`: encrypted single-use confirmation for account changes; `refresh_pcloud_siblings` updates matching destinations after exchange |
| Same handler | `merge_candidate`, `run_connection_test` (`$required_secrets`), `save_destination`: app key identifies credentials; app secret and refresh token required; retention and connection validation | App/region/token come through a server-only argument; account changes and failed reconnects have explicit handling |
| Same handler | `SECRET_FIELDS` defines secret handling; `same_credential_target` compares Dropbox app keys; metadata-only saves compare app key, secret, token and path | Any future credential mode must participate in these comparisons and validation |
| `class/backup/storage/class-mainwp-backup-storage-dropbox.php` | `exchange_authorization_code` sends secret and redirect; `access_token` always sends secret on refresh. `put_object`, `head_object`, `get_object`, `delete_object`, `list_objects`, `connection_test` implement backup I/O; retention identity uses Dropbox marker-file IDs | `class-mainwp-backup-storage-pcloud.php`: `app_credentials`, `authorize_url`, `exchange_code_for_region`, `exchange_authorization_code`; regional API selection, access token, account/folder IDs, same provider I/O contract |
| `class/backup/storage/class-mainwp-backup-storage-destinations.php` | Encrypted settings persistence, provider factory, retention identity preparation | Same persistence/factory layer; provider-specific settings and identity |
| `pages/backup/page-mainwp-backup-storage-view.php` | Key, secret, folder, redirect URI, connect action, optional manual refresh token; inline JavaScript opens authorization and submits settings | `render_pcloud_connect_fields`, connect link, pasted code, confirmation and save handling |
| `class/backup/class-mainwp-backup-retention-protection.php` | Destination identity protects remote retention; `dropbox_connection_settings` includes the app secret in its identity receipt | pCloud has account/folder identity handling; its numeric IDs cannot replace Dropbox marker-file IDs |
| `class/backup/class-mainwp-backup-remote-path.php`, `class/backup/class-mainwp-backup-cloud-delete-driver.php` | Upload prefixes use site ID/name and snapshot ID, with no Dashboard identity; deletion operates by prefix | Shared-app destinations need isolated base folders without changing legacy paths |
| `class/backup/storage/class-mainwp-backup-upload-driver.php` and provider interface | Existing upload/restore/retention machinery consumes provider methods | Already shared; no new OAuth subsystem or backup-engine rewrite needed |
| `tests/backup/test-backup-storage-dropbox.php`, `test-backup-storage-destinations.php`, `test-backup-storage-access.php`, `test-backup-storage-page.php` | Provider, persistence, access and UI regression targets | `test-backup-storage-pcloud.php`, pCloud transport fixture and `tests/js/test-backup-storage-pcloud-confirm.js` supply comparison cases |

pCloud currently uses configured app credentials (including optional `MAINWP_PCLOUD_CLIENT_ID` / `MAINWP_PCLOUD_CLIENT_SECRET` overrides). That credential model is not a safe Dropbox template. Copy the user journey and existing save protections, not the secret handling or regional exchange. No credential values are reproduced here.

## Compatibility and migration

Existing settings must continue to select the stored app key, secret and refresh token for authorization and refresh. Do not replace them with a global owned-app key. Tokens are app-bound: switching app identity requires fresh user authorization. An App Folder belongs to its app, so an owned-app destination will not inherit the old app's backup folder. Full Dropbox legacy destinations may also point outside the new app's accessible namespace.

Create an owned-app connection as a separate destination, keeping the old destination, schedules, remote object references and restore/retention identity intact. Credential mode and app identity must be fixed per row: reject in-place custom-to-owned changes, including crafted save/connect requests. Today's Dropbox path can re-prove a marker in another app namespace without a pCloud-style confirmation, so its current safeguards alone do not enforce this rule. Test upload and restore in the new destination before offering an explicit schedule/default change. Retain the old connection for restoring and managing earlier backups; do not copy or delete old archives automatically. Cross-app data transfer and removing the custom-app option are outside this proposal.

A future implementation needs a persisted distinction between custom-app and owned-app credentials while treating existing rows as custom without rewriting them. Never infer owned mode from a blank secret: damaged custom credentials must fail closed. Bind owned-app refresh tokens to the app key that issued them so a future app-key rotation cannot silently strand stored tokens. Preserve blank-keeps-stored behavior and test-before-persist for custom mode. Reconnecting the owned row must retain its app, account and base-folder identity; a change requires a new destination. Existing custom-row editing behavior remains unchanged.

All Dashboards connected to the same account through the owned app share its App Folder. Require a stable, separately generated per-Dashboard base folder for new owned destinations, rather than the current empty default. Site IDs, names and snapshot IDs can match on cloned or staging Dashboards; uploads overwrite matching paths, and deletion uses prefixes. A marker per destination does not isolate those namespaces. Persist the folder choice, disallow empty or colliding owned-mode paths within the Dashboard, and document that a cloned Dashboard must select a new folder before its first write. This limits accidental collisions; it is not an access-control boundary between installations authorized to the same app/account.

## How much code should be shared?

Reuse destination validation, encrypted storage, access checks, connection tests, notices and test fixtures already shared by providers. Use pCloud's connect/paste/confirmation layout as the UI pattern, adding a Dropbox-specific start step to generate the verifier before the authorization link opens.

Keep small provider-specific authorize/exchange/refresh methods. Dropbox needs PKCE and offline refresh; pCloud needs US/EU exchange and its own account/token lifecycle. Do not copy `refresh_pcloud_siblings` into Dropbox without evidence that Dropbox invalidates sibling tokens. Testing multiple destinations and Dashboards with the same account/app is required. Extract a helper only if implementation reveals exact repeated logic; MWP-2148 does not justify a generic OAuth framework.

## Effort and acceptance for a later approved change

Estimate: **5–8 engineering days**, plus Dropbox review elapsed time (unknown), for one engineer familiar with native backups. This includes retaining custom apps, not automatic archive migration.

| Work | Estimate |
| --- | --- |
| App configuration/review materials, opt-in pilot setup and authorized real-account lifecycle probe | 0.5–1 day |
| PKCE start/paste handling, secretless exchange/refresh, fixed credential modes and isolated default folders | 1.5–2.5 days |
| Storage UI, reconnect/recovery, freeze handling and support instructions | 1 day |
| Focused regressions, live backup/restore/retention checks and review fixes | 2–3.5 days |

Before release, prove no-redirect S256 exchange and refresh without a secret against the selected app; invalid/replayed/expired codes and wrong verifiers must fail without persisting credentials. Verify expiry/restart, cancellation, cross-user access, concurrent edits and missing refresh tokens. Exercise legacy reconnect and background refresh unchanged, restore/delete/list of old backups, and separate new-folder upload/restore/retention. Test two destinations and two Dashboards using one account, account switching, revocation, unavailable app configuration and provider failures. Confirm tokens remain local, encrypted, and absent from responses/logs. Check aggregate app operations and freeze messaging during the pilot.

Include matching site/name/snapshot IDs on two Dashboards and a cloned configuration: neither upload nor deletion may affect the other's archives. Reject mode/app/account/folder changes on an existing owned row, custom-to-owned conversion, blank-secret mode inference and manual token injection into owned mode. Verify simultaneous new-connect attempts remain distinct and that a code generated with another verifier cannot complete the legitimate attempt. Review the phishing warning with support and confirm pilot opt-in and new-link freeze recovery before general availability.

No production PHP changes, testbed deployment, or live API probe are needed to deliver this recommendation. The real-account checks above are release acceptance criteria for subsequent work, not claims made by this investigation.
