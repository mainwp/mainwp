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
	 * duration
	 *
	 * @var int
	 */
	public $duration;

	/**
	 * state
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
	 * Class constructor
	 *
	 * @param object $item  Record data object.
	 */
	public function __construct( $item ) {
		$this->log_id    = isset( $item->log_id ) ? $item->log_id : null;
		$this->created   = isset( $item->created ) ? $item->created : null;
		$this->site_id   = isset( $item->site_id ) ? $item->site_id : null;
		$this->name      = isset( $item->name ) ? $item->name : null;
		$this->url       = isset( $item->url ) ? $item->url : null;
		$this->user_id   = isset( $item->user_id ) ? $item->user_id : null;
		$this->user_meta = isset( $item->meta['user_meta'] ) ? $item->meta['user_meta'] : null;
		$this->item      = isset( $item->item ) ? $item->item : null;
		$this->connector = isset( $item->connector ) ? $item->connector : null;
		$this->context   = isset( $item->context ) ? $item->context : null;
		$this->action    = isset( $item->action ) ? $item->action : null;
		$this->state     = isset( $item->state ) ? $item->state : null;
		$this->duration  = isset( $item->duration ) ? $item->duration : null;
		$this->meta      = isset( $item->meta ) ? $item->meta : null;

		if ( isset( $this->meta['user_meta'] ) ) {
			unset( $this->meta['user_meta'] );
		}
	}

	/**
	 * Save record.
	 *
	 * @return int|WP_Error
	 */
	public function save() {
		if ( ! $this->validate() ) {
			return new \WP_Error( 'validation-error', esc_html__( 'Could not validate record data.', 'mainwp' ) );
		}

		Log_Manager::instance()->db->insert( (array) $this );
	}

	/**
	 * Populate "$this" object with provided data.
	 *
	 * @param array $raw  Data to be used to populate $this object.
	 */
	public function populate( array $raw ) {
		$keys = get_class_vars( $this );
		$data = array_intersect_key( $raw, $keys );
		foreach ( $data as $key => $val ) {
			$this->{$key} = $val;
		}
	}

	/**
	 * Validates this record
	 *
	 * @todo Add actual validation measures.
	 *
	 * @return bool
	 */
	public function validate() {
		return true;
	}
}
