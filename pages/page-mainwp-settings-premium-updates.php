<?php
/**
 * MainWP Premium Updates Settings page.
 *
 * Settings subpage for the built-in premium plugin & theme update
 * compatibility (MWP-1660): license notice, custom identifiers, and a
 * read-only overview of detected products.
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
     * Handle custom-identifier form submissions.
     *
     * @return array|false Notice data on submission, false otherwise.
     */
    public static function handle_settings_post() { // phpcs:ignore -- NOSONAR - complex.
        $action = isset( $_POST['premium_updates_action'] ) ? sanitize_key( wp_unslash( $_POST['premium_updates_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( '' === $action || ! MainWP_System_Utility::is_admin() ) {
            return false;
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

        $custom             = MainWP_Premium_Update_Registry::get_custom_entries();
        $scan               = static::get_detected_products();
        $suggestions        = static::get_identifier_suggestions();
        $suggestion_sources = array(
            'plugin' => array(),
            'theme'  => array(),
        );
        foreach ( $suggestions as $suggestion ) {
            $suggestion_sources[ $suggestion['type'] ][] = array(
                'title'       => $suggestion['name'],
                'identifier'  => $suggestion['identifier'],
                'description' => sprintf(
                    /* translators: 1: Plugin or theme identifier, 2: Number of child sites. */
                    _n( '%1$s - installed on %2$d site', '%1$s - installed on %2$d sites', $suggestion['sites'], 'mainwp' ),
                    $suggestion['identifier'],
                    $suggestion['sites']
                ),
            );
        }
        $add_error    = is_array( $notice ) && 'error' === $notice['type'] && 'add_custom' === $notice['action'];
        $notice_class = is_array( $notice ) && 'error' === $notice['type'] ? 'red' : 'green';
        $new_type     = $add_error && isset( $_POST['premium_updates_new_type'] ) && 'theme' === sanitize_key( wp_unslash( $_POST['premium_updates_new_type'] ) ) ? 'theme' : 'plugin'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- repopulating sanitized form input.
        $new_id       = $add_error && isset( $_POST['premium_updates_new_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['premium_updates_new_id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- repopulating sanitized form input.
        ?>
        <div id="mainwp-premium-updates-settings" class="ui padded segment">
            <?php if ( is_array( $notice ) && ! $add_error ) : ?>
                <div class="ui <?php echo esc_attr( $notice_class ); ?> message"><i class="close icon"></i><?php echo esc_html( $notice['message'] ); ?></div>
            <?php endif; ?>
            <div class="ui info message">
                <div class="header"><?php esc_html_e( 'Premium products usually need an active license on each child site.', 'mainwp' ); ?></div>
                <p><?php esc_html_e( 'MainWP detects and starts these updates, but vendors only deliver the update download to sites where the product license, account connection, or registration is active. If a premium update appears but fails to install, check the product licensing on that child site first.', 'mainwp' ); ?></p>
            </div>

            <div class="ui hidden divider"></div>

            <div class="ui form">

                <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-premium-updates-custom-accordion">
                    <h2 class="ui dividing header active title">
                        <i class="right dropdown icon"></i>
                        <?php esc_html_e( 'Custom Identifiers', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'For premium products not on the built-in list, or for theme folders that were renamed. Added identifiers get the same treatment as built-in ones.', 'mainwp' ); ?></div>
                    </h2>
                    <div class="content active">
                        <?php if ( $add_error ) : ?>
                            <div class="ui red message"><?php echo esc_html( $notice['message'] ); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="<?php echo esc_url( admin_url( 'admin.php?page=PremiumUpdates' ) ); ?>">
                            <?php wp_nonce_field( 'PremiumUpdatesCustomAdd', 'premium_updates_add_nonce' ); ?>
                            <input type="hidden" name="premium_updates_action" value="add_custom" />
                            <div class="fields" id="mainwp-premium-updates-identifier-fields">
                                <div class="six wide field">
                                    <label for="premium_updates_new_type"><?php esc_html_e( 'Type', 'mainwp' ); ?></label>
                                    <select name="premium_updates_new_type" id="premium_updates_new_type" class="ui dropdown">
                                        <option value="plugin" <?php selected( $new_type, 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'mainwp' ); ?></option>
                                        <option value="theme" <?php selected( $new_type, 'theme' ); ?>><?php esc_html_e( 'Theme', 'mainwp' ); ?></option>
                                    </select>
                                </div>
                                <div class="ten wide field">
                                    <label for="premium_updates_new_id"><?php esc_html_e( 'Identifier', 'mainwp' ); ?> <span class="ui small red text"><?php esc_html_e( '(Required)', 'mainwp' ); ?></span></label>
                                    <div class="fields">
                                        <div class="thirteen wide field">
                                            <div class="ui fluid search" id="mainwp-premium-updates-identifier-search">
                                                <input type="text" name="premium_updates_new_id" id="premium_updates_new_id" placeholder="<?php echo esc_attr( 'theme' === $new_type ? __( 'Search installed themes or enter the theme folder', 'mainwp' ) : __( 'Search installed plugins or enter plugin-folder/main-file.php', 'mainwp' ) ); ?>" data-plugin-placeholder="<?php esc_attr_e( 'Search installed plugins or enter plugin-folder/main-file.php', 'mainwp' ); ?>" data-theme-placeholder="<?php esc_attr_e( 'Search installed themes or enter the theme folder', 'mainwp' ); ?>" value="<?php echo esc_attr( $new_id ); ?>" pattern="[A-Za-z0-9._/-]+" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mainwp-premium-updates-identifier-results" aria-describedby="mainwp-premium-updates-identifier-help" autocomplete="off" required />
                                                <div class="results" id="mainwp-premium-updates-identifier-results" role="listbox" aria-label="<?php esc_attr_e( 'Identifier suggestions', 'mainwp' ); ?>"></div>
                                            </div>
                                        </div>
                                        <div class="three wide field">
                                            <button type="submit" class="ui fluid basic green button" id="mainwp-premium-updates-add-custom"><i class="plus icon"></i><?php esc_html_e( 'Add New', 'mainwp' ); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="ui small text" id="mainwp-premium-updates-identifier-help">
                                <div><?php esc_html_e( 'Sync your child sites first so MainWP Dashboard has the latest installed plugin and theme data.', 'mainwp' ); ?></div>
                                <div><?php esc_html_e( 'Start typing to search all detected products. Results also include non-premium plugins and themes, so ignore those and select only the premium product you want to add.', 'mainwp' ); ?></div>
                                <div><?php esc_html_e( 'You can also enter an identifier manually. Plugin identifiers look like plugin-folder/main-file.php; theme identifiers are the installed theme folder.', 'mainwp' ); ?></div>
                            </div>
                        </form>

                        <div class="ui hidden divider"></div>

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

                <div class="ui hidden divider"></div>

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

                <div class="ui hidden divider"></div>

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
            </div>

            <script type="text/javascript">
                jQuery( function( $ ) {
                    const identifierSources = <?php echo wp_json_encode( $suggestion_sources, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safely encoded JSON for an inline script. ?>;
                    const identifierSearch = $( '#mainwp-premium-updates-identifier-search' );
                    const identifierInput = $( '#premium_updates_new_id' );
                    const typeInput = $( '#premium_updates_new_type' );
                    const identifierResults = $( '#mainwp-premium-updates-identifier-results' );

                    if ( identifierSearch.length && identifierInput.length && typeInput.length && identifierResults.length && 'function' === typeof $.fn.search ) {
                        const getIdentifierSource = function() {
                            const type = 'theme' === typeInput.val() ? 'theme' : 'plugin';
                            return identifierSources[ type ] || [];
                        };
                        const syncActiveIdentifierResult = function() {
                            const activeResult = identifierResults.find( '.result.active' ).first();
                            identifierResults.find( '.result' ).attr( 'aria-selected', 'false' );
                            if ( activeResult.length ) {
                                activeResult.attr( 'aria-selected', 'true' );
                                identifierInput.attr( 'aria-activedescendant', activeResult.attr( 'id' ) );
                            } else {
                                identifierInput.removeAttr( 'aria-activedescendant' );
                            }
                        };
                        const prepareIdentifierResults = function() {
                            identifierResults.find( '.result' ).each( function( index ) {
                                $( this ).attr( {
                                    id: 'mainwp-premium-updates-identifier-option-' + index,
                                    role: 'option',
                                    'aria-selected': 'false'
                                } );
                            } );
                            syncActiveIdentifierResult();
                        };
                        let identifierSearchTimer = null;

                        identifierSearch.search( {
                            source: getIdentifierSource(),
                            selector: {
                                prompt: '#premium_updates_new_id'
                            },
                            searchFields: [ 'title', 'identifier' ],
                            fullTextSearch: 'exact',
                            minCharacters: 2,
                            maxResults: 12,
                            cache: false,
                            automatic: false,
                            duration: 0,
                            searchOnFocus: false,
                            showNoResults: false,
                            preserveHTML: false,
                            onSelect: function( result ) {
                                window.clearTimeout( identifierSearchTimer );
                                identifierInput.attr( 'aria-expanded', 'false' ).removeAttr( 'aria-activedescendant' );
                                if ( ! result || ! result.identifier ) {
                                    return false;
                                }
                                identifierInput.val( result.identifier ).trigger( 'change' );
                                identifierSearch.search( 'hide results' );
                                return false;
                            },
                            onResultsAdd: function( html ) {
                                identifierInput.removeAttr( 'aria-activedescendant' );
                                if ( html ) {
                                    window.setTimeout( prepareIdentifierResults, 0 );
                                }
                            },
                            onResultsOpen: function() {
                                identifierInput.attr( 'aria-expanded', 'true' );
                            },
                            onResultsClose: function() {
                                identifierInput.attr( 'aria-expanded', 'false' ).removeAttr( 'aria-activedescendant' );
                            }
                        } );

                        // Bind directly instead of relying on delegated form input events.
                        identifierInput.on( 'input.mainwpPremiumUpdatesIdentifierSearch', function() {
                            window.clearTimeout( identifierSearchTimer );
                            identifierInput.removeAttr( 'aria-activedescendant' );
                            if ( identifierInput.val().length < 2 ) {
                                identifierSearch.search( 'hide results' );
                                return;
                            }
                            identifierSearchTimer = window.setTimeout( function() {
                                if ( document.activeElement !== identifierInput[0] ) {
                                    return;
                                }
                                identifierSearch.search( 'query' );
                            }, 100 );
                        } );
                        identifierInput.on( 'blur.mainwpPremiumUpdatesIdentifierSearch', function() {
                            window.clearTimeout( identifierSearchTimer );
                        } );
                        identifierInput.on( 'keydown.mainwpPremiumUpdatesIdentifierSearch', function( event ) {
                            if ( 38 === event.which || 40 === event.which ) {
                                window.setTimeout( syncActiveIdentifierResult, 0 );
                            }
                        } );

                        typeInput.on( 'change', function() {
                            const type = 'theme' === typeInput.val() ? 'theme' : 'plugin';
                            window.clearTimeout( identifierSearchTimer );
                            identifierInput
                                .val( '' )
                                .attr( 'placeholder', identifierInput.attr( 'data-' + type + '-placeholder' ) )
                                .trigger( 'change' );
                            identifierSearch.search( 'setting', 'source', getIdentifierSource() );
                            identifierSearch.search( 'clear cache' );
                            identifierSearch.search( 'hide results' );
                        } );
                    }

                    $( '.mainwp-premium-updates-remove' ).on( 'click', function() {
                        const form = this.form;
                        if ( ! form ) {
                            return false;
                        }
                        mainwp_confirm( $( this ).attr( 'data-confirm' ), function() {
                            form.submit();
                        } );
                        return false;
                    } );
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
     * Build selectable custom-identifier suggestions from synced inventories.
     *
     * Built-in and already-custom identifiers are excluded. Passing inventories
     * is intended for focused tests; normal page rendering reads accessible child
     * sites from the Dashboard database.
     *
     * @param array|null $site_inventories Optional site inventories. Each item contains id, plugin, and theme keys.
     *
     * @return array[] Rows: name, identifier, type, sites.
     */
    public static function get_identifier_suggestions( $site_inventories = null ) { // phpcs:ignore -- NOSONAR - inventory normalization and aggregation.
        $catalog = array();
        $rules   = self::get_identifier_exclusion_rules();
        if ( null === $site_inventories ) {
            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
            while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- iterating DB result.
                $inventories = array(
                    'plugin' => ! empty( $website->plugins ) ? json_decode( $website->plugins, true ) : array(),
                    'theme'  => ! empty( $website->themes ) ? json_decode( $website->themes, true ) : array(),
                );
                self::collect_identifier_suggestions( $catalog, $inventories, (string) $website->id, $rules );
            }
            MainWP_DB::free_result( $websites );
        } else {
            if ( ! is_array( $site_inventories ) ) {
                return array();
            }
            foreach ( $site_inventories as $index => $inventories ) {
                if ( ! is_array( $inventories ) ) {
                    continue;
                }
                $site_id = isset( $inventories['id'] ) ? (string) $inventories['id'] : 'site-' . $index;
                self::collect_identifier_suggestions( $catalog, $inventories, $site_id, $rules );
            }
        }

        $rows = array();
        foreach ( $catalog as $item ) {
            $rows[] = array(
                'name'       => $item['name'],
                'identifier' => $item['identifier'],
                'type'       => $item['type'],
                'sites'      => count( $item['site_ids'] ),
            );
        }
        usort(
            $rows,
            function ( $a, $b ) {
                $name_comparison = strcasecmp( $a['name'], $b['name'] );
                if ( 0 !== $name_comparison ) {
                    return $name_comparison;
                }
                $type_comparison = strcmp( $a['type'], $b['type'] );
                return 0 !== $type_comparison ? $type_comparison : strcasecmp( $a['identifier'], $b['identifier'] );
            }
        );

        return $rows;
    }

    /**
     * Get identifiers which should not be offered as new custom entries.
     *
     * @return array Rules grouped by product type and match style.
     */
    private static function get_identifier_exclusion_rules() {
        $rules = array(
            'plugin' => array(
                'exact'    => array(),
                'prefixes' => array(),
            ),
            'theme'  => array(
                'exact'    => array(),
                'prefixes' => array(),
            ),
        );

        foreach ( MainWP_Premium_Update_Registry::get_entries() as $entry ) {
            if ( empty( $entry['id'] ) || ! isset( $rules[ $entry['type'] ] ) ) {
                continue;
            }
            if ( 'prefix' === $entry['match'] ) {
                $rules[ $entry['type'] ]['prefixes'][] = strtolower( $entry['id'] );
            } else {
                $rules[ $entry['type'] ]['exact'][ strtolower( $entry['id'] ) ] = true;
            }
        }
        foreach ( MainWP_Premium_Update_Registry::get_custom_entries() as $entry ) {
            if ( ! empty( $entry['id'] ) && isset( $rules[ $entry['type'] ] ) ) {
                $rules[ $entry['type'] ]['exact'][ strtolower( $entry['id'] ) ] = true;
            }
        }

        return $rules;
    }

    /**
     * Merge one site's inventory into the identifier suggestion catalog.
     *
     * @param array  $catalog     Suggestion catalog, updated by reference.
     * @param array  $inventories Plugin and theme inventories for one child site.
     * @param string $site_id     Stable site identifier used for deduplication.
     * @param array  $rules       Built-in and custom exclusion rules.
     */
    private static function collect_identifier_suggestions( &$catalog, $inventories, $site_id, $rules ) { // phpcs:ignore -- NOSONAR - inventory normalization.
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            $items = isset( $inventories[ $type ] ) && is_array( $inventories[ $type ] ) ? $inventories[ $type ] : array();
            foreach ( $items as $inventory_key => $info ) {
                if ( ! is_array( $info ) ) {
                    continue;
                }

                $identifier = MainWP_Premium_Update_Registry::get_inventory_identifier( $inventory_key, $info );
                if ( '' === $identifier ) {
                    continue;
                }
                $normalized_identifier = strtolower( $identifier );
                if ( isset( $rules[ $type ]['exact'][ $normalized_identifier ] ) || self::identifier_matches_prefix( $normalized_identifier, $rules[ $type ]['prefixes'] ) ) {
                    continue;
                }

                $name = self::get_inventory_name( $info, $identifier );

                $catalog_key = $type . '|' . $normalized_identifier;
                if ( ! isset( $catalog[ $catalog_key ] ) ) {
                    $catalog[ $catalog_key ] = array(
                        'name'       => $name,
                        'identifier' => $identifier,
                        'type'       => $type,
                        'site_ids'   => array(),
                    );
                } elseif ( 0 === strcasecmp( $catalog[ $catalog_key ]['name'], $catalog[ $catalog_key ]['identifier'] ) && 0 !== strcasecmp( $name, $identifier ) ) {
                    $catalog[ $catalog_key ]['name'] = $name;
                }
                $catalog[ $catalog_key ]['site_ids'][ $site_id ] = true;
            }
        }
    }

    /**
     * Resolve a synced product name, falling back to its identifier.
     *
     * @param array  $info       Installed product data.
     * @param string $identifier Canonical product identifier.
     * @return string Display name.
     */
    private static function get_inventory_name( $info, $identifier ) {
        foreach ( array( 'name', 'Name', 'title' ) as $name_key ) {
            if ( ! isset( $info[ $name_key ] ) || ! is_scalar( $info[ $name_key ] ) ) {
                continue;
            }
            $name = sanitize_text_field( wp_strip_all_tags( (string) $info[ $name_key ] ) );
            if ( '' !== $name ) {
                return $name;
            }
        }
        return $identifier;
    }

    /**
     * Check an already-normalized identifier against normalized prefixes.
     *
     * @param string   $identifier Lowercase identifier.
     * @param string[] $prefixes   Lowercase prefixes.
     *
     * @return bool
     */
    private static function identifier_matches_prefix( $identifier, $prefixes ) {
        foreach ( $prefixes as $prefix ) {
            if ( '' !== $prefix && 0 === strncmp( $identifier, $prefix, strlen( $prefix ) ) ) {
                return true;
            }
        }
        return false;
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
                $items = isset( $inventories[ $tracker['type'] ] ) ? $inventories[ $tracker['type'] ] : array();
                if ( ! is_array( $items ) || empty( $items ) ) {
                    continue;
                }
                $site_matched          = false;
                $site_has_active_match = false;
                foreach ( $items as $inventory_key => $info ) {
                    $identifier = MainWP_Premium_Update_Registry::get_inventory_identifier( $inventory_key, $info );
                    if ( '' === $identifier ) {
                        continue;
                    }
                    $matched = 'prefix' === $tracker['match']
                        ? 0 === strncasecmp( $identifier, $tracker['id'], strlen( $tracker['id'] ) )
                        : 0 === strcasecmp( $identifier, $tracker['id'] );
                    if ( ! $matched ) {
                        continue;
                    }
                    $site_matched = true;

                    $trackers[ $index ]['slugs'][ strtolower( $identifier ) ] = true;

                    if ( 'theme' === $tracker['type'] ) {
                        if ( ! empty( $info['active'] ) || ! empty( $info['parent_active'] ) ) {
                            $site_has_active_match = true;
                        }
                    } elseif ( ! isset( $info['active'] ) || ! empty( $info['active'] ) ) {
                        $site_has_active_match = true;
                    }

                    $reported_name = self::get_inventory_name( $info, '' );
                    if ( empty( $trackers[ $index ]['reported_name'] ) && '' !== $reported_name && 'exact' === $tracker['match'] ) {
                        $trackers[ $index ]['reported_name'] = $reported_name;
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
