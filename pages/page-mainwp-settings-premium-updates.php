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
     * @return string
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Handle settings and custom-identifier form submissions.
     *
     * @return array|false Notice data on submission, false otherwise.
     */
    public static function handle_settings_post() { // phpcs:ignore -- NOSONAR - complex.
        $action = isset( $_POST['premium_updates_action'] ) ? sanitize_key( wp_unslash( $_POST['premium_updates_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( '' === $action && isset( $_POST['submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- backwards-compatible settings submission.
            $action = 'save_settings';
        }

        if ( '' === $action || ! MainWP_System_Utility::is_admin() ) {
            return false;
        }

        if ( 'save_settings' === $action ) {
            if ( ! isset( $_POST['wp_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wp_nonce'] ) ), 'PremiumUpdatesSettings' ) ) {
                return static::get_post_notice( 'error', __( 'The security check failed. Please reload the page and try again.', 'mainwp' ) );
            }

            MainWP_Utility::update_option( MainWP_Premium_Update_Registry::OPTION_ENABLED, isset( $_POST['mainwp_premium_updates_enabled'] ) ? 1 : 0 );
            delete_transient( static::SCAN_TRANSIENT . '_' . get_current_user_id() );

            return static::get_post_notice( 'success', __( 'Settings have been saved.', 'mainwp' ) );
        }

        if ( 'add_custom' === $action ) {
            return static::handle_add_custom_entry();
        }

        if ( 'remove_custom' === $action ) {
            return static::handle_remove_custom_entry();
        }

        return false;
    }

    /**
     * Add one custom premium-update identifier.
     *
     * @return array Submission notice data.
     */
    private static function handle_add_custom_entry() {
        if ( ! isset( $_POST['premium_updates_add_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['premium_updates_add_nonce'] ) ), 'PremiumUpdatesCustomAdd' ) ) {
            return static::get_post_notice( 'error', __( 'The security check failed. Please reload the page and try again.', 'mainwp' ), 'add_custom' );
        }

        $type = isset( $_POST['premium_updates_new_type'] ) && 'theme' === sanitize_key( wp_unslash( $_POST['premium_updates_new_type'] ) ) ? 'theme' : 'plugin';
        $id   = isset( $_POST['premium_updates_new_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['premium_updates_new_id'] ) ) ) : '';

        if ( '' === $id ) {
            return static::get_post_notice( 'error', __( 'Enter a plugin or theme identifier.', 'mainwp' ), 'add_custom' );
        }

        if ( ! preg_match( '/^[A-Za-z0-9\-_.\/]+$/', $id ) ) {
            return static::get_post_notice( 'error', __( 'The identifier is invalid. Use a plugin basename or theme folder containing only letters, numbers, hyphens, underscores, periods, and forward slashes.', 'mainwp' ), 'add_custom' );
        }

        $custom = MainWP_Premium_Update_Registry::get_custom_entries();
        foreach ( $custom as $item ) {
            if ( $item['type'] === $type && 0 === strcasecmp( $item['id'], $id ) ) {
                return static::get_post_notice( 'error', __( 'That custom identifier already exists.', 'mainwp' ), 'add_custom' );
            }
        }

        $custom[] = array(
            'type' => $type,
            'id'   => $id,
        );
        MainWP_Premium_Update_Registry::save_custom_entries( $custom );
        delete_transient( static::SCAN_TRANSIENT . '_' . get_current_user_id() );

        return static::get_post_notice( 'success', __( 'Custom identifier added.', 'mainwp' ), 'add_custom' );
    }

    /**
     * Remove one custom premium-update identifier.
     *
     * @return array Submission notice data.
     */
    private static function handle_remove_custom_entry() {
        if ( ! isset( $_POST['premium_updates_remove_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['premium_updates_remove_nonce'] ) ), 'PremiumUpdatesCustomRemove' ) ) {
            return static::get_post_notice( 'error', __( 'The security check failed. Please reload the page and try again.', 'mainwp' ), 'remove_custom' );
        }

        $type   = isset( $_POST['premium_updates_remove_type'] ) && 'theme' === sanitize_key( wp_unslash( $_POST['premium_updates_remove_type'] ) ) ? 'theme' : 'plugin';
        $id     = isset( $_POST['premium_updates_remove_id'] ) ? sanitize_text_field( wp_unslash( $_POST['premium_updates_remove_id'] ) ) : '';
        $custom = MainWP_Premium_Update_Registry::get_custom_entries();
        $found  = false;

        $custom = array_filter(
            $custom,
            function ( $item ) use ( $type, $id, &$found ) {
                if ( $item['type'] === $type && 0 === strcasecmp( $item['id'], $id ) ) {
                    $found = true;
                    return false;
                }
                return true;
            }
        );

        if ( ! $found ) {
            return static::get_post_notice( 'error', __( 'The custom identifier could not be found.', 'mainwp' ), 'remove_custom' );
        }

        MainWP_Premium_Update_Registry::save_custom_entries( array_values( $custom ) );
        delete_transient( static::SCAN_TRANSIENT . '_' . get_current_user_id() );

        return static::get_post_notice( 'success', __( 'Custom identifier removed.', 'mainwp' ), 'remove_custom' );
    }

    /**
     * Build submission notice data.
     *
     * @param string $type    Notice type: success|error.
     * @param string $message Notice message.
     * @param string $action  Optional related action.
     *
     * @return array Notice data.
     */
    private static function get_post_notice( $type, $message, $action = '' ) {
        return array(
            'type'    => 'error' === $type ? 'error' : 'success',
            'message' => $message,
            'action'  => $action,
        );
    }

    /**
     * Render the Premium Updates settings page.
     */
    public static function render() { // phpcs:ignore -- NOSONAR - complex render method.
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard settings', 'mainwp' ) );
            return;
        }

        $notice = static::handle_settings_post();

        MainWP_Settings::render_header( 'PremiumUpdates' );

        $enabled        = MainWP_Premium_Update_Registry::is_enabled();
        $custom         = MainWP_Premium_Update_Registry::get_custom_entries();
        $scan           = static::get_detected_products();
        $open_add_modal = is_array( $notice ) && 'error' === $notice['type'] && 'add_custom' === $notice['action'];
        $notice_class   = is_array( $notice ) && 'error' === $notice['type'] ? 'red' : 'green';
        $new_type       = $open_add_modal && isset( $_POST['premium_updates_new_type'] ) && 'theme' === sanitize_key( wp_unslash( $_POST['premium_updates_new_type'] ) ) ? 'theme' : 'plugin'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- repopulating sanitized modal input.
        $new_id         = $open_add_modal && isset( $_POST['premium_updates_new_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['premium_updates_new_id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- repopulating sanitized modal input.
        ?>
        <div id="mainwp-premium-updates-settings" class="ui segment">
            <?php if ( is_array( $notice ) && ! $open_add_modal ) : ?>
                <div class="ui <?php echo esc_attr( $notice_class ); ?> message"><i class="close icon"></i><?php echo esc_html( $notice['message'] ); ?></div>
            <?php endif; ?>
            <div class="ui form">
                <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-premium-updates-general-accordion">
                    <h2 class="ui dividing header active title">
                        <i class="right dropdown icon"></i>
                        <?php esc_html_e( 'Premium Updates', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Built-in update compatibility for premium plugins and themes. MainWP performs an extra wp-admin check on child sites that have these products installed, so their updates show up and install like any other.', 'mainwp' ); ?></div>
                    </h2>
                    <div class="content active">
                        <form method="POST" action="<?php echo esc_url( admin_url( 'admin.php?page=PremiumUpdates' ) ); ?>">
                            <?php wp_nonce_field( 'PremiumUpdatesSettings', 'wp_nonce' ); ?>
                            <input type="hidden" name="premium_updates_action" value="save_settings" />
                            <div class="ui grid field">
                                <label class="six wide column middle aligned" for="mainwp_premium_updates_enabled"><?php esc_html_e( 'Enable premium update compatibility', 'mainwp' ); ?></label>
                                <div class="ten wide column ui toggle checkbox">
                                    <input type="checkbox" name="mainwp_premium_updates_enabled" id="mainwp_premium_updates_enabled" <?php echo $enabled ? 'checked="true"' : ''; ?> />
                                    <label for="mainwp_premium_updates_enabled"></label>
                                </div>
                            </div>

                            <div class="ui info message">
                                <div class="header"><?php esc_html_e( 'Premium products usually need an active license on each child site.', 'mainwp' ); ?></div>
                                <p><?php esc_html_e( 'MainWP detects and starts these updates, but vendors only deliver the update download to sites where the product license, account connection, or registration is active. If a premium update appears but fails to install, check the product licensing on that child site first.', 'mainwp' ); ?></p>
                            </div>

                            <div class="ui divider"></div>
                            <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                        </form>
                    </div>
                </div>

                <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-premium-updates-plugins-accordion">
                    <h2 class="ui dividing header title">
                        <i class="right dropdown icon"></i>
                        <?php esc_html_e( 'Detected Plugins', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Read-only overview of supported premium plugins found across your child sites. Use Ignore Updates to stop tracking individual updates.', 'mainwp' ); ?></div>
                    </h2>
                    <div class="content">
                        <?php static::render_detected_table( 'plugin', $scan ); ?>
                    </div>
                </div>

                <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-premium-updates-themes-accordion">
                    <h2 class="ui dividing header title">
                        <i class="right dropdown icon"></i>
                        <?php esc_html_e( 'Detected Themes', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Read-only overview of supported premium themes found across your child sites. Use Ignore Updates to stop tracking individual updates.', 'mainwp' ); ?></div>
                    </h2>
                    <div class="content">
                        <?php static::render_detected_table( 'theme', $scan ); ?>
                    </div>
                </div>

                <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-premium-updates-custom-accordion">
                    <h2 class="ui dividing header title">
                        <i class="right dropdown icon"></i>
                        <?php esc_html_e( 'Custom Identifiers', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'For premium products not on the built-in list, or for theme folders that were renamed. Added identifiers get the same treatment as built-in ones.', 'mainwp' ); ?></div>
                    </h2>
                    <div class="content">
                        <div class="ui right aligned basic segment">
                            <button type="button" class="ui mini green basic button" id="mainwp-premium-updates-add-custom"><i class="plus icon"></i><?php esc_html_e( 'Add New', 'mainwp' ); ?></button>
                        </div>

                        <table class="ui unstackable table" id="mainwp-premium-updates-custom-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Identifier', 'mainwp' ); ?></th>
                                    <th scope="col" class="collapsing"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mainwp' ); ?></span></th>
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
                                            <td class="right aligned">
                                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin.php?page=PremiumUpdates' ) ); ?>" class="mainwp-premium-updates-remove-form">
                                                    <input type="hidden" name="premium_updates_remove_nonce" value="<?php echo esc_attr( wp_create_nonce( 'PremiumUpdatesCustomRemove' ) ); ?>" />
                                                    <input type="hidden" name="premium_updates_action" value="remove_custom" />
                                                    <input type="hidden" name="premium_updates_remove_type" value="<?php echo esc_attr( $item['type'] ); ?>" />
                                                    <input type="hidden" name="premium_updates_remove_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
                                                    <button type="button" class="ui mini icon basic button mainwp-premium-updates-remove" data-inverted="" data-position="top right" data-tooltip="<?php esc_attr_e( 'Remove custom identifier.', 'mainwp' ); ?>" data-confirm="<?php esc_attr_e( 'Are you sure you want to remove this custom identifier?', 'mainwp' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Custom identifier. */ __( 'Remove custom identifier %s', 'mainwp' ), $item['id'] ) ); ?>"><i class="trash icon"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="ui small modal" id="mainwp-premium-updates-add-custom-modal">
                <i class="close icon"></i>
                <div class="header"><?php esc_html_e( 'Add Custom Identifier', 'mainwp' ); ?></div>
                <form method="POST" action="<?php echo esc_url( admin_url( 'admin.php?page=PremiumUpdates' ) ); ?>" class="content ui mini form" id="mainwp-premium-updates-add-custom-form">
                    <?php wp_nonce_field( 'PremiumUpdatesCustomAdd', 'premium_updates_add_nonce' ); ?>
                    <input type="hidden" name="premium_updates_action" value="add_custom" />
                    <?php if ( $open_add_modal ) : ?>
                        <div class="ui red message"><?php echo esc_html( $notice['message'] ); ?></div>
                    <?php endif; ?>
                    <div class="field">
                        <label for="premium_updates_new_type"><?php esc_html_e( 'Type', 'mainwp' ); ?></label>
                        <select name="premium_updates_new_type" id="premium_updates_new_type" class="ui dropdown">
                            <option value="plugin" <?php selected( $new_type, 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'mainwp' ); ?></option>
                            <option value="theme" <?php selected( $new_type, 'theme' ); ?>><?php esc_html_e( 'Theme', 'mainwp' ); ?></option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="premium_updates_new_id"><?php esc_html_e( 'Identifier', 'mainwp' ); ?> <span class="ui small red text"><?php esc_html_e( '(Required)', 'mainwp' ); ?></span></label>
                        <input type="text" name="premium_updates_new_id" id="premium_updates_new_id" placeholder="<?php esc_attr_e( 'plugin-folder/main-file.php or theme folder name (case-insensitive)', 'mainwp' ); ?>" value="<?php echo esc_attr( $new_id ); ?>" pattern="[A-Za-z0-9._/-]+" required />
                    </div>
                </form>
                <div class="actions">
                    <button type="button" class="ui button" id="mainwp-premium-updates-add-custom-cancel"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
                    <button type="submit" form="mainwp-premium-updates-add-custom-form" class="ui green button"><?php esc_html_e( 'Create', 'mainwp' ); ?></button>
                </div>
            </div>

            <script type="text/javascript">
                jQuery( function( $ ) {
                    const addModal = $( '#mainwp-premium-updates-add-custom-modal' );
                    const addForm = document.getElementById( 'mainwp-premium-updates-add-custom-form' );

                    if ( ! addModal.length || ! addForm ) {
                        return;
                    }

                    $( '#mainwp-premium-updates-add-custom' ).on( 'click', function() {
                        addForm.reset();
                        addModal.find( '.ui.dropdown' ).dropdown( 'set selected', 'plugin' );
                        addModal.find( '.ui.red.message' ).remove();
                        addModal.modal( { closable: false } ).modal( 'show' );
                    } );

                    $( '#mainwp-premium-updates-add-custom-cancel' ).on( 'click', function() {
                        addModal.modal( 'hide' );
                    } );

                    $( '.mainwp-premium-updates-remove' ).on( 'click', function() {
                        const form = this.form;
                        mainwp_confirm( $( this ).attr( 'data-confirm' ), function() {
                            form.submit();
                        } );
                        return false;
                    } );

                    <?php if ( $open_add_modal ) : ?>
                        addModal.modal( { closable: false } ).modal( 'show' );
                    <?php endif; ?>
                } );
            </script>
        </div>
        <?php
        MainWP_Settings::render_footer();
    }

    /**
     * Render one read-only detected-products table.
     *
     * @param string $type 'plugin' | 'theme'.
     * @param array  $scan Scan results from get_detected_products().
     */
    private static function render_detected_table( $type, $scan ) {
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
        $cache_key = static::SCAN_TRANSIENT . '_' . get_current_user_id();
        $cached    = get_transient( $cache_key );
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
                $site_matched          = false;
                $site_has_active_match = false;
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
                        if ( ! empty( $info['active'] ) || ! empty( $info['parent_active'] ) ) {
                            $site_has_active_match = true;
                        }
                    } elseif ( ! isset( $info['active'] ) || ! empty( $info['active'] ) ) {
                        $site_has_active_match = true;
                    }

                    if ( empty( $trackers[ $index ]['reported_name'] ) && ! empty( $info['name'] ) && 'exact' === $tracker['match'] ) {
                        $trackers[ $index ]['reported_name'] = $info['name'];
                    }
                }
                if ( $site_matched ) {
                    ++$trackers[ $index ]['sites'];
                    if ( ! $site_has_active_match ) {
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

        set_transient( $cache_key, $rows, 10 * MINUTE_IN_SECONDS );

        return $rows;
    }
}
