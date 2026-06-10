<?php
/**
 * MainWP Legacy Backups Page (deprecated shim).
 *
 * The Backups page was split into MainWP_Backup_Page,
 * MainWP_Backup_List_View, MainWP_Backup_Task_Form, and
 * MainWP_Backup_Settings_View in pages/backup/. This shim keeps the old
 * class name working for extensions; every method delegates to the new
 * classes and emits a deprecation notice.
 *
 * Known compatibility limits of the shim: subclasses that add column_*
 * methods or rely on late static binding of the old singleton are no
 * longer dispatched, and the public $subPages property is only synced
 * back after init_menu() runs through this class.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Manage_Backups
 *
 * @deprecated 6.2 Use MainWP_Backup_Page, MainWP_Backup_List_View, MainWP_Backup_Task_Form, and MainWP_Backup_Settings_View instead.
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Backups { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Subpages variable.
     *
     * @deprecated 6.2 Only synced after init_menu() runs through this class. Use MainWP_Backup_Page::$subPages instead.
     *
     * @var array|null $subPages Subpages variable.
     */
    public static $subPages;

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::get_class_name() instead.
     *
     * @return string Class name of the new page class.
     */
    public static function get_class_name() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::get_class_name' );
        return MainWP_Backup_Page::get_class_name();
    }

    /**
     * Create instance.
     *
     * Returns the list view so legacy calls such as
     * MainWP_Manage_Backups::instance()->display() keep working.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::instance() or MainWP_Backup_List_View::instance() instead.
     *
     * @return MainWP_Backup_List_View List view instance.
     */
    public static function instance() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::instance' );
        return MainWP_Backup_List_View::instance();
    }

    /**
     * Instantiate Hooks.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::init() instead.
     */
    public static function init() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::init' );
        MainWP_Backup_Page::init();
    }

    /**
     * Instantiate Legacy Backups Menu.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::init_menu() instead.
     */
    public static function init_menu() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::init_menu' );
        MainWP_Backup_Page::init_menu();
        static::$subPages = MainWP_Backup_Page::$subPages;
    }

    /**
     * Instantiate legacy backups subpages.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::init_subpages_menu() instead.
     */
    public static function init_subpages_menu() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::init_subpages_menu' );
        MainWP_Backup_Page::init_subpages_menu();
    }

    /**
     * Instantiate Legacy Backups Menu.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::init_left_menu() instead.
     *
     * @param array $subPages Legacy Backup Subpages.
     * @param bool  $enableLegacyBackup true|false, whether or not to enable menu.
     */
    public static function init_left_menu( $subPages = array(), $enableLegacyBackup = true ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::init_left_menu' );
        MainWP_Backup_Page::init_left_menu( $subPages, $enableLegacyBackup );
    }

    /**
     * Render MainWP Legacy Backups Page Header.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::render_header() instead.
     *
     * @param string $shownPage The page slug shown at this moment.
     */
    public static function render_header( $shownPage = '' ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::render_header' );
        MainWP_Backup_Page::render_header( $shownPage );
    }

    /**
     * Method render_backup_environment_notices()
     *
     * Display admin notices for backup environment issues.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::render_backup_environment_notices() instead.
     *
     * @return void
     */
    public static function render_backup_environment_notices() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::render_backup_environment_notices' );
        MainWP_Backup_Page::render_backup_environment_notices();
    }

    /**
     * Render MainWP Legacy Backups Footer.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::render_footer() instead.
     *
     * @param string $shownPage The page slug shown at this moment.
     */
    public static function render_footer( $shownPage = '' ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::render_footer' );
        MainWP_Backup_Page::render_footer( $shownPage );
    }

    /**
     * Render Legacy Backups page.
     *
     * @deprecated 6.2 Use MainWP_Backup_Page::render_manager() instead.
     */
    public static function render_manager() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Page::render_manager' );
        MainWP_Backup_Page::render_manager();
    }

    /**
     * Render MainWP Legacy Backups Table.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::display() instead.
     *
     * @param mixed $backup_items List Item.
     */
    public function display( $backup_items ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::display' );
        MainWP_Backup_List_View::instance()->display( $backup_items );
    }

    /**
     * Single row Content.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::single_row() instead.
     *
     * @param mixed $item Item to go in column.
     * @param mixed $columns Columns Array.
     */
    public function single_row( $item, $columns ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::single_row' );
        MainWP_Backup_List_View::instance()->single_row( $item, $columns );
    }

    /**
     * Column Actions.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_actions() instead.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_actions( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_actions' );
        return MainWP_Backup_List_View::instance()->column_actions( $item );
    }

    /**
     * Column Task Name.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_task_name() instead.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_task_name( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_task_name' );
        return MainWP_Backup_List_View::instance()->column_task_name( $item );
    }

    /**
     * Column Type.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_type() instead.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_type( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_type' );
        return MainWP_Backup_List_View::instance()->column_type( $item );
    }

    /**
     * Column Schedule.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_schedule() instead.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_schedule( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_schedule' );
        return MainWP_Backup_List_View::instance()->column_schedule( $item );
    }

    /**
     * Column Destination.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_destination() instead.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_destination( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_destination' );
        return MainWP_Backup_List_View::instance()->column_destination( $item );
    }

    /**
     * Column Websites.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_websites() instead.
     *
     * @param mixed $item Item to go in column.
     */
    public function column_websites( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_websites' );
        MainWP_Backup_List_View::instance()->column_websites( $item );
    }

    /**
     * Column Details.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_details() instead.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_details( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_details' );
        return MainWP_Backup_List_View::instance()->column_details( $item );
    }

    /**
     * Column Trigger.
     *
     * @deprecated 6.2 Use MainWP_Backup_List_View::column_trigger() instead.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_trigger( $item ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_List_View::column_trigger' );
        return MainWP_Backup_List_View::instance()->column_trigger( $item );
    }

    /**
     * Render edit.
     *
     * @deprecated 6.2 Use MainWP_Backup_Task_Form::render_edit() instead.
     *
     * @param mixed $task Task to edit.
     */
    public static function render_edit( $task ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Task_Form::render_edit' );
        MainWP_Backup_Task_Form::render_edit( $task );
    }

    /**
     * Render New Task Form.
     *
     * @deprecated 6.2 Use MainWP_Backup_Task_Form::render_new() instead.
     */
    public static function render_new() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Task_Form::render_new' );
        MainWP_Backup_Task_Form::render_new();
    }

    /**
     * Render New edit Form.
     *
     * @deprecated 6.2 Use MainWP_Backup_Task_Form::render_new_edit() instead.
     *
     * @param mixed $task Task to edit.
     */
    public static function render_new_edit( $task ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Task_Form::render_new_edit' );
        MainWP_Backup_Task_Form::render_new_edit( $task );
    }

    /**
     * Render Scheduled Backup.
     *
     * @deprecated 6.2 Use MainWP_Backup_Task_Form::render_schedule_backup() instead.
     *
     * @return void
     */
    public static function render_schedule_backup() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Task_Form::render_schedule_backup' );
        MainWP_Backup_Task_Form::render_schedule_backup();
    }

    /**
     * Render Task Details.
     *
     * @deprecated 6.2 Use MainWP_Backup_Task_Form::render_task_details() instead.
     *
     * @param mixed $task Task to perform.
     * @param mixed $globalArchiveFormatText Global Archived Format Text.
     * @param mixed $archiveFormat Archive Format.
     * @param mixed $useGlobal Use Global.
     * @param mixed $useSite Use Site.
     */
    public static function render_task_details( $task, $globalArchiveFormatText, $archiveFormat, $useGlobal, $useSite ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Task_Form::render_task_details' );
        MainWP_Backup_Task_Form::render_task_details( $task, $globalArchiveFormatText, $archiveFormat, $useGlobal, $useSite );
    }

    /**
     * Method render_settings()
     *
     * Render backup settings.
     *
     * @deprecated 6.2 Use MainWP_Backup_Settings_View::render_settings() instead.
     */
    public static function render_settings() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Settings_View::render_settings' );
        MainWP_Backup_Settings_View::render_settings();
    }

    /**
     * Method render_individual_settings()
     *
     * Render per-site backup settings.
     *
     * @deprecated 6.2 Use MainWP_Backup_Settings_View::render_individual_settings() instead.
     *
     * @param mixed $website Website data.
     */
    public static function render_individual_settings( $website ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Settings_View::render_individual_settings' );
        MainWP_Backup_Settings_View::render_individual_settings( $website );
    }
}
