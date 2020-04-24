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
 * 
 * phpcs:disable PSR1.Classes.ClassDeclaration,Generic.Files.OneObjectStructurePerFile,WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions - Deprecated
 */
class MainWP_Live_Report_Responder_Activator {

	/**
	 * @var boolean Check if MainWP is enabled.
	 */
	protected $mainwpMainActivated = false;

	/**
	 * @var boolean $childEnabled Check if MainWP Child plugin is enabled.
	 */
	protected $childEnabled        = false;

	/**
	 * @var boolean $childkey Child Site Key, false by default.
	 */
	protected $childKey            = false;

	/**
	 * @var undefined Child File.
	 */
	protected $childFile;
	
	/**
	 * @var string $plugin_handle Etension Handle.
	 */
	protected $plugin_handle    = 'mainwp-client-reports-extension';

	/**
	 * @var string $produc_id Extention Name.
	 */
	protected $product_id       = 'Managed Client Reports Responder';

	/**
	 * @var string $software_version Extension version.
	 */
	protected $software_version = '1.1';

	/**
	 * Instanciate Plugin.
	 */
	public function __construct() {

		$this->childFile           = __FILE__;
		$this->mainwpMainActivated = apply_filters( 'mainwp_activated_check', false );

		if ( false !== $this->mainwpMainActivated ) {
			$this->activate_this_plugin();
		} else {
			add_action( 'mainwp_activated', array( &$this, 'activate_this_plugin' ) );
		}
	}

	/**
	 * Activate Plugin.
	 */
	public function activate_this_plugin() {

		$this->mainwpMainActivated = apply_filters( 'mainwp_activated_check', $this->mainwpMainActivated );
		$this->childEnabled        = apply_filters( 'mainwp_extension_enabled_check', __FILE__ );
		$this->childKey            = $this->childEnabled['key'];
		if ( function_exists( 'mainwp_current_user_can' ) && ! mainwp_current_user_can( 'extension', 'mainwp-client-reports-extension' ) ) {
			return;
		}

		new MainWP_Live_Report_Responder();
	}

	/**
	 * Get Child Key.
	 * 
	 * @return mixed Child Key.
	 */
	public function get_child_key() {

		return $this->childKey;
	}

	/**
	 * Get Child File.
	 * 
	 * @return mixed Child File.
	 */
	public function get_child_file() {

		return $this->childFile;
	}

	/**
	 * Activate Plugin.
	 */
	public function activate() {
	}

	/**
	 * Deactivate Plugin.
	 */
	public function deactivate() {
	}

}

global $mainwpLiveReportResponderActivator;
$mainwpLiveReportResponderActivator = new MainWP_Live_Report_Responder_Activator();

