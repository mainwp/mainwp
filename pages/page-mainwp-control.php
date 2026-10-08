<?php
/**
 * MainWP Control landing page.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MainWP Control page controller.
 */
class MainWP_Control {

    /**
     * Register the MainWP Control page and AI submenu item.
     *
     * @return void
     */
    public static function init_menu() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() || MainWP_Menu::is_disable_menu_item( 2, 'ai' ) ) {
            return;
        }

        $page = add_submenu_page(
            'mainwp_tab',
            esc_html__( 'MainWP Control', 'mainwp' ),
            '<span id="mainwp-control">' . esc_html__( 'MainWP Control', 'mainwp' ) . '</span>',
            'manage_options',
            'mainwp-control',
            array( static::class, 'render' )
        );

        add_action( 'load-' . $page, array( static::class, 'enqueue_scripts' ) );

        MainWP_Menu::add_left_menu(
            array(
                'title'                => esc_html__( 'MainWP Control', 'mainwp' ),
                'parent_key'           => 'ai',
                'slug'                 => 'mainwp-control',
                'href'                 => 'admin.php?page=mainwp-control',
                'leftsub_order_level2' => 3,
            ),
            2
        );
    }

    /**
     * Enqueue scripts used by the MainWP Control page.
     *
     * @return void
     */
    public static function enqueue_scripts() {
        wp_enqueue_script( 'mainwp-code-copy', MAINWP_PLUGIN_URL . 'assets/js/mainwp-code-copy.js', array( 'jquery', 'clipboard' ), MAINWP_VERSION, true );
        wp_enqueue_script( 'mainwp-control', MAINWP_PLUGIN_URL . 'assets/js/mainwp-control.js', array( 'jquery' ), MAINWP_VERSION, true );
    }

    /**
     * Render the MainWP Control landing page.
     *
     * @return void
     */
    public static function render() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() ) {
            echo '<div class="ui message">' . esc_html__( 'You do not have permission to access this page.', 'mainwp' ) . '</div>';
            return;
        }

        MainWP_UI::render_top_header( array( 'title' => esc_html__( 'MainWP Control', 'mainwp' ) ) );
        ?>
        <div id="mainwp-control-page" class="ui padded segment">
            <h2 class="ui header">
                <?php esc_html_e( 'Automate MainWP from the command line', 'mainwp' ); ?>
                <span class="sub header">
                    <?php esc_html_e( 'Run repeatable site maintenance from your terminal, shell scripts, cron jobs, and CI/CD pipelines.', 'mainwp' ); ?>
                    <a href="https://github.com/mainwp/mainwp-control" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View MainWP Control on GitHub.', 'mainwp' ); ?></a>
                </span>
            </h2>

            <div class="ui section divider mainwp-control-section-divider"></div>

            <div id="mainwp-control-steps" class="ui circular ordered stackable fluid steps" role="tablist" aria-label="<?php esc_attr_e( 'MainWP Control setup steps', 'mainwp' ); ?>">
                <div id="mainwp-control-step-tab-1" class="active step" role="tab" tabindex="0" aria-selected="true" aria-current="step" aria-controls="mainwp-control-step-panel-1" data-step-index="0">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Credentials', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Create an Application Password', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-control-step-tab-2" class="step" role="tab" tabindex="-1" aria-selected="false" aria-controls="mainwp-control-step-panel-2" data-step-index="1">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Install', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Install and sign in', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-control-step-tab-3" class="step" role="tab" tabindex="-1" aria-selected="false" aria-controls="mainwp-control-step-panel-3" data-step-index="2">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Test', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Run your first commands', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-control-step-tab-4" class="step" role="tab" tabindex="-1" aria-selected="false" aria-controls="mainwp-control-step-panel-4" data-step-index="3">
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Finish', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Review features and safeguards', 'mainwp' ); ?></div>
                    </div>
                </div>
            </div>

            <div id="mainwp-control-step-panels">
                <div id="mainwp-control-step-panel-1" class="ui basic segment mainwp-control-step-panel" role="tabpanel" aria-labelledby="mainwp-control-step-tab-1" data-step-panel="0">
                    <h3 class="ui header"><?php esc_html_e( 'Create an Application Password', 'mainwp' ); ?></h3>
                    <p class="mainwp-control-step-intro">
                        <?php esc_html_e( 'Create a separate, revocable credential for MainWP Control. A dedicated WordPress administrator account keeps the audit trail separate from your everyday login.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/api-reference/rest-api/application-passwords" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more about Application Passwords.', 'mainwp' ); ?></a>
                    </p>
                    <?php MainWP_Rest_Api_Page::render_inline_application_password_creator( esc_html__( 'MainWP Control', 'mainwp' ) ); ?>
                    <div class="ui divider mainwp-control-step-navigation-divider"></div>
                    <div class="mainwp-control-step-navigation-actions">
                        <a href="#mainwp-control-step-panel-2" class="mainwp-control-step-navigation mainwp-control-next-step" data-step-index="1" aria-controls="mainwp-control-step-panel-2"><span class="ui grey text"><?php esc_html_e( 'Next Step', 'mainwp' ); ?><i class="ui grey chevron right icon"></i></span></a>
                    </div>
                </div>

                <div id="mainwp-control-step-panel-2" class="ui basic segment mainwp-control-step-panel" role="tabpanel" aria-labelledby="mainwp-control-step-tab-2" data-step-panel="1" hidden>
                    <h3 class="ui header"><?php esc_html_e( 'Install MainWP Control and log in', 'mainwp' ); ?></h3>
                    <p class="mainwp-control-step-intro">
                        <?php esc_html_e( 'Install Node.js 20.18.1 or later on the computer or server where you will run the CLI. Login asks for your Dashboard URL, username, and Application Password, then stores the credential in your operating system keychain.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/api-reference/mainwp-control" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read the installation guide.', 'mainwp' ); ?></a>
                    </p>
                    <textarea id="mainwp-control-install-commands" class="mainwp-copyable-code mainwp-control-code-editor" rows="5" spellcheck="false" aria-label="<?php esc_attr_e( 'Editable MainWP Control installation commands', 'mainwp' ); ?>">npm install -g @mainwp/control

mainwpcontrol login</textarea>
                    <div class="mainwp-control-step-action">
                        <button type="button" class="ui small green button mainwp-code-copy-button" data-clipboard-target="#mainwp-control-install-commands" data-copy-success="<?php esc_attr_e( 'Copied!', 'mainwp' ); ?>" data-copy-error="<?php esc_attr_e( 'Copy failed', 'mainwp' ); ?>" aria-label="<?php esc_attr_e( 'Copy MainWP Control installation commands to clipboard', 'mainwp' ); ?>">
                            <i class="copy outline icon"></i>
                            <?php esc_html_e( 'Copy to clipboard', 'mainwp' ); ?>
                        </button>
                    </div>
                    <div class="ui divider mainwp-control-step-navigation-divider"></div>
                    <div class="mainwp-control-step-navigation-actions">
                        <a href="#mainwp-control-step-panel-1" class="mainwp-control-step-navigation" data-step-index="0" aria-controls="mainwp-control-step-panel-1"><span class="ui grey text"><i class="ui grey chevron left icon"></i><?php esc_html_e( 'Previous Step', 'mainwp' ); ?></span></a>
                        <a href="#mainwp-control-step-panel-3" class="mainwp-control-step-navigation mainwp-control-next-step" data-step-index="2" aria-controls="mainwp-control-step-panel-3"><span class="ui grey text"><?php esc_html_e( 'Next Step', 'mainwp' ); ?><i class="ui grey chevron right icon"></i></span></a>
                    </div>
                </div>

                <div id="mainwp-control-step-panel-3" class="ui basic segment mainwp-control-step-panel" role="tabpanel" aria-labelledby="mainwp-control-step-tab-3" data-step-panel="2" hidden>
                    <h3 class="ui header"><?php esc_html_e( 'Discover abilities and run your first command', 'mainwp' ); ?></h3>
                    <p class="mainwp-control-step-intro"><?php esc_html_e( 'MainWP Control discovers the abilities available on your Dashboard at runtime. Start by listing them, then try a read-only site request.', 'mainwp' ); ?></p>
                    <textarea id="mainwp-control-first-commands" class="mainwp-copyable-code mainwp-control-code-editor" rows="5" spellcheck="false" aria-label="<?php esc_attr_e( 'Editable MainWP Control starter commands', 'mainwp' ); ?>">mainwpcontrol abilities list

mainwpcontrol abilities run list-sites-v1 --json</textarea>
                    <div class="mainwp-control-step-action">
                        <button type="button" class="ui small green button mainwp-code-copy-button" data-clipboard-target="#mainwp-control-first-commands" data-copy-success="<?php esc_attr_e( 'Copied!', 'mainwp' ); ?>" data-copy-error="<?php esc_attr_e( 'Copy failed', 'mainwp' ); ?>" aria-label="<?php esc_attr_e( 'Copy MainWP Control starter commands to clipboard', 'mainwp' ); ?>">
                            <i class="copy outline icon"></i>
                            <?php esc_html_e( 'Copy to clipboard', 'mainwp' ); ?>
                        </button>
                    </div>
                    <div class="ui divider mainwp-control-step-navigation-divider"></div>
                    <div class="mainwp-control-step-navigation-actions">
                        <a href="#mainwp-control-step-panel-2" class="mainwp-control-step-navigation" data-step-index="1" aria-controls="mainwp-control-step-panel-2"><span class="ui grey text"><i class="ui grey chevron left icon"></i><?php esc_html_e( 'Previous Step', 'mainwp' ); ?></span></a>
                        <a href="#mainwp-control-step-panel-4" class="mainwp-control-step-navigation mainwp-control-next-step" data-step-index="3" aria-controls="mainwp-control-step-panel-4"><span class="ui grey text"><?php esc_html_e( 'Next Step', 'mainwp' ); ?><i class="ui grey chevron right icon"></i></span></a>
                    </div>
                </div>

                <div id="mainwp-control-step-panel-4" class="ui basic segment mainwp-control-step-panel" role="tabpanel" aria-labelledby="mainwp-control-step-tab-4" data-step-panel="3" hidden>
                    <h3 class="ui header"><?php esc_html_e( 'You are ready to use MainWP Control', 'mainwp' ); ?></h3>
                    <p class="mainwp-control-step-intro">
                        <?php esc_html_e( 'MainWP Control is ready for explicit commands, repeatable automation, and structured output. Use the features and safeguards below as a quick reference.', 'mainwp' ); ?>
                        <a href="https://docs.mainwp.com/api-reference/mainwp-control" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read the full documentation.', 'mainwp' ); ?></a>
                        <a href="https://mainwp.com/mainwp-tools/mainwp-control/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Explore MainWP Control.', 'mainwp' ); ?></a>
                    </p>

                    <h4 class="ui header"><?php esc_html_e( 'Why use MainWP Control', 'mainwp' ); ?></h4>
                    <div class="ui relaxed list">
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Automation-ready output', 'mainwp' ); ?></strong> — <?php esc_html_e( 'use JSON and reliable exit codes in scripts, reports, and monitoring.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Durable batch operations', 'mainwp' ); ?></strong> — <?php esc_html_e( 'wait for queued jobs or return later using their job ID.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Multiple Dashboards', 'mainwp' ); ?></strong> — <?php esc_html_e( 'keep named profiles and select one globally or per command.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><strong><?php esc_html_e( 'Optional chat mode', 'mainwp' ); ?></strong> — <?php esc_html_e( 'use your own LLM API key for plain-language commands with the same safety gates.', 'mainwp' ); ?></div></div>
                    </div>

                    <div class="ui hidden divider"></div>

                    <h4 class="ui header"><?php esc_html_e( 'Built-in safeguards', 'mainwp' ); ?></h4>
                    <div class="ui relaxed list">
                        <div class="item"><div class="content"><?php esc_html_e( 'Destructive operations require a successful dry-run preview before execution.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><?php esc_html_e( 'Execution requires an explicit confirmation; automation must opt in per command.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><?php esc_html_e( 'Confirmed destructive commands are recorded in a local audit log.', 'mainwp' ); ?></div></div>
                        <div class="item"><div class="content"><?php esc_html_e( 'Interactive credentials stay in your operating system keychain; CI can use protected environment variables.', 'mainwp' ); ?></div></div>
                    </div>
                    <div class="ui divider mainwp-control-step-navigation-divider"></div>
                    <div class="mainwp-control-step-navigation-actions">
                        <a href="#mainwp-control-step-panel-3" class="mainwp-control-step-navigation" data-step-index="2" aria-controls="mainwp-control-step-panel-3"><span class="ui grey text"><i class="ui grey chevron left icon"></i><?php esc_html_e( 'Previous Step', 'mainwp' ); ?></span></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // The shared header opens the page container, as on the Tags page.
        echo '</div>';
    }
}
