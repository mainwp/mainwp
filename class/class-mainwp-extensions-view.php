<?php
/**
 * MainWP Extensions View
 *
 * Renders MainWP Extensions Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Extensions_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Extensions_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    /**
     * Get Class name.
     *
     * @return string __CLASS__.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method init_menu()
     *
     * Add MainWP > Extensions Submenu
     *
     * @return $page
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions::get_class_name()
     */
    public static function init_menu() {
        return add_submenu_page(
            'mainwp_tab',
            __( 'Extensions', 'mainwp' ),
            ' <span id="mainwp-Extensions">' . esc_html__( 'Extensions', 'mainwp' ) . '</span>',
            'read',
            'Extensions',
            array(
                MainWP_Extensions::get_class_name(),
                'render',
            )
        );
    }

    /**
     * Method render_header()
     *
     * Render page header.
     *
     * @param string $shownPage The page slug shown at this moment.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
     */
    public static function render_header( $shownPage = '' ) {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! empty( $page ) && 'Extensions' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $params = array(
                'title' => esc_html__( 'Extensions', 'mainwp' ),
            );
        } else {
            $extension_name_raw = $page;
            $extension_name     = str_replace( array( '-' ), ' ', $extension_name_raw );
            $extension_name     = MainWP_Extensions_Handler::polish_string_name( $extension_name );
            $extension_name     = apply_filters( 'mainwp_extensions_page_top_header', $extension_name, $extension_name_raw );
            $params             = array(
                'title' => $extension_name,
            );
        }

        MainWP_UI::render_top_header( $params );

        $renderItems   = array();
        $renderItems[] = array(
            'title'  => esc_html__( 'Manage Extensions', 'mainwp' ),
            'href'   => 'admin.php?page=Extensions',
            'active' => ( '' === $shownPage ) ? true : false,
        );

        // get extensions to generate manage site page header.
        $extensions = MainWP_Extensions_Handler::get_extensions();
        foreach ( $extensions as $extension ) {
            if ( $extension['plugin'] === $shownPage ) {
                $renderItems[] = array(
                    'title'  => $extension['name'],
                    'href'   => 'admin.php?page=' . $extension['page'],
                    'active' => true,
                );
                break;
            }
        }
        MainWP_UI::render_page_navigation( $renderItems );
        do_action( 'mainwp_extensions_top_header_after_tab', $shownPage );
    }

    /**
     * Method render_footer()
     *
     * Render page footer.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Method render()
     *
     * Render the extensions page.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::added_on_menu()
     * @uses \MainWP\Dashboard\MainWP_Utility::remove_http_prefix()
     */
    public static function render() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $mainwp_api_key = false;
        if ( get_option( 'mainwp_extensions_api_save_login' ) ) {
            $mainwp_api_key = MainWP_Api_Manager_Key::instance()->get_decrypt_master_api_key();
        }

        if ( 1 === (int) get_option( 'mainwp_api_sslVerifyCertificate' ) ) {
            update_option( 'mainwp_api_sslVerifyCertificate', 0 );
        }

        $all_available_extensions = static::get_available_extensions( 'all' );

        $extensions_disabled = MainWP_Extensions_Handler::get_extensions_disabled();

        $extensions       = MainWP_Extensions_Handler::get_extensions();
        $extension_update = get_site_transient( 'update_plugins' );
        $is_demo          = MainWP_Demo_Handle::is_demo_mode();
        ?>
        <div id="mainwp-manage-extensions" class="ui alt segment">
            <div class="mainwp-extensions-api-loading" style="display:none">
                <div class="ui active inverted page dimmer">
                    <div class="ui medium text loader"></div>
                </div>
            </div>
            <div class="mainwp-main-content">
                <?php
                static::render_incompatible_notice();
                if ( $is_demo ) {
                    ?>
                    <div class="ui yellow message">
                        <?php esc_html_e( 'Extensions are disabled in the Demo Mode.', 'mainwp' ); ?>
                        <i class="close icon"></i>
                    </div>
                    <?php
                }
                ?>
                <?php if ( empty( $extensions ) && empty( $extensions_disabled ) ) { ?>
                    <?php static::render_intro_notice(); ?>
                    <?php
                } else {
                    ?>
                    <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-extensions-info-message' ) ) { ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-extensions-info-message"></i>
                            <?php printf( esc_html__( 'Quickly access, install, and activate your MainWP extensions.  If you need additional help with managing your MainWP Extensions, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/category/getting-started/first-steps-with-extensions/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
                        </div>
                        <?php } ?>
                        <div class="ui segment" id="mainwp-extensions-search-no-results" style="display:none">
                            <div class="ui info message"><?php esc_html_e( 'Your search returned no results. The extension may need to be installed or does not exist.' ); ?></div>
                        </div>
                    <div class="ui four stackable cards" id="mainwp-extensions-list">
                    <?php if ( isset( $extensions ) && is_array( $extensions ) ) { ?>
                            <?php foreach ( $extensions as $extension ) { ?>
                                    <?php
                                    if ( ! mainwp_current_user_have_right( 'extension', dirname( $extension['slug'] ) ) ) {
                                        continue;
                                    }

                                    $extensions_data = isset( $all_available_extensions[ dirname( $extension['slug'] ) ] ) ? $all_available_extensions[ dirname( $extension['slug'] ) ] : array();

                                    if ( isset( $extensions_data['img'] ) && ! empty( $extensions_data['img'] ) ) {
                                        $img_url = $extensions_data['img'];
                                    } elseif ( isset( $extension['icon'] ) && ! empty( $extension['icon'] ) ) {
                                        $img_url = $extension['icon'];
                                    } elseif ( isset( $extension['iconURI'] ) && '' !== $extension['iconURI'] ) {
                                        $img_url = MainWP_Utility::remove_http_prefix( $extension['iconURI'] );
                                    } else {
                                        $img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
                                    }

                                    static::render_extension_card( $extension, $extension_update, $img_url );
                                    ?>

                            <?php } ?>
                        <?php } ?>

                                <?php if ( is_array( $extensions_disabled ) ) { ?>
                                    <?php foreach ( $extensions_disabled as $extension ) { ?>
                                        <?php
                                        $slug = dirname( $extension['slug'] );

                                        if ( ! isset( $all_available_extensions[ $slug ] ) ) {
                                            continue;
                                        }

                                        $extensions_data = $all_available_extensions[ $slug ];

                                        if ( isset( $extensions_data['img'] ) && ! empty( $extensions_data['img'] ) ) {
                                            $img_url = $extensions_data['img'];  // icon from the available extensions in dashboard.
                                        } elseif ( isset( $extension['icon'] ) && ! empty( $extension['icon'] ) ) {
                                            $img_url = $extension['icon']; // icon from the get_this_extension().
                                        } elseif ( isset( $extension['iconURI'] ) && '' !== $extension['iconURI'] ) {
                                            $img_url = MainWP_Utility::remove_http_prefix( $extension['iconURI'] );  // icon from the extension header.
                                        } else {
                                            $img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
                                        }

                                        static::render_extension_card( $extension, $extension_update, $img_url, true );
                                        ?>

                            <?php } ?>

                        <?php } ?>
            </div>
                    <?php } ?>
                <?php static::render_purchase_notice(); ?>
            </div>
            <div class="mainwp-side-content mainwp-no-padding">
                    <?php if ( ! empty( $extensions ) || ! empty( $extensions_disabled ) ) { ?>
                        <?php static::render_search_box(); ?>
                <?php } ?>
                    <?php static::render_side_box( $mainwp_api_key ); ?>
            </div>
            <div id="mainwp-extensions-privacy-info">
                <?php $priv_extensions = static::get_available_extensions( 'all' ); ?>
                <?php
                foreach ( $priv_extensions as $priv_extension ) {
                    $item_slug = MainWP_Utility::get_dir_slug( $priv_extension['slug'] );
                    ?>
                    <?php if ( isset( $priv_extension['privacy'] ) && ( 2 === $priv_extension['privacy'] || 1 === (int) $priv_extension['privacy'] ) ) { ?>
                    <input
                        type="hidden"
                        id="<?php esc_attr_e( $priv_extension['slug'] ); ?>"
                        name="<?php esc_attr_e( $priv_extension['slug'] ); ?>"
                        base-slug="<?php esc_attr_e( $item_slug ); ?>"
                        privacy="<?php esc_attr_e( $priv_extension['privacy'] ); ?>"
                        integration="<?php esc_attr_e( $priv_extension['integration'] ); ?>"
                        integration_url="<?php esc_attr_e( $priv_extension['integration_url'] ); ?>"
                        integration_owner="<?php esc_attr_e( $priv_extension['integration_owner'] ); ?>"
                        integration_owner_pp="<?php esc_attr_e( $priv_extension['integration_owner_pp'] ); ?>"
                        extension_title="<?php esc_attr_e( $priv_extension['title'] ); ?>"
                        value="<?php esc_attr_e( $priv_extension['title'] ); ?>"
                    />
                        <?php
                    } elseif ( isset( $priv_extension['privacy'] ) && 0 === (int) $priv_extension['privacy'] ) {
                        ?>
                    <input
                        type="hidden"
                        id="<?php esc_attr_e( $priv_extension['slug'] ); ?>"
                        name="<?php esc_attr_e( $priv_extension['slug'] ); ?>"
                        base-slug="<?php esc_attr_e( $item_slug ); ?>"
                        privacy="<?php esc_attr_e( $priv_extension['privacy'] ); ?>"
                        extension_title="<?php esc_attr_e( $priv_extension['title'] ); ?>"
                        value="<?php esc_attr_e( $priv_extension['title'] ); ?>"
                    />
                                        <?php
                    } else {
                        ?>
                        <input
                            type="hidden"
                            id="<?php esc_attr_e( $priv_extension['slug'] ); ?>"
                            name="<?php esc_attr_e( $priv_extension['slug'] ); ?>"
                            base-slug="<?php esc_attr_e( $item_slug ); ?>"
                            extension_title="<?php esc_attr_e( $priv_extension['title'] ); ?>"
                            value="<?php esc_attr_e( $priv_extension['title'] ); ?>"
                        />
                    <?php } ?>
                <?php } ?>
            </div>
        </div>

        <div class="ui tiny second coupled modal" id="mainwp-privacy-info-modal">
            <i class="close icon"></i>
            <div class="header"></div>
            <div class="content"></div>
        </div>
        <?php
    }

    /**
     * Method render_incompatible_notice()
     *
     * Render Incompatability Notice.
     */
    public static function render_incompatible_notice() {
        $deactivated_exts = get_transient( 'mainwp_transient_deactivated_incomtible_exts' );
        if ( $deactivated_exts && is_array( $deactivated_exts ) && ! empty( $deactivated_exts ) ) {
            ?>
            <?php delete_transient( 'mainwp_transient_deactivated_incomtible_exts' ); ?>
            <div class="ui yellow message">
                <div class="header"><?php esc_html_e( 'Important Note', 'mainwp' ); ?></div>
                <p><?php esc_html_e( 'MainWP Dashboard 4.0 or newer requires Extensions 4.0 or newer. MainWP will automatically deactivate older versions of MainWP Extensions in order to prevent compatibility problems.', 'mainwp' ); ?></p>
                <div class="header"><?php esc_html_e( 'Steps to Update Extensions', 'mainwp' ); ?></div>
                <div class="ui list">
                    <div class="item">1. <?php esc_html_e( 'Go to the WP Admin > Plugins > Installed Plugins page', 'mainwp' ); ?></div>
                    <div class="item">2. <?php esc_html_e( 'Delete Version 3 Extensions (extensions older than version 4) from your MainWP Dashboard', 'mainwp' ); ?></div>
                    <div class="item">3. <?php esc_html_e( 'Go back to the MainWP > Extensions page and use the Install Extensions button', 'mainwp' ); ?></div>
                </div>
                <p><?php esc_html_e( 'This process does not affect your extensions settings.', 'mainwp' ); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Method render_intro_notice()
     *
     * Render Intro Notice.
     */
    public static function render_intro_notice() {
        ?>
        <div class="ui secondary segment">
            <h2 class="header"><?php esc_html_e( 'What are Extensions?', 'mainwp' ); ?></h2>
            <p><?php esc_html_e( 'Extensions are specific features or tools created to expand the basic functionality of MainWP. The core of MainWP is designed to provide the functions most needed for you and minimize code bloat.', 'mainwp' ); ?></p>
            <p><?php esc_html_e( 'Extensions offer custom functions and features so that each user can tailor the MainWP Dashboard to their specific needs.', 'mainwp' ); ?></p>
            <p><?php esc_html_e( 'MainWP Pro offers 40+ Free & Premium Extensions in multiple categories, such as Security, Backup, Performance, Administrative, etc. You can get all of them at a single price.', 'mainwp' ); ?></p>
            <h4><?php esc_html_e( 'MainWP Pro includes:', 'mainwp' ); ?></h4>
            <div class="ui bulleted list">
                <div class="item"><?php esc_html_e( 'All current MainWP Extensions (free & premium)', 'mainwp' ); ?></div>
                <div class="item"><?php esc_html_e( 'All future MainWP Extensions (free & premium)', 'mainwp' ); ?></div>
                <div class="item"><?php esc_html_e( 'Critical Security & Performance updates for MainWP Extensions', 'mainwp' ); ?></div>
                <div class="item"><?php esc_html_e( 'Priority support via Helpdesk & Community for MainWP products', 'mainwp' ); ?></div>
            </div>
            <a class="ui basic green button" href="https://mainwp.com/mainwp-extensions/" target="_blank"><?php esc_html_e( 'Browse All Extensions', 'mainwp' ); ?></a> <a class="ui green button" href="https://mainwp.com/free-vs-pro/" target="_blank"><?php esc_html_e( 'Free Vs. Pro', 'mainwp' ); ?></a> <a class="ui green button" href="https://mainwp.com/signup/" target="_blank"><?php esc_html_e( 'Get Pro', 'mainwp' ); ?></a> <?php // NOSONAR - noopener - open safe. ?>
            <h2 class="header"><?php esc_html_e( 'How to install your MainWP Extensions?', 'mainwp' ); ?></h2>
            <p><?php printf( esc_html__( 'Once you have ordered MainWP Extensions, you can either use the %1$sautomatic extension installation%2$s option or %3$smanual installation%4$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/install-extensions/" target="_blank">', '</a> <i class="external alternate icon"></i>', '<a href="https://kb.mainwp.com/docs/my-downloads-and-api-keys/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></p> <?php // NOSONAR - noopener - open safe. ?>
        </div>
        <?php
    }

    /**
     * Method render_search_box()
     *
     * Render Search Box.
     */
    public static function render_search_box() {
        ?>
        <div class="mainwp-search-options ui fluid accordion mainwp-sidebar-accordion">
            <div class="title">
                <i class="dropdown icon"></i>
            <?php esc_html_e( 'Search Installed Extensions', 'mainwp' ); ?>
            </div>
            <div class="content">
                <div id="mainwp-search-extensions" class="ui fluid search">
                    <div class="ui icon fluid input">
                        <input class="prompt" id="mainwp-search-extensions-input" autocomplete="one-time-code" type="text" placeholder="<?php esc_attr_e( 'Find extension...', 'mainwp' ); ?>">
                        <i class="search icon"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="ui fitted divider"></div>
                <script type="text/javascript">
                jQuery( document ).ready( function () {
                    jQuery( '#mainwp-search-extensions-input' ).on( 'keyup', function () {
                        var searchQuery = jQuery( this ).val().toLowerCase();
                        var extensions = jQuery( '#mainwp-extensions-list' ).find( '.ui.extension.card' );
                        for ( var i = 0; i < extensions.length; i++ ) {
                            var currentExtension = jQuery( extensions[i] );
                            var extensionTitle = jQuery( currentExtension ).attr( 'extension-title' ).toLowerCase();
                            if ( extensionTitle.indexOf( searchQuery ) > -1 ) {
                                currentExtension.show();
                                currentExtension.addClass( 'mainwp-found' );
                            } else {
                                currentExtension.removeClass( 'mainwp-found' );
                                currentExtension.hide();
                            }
                            var foundExtensions = jQuery( '#mainwp-extensions-list' ).find( '.ui.extension.card.mainwp-found' );
                            if ( foundExtensions.length < 1 ) {
                                jQuery( '#mainwp-extensions-search-no-results' ).show();
                            } else {
                                jQuery( '#mainwp-extensions-search-no-results' ).hide();
                        }
                    }
                } );
                } );
                </script>
            <?php
    }

    /**
     * Method render_extension_card()
     *
     * Render the MainWP Extension Cards.
     *
     * @param mixed $extension Extention to render.
     * @param mixed $extension_update Extension update.
     * @param mixed $img_url Extension image.
     * @param mixed $disabled Disabled extension.
     * @param bool  $simple Simple info.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::is_extension_activated()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::polish_ext_name()
     */
    public static function render_extension_card( $extension, $extension_update, $img_url, $disabled = false, $simple = false ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        if ( isset( $extension['href'] ) && ! empty( $extension['href'] ) ) {
            $extension_page_url = $extension['href'];
        } elseif ( isset( $extension['direct_page'] ) && ! empty( $extension['direct_page'] ) ) {
            $extension_page_url = admin_url( 'admin.php?page=' . $extension['direct_page'] );
        } elseif ( isset( $extension['callback'] ) ) {
            $extension_page_url = admin_url( 'admin.php?page=' . $extension['page'] );
        } else {
            $extension_page_url = admin_url( 'admin.php?page=Extensions' );
        }

        $active = MainWP_Extensions_Handler::is_extension_activated( $extension['slug'] );
        if ( empty( $extension['api_key'] ) ) {
            $active = false;
        }

        if ( isset( $extension['apiManager'] ) && $extension['apiManager'] && ! isset( $extension['product_item_id'] ) ) {
            $extension['product_item_id'] = 0;
        }

        $queue_status = '';
        if ( ! $disabled && isset( $extension['apiManager'] ) && $extension['apiManager'] ) {
            $queue_status = 'queue';
        }

        $all_available_extensions = static::get_available_extensions( 'all' );
        $extensions_data          = isset( $all_available_extensions[ dirname( $extension['slug'] ) ] ) ? $all_available_extensions[ dirname( $extension['slug'] ) ] : array();

        $privacy_class = '';
        $license_class = '';

        if ( isset( $extensions_data['privacy'] ) ) {
            if ( empty( $extensions_data['privacy'] ) ) {
                $privacy_class = '<i class="green check icon"></i>';
            } elseif ( 1 === (int) $extensions_data['privacy'] || 2 === (int) $extensions_data['privacy'] ) {
                $privacy_class = '<i class="yellow info icon"></i>';
            }
        }

        if ( $active ) {
            $license_class = '<i class="green cog icon"></i>';
        } else {
            $license_class = '<i class="red cog icon"></i>';
        }

        $item_slug = MainWP_Utility::get_dir_slug( $extension['slug'] );

        $new = '';

        if ( isset( $extensions_data['release_date'] ) && ( time() - $extensions_data['release_date'] < MONTH_IN_SECONDS ) ) {
            $new = '<span class="ui floating green mini label">NEW!</span>';
        }

        ?>
            <div class="ui card extension <?php echo $disabled ? 'grey mainwp-disabled-extension' : 'green mainwp-enabled-extension'; ?> extension-card-<?php echo esc_attr( $extension['name'] ); ?>" extension-title="<?php echo esc_attr( $extension['name'] ); ?>" base-slug="<?php echo esc_attr( $item_slug ); ?>" extension-slug="<?php echo esc_attr( $extension['slug'] ); ?>" status="<?php echo esc_attr( $queue_status ); ?>" license-status="<?php echo $active ? 'activated' : 'deactivated'; ?>">
        <?php
        /**
         * Action: mainwp_extension_card_top
         *
         * Fires at the Extension card top
         *
         * @since 4.1.4.1
         *
         * @param array $extension Array containing the Extension information.
         */
        do_action( 'mainwp_extension_card_top', $extension );
        ?>
                <div class="content">
                    <img class="right floated mini ui image" alt="" src="<?php echo esc_html( $img_url ); ?>">
                    <div class="header">

                        <?php if ( ! $disabled ) { ?>
                        <a href="<?php echo esc_url( $extension_page_url ); ?>"><?php echo esc_html( MainWP_Extensions_Handler::polish_ext_name( $extension, true ) ); ?></a>
                            <?php
                        } else {
                            ?>
                            <?php echo esc_html( MainWP_Extensions_Handler::polish_ext_name( $extension, true ) ); ?>
                        <?php } ?>
                    </div>

                    <div class="meta">
                <?php echo '<i class="code branch icon"></i>' . esc_html( $extension['version'] ); ?> <?php echo isset( $extension['DocumentationURI'] ) && ! empty( $extension['DocumentationURI'] ) ? ' - <a href="' . esc_url( str_replace( array( 'http:', 'https:' ), '', $extension['DocumentationURI'] ) ) . '" target="_blank">' . esc_html__( 'Documentation', 'mainwp' ) . '</a> <i class="external alternate icon"></i>' : ''; ?>
                </div>

                <?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) { ?>
                    <?php if ( ! $active && ! $disabled ) { ?>
                        <span class="ui red ribbon label"><?php esc_html_e( 'License not activated', 'mainwp' ); ?></span>
                    <?php } ?>
                <?php } ?>
                <?php if ( isset( $extension_update->response[ $extension['slug'] ] ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="ui yellow ribbon label"><?php esc_html_e( 'Update available', 'mainwp' ); ?></a>
                <?php } ?>

                <div class="description">
                    <?php echo esc_html( preg_replace( '/\<cite\>.*\<\/cite\>/', '', $extension['description'] ) ); ?>
                </div>
            </div>
            <?php echo $new; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php if ( ! $simple ) { ?>
            <div class="extra content">
                <div class="ui mini fluid stackable buttons">
                    <a class="ui basic button extension-the-plugin-action" plugin-action="<?php echo $disabled ? 'active' : 'disable'; ?>"><?php echo $disabled ? '<i class="toggle on icon"></i> ' . esc_html__( 'Enable', 'mainwp' ) : '<i class="toggle off icon"></i> ' . esc_html__( 'Disable', 'mainwp' ); ?></a>
                    <a class="ui extension-privacy-info-link icon basic button" base-slug="<?php echo esc_attr( $item_slug ); ?>" data-tooltip="<?php echo esc_html__( 'Click to see more about extension privacy.', 'mainwp' ); ?>" data-position="top left" data-inverted=""><?php echo $privacy_class; ?> <?php echo esc_html__( 'Privacy', 'mainwp' ); ?></a> <?php // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php if ( $disabled ) { ?>
                    <a class="ui basic button extension-the-plugin-action" plugin-action="remove"><i class="trash icon"></i> <?php echo esc_html__( 'Delete', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) { ?>
                    <a class="ui activate-api-status mainwp-manage-extension-license icon basic button" data-tooltip="<?php echo $active ? esc_html__( 'Extension API license is activated properly. Click here to Deactivate it if needed.', 'mainwp' ) : esc_html__( 'Extension API license is not activated. Click here to activate it.', 'mainwp' ); ?>" api-actived="<?php echo $active ? '1' : '0'; ?>" data-position="top left" data-inverted=""><?php echo $license_class; ?> <?php echo esc_html__( 'License', 'mainwp' ); ?></a> <?php // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
            <div class="extra content action-feedback" style="display:none;">
                <div class="ui mini message"></div>
            </div>

            <?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) { ?>
                <?php if ( $active ) { ?>
                    <div class="extra content" id="mainwp-extensions-api-form" style="display: none;">
                        <div class="ui form">
                            <div class="field">
                                <div class="ui input fluid">
                                    <input type="text" class="extension-api-key" placeholder="<?php esc_attr_e( 'API license key', 'mainwp' ); ?>" value="<?php echo esc_attr( $extension['api_key'] ); ?>"/>
                                </div>
                            </div>
                            <div class="field">
                                <div class="ui checkbox">
                                    <input type="checkbox" id="extension-deactivate-cb" class="mainwp-extensions-deactivate-chkbox" <?php echo 'on' === $extension['deactivate_checkbox'] ? 'checked' : ''; ?>>
                                    <label for="extension-deactivate-cb"><?php esc_html_e( 'Deactivate License Key', 'mainwp' ); ?></label>
                                </div>
                            </div>
                            <input type="button" class="ui basic red fluid button mainwp-extensions-deactivate" value="<?php esc_html_e( 'Deactivate License', 'mainwp' ); ?>">

                        </div>
                    </div>
                <?php } ?>

                <?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) { ?>
                <div class="extra content api-feedback" style="display:none;">
                    <div class="ui mini message"></div>
                </div>
                <?php } ?>
            <?php } ?>
        <?php
        /**
         * Action: mainwp_extension_card_bottom
         *
         * Fires at the Extension card bottom
         *
         * @since 4.1.4.1
         *
         * @param array $extension Array containing the Extension information.
         */
        do_action( 'mainwp_extension_card_bottom', $extension );
        ?>
        </div>
        <?php
    }

    /**
     * Method render_inactive_extension_card()
     *
     * Render the MainWP Extension Cards.
     *
     * @param mixed $extension Extention to render.
     * @param mixed $img_url Extension image.
     * @param bool  $installed Extension installed.
     */
    public static function render_inactive_extension_card( $extension, $img_url, $installed = false ) {

        if ( ! isset( $extension['link'] ) && isset( $extension['DocumentationURI'] ) ) {
            $extension['link'] = $extension['DocumentationURI'];
        }

        if ( ! isset( $extension['version'] ) ) {
            $extension['version'] = '';
        }
        ?>
        <div class="ui card extension grey mainwp-disabled-extension extension-card-<?php echo esc_attr( $extension['name'] ); ?>" extension-title="<?php echo esc_attr( $extension['name'] ); ?>" base-slug="<?php echo esc_attr( $extension['slug'] ); ?>">
            <div class="content">
                <img class="right floated mini ui image" alt="" src="<?php echo esc_html( $img_url ); ?>">
                <div class="header">
                    <?php echo esc_html( MainWP_Extensions_Handler::polish_ext_name( $extension, true ) ); ?>
                </div>

                <?php if ( $installed ) : ?>
                    <a href="admin.php?page=Extensions" class="ui black ribbon label"><?php echo esc_html__( 'Activate extension', 'mainwp' ); ?></a>
                <?php else : ?>
                    <a href="admin.php?page=Extensions&message=install-ext-<?php echo esc_attr( $extension['slug'] ); ?>" class="ui grey ribbon label"><?php echo esc_html__( 'Install extension', 'mainwp' ); ?></a>
                <?php endif; ?>
                <div class="description">
                    <?php echo esc_html( preg_replace( '/\<cite\>.*\<\/cite\>/', '', $extension['description'] ) ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_purchase_notice()
     *
     * Render Purchase Notice.
     */
    public static function render_purchase_notice() {
        $is_demo = MainWP_Demo_Handle::is_demo_mode();
        $is_pro  = MainWP_Hooks::is_pro_member();
        ?>
        <div id="mainwp-get-purchased-extensions-modal" class="ui first coupled large modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Install Extensions', 'mainwp' ); ?></div>
            <div class="scrolling content"></div>
            <div class="actions">
                <div class="ui two columns stackable grid">
                    <div class="left aligned column">
                    <?php
                    if ( $is_demo ) {
                        MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" id="mainwp-extensions-installnow-disabled" class="ui green button disabled" disabled="disabled" value="' . esc_attr__( 'Install Selected Extensions', 'mainwp' ) . '">' );
                    } else {
                        ?>
                        <input type="button" class="ui green button" id="mainwp-extensions-installnow" value="<?php esc_attr_e( 'Install Selected Extensions', 'mainwp' ); ?>">
                    <?php } ?>
                    </div>
                    <div class="right aligned column">
                        <?php if ( ! $is_pro ) : ?>
                        <a href="https://mainwp.com/signup/?utm_campaign=Dashboard%20-%20Upgrade%20to%20Pro&utm_source=Dashboard&utm_medium=install%20modal&utm_term=get%20mainwp%20pro" class="ui green basic button" target="_blank"><?php esc_html_e( 'Get MainWP Pro', 'mainwp' ); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Sidebar.
     *
     * @param string $mainwp_api_key MainWP.com api key.
     */
    public static function render_side_box( $mainwp_api_key ) {
        $is_demo = MainWP_Demo_Handle::is_demo_mode();
        ?>
        <div class="mainwp-search-options ui fluid accordion mainwp-sidebar-accordion">
            <div class="title active">
                <i class="dropdown icon"></i>
        <?php esc_html_e( 'Install and Activate Extensions', 'mainwp' ); ?>
        </div>
            <div class="content active">
        <?php if ( empty( $mainwp_api_key ) ) { ?>
        <div class="ui message info">
            <?php printf( esc_html__( 'Not sure how to find your MainWP Main API Key? %1$sClick here to get it.%2$s', 'mainwp' ), '<a href="https://mainwp.com/my-account/my-api-keys/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
        </div>
        <?php } ?>
        <div class="ui form" id="mainwp-extensions-api-fields">
            <div class="field">
                        <label><?php esc_html_e( 'Enter your MainWP Main API Key.', 'mainwp' ); ?></label>
                    </div>
                    <div class="field">
                <div class="ui input fluid">
                    <input type="password" id="mainwp_com_api_key" autocomplete="one-time-code" autocorrect="off" autocapitalize="none" spellcheck="false" placeholder="<?php esc_attr_e( '', 'mainwp' ); ?>" value="<?php echo esc_attr( $mainwp_api_key ); ?>"/>
                </div>
            </div>
            <div class="field">
                <div class="ui checkbox">
                    <input type="checkbox" <?php echo '' !== $mainwp_api_key ? 'checked="checked"' : ''; ?> name="extensions_api_savemylogin_chk" id="extensions_api_savemylogin_chk">
                    <label for="extensions_api_savemylogin_chk"><small><?php esc_html_e( 'Remember MainWP Main API Key', 'mainwp' ); ?></small></label>
                </div>
            </div>
        </div>
        <div class="ui compact hidden divider"></div>

        <input type="button" class="ui fluid button" id="mainwp-extensions-savelogin" value="<?php esc_attr_e( 'Validate my MainWP Main API Key', 'mainwp' ); ?>">
        <?php if ( ! $is_demo ) { ?>
        <div class="ui divider"></div>
        <input type="button" class="ui fluid basic green button" id="mainwp-extensions-bulkinstall" value="<?php esc_attr_e( 'Install Extensions', 'mainwp' ); ?>">
        <br/>
        <input type="button" class="ui fluid green button" id="mainwp-extensions-grabkeys" value="<?php esc_attr_e( 'Activate Extensions', 'mainwp' ); ?>">
        <?php } ?>
    </div>
    </div>
        <?php
        $install_ext_slug = '';
        if ( isset( $_GET['message'] ) && 0 === strpos( wp_unslash( $_GET['message'] ), 'install-ext-' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $install_ext_slug = str_replace( 'install-ext-', '', wp_unslash( $_GET['message'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ?>
            <input type="hidden" id="extension_install_ext_slug" value="<?php echo esc_attr( $install_ext_slug ); ?>">
            <?php
        }
        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function ($) {
                <?php
                if ( ! empty( $install_ext_slug ) ) {
                    ?>
                    $('#mainwp-extensions-bulkinstall').trigger('click');
                    <?php
                }
                ?>
            });
        </script>
        <?php
    }

    /**
     * Metod get_extension_groups()
     *
     * Grab current MainWP Extension Groups.
     *
     * @return array $groups
     */
    public static function get_extension_groups() {
        return array(
            'admin'       => esc_html__( 'Administrative', 'mainwp' ),
            'agency'      => esc_html__( 'Agency', 'mainwp' ),
            'backup'      => esc_html__( 'Backups', 'mainwp' ),
            'client'      => esc_html__( 'Client', 'mainwp' ),
            'content'     => esc_html__( 'Posts/Pages', 'mainwp' ),
            'development' => esc_html__( 'Development', 'mainwp' ),
            'monitoring'  => esc_html__( 'Monitoring', 'mainwp' ),
            'performance' => esc_html__( 'Performance', 'mainwp' ),
            'security'    => esc_html__( 'Security', 'mainwp' ),
            'visitor'     => esc_html__( 'Analytics', 'mainwp' ),
            'updates'     => esc_html__( 'Updates', 'mainwp' ),
        );
    }

    /**
     * Method get_available_extensions()
     *
     * Static Arrays of all Available Extensions.
     *
     * @param mixed $types Extensions type. Default: array( 'free', 'pro' ).
     * @param array $ext_grouped Extensions grouped. Default: array().
     *
     * @devtodo Move to MainWP Server via an XML file.
     */
    public static function get_available_extensions( $types = array( 'free', 'pro' ), $ext_grouped = array() ) { //phpcs:ignore -- NOSONAR - complex.

        $folder_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/';
        $all_exts   = array(
            'advanced-uptime-monitor-extension'       =>
            array(
                'type'                   => 'free',
                'slug'                   => 'advanced-uptime-monitor-extension',
                'title'                  => 'MainWP Advanced Uptime Monitor',
                'desc'                   => 'MainWP Extension for real-time up time monitoring.',
                'link'                   => 'https://mainwp.com/extension/advanced-uptime-monitor/',
                'img'                    => $folder_url . 'advanced-uptime-monitor.png',
                'product_id'             => 'Advanced Uptime Monitor Extension',
                'product_item_id'        => 0,
                'catalog_id'             => '218',
                'group'                  => array( 'monitoring' ),
                'privacy'                => 1, // 0 -standalone, 1 - API integration, 2 - 3rd party plugin integration
                'integration'            => 'Uptime Robot API',
                'integration_url'        => 'https://uptimerobot.com/',
                'integration_owner'      => 'Uptime Robot Service Provider Ltd.',
                'integration_owner_pp'   => 'https://uptimerobot.com/privacy/',
                'integration_1'          => 'Better Uptime API',
                'integration_url_1'      => 'https://betteruptime.com/',
                'integration_owner_1'    => 'Better Stack, Inc.',
                'integration_owner_pp_1' => 'https://betterstack.com/privacy',
                'integration_2'          => 'NodePing API',
                'integration_url_2'      => 'https://nodeping.com/',
                'integration_owner_2'    => 'NodePing LLC',
                'integration_owner_pp_2' => 'https://nodeping.com/privacy.html',
                'integration_3'          => 'Site24x7 API',
                'integration_url_3'      => 'https://www.site24x7.com/',
                'integration_owner_3'    => 'Zoho Corporation Pvt. Ltd.',
                'integration_owner_pp_3' => 'https://www.zoho.com/privacy.html',
            ),
            'mainwp-article-uploader-extension'       =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-article-uploader-extension',
                'title'                => 'MainWP Article Uploader Extension',
                'desc'                 => 'MainWP Article Uploader Extension allows you to bulk upload articles to your dashboard and publish to child sites.',
                'link'                 => 'https://mainwp.com/extension/article-uploader/',
                'img'                  => $folder_url . 'article-uploader.png',
                'product_id'           => 'MainWP Article Uploader Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '15340',
                'group'                => array( 'content' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-atarim-extension'                 =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-atarim-extension',
                'title'                => 'MainWP Atarim Extension',
                'desc'                 => 'MainWP Atarim Extension allows you get your Atarim info about managed sites to your MainWP Dashboard.',
                'link'                 => 'https://mainwp.com/extension/atarim/',
                'img'                  => $folder_url . 'atarim.png',
                'product_id'           => 'MainWP Atarim Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1251161',
                'group'                => array( 'development' ),
                'privacy'              => 1,
                'integration'          => 'Atarim API',
                'integration_url'      => 'https://atarim.io/',
                'integration_owner'    => 'WP FeedBack LTD',
                'integration_owner_pp' => 'https://atarim.io/privacy-policy/',
            ),
            'mainwp-backwpup-extension'               =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-backwpup-extension',
                'title'                => 'MainWP BackWPup Extension',
                'desc'                 => 'MainWP BackWPup Extension combines the power of your MainWP Dashboard with the popular WordPress BackWPup Plugin. It allows you to schedule backups on your child sites.',
                'link'                 => 'https://mainwp.com/extension/backwpup/',
                'img'                  => $folder_url . 'backwpup.png',
                'product_id'           => 'MainWP BackWPup Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '995008',
                'group'                => array( 'backup' ),
                'privacy'              => 2,
                'integration'          => 'BackWPup WordPress Backup Plugin',
                'integration_url'      => 'https://backwpup.com/',
                'integration_owner'    => 'Inpsyde GmbH',
                'integration_owner_pp' => 'https://backwpup.com/privacy/',
            ),
            'boilerplate-extension'                   =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'boilerplate-extension',
                'title'                => 'MainWP Boilerplate Extension',
                'desc'                 => 'MainWP Boilerplate extension allows you to create, edit and share repetitive pages across your network of child sites. The available placeholders allow these pages to be customized for each site without needing to be rewritten. The Boilerplate extension is the perfect solution for commonly repeated pages such as your "Privacy Policy", "About Us", "Terms of Use", "Support Policy", or any other page with standard text that needs to be distributed across your network.',
                'link'                 => 'https://mainwp.com/extension/boilerplate/',
                'img'                  => $folder_url . 'boilerplate.png',
                'product_id'           => 'Boilerplate Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1188',
                'group'                => array( 'content' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-buddy-extension'                  =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-buddy-extension',
                'title'                => 'MainWP Buddy Extension',
                'desc'                 => 'With the MainWP Buddy Extension, you can control the BackupBuddy Plugin settings for all your child sites directly from your MainWP Dashboard. This includes giving you the ability to create your child site backups and even set Backup schedules directly from your MainWP Dashboard.',
                'link'                 => 'https://mainwp.com/extension/mainwpbuddy/',
                'img'                  => $folder_url . 'mainwp-buddy.png',
                'product_id'           => 'MainWP Buddy Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1006044',
                'group'                => array( 'backup' ),
                'privacy'              => 2,
                'integration'          => 'BackupBuddy Plugin',
                'integration_url'      => 'https://ithemes.com/',
                'integration_owner'    => 'Liquid Web, LLC',
                'integration_owner_pp' => 'https://www.liquidweb.com/about-us/policies/privacy-policy/',
            ),
            'mainwp-bulk-settings-manager'            =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-bulk-settings-manager',
                'title'                => 'MainWP Bulk Settings Manager',
                'desc'                 => 'The Bulk Settings Manager Extension unlocks the world of WordPress directly from your MainWP Dashboard.  With Bulk Settings Manager you can adjust your Child site settings for the WordPress Core and almost any WordPress Plugin or Theme.',
                'link'                 => 'https://mainwp.com/extension/bulk-settings-manager/',
                'img'                  => $folder_url . 'bulk-settings-manager.png',
                'product_id'           => 'MainWP Bulk Settings Manager',
                'product_item_id'      => 0,
                'catalog_id'           => '347704',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-cache-control-extension'          =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-cache-control-extension',
                'title'                => 'MainWP Cache Control Extension',
                'desc'                 => 'MainWP Cache Control allows you to automatically purge the Cache on your child sites after performing an update of WP Core, Theme, or a Plugin through the MainWP Dashboard.',
                'link'                 => 'https://mainwp.com/extension/cache-control/',
                'img'                  => $folder_url . 'cache-control.png',
                'product_id'           => 'MainWP Cache Control Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1263050',
                'group'                => array( 'performance' ),
                'privacy'              => 1,
                'integration'          => 'Cloudflare API',
                'integration_url'      => 'https://www.cloudflare.com/',
                'integration_owner'    => 'Cloudflare, Inc.',
                'integration_owner_pp' => 'https://www.cloudflare.com/privacypolicy/',
                'release_date'         => 1676847600,
            ),
            'mainwp-clone-extension'                  =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-clone-extension',
                'title'                => 'MainWP Clone Extension',
                'desc'                 => 'MainWP Clone Extension is an extension for the MainWP plugin that enables you to clone your child sites with no technical knowledge required.',
                'link'                 => 'https://mainwp.com/extension/clone/',
                'img'                  => $folder_url . 'clone.png',
                'product_id'           => 'MainWP Clone Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1555',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-code-snippets-extension'          =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-code-snippets-extension',
                'title'                => 'MainWP Code Snippets Extension',
                'desc'                 => 'The MainWP Code Snippets Extension is a powerful PHP platform that enables you to execute php code and scripts on your child sites and view the output on your Dashboard. Requires the MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/code-snippets/',
                'img'                  => $folder_url . 'code-snippets.png',
                'product_id'           => 'MainWP Code Snippets Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '11196',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-comments-extension'               =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-comments-extension',
                'title'                => 'MainWP Comments Extension',
                'desc'                 => 'MainWP Comments Extension is an extension for the MainWP plugin that enables you to manage comments on your child sites.',
                'link'                 => 'https://mainwp.com/extension/comments/',
                'img'                  => $folder_url . 'comments.png',
                'product_id'           => 'MainWP Comments Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1551',
                'group'                => array( 'admin' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-custom-dashboard-extension'       =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-custom-dashboard-extension',
                'title'                => 'MainWP Custom Dashboard Extension',
                'desc'                 => 'The purpose of this plugin is to contain your customisation snippets for your MainWP Dashboard.',
                'link'                 => 'https://mainwp.com/extension/mainwp-custom-dashboard-extension/',
                'img'                  => $folder_url . 'custom-dashboard.png',
                'product_id'           => 'MainWP Custom Dashboard Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1080528',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-custom-post-types'                =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-custom-post-types',
                'title'                => 'MainWP Custom Post Type',
                'desc'                 => 'Custom Post Types Extension is an extension for the MainWP Plugin that allows you to manage almost any custom post type on your child sites and that includes Publishing, Editing, and Deleting custom post type content.',
                'link'                 => 'https://mainwp.com/extension/custom-post-types/',
                'img'                  => $folder_url . 'custom-post.png',
                'product_id'           => 'MainWP Custom Post Types',
                'product_item_id'      => 0,
                'catalog_id'           => '1002564',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-clean-and-lock-extension'         =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-clean-and-lock-extension',
                'title'                => 'MainWP Dashboard Lock Extension',
                'desc'                 => 'MainWP Dashboard Lock Extension allows you to limit access to your wp-admin and even redirect non-wp-admin pages to a different site making your MainWP Dashboard virtually invisible.',
                'link'                 => 'https://mainwp.com/extension/clean-lock/',
                'img'                  => $folder_url . 'clean-and-lock.png',
                'product_id'           => 'MainWP Clean and Lock Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '12907',
                'group'                => array( 'security' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-database-updater-extension'       =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-database-updater-extension',
                'title'                => 'MainWP Database Updater Extension',
                'desc'                 => 'MainWP Database Updater Extension detects available Database updates for the WooCommerce and Elementor plugins, and allows you to process them.',
                'link'                 => 'https://mainwp.com/extension/database-updater/',
                'img'                  => $folder_url . 'database-updater.png',
                'product_id'           => 'MainWP Database Updater Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1263539',
                'group'                => array( 'updates' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
                'release_date'         => 1677106800,
            ),
            'mainwp-domain-monitor-extension'         =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-domain-monitor-extension',
                'title'                => 'MainWP Domain Monitor Extension',
                'desc'                 => 'MainWP Domain Monitor Extension lets you keep a watchful eye on your domains. It alerts you via email when monitored domains are nearing expiration.',
                'link'                 => 'https://mainwp.com/extension/domain-monitor/',
                'img'                  => $folder_url . 'domain-monitor.png',
                'product_id'           => 'MainWP Domain Monitor Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1240624',
                'group'                => array( 'monitoring' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-favorites-extension'              =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-favorites-extension',
                'title'                => 'MainWP Favorites Extension',
                'desc'                 => 'MainWP Favorites is an extension for the MainWP plugin that allows you to store your favorite plugins and themes, and install them directly to child sites from the dashboard repository.',
                'link'                 => 'https://mainwp.com/extension/favorites/',
                'img'                  => $folder_url . 'favorites.png',
                'product_id'           => 'MainWP Favorites Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1379',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-fathom-extension'                 =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-fathom-extension',
                'title'                => 'MainWP Fathom Extension',
                'desc'                 => 'MainWP Fathom Extension is an extension for the MainWP plugin that enables you to monitor detailed statistics about your child sites traffic. It integrates seamlessly with your Fathom account.',
                'link'                 => 'https://mainwp.com/extension/fathom/',
                'img'                  => $folder_url . 'fathom.png',
                'product_id'           => 'MainWP Fathom Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1274704',
                'group'                => array( 'visitor' ),
                'privacy'              => 1,
                'integration'          => 'Fathom Analytics API',
                'integration_url'      => 'https://usefathom.com/',
                'integration_owner'    => 'Conva Ventures Inc.',
                'integration_owner_pp' => 'https://usefathom.com/privacy',
            ),
            'mainwp-file-uploader-extension'          =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-file-uploader-extension',
                'title'                => 'MainWP File Uploader Extension',
                'desc'                 => 'MainWP File Uploader Extension gives you an simple way to upload files to your child sites! Requires the MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/file-uploader/',
                'img'                  => $folder_url . 'file-uploader.png',
                'product_id'           => 'MainWP File Uploader Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '11637',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-google-analytics-extension'       =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-google-analytics-extension',
                'title'                => 'MainWP Google Analytics Extension',
                'desc'                 => 'MainWP Google Analytics Extension is an extension for the MainWP plugin that enables you to monitor detailed statistics about your child sites traffic. It integrates seamlessly with your Google Analytics account.',
                'link'                 => 'https://mainwp.com/extension/google-analytics/',
                'img'                  => $folder_url . 'google-analytics.png',
                'product_id'           => 'MainWP Google Analytics Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1554',
                'group'                => array( 'visitor' ),
                'privacy'              => 1,
                'integration'          => 'Google Analytics API',
                'integration_url'      => 'https://analytics.google.com',
                'integration_owner'    => 'Google LLC',
                'integration_owner_pp' => 'https://policies.google.com/privacy',
            ),
            'mainwp-jetpack-protect-extension'        =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-jetpack-protect-extension',
                'title'                => 'MainWP Jetpack Protect Extension',
                'desc'                 => 'MainWP Jetpack Protect Extension uses the Jetpack Protect plugin to bring you information about vulnerable plugins and themes on your Child Sites so you can act accordingly.',
                'link'                 => 'https://mainwp.com/extension/jetpack-protect/',
                'img'                  => $folder_url . 'jetpack-protect.png',
                'product_id'           => 'MainWP Jetpack Protect Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1263547',
                'group'                => array( 'security' ),
                'privacy'              => 2,
                'integration'          => 'Jetpack Protect',
                'integration_url'      => 'https://jetpack.com/',
                'integration_owner'    => 'Automattic Inc.',
                'integration_owner_pp' => 'https://automattic.com/privacy/',
                'release_date'         => 1677020400,
            ),
            'mainwp-jetpack-scan-extension'           =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-jetpack-scan-extension',
                'title'                => 'MainWP Jetpack Scan Extension',
                'desc'                 => 'MainWP Jetpack Scan Extension uses the Jetpack Scan API to bring you information about vulnerable plugins and themes on your Child Sites so you can act accordingly.',
                'link'                 => 'https://mainwp.com/extension/jetpack-scan/',
                'img'                  => $folder_url . 'jetpack-scan.png',
                'product_id'           => 'MainWP Jetpack Scan Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1263551',
                'group'                => array( 'security' ),
                'privacy'              => 1,
                'integration'          => 'Jetpack Scan API',
                'integration_url'      => 'https://jetpack.com/',
                'integration_owner'    => 'Automattic Inc.',
                'integration_owner_pp' => 'https://automattic.com/privacy/',
                'release_date'         => 1677020400,
            ),
            'mainwp-lighthouse-extension'             =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-lighthouse-extension',
                'title'                => 'MainWP Lighthouse Extension',
                'desc'                 => 'MainWP Lighthouse Extension is used for measuring the quality of your websites. It uses the Google PageSpeed Insights API to audit performance, accessibility and search engine optimization of your WordPress sites.',
                'link'                 => 'https://mainwp.com/extension/lighthouse/',
                'img'                  => $folder_url . 'lighthouse.png',
                'product_id'           => 'MainWP Lighthouse Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1233934',
                'group'                => array( 'monitoring' ),
                'privacy'              => 1,
                'integration'          => 'Google PageSpeed Insights API',
                'integration_url'      => 'https://pagespeed.web.dev/',
                'integration_owner'    => 'Google LLC',
                'integration_owner_pp' => 'https://policies.google.com/privacy',
            ),
            'mainwp-maintenance-extension'            =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-maintenance-extension',
                'title'                => 'MainWP Maintenance Extension',
                'desc'                 => 'MainWP Maintenance Extension is MainWP Dashboard extension that clears unwanted entries from child sites in your network. You can delete post revisions, delete auto draft pots, delete trash posts, delete spam, pending and trash comments, delete unused tags and categories and optimize database tables on selected child sites.',
                'link'                 => 'https://mainwp.com/extension/maintenance/',
                'img'                  => $folder_url . 'maintenance.png',
                'product_id'           => 'MainWP Maintenance Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1141',
                'group'                => array( 'performance' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-piwik-extension'                  =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-piwik-extension',
                'title'                => 'MainWP Piwik Extension',
                'desc'                 => 'MainWP Piwik Extension is an extension for the MainWP plugin that enables you to monitor detailed statistics about your child sites traffic. It integrates seamlessly with your Piwik account.',
                'link'                 => 'https://mainwp.com/extension/piwik/',
                'img'                  => $folder_url . 'piwik.png',
                'product_id'           => 'MainWP Piwik Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '10523',
                'group'                => array( 'visitor' ),
                'privacy'              => 1,
                'integration'          => 'Matomo API',
                'integration_url'      => 'https://matomo.org/',
                'integration_owner'    => 'InnoCraft',
                'integration_owner_pp' => 'https://matomo.org/privacy-policy/',
            ),
            'mainwp-post-dripper-extension'           =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-post-dripper-extension',
                'title'                => 'MainWP Post Dripper Extension',
                'desc'                 => 'MainWP Post Dripper Extension allows you to deliver posts or pages to your network of sites over a pre-scheduled period of time. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/post-dripper/',
                'img'                  => $folder_url . 'post-dripper.png',
                'product_id'           => 'MainWP Post Dripper Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '11756',
                'group'                => array( 'content' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-post-plus-extension'              =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-post-plus-extension',
                'title'                => 'MainWP Post Plus Extension',
                'desc'                 => 'Enhance your MainWP publishing experience. The MainWP Post Plus Extension allows you to save work in progress as Post and Page drafts. That is not all, it allows you to use random authors, dates and categories for your posts and pages. Requires the MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/post-plus/',
                'img'                  => $folder_url . 'post-plus.png',
                'product_id'           => 'MainWP Post Plus Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '12458',
                'group'                => array( 'content' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-pressable-extension'              =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-pressable-extension',
                'title'                => 'MainWP Pressable Extension',
                'desc'                 => 'MainWP Pressable Extension simplifies your Pressable hosting management experience, such as creating, disabling, and deleting websites, enabling/disabling CDN, managing backups, and more without the need to log in to your Pressable account.',
                'link'                 => 'https://mainwp.com/extension/pressable/',
                'img'                  => $folder_url . 'pressable.png',
                'product_id'           => 'MainWP Pressable Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1271427',
                'group'                => array( 'development' ),
                'privacy'              => 1,
                'integration'          => 'Pressable API',
                'integration_url'      => 'https://pressable.com/',
                'integration_owner'    => 'Pressable, Inc.',
                'integration_owner_pp' => 'https://automattic.com/privacy/',
            ),
            'mainwp-pro-reports-extension'            =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-pro-reports-extension',
                'title'                => 'MainWP Pro Reports Extension',
                'desc'                 => 'The MainWP Pro Reports extension is a fully customizable reporting engine that allows you to create the type of report you are proud to send to your clients.',
                'link'                 => 'https://mainwp.com/extension/pro-reports/',
                'img'                  => $folder_url . 'pro-reports.png',
                'product_id'           => 'MainWP Pro Reports Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1133708',
                'group'                => array( 'client' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-rocket-extension'                 =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-rocket-extension',
                'title'                => 'MainWP Rocket Extension',
                'desc'                 => 'MainWP Rocket Extension combines the power of your MainWP Dashboard with the popular WP Rocket Plugin. It allows you to mange WP Rocket settings and quickly Clear and Preload cache on your child sites.',
                'link'                 => 'https://mainwp.com/extension/rocket/',
                'img'                  => $folder_url . 'rocket.png',
                'product_id'           => 'MainWP Rocket Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '335257',
                'group'                => array( 'performance' ),
                'privacy'              => 2,
                'integration'          => 'WP Rocket Plugin',
                'integration_url'      => 'https://wp-rocket.me/',
                'integration_owner'    => 'WP Media, Inc.',
                'integration_owner_pp' => 'https://wp-rocket.me/privacy-policy/',
            ),
            'mainwp-sucuri-extension'                 =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-sucuri-extension',
                'title'                => 'MainWP Sucuri Extension',
                'desc'                 => 'MainWP Sucuri Extension enables you to scan your child sites for various types of malware, spam injections, website errors, and much more. Requires the MainWP Dashboard.',
                'link'                 => 'https://mainwp.com/extension/sucuri/',
                'img'                  => $folder_url . 'sucuri.png',
                'product_id'           => 'MainWP Sucuri Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '10777',
                'group'                => array( 'security' ),
                'privacy'              => 1,
                'integration'          => 'Sucuri API',
                'integration_url'      => 'https://sucuri.net/',
                'integration_owner'    => 'GoDaddy Mediatemple, Inc., d/b/a Sucuri.',
                'integration_owner_pp' => 'https://sucuri.net/privacy/',
            ),
            'mainwp-ithemes-security-extension'       =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-ithemes-security-extension',
                'title'                => 'MainWP iThemes Security Extension',
                'desc'                 => 'The iThemes Security Extension combines the power of your MainWP Dashboard with the popular iThemes Security Plugin. It allows you to manage iThemes Security plugin settings directly from your dashboard. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/ithemes-security/',
                'img'                  => $folder_url . 'ithemes.png',
                'product_id'           => 'MainWP Security Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '113355',
                'group'                => array( 'security' ),
                'privacy'              => 2,
                'integration'          => 'iThemes Security Plugin',
                'integration_url'      => 'https://ithemes.com/',
                'integration_owner'    => 'Liquid Web, LLC',
                'integration_owner_pp' => 'https://www.liquidweb.com/about-us/policies/privacy-policy/',
            ),
            'mainwp-ssl-monitor-extension'            =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-ssl-monitor-extension',
                'title'                => 'MainWP SSL Monitor Extension',
                'desc'                 => 'MainWP SSL Monitor Extension lets you keep a watchful eye on your SSL Certificates. It alerts you via email when monitored certificates are nearing expiration.',
                'link'                 => 'https://mainwp.com/extension/ssl-monitor/',
                'img'                  => $folder_url . 'ssl-monitor.png',
                'product_id'           => 'MainWP SSL Monitor Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1263543',
                'group'                => array( 'monitoring' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
                'release_date'         => 1676934000,
            ),
            'mainwp-staging-extension'                =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-staging-extension',
                'title'                => 'MainWP Staging Extension',
                'desc'                 => 'MainWP Staging Extension along with the WP Staging plugin, allows you to create and manage staging sites for your child sites.',
                'link'                 => 'https://mainwp.com/extension/staging/',
                'img'                  => $folder_url . 'staging.png',
                'product_id'           => 'MainWP Staging Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1034878',
                'group'                => array( 'development' ),
                'privacy'              => 2,
                'integration'          => 'WP STAGING Backup Duplicator & Migration Plugin',
                'integration_url'      => 'https://wp-staging.com/',
                'integration_owner'    => 'WP STAGING',
                'integration_owner_pp' => 'https://wp-staging.com/privacy-policy/',
            ),
            'mainwp-team-control'                     =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-team-control',
                'title'                => 'MainWP Team Control',
                'desc'                 => 'MainWP Team Control extension allows you to create a custom roles for your dashboard site users and limiting their access to MainWP features. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/team-control/',
                'img'                  => $folder_url . 'team-control.png',
                'product_id'           => 'MainWP Team Control',
                'product_item_id'      => 0,
                'catalog_id'           => '23936',
                'group'                => array( 'agency' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'termageddon-for-mainwp'                  =>
            array(
                'type'                 => 'free',
                'slug'                 => 'termageddon-for-mainwp',
                'title'                => 'Termageddon for MainWP',
                'desc'                 => 'This extension is used for creating Privacy Policy, ToS, Disclaimer and Cookie Policy & Consent Tool pages automatically on your websites.',
                'link'                 => 'https://mainwp.com/extension/termageddon-for-mainwp/',
                'img'                  => $folder_url . 'termageddon.png',
                'product_id'           => 'Termageddon for MainWP',
                'product_item_id'      => 0,
                'catalog_id'           => '1200201',
                'group'                => array( 'content' ),
                'privacy'              => 1,
                'integration'          => 'Termageddon API',
                'integration_url'      => 'https://termageddon.com/',
                'integration_owner'    => 'Termageddon, LLC',
                'integration_owner_pp' => 'https://termageddon.com/privacy-policy/',
                'release_date'         => 1678921200,
            ),
            'mainwp-timecapsule-extension'            =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-timecapsule-extension',
                'title'                => 'MainWP Time Capsule Extension',
                'desc'                 => 'With the MainWP Time Capsule Extension, you can control the WP Time Capsule Plugin on all your child sites directly from your MainWP Dashboard. This includes the ability to create your child site backups and even restore your child sites to a point back in time directly from your dashboard.',
                'link'                 => 'https://mainwp.com/extension/time-capsule/',
                'img'                  => $folder_url . 'time-capsule.png',
                'product_id'           => 'MainWP Time Capsule Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1049003',
                'group'                => array( 'backup' ),
                'privacy'              => 2,
                'integration'          => 'Backup and Staging by WP Time Capsule',
                'integration_url'      => 'https://wptimecapsule.com/',
                'integration_owner'    => 'Revmakx, LLC.',
                'integration_owner_pp' => 'https://wptimecapsule.com/privacy-policy/',
            ),
            'mainwp-time-tracker-extension'           =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-time-tracker-extension',
                'title'                => 'MainWP Time Tracker Extension',
                'desc'                 => 'Simplify client billing with precise project hour logging and detailed reporting. This tool integrates directly into your Dashboard for streamlined billing, ensuring accuracy and transparency in client charges.',
                'link'                 => 'https://mainwp.com/extension/time-tracker/',
                'img'                  => $folder_url . 'time-tracker.png',
                'product_id'           => 'MainWP Time Tracker Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1284683',
                'group'                => array( 'client' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-cost-tracker-assistant-extension' =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-cost-tracker-assistant-extension',
                'title'                => 'MainWP Cost Tracker Assistant Extension',
                'desc'                 => 'Enhance your MainWP Dashboard by adding timely notifications for upcoming subscription renewals and automates cost tracking for newly installed plugins and themes through zip uploads, streamlining cost management tasks.',
                'link'                 => 'https://mainwp.com/extension/cost-tracker-assistant/',
                'img'                  => $folder_url . 'cost-tracker-assistant.png',
                'product_id'           => 'MainWP Cost Tracker Assistant Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1284687',
                'group'                => array( 'admin' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-updraftplus-extension'            =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-updraftplus-extension',
                'title'                => 'MainWP UpdraftPlus Extension',
                'desc'                 => 'MainWP UpdraftPlus Extension combines the power of your MainWP Dashboard with the popular WordPress UpdraftPlus Plugin. It allows you to quickly back up your child sites.',
                'link'                 => 'https://mainwp.com/extension/updraftplus/',
                'img'                  => $folder_url . 'updraftplus.png',
                'product_id'           => 'MainWP UpdraftPlus Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '165843',
                'group'                => array( 'backup' ),
                'privacy'              => 2,
                'integration'          => 'UpdraftPlus WordPress Backup Plugin',
                'integration_url'      => 'https://updraftplus.com/',
                'integration_owner'    => 'Updraft WP Software Ltd.',
                'integration_owner_pp' => 'https://updraftplus.com/data-protection-and-privacy-centre/',
            ),
            'mainwp-url-extractor-extension'          =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-url-extractor-extension',
                'title'                => 'MainWP URL Extractor Extension',
                'desc'                 => 'MainWP URL Extractor allows you to search your child sites post and pages and export URLs in customized format. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/url-extractor/',
                'img'                  => $folder_url . 'url-extractor.png',
                'product_id'           => 'MainWP Url Extractor Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '11965',
                'group'                => array( 'development' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-virusdie-extension'               =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-virusdie-extension',
                'title'                => 'MainWP Virusdie Extension',
                'desc'                 => 'MainWP Virusdie Extension enables you to scan your child sites for various types of malware, spam injections, website errors, and much more. Requires the MainWP Dashboard.',
                'link'                 => 'https://mainwp.com/extension/virusdie/',
                'img'                  => $folder_url . 'virusdie.png',
                'product_id'           => 'MainWP Virusdie Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '1213235',
                'group'                => array( 'security' ),
                'privacy'              => 1,
                'integration'          => 'Virusdie API',
                'integration_url'      => 'https://virusdie.com/',
                'integration_owner'    => 'Virusdie OU',
                'integration_owner_pp' => 'https://virusdie.com/rules/privacypolicy/',
            ),
            'mainwp-vulnerability-checker-extension'  =>
            array(
                'type'                   => 'pro',
                'slug'                   => 'mainwp-vulnerability-checker-extension',
                'title'                  => 'MainWP Vulnerability Checker Extension',
                'desc'                   => 'MainWP Vulnerability Checker extension uses WPScan Vulnerability Database API to bring you information about vulnerable plugins on your Child Sites so you can act accordingly.',
                'link'                   => 'https://mainwp.com/extension/vulnerability-checker/',
                'img'                    => $folder_url . 'vulnerability-checker.png',
                'product_id'             => 'MainWP Vulnerability Checker Extension',
                'product_item_id'        => 0,
                'catalog_id'             => '12458',
                'group'                  => array( 'security' ),
                'privacy'                => 1,
                'integration'            => 'WPScan API',
                'integration_url'        => 'https://wpscan.com/',
                'integration_owner'      => 'Automattic Inc.',
                'integration_owner_pp'   => 'https://automattic.com/privacy/',
                'integration_1'          => 'NVD NIST API',
                'integration_url_1'      => 'https://nvd.nist.gov/',
                'integration_owner_1'    => 'National Institute of Standards and Technology',
                'integration_owner_pp_1' => 'https://www.nist.gov/privacy-policy',
            ),
            'mainwp-branding-extension'               =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-branding-extension',
                'title'                => 'MainWP White Label Extension',
                'desc'                 => 'The MainWP White Label extension allows you to alter the details of the MianWP Child Plugin to reflect your companies brand or completely hide the plugin from the installed plugins list.',
                'link'                 => 'https://mainwp.com/extension/child-plugin-branding/',
                'img'                  => $folder_url . 'branding.png',
                'product_id'           => 'MainWP Branding Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '10679',
                'group'                => array( 'agency' ),
                'privacy'              => 0,
                'integration'          => '',
                'integration_url'      => '',
                'integration_owner'    => '',
                'integration_owner_pp' => '',
            ),
            'mainwp-woocommerce-shortcuts-extension'  =>
            array(
                'type'                 => 'free',
                'slug'                 => 'mainwp-woocommerce-shortcuts-extension',
                'title'                => 'MainWP WooCommerce Shortcuts Extension',
                'desc'                 => 'MainWP WooCommerce Shortcuts provides you a quick access WooCommerce pages in your network. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/woocommerce-shortcuts/',
                'img'                  => $folder_url . 'woo-shortcuts.png',
                'product_id'           => 'MainWP WooCommerce Shortcuts Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '12706',
                'group'                => array( 'admin' ),
                'privacy'              => 2,
                'integration'          => 'WooCommerce Plugin',
                'integration_url'      => 'https://woocommerce.com',
                'integration_owner'    => 'Automattic Inc.',
                'integration_owner_pp' => 'https://automattic.com/privacy/',
            ),
            'mainwp-woocommerce-status-extension'     =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-woocommerce-status-extension',
                'title'                => 'MainWP WooCommerce Status Extension',
                'desc'                 => 'MainWP WooCommerce Status provides you a quick overview of your WooCommerce stores in your network. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/woocommerce-status/',
                'img'                  => $folder_url . 'woo-status.png',
                'product_id'           => 'MainWP WooCommerce Status Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '12671',
                'group'                => array( 'admin' ),
                'privacy'              => 2,
                'integration'          => 'WooCommerce Plugin',
                'integration_url'      => 'https://woocommerce.com',
                'integration_owner'    => 'Automattic Inc.',
                'integration_owner_pp' => 'https://automattic.com/privacy/',
            ),
            'mainwp-wordfence-extension'              =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'mainwp-wordfence-extension',
                'title'                => 'MainWP WordFence Extension',
                'desc'                 => 'The WordFence Extension combines the power of your MainWP Dashboard with the popular WordPress Wordfence Plugin. It allows you to manage WordFence settings, Monitor Live Traffic and Scan your child sites directly from your dashboard. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/wordfence/',
                'img'                  => $folder_url . 'wordfence.png',
                'product_id'           => 'MainWP Wordfence Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '19678',
                'group'                => array( 'security' ),
                'privacy'              => 2,
                'integration'          => 'Wordfence Security � Firewall & Malware Scan Plugin',
                'integration_url'      => 'https://www.wordfence.com/',
                'integration_owner'    => 'Defiant, Inc.',
                'integration_owner_pp' => 'https://www.wordfence.com/privacy-policy/',
            ),
            'wordpress-seo-extension'                 =>
            array(
                'type'                 => 'pro',
                'slug'                 => 'wordpress-seo-extension',
                'title'                => 'MainWP WordPress SEO Extension',
                'desc'                 => 'MainWP WordPress SEO extension by MainWP enables you to manage all your WordPress SEO by Yoast plugins across your network. Create and quickly set settings templates from one central dashboard. Requires MainWP Dashboard plugin.',
                'link'                 => 'https://mainwp.com/extension/wordpress-seo/',
                'img'                  => $folder_url . 'wordpress-seo.png',
                'product_id'           => 'MainWP Wordpress SEO Extension',
                'product_item_id'      => 0,
                'catalog_id'           => '12080',
                'group'                => array( 'content' ),
                'privacy'              => 2,
                'integration'          => 'Yoast SEO Plugin',
                'integration_url'      => 'https://yoast.com/',
                'integration_owner'    => 'Newfold Capital Inc.',
                'integration_owner_pp' => 'https://yoast.com/privacy-policy/',
            ),
            'activity-log-mainwp'                     => array(
                'type'                 => 'org',
                'product_id'           => 'activity-log-mainwp',
                'slug'                 => 'activity-log-mainwp/activity-log-mainwp.php',
                'title'                => 'Activity Log For MainWP',
                'link'                 => 'https://wordpress.org/plugins/activity-log-mainwp/',
                'url'                  => 'https://wordpress.org/plugins/activity-log-mainwp/',
                'group'                => array( 'security' ),
                'privacy'              => 2,
                'integration'          => 'WP Activity Log',
                'integration_url'      => 'https://wpactivitylog.com/',
                'integration_owner'    => 'WP White Security',
                'integration_owner_pp' => 'https://www.wpwhitesecurity.com/privacy-policy/',
                'desc'                 => 'Add the Activity Logs for MainWP extension to also keep a log of all the user activity and other changes that happen both on the MainWP dashboard, collate child site activity logs',
            ),
            'security-ninja-for-mainwp'               => array(
                'type'                 => 'org',
                'product_id'           => 'security-ninja-for-mainwp',
                'slug'                 => 'security-ninja-for-mainwp/security-ninja-mainwp.php',
                'title'                => 'Security Ninja For MainWP',
                'link'                 => 'https://wordpress.org/plugins/security-ninja-for-mainwp/',
                'url'                  => 'https://wordpress.org/plugins/security-ninja-for-mainwp/',
                'group'                => array( 'security' ),
                'privacy'              => 2,
                'integration'          => 'Security Ninja',
                'integration_url'      => 'https://wpsecurityninja.com/',
                'integration_owner'    => 'Larsik Corp',
                'integration_owner_pp' => 'https://larsik.com/privacy/',
                'desc'                 => 'Security Ninja is a strong plugin that helps you find vulnerabilites and improve the security on your website.',
            ),
            'wp-compress-mainwp'                      => array(
                'type'                 => 'org',
                'product_id'           => 'wp-compress-mainwp',
                'slug'                 => 'wp-compress-mainwp/wp-compress-main-wp.php',
                'title'                => 'WP Compress for MainWP',
                'link'                 => 'https://wordpress.org/plugins/wp-compress-mainwp/',
                'url'                  => 'https://wordpress.org/plugins/wp-compress-mainwp/',
                'group'                => array( 'performance' ),
                'privacy'              => 2,
                'integration'          => 'WP Compress',
                'integration_url'      => 'https://wpcompress.com/',
                'integration_owner'    => 'WP Compress',
                'integration_owner_pp' => 'https://wpcompress.com/privacy-policy/',
            ),
            'seopress-for-mainwp'                     => array(
                'type'                 => 'org',
                'product_id'           => 'seopress-for-mainwp',
                'slug'                 => 'seopress-for-mainwp/seopress-for-mainwp.php',
                'title'                => 'SEOPress for MainWP',
                'link'                 => 'https://wordpress.org/plugins/seopress-for-mainwp/',
                'url'                  => 'https://wordpress.org/plugins/seopress-for-mainwp/',
                'group'                => array( 'content' ),
                'privacy'              => 2,
                'integration'          => 'SEOPress',
                'integration_url'      => 'https://www.seopress.org/',
                'integration_owner'    => 'SEOPRESS',
                'integration_owner_pp' => 'https://www.seopress.org/privacy-policy/',
            ),
            'wpvivid-backup-mainwp'                   => array(
                'type'                 => 'org',
                'product_id'           => 'wpvivid-backup-mainwp',
                'slug'                 => 'wpvivid-backup-mainwp/wpvivid-backup-mainwp.php',
                'title'                => 'WPvivid Backup for MainWP',
                'link'                 => 'https://wordpress.org/plugins/wpvivid-backup-mainwp/',
                'url'                  => 'https://wordpress.org/plugins/wpvivid-backup-mainwp/',
                'group'                => array( 'backup' ),
                'privacy'              => 2,
                'integration'          => 'WPvivid Backup',
                'integration_url'      => 'https://wpvivid.com/',
                'integration_owner'    => 'VPSrobots Inc.',
                'integration_owner_pp' => 'https://wpvivid.com/privacy-policy',
                'desc'                 => 'WPvivid Backup for MainWP enables you to create and download backups of a specific child site, set backup schedules, set WPvivid Backup Plugin settings for all of your child sites directly from your MainWP Dashboard.',
            ),
        );

        $list = array();

        if ( is_string( $types ) ) {
            if ( 'all' === $types ) {
                return $all_exts;
            } elseif ( in_array( $types, array( 'free', 'pro', 'org' ) ) ) {
                $list = array();
                foreach ( $all_exts as $slug => $ext ) {
                    if ( $ext['type'] === $types ) {

                        $list[ $slug ] = $ext;

                    }
                }
                return $list;

            }
        }

        if ( is_array( $types ) ) {
            $list = array();
            foreach ( $all_exts as $slug => $ext ) {
                if ( in_array( $ext['type'], $types ) ) {
                    $list[ $slug ] = $ext;
                }
            }
        }

        if ( is_array( $ext_grouped ) && ! empty( $ext_grouped ) ) {
            $list = array();
            foreach ( $all_exts as $slug => $ext ) {
                foreach ( $ext_grouped as $group ) {
                    if ( in_array( $group, $ext['group'] ) ) {
                        $list[ $slug ] = $ext;
                    }
                }
            }
        }

        return $list;
    }
}
