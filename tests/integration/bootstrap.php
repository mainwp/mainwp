<?php
/**
 * Bootstrap for the live "Integration" suite.
 *
 * Deliberately minimal: it loads ONLY the Composer autoloader and the live test
 * base class. It does NOT boot the WordPress test harness, does NOT need a
 * database, and does NOT define MAINWP_TESTING_MODE. These tests make real
 * outbound HTTP to a remote dashboard / children and assert nothing about local
 * WordPress state, so the full harness (and the LocalWP MySQL it depends on)
 * would be pure overhead and a fragility.
 *
 * Run via:  ./vendor/bin/phpunit -c phpunit.integration.xml.dist
 * or:       ~/.claude/skills/run-mainwp-testbed/run.sh test-live
 *
 * The suite skips itself entirely when REMOTE_DASH_URL is unset.
 *
 * @package MainWP\Dashboard\Tests\Integration
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/class-mainwp-live-test-case.php';
