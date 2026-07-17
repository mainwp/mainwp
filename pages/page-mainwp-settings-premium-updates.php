<?php
/**
 * MainWP Premium Updates Settings page.
 *
 * Settings subpage for the built-in premium plugin & theme update
 * compatibility (MWP-1660): master switch, license notice, read-only
 * overview of detected products, and custom identifiers.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Settings_Premium_Updates
 *
 * @package MainWP\Dashboard
 */
class MainWP_Settings_Premium_Updates { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Transient used to cache the detected-products scan.
     *
     * @var string
     */
    const SCAN_TRANSIENT = 'mainwp_premium_updates_scan';

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Handle the settings form submission.
     *
     * @return bool True when settings were saved.
     */
    public static function handle_settings_post() { // phpcs:ignore -- NOSONAR - complex.
        if ( ! isset( $_POST['submit'] ) || ! isset( $_POST['wp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'PremiumUpdatesSettings' ) ) {
            return false;
        }

        if ( ! MainWP_System_Utility::is_admin() ) {
            return false;
        }

        MainWP_Utility::update_option( MainWP_Premium_Update_Registry::OPTION_ENABLED, isset( $_POST['mainwp_premium_updates_enabled'] ) ? 1 : 0 );

        $custom = MainWP_Premium_Update_Registry::get_custom_entries();

        // Remove entries flagged for removal.
        $remove = isset( $_POST['premium_updates_remove'] ) && is_array( $_POST['premium_updates_remove'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['premium_updates_remove'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! empty( $remove ) ) {
            $custom = array_filter(
                $custom,
                function ( $item ) use ( $remove ) {
                    return ! in_array( $item['type'] . '|' . strtolower( $item['id'] ), $remove, true );
                }
            );
        }

        // Add a new entry when provided.
        $new_id = isset( $_POST['premium_updates_new_id'] ) ? sanitize_text_field( wp_unslash( $_POST['premium_updates_new_id'] ) ) : '';
        if ( '' !== trim( $new_id ) ) {
            $custom[] = array(
                'type' => ( isset( $_POST['premium_updates_new_type'] ) && 'theme' === $_POST['premium_updates_new_type'] ) ? 'theme' : 'plugin',
                'id'   => trim( $new_id ),
            );
        }

        MainWP_Premium_Update_Registry::save_custom_entries( array_values( $custom ) );

        delete_transient( static::SCAN_TRANSIENT );

        return true;
    }

    /**
     * Render the Premium Updates settings page.
     */
    public static function render() { // phpcs:ignore -- NOSONAR - complex render method.
        $updated = static::handle_settings_post();

        MainWP_Settings::render_header( 'PremiumUpdates' );

        $enabled = MainWP_Premium_Update_Registry::is_enabled();
        $custom  = MainWP_Premium_Update_Registry::get_custom_entries();
        $scan    = static::get_detected_products();
        ?>
        <div id="mainwp-premium-updates-settings" class="ui segment">
            <?php if ( $updated ) : ?>
                <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved.', 'mainwp' ); ?></div>
            <?php endif; ?>
            <div class="ui form">
                <form method="POST" action="admin.php?page=PremiumUpdates">
                    <?php wp_nonce_field( 'PremiumUpdatesSettings', 'wp_nonce' ); ?>
                    <h3 class="ui dividing header">
                        <?php esc_html_e( 'Premium Updates', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Built-in update compatibility for premium plugins and themes. MainWP performs an extra wp-admin check on child sites that have these products installed, so their updates show up and install like any other.', 'mainwp' ); ?></div>
                    </h3>

                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Enable premium update compatibility', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox">
                            <input type="checkbox" name="mainwp_premium_updates_enabled" id="mainwp_premium_updates_enabled" <?php echo $enabled ? 'checked="true"' : ''; ?> />
                            <label for="mainwp_premium_updates_enabled"><?php esc_html_e( 'One switch for the whole feature: detecting premium updates and installing them. Runs only on sites where a listed product is installed; existing filter snippets keep working alongside it.', 'mainwp' ); ?></label>
                        </div>
                    </div>

                    <div class="ui info message">
                        <div class="header"><?php esc_html_e( 'Premium products usually need an active license on each child site.', 'mainwp' ); ?></div>
                        <p><?php esc_html_e( 'MainWP detects and starts these updates, but vendors only deliver the update download to sites where the product license, account connection, or registration is active. If a premium update appears but fails to install, check the product licensing on that child site first.', 'mainwp' ); ?></p>
                    </div>

                    <h3 class="ui dividing header">
                        <?php esc_html_e( 'Detected on your sites', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Read-only overview of where built-in premium support applies. To stop tracking updates for a product, use the existing Ignore Updates options; to turn this feature off entirely, use the switch above.', 'mainwp' ); ?></div>
                    </h3>

                    <?php static::render_detected_table( 'plugin', esc_html__( 'Plugins', 'mainwp' ), $scan ); ?>
                    <?php static::render_detected_table( 'theme', esc_html__( 'Themes', 'mainwp' ), $scan ); ?>

                    <div class="ui hidden divider"></div>

                    <h3 class="ui dividing header">
                        <?php esc_html_e( 'Custom identifiers', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'For premium products not on the built-in list, or for theme folders that were renamed. Custom entries get the same treatment as built-in ones.', 'mainwp' ); ?></div>
                    </h3>

                    <table class="ui unstackable table" id="mainwp-premium-updates-custom-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Identifier', 'mainwp' ); ?></th>
                                <th scope="col" class="collapsing"><?php esc_html_e( 'Remove', 'mainwp' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $custom ) ) : ?>
                                <tr>
                                    <td colspan="3"><?php esc_html_e( 'No custom identifiers added yet.', 'mainwp' ); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $custom as $item ) : ?>
                                    <tr>
                                        <td><?php echo 'theme' === $item['type'] ? esc_html__( 'Theme', 'mainwp' ) : esc_html__( 'Plugin', 'mainwp' ); ?></td>
                                        <td><code><?php echo esc_html( $item['id'] ); ?></code></td>
                                        <td class="center aligned">
                                            <div class="ui checkbox">
                                                <input type="checkbox" name="premium_updates_remove[]" id="premium-updates-remove-<?php echo esc_attr( md5( $item['type'] . $item['id'] ) ); ?>" value="<?php echo esc_attr( $item['type'] . '|' . strtolower( $item['id'] ) ); ?>" />
                                                <label for="premium-updates-remove-<?php echo esc_attr( md5( $item['type'] . $item['id'] ) ); ?>"></label>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="two fields">
                        <div class="field">
                            <label for="premium_updates_new_type"><?php esc_html_e( 'Type', 'mainwp' ); ?></label>
                            <select name="premium_updates_new_type" id="premium_updates_new_type" class="ui dropdown">
                                <option value="plugin"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></option>
                                <option value="theme"><?php esc_html_e( 'Theme', 'mainwp' ); ?></option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="premium_updates_new_id"><?php esc_html_e( 'Identifier', 'mainwp' ); ?></label>
                            <input type="text" name="premium_updates_new_id" id="premium_updates_new_id" placeholder="<?php esc_attr_e( 'plugin-folder/main-file.php or theme folder name (case matters)', 'mainwp' ); ?>" value="" />
                        </div>
                    </div>

                    <div class="ui divider"></div>
                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                </form>
            </div>
        </div>
        <?php
        MainWP_Settings::render_footer();
    }

    /**
     * Render one read-only detected-products table.
     *
     * @param string $type  'plugin' | 'theme'.
     * @param string $label Escaped section label.
     * @param array  $scan  Scan results from get_detected_products().
     */
    private static function render_detected_table( $type, $label, $scan ) {
        $rows = array();
        foreach ( $scan as $row ) {
            if ( $row['type'] === $type && $row['sites'] > 0 ) {
                $rows[] = $row;
            }
        }
        usort(
            $rows,
            function ( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
        );
        ?>
        <h4 class="ui header"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by caller. ?></h4>
        <table class="ui unstackable table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Identifier', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Installed on', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr>
                        <td colspan="3"><?php 'theme' === $type ? esc_html_e( 'No supported premium themes detected on your sites yet. Products appear here automatically once detected during a sync.', 'mainwp' ) : esc_html_e( 'No supported premium plugins detected on your sites yet. Products appear here automatically once detected during a sync.', 'mainwp' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $row['name'] ); ?></strong>
                                <?php if ( ! empty( $row['products'] ) && $row['products'] > 1 ) : ?>
                                    <div><em><?php printf( esc_html__( 'One rule covers every matching product (%d found across your sites).', 'mainwp' ), (int) $row['products'] ); ?></em></div>
                                <?php endif; ?>
                                <?php if ( ! empty( $row['inactive'] ) ) : ?>
                                    <div><em>
                                    <?php
                                    if ( 'theme' === $type ) {
                                        printf( esc_html__( 'Inactive on %d sites; theme updates cannot be detected while the theme is inactive.', 'mainwp' ), (int) $row['inactive'] );
                                    } else {
                                        printf( esc_html__( 'Inactive on %d sites; premium updates cannot be detected while the plugin is inactive.', 'mainwp' ), (int) $row['inactive'] );
                                    }
                                    ?>
                                    </em></div>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html( $row['identifier'] ); ?></code></td>
                            <td>
                                <?php printf( esc_html( _n( '%d site', '%d sites', $row['sites'], 'mainwp' ) ), (int) $row['sites'] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Scan synced site inventories for registry-matched products.
     *
     * Aggregates per registry entry (and per custom identifier): site count,
     * inactive count, display name, and for prefix rules the number of distinct
     * matching products. Results are cached briefly; the cache is cleared on save.
     *
     * @return array[] Rows: name, identifier, type, sites, inactive, products.
     */
    public static function get_detected_products() { // phpcs:ignore -- NOSONAR - complex.
        $cached = get_transient( static::SCAN_TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $trackers = array();

        foreach ( MainWP_Premium_Update_Registry::get_entries() as $entry ) {
            if ( empty( $entry['detect'] ) ) {
                continue;
            }
            $trackers[] = array(
                'name'       => $entry['name'],
                'identifier' => 'prefix' === $entry['match'] ? $entry['id'] . '*' : $entry['id'],
                'match'      => $entry['match'],
                'id'         => $entry['id'],
                'type'       => $entry['type'],
                'sites'      => 0,
                'inactive'   => 0,
                'slugs'      => array(),
            );
        }
        foreach ( MainWP_Premium_Update_Registry::get_custom_entries() as $item ) {
            $trackers[] = array(
                'name'       => $item['id'],
                'identifier' => $item['id'],
                'match'      => 'exact',
                'id'         => $item['id'],
                'type'       => $item['type'],
                'sites'      => 0,
                'inactive'   => 0,
                'slugs'      => array(),
            );
        }

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- iterating DB result.
            $inventories = array(
                'plugin' => ! empty( $website->plugins ) ? json_decode( $website->plugins, true ) : array(),
                'theme'  => ! empty( $website->themes ) ? json_decode( $website->themes, true ) : array(),
            );
            foreach ( $trackers as $index => $tracker ) {
                $items = $inventories[ $tracker['type'] ];
                if ( ! is_array( $items ) || empty( $items ) ) {
                    continue;
                }
                $site_matched  = false;
                $site_inactive = false;
                foreach ( $items as $info ) {
                    if ( ! isset( $info['slug'] ) || ! is_string( $info['slug'] ) ) {
                        continue;
                    }
                    $matched = 'prefix' === $tracker['match']
                        ? 0 === strncasecmp( $info['slug'], $tracker['id'], strlen( $tracker['id'] ) )
                        : 0 === strcasecmp( $info['slug'], $tracker['id'] );
                    if ( ! $matched ) {
                        continue;
                    }
                    $site_matched = true;

                    $trackers[ $index ]['slugs'][ strtolower( $info['slug'] ) ] = true;

                    if ( 'theme' === $tracker['type'] ) {
                        if ( empty( $info['active'] ) && empty( $info['parent_active'] ) ) {
                            $site_inactive = true;
                        }
                    } elseif ( isset( $info['active'] ) && empty( $info['active'] ) ) {
                        $site_inactive = true;
                    }

                    if ( empty( $trackers[ $index ]['reported_name'] ) && ! empty( $info['name'] ) && 'exact' === $tracker['match'] ) {
                        $trackers[ $index ]['reported_name'] = $info['name'];
                    }
                }
                if ( $site_matched ) {
                    ++$trackers[ $index ]['sites'];
                    if ( $site_inactive ) {
                        ++$trackers[ $index ]['inactive'];
                    }
                }
            }
        }
        MainWP_DB::free_result( $websites );

        $rows = array();
        foreach ( $trackers as $tracker ) {
            $rows[] = array(
                'name'       => ! empty( $tracker['reported_name'] ) ? $tracker['reported_name'] : $tracker['name'],
                'identifier' => $tracker['identifier'],
                'type'       => $tracker['type'],
                'sites'      => $tracker['sites'],
                'inactive'   => $tracker['inactive'],
                'products'   => count( $tracker['slugs'] ),
            );
        }

        set_transient( static::SCAN_TRANSIENT, $rows, 10 * MINUTE_IN_SECONDS );

        return $rows;
    }
}
