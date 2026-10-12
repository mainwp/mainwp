<?php
/**
 * Child Site and client search for the WordPress command palette.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Command_Palette
 */
class MainWP_Command_Palette { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Configure the dynamic command loader.
     *
     * @return array<string, mixed> Loader configuration.
     */
    public static function get_search_config() {
        if ( ! MainWP_System_Utility::is_admin() ) {
            return array();
        }

        return array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'searchNonce' => wp_create_nonce( 'mainwp_command_palette_search_sites' ),
            'strings'     => array(
                'searchError'   => __( 'Search failed. Select to try again.', 'mainwp' ),
                'syncingAction' => __( 'Syncing…', 'mainwp' ),
                'searchLabel'   => __( 'Search sites, clients and commands', 'mainwp' ),
                /* translators: %s: Child Site name. */
                'actionsFor'    => __( 'Actions for %s', 'mainwp' ),
                /* translators: 1: Action label, 2: Child Site name. */
                'siteAction'    => __( '%1$s for %2$s', 'mainwp' ),
                'selectSite'    => __( 'Select site', 'mainwp' ),
                'actions'       => __( 'Actions', 'mainwp' ),
                'search'        => __( 'Search', 'mainwp' ),
                'enter'         => _x( 'Enter', 'keyboard key', 'mainwp' ),
                /* translators: %s: Default action for the selected site. */
                'keyboard'      => __( 'Use Up and Down to select a result. Press Enter for %s. Press Tab to reach site actions, Shift+Tab to move back, and Escape to return to search.', 'mainwp' ),
            ),
        );
    }

    /**
     * Search the Dashboard's stored site names/URLs and client names.
     */
    public static function ajax_search_sites() {
        MainWP_Post_Site_Handler::instance()->secure_request( 'mainwp_command_palette_search_sites' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- secure_request() verifies the action nonce above.
        if ( isset( $_POST['recent'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secure_request() verifies the nonce; get_recent_commands() strictly validates decoded command names.
            $names = is_string( $_POST['recent'] ) && strlen( $_POST['recent'] ) <= 512 ? json_decode( wp_unslash( $_POST['recent'] ), true ) : array();
            wp_send_json_success( static::get_recent_commands( $names ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- secure_request() verifies the action nonce above.
        $search = isset( $_POST['search'] ) && is_string( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        wp_send_json_success( array_merge( static::get_site_commands( $search ), static::get_client_commands( $search ) ) );
    }

    /**
     * Resolve at most five previously used entities through the search permissions.
     *
     * @param mixed $names Native command names from the user's Recent list.
     * @return array<int, array<string, mixed>> Visible commands in history order.
     */
    public static function get_recent_commands( $names ) {
        if ( ! MainWP_System_Utility::is_admin() || ! is_array( $names ) ) {
            return array();
        }

        $requested  = array();
        $site_ids   = array();
        $client_ids = array();
        foreach ( array_slice( $names, 0, 5 ) as $name ) {
            if ( ! is_string( $name ) || ! preg_match( '#^mainwp/(site|client)/([1-9][0-9]*)$#D', $name, $match ) || (string) (int) $match[2] !== $match[2] ) {
                continue;
            }
            $requested[] = $name;
            if ( 'site' === $match[1] ) {
                $site_ids[] = (int) $match[2];
            } else {
                $client_ids[] = (int) $match[2];
            }
        }

        $commands = array_merge(
            empty( $site_ids ) ? array() : static::get_site_commands( '', $site_ids ),
            empty( $client_ids ) ? array() : static::get_client_commands( '', $client_ids )
        );
        $by_name  = array_column( $commands, null, 'name' );
        $results  = array();
        foreach ( array_unique( $requested ) as $name ) {
            if ( isset( $by_name[ $name ] ) ) {
                $results[] = $by_name[ $name ];
            }
        }
        return $results;
    }

    /**
     * Build client navigation commands using the client dashboard's permissions.
     *
     * @param string $search Client name search term.
     * @param array  $ids Optional bounded client IDs for Recent resolution.
     * @return array<int, array<string, mixed>> Client commands.
     */
    public static function get_client_commands( $search, $ids = array() ) {
        global $wpdb;

        if ( ! MainWP_System_Utility::is_admin() || ! is_string( $search ) ||
            ( ! \mainwp_current_user_can( 'dashboard', 'manage_clients' ) && ! \mainwp_current_user_can( 'dashboard', 'access_client_dashboard' ) ) ) {
            return array();
        }

        $search = trim( $search );
        $ids    = is_array( $ids ) ? array_slice( wp_parse_id_list( $ids ), 0, 5 ) : array();
        $ids    = array_values( array_filter( $ids ) );
        if ( empty( $ids ) && ! preg_match( '/^.{2,200}$/us', $search ) ) {
            return array();
        }

        $allowed = MainWP_Abilities_Util::get_allowed_site_ids();
        if ( array() === $allowed ) {
            return array();
        }

        $db      = MainWP_DB_Client::instance();
        $clients = esc_sql( $db->get_table_name( 'wp_clients' ) );
        $sites   = esc_sql( $db->get_table_name( 'wp' ) );
        $where   = empty( $ids ) ? $wpdb->prepare( 'wc.name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ) : $wpdb->prepare( 'wc.client_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', ...$ids );
        if ( 'all' !== $allowed ) {
            // Apply the client dashboard's "at least one allowed site" scope
            // before LIMIT, so inaccessible matches cannot crowd out results.
            $where .= " AND EXISTS (SELECT 1 FROM {$sites} wp WHERE wp.client_id = wc.client_id AND wp.is_staging = 0 AND wp.id IN (" . implode( ',', $allowed ) . '))';
        }

        $orderby = empty( $ids ) ? $wpdb->prepare(
            'CASE WHEN wc.name = %s THEN 0 WHEN wc.name LIKE %s THEN 1 ELSE 2 END, wc.name ASC, wc.client_id ASC',
            $search,
            $wpdb->esc_like( $search ) . '%'
        ) : 'wc.name ASC, wc.client_id ASC';
        $sql     = "SELECT wc.client_id, wc.name FROM {$clients} wc WHERE {$where} ORDER BY {$orderby} LIMIT 5";
        $rows    = MainWP_Client::filter_viewable_clients( $db->get_results_result( $sql ) );
        $results = array();

        foreach ( (array) $rows as $client ) {
            $name = wp_specialchars_decode( wp_strip_all_tags( $client->name ), ENT_QUOTES );
            if ( '' === $name ) {
                continue;
            }
            $results[] = array(
                'name'     => 'mainwp/client/' . (int) $client->client_id,
                /* translators: %s: Client name. */
                'label'    => sprintf( __( '%s (Client)', 'mainwp' ), $name ),
                'keywords' => array( $name, __( 'Client', 'mainwp' ) ),
                'category' => 'view',
                'url'      => admin_url( 'admin.php?page=ManageClients&client_id=' . (int) $client->client_id ),
            );
        }

        return $results;
    }

    /**
     * Build a bounded list of native commands, with exact matches first.
     *
     * @param string $search Search term.
     * @param array  $ids Optional bounded site IDs for Recent resolution.
     * @return array<int, array<string, mixed>> Site commands.
     */
    public static function get_site_commands( $search, $ids = array() ) {
        global $wpdb;

        if ( ! MainWP_System_Utility::is_admin() || ! is_string( $search ) ) {
            return array();
        }

        $search = rtrim( preg_replace( '#^(?:https?://)?(?:www\.)?#i', '', trim( $search ) ), '/' );
        $ids    = is_array( $ids ) ? array_slice( wp_parse_id_list( $ids ), 0, 5 ) : array();
        $ids    = array_values( array_filter( $ids ) );
        if ( empty( $ids ) && ! preg_match( '/^.{2,200}$/us', $search ) ) {
            return array();
        }

        $db       = MainWP_DB::instance();
        $url_sql  = "TRIM(TRAILING '/' FROM REPLACE(REPLACE(REPLACE(LOWER(wp.url), 'https://', ''), 'http://', ''), 'www.', ''))";
        $contains = '%' . $wpdb->esc_like( $search ) . '%';
        $prefix   = $wpdb->esc_like( $search ) . '%';
        $where    = empty( $ids ) ? $wpdb->prepare( "(wp.name LIKE %s OR {$url_sql} LIKE %s)", $contains, $contains ) : $wpdb->prepare( 'wp.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed URL normalization expression and generated integer placeholders.

        // The optimized query applies Team Control scope; preserve legacy site ownership too.
        if ( MainWP_System::instance()->is_multi_user() ) {
            $where .= $wpdb->prepare( ' AND wp.userid = %d', get_current_user_id() );
        }

        $orderby = empty( $ids ) ? $wpdb->prepare(
            "CASE WHEN wp.name = %s OR {$url_sql} = %s THEN 0 WHEN wp.name LIKE %s OR {$url_sql} LIKE %s THEN 1 ELSE 2 END, wp.name ASC, wp.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed URL normalization expression.
            $search,
            $search,
            $prefix,
            $prefix
        ) : 'wp.name ASC, wp.id ASC';
        $sql      = $db->get_sql_websites_for_current_user_by_params(
            array(
                'view'               => 'custom_view',
                'select_wp_fields'   => array( 'id', 'name', 'url', 'userid' ),
                'select_sync_fields' => array( 'sync_errors' ),
                'others_fields'      => array(),
                'where'              => $where,
                'orderby'            => $orderby,
                'limit'              => 5,
            )
        );
        $sites    = $db->get_results_result( $sql );
        $commands = array();

        foreach ( (array) $sites as $site ) {
            if ( ! MainWP_System_Utility::can_edit_website( $site ) ) {
                continue;
            }

            $name    = wp_specialchars_decode( wp_strip_all_tags( $site->name ), ENT_QUOTES );
            $url     = preg_replace( '#^https?://#i', '', rtrim( $site->url, '/' ) );
            $actions = array();

            if ( \mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) {
                $actions['operations'] = array(
                    'label' => __( 'Operations', 'mainwp' ),
                    'url'   => admin_url( 'admin.php?page=managesites&dashboard=' . (int) $site->id ),
                );
            }
            if ( \mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
                $actions['admin'] = array(
                    'label' => __( 'Go to WP Admin', 'mainwp' ),
                    'url'   => admin_url( MainWP_Site_Open::get_open_site_admin_link( $site->id ) ),
                );
            }
            // Match the standard popup's connected-site eligibility on every page.
            if ( empty( $site->sync_errors ) ) {
                $actions['sync'] = array( 'label' => __( 'Sync Site', 'mainwp' ) );
            }
            if ( empty( $actions ) ) {
                continue;
            }

            $default_action = key( $actions );
            $commands[]     = array(
                'name'          => 'mainwp/site/' . (int) $site->id,
                /* translators: 1: Child Site name, 2: Site URL. */
                'label'         => sprintf( __( '%1$s (%2$s)', 'mainwp' ), $name, $url ),
                'keywords'      => array( $name, $site->url ),
                'category'      => 'sync' === $default_action ? 'command' : 'view',
                'defaultAction' => $default_action,
                'siteId'        => (int) $site->id,
                'siteName'      => $name,
                'actions'       => $actions,
            );
        }

        return $commands;
    }
}
