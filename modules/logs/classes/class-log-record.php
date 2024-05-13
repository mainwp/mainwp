<?php
/**
 * Manages the state of a single record
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

/**
 * Class - Log_Record
 */
class Log_Record {

	/**
	 * Record log_id
	 *
	 * @var int
	 */
	public $log_id;

	/**
	 * Date record created
	 *
	 * @var string
	 */
	public $created;

	/**
	 * Site ID of the site where the record was created
	 *
	 * @var int
	 */
	public $site_id;


	/**
	 * User ID of the record creator
	 *
	 * @var int
	 */
	public $user_id;

	/**
	 * User role of the record creator
	 *
	 * @var string
	 */
	public $user_role;

	/**
	 * Record user meta data.
	 *
	 * @var string
	 */
	public $user_meta;


	/**
	 * Record log_site_name
	 *
	 * @var string
	 */
	public $log_site_name;

	/**
	 * Record url
	 *
	 * @var string
	 */
	public $url;

	/**
	 * Record item
	 *
	 * @var string
	 */
	public $item;

	/**
	 * Record connector
	 *
	 * @var string
	 */
	public $connector;

	/**
	 * Context record was made in.
	 *
	 * @var string
	 */
	public $context;

	/**
	 * Record action
	 *
	 * @var string
	 */
	public $action;

	/**
	 * Duration
	 *
	 * @var int
	 */
	public $duration;

	/**
	 * State
	 *
	 * @var int
	 */
	public $state;

	/**
	 * Record meta data
	 *
	 * @var array
	 */
	public $meta;

	/**
	 * Record extra_meta data
	 *
	 * @var array
	 */
	public $extra_meta;

	/**
	 * Class constructor
	 *
	 * @param object $log  Record data object.
	 */
	public function __construct( $log ) { //phpcs:ignore -- complex method.
		$this->log_id        = isset( $log->log_id ) ? $log->log_id : null;
		$this->created       = isset( $log->created ) ? $log->created : null;
		$this->site_id       = isset( $log->site_id ) ? $log->site_id : null;
		$this->log_site_name = isset( $log->log_site_name ) ? $log->log_site_name : null;
		$this->url           = isset( $log->url ) ? $log->url : null;
		$this->user_id       = isset( $log->user_id ) ? $log->user_id : null;
		$this->user_meta     = isset( $log->meta['user_meta'] ) ? $log->meta['user_meta'] : null;
		$this->item          = isset( $log->item ) ? $log->item : null;
		$this->connector     = isset( $log->connector ) ? $log->connector : null;
		$this->context       = isset( $log->context ) ? $log->context : null;
		$this->action        = isset( $log->action ) ? $log->action : null;
		$this->state         = isset( $log->state ) ? $log->state : null;
		$this->duration      = isset( $log->duration ) ? $log->duration : null;
		$this->meta          = isset( $log->meta ) ? $log->meta : null;
		$this->extra_meta    = isset( $log->extra_info ) ? $log->extra_info : null;

		if ( isset( $this->meta['user_meta'] ) ) {
			unset( $this->meta['user_meta'] );
		}
	}
}
