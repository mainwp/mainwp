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

    /** Register the Backups page header, footer, and help sidebar hooks. */
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
             * @since 6.3
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
     * Render a page's dismissible intro message, the way core list pages
     * open.
     *
     * @param string $notice_id Per-user dismissal key.
     * @param string $text      Plain message text.
     */
    public static function render_intro_message( string $notice_id, string $text ): void {
        if ( ! MainWP_Utility::show_mainwp_message( 'notice', $notice_id ) ) {
            return;
        }
        ?>
        <div class="ui info message">
            <i class="close icon mainwp-notice-dismiss" notice-id="<?php echo esc_attr( $notice_id ); ?>"></i>
            <?php echo esc_html( $text ); ?>
        </div>
        <?php
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
         * @since 4.0.7.2
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
            $menu_label = \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ? '<span id="mainwp-Backups">' . esc_html__( 'Backups', 'mainwp' ) . '</span>' : '<div class="mainwp-hidden">' . esc_html__( 'Backups', 'mainwp' ) . '</div>';
            add_submenu_page( 'mainwp_tab', esc_html__( 'Backups', 'mainwp' ), $menu_label, 'read', 'ManageBackups', array( static::get_class_name(), 'render_manager' ) );
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
            // Bookmarks, emails and help links point at Manage Backups, so it
            // stays reachable and explains how to turn the feature on instead
            // of answering with the WordPress permission error. The other
            // backup pages stay unregistered.
            add_submenu_page( 'mainwp_tab', esc_html__( 'Backups', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Backups', 'mainwp' ) . '</div>', 'read', 'ManageBackups', array( static::get_class_name(), 'render_manager' ) );
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
                if ( ! is_array( $subPage ) || empty( $subPage['slug'] ) || ! isset( $subPage['title'], $subPage['callback'] ) || ! is_callable( $subPage['callback'] ) ) {
                    continue;
                }
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
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackups' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Backups', 'mainwp' ); ?></a>
                        <?php } ?>
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
                            if ( ! is_array( $subPage ) || empty( $subPage['slug'] ) || ! isset( $subPage['title'], $subPage['callback'] ) || ! is_callable( $subPage['callback'] ) ) {
                                continue;
                            }
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
                    'right'      => 'execute_backups',
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
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            static::render_native_backups_off();
            return;
        }

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

            // Back Up Now keeps one hidden task per site. It is not in the
            // list and has no schedule, so the schedule form must not open
            // it: saving that form would turn it into a daily task.
            if ( ! empty( $backupTask ) && ! empty( $backupTask->template ) ) {
                static::render_on_demand_task_refusal( $backupTask );
                return;
            }

            if ( ! empty( $backupTask ) && ! MainWP_Manage_Backups_Handler::validate_backup_tasks( array( $backupTask ) ) ) {
                $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
            }
        }

        if ( null === $backupTask ) {
            if ( ! MainWP_Abilities_Util::current_user_has_operation_right( 'execute_backups' ) ) {
                \mainwp_do_not_have_permissions( esc_html__( 'manage backups', 'mainwp' ) );
                return;
            }

            $backup_items = MainWP_DB_Backup::instance()->get_backup_tasks_for_user();
            if ( ! MainWP_Manage_Backups_Handler::validate_backup_tasks( $backup_items ) ) {
                $backup_items = MainWP_DB_Backup::instance()->get_backup_tasks_for_user();
            }

            static::render_header( '' );
            if ( ! empty( $backup_items ) ) {
                self::render_task_list_actions_bar();
            }
            ?>
            <div class="ui padded segment">
                <?php static::render_intro_message( 'mainwp-backup-tasks-info-message', __( 'Scheduled backup tasks run from this Dashboard on the child sites you pick. To back up one site right away, use Back Up Now on the Snapshots & Restore page.', 'mainwp' ) ); ?>
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

                <?php if ( empty( $backup_items ) ) : ?>
                    <?php static::render_task_list_empty_state(); ?>
                <?php else : ?>
                <form method="post" class="mainwp-table-container">
                    <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                    <?php
                    MainWP_UI::render_modal_edit_notes();
                    MainWP_Backup_List_View::instance()->display( $backup_items );
                    ?>
                </form>
                <?php endif; ?>
            </div>

            <div class="ui modal" id="managebackups-task-status-box" tabindex="0">
                <div class="header"><?php esc_html_e( 'Running Task', 'mainwp' ); ?></div>
                    <div class="content mainwp-modal-content"></div>
                    <div class="actions mainwp-modal-actions">
                        <input id="managebackups-task-status-close" type="button" name="Close" value="<?php esc_attr_e( 'Close', 'mainwp' ); ?>" class="ui button" />
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
     * Manage Backups with native backups turned off: a pointer to the
     * setting in place of the task list.
     */
    public static function render_native_backups_off(): void {
        MainWP_UI::render_top_header( array( 'title' => esc_html__( 'Backups', 'mainwp' ) ) );
        ?>
        <div class="ui alt segment">
            <div class="ui yellow message" id="mainwp-native-backups-off-message">
                <?php esc_html_e( 'Native backups are turned off.', 'mainwp' ); ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) && ! MainWP_System_Utility::is_team_control_staff() ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=Settings#mainwp_options_enableNativeBackups' ) ); ?>"><?php esc_html_e( 'Turn them on in the Backup Settings', 'mainwp' ); ?></a>
                <?php else : ?>
                    <?php esc_html_e( 'Ask a Dashboard administrator to turn them on in the Backup Settings.', 'mainwp' ); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        static::render_footer( '' );
    }

    /**
     * Refuse to open Back Up Now's hidden per-site task in the schedule form.
     *
     * @param object $task Hidden on-demand task row.
     */
    private static function render_on_demand_task_refusal( $task ): void {
        $site_id = (int) strtok( (string) $task->sites, ',' );
        $url     = admin_url( 'admin.php?page=ManageBackupsSnapshots' . ( $site_id > 0 ? '&site_id=' . $site_id : '' ) );
        static::render_header( 'ManageBackupsEdit' );
        ?>
        <div class="ui alt segment">
            <div class="ui yellow message" id="mainwp-backup-task-on-demand-refusal">
                <?php esc_html_e( 'This task holds the backups made with Back Up Now. It has no schedule to edit.', 'mainwp' ); ?>
                <a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Go to Snapshots & Restore', 'mainwp' ); ?></a>
            </div>
        </div>
        <?php
        static::render_footer( 'ManageBackupsEdit' );
    }

    /**
     * Page-level controls above the task list, in the core actions bar.
     * The empty list leaves it out: its own first-step buttons already
     * offer a new task.
     */
    private static function render_task_list_actions_bar(): void {
        if ( ! \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) || MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) {
            return;
        }
        ?>
        <div class="mainwp-actions-bar">
            <div class="ui mini form">
                <div class="ui stackable grid">
                    <div class="ui two column row">
                        <div class="middle aligned column">
                            <a class="ui mini green button" id="mainwp-backup-add-new-task" href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackupsAddNew' ) ); ?>"><?php esc_html_e( 'Add New Task', 'mainwp' ); ?></a>
                        </div>
                        <div class="right aligned middle aligned column"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Empty task list: point at the two things a first backup needs.
     */
    private static function render_task_list_empty_state(): void {
        $actions = '';
        if ( \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) {
            $actions .= '<a class="ui mini green button" href="' . esc_url( admin_url( 'admin.php?page=ManageBackupsAddNew' ) ) . '">' . esc_html__( 'Create your first backup task', 'mainwp' ) . '</a> ';
        }
        if ( \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            $actions .= '<a class="ui mini basic button" href="' . esc_url( admin_url( 'admin.php?page=ManageBackupsStorage' ) ) . '">' . esc_html__( 'Add a storage destination', 'mainwp' ) . '</a>';
        }
        ?>
        <div id="mainwp-backup-tasks-empty">
            <?php MainWP_UI::render_empty_page_placeholder( esc_html__( 'No backup tasks yet', 'mainwp' ), '' !== $actions ? $actions : esc_html__( 'Ask a Dashboard administrator to create a backup task.', 'mainwp' ), '<i class="massive hdd outline icon"></i>' ); ?>
        </div>
        <?php
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
                if ( ! is_array( $subPage ) || empty( $subPage['slug'] ) || ! isset( $subPage['title'], $subPage['callback'] ) || ! is_callable( $subPage['callback'] ) ) {
                    continue;
                }
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
     * that will affect backups: low disk space, an unusable private root
     * configuration, or a low PHP memory limit.
     *
     * @return void
     */
    public static function render_backup_environment_notices(): void {
        $warnings = array();

        $backup_dir = MainWP_Backup_Private_Root::resolve();
        if ( is_wp_error( $backup_dir ) ) {
            $warnings[] = $backup_dir->get_error_message();
        } else {
            $reason = MainWP_Backup_Private_Root::availability_reason();
            if ( '' !== $reason ) {
                $warnings[] = sprintf(
                    /* translators: 1: Directory path, 2: Failure reason, 3: wp-config.php constant definition. */
                    __( 'Private backup directory "%1$s" is unavailable: %2$s. Add this line to wp-config.php: %3$s', 'mainwp' ),
                    $backup_dir,
                    $reason,
                    "define( 'MAINWP_BACKUP_PRIVATE_DIR', '/absolute/path/outside/the/web/root' );"
                );
            }
        }
        if ( ! is_wp_error( $backup_dir ) && function_exists( 'disk_free_space' ) && is_dir( $backup_dir ) ) {
            $free = @disk_free_space( $backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
            if ( false !== $free && $free < 500 * MB_IN_BYTES ) {
                $warnings[] = sprintf(
                    /* translators: %s: free disk space */
                    esc_html__( 'Low disk space: %s available. Large backups may fail.', 'mainwp' ),
                    size_format( $free )
                );
            }
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
