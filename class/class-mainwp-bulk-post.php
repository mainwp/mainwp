<?php
/**
 * MainWP Bulk Post Class
 */
namespace MainWP\Dashboard;

/**
 * MainWP Bulk Post
 */
class MainWP_Bulk_Post {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_mainwp_editpost', array( &$this, 'handle_edit_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpage' ) );
		add_action( 'init', array( &$this, 'create_post_type' ) );
		add_filter( 'post_updated_messages', array( &$this, 'post_updated_messages' ) );
	}

	/**
	 * Bulkpost Edit Handler.
	 */
	public function handle_edit_bulkpost() {

		$post_id = 0;
		if ( isset( $_POST['post_ID'] ) ) {
			$post_id = (int) $_POST['post_ID'];
		}

		if ( $post_id && isset( $_POST['select_sites_nonce'] ) && wp_verify_nonce( $_POST['select_sites_nonce'], 'select_sites_' . $post_id ) ) {
			check_admin_referer( 'update-post_' . $post_id );
			edit_post();

			$location = admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $post_id . '&message=1' );
			$location = apply_filters( 'redirect_post_location', $location, $post_id );
			wp_safe_redirect( $location );
			exit();
		}
	}

	/**
	 * Bulkpost Edit Redirector.
	 *
	 * @param string $location
	 * @param int    $post_id
	 * @return string $location
	 */
	public function redirect_edit_bulkpost( $location, $post_id ) {
		if ( $post_id ) {
			$location = admin_url( 'admin.php?page=PostBulkEdit&post_id=' . intval( $post_id ) );
		} else {
			$location = admin_url( 'admin.php?page=PostBulkAdd' );
		}

		return $location;
	}

	/**
	 * Bulkpage Edit Redirector.
	 *
	 * @param string $location
	 * @param int    $post_id
	 * @return string $location
	 */
	public function redirect_edit_bulkpage( $location, $post_id ) {

		if ( $post_id ) {
			$location = admin_url( 'admin.php?page=PageBulkEdit&post_id=' . intval( $post_id ) );
		} else {
			$location = admin_url( 'admin.php?page=PageBulkAdd' );
		}

		return $location;
	}


	/**
	 * Save Bulkpost.
	 *
	 * @param int $post_id
	 */
	public function save_bulkpost( $post_id ) {
		$_post = get_post( $post_id );

		if ( 'bulkpost' !== $_post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-post_' . $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['post_type'] ) || ( 'bulkpost' !== $_POST['post_type'] ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		$pid = MainWP_System::instance()->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
		MainWP_System::instance()->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
		MainWP_System::instance()->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
		MainWP_System::instance()->metaboxes->add_slug_handle( $post_id, 'bulkpost' );
		MainWP_Post_Page_Handler::add_sticky_handle( $post_id );
		do_action( 'mainwp_save_bulkpost', $post_id );

		if ( $pid == $post_id ) {
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpost' ), 10, 2 );
		} else {
			do_action( 'mainwp_before_redirect_posting_bulkpost', $_post );
			wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&id=' . $post_id . '&hideall=1' );
			die();
		}
	}

	/**
	 * Save Bulkpage.
	 *
	 * @param int $post_id
	 */
	public function save_bulkpage( $post_id ) {

		$_post = get_post( $post_id );

		if ( 'bulkpage' !== $_post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-post_' . $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['post_type'] ) || ( 'bulkpage' !== $_POST['post_type'] ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['mainwp_wpseo_metabox_save_values'] ) && ( ! empty( $_POST['mainwp_wpseo_metabox_save_values'] ) ) ) {
			return;
		}

		$pid = MainWP_System::instance()->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
		MainWP_System::instance()->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
		MainWP_Page::add_status_handle( $post_id );

		do_action( 'mainwp_save_bulkpage', $post_id );

		if ( $pid == $post_id ) {
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpage' ), 10, 2 );
		} else {
			do_action( 'mainwp_before_redirect_posting_bulkpage', $_post );
			wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&id=' . $post_id . '&hideall=1' );
			die();
		}
	}

	/**
	 * Create Post Type.
	 */
	public function create_post_type() {
		$queryable = is_plugin_active( 'mainwp-post-plus-extension/mainwp-post-plus-extension.php' ) ? true : false;
		$labels    = array(
			'name'               => _x( 'Bulkpost', 'bulkpost' ),
			'singular_name'      => _x( 'Bulkpost', 'bulkpost' ),
			'add_new'            => _x( 'Add New', 'bulkpost' ),
			'add_new_item'       => _x( 'Add New Bulkpost', 'bulkpost' ),
			'edit_item'          => _x( 'Edit Bulkpost', 'bulkpost' ),
			'new_item'           => _x( 'New Bulkpost', 'bulkpost' ),
			'view_item'          => _x( 'View Bulkpost', 'bulkpost' ),
			'search_items'       => _x( 'Search Bulkpost', 'bulkpost' ),
			'not_found'          => _x( 'No bulkpost found', 'bulkpost' ),
			'not_found_in_trash' => _x( 'No bulkpost found in Trash', 'bulkpost' ),
			'parent_item_colon'  => _x( 'Parent Bulkpost:', 'bulkpost' ),
			'menu_name'          => _x( 'Bulkpost', 'bulkpost' ),
		);

		$args = array(
			'labels'                 => $labels,
			'hierarchical'           => false,
			'description'            => 'description...',
			'supports'               => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			'public'                 => true,
			'show_ui'                => true,
			'show_in_nav_menus'      => false,
			'publicly_queryable'     => $queryable,
			'exclude_from_search'    => true,
			'has_archive'            => false,
			'query_var'              => false,
			'can_export'             => false,
			'rewrite'                => false,
			'capabilities'           => array(
				'edit_post'          => 'read',
				'edit_posts'         => 'read',
				'edit_others_posts'  => 'read',
				'publish_posts'      => 'read',
				'read_post'          => 'read',
				'read_private_posts' => 'read',
				'delete_post'        => 'read',
			),
		);

		register_post_type( 'bulkpost', $args );

		$labels = array(
			'name'               => _x( 'Bulkpage', 'bulkpage' ),
			'singular_name'      => _x( 'Bulkpage', 'bulkpage' ),
			'add_new'            => _x( 'Add New', 'bulkpage' ),
			'add_new_item'       => _x( 'Add New Bulkpage', 'bulkpage' ),
			'edit_item'          => _x( 'Edit Bulkpage', 'bulkpage' ),
			'new_item'           => _x( 'New Bulkpage', 'bulkpage' ),
			'view_item'          => _x( 'View Bulkpage', 'bulkpage' ),
			'search_items'       => _x( 'Search Bulkpage', 'bulkpage' ),
			'not_found'          => _x( 'No bulkpage found', 'bulkpage' ),
			'not_found_in_trash' => _x( 'No bulkpage found in Trash', 'bulkpage' ),
			'parent_item_colon'  => _x( 'Parent Bulkpage:', 'bulkpage' ),
			'menu_name'          => _x( 'Bulkpage', 'bulkpage' ),
		);

		$args = array(
			'labels'                 => $labels,
			'hierarchical'           => false,
			'description'            => 'description...',
			'supports'               => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			'public'                 => true,
			'show_ui'                => true,
			'show_in_nav_menus'      => false,
			'publicly_queryable'     => $queryable,
			'exclude_from_search'    => true,
			'has_archive'            => false,
			'query_var'              => false,
			'can_export'             => false,
			'rewrite'                => false,
			'capabilities'           => array(
				'edit_post'          => 'read',
				'edit_posts'         => 'read',
				'edit_others_posts'  => 'read',
				'publish_posts'      => 'read',
				'read_post'          => 'read',
				'read_private_posts' => 'read',
				'delete_post'        => 'read',
			),
		);

		register_post_type( 'bulkpage', $args );
	}

	/**
	 * Render post updated message.
	 *
	 * @param mixed $messages Message to display.
	 *
	 * @return string $messages.
	 */
	public function post_updated_messages( $messages ) {
		$messages['post'][98] = esc_html__( 'WordPress SEO values have been saved.', 'mainwp' );
		$messages['post'][99] = esc_html__( 'You have to select the sites you wish to publish to.', 'mainwp' );

		return $messages;
	}

}
