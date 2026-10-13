<?php
/**
 * AI features landing page.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI page controller.
 */
class MainWP_AI {

    /**
     * Register the AI landing page and first-level navigation item.
     *
     * @return void
     */
    public static function init_menu() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() ) {
            return;
        }

        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'AI', 'mainwp' ),
            '<span id="mainwp-ai">' . esc_html__( 'AI', 'mainwp' ) . '</span>',
            'manage_options',
            'ai',
            array( static::class, 'render' )
        );

        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'AI', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'ai',
                'href'       => 'admin.php?page=ai',
                'icon'       => '<i class="microchip icon"></i>',
                'nosubmenu'  => true,
            ),
            0
        );
    }

    /**
     * Render the AI features landing page.
     *
     * @return void
     */
    public static function render() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() ) {
            echo '<div class="ui message">' . esc_html__( 'You do not have permission to access this page.', 'mainwp' ) . '</div>';
            return;
        }

        MainWP_UI::render_top_header( array( 'title' => esc_html__( 'AI', 'mainwp' ) ) );
        ?>
        <div id="mainwp-ai-page" class="ui padded segment">
            <div id="mainwp-ai-feature-cards" class="ui four stackable cards">
                <div class="ui fluid card">
                    <div class="content">
                        <em data-emoji=":book:" class="right floated small"></em>
                        <div class="header">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=Knowledge' ) ); ?>"><?php esc_html_e( 'Knowledge', 'mainwp' ); ?></a>
                        </div>
                        <div class="meta">
                            <a href="https://docs.mainwp.com/" target="_blank"><i class="book icon"></i> <?php esc_html_e( 'Documentation', 'mainwp' ); ?></a>
                        </div>
                        <div class="description">
                            <?php esc_html_e( 'Review collected Knowledge records or add and manage Agency knowledge for connected AI tools.', 'mainwp' ); ?>
                        </div>
                    </div>
                    <div class="extra content">
                        <a class="ui mini basic grey right floated button" href="<?php echo esc_url( admin_url( 'admin.php?page=Knowledge' ) ); ?>">
                            <?php esc_html_e( 'Read More', 'mainwp' ); ?>
                        </a>
                    </div>
                </div>

                <div class="ui fluid card">
                    <div class="content">
                        <em data-emoji=":brain:" class="right floated small"></em>
                        <div class="header">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-mcp' ) ); ?>"><?php esc_html_e( 'MainWP MCP', 'mainwp' ); ?></a>
                        </div>
                        <div class="meta">
                            <a href="https://docs.mainwp.com/mcp-server/quickstart" target="_blank"><i class="book icon"></i> <?php esc_html_e( 'Documentation', 'mainwp' ); ?></a>
                        </div>
                        <div class="description">
                            <?php esc_html_e( 'Connect Claude, Cursor, OpenAI Codex, VS Code Copilot, and other MCP-compatible AI tools to your Dashboard.', 'mainwp' ); ?>
                        </div>
                    </div>
                    <div class="extra content">
                        <a class="ui mini basic grey right floated button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-mcp' ) ); ?>">
                            <?php esc_html_e( 'Read More', 'mainwp' ); ?>
                        </a>
                    </div>
                </div>

                <div class="ui fluid card">
                    <div class="content">
                        <em data-emoji=":computer:" class="right floated small"></em>
                        <div class="header">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-control' ) ); ?>"><?php esc_html_e( 'MainWP Control', 'mainwp' ); ?></a>
                        </div>
                        <div class="meta">
                            <a href="https://docs.mainwp.com/api-reference/mainwp-control" target="_blank"><i class="book icon"></i> <?php esc_html_e( 'Documentation', 'mainwp' ); ?></a>
                        </div>
                        <div class="description">
                            <?php esc_html_e( 'Run repeatable MainWP operations from your terminal, shell scripts, cron jobs, monitoring, and CI/CD pipelines.', 'mainwp' ); ?>
                        </div>
                    </div>
                    <div class="extra content">
                        <a class="ui mini basic grey right floated button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-control' ) ); ?>">
                            <?php esc_html_e( 'Read More', 'mainwp' ); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // The shared header opens the page container, as on the Tags page.
        echo '</div>';
    }
}
