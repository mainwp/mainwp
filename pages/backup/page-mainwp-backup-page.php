<?php
/**
 * MainWP Backup Page.
 *
 * Page chrome, routing, and menu registration for the Backups pages.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Page
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Page { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name(): string {
        return __CLASS__;
    }

    /**
     * Subpages variable.
     *
     * @var array|null $subPages Subpages variable.
     */
    public static $subPages;

    /**
     * Whether or not to show the Backups Submenu.
     *
     * @var bool $hideSubmenuBackups true|false.
     */
    private static $hideSubmenuBackups = false;

    /**
     * Instance variable.
     *
     * @var self|null Instance variable.
     */
    private static $instance = null;

    /**
     * Create instance.
     *
     * @return self $instance.
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new MainWP_Backup_Page();
        }

        return static::$instance;
    }

    /** Instantiate Hooks. */
    public static function init(): void {
        /**
         * This hook allows you to render the Backups page header via the 'mainwp-pageheader-backups' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pageheader-backups
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
         *
         * @see \MainWP\Dashboard\MainWP_Backup_Page::render_header
         */
        add_action( 'mainwp-pageheader-backups', array( static::get_class_name(), 'render_header' ) );

        /**
         * This hook allows you to render the Backups page footer via the 'mainwp-pagefooter-backups' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pagefooter-backups
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
         *
         * @see \MainWP\Dashboard\MainWP_Backup_Page::render_footer
         */
        add_action( 'mainwp-pagefooter-backups', array( static::get_class_name(), 'render_footer' ) );
    }

    /**
     * Render MainWP Backups Page Header.
     *
     * @param string $shownPage The page slug shown at this moment.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     */
    public static function render_header( $shownPage = '' ): void {  //phpcs:ignore -- NOSONAR - complex.

        $params = array(
            'title' => esc_html__( 'Backups', 'mainwp' ),
        );

        MainWP_UI::render_top_header( $params );

        $renderItems = array();

        $renderItems[] = array(
            'title'    => esc_html__( 'Manage Backups', 'mainwp' ),
            'href'     => 'admin.php?page=ManageBackups',
            'active'   => ( '' === $shownPage ) ? true : false,
            'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' ) ? true : false,
        );

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) {
            $renderItems[] = array(
                'title'    => esc_html__( 'Add New', 'mainwp' ),
                'href'     => 'admin.php?page=ManageBackupsAddNew',
                'access'   => \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ),
                'active'   => ( 'AddNew' === $shownPage ) ? true : false,
                'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsEdit' ) && 'ManageBackupsEdit' === $shownPage ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Edit', 'mainwp' ),
                'href'   => '#',
                'active' => true,
            );
        }

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' . $subPage['slug'] ) ) {
                        continue;
                }

                $item           = array();
                $item['title']  = esc_html( $subPage['title'] );
                $item['href']   = 'admin.php?page=ManageBackups' . $subPage['slug'];
                $item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
                $renderItems[]  = $item;
            }
        }

        MainWP_UI::render_page_navigation( $renderItems );
        static::render_backup_environment_notices();
    }

    /**
     * Method render_backup_environment_notices()
     *
     * Display admin notices when the dashboard environment has issues
     * that will affect backups: low disk space, a non-writable backup
     * directory, or a low PHP memory limit.
     *
     * @return void
     */
    public static function render_backup_environment_notices(): void {
        $warnings = array();

        $backup_dir = MainWP_System_Utility::get_mainwp_dir( 'backup' );
        if ( is_array( $backup_dir ) ) {
            $backup_dir = $backup_dir[0];
        }

        if ( function_exists( 'disk_free_space' ) && is_dir( $backup_dir ) ) {
            $free = @disk_free_space( $backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
            if ( false !== $free && $free < 500 * MB_IN_BYTES ) {
                $warnings[] = sprintf(
                    /* translators: %s: free disk space */
                    esc_html__( 'Low disk space: %s available. Large backups may fail.', 'mainwp' ),
                    size_format( $free )
                );
            }
        }

        if ( is_dir( $backup_dir ) && ! wp_is_writable( $backup_dir ) ) {
            $warnings[] = esc_html__( 'The backup directory is not writable. Backups will fail until permissions are fixed.', 'mainwp' );
        }

        $memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        if ( $memory > 0 && $memory < 128 * MB_IN_BYTES ) {
            $warnings[] = sprintf(
                /* translators: %s: current memory limit */
                esc_html__( 'PHP memory limit is %s. Recommended: 256M or higher for backups.', 'mainwp' ),
                esc_html( ini_get( 'memory_limit' ) )
            );
        }

        foreach ( $warnings as $warning ) {
            printf(
                '<div class="ui yellow message">%s</div>',
                esc_html( $warning )
            );
        }
    }

    /**
     * Render MainWP Backups Footer.
     *
     * @param string $shownPage The page slug shown at this moment.
     */
    public static function render_footer( $shownPage = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature kept for hook compatibility.
        echo '</div>';
    }
}
