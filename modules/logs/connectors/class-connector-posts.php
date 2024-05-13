<?php
/**
 * Module Logs Site connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Posts
 *
 * @package MainWP\Dashboard
 */
class Connector_Posts extends Log_Connector {
	/**
	 * Connector slug
	 *
	 * @var string
	 */
	public $name = 'posts';

	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array(
		'mainwp_post_created',
		'mainwp_post_action',
	);

	/**
	 * Return translated connector label
	 *
	 * @return string Translated connector label
	 */
	public function get_label() {
		return esc_html__( 'Posts', 'mainwp' );
	}

	/**
	 * Return translated action labels
	 *
	 * @return array Action label translations
	 */
	public function get_action_labels() {
		return array(
			'published' => esc_html__( 'Published', 'mainwp' ),
			'updated'   => esc_html__( 'Updated', 'mainwp' ),
			'created'   => esc_html__( 'Created', 'mainwp' ),
			'trashed'   => esc_html__( 'Trashed', 'mainwp' ),
			'untrashed' => esc_html__( 'Restored', 'mainwp' ),
			'deleted'   => esc_html__( 'Deleted', 'mainwp' ),
		);
	}

	/**
	 * Register log data.
	 */
	public function register() { //phpcs:ignore -- overrided.
		parent::register();
	}

	/**
	 * Return translated context labels
	 *
	 * @return array Context label translations
	 */
	public function get_context_labels() {
		global $wp_post_types;
		$post_types = wp_filter_object_list( $wp_post_types, array(), null, 'label' );
		add_action( 'registered_post_type', array( $this, 'registered_post_type' ), 10, 2 );
		return $post_types;
	}

	/**
	 * Catch registration of post_types after initial loading, to cache its labels
	 *
	 * @action registered_post_type
	 *
	 * @param string $post_type Post type slug.
	 * @param array  $args      Arguments used to register the post type.
	 */
	public function registered_post_type( $post_type, $args ) {
		unset( $args );

		$post_type_obj = get_post_type_object( $post_type );
		$label         = $post_type_obj->label;

		Log_Manager::instance()->connectors->term_labels['logs_context'][ $post_type ] = $label;
	}

	/**
	 * Log create post.
	 *
	 * @action mainwp_post_created
	 *
	 * @param mixed  $website website.
	 * @param string $post_action post action.
	 * @param array  $data post data array.
	 */
	public function callback_mainwp_post_created( $website, $post_action, $data ) {
		if ( empty( $website ) || ! is_array( $data ) || empty( $data['post_title'] ) ) {
			return;
		}
		$action = '';
		if ( 'newpost' === $post_action ) {
			$action = 'created';
			if ( ! empty( $data['is_editing'] ) ) {
				$action = 'updated';
			}
		}

		if ( empty( $action ) ) {
			return;
		}

		// translators: Placeholders refer to a post title.
		$message = esc_html_x(
			'%1$s',
			'1: Post title',
			'mainwp'
		);

		$default = array(
			'post_title'    => '',
			'singular_name' => '',
		);
		$args    = MainWP_Utility::right_array_merge( $default, $data );

		$default_other      = array(
			'post_date'     => '',
			'post_date_gmt' => '',
			'new_status'    => '',
			'old_status'    => '',
			'post_id'       => '',
		);
		$other              = MainWP_Utility::right_array_merge( $default_other, $data );
		$args['siteurl']    = $website->url;
		$args['site_name']  = $website->name;
		$args['extra_info'] = wp_json_encode( $other );
		$state              = 1;
		$this->log(
			$message,
			$args,
			$website->id,
			$data['post_type'],
			$action,
			$state
		);
	}

	/**
	 * Log all post status changes ( creating / updating / trashing )
	 *
	 * @action mainwp_post_action
	 *
	 * @param object    $website website.
	 * @param string    $post_action post action.
	 * @param array     $data post data array.
	 * @param int|false $post_id post id.
	 * @param string    $type post|page.
	 */
	public function callback_mainwp_post_action( $website, $post_action, $data, $post_id, $type = '' ) {

		if ( empty( $website ) || ! is_array( $data ) || empty( $data['post_title'] ) ) {
			return;
		}

		$action = '';
		// translators: Placeholders refer to a post title, and a post type singular name (e.g. "Hello World", "Post").
		$message = esc_html_x(
			'%1$s',
			'1: Post title 2: singular name',
			'mainwp'
		);

		if ( 'publish' === $post_action ) {
			$action = 'published';
		} elseif ( 'update' === $post_action ) {
			$action = 'updated';
		} elseif ( 'unpublish' === $post_action ) {
			$action = 'unpublished';
		} elseif ( 'trash' === $post_action ) {
			$action = 'trashed';
		} elseif ( 'delete' === $post_action ) {
			$action = 'deleted';
		} elseif ( 'restore' === $post_action ) {
			$action = 'restored';
		} else {
			return;
		}

		$default = array(
			'post_title'    => '',
			'singular_name' => '',
			'post_date'     => '',
			'post_date_gmt' => '',
			'new_status'    => '',
			'old_status'    => '',
		);

		$args = MainWP_Utility::right_array_merge( $default, $data );

		$args['siteurl']   = $website->url;
		$args['site_name'] = $website->name;
		$state             = 1;
		$this->log(
			$message,
			$args,
			$website->id,
			$data['post_type'],
			$action,
			$state
		);
	}
}
