<?php
/**
 * Manage Sites List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites_List_Table
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites_List_Table { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Protected variable to hold User extension.
     *
     * @var mixed Default null.
     */
    protected $userExtension = null;

    /**
     * Public variable to hold Items information.
     *
     * @var array
     */
    public $items;

    /**
     * Public variable to hold total items number.
     *
     * @var integer
     */
    public $total_items;

    /**
     * Protected variable to hold columns headers
     *
     * @var array
     */
    protected $column_headers;

    /**
     * Public variable to hold primary backup method.
     *
     * @var string
     */
    public $primary_backup = null;

    /**
     * Public variable.
     *
     * @var bool
     */
    public $site_health_disabled = false;

    /**
     * MainWP_Manage_Sites_List_Table constructor.
     *
     * Run each time the class is called.
     * Add action to generate tabletop.
     */
    public function __construct() {
        add_action( 'mainwp_managesites_tabletop', array( &$this, 'generate_tabletop' ) );
        $this->site_health_disabled = get_option( 'mainwp_disableSitesHealthMonitoring', 1 ) ? true : false;  // disabled by default.
    }

    /**
     * Get the default primary column name.
     *
     * @return string Child Site name.
     */
    protected function get_default_primary_column_name() {
        return 'site_combo';
    }

    /**
     * Method column_backup()
     *
     * Backup Column.
     *
     * @param mixed $item List of backups.
     *
     * @return mixed $backupnow_lnk.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Handler::is_archive()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    public function column_backup( $item ) { // phpcs:ignore -- NOSONAR - complex.
        if ( null === $this->primary_backup ) {
            $this->primary_backup = MainWP_System_Utility::get_primary_backup();
        }

        $backup_method = empty( $item['primary_backup_method'] ) || 'global' === $item['primary_backup_method'] ? $this->primary_backup : $item['primary_backup_method'];

        $lastBackup = MainWP_DB::instance()->get_website_option( $item, 'primary_lasttime_backup' );

        /**
         * Filter: mainwp_managesites_getbackuplink
         *
         * Filters the link for the last backup item.
         *
         * @param int    $item['id'] Child site ID.
         * @param string $lastBackup Last backup timestamp for the child site.
         *
         * @since Unknown
         */
        $backupnow_lnk = apply_filters( 'mainwp_managesites_getbackuplink', '', $item['id'], $lastBackup, $backup_method );

        if ( ! empty( $backupnow_lnk ) ) {
            return $backupnow_lnk;
        }

        $dir        = MainWP_System_Utility::get_mainwp_specific_dir( $item['id'] );
        $lastbackup = 0;

        $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        if ( $hasWPFileSystem && $wp_filesystem->exists( $dir ) ) {
            $dh = opendir( $dir );
            if ( $dh ) {
                while ( false !== ( $file = readdir( $dh ) ) ) {
                    if ( '.' !== $file && '..' !== $file ) {
                        $theFile = $dir . $file;
                        if ( MainWP_Backup_Handler::is_archive( $file ) && ! MainWP_Backup_Handler::is_sql_archive( $file ) && $wp_filesystem->mtime( $theFile ) > $lastbackup ) {
                            $lastbackup = $wp_filesystem->mtime( $theFile );
                        }
                    }
                }
                closedir( $dh );
            }
        }

        $output = '';
        if ( 0 < $lastbackup ) {
            $output = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $lastbackup ) ) . '<br />';
        } else {
            $output = '<div class="ui red label">Never</div><br/>';
        }

        return $output;
    }

    /**
     * Method column_site_preview()
     *
     * Site preview column.
     *
     * @param mixed $item List of backups.
     *
     * @return mixed preview content.
     */
    public function column_site_preview( $item ) {
        return '<span class="mainwp-preview-item ui mini grey icon basic button" data-position="left center" data-inverted="" data-tooltip="' . esc_html__( 'Click to see the site homepage screenshot.', 'mainwp' ) . '" preview-site-url="' . esc_url( $item['url'] ) . '" ><i class="camera icon"></i></span>';
    }

    /**
     * Set the column names.
     *
     * @param mixed  $item MainWP Sitetable Item.
     * @param string $column_name Column name to use.
     *
     * @return string Column Name.
     */
    public function column_default( $item, $column_name ) { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * Filter is being replaced with mainwp_sitestable_item
         *
         * @deprecated
         */
        $item = apply_filters_deprecated( 'mainwp-sitestable-item', array( $item, $item ), '4.0.7.2', 'mainwp_sitestable_item' ); // NOSONAR - not IP.

        /**
         * Filter: mainwp_sitestable_item
         *
         * Filters the Manage Sites table column items. Allows user to create new column item.
         *
         * @param array $item Array containing child site data.
         *
         * @since Unknown
         */
        $item = apply_filters( 'mainwp_sitestable_item', $item, $column_name );

        switch ( $column_name ) {
            case 'status':
            case 'favicon':
            case 'site_combo':
            case 'site':
            case 'login':
            case 'url':
            case 'tags':
            case 'update':
            case 'wpcore_update':
            case 'plugin_update':
            case 'theme_update':
            case 'backup':
            case 'security':
            case 'uptime':
            case 'last_sync':
            case 'last_post':
            case 'site_health':
            case 'status_code':
            case 'notes':
            case 'phpversion':
            case 'language':
            case 'index':
            case 'site_actions':
                return '';
            default:
                return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
        }
    }

    /**
     * Get sortable columns.
     *
     * @return array $sortable_columns Array of sortable column names.
     */
    public function get_sortable_columns() {
        $sortable_columns = array(
            'site_combo'     => array( 'site_combo', false ),
            'site'           => array( 'site', false ),
            'url'            => array( 'url', false ),
            'tags'           => array( 'tags', false ),
            'client_name'    => array( 'client_name', false ),
            'security'       => array( 'security', false ),
            'last_sync'      => array( 'last_sync', false ),
            'last_post'      => array( 'last_post', false ),
            'site_health'    => array( 'site_health', false ),
            'status_code'    => array( 'status_code', false ),
            'notes'          => array( 'notes', false ),
            'phpversion'     => array( 'phpversion', false ),
            'update'         => array( 'update', false ),
            'added_datetime' => array( 'added_datetime', false ),
            'backup'         => array( 'backup', false ),
        );

        if ( ! MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() ) {
            unset( $sortable_columns['status_code'] );
        }

        // disable this hook for sortable columns.
        // $sortable_columns = apply_filters( 'mainwp_sitestable_sortable_columns', $sortable_columns ); //.

        return $sortable_columns;
    }

    /**
     * Default Manage Sites table columns.
     *
     * Returns the array of default columns for the Manage Sites table.
     *
     * @return array Array of default columns names.
     */
    public function get_default_columns() {
        $columns = array(
            'cb'             => '<input type="checkbox" />',
            'status'         => '',
            'favicon'        => '',
            'site_combo'     => esc_html__( 'Site', 'mainwp' ),
            'site'           => esc_html__( 'Site Title', 'mainwp' ),
            'login'          => '<i class="sign in alternate icon"></i>',
            'url'            => esc_html__( 'URL', 'mainwp' ),
            'tags'           => esc_html__( 'Tags', 'mainwp' ),
            'update'         => esc_html__( 'Updates', 'mainwp' ),
            'wpcore_update'  => '<i class="icon wordpress"></i>', // phpcs:ignore -- Prevent modify WP icon.
            'plugin_update'  => '<i class="plug icon"></i>',
            'theme_update'   => '<i class="tint icon"></i>',
            'client_name'    => esc_html__( 'Client', 'mainwp' ),
            'security'       => esc_html__( 'Security', 'mainwp' ),
            'language'       => esc_html__( 'Language', 'mainwp' ),
            'index'          => esc_html__( 'Indexable', 'mainwp' ),
            'uptime'         => esc_html__( 'Uptime', 'mainwp' ),
            'last_sync'      => esc_html__( 'Last Sync', 'mainwp' ),
            'backup'         => esc_html__( 'Backups', 'mainwp' ),
            'phpversion'     => esc_html__( 'PHP', 'mainwp' ),
            'last_post'      => esc_html__( 'Last Post', 'mainwp' ),
            'site_health'    => esc_html__( 'Health', 'mainwp' ),
            'status_code'    => esc_html__( 'HTTP Status', 'mainwp' ),
            'added_datetime' => esc_html__( 'Connected', 'mainwp' ),
            'site_preview'   => '<i class="camera icon"></i>',
            'notes'          => esc_html__( 'Notes', 'mainwp' ),
        );

        if ( ! MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() ) {
            unset( $columns['status_code'] );
            unset( $columns['uptime'] );
        }

        if ( $this->site_health_disabled ) {
            unset( $columns['site_health'] );
        }

        return $columns;
    }

    /**
     * Method get_columns()
     *
     * Combine all columns.
     *
     * @return array $columns Array of column names.
     */
    public function get_columns() {

        $columns = $this->get_default_columns();

        /**
         * Filter is being replaced with mainwp_sitestable_getcolumns
         *
         * @deprecated
         */
        $columns = apply_filters_deprecated( 'mainwp-sitestable-getcolumns', array( $columns, $columns ), '4.0.7.2', 'mainwp_sitestable_getcolumns' ); // NOSONAR - not IP.

        /**
         * Filter: mainwp_sitestable_getcolumns
         *
         * Filters the Manage Sites table columns. Allows user to create a new column.
         *
         * @param array $columns Array containing table columns.
         *
         * @since Unknown
         */
        $columns = apply_filters( 'mainwp_sitestable_getcolumns', $columns, $columns );

        $columns['site_actions'] = '';

        $disable_backup = false;
        $primaryBackup  = get_option( 'mainwp_primaryBackup' );

        $primary_methods = array();
        $primary_methods = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' ); // NOSONAR - not IP.

        /** This filter is documented in ../pages/page-mainwp-server-information-handler.php */
        $primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

        if ( empty( $primaryBackup ) ) {
            if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
                $disable_backup = true;
            }
        } elseif ( ! is_array( $primaryBackupMethods ) || empty( $primaryBackupMethods ) ) {
            $disable_backup = true;
        }

        if ( $disable_backup && isset( $columns['backup'] ) ) {
            unset( $columns['backup'] );
        }

        return $columns;
    }

    /**
     * Instantiate Columns.
     *
     * @return array $init_cols
     */
    public function get_columns_init() {
        $cols      = $this->get_columns();
        $init_cols = array();
        foreach ( $cols as $key => $val ) {
            $init_cols[] = array( 'data' => esc_html( $key ) );
        }
        return $init_cols;
    }

    /**
     * Get column defines.
     *
     * @return array $defines
     */
    public function get_columns_defines() {
        $defines   = array();
        $defines[] = array(
            'targets'   => 'no-sort',
            'orderable' => false,
        );
        $defines[] = array(
            'targets'   => 'manage-cb-column',
            'className' => 'check-column collapsing',
        );
        $defines[] = array(
            'targets'   => 'manage-site-column',
            'className' => 'column-site-bulk mainwp-site-cell collapsing',
        );
        $defines[] = array(
            'targets'   => 'manage-url-column',
            'className' => 'mainwp-url-cell collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-last_sync-column', 'manage-last_post-column', 'manage-site_actions-column', 'extra-column' ),
            'className' => 'collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-status_code-column' ),
            'className' => 'left aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-client_name-column' ),
            'className' => 'left aligned',
        );
        $defines[] = array(
            'targets'   => array( 'manage-security-column' ),
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-login-column', 'manage-wpcore_update-column', 'manage-plugin_update-column', 'manage-theme_update-column', 'manage-backup-column' ),
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => 'manage-update-column',
            'className' => 'cented aligned collapsing',
        );
        $defines[] = array(
            'targets'   => 'manage-favicon-column',
            'className' => 'collapsing',
        );
        $defines[] = array(
            'targets'   => 'manage-atarim_tasks-column',
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-notes-column' ),
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-site_preview-column' ),
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-site_health-column' ),
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-phpversion-column', 'manage-status-column' ),
            'className' => 'collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-uptime-column' ),
            'className' => 'right aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-language-column' ),
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-index-column' ),
            'className' => 'center aligned collapsing',
        );
        $defines[] = array(
            'targets'   => array( 'manage-site_actions-column' ),
            'className' => 'collapsing not-selectable',
        );
        return $defines;
    }

    /**
     * Method generate_tabletop()
     *
     * Run the render_manage_sites_table_top menthod.
     */
    public function generate_tabletop() {
        $this->render_manage_sites_table_top();
    }

    /**
     * Create Bulk Actions Drop Down.
     *
     * @return array $actions Available bulk actions.
     */
    public function get_bulk_actions() {

        $actions = array(
            'sync'                   => esc_html__( 'Sync Data', 'mainwp' ),
            'reconnect'              => esc_html__( 'Reconnect', 'mainwp' ),
            'suspend'                => esc_html__( 'Suspend', 'mainwp' ),
            'unsuspend'              => esc_html__( 'Unsuspend', 'mainwp' ),
            'refresh_favico'         => esc_html__( 'Reload Favicon', 'mainwp' ),
            'delete'                 => esc_html__( 'Remove', 'mainwp' ),
            'open_wpadmin'           => esc_html__( 'Go to WP Admin', 'mainwp' ),
            'open_frontpage'         => esc_html__( 'Go to Site', 'mainwp' ),
            'update_plugins'         => esc_html__( 'Update Plugins', 'mainwp' ),
            'update_themes'          => esc_html__( 'Update Themes', 'mainwp' ),
            'update_wpcore'          => esc_html__( 'Update WordPress', 'mainwp' ),
            'update_translations'    => esc_html__( 'Update Translations', 'mainwp' ),
            'update_everything'      => esc_html__( 'Update Everything', 'mainwp' ),
            'check_abandoned_plugin' => esc_html__( 'Check Abandoned Plugins', 'mainwp' ),
            'check_abandoned_theme'  => esc_html__( 'Check Abandoned Phemes', 'mainwp' ),
        );

        /**
         * Filter: mainwp_managesites_bulk_actions
         *
         * Filters bulk actions on the Manage Sites page. Allows user to hook in new actions or remove default ones.
         *
         * @since Unknown
         */
        return apply_filters( 'mainwp_managesites_bulk_actions', $actions );
    }

    /**
     * Render Manage Sites Table Top.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::instance()::get_groups_for_manage_sites()
     */
    public function render_manage_sites_table_top() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.
        $items_bulk        = $this->get_bulk_actions();
        $filters_row_style = 'display:none';

        // phpcs:disable WordPress.Security.NonceVerification,Missing,Missing,Missing,Missing,Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $selected_status           = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
        $selected_group            = isset( $_REQUEST['g'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) : '';
        $selected_client           = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
        $is_not                    = isset( $_REQUEST['isnot'] ) && ( 'yes' === $_REQUEST['isnot'] ) ? true : false;
        $selected_one_time_siteids = isset( $_REQUEST['selected_sites'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['selected_sites'] ) ) : '';
        $reset_filter              = isset( $_REQUEST['reset'] ) && ( 'yes' === $_REQUEST['reset'] ) ? true : false;
        // phpcs:enable WordPress.Security.NonceVerification,Missing,Missing,Missing,Missing,Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! empty( $selected_one_time_siteids ) ) {
            MainWP_Utility::update_user_option( 'mainwp_managesites_filter_onetime_selected_siteids', $selected_one_time_siteids );
        }

        if ( ! $reset_filter && empty( $selected_status ) && empty( $selected_group ) && empty( $selected_client ) && empty( $selected_one_time_siteids ) ) {
            $selected_status = get_user_option( 'mainwp_managesites_filter_status' );
            $selected_group  = get_user_option( 'mainwp_managesites_filter_group' );
            $selected_client = get_user_option( 'mainwp_managesites_filter_client' );
            $is_not          = get_user_option( 'mainwp_managesites_filter_is_not' );
        }

        $default_filter = true;
        if ( ! empty( $is_not ) || ( ! empty( $selected_status ) && 'all' !== $selected_status ) || ! empty( $selected_group ) || ! empty( $selected_client ) ) {
            $filters_row_style = 'display:flex';
            $default_filter    = false;
        }

        ?>
        <div class="ui stackable grid">
            <div class="eight wide middle aligned column">
                <div id="mainwp-sites-bulk-actions-menu" class="ui selection mini dropdown">
                    <div class="default text"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></div>
                    <i class="dropdown icon"></i>
                    <div class="menu">
                        <?php foreach ( $items_bulk as $value => $title ) : ?>
                            <div class="item" data-value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $title ); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="ui mini basic button" id="mainwp-do-sites-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
            </div>

            <div class="eight wide right aligned middle aligned column">
                <?php static::render_page_navigation_left_items(); ?>
            </div>
        </div>

        <div class="ui stackable grid" id="mainwp-sites-filters-row" style="<?php echo esc_attr( $filters_row_style ); ?>">
            <div class="twelve wide column">
                <div class="ui selection mini compact dropdown seg_is_not" id="mainwp_is_not_site">
                    <input type="hidden" value="<?php echo $is_not ? 'yes' : ''; ?>">
                    <i class="dropdown icon"></i>
                    <div class="default text"><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
                    <div class="menu">
                        <div class="item" data-value=""><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
                        <div class="item" data-value="yes"><?php esc_html_e( 'Is not', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-filter-sites-group" class="ui selection multiple mini dropdown seg_site_tags">
                    <input type="hidden" value="<?php echo esc_html( $selected_group ); ?>">
                    <i class="dropdown icon"></i>
                    <div class="default text"><?php esc_html_e( 'All tags', 'mainwp' ); ?></div>
                    <div class="menu">
                        <?php
                        $groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
                        foreach ( $groups as $group ) {
                            ?>
                            <div class="item" data-value="<?php echo intval( $group->id ); ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></div>
                            <?php
                        }
                        ?>
                        <div class="item" data-value="nogroups"><?php esc_html_e( 'No Tags', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div class="ui selection mini dropdown seg_site_status" id="mainwp-filter-sites-status">
                    <input type="hidden" value="<?php echo esc_html( $selected_status ); ?>">
                    <div class="default text"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                    <i class="dropdown icon"></i>
                    <div class="menu">
                        <div class="item" data-value="all" ><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                        <div class="item" data-value="connected"><?php esc_html_e( 'Connected', 'mainwp' ); ?></div>
                        <div class="item" data-value="disconnected"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></div>
                        <div class="item" data-value="update"><?php esc_html_e( 'Available update', 'mainwp' ); ?></div>
                        <div class="item" data-value="sitehealthnotgood"><?php esc_html_e( 'Site Health needs improvement', 'mainwp' ); ?></div>
                        <div class="item" data-value="phpver7"><?php esc_html_e( 'PHP Ver < 7.0', 'mainwp' ); ?></div>
                        <div class="item" data-value="phpver8"><?php esc_html_e( 'PHP Ver < 8.0', 'mainwp' ); ?></div>
                        <div class="item" data-value="suspended"><?php esc_html_e( 'Suspended', 'mainwp' ); ?></div>
                    </div>
                </div>
                <div id="mainwp-filter-clients" class="ui selection mini multiple dropdown seg_site_clients">
                    <input type="hidden" value="<?php echo esc_html( $selected_client ); ?>">
                    <i class="dropdown icon"></i>
                    <div class="default text"><?php esc_html_e( 'All clients', 'mainwp' ); ?></div>
                    <div class="menu">
                        <?php
                        $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
                        foreach ( $clients as $client ) {
                            ?>
                            <div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( stripslashes( $client->name ) ); ?></div>
                            <?php
                        }
                        ?>
                        <div class="item" data-value="noclients"><?php esc_html_e( 'No Client', 'mainwp' ); ?></div>
                    </div>
                </div>
                <button onclick="mainwp_manage_sites_filter()" class="ui mini green button"><?php esc_html_e( 'Filter Sites', 'mainwp' ); ?></button>
                <button onclick="mainwp_manage_sites_reset_filters(this)" id="mainwp_manage_sites_reset_filters" class="ui mini button" <?php echo $default_filter ? 'disabled="disabled"' : ''; ?>><?php esc_html_e( 'Reset Filters', 'mainwp' ); ?></button>
            </div>
            <?php MainWP_Manage_Sites_Filter_Segment::get_instance()->render_filters_segment(); ?>

        </div>
        <?php
        MainWP_UI::render_modal_save_segment();
    }


    /**
     * Method render_page_navigation_left_items()
     *
     * Render page navigation left items.
     */
    public static function render_page_navigation_left_items() {
        $siteViewMode = MainWP_Utility::get_siteview_mode();
        $nonce        = wp_create_nonce( 'viewmode' );
        ?>
        <span data-tooltip="<?php esc_html_e( 'Switch between Table or Grid view.', 'mainwp' ); ?>" data-position="bottom right" data-inverted="">
            <div class="ui mini buttons view-mode-manage-site">
                <a class="ui button basic <?php echo 'table' === $siteViewMode ? 'disabled' : ''; ?> icon" href="admin.php?page=managesites&viewmode=table&modenonce=<?php echo esc_html( $nonce ); ?>">
                    <i class="bars icon"></i>
                </a>
                <a class="ui button basic <?php echo 'grid' === $siteViewMode ? 'disabled' : ''; ?> icon" href="admin.php?page=managesites&viewmode=grid&modenonce=<?php echo esc_html( $nonce ); ?>">
                    <i class="grid layout icon"></i>
                </a>
            </div>
        </span>
        <span data-tooltip="<?php esc_html_e( 'Click to filter sites.', 'mainwp' ); ?>" data-position="bottom right" data-inverted="">
            <a href="#" class="ui mini icon basic button" id="mainwp-manage-sites-filter-toggle-button">
                <i class="filter icon"></i> <?php esc_html_e( 'Filter Sites', 'mainwp' ); ?>
            </a>
        </span>
        <?php
    }

    /**
     * Html output if no Child Sites are connected.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_websites_count()
     */
    public function no_items() {
        ?>
        <div class="ui center aligned segment">
            <i class="globe massive icon"></i>
            <div class="ui header">
                <?php esc_html_e( 'No websites connected to the MainWP Dashboard yet.', 'mainwp' ); ?>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>" class="ui big green button"><?php esc_html_e( 'Connect Your WordPress Sites', 'mainwp' ); ?></a>
            <div class="ui sub header">
                <?php printf( esc_html__( 'If all your child sites are missing from your MainWP Dashboard, please check this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/all-child-sites-disappeared-from-my-mainwp-dashboard/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
            </div>
        </div>
        <?php
    }

    /**
     * Method has_items().
     *
     * Verify if items exist.
     */
    public function has_items() {
        return ! empty( $this->items );
    }

    /**
     * Prepare the items to be listed.
     *
     * @param bool $optimize true|false Whether or not to optimize.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::instance()::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::query()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_sql_search_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::num_rows()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function prepare_items( $optimize = true ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        if ( null === $this->userExtension ) {
            $this->userExtension = MainWP_DB_Common::instance()->get_user_extension();
        }

        $orderby = 'wp.name';

        $req_orderby = null;
        $req_order   = null;

        $extra_view = apply_filters( 'mainwp_sitestable_prepare_extra_view', array( 'favi_icon', 'health_site_status' ) );
        $extra_view = array_unique( $extra_view );

        if ( $optimize ) {
            // phpcs:disable WordPress.Security.NonceVerification,Missing,Missing,Missing,Missing,Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( isset( $_REQUEST['order'] ) ) {
                $order_values = MainWP_Utility::instance()->get_table_orders( $_REQUEST );
                $req_orderby  = $order_values['orderby'];
                $req_order    = $order_values['order'];
            }
            // phpcs:enable
            if ( isset( $req_orderby ) ) {
                if ( 'site' === $req_orderby ) {
                    $orderby = 'wp.name ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'site_combo' === $req_orderby ) {
                    $orderby = 'wp.name ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'url' === $req_orderby ) {
                    $orderby = 'wp.url ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'tags' === $req_orderby ) {
                    $orderby = 'GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'update' === $req_orderby ) {
                    $orderby = 'CASE true
                                            WHEN (offline_check_result = -1)
                                                THEN 2
                                            WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                                THEN 3
                                            ELSE 4
                                                + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
                                                + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
                                                + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
                                            END ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'phpversion' === $req_orderby ) {
                    $orderby = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'status' === $req_orderby ) {
                    $orderby = 'CASE true
                                            WHEN (offline_check_result = -1)
                                                THEN 2
                                            WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                                THEN 3
                                            ELSE 4
                                                + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
                                                + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
                                                + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
                                            END ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'last_post' === $req_orderby ) {
                    $orderby = 'wp_sync.last_post_gmt ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'status_code' === $req_orderby ) {
                    $orderby = 'wp.http_response_code ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'added_datetime' === $req_orderby ) {
                    $orderby = 'wp_optionview.added_timestamp ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'site_health' === $req_orderby ) {
                    $orderby = 'wp_sync.health_value ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'client_name' === $req_orderby ) {
                    $orderby = 'client_name ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'last_sync' === $req_orderby ) {
                    $orderby = 'wp_sync.dtsSync ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'security' === $req_orderby ) {
                    $orderby = 'wp.securityIssues ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                } elseif ( 'backup' === $req_orderby ) {
                    $orderby = 'wp_optionview.primary_lasttime_backup ' . ( 'asc' === $req_order ? 'asc' : 'desc' );
                    if ( ! in_array( 'primary_lasttime_backup', $extra_view ) ) {
                        $extra_view[] = 'primary_lasttime_backup';
                    }
                }
            }
        }

         // phpcs:disable WordPress.Security.NonceVerification,Missing,Missing,Missing,Missing,Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! $optimize ) {
            $perPage = 9999;
            $start   = 0;
        } else {
            $perPage = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;
            if ( -1 === (int) $perPage ) {
                $perPage = 9999;
            }
            $start = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;
        }

        $reset_filter = isset( $_REQUEST['reset'] ) && ( 'yes' === $_REQUEST['reset'] ) ? true : false;

        $search          = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';
        $get_saved_state = empty( $search ) && ! isset( $_REQUEST['g'] ) && ! isset( $_REQUEST['status'] ) && ! isset( $_REQUEST['client'] );
        $get_all         = ( '' === $search ) && ( isset( $_REQUEST['status'] ) && 'all' === $_REQUEST['status'] ) && empty( $_REQUEST['g'] ) && empty( $_REQUEST['client'] ) ? true : false;
        $is_not          = ( isset( $_REQUEST['isnot'] ) && 'yes' === $_REQUEST['isnot'] ) ? true : false;

        if ( $reset_filter ) {
            $get_all         = false;
            $get_saved_state = false;
        }

        $selected_one_time_siteids = get_user_option( 'mainwp_managesites_filter_onetime_selected_siteids' );

        if ( ! empty( $selected_one_time_siteids ) ) {
            $userid = get_current_user_id();
            if ( ! empty( $userid ) ) {
                delete_user_option( $userid, 'mainwp_managesites_filter_onetime_selected_siteids' );
                $get_all = true;
            } else {
                $selected_one_time_siteids = '';
            }
        }

        $group_ids   = false;
        $client_ids  = false;
        $site_status = '';

        if ( ! isset( $_REQUEST['status'] ) ) {
            if ( $get_saved_state ) {
                $site_status = get_user_option( 'mainwp_managesites_filter_status' );
            } else {
                MainWP_Utility::update_user_option( 'mainwp_managesites_filter_status', '' );
            }
        } else {
            MainWP_Utility::update_user_option( 'mainwp_managesites_filter_status', sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) );
            MainWP_Utility::update_user_option( 'mainwp_managesites_filter_is_not', $is_not );
            $site_status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
        }

        if ( $get_all && ! $selected_one_time_siteids ) {
            MainWP_Utility::update_user_option( 'mainwp_managesites_filter_group', '' );
            MainWP_Utility::update_user_option( 'mainwp_managesites_filter_client', '' );
        }

        if ( ! $get_all ) {
            if ( ! isset( $_REQUEST['g'] ) ) {
                if ( $get_saved_state ) {
                    $group_ids = get_user_option( 'mainwp_managesites_filter_group' );
                } else {
                    MainWP_Utility::update_user_option( 'mainwp_managesites_filter_group', '' );
                }
            } else {
                MainWP_Utility::update_user_option( 'mainwp_managesites_filter_group', sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) );
                $group_ids = sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ); // may be multi groups.
            }

            if ( ! isset( $_REQUEST['client'] ) ) {
                if ( $get_saved_state ) {
                    $client_ids = get_user_option( 'mainwp_managesites_filter_client' );
                } else {
                    MainWP_Utility::update_user_option( 'mainwp_managesites_filter_client', '' );
                }
            } else {
                MainWP_Utility::update_user_option( 'mainwp_managesites_filter_client', sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) );
                $client_ids = sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ); // may be multi groups.
            }
        }
        // phpcs:enable

        $where = null;

        if ( '' !== $site_status && 'all' !== $site_status ) {
            if ( 'connected' === $site_status ) {
                $where = 'wp_sync.sync_errors = ""';
                if ( $is_not ) {
                    $where = 'wp_sync.sync_errors != ""';
                }
            } elseif ( 'disconnected' === $site_status ) {
                $where = 'wp_sync.sync_errors != ""';
                if ( $is_not ) {
                    $where = 'wp_sync.sync_errors = ""';
                }
            } elseif ( 'update' === $site_status ) {
                $available_update_ids = MainWP_Common_Functions::instance()->get_available_update_siteids();
                if ( empty( $available_update_ids ) ) {
                    $where = 'wp.id = -1'; // so get empty results.
                    if ( $is_not ) {
                        $where = 'wp.id != -1'; // so query with all sites.
                    }
                } else {
                    $where = 'wp.id IN (' . implode( ',', $available_update_ids ) . ') ';
                    if ( $is_not ) {
                        $where = 'wp.id NOT IN (' . implode( ',', $available_update_ids ) . ') ';
                    }
                }
            } elseif ( 'sitehealthnotgood' === $site_status ) {
                $where = ' wp_sync.health_status = 1 ';
                if ( $is_not ) {
                    $where = 'wp_sync.health_status = 0';
                }
            } elseif ( 'phpver7' === $site_status ) {
                $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) < INET_ATON("7.0.0.0") ';
                if ( $is_not ) {
                    $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) >= INET_ATON("7.0.0.0") ';
                }
            } elseif ( 'phpver8' === $site_status ) {
                $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) < INET_ATON("8.0.0.0") ';
                if ( $is_not ) {
                    $where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) >= INET_ATON("8.0.0.0") ';
                }
            } elseif ( 'suspended' === $site_status ) {
                $where = 'wp.suspended = 1'; // query for suspended sites.
                if ( $is_not ) {
                    $where = 'wp.suspended = 0'; // query for not suspended sites.
                }
            }
        }

        $total_params = array( 'count_only' => true );

        if ( $get_all ) {
            $params = array(
                'selectgroups' => true,
                'orderby'      => $orderby,
                'offset'       => $start,
                'rowcount'     => $perPage,
            );
        } else {

            $total_params['search'] = $search;

            $params = array(
                'selectgroups' => true,
                'orderby'      => $orderby,
                'offset'       => $start,
                'rowcount'     => $perPage,
                'search'       => $search,
            );

            $total_params['isnot'] = $is_not;
            $params['isnot']       = $is_not;

            $qry_group_ids = array();
            if ( ! empty( $group_ids ) ) {
                $group_ids = explode( ',', $group_ids ); // convert to array.
                // to fix query deleted groups.
                $groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
                foreach ( $groups as $gr ) {
                    if ( in_array( $gr->id, $group_ids ) ) {
                        $qry_group_ids[] = $gr->id;
                    }
                }
                // to fix.
                if ( in_array( 'nogroups', $group_ids ) ) {
                    $qry_group_ids[] = 'nogroups';
                }
            }

            if ( ! empty( $qry_group_ids ) ) {
                $total_params['group_id'] = $qry_group_ids;
                $params['group_id']       = $qry_group_ids;
            }

            $qry_client_ids = array();
            if ( ! empty( $client_ids ) ) {
                $client_ids = explode( ',', $client_ids ); // convert to array.
                // to fix query deleted client.
                $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
                foreach ( $clients as $cl ) {
                    if ( in_array( $cl->client_id, $client_ids ) ) {
                        $qry_client_ids[] = $cl->client_id;
                    }
                }
                // to fix.
                if ( in_array( 'noclients', $client_ids ) ) {
                    $qry_client_ids[] = 'noclients';
                }
            }

            if ( ! empty( $qry_client_ids ) ) {
                $total_params['client_id'] = $qry_client_ids;
                $params['client_id']       = $qry_client_ids;
            }

            if ( ! empty( $where ) ) {
                $total_params['extra_where'] = $where;
                $params['extra_where']       = $where;
            }
        }

        if ( empty( $selected_one_time_siteids ) ) {
            $selected_one_time_siteids = isset( $_REQUEST['selected_sites'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['selected_sites'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( ! empty( $selected_one_time_siteids ) ) {
            $selected_siteids = explode( ',', $selected_one_time_siteids );
            $selected_siteids = MainWP_Utility::array_numeric_filter( $selected_siteids );
            if ( ! empty( $selected_siteids ) ) {
                $params['selected_sites'] = $selected_siteids;
            }
        }

        $total_websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $total_params ) );
        $totalRecords   = ( $total_websites ? MainWP_DB::num_rows( $total_websites ) : 0 );
        if ( $total_websites ) {
            MainWP_DB::free_result( $total_websites );
        }

        $params['extra_view']    = $extra_view;
        $params['view']          = 'manage_site';
        $params['dev_log_query'] = 0;

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $params ) );

        $site_ids = array();
        while ( $websites && ( $site = MainWP_DB::fetch_object( $websites ) ) ) {
            $site_ids[] = $site->id;
        }

        /**
         * Action is being replaced with mainwp_sitestable_prepared_items
         *
         * @deprecated
         */
        do_action_deprecated( 'mainwp-sitestable-prepared-items', array( $websites, $site_ids ), '4.0.7.2', 'mainwp_sitestable_prepared_items' ); // NOSONAR - not IP.

        /**
         * Action: mainwp_sitestable_prepared_items
         *
         * Fires before the Sites table itemes are prepared.
         *
         * @param object $websites Object containing child sites data.
         * @param array  $site_ids Array containing IDs of all child sites.
         *
         * @since Unknown
         */
        do_action( 'mainwp_sitestable_prepared_items', $websites, $site_ids );

        MainWP_DB::data_seek( $websites, 0 );

        $this->items       = $websites;
        $this->total_items = $totalRecords;
    }


    /**
     * Display the table.
     *
     * @param bool $optimize true|false Whether or not to optimize.
     */
    public function display( $optimize = true ) { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.

        $sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );

        $sites_per_page = intval( $sites_per_page );

        $pages_length = array(
            25  => '25',
            10  => '10',
            50  => '50',
            100 => '100',
            300 => '300',
        );

        $pages_length = $pages_length + array( $sites_per_page => $sites_per_page );
        ksort( $pages_length );

        if ( isset( $pages_length[-1] ) ) {
            unset( $pages_length[-1] );
        }

        $pagelength_val   = implode( ',', array_keys( $pages_length ) );
        $pagelength_title = implode( ',', array_values( $pages_length ) );

        /**
         * Action: mainwp_before_manage_sites_table
         *
         * Fires before the Manage Sites table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_manage_sites_table' );
        ?>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-columns-notice' ) ) : ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-columns-notice"></i>
                <?php printf( esc_html__( 'To hide or show a column, click the Cog (%s) icon and select options from "Show columns"', 'mainwp' ), '<i class="cog icon"></i>' ); ?>
            </div>
        <?php endif; ?>

        <table id="mainwp-manage-sites-table" style="width:100%" class="ui selectable unstackable table mainwp-with-preview-table mainwp-manage-wpsites-table">
            <thead>
                <tr><?php $this->print_column_headers( $optimize, true ); ?></tr>
            </thead>
            <?php if ( ! $optimize ) : ?>
                <tbody id="mainwp-manage-sites-body-table">
                    <?php $this->display_rows_or_placeholder(); ?>
                </tbody>
            <?php endif; ?>
        </table>
        <?php $count = MainWP_DB::instance()->get_websites_count( null, true ); ?>
        <?php if ( empty( $count ) ) : ?>
            <div id="sites-table-count-empty" style="display: none;">
                <?php $this->no_items(); ?>
            </div>
        <?php endif; ?>
        <?php
        /**
         * Action: mainwp_after_manage_sites_table
         *
         * Fires after the Manage Sites table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_manage_sites_table' );
        ?>
        <div id="mainwp-loading-sites" style="display: none;">
            <div class="ui active inverted dimmer">
                <div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp' ); ?></div>
            </div>
        </div>

        <?php
        $table_features = array(
            'searching'     => 'true',
            'paging'        => 'true',
            'pagingType'    => 'full_numbers',
            'info'          => 'true',
            'colReorder'    => ' {columns:":not(.check-column):not(.manage-site_actions-column)"} ',
            'stateSave'     => 'true',
            'stateDuration' => '0',
            'order'         => '[]',
            'scrollX'       => 'true',
            'responsive'    => 'true',
            'fixedColumns'  => '',
        );

        /**
         * Filter: mainwp_sites_table_features
         *
         * Filter the Monitoring table features.
         *
         * @since 4.1
         */
        $table_features = apply_filters( 'mainwp_sites_table_features', $table_features );
        ?>

        <script type="text/javascript">
            mainwp_manage_sites_screen_options = function () {
                jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( {
                    allowMultiple: true,
                    onHide: function () {
                        let val = jQuery( '#mainwp_default_sites_per_page' ).val();
                        let saved = jQuery( '#mainwp_default_sites_per_page' ).attr( 'saved-value' );
                        if ( saved != val ) {
                            jQuery( '#mainwp-manage-sites-table' ).DataTable().page.len( val );
                            jQuery( '#mainwp-manage-sites-table' ).DataTable().state.save();
                        }
                    }
                } ).modal( 'show' );

                jQuery( '#manage-sites-screen-options-form' ).submit( function() {
                    if ( jQuery('input[name=reset_managersites_columns_order]').attr('value') == 1 ) {
                        $manage_sites_table.colReorder.reset();
                    }
                    jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( 'hide' );
                } );
                return false;
            };

            let responsive = <?php echo esc_js( $table_features['responsive'] ); ?>;
            if( jQuery( window ).width() > 1140 ) {
                responsive = false;
            }

            jQuery( document ).ready( function( $ ) {

            <?php if ( ! $optimize ) { ?>
                        try {
                            //jQuery( '#mainwp-sites-table-loader' ).hide();
                            $manage_sites_table = jQuery( '#mainwp-manage-sites-table' )
                            .DataTable( {
                                "responsive": responsive,
                                "searching" : <?php echo esc_js( $table_features['searching'] ); ?>,
                                "paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
                                "pagingType" : "<?php echo esc_js( $table_features['pagingType'] ); ?>",
                                "info" : <?php echo esc_js( $table_features['info'] ); ?>,
                                "scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
                                "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                                "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                                "stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
                                "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                                <?php if ( isset( $table_features['fixedColumns'] ) && '' !== $table_features['fixedColumns'] ) : ?>
                                "fixedColumns" : <?php echo esc_js( $table_features['fixedColumns'] ); ?>,
                                <?php endif; ?>
                                "lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>, -1 ], [<?php echo esc_js( $pagelength_title ); ?>, "All" ] ],
                                "columnDefs": [
                                    {
                                        "targets": 'no-sort',
                                        "orderable": false
                                    },
                                    {
                                        "targets": 'manage-site-column',
                                        "type": 'natural-nohtml'
                                    },
                                    <?php do_action( 'mainwp_manage_sites_table_columns_defs' ); ?>
                                ],
                                "pageLength": <?php echo intval( $sites_per_page ); ?>,
                                "initComplete": function( settings, json ) {
                                },
                                "language": {
                                    "emptyTable": "<?php esc_html_e( 'No websites found.', 'mainwp' ); ?>"
                                },
                                "drawCallback": function( settings ) {
                                    if ( jQuery('#mainwp-manage-sites-body-table td.dt-empty').length > 0 && jQuery('#sites-table-count-empty').length ){
                                        jQuery('#mainwp-manage-sites-body-table td.dt-empty').html(jQuery('#sites-table-count-empty').html());
                                    }
                                }
                            } );

                        } catch(err) {
                            // to fix js error.
                            console.log(err);
                        }
                        setTimeout(() => {
                            mainwp_datatable_fix_menu_overflow('#mainwp-manage-sites-table' );
                        }, 800);
            <?php } else { ?>
                    try {
                        //jQuery( '#mainwp-sites-table-loader' ).hide();
                        $manage_sites_table = jQuery( '#mainwp-manage-sites-table' ).on( 'processing.dt', function ( e, settings, processing ) {
                            jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
                            if (!processing) {
                                let tb = jQuery( '#mainwp-manage-sites-table' );
                                tb.find( 'th[cell-cls]' ).each( function(){
                                    let ceIdx = this.cellIndex;
                                    let cls = jQuery( this ).attr( 'cell-cls' );
                                    jQuery( '#mainwp-manage-sites-table tr' ).each(function(){
                                        jQuery(this).find( 'td:eq(' + ceIdx + ')' ).addClass(cls);
                                    } );
                                } );
                                $( '#mainwp-manage-sites-table .ui.dropdown' ).dropdown();
                                $( '#mainwp-manage-sites-table .ui.checkbox' ).checkbox();
                            }
                        } ).DataTable( {
                            "ajax": {
                                "url": ajaxurl,
                                "type": "POST",
                                "data":  function ( d ) {
                                        return $.extend( {}, d, mainwp_secure_data( {
                                            action: 'mainwp_manage_sites_display_rows',
                                            status: jQuery("#mainwp-filter-sites-status").dropdown("get value"),
                                            g: jQuery("#mainwp-filter-sites-group").dropdown("get value"),
                                            client: jQuery("#mainwp-filter-clients").dropdown("get value"),
                                            isnot: jQuery("#mainwp_is_not_site").dropdown("get value"),
                                        } )
                                    );
                                },
                                "dataSrc": function ( json ) {
                                    managesites_reset_bulk_actions_params();
                                    for ( let i=0, ien=json.data.length ; i < ien ; i++ ) {
                                        json.data[i].syncError = json.rowsInfo[i].syncError ? json.rowsInfo[i].syncError : false;
                                        json.data[i].rowClass = json.rowsInfo[i].rowClass;
                                        json.data[i].siteID = json.rowsInfo[i].siteID;
                                        json.data[i].siteUrl = json.rowsInfo[i].siteUrl;
                                    }
                                    return json.data;
                                }
                            },
                            "responsive": responsive,
                            "searching" : <?php echo esc_js( $table_features['searching'] ); ?>,
                            "paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
                            "pagingType" : "<?php echo esc_js( $table_features['pagingType'] ); ?>",
                            "info" : <?php echo esc_js( $table_features['info'] ); ?>,
                            "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                            "scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
                            "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                            "stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
                            "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                            "fixedColumns" : <?php echo ! empty( $table_features['fixedColumns'] ) ? esc_js( $table_features['fixedColumns'] ) : '""'; ?>,
                            "lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>, -1 ], [<?php echo esc_js( $pagelength_title ); ?>, "All"] ],
                            serverSide: true,
                            "pageLength": <?php echo intval( $sites_per_page ); ?>,
                            "columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
                            "columns": <?php echo wp_json_encode( $this->get_columns_init() ); ?>,
                            "language": {
                                "emptyTable": "<?php esc_html_e( 'No websites found.', 'mainwp' ); ?>"
                            },
                            "drawCallback": function( settings ) {
                                this.api().tables().body().to$().attr( 'id', 'mainwp-manage-sites-body-table' );
                                if ( typeof mainwp_preview_init_event !== "undefined" ) {
                                    mainwp_preview_init_event();
                                }
                                //jQuery( '#mainwp-sites-table-loader' ).hide();
                                if ( jQuery('#mainwp-manage-sites-body-table td.dt-empty').length > 0 && jQuery('#sites-table-count-empty').length ){
                                    jQuery('#mainwp-manage-sites-body-table td.dt-empty').html(jQuery('#sites-table-count-empty').html());
                                }
                                setTimeout(() => {
                                    mainwp_datatable_fix_menu_overflow('#mainwp-manage-sites-table');
                                }, 1000);
                            },
                            "initComplete": function( settings, json ) {
                            },
                            rowCallback: function (row, data) {
                                jQuery( row ).addClass(data.rowClass);
                                jQuery( row ).attr( 'site-url', data.siteUrl );
                                jQuery( row ).attr( 'siteid', data.siteID );
                                jQuery( row ).attr( 'id', "child-site-" + data.siteID );
                                if ( data.syncError ) {
                                    jQuery( row ).find( 'td.column-site-bulk' ).addClass( 'site-sync-error' );
                                };
                            },
                            'select': {
                                items: 'row',
                                style: 'multi+shift',
                                selector: 'tr>td.check-column'
                            },
                        }).on('select', function (e, dt, type, indexes) {
                            if( 'row' == type ){
                                dt.rows(indexes)
                                .nodes()
                                .to$().find('td.check-column .ui.checkbox' ).checkbox('set checked');
                            }
                        }).on('deselect', function (e, dt, type, indexes) {
                            if( 'row' == type ){
                                dt.rows(indexes)
                                .nodes()
                                .to$().find('td.check-column .ui.checkbox' ).checkbox('set unchecked');
                            }
                        }).on( 'columns-reordered', function ( e, settings, details ) {
                            console.log('columns-reordered');
                            setTimeout(() => {
                                jQuery( '#mainwp-manage-sites-table .ui.dropdown' ).dropdown();
                                jQuery( '#mainwp-manage-sites-table .ui.checkbox' ).checkbox();
                                mainwp_datatable_fix_menu_overflow('#mainwp-manage-sites-table' );
                            }, 1000);
                        } );
                    } catch(err) {
                        // to fix js error.
                    }
        <?php } ?>
                    _init_manage_sites_screen = function() {
                        <?php
                        if ( empty( $count ) ) {
                            ?>
                            jQuery( '#mainwp-manage-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
                                let col_id = jQuery( this ).attr( 'id' );
                                col_id = col_id.replace( "mainwp_show_column_", "" );
                                try {
                                    $manage_sites_table.column( '#' + col_id ).visible( false );
                                } catch(err) {
                                    // to fix js error.
                                }
                            } );
                            let cols = ['favicon', 'site_combo','update','site_health','last_sync','site_actions'];
                            jQuery.each( cols, function ( index, value ) {
                                try {
                                    $manage_sites_table.column( '#' + value ).visible( true );
                                } catch(err) {
                                    // to fix js error.
                                }
                            } );
                            <?php
                        } else {
                            ?>
                            jQuery( '#mainwp-manage-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
                                let col_id = jQuery( this ).attr( 'id' );
                                col_id = col_id.replace( "mainwp_show_column_", "" );
                                try {
                                    $manage_sites_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
                                } catch(err) {
                                    // to fix js error.
                                }
                            } );
                    <?php } ?>
                    };
                    _init_manage_sites_screen();
            } );

                mainwp_manage_sites_filter = function() {
                    <?php if ( ! $optimize ) { ?>
                        let group = jQuery( "#mainwp-filter-sites-group" ).dropdown( "get value" );
                        let status = jQuery( "#mainwp-filter-sites-status" ).dropdown( "get value" );
                        let isNot = jQuery("#mainwp_is_not_site").dropdown("get value");
                        let client = jQuery("#mainwp-filter-clients").dropdown("get value");
                        let params = '';
                        params += '&g=' + group;
                        params += '&client=' + client;
                        if ( status != '' ) {
                            params += '&status=' + status;
                        }
                        if ( 'yes' == isNot ){
                            params += '&isnot=yes';
                        }
                        window.location = 'admin.php?page=managesites' + params;
                        return false;
                    <?php } else { ?>
                        try {
                            let defaultFilter = (jQuery( "#mainwp-filter-sites-group" ).dropdown('get value') ==  ''
                            && jQuery("#mainwp-filter-clients").dropdown('get value') == ''
                            && jQuery("#mainwp-filter-sites-status").dropdown('get value') == 'all'
                            && jQuery("#mainwp_is_not_site").dropdown('get value') == '');

                            if(defaultFilter){
                                jQuery('#mainwp_manage_sites_reset_filters').attr('disabled', 'disabled');
                            } else {
                                jQuery('#mainwp_manage_sites_reset_filters').attr('disabled', false);
                            }

                            $manage_sites_table.ajax.reload();
                        } catch(err) {
                            // to fix js error.
                        }
                    <?php } ?>
                };
                mainwp_manage_sites_reset_filters = function(resetObj) {
                    <?php if ( ! $optimize ) { ?>
                        window.location = 'admin.php?page=managesites&isnot=no';
                        return false;
                    <?php } else { ?>
                        try {
                            jQuery( "#mainwp-filter-sites-group" ).dropdown('clear');
                            jQuery("#mainwp-filter-clients").dropdown('clear');
                            jQuery( "#mainwp-filter-sites-status" ).dropdown('set selected', 'all');
                            jQuery("#mainwp_is_not_site").dropdown('set selected', '');
                            jQuery(resetObj).attr('disabled','disabled');
                            $manage_sites_table.ajax.reload();
                        } catch(err) {
                            // to fix js error.
                        }
                    <?php } ?>
                }
        </script>
        <?php
    }

    /**
     * Return empty table place holders.
     */
    public function display_rows_or_placeholder() {
        if ( $this->has_items() ) {
            $this->display_rows();
        }
    }

    /**
     * Get the column count.
     *
     * @return int Column Count.
     */
    public function get_column_count() {
        list ( $columns ) = $this->get_column_info();
        return count( $columns );
    }

    /**
     * Echo the column headers.
     *
     * @param bool $optimize true|false Whether or not to optimise.
     * @param bool $top true|false.
     */
    public function print_column_headers( $optimize, $top = true ) { // phpcs:ignore -- NOSONAR - complex.
        list( $columns, $sortable ) = $this->get_column_info();

        if ( ! empty( $columns['cb'] ) ) {
            $columns['cb'] = '<div class="ui checkbox"><input id="' . ( $top ? 'cb-select-all-top' : 'cb-select-all-bottom' ) . '" type="checkbox" /></div>';
        }

        $def_columns                 = $this->get_default_columns();
        $def_columns['site_actions'] = '';

        foreach ( $columns as $column_key => $column_display_name ) {

            $class = array( 'manage-' . $column_key . '-column' );
            $attr  = '';
            if ( ! isset( $def_columns[ $column_key ] ) ) {
                $class[] = 'extra-column';
                if ( $optimize ) {
                    $attr = 'cell-cls="' . esc_html( "collapsing $column_key column-$column_key" ) . '"';
                }
            }

            if ( 'cb' === $column_key ) {
                $class[] = 'check-column';
                $class[] = 'collapsing';
            }

            if ( ! isset( $sortable[ $column_key ] ) ) {
                $class[] = 'no-sort';
            }

            $tag = 'th';
            $id  = "id='$column_key'";

            if ( ! empty( $class ) ) {
                $class = "class='" . join( ' ', $class ) . "'";
            }

            echo "<$tag $id $class $attr>$column_display_name</$tag>"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /**
     * Get column info.
     */
    protected function get_column_info() {

        if ( isset( $this->column_headers ) && is_array( $this->column_headers ) ) {
            $column_headers = array( array(), array(), array(), $this->get_default_primary_column_name() );
            foreach ( $this->column_headers as $key => $value ) {
                $column_headers[ $key ] = $value;
            }

            return $column_headers;
        }

        $columns = $this->get_columns();

        $sortable_columns = $this->get_sortable_columns();

        $_sortable = $sortable_columns;

        $sortable = array();
        foreach ( $_sortable as $id => $data ) {
            if ( empty( $data ) ) {
                continue;
            }

            $data = (array) $data;
            if ( ! isset( $data[1] ) ) {
                $data[1] = false;
            }

            $sortable[ $id ] = $data;
        }

        $primary              = $this->get_default_primary_column_name();
        $this->column_headers = array( $columns, $sortable, $primary );

        return $this->column_headers;
    }


    /**
     * Clear Items.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::is_result()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public function clear_items() {
        if ( MainWP_DB::is_result( $this->items ) ) {
            MainWP_DB::free_result( $this->items );
        }
    }

    /**
     * Get table rows.
     *
     * Optimize for shared hosting or big networks.
     *
     * @return array Rows html.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_favico_url()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_options_array()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_site_health()
     * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    public function ajax_get_datatable_rows() { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $all_rows  = array();
        $info_rows = array();
        $use_favi  = get_option( 'mainwp_use_favicon', 1 );

        $http_error_codes = MainWP_Utility::get_http_codes();

        $userExtension = MainWP_DB_Common::instance()->get_user_extension();

        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        $last24_time = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( time() - DAY_IN_SECONDS );

        if ( $this->items ) {
            foreach ( $this->items as $website ) {
                $rw_classes    = $this->get_groups_classes( $website );
                $hasSyncErrors = ( '' !== $website['sync_errors'] );
                $suspendedSite = ( '0' !== $website['suspended'] );

                $rw_classes = trim( $rw_classes );
                $rw_classes = 'child-site mainwp-child-site-' . intval( $website['id'] ) . ' ' . ( $hasSyncErrors ? 'error' : '' ) . ' ' . ( $suspendedSite ? 'suspended' : '' ) . ' ' . $rw_classes;

                $info_item = array(
                    'rowClass'  => esc_html( $rw_classes ),
                    'siteID'    => $website['id'],
                    'siteUrl'   => $website['url'],
                    'syncError' => ( '' !== $website['sync_errors'] ? true : false ),
                );

                $total_wp_upgrades     = 0;
                $total_plugin_upgrades = 0;
                $total_theme_upgrades  = 0;

                $site_options          = MainWP_DB::instance()->get_website_options_array( $website, array( 'wp_upgrades', 'ignored_wp_upgrades', 'premium_upgrades', 'primary_lasttime_backup' ) );
                $wp_upgrades           = isset( $site_options['wp_upgrades'] ) ? json_decode( $site_options['wp_upgrades'], true ) : array();
                $ignored_core_upgrades = isset( $site_options['ignored_wp_upgrades'] ) ? json_decode( $site_options['ignored_wp_upgrades'], true ) : array();

                if ( $website['is_ignoreCoreUpdates'] || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
                    $wp_upgrades = array();
                }

                if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
                    ++$total_wp_upgrades;
                }

                $plugin_upgrades = json_decode( $website['plugin_upgrades'], true );

                if ( $website['is_ignorePluginUpdates'] ) {
                    $plugin_upgrades = array();
                }

                $theme_upgrades = json_decode( $website['theme_upgrades'], true );

                if ( $website['is_ignoreThemeUpdates'] ) {
                    $theme_upgrades = array();
                }

                $decodedPremiumUpgrades = isset( $site_options['premium_upgrades'] ) ? json_decode( $site_options['premium_upgrades'], true ) : array();

                if ( is_array( $decodedPremiumUpgrades ) ) {
                    foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                        $premiumUpgrade['premium'] = true;
                        if ( 'plugin' === $premiumUpgrade['type'] ) {
                            if ( ! is_array( $plugin_upgrades ) ) {
                                $plugin_upgrades = array();
                            }
                            if ( ! $website['is_ignorePluginUpdates'] ) {
                                $plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
                            }
                        } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                            if ( ! is_array( $theme_upgrades ) ) {
                                $theme_upgrades = array();
                            }
                            if ( ! $website['is_ignoreThemeUpdates'] ) {
                                $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                            }
                        }
                    }
                }

                if ( is_array( $plugin_upgrades ) ) {
                    $ignored_plugins = json_decode( $website['ignored_plugins'], true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
                    }

                    $ignored_plugins = json_decode( $this->userExtension->ignored_plugins, true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
                    }
                    $total_plugin_upgrades += count( $plugin_upgrades );
                }

                if ( is_array( $theme_upgrades ) ) {
                    $ignored_themes = json_decode( $website['ignored_themes'], true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                    }
                    $ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                    }
                    $total_theme_upgrades += count( $theme_upgrades );
                }

                $total_updates = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

                $health_status = isset( $website['health_site_status'] ) ? json_decode( $website['health_site_status'], true ) : array();
                $hstatus       = MainWP_Utility::get_site_health( $health_status );

                $hval     = $hstatus['val'];
                $critical = $hstatus['critical'];

                if ( 80 <= $hval && empty( $critical ) ) {
                    $site_health = '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website['id'] ) . '&location=' . esc_attr( base64_encode( 'site-health.php' ) ) . '&_opennonce=' . esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ) . '" target="_blank" class="open_newwindow_wpadmin ui mini grey icon basic button" data-tooltip="' . esc_attr__( 'Health Score: ', '' ) . $hval . '" data-position="left center" data-inverted=""><i class="heartbeat green icon"></i></a>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                } else {
                    $site_health = '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website['id'] ) . '&location=' . esc_attr( base64_encode( 'site-health.php' ) ) . '&_opennonce=' . esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ) . '" target="_blank" class="open_newwindow_wpadmin ui mini grey icon basic button" data-tooltip="' . esc_attr__( 'Health Score: ', '' ) . $hval . esc_attr__( ' | Critical Issues: ', '' ) . $critical . '" data-position="left center" data-inverted=""><i class="heartbeat yellow icon"></i></a>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                }

                $note       = html_entity_decode( $website['note'] );
                $esc_note   = MainWP_Utility::esc_content( $note );
                $strip_note = wp_strip_all_tags( $esc_note );

                $columns = $this->get_columns();

                $cols_data = array();

                $website = apply_filters( 'mainwp_sitestable_website', $website, $this->userExtension );

                $site_icon = '';
                if ( $use_favi ) {
                    $siteObj   = (object) $website;
                    $favi_url  = MainWP_Connect::get_favico_url( $siteObj );
                    $site_icon = MainWP_Manage_Sites::get_instance()->get_site_icon_display( $website['cust_site_icon_info'], $favi_url );
                }

                $client_image = '';
                if ( $website['client_id'] > 0 ) {
                    $client       = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $website['client_id'], true );
                    $client_image = MainWP_Client_Handler::get_client_avatar( $client );
                } else {
                    $client_image = '<i class="user circle grey big icon"></i>';
                }

                $website_info = MainWP_DB::instance()->get_website_option( $website, 'site_info' );
                $website_info = ! empty( $website_info ) ? json_decode( $website_info, true ) : array();

                foreach ( $columns as $column_name => $column_display_name ) {

                    $column_content = apply_filters( 'mainwp_sitestable_display_row_columns', false, $column_name, $website );

                    if ( false !== $column_content ) {
                        $cols_data[ $column_name ] = $column_content;
                        continue;
                    }

                    ob_start();
                    ?>
                    <?php if ( 'cb' === $column_name ) { ?>
                        <div class="ui checkbox"><input type="checkbox" value="<?php echo intval( $website['id'] ); ?>" /></div>
                    <?php } elseif ( 'status' === $column_name ) { ?>
                        <?php if ( $hasSyncErrors ) : ?>
                            <span data-tooltip="<?php echo esc_attr_e( 'Disconnected', 'mainwp' ); ?>" data-position="right center" data-inverted=""><a class="mainwp_site_reconnect" href="#"><i class="red times large icon"></i></a></span>
                        <?php else : ?>
                            <?php if ( $suspendedSite ) : ?>
                                <span data-tooltip="<?php echo esc_attr_e( 'Suspended', 'mainwp' ); ?>" data-position="right center" data-inverted=""><a class="managesites_syncdata" href="#"><i class="pause yellow large icon"></i></a></span>
                            <?php else : ?>
                                <span data-tooltip="<?php echo esc_attr_e( 'Connected', 'mainwp' ); ?>" data-position="right center" data-inverted=""><a class="managesites_syncdata" href="#"><i class="green large check icon"></i></a></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php } elseif ( 'favicon' === $column_name ) { ?>
                        <?php echo $site_icon; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php } elseif ( 'site_combo' === $column_name ) { ?>
                        <i class="ui active inline loader tiny" style="display:none"></i> <span id="site-status-<?php echo esc_attr( $website['id'] ); ?>" class="status hidden"></span>
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
                            <a href="<?php MainWP_Site_Open::get_open_site_url( $website['id'] ); ?>" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
                        <?php endif; ?>
                        <a href="<?php echo 'admin.php?page=managesites&dashboard=' . intval( $website['id'] ); ?>">
                            <?php echo esc_attr( stripslashes( $website['name'] ) ); ?>
                        </a>
                        <div>
                            <span class="ui small text">
                                <a href="<?php echo esc_url( $website['url'] ); ?>" class="mainwp-may-hide-referrer open_site_url ui grey text" target="_blank">
                                    <?php echo esc_html( MainWP_Utility::get_nice_url( $website['url'] ) ); ?>
                                </a>
                            </span>
                        </div>
                    <?php } elseif ( 'site' === $column_name ) { ?>
                        <a href="<?php echo 'admin.php?page=managesites&dashboard=' . intval( $website['id'] ); ?>"><?php echo esc_attr( stripslashes( $website['name'] ) ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php echo esc_attr( $website['id'] ); ?>" class="status hidden"></span>
                    <?php } elseif ( 'login' === $column_name ) { ?>
                        <?php if ( ! \mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
                            <i class="sign in icon"></i>
                        <?php else : ?>
                            <a href="<?php MainWP_Site_Open::get_open_site_url( $website['id'] ); ?>" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
                        <?php endif; ?>
                    <?php } elseif ( 'url' === $column_name ) { ?>
                        <a href="<?php echo esc_url( $website['url'] ); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html( MainWP_Utility::get_nice_url( $website['url'] ) ); ?></a>
                    <?php } elseif ( 'tags' === $column_name ) { ?>
                        <?php echo MainWP_System_Utility::get_site_tags_belong( $website ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php } elseif ( 'update' === $column_name ) { ?>
                        <a data-tooltip="<?php echo ! empty( $website['dtsSync'] ) ? esc_attr__( 'Last sync: ', 'mainwp' ) . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['dtsSync'] ) ) : ''; //phpcs:ignore -- ok. ?> " data-position="left center" data-inverted="" class="ui mini grey button" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>">
                            <i class="sync alternate icon"></i> <?php echo intval( $total_updates ); ?>
                        </a>
                    <?php } elseif ( 'wpcore_update' === $column_name ) { ?>
                        <a class="ui mini basic grey button" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>&tab=wordpress-updates">
                            <i class="sync alternate icon"></i> <?php echo intval( $total_wp_upgrades ); ?>
                        </a>
                    <?php } elseif ( 'plugin_update' === $column_name ) { ?>
                        <a class="ui mini basic grey button" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>">
                            <i class="sync alternate icon"></i> <?php echo intval( $total_plugin_upgrades ); ?>
                        </a>
                    <?php } elseif ( 'theme_update' === $column_name ) { ?>
                        <a class="ui mini basic grey button" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>&tab=themes-updates">
                            <i class="sync alternate icon"></i> <?php echo intval( $total_theme_upgrades ); ?>
                        </a>
                    <?php } elseif ( 'client_name' === $column_name ) { ?>
                        <?php if ( ! empty( $website['client_name'] ) ) : ?>
                            <a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website['client_id'] ); ?>">
                                <?php echo $client_image; //phpcs:ignore -- NOSONAR - ok.?> <?php echo esc_html( $website['client_name'] ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo $client_image; //phpcs:ignore -- NOSONAR - ok.?> <span><?php echo esc_html( 'Unassigned' ); ?></span>
                        <?php endif; ?>
                    <?php } elseif ( 'security' === $column_name ) { ?>
                        <?php if ( ! empty( $website['securityIssues'] ) && '[]' !== $website['securityIssues'] ) : ?>
                            <?php if ( 0 < $website['securityIssues'] ) : ?>
                                <a href="admin.php?page=managesites&scanid=<?php echo intval( $website['id'] ); ?>" class="ui mini button" data-tooltip="<?php esc_attr_e( 'Click to review site hardening options.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="shield red icon"></i> <?php echo intval( $website['securityIssues'] ); ?></a>
                            <?php else : ?>
                                <a href="admin.php?page=managesites&scanid=<?php echo intval( $website['id'] ); ?>" class="ui mini button" data-tooltip="<?php esc_attr_e( 'Click to review site hardening options.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="shield green icon"></i> <?php esc_html_e( '0', 'mainwp' ); ?></a>
                            <?php endif; ?>
                        <?php else : ?>
                            <a href="admin.php?page=managesites&scanid=<?php echo intval( $website['id'] ); ?>" class="ui mini button" data-tooltip="<?php esc_attr_e( 'Data not available. Site might be disconnected.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="shield grey icon"></i> <?php esc_html_e( '0', 'mainwp' ); ?></a>
                        <?php endif; ?>
                    <?php } elseif ( 'uptime' === $column_name ) { ?>
                        <?php
                        $uptime_status = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitor_stat_hourly_by( $website['monitor_id'], 'last24', $last24_time );
                        MainWP_Monitoring_Sites_List_Table::instance()->render_last24_uptime_status( $uptime_status, $last24_time );
                        ?>

                    <?php } elseif ( 'last_sync' === $column_name ) { ?>
                        <?php echo ! empty( $website['dtsSync'] ) ? '<span data-tooltip="' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['dtsSync'] ) ) . '" data-position="left center" data-inverted=""><i class="calendar outline icon"></i> ' . MainWP_Utility::time_elapsed_string( $website['dtsSync'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php } elseif ( 'last_post' === $column_name ) { ?>
                        <?php echo ! empty( $website['last_post_gmt'] ) ? '<span data-tooltip="' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['last_post_gmt'] ) ) . '" data-position="left center" data-inverted=""><i class="calendar outline icon"></i> ' . MainWP_Utility::time_elapsed_string( $website['last_post_gmt'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php } elseif ( ! $this->site_health_disabled && 'site_health' === $column_name ) { ?>
                        <?php if ( ! $hasSyncErrors ) : ?>
                            <?php echo $site_health; //phpcs:ignore -- NOSONAR - ok.?>
                        <?php else : ?>
                            <span class="ui mini grey icon basic button" data-tooltip="<?php esc_attr_e( 'Data not available. Site might be disconnected.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="heartbeat grey icon"></i></span>
                        <?php endif; ?>
                    <?php } elseif ( 'status_code' === $column_name ) { ?>
                        <?php
                        if ( $website['http_response_code'] ) {
                            $code = $website['http_response_code'];
                            ?>
                            <div class="ui small label">
                                <?php echo esc_html( $code ); ?>
                                <?php
                                if ( isset( $http_error_codes[ $code ] ) ) {
                                    echo '<div class="detail">' . esc_html( $http_error_codes[ $code ] ) . '</div>';
                                }
                                ?>
                            </div>
                            <?php
                        }
                        ?>
                    <?php } elseif ( 'notes' === $column_name ) { ?>
                        <?php if ( empty( $website['note'] ) ) : ?>
                            <a href="javascript:void(0)" class="mainwp-edit-site-note ui mini grey icon basic button" id="mainwp-notes-<?php echo intval( $website['id'] ); ?>"><i class="sticky note outline grey icon"></i></a>
                        <?php else : ?>
                            <a href="javascript:void(0)" class="mainwp-edit-site-note ui mini icon basic button" id="mainwp-notes-<?php echo intval( $website['id'] ); ?>" data-tooltip="<?php echo substr( wp_unslash( $strip_note ), 0, 100 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
                        <?php endif; ?>
                            <span style="display: none" id="mainwp-notes-<?php echo intval( $website['id'] ); ?>-note"><?php echo wp_unslash( $esc_note ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <?php } elseif ( 'phpversion' === $column_name ) { ?>
                        <?php echo ! empty( $website['phpversion'] ) ? '<i class="php icon"></i> ' . esc_html( substr( $website['phpversion'], 0, 6 ) ) : ''; ?>
                    <?php } elseif ( 'language' === $column_name ) { ?>
                        <?php MainWP_Utility::get_language_code_as_flag( ! empty( $website_info['site_lang'] ) ? $website_info['site_lang'] : '' ); ?>
                    <?php } elseif ( 'index' === $column_name ) { ?>
                        <?php MainWP_Utility::get_site_index_option_icon( isset( $website_info['site_public'] ) ? $website_info['site_public'] : '' ); ?>
                    <?php } elseif ( 'added_datetime' === $column_name ) { ?>
                        <?php echo ! empty( $website['added_timestamp'] ) ? '<span data-tooltip="' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['added_timestamp'] ) ) . '" data-position="left center" data-inverted=""><i class="calendar outline icon"></i> ' . MainWP_Utility::time_elapsed_string( $website['added_timestamp'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php } elseif ( 'site_actions' === $column_name ) { ?>
                        <div class="ui right pointing dropdown" style="z-index: 99;">
                            <a href="javascript:void(0)"><i class="ellipsis vertical icon"></i></a>
                            <div class="menu" siteid="<?php echo intval( $website['id'] ); ?>">
                                <?php if ( '' !== $website['sync_errors'] ) : ?>
                                <a class="mainwp_site_reconnect item" href="#"><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
                                <?php else : ?>
                                    <?php if ( \mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
                                        <a href="<?php MainWP_Site_Open::get_open_site_url( $website['id'] ); ?>" class="open_newwindow_wpadmin item" target="_blank"><?php esc_html_e( 'Go To WP Admin', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                <a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
                                <?php endif; ?>
                                <?php if ( \mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) : ?>
                                <a class="item" href="admin.php?page=managesites&dashboard=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
                                <?php endif; ?>
                                <?php if ( \mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) : ?>
                                <a class="item" href="admin.php?page=managesites&id=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Settings', 'mainwp' ); ?></a>
                                <?php endif; ?>
                                <?php if ( \mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) : ?>
                                <a class="item" href="admin.php?page=managesites&scanid=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Site Hardening', 'mainwp' ); ?></a>
                                <?php endif; ?>
                                <a class="item" site-name="<?php echo esc_html( $website['name'] ); ?>" site-id="<?php echo esc_html( $website['id'] ); ?>" onclick="return managesites_remove( this )"><?php esc_html_e( 'Remove Site', 'mainwp' ); ?></a>
                                <?php
                                /**
                                 * Action: mainwp_manage_sites_action
                                 *
                                 * Adds custom manage sites action item.
                                 *
                                 * @param array $website Array containing website data.
                                 *
                                 * @since 4.1
                                 */
                                do_action( 'mainwp_manage_sites_action', $website );
                                ?>
                            </div>
                        </div>
                            <?php
                    } elseif ( method_exists( $this, 'column_' . $column_name ) ) {
                        echo call_user_func( array( $this, 'column_' . $column_name ), $website ); // phpcs:ignore WordPress.Security.EscapeOutput
                    } else {
                        echo $this->column_default( $website, $column_name ); // phpcs:ignore WordPress.Security.EscapeOutput
                    }
                    $cols_data[ $column_name ] = ob_get_clean();
                }
                $all_rows[]  = $cols_data;
                $info_rows[] = $info_item;
            }
        }
        return array(
            'data'            => $all_rows,
            'recordsTotal'    => $this->total_items,
            'recordsFiltered' => $this->total_items,
            'rowsInfo'        => $info_rows,
        );
    }

    /**
     * Get groups classes row.
     *
     * @param array $item Array containing child site data.
     * @return mixed Single Row Classes Item.
     */
    public function get_groups_classes( $item ) {
        $rw_classes = '';
        if ( isset( $item['wpgroupids'] ) && ! empty( $item['wpgroupids'] ) ) {
            $group_class = $item['wpgroupids'];
            $group_class = explode( ',', $group_class );
            if ( is_array( $group_class ) ) {
                foreach ( $group_class as $_class ) {
                    $_class      = trim( $_class );
                    $_class      = 'group-' . MainWP_Utility::sanitize_file_name( $_class );
                    $rw_classes .= ' ' . strtolower( $_class );
                }
            } else {
                $_class      = MainWP_Utility::sanitize_file_name( $group_class );
                $rw_classes .= ' group-' . strtolower( $_class );
            }
        }
        return $rw_classes;
    }

    /**
     * Fetch single row item.
     *
     * @return mixed Single Row Item.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::is_result()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_array()
     */
    public function display_rows() {
        if ( MainWP_DB::is_result( $this->items ) ) {
            while ( $this->items && ( $item = MainWP_DB::fetch_array( $this->items ) ) ) {
                $this->single_row( $item );
            }
        }
    }

    /**
     * Single Row.
     *
     * @param mixed $website Child Site.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
     */
    public function single_row( $website ) {
        $classes = $this->get_groups_classes( $website );

        $hasSyncErrors = ( '' !== $website['sync_errors'] );
        $suspendedSite = ( '0' !== $website['suspended'] );

        $classes = trim( $classes );
        $classes = ' class="child-site mainwp-child-site-' . intval( $website['id'] ) . ' ' . ( $hasSyncErrors ? 'error' : '' ) . ' ' . ( $suspendedSite ? 'suspended' : '' ) . ' ' . $classes . '"';
        echo '<tr id="child-site-' . intval( $website['id'] ) . '"' . esc_html( $classes ) . ' siteid="' . intval( $website['id'] ) . '" site-url="' . esc_url( $website['url'] ) . '">';
        $this->single_row_columns( $website );
        echo '</tr>';
    }


    /**
     * Columns for a single row.
     *
     * @param mixed $website Child Site.
     * @param bool  $good_health Good site health info.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_favico_url()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_options_array()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_site_health()
     * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    protected function single_row_columns( $website, $good_health = false ) { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $total_wp_upgrades     = 0;
        $total_plugin_upgrades = 0;
        $total_theme_upgrades  = 0;

        $userExtension = MainWP_DB_Common::instance()->get_user_extension();

        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        $site_options          = MainWP_DB::instance()->get_website_options_array( $website, array( 'wp_upgrades', 'ignored_wp_upgrades', 'premium_upgrades', 'primary_lasttime_backup' ) );
        $wp_upgrades           = isset( $site_options['wp_upgrades'] ) ? json_decode( $site_options['wp_upgrades'], true ) : array();
        $ignored_core_upgrades = isset( $site_options['ignored_wp_upgrades'] ) ? json_decode( $site_options['ignored_wp_upgrades'], true ) : array();

        if ( $website['is_ignoreCoreUpdates'] || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
            $wp_upgrades = array();
        }

        if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
            ++$total_wp_upgrades;
        }

        $plugin_upgrades = json_decode( $website['plugin_upgrades'], true );

        if ( $website['is_ignorePluginUpdates'] ) {
            $plugin_upgrades = array();
        }

        $theme_upgrades = json_decode( $website['theme_upgrades'], true );

        if ( $website['is_ignoreThemeUpdates'] ) {
            $theme_upgrades = array();
        }

        $decodedPremiumUpgrades = isset( $site_options['premium_upgrades'] ) ? json_decode( $site_options['premium_upgrades'], true ) : array();
        if ( is_array( $decodedPremiumUpgrades ) ) {
            foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                $premiumUpgrade['premium'] = true;

                if ( 'plugin' === $premiumUpgrade['type'] ) {
                    if ( ! is_array( $plugin_upgrades ) ) {
                        $plugin_upgrades = array();
                    }
                    if ( ! $website['is_ignorePluginUpdates'] ) {
                        $plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
                    }
                } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                    if ( ! is_array( $theme_upgrades ) ) {
                        $theme_upgrades = array();
                    }
                    if ( ! $website['is_ignoreThemeUpdates'] ) {
                        $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                    }
                }
            }
        }

        if ( is_array( $plugin_upgrades ) ) {

            $ignored_plugins = json_decode( $website['ignored_plugins'], true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

            }

            $ignored_plugins = json_decode( $this->userExtension->ignored_plugins, true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
            }

            $total_plugin_upgrades += count( $plugin_upgrades );
        }

        if ( is_array( $theme_upgrades ) ) {

            $ignored_themes = json_decode( $website['ignored_themes'], true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            $ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            $total_theme_upgrades += count( $theme_upgrades );
        }

        $total_updates = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

        if ( 5 < $total_updates ) {
            $a_color = 'red';
        } elseif ( 0 < $total_updates && 5 >= $total_updates ) {
            $a_color = 'yellow';
        } else {
            $a_color = 'green';
        }

        if ( 5 < $total_wp_upgrades ) {
            $w_color = 'red';
        } elseif ( 0 < $total_wp_upgrades && 5 >= $total_wp_upgrades ) {
            $w_color = 'yellow';
        } else {
            $w_color = 'green';
        }

        if ( 5 < $total_plugin_upgrades ) {
            $p_color = 'red';
        } elseif ( 0 < $total_plugin_upgrades && 5 >= $total_plugin_upgrades ) {
            $p_color = 'yellow';
        } else {
            $p_color = 'green';
        }

        if ( 5 < $total_theme_upgrades ) {
            $t_color = 'red';
        } elseif ( 0 < $total_theme_upgrades && 5 >= $total_theme_upgrades ) {
            $t_color = 'yellow';
        } else {
            $t_color = 'green';
        }

        $hasSyncErrors = ( '' !== $website['sync_errors'] );
        $suspendedSite = ( '0' !== $website['suspended'] );

        if ( $hasSyncErrors ) {
            $a_color = '';
            $w_color = '';
            $p_color = '';
            $t_color = '';
        }

        $health_status = isset( $website['health_site_status'] ) ? json_decode( $website['health_site_status'], true ) : array();
        $hstatus       = MainWP_Utility::get_site_health( $health_status );
        $hval          = $hstatus['val'];
        $critical      = $hstatus['critical'];

        if ( 80 <= $hval && empty( $critical ) ) {
            $h_color = 'green';
            $h_text  = esc_html__( 'Good', 'mainwp' );
        } else {
            $h_color = 'orange';
            $h_text  = esc_html__( 'Should be improved', 'mainwp' );
        }

        if ( $hasSyncErrors ) {
            $h_color = '';
            $h_color = '';
        }

        $note       = html_entity_decode( $website['note'] );
        $esc_note   = MainWP_Utility::esc_content( $note );
        $strip_note = wp_strip_all_tags( $esc_note );

        list( $columns ) = $this->get_column_info();

        $use_favi = get_option( 'mainwp_use_favicon', 1 );

        $http_error_codes = MainWP_Utility::get_http_codes();

        $website = apply_filters( 'mainwp_sitestable_website', $website, $this->userExtension );

        $imgfavi = '';
        if ( $use_favi ) {
            $siteObj  = (object) $website;
            $favi_url = MainWP_Connect::get_favico_url( $siteObj );
            if ( ! empty( $favi_url ) ) {
                $imgfavi = '<img src="' . esc_attr( $favi_url ) . '" style="width:28px;height:28px;" class="ui circular image" />';
            } else {
                $imgfavi = '<i class="icon big wordpress"></i> '; // phpcs:ignore -- Prevent modify WP icon.
            }
        }

        foreach ( $columns as $column_name => $column_display_name ) {

            $classes    = "collapsing center aligned $column_name column-$column_name";
            $attributes = "class='$classes'";

            $rendered_custom_column = apply_filters( 'mainwp_sitestable_render_column', false, $column_name, $website, $classes );
            if ( $rendered_custom_column ) {
                continue;
            }

            ?>
            <?php if ( 'cb' === $column_name ) { ?>
                <td class="check-column">
                    <div class="ui checkbox">
                        <input type="checkbox" value="<?php echo intval( $website['id'] ); ?>" name=""/>
                    </div>
                </td>
            <?php } elseif ( 'status' === $column_name ) { ?>
                <td class="center aligned collapsing mainwp-status-cell">
                    <?php if ( $hasSyncErrors ) : ?>
                        <span><a class="mainwp_site_reconnect" href="#"><i class="circular inverted red unlink icon"></i></a></span>
                    <?php else : ?>
                        <span><a class="managesites_syncdata" href="#"><?php echo $suspendedSite ? '<i class="pause circular yellow inverted icon"></i>' : '<i class="circular inverted green check icon"></i>'; ?></a></span>
                    <?php endif; ?>
                </td>
                <?php
            } elseif ( 'favicon' === $column_name ) {
                ?>
                    <td class="collapsing no-sort mainwp-favicon-cell">
                    <?php echo $imgfavi; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </td>
                    <?php
            } elseif ( 'site' === $column_name ) {
                $cls_site = '';
                if ( '' !== $website['sync_errors'] ) {
                    $cls_site = 'site-sync-error';
                }
                ?>
                <td class="column-site-bulk mainwp-site-cell collapsing all <?php echo esc_attr( $cls_site ); ?>"><a href="<?php echo 'admin.php?page=managesites&dashboard=' . intval( $website['id'] ); ?>"><?php echo esc_html( stripslashes( $website['name'] ) ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php echo esc_attr( $website['id'] ); ?>" class="status hidden"></span></td>
            <?php } elseif ( 'login' === $column_name ) { ?>
                <td class="collapsing mainwp-wp-admin-cell">
                <?php if ( ! \mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
                    <i class="sign in icon"></i>
                <?php else : ?>
                    <a href="<?php MainWP_Site_Open::get_open_site_url( $website['id'] ); ?>" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
                <?php endif; ?>
                </td>
                <?php
            } elseif ( 'client_name' === $column_name ) {
                ?>
                <td class="collapsing mainwp-client-cell">
                <a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website['client_id'] ); ?>"><?php echo esc_html( $website['client_name'] ); ?></a>
                </td>
                <?php
            } elseif ( 'url' === $column_name ) {
                ?>
                <td class="mainwp-url-cell collapsing"><a href="<?php echo esc_url( $website['url'] ); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html( MainWP_Utility::get_nice_url( $website['url'] ) ); ?></a></td>
                <?php
            } elseif ( 'tags' === $column_name ) {
                ?>
                <td class="collapsing mainwp-tags-cell"><?php echo MainWP_System_Utility::get_site_tags_belong( $website ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <?php
            } elseif ( 'update' === $column_name ) {
                ?>
                <td class="collapsing center aligned mainwp-update-cell"><a class="ui mini compact button <?php echo esc_attr( $a_color ); ?>" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>"><?php echo intval( $total_updates ); ?></a></td>
            <?php } elseif ( 'wpcore_update' === $column_name ) { ?>
                <td class="collapsing mainwp-wp-core-update-cell center aligned"><a class="ui basic mini compact button <?php echo esc_attr( $w_color ); ?>" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>&tab=wordpress-updates"><?php echo intval( $total_wp_upgrades ); ?></a></td>
            <?php } elseif ( 'plugin_update' === $column_name ) { ?>
                <td class="collapsing mainwp-plugin-update-cell center aligned"><a class="ui basic mini compact button <?php echo esc_attr( $p_color ); ?>" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>"><?php echo intval( $total_plugin_upgrades ); ?></a></td>
            <?php } elseif ( 'theme_update' === $column_name ) { ?>
                <td class="collapsing mainwp-theme-update-cell center aligned"><a class="ui basic mini compact button <?php echo esc_attr( $t_color ); ?>" href="admin.php?page=managesites&updateid=<?php echo intval( $website['id'] ); ?>&tab=themes-updates"><?php echo intval( $total_theme_upgrades ); ?></a></td>
            <?php } elseif ( 'last_sync' === $column_name ) { ?>
                <td class="collapsing mainwp-last-sync-cell"><?php echo ! empty( $website['dtsSync'] ) ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['dtsSync'] ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            <?php } elseif ( 'last_post' === $column_name ) { ?>
                <td class="collapsing mainwp-last-post-cell"><?php echo ! empty( $website['last_post_gmt'] ) ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website['last_post_gmt'] ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            <?php } elseif ( 'site_health' === $column_name ) { ?>
                <td class="collapsing mainwp-site-health-cell"><a class="open_newwindow_wpadmin" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website['id'] ); ?>&location=<?php echo esc_attr( base64_encode( 'site-health.php' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>&_opennonce=<?php echo esc_attr( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank"><span class="ui <?php echo esc_attr( $h_color ); ?> empty circular label"></span></a> <?php echo esc_html( $h_text ); ?></td>
            <?php } elseif ( 'status_code' === $column_name ) { ?>
                <td class="collapsing mainwp-status-code-cell" data-order="<?php echo esc_html( $website['http_response_code'] ); ?>">
                    <?php
                    if ( $website['http_response_code'] ) {
                        $code = $website['http_response_code'];
                        echo esc_html( $code );
                        if ( isset( $http_error_codes[ $code ] ) ) {
                            echo ' - ' . esc_html( $http_error_codes[ $code ] );
                        }
                    }
                    ?>
                </td>
            <?php } elseif ( 'notes' === $column_name ) { ?>
                <td class="collapsing center aligned mainwp-notes-cell">
                    <?php if ( '' === $website['note'] ) : ?>
                        <a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo intval( $website['id'] ); ?>"><i class="sticky note outline icon"></i></a>
                    <?php else : ?>
                        <a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo intval( $website['id'] ); ?>" data-tooltip="<?php echo substr( wp_unslash( $strip_note ), 0, 100 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
                    <?php endif; ?>
                        <span style="display: none" id="mainwp-notes-<?php echo intval( $website['id'] ); ?>-note"><?php echo wp_unslash( $esc_note ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                </td>
        <?php } elseif ( 'phpversion' === $column_name ) { ?>
                <td class="collapsing center aligned mainwp-php-cell"><?php echo esc_html( substr( $website['phpversion'], 0, 6 ) ); ?></td>
                <?php
        } elseif ( 'added_datetime' === $column_name ) {
            ?>
                <td class="collapsing center aligned mainwp-connected-cell" data-order="<?php echo intval( $website['added_timestamp'] ); ?>"><?php echo ! empty( $website['added_timestamp'] ) ? MainWP_Utility::format_date( MainWP_Utility::get_timestamp( $website['added_timestamp'] ) ) : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <?php
        } elseif ( 'site_actions' === $column_name ) {
            ?>
                    <td class="collapsing mainwp-site-actions-cell">
                        <div class="ui right pointing dropdown" style="z-index: 99;">
                            <a href="javascript:void(0)"><i class="ellipsis vertical icon"></i></a>
                            <div class="menu" siteid="<?php echo intval( $website['id'] ); ?>">
                <?php if ( '' !== $website['sync_errors'] ) : ?>
                            <a class="mainwp_site_reconnect item" href="#"><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
                            <?php else : ?>
                            <a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
                            <?php endif; ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) : ?>
                            <a class="item" href="admin.php?page=managesites&dashboard=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
                            <?php endif; ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) : ?>
                            <a class="item" href="admin.php?page=managesites&id=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Settings', 'mainwp' ); ?></a>
                            <?php endif; ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) : ?>
                            <a class="item" href="admin.php?page=managesites&scanid=<?php echo intval( $website['id'] ); ?>"><?php esc_html_e( 'Site Hardening', 'mainwp' ); ?></a>
                            <?php endif; ?>
                            <a class="item" site-name="<?php echo esc_html( $website['name'] ); ?>" site-id="<?php echo esc_html( $website['id'] ); ?>" onclick="return managesites_remove( this )"><?php esc_html_e( 'Remove Site', 'mainwp' ); ?></a>
                        <?php
                        /**
                         * Action: mainwp_manage_sites_action
                         *
                         * Adds custom manage sites action item.
                         *
                         * @param array $website Array containing website data.
                         *
                         * @since 4.1
                         */
                        do_action( 'mainwp_manage_sites_action', $website );
                        ?>
                            </div>
                        </div>
                    </td>
                <?php
        } elseif ( method_exists( $this, 'column_' . $column_name ) ) {
            echo "<td $attributes>"; // phpcs:ignore WordPress.Security.EscapeOutput
            echo call_user_func( array( $this, 'column_' . $column_name ), $website ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo '</td>';
        } else {
            echo "<td $attributes>"; // phpcs:ignore WordPress.Security.EscapeOutput
            echo $this->column_default( $website, $column_name ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo '</td>';
        }
        }
    }
}
