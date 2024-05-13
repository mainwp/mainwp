<?php
/**
 * MainWP Logs Connectors.
 *
 * This file handles all interactions with the Client DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

/**
 * Class Log_Connectors
 */
class Log_Connectors {

	/**
	 * Holds instance of manager object
	 *
	 * @var Log_Manager
	 */
	public $manager;

	/**
	 * Connectors registered
	 *
	 * @var array
	 */
	public $connectors = array();

	/**
	 * Contexts registered to Connectors
	 *
	 * @var array
	 */
	public $contexts = array();

	/**
	 * Action taxonomy terms
	 * Holds slug to localized label association
	 *
	 * @var array
	 */
	public $term_labels = array(
		'logs_connector' => array(),
		'logs_context'   => array(),
		'logs_action'    => array(),
	);

	/**
	 * Constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @param Log_Manager $manager The main manager class.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
		$this->load_connectors();
	}

	/**
	 * Load built-in connectors
	 *
	 * @uses \MainWP\Dashboard\Module\Log\Log_Connector
	 */
	public function load_connectors() { //phpcs:ignore -- complex method.
		$connectors = $this->manager->get_internal_connectors();

		$enabled_logging = ! empty( $this->manager->settings->options['enabled'] ) ? true : false;
		if ( $enabled_logging ) {
			$connectors = array_merge(
				$connectors,
				array(
					'site',
					'client',
					'posts',
					'installer',
					'user',
				)
			);
		}

		$classes = array();
		foreach ( $connectors as $connector ) {
			include_once $this->manager->locations['dir'] . 'connectors/class-connector-' . $connector . '.php';
			$class_name = sprintf( '\MainWP\Dashboard\Module\Log\Connector_%s', str_replace( '-', '_', $connector ) );
			if ( ! class_exists( $class_name ) ) {
				continue;
			}
			$class                   = new $class_name( $this->manager->log );
			$classes[ $class->name ] = $class;
		}

		if ( $enabled_logging ) {
			/**
			 * Allows for adding additional connectors via classes that extend Connector.
			 *
			 * @param array $classes An array of Connector objects.
			 */
			$this->connectors = apply_filters( 'mainwp_module_log_connectors', $classes, $this->manager->log );
		} else {
			$this->connectors = $classes; // do not load external connectors.
		}

		if ( empty( $this->connectors ) ) {
			return;
		}

		foreach ( $this->connectors as $connector ) {
			if ( ! method_exists( $connector, 'get_label' ) ) {
				continue;
			}
			$this->term_labels['logs_connector'][ $connector->name ] = $connector->get_label();
		}

		// Get excluded connectors.
		$excluded_connectors = array();

		foreach ( $this->connectors as $connector ) {
			if ( ! method_exists( $connector, 'get_label' ) ) {
				continue;
			}
			if ( ! method_exists( $connector, 'register' ) ) {
				continue;
			}
			if ( ! method_exists( $connector, 'get_context_labels' ) ) {
				continue;
			}
			if ( ! method_exists( $connector, 'get_action_labels' ) ) {
				continue;
			}

			// Check if the connectors extends the Connector class, if not skip it.
			if ( ! is_subclass_of( $connector, '\MainWP\Dashboard\Module\Log\Log_Connector' ) ) {
				continue;
			}

			// Store connector label.
			if ( ! in_array( $connector->name, $this->term_labels['logs_connector'], true ) ) {
				$this->term_labels['logs_connector'][ $connector->name ] = $connector->get_label();
			}

			$connector_name = $connector->name;
			$is_excluded    = in_array( $connector_name, $excluded_connectors, true );

			/**
			 * Allows excluded connectors to be overridden and registered.
			 *
			 * @param bool   $is_excluded         True if excluded, otherwise false.
			 * @param string $connector           The current connector's slug.
			 * @param array  $excluded_connectors An array of all excluded connector slugs.
			 */
			$is_excluded_connector = apply_filters( 'mainwp_module_log_check_connector_is_excluded', $is_excluded, $connector_name, $excluded_connectors );

			if ( $is_excluded_connector ) {
				continue;
			}

			$connector->register();

			// Link context labels to their connector.
			$this->contexts[ $connector->name ] = $connector->get_context_labels();

			// Add new terms to our label lookup array.
			$this->term_labels['logs_action']  = array_merge(
				$this->term_labels['logs_action'],
				$connector->get_action_labels()
			);
			$this->term_labels['logs_context'] = array_merge(
				$this->term_labels['logs_context'],
				$connector->get_context_labels()
			);
		}

		$labels = $this->term_labels['logs_connector'];

		/**
		 * Fires after all connectors have been registered.
		 *
		 * @param array      $labels     All register connectors labels array
		 * @param Connectors $connectors The Connectors object
		 */
		do_action( 'mainwp_module_log_after_connectors_registration', $labels, $this );
	}
}
