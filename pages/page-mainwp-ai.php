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
            <div id="mainwp-ai-feature-cards" class="ui two stackable cards">
                <div class="ui fluid card">
                    <div class="content">
                        <em data-emoji=":robot:" class="right floated small"></em>
                        <div class="header">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=AssistantSettings' ) ); ?>"><?php esc_html_e( 'Assistant', 'mainwp' ); ?></a>
                        </div>
                        <div class="description">
                            <?php esc_html_e( 'An AI assistant built into your Dashboard. Ask it how MainWP works, or have it work on your sites, like finding pending updates and applying them. It starts read-only, and you choose how much it can change. Connect it to your ChatGPT plan or an API key to get started.', 'mainwp' ); ?>
                        </div>
                    </div>
                    <div class="extra content">
                        <a class="ui mini basic grey right floated button" href="<?php echo esc_url( admin_url( 'admin.php?page=AssistantSettings' ) ); ?>">
                            <?php esc_html_e( 'Open', 'mainwp' ); ?>
                        </a>
                    </div>
                </div>

                <div class="ui fluid card">
                    <div class="content">
                        <em data-emoji=":book:" class="right floated small"></em>
                        <div class="header">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=Knowledge' ) ); ?>"><?php esc_html_e( 'Knowledge', 'mainwp' ); ?></a>
                        </div>
                        <div class="meta">
                            <a href="https://docs.mainwp.com/dashboard/overview/mainwp-knowledge" target="_blank"><i class="book icon"></i> <?php esc_html_e( 'Documentation', 'mainwp' ); ?></a>
                        </div>
                        <div class="description">
                            <?php esc_html_e( 'Teach the Assistant and your connected AI apps how your agency works: your standards, each client\'s preferences, how to handle a site\'s hosting quirks, and a dated history of work done. Anything an AI saves here stays unverified until you review it.', 'mainwp' ); ?>
                        </div>
                    </div>
                    <div class="extra content">
                        <a class="ui mini basic grey right floated button" href="<?php echo esc_url( admin_url( 'admin.php?page=Knowledge' ) ); ?>">
                            <?php esc_html_e( 'Open', 'mainwp' ); ?>
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
                            <?php
                            // ChatGPT is named only where the direct connection exists: it cannot run a local server.
                            if ( null !== MainWP_MCP::direct_view() ) {
                                esc_html_e( 'Manage your sites from an AI app you already use, like Claude, ChatGPT or Cursor. Ask it to check sites, apply updates or organize clients. Destructive changes are previewed first and wait for your approval by default. Connect with nothing to install if your Dashboard is reachable over https, or run a small local server (it needs Node.js) for a private one.', 'mainwp' );
                            } else {
                                esc_html_e( 'Manage your sites from an AI app you already use, like Claude, Cursor or VS Code. Ask it to check sites, apply updates or organize clients. Destructive changes are previewed first and wait for your approval by default. Runs as a small local server on your computer, which needs Node.js.', 'mainwp' );
                            }
                            ?>
                        </div>
                    </div>
                    <div class="extra content">
                        <a class="ui mini basic grey right floated button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-mcp' ) ); ?>">
                            <?php esc_html_e( 'Open', 'mainwp' ); ?>
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
                            <?php esc_html_e( 'A Node.js command-line tool for developers, with JSON output and exit codes. Script routine work like nightly update checks or a backup before every deploy, and run it from cron jobs or CI/CD pipelines. Destructive commands need a dry-run preview first, then an explicit confirmation.', 'mainwp' ); ?>
                        </div>
                    </div>
                    <div class="extra content">
                        <a class="ui mini basic grey right floated button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-control' ) ); ?>">
                            <?php esc_html_e( 'Open', 'mainwp' ); ?>
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
