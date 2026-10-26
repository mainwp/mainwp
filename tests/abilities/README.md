# MainWP Abilities API Test Suite

Comprehensive test coverage for the MainWP Abilities API integration (Phase 4 of the integration plan).

> **Note:** All commands should be run from the repository root directory.

## Quick Start

**⚠️ Source your environment file FIRST before running any tests:**

```bash
# Step 1: Source your environment file (path varies by setup)
source /path/to/your/.env

# Step 2: Run all Abilities tests
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=Abilities

# Or run a specific test file
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit tests/abilities/test-list-sites-ability.php
```

> **Note:** Your `.env` file should provide database socket and credentials required by the test harness. Tests WILL FAIL without sourcing the environment first.

## Prerequisites

- WordPress test harness installed (default: `$HOME/.mainwp-wp-tests/wordpress-tests-lib`); set `WP_TESTS_DIR=/tmp/wordpress-tests-lib` when running `bin/install-wp-tests.sh` to use the `/tmp` examples below.
- WordPress version with Abilities API support (or mock functions available)
- Environment file with database credentials and socket path

### One-Time Setup

If you haven't installed the WordPress test harness yet:

```bash
# Source your environment file for database socket
source /path/to/your/.env

# Install test harness (creates test database)
# Adjust DB credentials and socket variable name for your setup
bin/install-wp-tests.sh mainwp_tests root root "localhost:$DB_SOCKET" latest
```

## Running Tests

```bash
# Always source your .env first!
source /path/to/your/.env

# Run all abilities tests
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=Abilities

# Run specific test file
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit tests/abilities/test-list-sites-ability.php

# Run with verbose output
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=Abilities --verbose

# Run tests matching a pattern
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --filter="test_list_sites"

# Run with coverage (requires Xdebug)
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --coverage-html coverage/ --testsuite=Abilities
```

## Test Files

### Base Classes

| File | Description |
|------|-------------|
| `class-mainwp-abilities-test-case.php` | Base test case with fixtures and helpers for ability tests |

### Discovery & Registration

| File | Description |
|------|-------------|
| `test-abilities-discovery.php` | Ability/category registration and discoverability |

### Sites Abilities

| File | Description |
|------|-------------|
| `test-list-sites-ability.php` | `mainwp/list-sites-v1` - List sites with pagination, filtering, search |
| `test-get-site-ability.php` | `mainwp/get-site-v1` - Single site retrieval by ID/domain |
| `test-sync-sites-ability.php` | `mainwp/sync-sites-v1` - Site sync with batch queuing |
| `test-site-plugins-themes-abilities.php` | `mainwp/get-site-plugins-v1` and `mainwp/get-site-themes-v1` |

### Updates Abilities

| File | Description |
|------|-------------|
| `test-updates-abilities.php` | All update abilities: list, run, ignored updates |

### Cross-Cutting Concerns

| File | Description |
|------|-------------|
| `test-permissions.php` | Permission callbacks, ACL enforcement, REST auth |
| `test-batch-operations.php` | Queuing, thresholds, job storage, cron scheduling |
| `test-rest-integration.php` | REST v2 abilities-first pattern and fallback |
| `test-rest-api-execution.php` | Direct execution of abilities via Abilities API REST endpoints |

## Test Coverage

### Abilities Tested (9 total)

**Sites Category (5 abilities):**
- `mainwp/list-sites-v1`
- `mainwp/get-site-v1`
- `mainwp/sync-sites-v1`
- `mainwp/get-site-plugins-v1`
- `mainwp/get-site-themes-v1`

**Updates Category (4 abilities):**
- `mainwp/list-updates-v1`
- `mainwp/run-updates-v1`
- `mainwp/list-ignored-updates-v1`
- `mainwp/set-ignored-updates-v1`

### Test Areas

| Area | Coverage |
|------|----------|
| Registration | Categories and abilities are discoverable |
| Schemas | Input/output validation |
| Execute callbacks | Core functionality for each ability |
| Permissions | Global (manage_options), per-site ACLs, REST API keys |
| Batch operations | Queuing for >50 sites, immediate execution for ≤50 |
| Error handling | Offline sites, child version checks, not found, partial failures |
| REST integration | Abilities-first pattern, parameter mapping, fallback |
| REST API execution | Direct HTTP execution via /wp-abilities/v1/abilities/{name}/run |

## Test Patterns

### Skipping Tests Without Abilities API

```php
$this->skip_if_no_abilities_api();
```

### Creating Test Sites

```php
$site_id = $this->create_test_site([
    'name'                 => 'Test Site',
    'url'                  => 'https://test.example.com/',
    'offline_check_result' => 1, // 1 = online, -1 = offline
    'version'              => '5.0.0', // Child plugin version
]);
```

### Setting Up Admin User

```php
$this->set_current_user_as_admin();
```

### Executing Abilities

```php
$result = $this->execute_ability('mainwp/list-sites-v1', [
    'page'     => 1,
    'per_page' => 10,
]);
```

### Mocking Failures

```php
// Mock sync failure
$this->mock_sync_failure('mainwp_connection_failed', 'Connection timed out');

// Mock partial update result
$this->mock_partial_update_result(
    [$success_site_id],
    [['site_id' => $fail_site_id, 'code' => 'error_code', 'message' => 'Error message']]
);
```

## Key Test Scenarios

### Batch Queuing Threshold

- **≤50 sites**: Execute immediately, return `synced`/`errors` arrays
- **>50 sites**: Queue for background processing, return `job_id`/`status_url`

### Permission Checks

1. **Global permission**: Requires `manage_options` capability
2. **Per-site ACL**: Uses `MainWP_System::check_site_access()`
3. **REST API key**: Prioritizes `MainWP_REST_Authentication::get_rest_valid_user()`

### Site Resolution

- Numeric identifiers → Resolve as MainWP site ID
- String identifiers → Normalize URL and search by domain

### Error Handling

- Offline sites → `mainwp_site_offline` error code
- Outdated child → `mainwp_child_outdated` error code
- Not found → `mainwp_site_not_found` error code
- Access denied → `mainwp_access_denied` error code

## Notes

- Tests automatically clean up created sites in `tearDown()`
- All tests skip gracefully if Abilities API is not available
- Batch queuing tests create many sites (50-60) and may be slow
- REST integration tests require REST API infrastructure
- Some tests may need actual plugin/theme data seeded for full coverage

## REST API Execution Tests

The `test-rest-api-execution.php` file tests the Abilities API REST endpoints directly, verifying that all 9 MainWP abilities can be executed via HTTP requests to `/wp-abilities/v1/abilities/{name}/run`. This is distinct from `test-rest-integration.php`, which tests MainWP's REST v2 controllers that internally use abilities.

Key differences:
- **test-rest-integration.php**: Tests `/mainwp/v2/sites`, `/mainwp/v2/updates` (MainWP REST controllers)
- **test-rest-api-execution.php**: Tests `/wp-abilities/v1/abilities/mainwp/*/run` (Abilities API endpoints)

The REST API execution tests cover:
- GET vs POST method validation based on `readonly` annotation
- Input handling (no input, empty object, partial, full)
- Authentication requirements
- Error responses (400, 403, 404, 405 status codes)
- Response structure validation against output schemas

## Intentionally Omitted Test Scenarios

The following scenarios from the implementation plan are **intentionally not covered** in `test-rest-api-execution.php`. These decisions were made after careful technical analysis:

### Application Password Authentication Tests

**Not implemented because:**
- The existing tests (`test_rest_ability_requires_authentication`, `test_rest_ability_permission_denied_for_subscriber`) already verify that unauthenticated and low-privilege users are denied
- Using `wp_set_current_user()` is the standard WordPress PHPUnit pattern for REST API permission tests
- Testing Application Password validation would test WordPress Core's `WP_Application_Passwords` class, not MainWP code
- MainWP REST API key authentication is already tested in `test-rest-integration.php`

### WordPress HTTP API Tests (`wp_remote_get`/`wp_remote_post`)

**Not implemented because:**
- PHPUnit unit tests run without an HTTP server
- `wp_remote_*` functions make actual HTTP requests that would fail without a listening server
- Using `rest_do_request()` is the standard and recommended approach for WordPress REST API unit tests
- WordPress Core itself uses `rest_do_request()` for REST API tests, not `wp_remote_*`

If true HTTP-level integration tests are needed in the future, they would require a separate E2E test suite (e.g., Playwright, Codeception) with a running WordPress instance.

### Content-Type Response Header Assertions

**Not implemented because:**
- WordPress REST API automatically sets `Content-Type: application/json` for all responses
- Testing this would verify WordPress Core behavior, not MainWP behavior
- The `test_rest_ability_response_is_json()` test verifies responses are JSON-serializable, which is the meaningful check

### Current Test Scope

The `test-rest-api-execution.php` file focuses on:
- Controller-level behavior via `rest_do_request()`
- Input validation and schema conformance
- Permission callbacks (both grant and deny paths)
- HTTP method validation (GET for readonly, POST for write)
- Response structure validation against output schemas
- MainWP-specific error codes and handling

## Integration Plan Reference

These tests implement the testing strategy from:
`.mwpdev/plans/abilities-api/abilities-api-integration-plan.md` (Section 11, lines 2333-2934)

## Contributing

When adding new tests:

1. Extend `MainWP_Abilities_Test_Case` for ability tests
2. Extend `WP_Test_REST_TestCase` for REST-specific tests
3. Use `skip_if_no_abilities_api()` at the start of each test
4. Clean up test data in `tearDown()`
5. Follow WordPress coding standards (4-space indentation)
6. **Always clean up filters/actions** - use try/finally (see below)

### Cleaning Up Filters and Global State

When tests add filters, actions, or modify global state, **always ensure cleanup happens** even if assertions fail. Use `try/finally` blocks to guarantee cleanup:

```php
public function test_behavior_with_modified_threshold() {
    $this->skip_if_no_abilities_api();
    $this->set_current_user_as_admin();

    // Add filter to modify behavior for this test.
    add_filter( 'mainwp_abilities_batch_threshold', array( $this, 'filter_lower_batch_threshold' ) );

    try {
        // Test code with assertions.
        $result = $this->execute_ability( 'mainwp/check-sites-v1', [
            'site_ids_or_domains' => $site_ids,
        ] );

        $this->assertNotWPError( $result );
        $this->assertTrue( $result['queued'] );
        // ... more assertions ...

    } finally {
        // Always remove filter to prevent test pollution.
        remove_filter( 'mainwp_abilities_batch_threshold', array( $this, 'filter_lower_batch_threshold' ) );
    }
}
```

#### Why This Matters

- **Test isolation**: Filters left registered leak into subsequent tests, causing unpredictable failures
- **Assertion failures don't skip cleanup**: Without try/finally, a failed assertion stops execution before `remove_filter()` runs
- **Debugging nightmare**: Filter pollution causes tests to pass/fail depending on execution order

#### Common Pitfalls

| Pitfall | Problem | Solution |
|---------|---------|----------|
| Filter cleanup after assertions | Failed assertion skips cleanup | Wrap in try/finally |
| Cleanup only in `tearDown()` | Works, but harder to trace which test added what | Prefer local try/finally for test-specific filters |
| Forgetting `remove_action()` | Actions persist and fire unexpectedly | Match every `add_action()` with `remove_action()` |
| Global variable changes | State leaks between tests | Reset in finally block or tearDown() |

#### Alternative: tearDown() Cleanup

For filters used across multiple tests in a class, use `tearDown()`:

```php
protected function tearDown(): void {
    // Remove any filters this test class might have added.
    remove_filter( 'mainwp_abilities_batch_threshold', array( $this, 'filter_lower_batch_threshold' ) );
    remove_filter( 'mainwp_some_other_filter', array( $this, 'filter_some_behavior' ) );

    parent::tearDown();
}
```

**Prefer try/finally** for single-test filters because:
- Cleanup is visually paired with the `add_filter()` call
- Easier to review and maintain
- Self-documenting: reader sees the full lifecycle in one place
