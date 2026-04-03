# Server Information Refresh Plan

## Objective

Refresh the MainWP Server Information page so that it:

1. Reflects the real MainWP settings surface, not a small hand-picked subset.
2. Includes support-oriented diagnostics that help triage the most common dashboard-side issues.
3. Preserves a safe "Community System Report" export that omits or masks sensitive data so users can post it publicly.

This document is a planning artifact only. It does not propose implementation details beyond the level needed to guide the work.

## Current State Summary

The current Server Information page is assembled primarily from:

- `pages/page-mainwp-server-information.php`
- `pages/page-mainwp-server-information-handler.php`

The "MainWP Dashboard Settings" section is currently a manually curated list of options. It is useful, but it is not a full representation of the settings pages and it misses several values that support regularly needs when troubleshooting.

There are also a few structural issues in the current page:

- Settings coverage is incomplete.
- Support diagnostics are mixed together with raw environment data.
- The report is hard to scan because unrelated information lives in one long flat report.
- The community export is driven by table-row CSS classes and should be retained, but the sensitivity model needs to be made more explicit as new fields are added.
- The current "Complete URL" field is actually based on the HTTP referrer and should either be renamed or replaced.

## Planning Principles

- Mirror the real admin settings pages first.
- Do not blindly dump every stored option.
- Reuse existing settings models where they already exist.
- Supplement the REST settings controller with page-specific sources when the controller is incomplete.
- Treat privacy as a first-class requirement, not an afterthought.
- Keep the full report support-friendly and the community report forum-safe.

## Main Source Files

Current report implementation:

- `pages/page-mainwp-server-information.php`
- `pages/page-mainwp-server-information-handler.php`
- `assets/js/mainwp.js`

Settings sources already available in code:

- `includes/rest-api/controller/version2/class-mainwp-rest-settings-controller.php`
- `pages/page-mainwp-settings.php`
- `pages/page-mainwp-manage-backups.php`
- `class/class-mainwp-monitoring-view.php`
- `class/class-mainwp-uptime-monitoring-edit.php`
- `class/class-mainwp-notification-settings.php`
- `modules/logs/classes/class-log-settings.php`
- `modules/api-backups/classes/class-api-backups-settings.php`

Knowledgebase references that match common support asks:

- `.../mintlify/docs/troubleshooting/wordpress-rest-api-does-not-respond.mdx`
- `.../mintlify/docs/troubleshooting/potential-issues.mdx`
- `.../mintlify/docs/troubleshooting/how-to-enable-error-logging.mdx`
- `.../mintlify/docs/customization/manage-wp-cron-on-low-traffic-mainwp-dashboard.mdx`
- `.../mintlify/docs/advanced/miscellaneous/mainwp-browser-extensions.mdx`
- `.../mintlify/docs/advanced/miscellaneous/mainwp-bridge.mdx`
- `.../mintlify/docs/sites/management/mainwp-dashboard-settings.mdx`

## Recommended Information Architecture

Instead of one long mixed report, split the page into these sections:

1. Environment
2. Connectivity and Authentication
3. Scheduler and Background Jobs
4. MainWP Settings
5. Extensions and Plugins
6. Debug and Logs

This is closer to how mature status pages such as WooCommerce's system status are organized, and it makes support triage faster.

## Phase 1: Settings-Page Parity

### Goal

Make the Server Information page reflect the settings pages listed below as closely as possible.

### Important Constraint

The REST settings controller should be the main source for settings parity where it already exposes user-facing values, but it is not sufficient on its own. Some settings exist on the admin pages and are not fully represented there.

### Settings Pages to Cover

#### 1. `wp-admin/admin.php?page=Settings`

Add or expand:

- Daily update time
- Daily update frequency
- Timezone
- Date format
- Time format
- Sidebar position
- Hide "Update Everything"
- Language update behavior
- Plugin, theme, and core advanced automatic update settings
- Automatic update cadence
- Day-of-week and day-of-month scheduling
- Delay between automatic updates
- HTTP response check enabled state
- HTTP response check method
- Backup before update enabled state
- Number of backup copies or days retained before upgrades
- Primary backup system
- Legacy backup mode
- Backup storage behavior
- Remote storage behavior
- Archive format
- Backup notification settings
- Execute backup tasks in chunks

Also include backup-page values that are effectively part of the settings experience even if they do not live in the main REST getter:

- Auto-detect maximum file descriptors
- Maximum file descriptors fallback
- Load files in memory before zipping

#### 2. `wp-admin/admin.php?page=SettingsAdvanced`

Add or expand:

- Browser cache expiration time
- Maximum simultaneous requests
- Maximum simultaneous requests per IP
- Minimum delay between requests
- Minimum delay between requests to the same IP
- Maximum simultaneous sync requests
- Maximum simultaneous install and update requests
- Maximum simultaneous uptime monitoring requests
- Chunk size for site processing
- Chunk sleep interval
- Optimize data loading
- Use WP Cron
- SSL certificate verification
- Verify connection method
- OpenSSL signature algorithm
- Force IPv4
- Sync-data selection summary

#### 3. `wp-admin/admin.php?page=MonitoringSettings`

Add or expand:

- Uptime monitoring enabled state
- Uptime monitor type
- Uptime check interval
- Uptime request method
- Uptime request timeout
- Accepted "up" status codes
- Down confirmation check count
- Keyword monitoring value
- Monitoring data retention period
- Site Health monitoring enabled state
- Site Health threshold

#### 4. `wp-admin/admin.php?page=SettingsEmail`

Add a compact per-email-type summary for:

- Enabled or disabled
- Recipient list summary
- Email type
- Subject
- Heading

Do not include raw email template bodies in the report.

#### 5. `wp-admin/admin.php?page=CostTrackerSettings`

Add:

- Currency
- Currency symbol position
- Thousand separator
- Decimal separator
- Number of decimal places
- Product cost category defaults or summary
- Custom payment method summary

If this data is noisy, prefer counts and summaries over dumping long arbitrary lists.

#### 6. `wp-admin/admin.php?page=SettingsInsights`

Add:

- Network Activity / Insights logging enabled state
- Automatic archive enabled state
- Dashboard log retention period
- Child-site log retention period
- Event logging configuration summary

The event logging configuration should be summarized rather than dumped verbatim. For example:

- Number of enabled dashboard event groups
- Number of enabled child-site event groups
- Number of enabled change-log groups

This page is more than a single toggle and should be treated that way.

#### 7. `wp-admin/admin.php?page=SettingsApiBackups`

Add a provider-by-provider summary with secrets masked:

- Provider enabled state
- Non-secret identifiers such as account email, URL, username, site name, company ID, or path where applicable
- Whether an API token or secret is configured

Never expose raw secrets in either the full report or the community report.

#### 8. `wp-admin/admin.php?page=MainWPTools`

Add:

- Current MainWP theme
- Guided tours enabled state
- Chatbase enabled state
- Guided video enabled state

The tools page contains actions as well as settings. Only durable settings should be included in the report.

## Phase 2: Support Diagnostics

### Goal

Add diagnostics that support asks for repeatedly, even when they are not stored settings.

### Diagnostics to Add

#### 1. WordPress REST API Reachability

Include:

- Reachable or not reachable
- Tested endpoint
- HTTP status code
- Short result classification

Examples of useful classifications:

- Reachable
- Blocked by auth challenge
- Rewrite or permalink issue suspected
- Security or firewall issue suspected
- Timed out

This is one of the highest-value additions because MainWP features depend heavily on REST accessibility.

#### 2. Permalink Mode

Include a simple summary:

- Plain permalinks
- Pretty permalinks

This should sit next to the REST API reachability result because the troubleshooting path is strongly related.

#### 3. Loopback / Self-Connect Result

Replace or expand the current "Server self connect" row so it includes:

- HTTP status code
- Error code when applicable
- Short classification
- Possibly a terse reason string

Examples:

- Success
- 401 auth challenge detected
- 403 blocked by firewall or WAF
- Timeout
- Unexpected response body

This is a better place to surface dashboard-side HTTP auth interference than trying to guess a perfect "Basic Auth yes/no" flag.

#### 4. Public Dashboard IP

Add a dedicated field for the public or external dashboard IP, separate from the raw server IP pulled from the local server context.

This is useful when support needs a whitelisting target and the current server IP is local or private.

#### 5. Dashboard URL Summary

In the full report only, add:

- Home URL
- Site URL
- Whether the dashboard runs over HTTPS

This helps support diagnose mixed-protocol or URL mismatch issues.

The community export must not expose the full dashboard URL.

#### 6. Debug and Error Logging Summary

Add:

- `WP_DEBUG`
- `WP_DEBUG_LOG`
- `WP_DEBUG_DISPLAY`
- PHP `log_errors`
- Error log path status
- Whether the log file is readable

Do not dump log contents into the report.

#### 7. Cron Health Summary

Keep the existing "Use WP Cron" setting, but add operational context:

- Key scheduled event count
- Last MainWP cron run
- Next scheduled MainWP cron run
- Simple stale or blocked signal when detectable

The report should help support distinguish between "WP Cron disabled by choice" and "background work is not running."

#### 8. Relevant Conflict Signals

Consider lightweight support hints for:

- Caching plugin presence
- Security plugin presence
- Maintenance mode plugin presence
- Drop-ins or object cache presence

This should be a compact summary, not an opinionated health score.

## Phase 3: Presentation and Export

### Goal

Make the report easier to scan while preserving safe sharing behavior.

### UI and Structure

- Group fields under the six recommended sections.
- Keep pass, warning, and info states where meaningful.
- Use short labels and consistent value formatting.
- Prefer compact summaries over very large raw dumps for long list-style values.
- Allow export of the full report.
- Keep the community-safe export path.

### Community System Report Requirements

The community report must continue to let users share a report publicly without exposing sensitive dashboard details.

Current behavior already excludes some fields through `mwp-not-generate-row` handling in:

- `pages/page-mainwp-server-information.php`
- `assets/js/mainwp.js`

Current excluded fields include:

- WordPress Root Directory
- Server Name
- Server IP
- HTTP Host
- Server Port
- Complete URL
- Currently Executing Script Pathname
- Current Page URI
- Remote Address
- Remote Host
- Remote Port

This behavior must be retained during the refresh.

### Recommended Privacy Model

Instead of relying only on table-row CSS classes, introduce explicit field metadata for export visibility. Each field should be marked as one of:

- `full_only`
- `community_masked`
- `community_safe`

This will make future additions safer and easier to review.

### Data Sensitivity Guidelines

Always hide from the community report:

- Dashboard URLs and hosts
- Public or private IP addresses
- Filesystem paths
- Referrer or current URI values
- Client network identifiers
- API tokens, secrets, keys, passwords

Mask or summarize in the community report:

- Email recipient addresses
- Provider account identifiers
- Error log paths
- Detailed auth or header strings if they expose infrastructure details

Safe to include in the community report:

- Version numbers
- Boolean feature flags
- Timeouts
- Retry counts
- Schedules
- Enabled or disabled state
- Generic pass or warning outcomes

## Suggested Implementation Order

1. Refactor the report structure into grouped sections without changing behavior yet.
2. Expand settings parity using the REST settings controller where available.
3. Fill gaps from page-specific sources:
   - backup settings
   - monitoring retention
   - email summaries
   - insights logging summaries
4. Add support diagnostics:
   - REST API reachability
   - permalink mode
   - self-connect classification
   - public IP
   - debug summary
   - cron summary
5. Formalize field sensitivity metadata.
6. Update both export paths:
   - full internal report
   - community-safe report
7. Review wording and output formatting for readability.

## Acceptance Criteria

The refresh should be considered complete when all of the following are true:

- The Server Information page covers the listed settings pages in a meaningful way.
- The report includes the new support diagnostics.
- The page is organized into clear sections.
- The community report still works.
- The community report does not reveal dashboard URL, IP, path, or other sensitive values.
- API backup secrets are never exposed.
- Long, noisy settings are summarized where needed.
- The output remains easy to copy, paste, and scan.

## Open Questions

- Should the full report show complete email recipient lists, or should it also use masking there?
- Should the report include counts only for event logging groups, or a more explicit enabled/disabled summary?
- Should the public dashboard IP be tested live on demand, cached, or omitted when lookup fails?
- Should cron health use a generic WordPress summary, a MainWP-only event summary, or both?
- Should the page eventually support two explicitly labeled exports:
  - Full Support Report
  - Community-Safe Report

## Practical Recommendation

Start with settings-page parity and the privacy model. That gives the biggest immediate support win with the lowest ambiguity. Then add the diagnostic tests that are most tightly tied to recurring support issues:

- REST API reachability
- Permalink mode
- Self-connect classification
- Public dashboard IP
- Debug summary
- Cron summary

Once those are in place, finish with the presentation cleanup and export hardening.
