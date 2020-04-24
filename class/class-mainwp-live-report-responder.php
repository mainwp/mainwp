<?php
/**
 * MainWP Client Live Reports
 *
 * Legacy Client Reports Extension.
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Live_Report_Responder
 *
 * @deprecated moved to external Extension.
 */
class MainWP_Live_Report_Responder {

	// phpcs:disable PSR1.Classes.ClassDeclaration,Generic.Files.OneObjectStructurePerFile,WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions - Deprecated

	/** @var null $instance Plugin Instance. */
	public static $instance = null;

	/** @var string $plugin_handle Plugin Handle. */
	public $plugin_handle = 'mainwp-wpcreport-extension';

	/** @var string $plugin_url Plugin Installation URL. */
	public static $plugin_url;

	/** @var string $plugin_slug Plugin Slug. */
	public $plugin_slug;

	/** @var string $plugin_dir Plugin Directory. */
	public $plugin_dir;

	/** @var mixed $option Option variable. */
	protected $option;

	/** @var string $option_handle Option Handle. */
	protected $option_handle = 'mainwp_wpcreport_extension';

	/**
	 * Create Instance.
	 *
	 * @return self $instance
	 */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct method.
	 */
	public function __construct() {

		$this->plugin_dir  = plugin_dir_path( __FILE__ );
		self::$plugin_url  = plugin_dir_url( __FILE__ );
		$this->plugin_slug = plugin_basename( __FILE__ );
		$this->option      = get_option( $this->option_handle );

		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		if ( ! in_array( 'mainwp-client-reports-extension/mainwp-client-reports-extension.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			MainWP_Live_Reports_Responder_DB::get_instance()->install();
		}
	}

	/**
	 * Initialize Admin.
	 */
	public function admin_init() {

		$translation_array = array( 'dashboard_sitename' => get_bloginfo( 'name' ) );
		MainWP_Live_Reports::init();
		$mwp_creport = new MainWP_Live_Reports();
		$mwp_creport->admin_init();
	}

}

// phpcs:enable
