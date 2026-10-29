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
     * Render the MainWP MCP landing page.
     *
     * @return void
     */
    public static function render() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() ) {
            echo '<div class="ui message">' . esc_html__( 'You do not have permission to access this page.', 'mainwp' ) . '</div>';
            return;
        }

        if ( get_option( 'mainwp_onboarding_fresh_install' ) ) {
            update_option( 'mainwp_onboarding_mcp_visited', 1 );
        }

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

        MainWP_UI::render_top_header( array( 'title' => esc_html__( 'MainWP MCP', 'mainwp' ) ) );
        ?>
        <div id="mainwp-mcp-page" class="ui padded segment">
            <h2 class="ui header">
                <?php esc_html_e( 'Manage MainWP with your AI assistant', 'mainwp' ); ?>
                <span class="sub header">
                    <?php esc_html_e( 'Connect Claude, Cursor, OpenAI Codex, VS Code Copilot, and other MCP-compatible tools to this Dashboard.', 'mainwp' ); ?>
                    <a href="https://github.com/mainwp/mainwp-mcp" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View MainWP MCP on GitHub.', 'mainwp' ); ?></a>
                </span>
            </h2>

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
                        <div class="description"><?php esc_html_e( 'Configure your AI tool', 'mainwp' ); ?></div>
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
                        <?php esc_html_e( 'Create a separate, revocable credential for your AI tool. For a cleaner audit trail, use a dedicated WordPress administrator account instead of your everyday login.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/api-reference/rest-api/application-passwords" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more about Application Passwords.', 'mainwp' ); ?></a>
                    </p>
                    <?php MainWP_Rest_Api_Page::render_inline_application_password_creator( esc_html__( 'MainWP MCP', 'mainwp' ), '#mainwp-mcp-config' ); ?>
                    <div class="ui divider mainwp-mcp-step-navigation-divider"></div>
                    <div class="mainwp-mcp-step-navigation-actions">
                        <a href="#mainwp-mcp-step-panel-2" class="mainwp-mcp-step-navigation mainwp-mcp-next-step" data-step-index="1" aria-controls="mainwp-mcp-step-panel-2"><span class="ui grey text"><?php esc_html_e( 'Next Step', 'mainwp' ); ?><i class="ui grey chevron right icon"></i></span></a>
                    </div>
                </div>

                <div id="mainwp-mcp-step-panel-2" class="ui basic segment mainwp-mcp-step-panel" role="tabpanel" aria-labelledby="mainwp-mcp-step-tab-2" data-step-panel="1" hidden>
                    <h3 class="ui header"><?php esc_html_e( 'Add MainWP MCP to your AI tool', 'mainwp' ); ?></h3>
                    <p class="mainwp-mcp-step-intro">
                        <?php esc_html_e( 'You need Node.js 20.19 or later on the computer running your AI tool. Paste this configuration into your client, then replace the password placeholder with the Application Password from step 1.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/mcp-server/clients" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Find instructions for your AI tool.', 'mainwp' ); ?></a>
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
                    <h3 class="ui header"><?php esc_html_e( 'Restart your AI tool and test the connection', 'mainwp' ); ?></h3>
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
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Sites', 'mainwp' ); ?></strong> — <?php esc_html_e( 'list, sync, check, add, reconnect, or remove child sites.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Updates', 'mainwp' ); ?></strong> — <?php esc_html_e( 'review and apply core, plugin, theme, and translation updates.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Plugins and themes', 'mainwp' ); ?></strong> — <?php esc_html_e( 'inspect, activate, deactivate, update, or delete them.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Clients and tags', 'mainwp' ); ?></strong> — <?php esc_html_e( 'organize clients, sites, tags, and tracked costs.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Knowledge', 'mainwp' ); ?></strong> — <?php esc_html_e( 'use verified agency, client, and site context, and add records for you to review.', 'mainwp' ); ?></div></div>
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
        </div>
        <?php
        // The shared header opens the page container, as on the Tags page.
        echo '</div>';
    }
}
