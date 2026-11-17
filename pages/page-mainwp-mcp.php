<?php
/**
 * MainWP MCP landing page.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MainWP MCP page controller.
 */
class MainWP_MCP {

    /**
     * Register the MainWP MCP page and AI submenu item.
     *
     * @return void
     */
    public static function init_menu() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() || MainWP_Menu::is_disable_menu_item( 2, 'ai' ) ) {
            return;
        }

        $page = add_submenu_page(
            'mainwp_tab',
            esc_html__( 'MainWP MCP', 'mainwp' ),
            '<span id="mainwp-mcp">' . esc_html__( 'MainWP MCP', 'mainwp' ) . '</span>',
            'manage_options',
            'mainwp-mcp',
            array( static::class, 'render' )
        );

        add_action( 'load-' . $page, array( static::class, 'enqueue_scripts' ) );

        MainWP_Menu::add_left_menu(
            array(
                'title'                => esc_html__( 'MainWP MCP', 'mainwp' ),
                'parent_key'           => 'ai',
                'slug'                 => 'mainwp-mcp',
                'href'                 => 'admin.php?page=mainwp-mcp',
                'leftsub_order_level2' => 2,
            ),
            2
        );
    }

    /**
     * Enqueue scripts used by the MainWP MCP page.
     *
     * @return void
     */
    public static function enqueue_scripts() {
        wp_enqueue_script( 'mainwp-code-copy', MAINWP_PLUGIN_URL . 'assets/js/mainwp-code-copy.js', array( 'jquery', 'clipboard' ), MAINWP_VERSION, true );
        wp_enqueue_script( 'mainwp-mcp', MAINWP_PLUGIN_URL . 'assets/js/mainwp-mcp.js', array( 'jquery' ), MAINWP_VERSION, true );
    }

    /**
     * The "Connect an app" view, which the remote MCP module offers through a filter.
     *
     * @return array|null `render` callable, optional `render_with_header` callable, `preferred`
     *                    and `apps`, or null where the module doesn't load (a multisite).
     */
    public static function direct_view() {
        /**
         * Filter: mainwp_mcp_direct_view
         *
         * Offers the "Connect an app" view of the MainWP MCP page.
         *
         * @param array|null $view `render` (callable that prints the view and runs its own capability
         *                         and nonce checks), `preferred` (bool, make it the default view)
         *                         and `apps` (names of the apps it works with). An optional
         *                         `render_with_header` callable accepts a header callback and
         *                         runs it after the view's page-level messages.
         */
        $view = apply_filters( 'mainwp_mcp_direct_view', null );
        if ( ! is_array( $view ) || ! isset( $view['render'] ) || ! is_callable( $view['render'] ) ) {
            return null;
        }
        return array(
            'render'             => $view['render'],
            'render_with_header' => isset( $view['render_with_header'] ) && is_callable( $view['render_with_header'] ) ? $view['render_with_header'] : null,
            'preferred'          => ! empty( $view['preferred'] ),
            'apps'               => isset( $view['apps'] ) && is_array( $view['apps'] ) ? array_values( array_filter( $view['apps'], 'is_string' ) ) : array(),
        );
    }

    /**
     * Render the MainWP MCP page: the "Connect an app" view or the local server wizard.
     *
     * @return void
     */
    public static function render() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() ) {
            echo '<div class="ui message">' . esc_html__( 'You do not have permission to access this page.', 'mainwp' ) . '</div>';
            return;
        }

        if ( ! MainWP_Abilities_Util::is_abilities_api_available() ) {
            MainWP_UI::render_top_header( array( 'title' => esc_html__( 'MainWP MCP', 'mainwp' ) ) );
            echo '<div class="ui padded segment"><div class="ui blue message">' . esc_html__( 'MainWP MCP and MainWP Control require WordPress 6.9 or later. Update WordPress on this Dashboard to set up a connection.', 'mainwp' ) . '</div></div></div>';
            return;
        }

        if ( get_option( 'mainwp_onboarding_fresh_install' ) ) {
            update_option( 'mainwp_onboarding_mcp_visited', 1 );
        }

        $direct = static::direct_view();
        $tab    = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation is read-only.
        if ( null === $direct ) {
            $tab = 'local';
        } elseif ( ! in_array( $tab, array( 'direct', 'local' ), true ) ) {
            $tab = $direct['preferred'] ? 'direct' : 'local';
        }

        MainWP_UI::render_top_header( array( 'title' => esc_html__( 'MainWP MCP', 'mainwp' ) ) );
        if ( null !== $direct ) {
            static::render_navigation( $tab );
        }
        ?>
        <div id="mainwp-mcp-page" class="ui padded segment">
            <?php
            if ( 'direct' === $tab ) {
                static::render_direct( $direct );
            } else {
                static::render_local( null !== $direct );
            }
            ?>
        </div>
        <?php
        // The shared header opens the page container, as on the Tags page.
        echo '</div>';
    }

    /**
     * Render the tabs for the two ways to connect.
     *
     * @param string $active Active tab.
     *
     * @return void
     */
    private static function render_navigation( $active ) {
        ?>
        <div class="ui labeled icon inverted menu mainwp-sub-submenu" id="mainwp-mcp-tabular-menu">
            <a class="item <?php echo esc_attr( 'direct' === $active ? 'active' : '' ); ?>" data-tab="direct" href="<?php echo esc_url( 'admin.php?page=mainwp-mcp&tab=direct' ); ?>">
                <i class="plug icon"></i>
                <?php esc_html_e( 'Connect an app', 'mainwp' ); ?>
            </a>
            <a class="item <?php echo esc_attr( 'local' === $active ? 'active' : '' ); ?>" data-tab="local" href="<?php echo esc_url( 'admin.php?page=mainwp-mcp&tab=local' ); ?>">
                <i class="laptop icon"></i>
                <?php esc_html_e( 'Local server', 'mainwp' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render the "Connect an app" view: what it needs, then the module's panel.
     *
     * @param array $direct The view from direct_view().
     *
     * @return void
     */
    private static function render_direct( $direct ) {
        if ( null !== $direct['render_with_header'] ) {
            call_user_func(
                $direct['render_with_header'],
                static function ( $accordion = false ) use ( $direct ) {
                    static::render_direct_header( $direct, $accordion );
                }
            );
            return;
        }

        static::render_direct_header( $direct );
        call_user_func( $direct['render'] );
    }

    /**
     * Render the Direct tab's heading and local server tip.
     *
     * @param array $direct    The view from direct_view().
     * @param bool  $accordion Whether the heading controls the connection settings accordion.
     *
     * @return void
     */
    private static function render_direct_header( $direct, $accordion = false ) {
        ?>
        <div class="ui message">
            <em data-emoji=":bulb:" class="small"></em>
            <?php esc_html_e( 'Dashboard not reachable from the internet (an office network, your own computer)?', 'mainwp' ); ?>
            <a href="<?php echo esc_url( 'admin.php?page=mainwp-mcp&tab=local' ); ?>"><?php esc_html_e( 'Use the Local server tab.', 'mainwp' ); ?></a>
        </div>
        <h3 class="ui dividing header<?php echo $accordion ? ' title active mainwp-rmcp-section-title' : ''; ?>"<?php echo $accordion ? ' id="mainwp-rmcp-connection-title" role="button" tabindex="0" aria-expanded="true" aria-controls="mainwp-rmcp-connection-content"' : ''; ?>>
            <?php
            if ( $accordion ) :
                ?>
                <i class="right dropdown icon"></i><?php endif; ?>
            <?php esc_html_e( 'Connect an AI app', 'mainwp' ); ?>
            <div class="sub header">
                <?php
                if ( $direct['apps'] ) {
                    printf(
                        /* translators: %s: list of AI app names. */
                        esc_html__( 'Nothing to install. Works with %s. Your Dashboard has to be reachable from the internet over https.', 'mainwp' ),
                        esc_html( wp_sprintf_l( '%l', $direct['apps'] ) )
                    );
                } else {
                    esc_html_e( 'Nothing to install. Your Dashboard has to be reachable from the internet over https.', 'mainwp' );
                }
                ?>
                <a href="https://docs.mainwp.com/mcp-server/remote/overview" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read the setup guide.', 'mainwp' ); ?></a>
            </div>
        </h3>
        <?php
    }

    /**
     * Render the local server setup wizard.
     *
     * @param bool $can_connect_directly Whether the "Connect an app" view exists here.
     *
     * @return void
     */
    private static function render_local( $can_connect_directly ) {
        $current_user  = wp_get_current_user();
        $dashboard_url = untrailingslashit( home_url( '/' ) );
        $config        = array(
            'mcpServers' => array(
                'mainwp' => array(
                    'command' => 'npx',
                    'args'    => array( '-y', '@mainwp/mcp' ),
                    'env'     => array(
                        'MAINWP_URL'          => $dashboard_url,
                        'MAINWP_USER'         => (string) $current_user->user_login,
                        'MAINWP_APP_PASSWORD' => 'paste-application-password-here',
                    ),
                ),
            ),
        );
        $config_json   = (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        ?>
            <h2 class="ui header">
                <?php
                if ( $can_connect_directly ) {
                    esc_html_e( 'Run the MainWP MCP server on your computer', 'mainwp' );
                } else {
                    esc_html_e( 'Manage MainWP with your AI assistant', 'mainwp' );
                }
                ?>
                <span class="sub header">
                    <?php esc_html_e( 'Needs Node.js 20.19 or later and an Application Password. Works with a private Dashboard and with any app that runs local MCP servers, including Claude, Cursor, Codex and VS Code.', 'mainwp' ); ?>
                    <a href="https://github.com/mainwp/mainwp-mcp" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View MainWP MCP on GitHub.', 'mainwp' ); ?></a>
                </span>
            </h2>
            <?php if ( $can_connect_directly ) : ?>
                <p>
                    <?php esc_html_e( 'Dashboard reachable from the internet?', 'mainwp' ); ?>
                    <a href="<?php echo esc_url( 'admin.php?page=mainwp-mcp&tab=direct' ); ?>"><?php esc_html_e( 'The Connect an app tab needs nothing installed.', 'mainwp' ); ?></a>
                </p>
            <?php endif; ?>

            <div class="ui section divider mainwp-mcp-section-divider"></div>

            <div id="mainwp-mcp-steps" class="ui circular ordered stackable fluid steps" role="tablist" aria-label="<?php esc_attr_e( 'MainWP MCP setup steps', 'mainwp' ); ?>">
                <div id="mainwp-mcp-step-tab-1" class="active step" role="tab" tabindex="0" aria-selected="true" aria-current="step" aria-controls="mainwp-mcp-step-panel-1" data-step-index="0">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Credentials', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Create an Application Password', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-mcp-step-tab-2" class="step" role="tab" tabindex="-1" aria-selected="false" aria-controls="mainwp-mcp-step-panel-2" data-step-index="1">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Connect', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Set up your AI app', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-mcp-step-tab-3" class="step" role="tab" tabindex="-1" aria-selected="false" aria-controls="mainwp-mcp-step-panel-3" data-step-index="2">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Test', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Verify the connection', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-mcp-step-tab-4" class="step" role="tab" tabindex="-1" aria-selected="false" aria-controls="mainwp-mcp-step-panel-4" data-step-index="3">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Finish', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Explore tools and safeguards', 'mainwp' ); ?></div>
                    </div>
                </div>
            </div>

            <div id="mainwp-mcp-step-panels">
                <div id="mainwp-mcp-step-panel-1" class="ui basic segment mainwp-mcp-step-panel" role="tabpanel" aria-labelledby="mainwp-mcp-step-tab-1" data-step-panel="0">
                    <h3 class="ui header"><?php esc_html_e( 'Create an Application Password', 'mainwp' ); ?></h3>
                    <p class="mainwp-mcp-step-intro">
                        <?php esc_html_e( 'Create a separate, revocable credential for your AI app. For a cleaner audit trail, use a dedicated WordPress administrator account instead of your everyday login.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/api-reference/rest-api/application-passwords" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more about Application Passwords.', 'mainwp' ); ?></a>
                    </p>
                    <?php MainWP_Rest_Api_Page::render_inline_application_password_creator( esc_html__( 'MainWP MCP', 'mainwp' ), '#mainwp-mcp-config' ); ?>
                    <div class="ui divider mainwp-mcp-step-navigation-divider"></div>
                    <div class="mainwp-mcp-step-navigation-actions">
                        <a href="#mainwp-mcp-step-panel-2" class="mainwp-mcp-step-navigation mainwp-mcp-next-step" data-step-index="1" aria-controls="mainwp-mcp-step-panel-2"><span class="ui grey text"><?php esc_html_e( 'Next Step', 'mainwp' ); ?><i class="ui grey chevron right icon"></i></span></a>
                    </div>
                </div>

                <div id="mainwp-mcp-step-panel-2" class="ui basic segment mainwp-mcp-step-panel" role="tabpanel" aria-labelledby="mainwp-mcp-step-tab-2" data-step-panel="1" hidden>
                    <h3 class="ui header"><?php esc_html_e( 'Add MainWP MCP to your AI app', 'mainwp' ); ?></h3>
                    <p class="mainwp-mcp-step-intro">
                        <?php esc_html_e( 'You need Node.js 20.19 or later on the computer that runs your AI app. Paste this into the app\'s MCP settings and replace the placeholder with the Application Password from step 1.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/mcp-server/clients" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Instructions for each app.', 'mainwp' ); ?></a>
                    </p>
                    <textarea id="mainwp-mcp-config" class="mainwp-copyable-code mainwp-mcp-config-editor" rows="14" spellcheck="false" aria-label="<?php esc_attr_e( 'Editable MainWP MCP configuration', 'mainwp' ); ?>"><?php echo esc_textarea( $config_json ); ?></textarea>
                    <div class="mainwp-mcp-step-action">
                        <button type="button" id="mainwp-mcp-copy-config" class="ui small green button mainwp-code-copy-button mainwp-mcp-copy-config-button" data-clipboard-target="#mainwp-mcp-config" data-copy-success="<?php esc_attr_e( 'Copied!', 'mainwp' ); ?>" data-copy-error="<?php esc_attr_e( 'Copy failed', 'mainwp' ); ?>" aria-label="<?php esc_attr_e( 'Copy MCP configuration to clipboard', 'mainwp' ); ?>">
                            <i class="copy outline icon"></i>
                            <?php esc_html_e( 'Copy to clipboard', 'mainwp' ); ?>
                        </button>
                    </div>
                    <div class="ui divider mainwp-mcp-step-navigation-divider"></div>
                    <div class="mainwp-mcp-step-navigation-actions">
                        <a href="#mainwp-mcp-step-panel-1" class="mainwp-mcp-step-navigation" data-step-index="0" aria-controls="mainwp-mcp-step-panel-1"><span class="ui grey text"><i class="ui grey chevron left icon"></i><?php esc_html_e( 'Previous Step', 'mainwp' ); ?></span></a>
                        <a href="#mainwp-mcp-step-panel-3" class="mainwp-mcp-step-navigation mainwp-mcp-next-step" data-step-index="2" aria-controls="mainwp-mcp-step-panel-3"><span class="ui grey text"><?php esc_html_e( 'Next Step', 'mainwp' ); ?><i class="ui grey chevron right icon"></i></span></a>
                    </div>
                </div>

                <div id="mainwp-mcp-step-panel-3" class="ui basic segment mainwp-mcp-step-panel" role="tabpanel" aria-labelledby="mainwp-mcp-step-tab-3" data-step-panel="2" hidden>
                    <h3 class="ui header"><?php esc_html_e( 'Restart your AI app and test the connection', 'mainwp' ); ?></h3>
                    <p class="mainwp-mcp-step-intro"><?php esc_html_e( 'Start with a read-only request. A working connection returns your child sites by name and URL.', 'mainwp' ); ?></p>
                    <div class="ui large basic label"><i class="comment outline icon"></i><?php esc_html_e( 'List all my sites', 'mainwp' ); ?></div>
                    <div class="mainwp-mcp-step-action">
                        <a class="ui small green button" href="https://docs.mainwp.com/mcp-server/prompt-cookbook" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Try ready-made prompts', 'mainwp' ); ?>
                        </a>
                    </div>
                    <div class="ui divider mainwp-mcp-step-navigation-divider"></div>
                    <div class="mainwp-mcp-step-navigation-actions">
                        <a href="#mainwp-mcp-step-panel-2" class="mainwp-mcp-step-navigation" data-step-index="1" aria-controls="mainwp-mcp-step-panel-2"><span class="ui grey text"><i class="ui grey chevron left icon"></i><?php esc_html_e( 'Previous Step', 'mainwp' ); ?></span></a>
                        <a href="#mainwp-mcp-step-panel-4" class="mainwp-mcp-step-navigation mainwp-mcp-next-step" data-step-index="3" aria-controls="mainwp-mcp-step-panel-4"><span class="ui grey text"><?php esc_html_e( 'Next Step', 'mainwp' ); ?><i class="ui grey chevron right icon"></i></span></a>
                    </div>
                </div>

                <div id="mainwp-mcp-step-panel-4" class="ui basic segment mainwp-mcp-step-panel" role="tabpanel" aria-labelledby="mainwp-mcp-step-tab-4" data-step-panel="3" hidden>
                    <h3 class="ui header"><?php esc_html_e( 'You are ready to use MainWP MCP', 'mainwp' ); ?></h3>
                    <p class="mainwp-mcp-step-intro">
                        <?php esc_html_e( 'Your AI assistant can now use the MainWP tools available to this account. Use the capabilities and safeguards below as a quick reference.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/mcp-server/quickstart" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Follow the full setup guide.', 'mainwp' ); ?></a>
                    </p>

                    <h4 class="ui header"><?php esc_html_e( 'What you can do', 'mainwp' ); ?></h4>
                    <div class="ui relaxed list">
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Sites', 'mainwp' ); ?></strong>: <?php esc_html_e( 'list, sync, check, add, reconnect, or remove child sites.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Updates', 'mainwp' ); ?></strong>: <?php esc_html_e( 'review and apply core, plugin, theme, and translation updates.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Plugins and themes', 'mainwp' ); ?></strong>: <?php esc_html_e( 'inspect, activate, deactivate, update, or delete them.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Clients and tags', 'mainwp' ); ?></strong>: <?php esc_html_e( 'organize clients, sites, tags, and tracked costs.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Knowledge', 'mainwp' ); ?></strong>: <?php esc_html_e( 'use verified agency, client, and site context, and add records for you to review.', 'mainwp' ); ?></div></div>
                    </div>

                    <div class="ui hidden divider"></div>

                    <h4 class="ui header"><?php esc_html_e( 'Built-in safeguards', 'mainwp' ); ?></h4>
                    <div class="ui relaxed list">
                        <div class="item"><div class="content"><?php esc_html_e( 'Destructive changes are previewed and wait for your approval by default.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><?php esc_html_e( 'Safe mode can block destructive tools completely while you get comfortable.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><?php esc_html_e( 'Allow and block lists control which tools your assistant can see.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><?php esc_html_e( 'Credentials stay in your local MCP configuration and are sent only to your Dashboard. Use HTTPS to protect them in transit.', 'mainwp' ); ?></div></div>
                    </div>
                    <div class="ui divider mainwp-mcp-step-navigation-divider"></div>
                    <div class="mainwp-mcp-step-navigation-actions">
                        <a href="#mainwp-mcp-step-panel-3" class="mainwp-mcp-step-navigation" data-step-index="2" aria-controls="mainwp-mcp-step-panel-3"><span class="ui grey text"><i class="ui grey chevron left icon"></i><?php esc_html_e( 'Previous Step', 'mainwp' ); ?></span></a>
                    </div>
                </div>
            </div>
        <?php
    }
}
