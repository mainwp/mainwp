<?php
/**
 * MainWP Monitoring Sites Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Monitoring Sites Page
 */
class MainWP_Monitoring {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Sub pages
	 *
	 * @static
	 * @var array $subPages Sub pages.
	 */
	public static $subPages;

	/**
	 * Current page.
	 *
	 * @static
	 * @var string $page Current page.
	 */
	public static $page;

	/**
	 * Magage Sites table
	 *
	 * @var $sitesTable Magage Sites table.
	 */
	public static $sitesTable;

	/**
	 * Method init_menu()
	 *
	 * Add Monitoring Sub Menu.
	 */
	/** Initiate menu. */
	public static function init_menu() {
		self::$page = add_submenu_page(
			'mainwp_tab',
			__( 'Monitoring', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Monitoring', 'mainwp' ) . '</div>',
			'read',
			'MonitoringSites',
			array(
				self::get_class_name(),
				'render_all_sites',
			)
		);
		add_action( 'load-' . self::$page, array( self::get_class_name(), 'on_load_page' ) );
	}


	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 */
	public static function on_load_page() {
		// MainWP_System::enqueue_postbox_scripts();
		self::$sitesTable = new MainWP_Monitoring_Sites_List_Table();
	}


	/**
	 * Method render_all_sites()
	 *
	 * Render monitoring sites content.
	 *
	 * @return html MainWP Groups Table.
	 */
	public static function render_all_sites() {

		if ( ! mainwp_current_user_have_right( 'dashboard', 'monitoring_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'monitoring sites', 'mainwp' ) );

			return;
		}

		$optimize_for_sites_table = ( 1 == get_option( 'mainwp_optimize' ) );

		if ( ! $optimize_for_sites_table ) {
			self::$sitesTable->prepare_items( false );
		}

		do_action( 'mainwp_pageheader_sites', 'MonitoringSites' );

		?>
		<div id="mainwp-manage-sites-content" class="ui segment">
			<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
			<form method="post" class="mainwp-table-container">
				<?php
				wp_nonce_field( 'mainwp-admin-nonce' );
				self::$sitesTable->display( $optimize_for_sites_table );
				self::$sitesTable->clear_items();
				?>
			</form>
		</div>		
		<?php

		do_action( 'mainwp_pagefooter_sites', 'MonitoringSites' );
	}


	/**
	 * Method ajax_optimize_display_rows()
	 *
	 * Display table rows, optimize for shared hosting or big networks.
	 */
	public static function ajax_optimize_display_rows() {
		self::$sitesTable = new MainWP_Monitoring_Sites_List_Table();
		self::$sitesTable->prepare_items( true );
		$output = self::$sitesTable->ajax_get_datatable_rows();
		self::$sitesTable->clear_items();
		wp_send_json( $output );
	}

}
