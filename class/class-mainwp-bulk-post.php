<?php
/**
 * MainWP Bulk Post Class
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Bulk_Post
 *
 * @package MainWP\Dashboard
 */
class MainWP_Bulk_Post {

	/**
	 * MainWP_Bulk_Post constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
		add_action( 'admin_post_mainwp_editpost', array( &$this, 'handle_edit_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpost' ) );
		add_action( 'save_post', array( &$this, 'save_bulkpage' ) );
		add_action( 'init', array( &$this, 'create_post_type' ) );
		add_filter( 'post_updated_messages', array( &$this, 'post_updated_messages' ) );
	}

	/**
	 * Method handle_edit_bulkpost()
	 *
	 * Handle bulk post edit process.
	 */
	public function handle_edit_bulkpost() {

		$post_id = 0;
		if ( isset( $_POST['post_ID'] ) ) {
			$post_id = (int) $_POST['post_ID'];
		}

		if ( $post_id && isset( $_POST['select_sites_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['select_sites_nonce'] ), 'select_sites_' . $post_id ) ) {
			check_admin_referer( 'update-post_' . $post_id );
			edit_post();

			$location = admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $post_id . '&message=1' );

			/**
			 * Filter: redirect_post_location
			 *
			 * Filters the location for the Edit process.
			 *
			 * @param int $post_id Post ID.
			 *
			 * @since Unknown
			 */
			$location = apply_filters( 'redirect_post_location', $location, $post_id );
			wp_safe_redirect( $location );
			exit();
		}
	}

	/**
	 * Method redirect_edit_bulkpost().
	 *
	 * Redirect to edit post.
	 *
	 * @param string $location Target URL.
	 * @param int    $post_id Post ID number.
	 *
	 * @return string $location Target URL.
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
	 * Method redirect_edit_bulkpage().
	 *
	 * Redirect to edit page.
	 *
	 * @param string $location Target URL.
	 * @param int    $post_id Page ID number.
	 *
	 * @return string $location Target URL.
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
	 * Method save_bulkpost().
	 *
	 * Save page (Bulk post custom post type).
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Meta_Boxes::select_sites_handle()
	 * @uses \MainWP\Dashboard\MainWP_Meta_Boxes::add_categories_handle()
	 * @uses \MainWP\Dashboard\MainWP_Meta_Boxes::add_slug_handle()
	 * @uses \MainWP\Dashboard\MainWP_Post_Page_Handler::add_sticky_handle()
	 */
	public function save_bulkpost( $post_id ) {
		$_post = get_post( $post_id );

		if ( 'bulkpost' !== $_post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'update-post_' . $post_id ) ) {
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

		$pid = (int) MainWP_System::instance()->metaboxes->select_sites_handle( $post_id, 'bulkpost' );
		MainWP_System::instance()->metaboxes->add_categories_handle( $post_id, 'bulkpost' );
		MainWP_System::instance()->metaboxes->add_tags_handle( $post_id, 'bulkpost' );
		MainWP_System::instance()->metaboxes->add_slug_handle( $post_id, 'bulkpost' );
		MainWP_Post_Page_Handler::add_sticky_handle( $post_id );
		MainWP_Post_Page_Handler::add_status_handle( $post_id );

		/**
		 * Action: mainwp_save_bulkpost
		 *
		 * Fires when saving the bulk post.
		 *
		 * @param int $post_id Post ID.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_save_bulkpost', $post_id );

		if ( $pid === $post_id ) {
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpost' ), 10, 2 );
		} else {
			/**
			 * Action: mainwp_before_redirect_posting_bulkpost
			 *
			 * Fires before redirection to posting 'bulk post' page after post submission.
			 *
			 * @param object $_post Object containing post data.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_before_redirect_posting_bulkpost', $_post );
			wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&id=' . $post_id . '&hideall=1' );
			die();
		}
	}

	/**
	 * Method save_bulkpage().
	 *
	 * Save page (Bulk page custom post type).
	 *
	 * @param int $post_id Page ID.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::$metaboxes
	 * @uses \MainWP\Dashboard\MainWP_Page::add_status_handle()
	 */
	public function save_bulkpage( $post_id ) {

		$_post = get_post( $post_id );

		if ( 'bulkpage' !== $_post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'update-post_' . $post_id ) ) {
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

		$pid = (int) MainWP_System::instance()->metaboxes->select_sites_handle( $post_id, 'bulkpage' );
		MainWP_System::instance()->metaboxes->add_slug_handle( $post_id, 'bulkpage' );
		MainWP_Post_Page_Handler::add_status_handle( $post_id );

		/**
		 * Action: mainwp_save_bulkpage
		 *
		 * Fires when saving the bulk page.
		 *
		 * @param int $post_id Post ID.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_save_bulkpage', $post_id );

		if ( $pid === $post_id ) {
			add_filter( 'redirect_post_location', array( $this, 'redirect_edit_bulkpage' ), 10, 2 );
		} else {
			/**
			 * Action: mainwp_before_redirect_posting_bulkpage
			 *
			 * Fires before redirection to posting 'bulk page' page after post submission.
			 *
			 * @param object $_post Object containing post data.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_before_redirect_posting_bulkpage', $_post );
			wp_safe_redirect( get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&id=' . $post_id . '&hideall=1' );
			die();
		}
	}

	/**
	 * Method create_post_type()
	 *
	 * Register "Bulkpost" and "Bulkpage" custom post types.
	 */
	public function create_post_type() {

		$queryable = true;
		if ( function_exists( 'is_plugin_active' ) ) {
			$queryable = is_plugin_active( 'mainwp-post-plus-extension/mainwp-post-plus-extension.php' ) ? true : false;
		}

		$labels = array(
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
			'labels'              => $labels,
			'hierarchical'        => false,
			'description'         => 'description...',
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			'public'              => true,
			'show_ui'             => true,
			'show_in_nav_menus'   => false,
			'publicly_queryable'  => $queryable,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'query_var'           => false,
			'can_export'          => false,
			'rewrite'             => false,
			'capabilities'        => array(
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
			'labels'              => $labels,
			'hierarchical'        => false,
			'description'         => 'description...',
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'comments',
				'revisions',
			),
			'public'              => true,
			'show_ui'             => true,
			'show_in_nav_menus'   => false,
			'publicly_queryable'  => $queryable,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'query_var'           => false,
			'can_export'          => false,
			'rewrite'             => false,
			'capabilities'        => array(
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

		do_action( 'mainwp_register_post_type' );
	}

	/**
	 * Method post_updated_messages()
	 *
	 * Render post updated message.
	 *
	 * @param string $messages Message to display.
	 *
	 * @return string $messages.
	 */
	public function post_updated_messages( $messages ) {
		$messages['post'][98] = esc_html__( 'WordPress SEO values have been saved.', 'mainwp' );
		$messages['post'][99] = esc_html__( 'You have to select the sites you wish to publish to.', 'mainwp' );

		return $messages;
	}
}
