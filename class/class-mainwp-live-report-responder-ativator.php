<?php
/**
 * MainWP Client Live Reports
 *
 * Legacy Client Reports Extension.
 */
namespace MainWP\Dashboard;

/**
 * Class MainWP_Live_Report_Responder_Activator
 *
 * @deprecated moved to external Extension.
 *  phpcs:disable PSR1.Classes.ClassDeclaration,Generic.Files.OneObjectStructurePerFile,WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions - Deprecated
 */
class MainWP_Live_Report_Responder_Activator {

	protected $mainwpMainActivated = false;
	protected $childEnabled        = false;
	protected $childKey            = false;
	protected $childFile;
	protected $plugin_handle    = 'mainwp-client-reports-extension';
	protected $product_id       = 'Managed Client Reports Responder';
	protected $software_version = '1.1';

	public function __construct() {

		$this->childFile           = __FILE__;
		$this->mainwpMainActivated = apply_filters( 'mainwp_activated_check', false );

		if ( false !== $this->mainwpMainActivated ) {
			$this->activate_this_plugin();
		} else {
			add_action( 'mainwp_activated', array( &$this, 'activate_this_plugin' ) );
		}
	}

	public function activate_this_plugin() {

		$this->mainwpMainActivated = apply_filters( 'mainwp_activated_check', $this->mainwpMainActivated );
		$this->childEnabled        = apply_filters( 'mainwp_extension_enabled_check', __FILE__ );
		$this->childKey            = $this->childEnabled['key'];
		if ( function_exists( 'mainwp_current_user_can' ) && ! mainwp_current_user_can( 'extension', 'mainwp-client-reports-extension' ) ) {
			return;
		}

		new MainWP_Live_Report_Responder();
	}

	public function get_child_key() {

		return $this->childKey;
	}

	public function get_child_file() {

		return $this->childFile;
	}


	public function activate() {
	}

	public function deactivate() {
	}

}

global $mainwpLiveReportResponderActivator;
$mainwpLiveReportResponderActivator = new MainWP_Live_Report_Responder_Activator();

