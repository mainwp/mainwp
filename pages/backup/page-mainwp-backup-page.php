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

        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content(): void {
        $backups_pages = array( 'ManageBackups', 'ManageBackupsAddNew', 'ManageBackupsStorage', 'ManageBackupsSnapshots' );
        if ( ! isset( $_GET['page'] ) || ! in_array( $_GET['page'], $backups_pages, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }
        ?>
        <p><?php esc_html_e( 'If you need help with backups, please review following help documents', 'mainwp' ); ?></p>
        <div class="ui list">
            <div class="item"><i class="external alternate icon"></i> <a href="https://docs.mainwp.com/sites/backups/manage-backups" target="_blank">Manage Backups</a></div>
            <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank">Backup Extensions</a></div>
            <?php
            /**
             * Action: mainwp_backups_help_item
             *
             * Fires at the bottom of the help articles list in the Help sidebar on the Backups page.
             *
             * Suggested HTML markup:
             *
             * <div class="item"><a href="Your custom URL">Your custom text</a></div>
             *
             * @since 7.0
             */
            do_action( 'mainwp_backups_help_item' );
            ?>
        </div>
        <?php
    }

    /**
     * The display label for a backup task type, escaped for HTML output.
     *
     * The stored type is machine state; only what the user reads goes through
     * here, so an unknown type falls through unchanged rather than being
     * relabelled as a full backup.
     *
     * @param string $type Stored task type.
     *
     * @return string Translated label, or the raw type when unknown.
     */
    public static function backup_type_label( $type ): string {
        switch ( $type ) {
            case 'full':
                return esc_html__( 'Full backup', 'mainwp' );
            case 'db':
                return esc_html__( 'Database backup', 'mainwp' );
            case 'incremental':
                return esc_html__( 'Incremental backup', 'mainwp' );
            case 'files':
                return esc_html__( 'Files only backup', 'mainwp' );
            default:
                return esc_html( (string) $type );
        }
    }

    /**
     * Instantiate the Backups Menu.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_menu(): void {  //phpcs:ignore -- NOSONAR - complex.
        $enable_native_backups = MainWP_Backup_Settings::is_native_backups_enabled();
        $mainwp_primaryBackup  = get_option( 'mainwp_primaryBackup' );

        /**
         * Backups Subpages
         *
         * Filters subpages for the Backups page.
         *
         * @since Unknown
         */
        $customPage = apply_filters_deprecated( 'mainwp-getcustompage-backups', array( false ), '4.0.7.2', 'mainwp_getcustompage_backups' ); // @deprecated Use 'mainwp_getcustompage_backups' instead. NOSONAR - not IP.
        $customPage = apply_filters( 'mainwp_getcustompage_backups', $customPage );

        if ( is_array( $customPage ) && isset( $customPage['slug'] ) && ! empty( $mainwp_primaryBackup ) ) {
            static::$hideSubmenuBackups = true;
            add_submenu_page( 'mainwp_tab', $customPage['title'], '<span id="mainwp-Backups">' . $customPage['title'] . '</span>', 'read', 'ManageBackups' . $customPage['slug'], $customPage['callback'] );
            MainWP_Menu::add_left_menu(
                array(
                    'title'      => $customPage['title'],
                    'parent_key' => 'mainwp_tab',
                    'slug'       => 'ManageBackups' . $customPage['slug'],
                    'href'       => 'admin.php?page=ManageBackups' . $customPage['slug'],
                    'icon'       => '<i class="hdd outline icon"></i>',
                ),
                1
            );

        } elseif ( $enable_native_backups ) {
                    add_submenu_page( 'mainwp_tab', esc_html__( 'Backups', 'mainwp' ), '<span id="mainwp-Backups">' . esc_html__( 'Backups', 'mainwp' ) . '</span>', 'read', 'ManageBackups', array( static::get_class_name(), 'render_manager' ) );
            if ( \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) && ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) {
                add_submenu_page( 'mainwp_tab', esc_html__( 'Add New Schedule', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Add New', 'mainwp' ) . '</div>', 'read', 'ManageBackupsAddNew', array( MainWP_Backup_Task_Form::class, 'render_new' ) );
            }
            if ( \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) && ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsStorage' ) ) {
                add_submenu_page( 'mainwp_tab', esc_html__( 'Backup Storage', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Storage', 'mainwp' ) . '</div>', 'read', 'ManageBackupsStorage', array( MainWP_Backup_Storage_View::class, 'render' ) );
            }
            if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) && ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsSnapshots' ) ) {
                add_submenu_page( 'mainwp_tab', esc_html__( 'Snapshots & Restore', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Snapshots', 'mainwp' ) . '</div>', 'read', 'ManageBackupsSnapshots', array( MainWP_Backup_Snapshots_View::class, 'render' ) );
            }
        } else {
            return;
        }

        /**
         * This hook allows you to add extra sub pages to the Backups page via the 'mainwp-getsubpages-backups' filter.
         *
         * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
         */

        $sub_pages        = array();
        $sub_pages        = apply_filters_deprecated( 'mainwp-getsubpages-backups', array( $sub_pages ), '4.0.7.2', 'mainwp_getsubpages_backups' );  // @deprecated Use 'mainwp_getsubpages_backups' instead. NOSONAR - not IP.
        static::$subPages = apply_filters( 'mainwp_getsubpages_backups', $sub_pages );
        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageBackups' . $subPage['slug'], $subPage['callback'] );
            }
        }
        static::init_left_menu( static::$subPages, $enable_native_backups );
    }

    /**
     * Instantiate Backups subpages.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_subpages_menu(): void {  //phpcs:ignore -- NOSONAR - complex.
        if ( static::$hideSubmenuBackups && ( empty( static::$subPages ) || ! is_array( static::$subPages ) ) ) {
            return;
        }
        ?>
        <div id="menu-mainwp-Backups" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <?php
                    if ( ! static::$hideSubmenuBackups ) {
                        ?>
                        <div class="mainwp_boxoutin"></div>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackups' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Backups', 'mainwp' ); ?></a>
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) { ?>
                            <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) { ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackupsAddNew' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
                            <?php } ?>
                        <?php } ?>
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) { ?>
                            <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsStorage' ) ) { ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackupsStorage' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Storage', 'mainwp' ); ?></a>
                            <?php } ?>
                        <?php } ?>
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) && ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsSnapshots' ) ) { ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackupsSnapshots' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Snapshots & Restore', 'mainwp' ); ?></a>
                        <?php } ?>
                    <?php } ?>
                    <?php
                    if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
                        foreach ( static::$subPages as $subPage ) {
                            if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' . $subPage['slug'] ) ) {
                                    continue;
                            }
                            ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackups' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Instantiate the Backups left menu.
     *
     * @param array $subPages Backup Subpages.
     * @param bool  $enableNativeBackups true|false, whether or not to enable menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_left_menu( $subPages = array(), $enableNativeBackups = true ): void {
        if ( ! static::$hideSubmenuBackups && $enableNativeBackups ) {
            MainWP_Menu::add_left_menu(
                array(
                    'title'      => esc_html__( 'Backups', 'mainwp' ),
                    'parent_key' => 'managesites',
                    'slug'       => 'ManageBackups',
                    'href'       => 'admin.php?page=ManageBackups',
                    'icon'       => '<i class="hdd outline icon"></i>',
                ),
                1
            );

            $init_sub_subleftmenu = array(
                array(
                    'title'      => esc_html__( 'Manage Backups', 'mainwp' ),
                    'parent_key' => 'ManageBackups',
                    'href'       => 'admin.php?page=ManageBackups',
                    'slug'       => 'ManageBackups',
                    'right'      => '',
                ),
                array(
                    'title'      => esc_html__( 'Add New', 'mainwp' ),
                    'parent_key' => 'ManageBackups',
                    'href'       => 'admin.php?page=ManageBackupsAddNew',
                    'slug'       => 'ManageBackupsAddNew',
                    'right'      => 'add_backup_tasks',
                ),
                array(
                    'title'      => esc_html__( 'Storage', 'mainwp' ),
                    'parent_key' => 'ManageBackups',
                    'href'       => 'admin.php?page=ManageBackupsStorage',
                    'slug'       => 'ManageBackupsStorage',
                    'right'      => 'edit_backup_tasks',
                ),
                array(
                    'title'      => esc_html__( 'Snapshots & Restore', 'mainwp' ),
                    'parent_key' => 'ManageBackups',
                    'href'       => 'admin.php?page=ManageBackupsSnapshots',
                    'slug'       => 'ManageBackupsSnapshots',
                    'right'      => 'execute_backups',
                ),
            );

            MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ManageBackups', 'ManageBackups' );

            foreach ( $init_sub_subleftmenu as $item ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                    continue;
                }
                MainWP_Menu::add_left_menu( $item, 2 );
            }
        }
    }

    /**
     * Render the Backups page: the task list, or the edit form when a
     * task id is requested.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_tasks_for_user()
     * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::can_edit_backuptask()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::validate_backup_tasks()
     */
    public static function render_manager(): void {  //phpcs:ignore -- NOSONAR - complex.
        $backupTask = null;
        //phpcs:disable WordPress.Security.NonceVerification.Recommended
        // The edit screen is addressed by `task_id`; a bare `id` also switches
        // the dashboard chrome into single-site mode. Old `&id=` links still
        // open the right task, they just keep the site panel.
        $backupTaskId = 0;
        if ( isset( $_GET['task_id'] ) ) {
            $backupTaskId = intval( $_GET['task_id'] );
        } elseif ( isset( $_GET['id'] ) ) {
            $backupTaskId = intval( $_GET['id'] );
        }

        if ( $backupTaskId > 0 ) {
            if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
                \mainwp_do_not_have_permissions( esc_html__( 'edit backup tasks', 'mainwp' ) );
                return;
            }

            $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
            if ( ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $backupTask ) ) {
                $backupTask = null;
            }

            if ( ! empty( $backupTask ) && ! MainWP_Manage_Backups_Handler::validate_backup_tasks( array( $backupTask ) ) ) {
                $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
            }
        }

        $primary_methods      = array();
        $primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead. NOSONAR - not IP.
        $primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

        if ( ! is_array( $primaryBackupMethods ) ) {
            $primaryBackupMethods = array();
        }

        if ( null === $backupTask ) {

            $backup_items = MainWP_DB_Backup::instance()->get_backup_tasks_for_user();
            if ( ! MainWP_Manage_Backups_Handler::validate_backup_tasks( $backup_items ) ) {
                $backup_items = MainWP_DB_Backup::instance()->get_backup_tasks_for_user();
            }

            static::render_header( '' );
            ?>
            <?php if ( 0 === count( $primaryBackupMethods ) ) { ?>
                <div class="ui blue message"><?php /* translators: 1: opening anchor tag, 2: closing anchor tag */ printf( esc_html__( 'Did you know that MainWP has extensions for working with popular backup plugins? Visit the %1$sextensions site%2$s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank">', '</a>' ); // NOSONAR - noopener - open safe. ?></div>
            <?php } ?>
            <div class="ui alt segment">
                <div id="mainwp_managebackups_add_message" class="ui green message" style="display:
                <?php
                if ( isset( $_GET['a'] ) && '1' === $_GET['a'] ) {
                    echo 'block';
                } else {
                    echo 'none';
                }
                ?>
                ">
                <?php
                if ( isset( $_GET['a'] ) && '1' === $_GET['a'] ) {
                    echo '<p>' . esc_html__( 'The backup task was added successfully', 'mainwp' ) . '</p>';
                }
                ?>
                    </div>

                <form method="post" class="mainwp-table-container">
                    <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                    <?php
                    MainWP_UI::render_modal_edit_notes();
                    MainWP_Backup_List_View::instance()->display( $backup_items );
                    ?>
                </form>
            </div>

            <div class="ui modal" id="managebackups-task-status-box" tabindex="0">
                <div class="header"><?php esc_html_e( 'Running Task', 'mainwp' ); ?></div>
                    <div class="content mainwp-modal-content"></div>
                    <div class="actions mainwp-modal-actions">
                        <input id="managebackups-task-status-close" type="button" name="Close" value="<?php esc_attr_e( 'Cancel', 'mainwp' ); ?>" class="button" />
                    </div>
                </div>
            <?php
            static::render_footer( '' );
        } else {
            MainWP_Backup_Task_Form::render_edit( $backupTask );
        }
        //phpcs:enable
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

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsStorage' ) ) {
            $renderItems[] = array(
                'title'    => esc_html__( 'Storage', 'mainwp' ),
                'href'     => 'admin.php?page=ManageBackupsStorage',
                'access'   => \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ),
                'active'   => ( 'Storage' === $shownPage ) ? true : false,
                'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsStorage' ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsSnapshots' ) ) {
            $renderItems[] = array(
                'title'    => esc_html__( 'Snapshots & Restore', 'mainwp' ),
                'href'     => 'admin.php?page=ManageBackupsSnapshots',
                'access'   => \mainwp_current_user_can( 'dashboard', 'execute_backups' ),
                'active'   => ( 'Snapshots' === $shownPage ) ? true : false,
                'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsSnapshots' ) ? true : false,
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
