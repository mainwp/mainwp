<?php
/**
 * Remote MCP uninstall routine, called from the plugin's root uninstall.php.
 *
 * PHP 7.4 syntax and self-contained: WordPress runs uninstall without loading the plugin, and the
 * module's other classes need PHP 8.1. The option and table names repeat
 * Remote_MCP_Settings, Remote_MCP_Install::TABLES and Remote_MCP_Install::MODULE_TABLES.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Drop the module's tables and delete its options and cron event.
 *
 * Runs only when the module's own db version option says it was ever installed, so uninstalling
 * a Dashboard that never enabled the connector touches nothing.
 */
function uninstall() {
    global $wpdb;

    if ( false === get_option( 'mainwp_remote_mcp_db_version', false ) ) {
        return;
    }

    // End every connection before dropping anything, the way disabling the connector does.
    // A host can refuse DROP and still allow UPDATE, and if the secret delete below fails too, a
    // reinstall at the same address would otherwise bring the old tokens back. A retried uninstall
    // may find some tables already dropped, so these statements stay quiet.
    $suppress = $wpdb->suppress_errors( true );
    $bumped   = false;
    for ( $attempt = 0; $attempt < 3 && ! $bumped; $attempt++ ) {
        $bumped = 0 < (int) $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (subject_id, generation) VALUES (0, 1) ON DUPLICATE KEY UPDATE generation = generation + 1', $wpdb->prefix . 'mainwp_oauth_generations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the site bump also ends open consent requests and codes.
    }
    $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'revoked', revoke_reason = 'disabled', revoked_at = %d, version = version + 1 WHERE status IN ('pending', 'live')", $wpdb->prefix . 'mainwp_oauth_grants', time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall revokes the module's own grants.
    if ( ! $bumped ) {
        // Without the bump, open consent requests and unredeemed codes still match the stored
        // generations after a reinstall, so end them directly.
        $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'failed' WHERE status IN ('pending', 'approving')", $wpdb->prefix . 'mainwp_oauth_requests' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall ends the module's own consent requests.
        $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'burned' WHERE status = 'live'", $wpdb->prefix . 'mainwp_oauth_codes' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall ends the module's own codes.
    }
    $wpdb->suppress_errors( $suppress );

    // Children before parents, stopping at the first failure. While a token or code row survives, its
    // grants, requests and generations stay too: a reinstall over dropped parents would restart grant
    // ids and generations, and a leftover token could attach to another user's new grant.
    // Approvals and jobs belong to grants the same way, and the clients table holds no ids any
    // other table reuses.
    $dropped = true;
    $tables  = array(
        'mainwp_oauth_tokens',
        'mainwp_oauth_codes',
        'mainwp_remote_mcp_jobs',
        'mainwp_remote_mcp_approvals',
        'mainwp_oauth_grants',
        'mainwp_oauth_requests',
        'mainwp_remote_mcp_clients',
        'mainwp_oauth_generations',
    );
    foreach ( $tables as $name ) {
        if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the module's own tables.
            $dropped = false;
            break;
        }
    }

    // The secret goes regardless: it is the sensitive part, and without it no leftover token row can be verified.
    $secret_gone = delete_option( 'mainwp_remote_mcp_secret' );
    if ( ! $secret_gone ) {
        // delete_option() is also false when the option never existed, which counts as gone.
        $secret_gone = null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s', $wpdb->options, 'mainwp_remote_mcp_secret' ) ) && '' === $wpdb->last_error; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the options cache can claim a failed delete succeeded.
    }
    delete_option( 'mainwp_remote_mcp_enabled' );
    delete_option( 'mainwp_remote_mcp_bound_home' );
    delete_option( 'mainwp_remote_mcp_home_changed' );
    delete_option( 'mainwp_remote_mcp_notice_state' );
    delete_metadata( 'user', 0, 'mainwp_remote_mcp_notices_seen', '', true );
    delete_option( 'mainwp_remote_mcp_competing_rules' );
    delete_option( 'mainwp_remote_mcp_registration_enabled' );
    delete_option( 'mainwp_remote_mcp_static_clients' );
    delete_option( 'mainwp_remote_mcp_tool_switches' );
    delete_option( 'mainwp_remote_mcp_unaudited_tools_enabled' );
    delete_option( 'mainwp_remote_mcp_counters' );
    $tick_options = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'mainwp_remote_mcp_job_tick_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall discovers the module's per-job locks; delete_option clears their caches.
    foreach ( $tick_options as $option ) {
        delete_option( $option );
    }
    // Include orphaned timeout rows left by interrupted transient cleanup.
    foreach ( array( 'mainwp_remote_mcp_cimd_', 'mainwp_remote_mcp_cimdrl_', 'mainwp_remote_mcp_reg_', 'mainwp_rmcp_rl_', 'mainwp_remote_mcp_kc_', 'mainwp_remote_mcp_probe_' ) as $prefix ) {
        $transients = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_' . $prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- discover module-owned transients and orphaned timeouts for cache-aware deletion.
        foreach ( $transients as $option ) {
            if ( 0 === strpos( $option, '_transient_timeout_' ) ) {
                delete_option( $option );
            } else {
                delete_transient( substr( $option, strlen( '_transient_' ) ) );
                delete_option( $option );
            }
        }
    }
    // The marker stays while a table or the secret is left, so a later reinstall and uninstall retries.
    if ( $dropped && $secret_gone ) {
        delete_option( 'mainwp_remote_mcp_db_version' );
    }

    wp_clear_scheduled_hook( 'mainwp_remote_mcp_purge' );
    // Each job schedules its tick with its own id as the argument, which wp_clear_scheduled_hook() wouldn't match.
    wp_unschedule_hook( 'mainwp_remote_mcp_job_tick' );
}

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    uninstall();
}
