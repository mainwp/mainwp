<?php
/**
 * This file handles the addintion and updating of Post Meta Boxes.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Meta_Boxes
 *
 * @package MainWP\Dashboard
 */
class MainWP_Meta_Boxes {

	/**
	 * Method select_sites_handle()
	 *
	 * Update Post meta for Select Sites Meta boxes.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post_type Post type.
	 *
	 * @return int $post_id Post ID.
	 */
	public function select_sites_handle( $post_id, $post_type ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Verify this came from the our screen and with proper authorization.
		 */
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['select_sites_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['select_sites_nonce'] ), 'select_sites_' . $post_id ) ) {
			return $post_id;
		}

		/**
		 * Verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything.
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		/**
		 * Check permissions.
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		/**
		 * OK, we're authenticated: we need to find and save the data.
		 */
		$_post = get_post( $post_id );
		if ( $_post->post_type === $post_type && isset( $_POST['select_by'] ) ) {
			$selected_wp = array();
			if ( isset( $_POST['selected_sites'] ) ) {
				if ( is_array( $_POST['selected_sites'] ) ) {
					$selected_wp = ! empty( $_POST['selected_sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array();
				} else { // radio selection.
					$selected_wp = ! empty( $_POST['selected_sites'] ) ? array( sanitize_text_field( wp_unslash( $_POST['selected_sites'] ) ) ) : array();
				}
			}
			update_post_meta( $post_id, '_selected_sites', $selected_wp );
			$selected_groups = array();
			if ( isset( $_POST['selected_groups'] ) ) {
				if ( is_array( $_POST['selected_groups'] ) ) {
					$selected_groups = ! empty( $_POST['selected_groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_groups'] ) ) : array();
				} else { // radio selection.
					$selected_groups = ! empty( $_POST['selected_groups'] ) ? array( sanitize_text_field( wp_unslash( $_POST['selected_groups'] ) ) ) : array();
				}
			}
			update_post_meta( $post_id, '_selected_groups', $selected_groups );
			$selected_clients = array();
			if ( isset( $_POST['selected_clients'] ) ) {
				if ( is_array( $_POST['selected_clients'] ) ) {
					$selected_clients = ! empty( $_POST['selected_clients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_clients'] ) ) : array();
				} else { // radio selection.
					$selected_clients = ! empty( $_POST['selected_clients'] ) ? array( sanitize_text_field( wp_unslash( $_POST['selected_clients'] ) ) ) : array();
				}
			}
			update_post_meta( $post_id, '_selected_clients', $selected_clients );
			update_post_meta( $post_id, '_selected_by', sanitize_text_field( wp_unslash( $_POST['select_by'] ) ) );

			if ( ( 'group' === $_POST['select_by'] && 0 < count( $selected_groups ) ) || ( 'site' === $_POST['select_by'] && 0 < count( $selected_wp ) ) || ( 'client' === $_POST['select_by'] && 0 < count( $selected_clients ) ) ) {
				return sanitize_text_field( wp_unslash( $_POST['select_by'] ) );
			}
		}
		// phpcs:enable

		return $post_id;
	}

	/**
	 * Method add_categories()
	 *
	 * Add categories.
	 *
	 * @param int $post_id Post ID.
	 */
	public function add_categories( $post_id = false ) {
		if ( empty( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		MainWP_Post::render_categories( $post );
	}

	/**
	 * Method add_categories_handle()
	 *
	 * Handle adding categories.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 */
	public function add_categories_handle( $post_id, $post_type ) {
		/**
		 * Verify this came from the our screen and with proper authorization.
		 */

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['post_category_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['post_category_nonce'] ), 'post_category_' . $post_id ) ) {
			return;
		}

		/**
		 * Verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything.
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		/**
		 * Check permissions.
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		/**
		 * OK, we're authenticated: we need to find and save the data.
		 */
		$_post = get_post( $post_id );
		if ( $_post->post_type === $post_type ) {
			if ( isset( $_POST['post_category'] ) && is_array( $_POST['post_category'] ) ) {
				update_post_meta( $post_id, '_categories', base64_encode( implode( ',', wp_unslash( $_POST['post_category'] ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				do_action( 'mainwp_bulkpost_categories_handle', $post_id, wp_unslash( $_POST['post_category'] ) );
			}

			$post_existing = ! empty( $_POST['post_only_existing'] ) ? 1 : 0;
			update_post_meta( $post_id, '_post_to_only_existing_categories', $post_existing );

			return;
		}
		// phpcs:enable
	}

	/**
	 * Method add_tags()
	 *
	 * Add tags to Post array.
	 *
	 * @param object $post Post object.
	 */
	public function add_tags( $post ) {
		$this->add_extra( 'Tags', '_tags', 'add_tags', $post );
	}

	/**
	 * Method add_tags_handle()
	 *
	 * Add Tags to post array handler.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 */
	public function add_tags_handle( $post_id, $post_type ) {
		$this->add_extra_handle( 'Tags', '_tags', 'add_tags', $post_id, $post_type );
		if ( isset( $_POST['add_tags'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			do_action( 'mainwp_bulkpost_tags_handle', $post_id, $post_type, wp_strip_all_tags( wp_unslash( $_POST['add_tags'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * Method add_slug()
	 *
	 * Add Slug to Post object.
	 *
	 * @param object $post Post object.
	 */
	public function add_slug( $post ) {
		$this->add_extra( 'Slug', '_slug', 'add_slug', $post );
	}

	/**
	 * Method add_extra()
	 *
	 * Add nounce to post object.
	 *
	 * @param string $title Post title.
	 * @param string $saveto Save to.
	 * @param string $prefix Custom prefix.
	 * @param object $post Post object.
	 */
	private function add_extra( $title, $saveto, $prefix, $post ) {
		$extra = base64_decode( get_post_meta( $post->ID, $saveto, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_decode used for http encoding compatible.
		?>
		<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>_nonce" value="<?php echo esc_attr( wp_create_nonce( $prefix . '_' . $post->ID ) ); ?>"/>
		<input type="text" name="<?php echo esc_attr( $prefix ); ?>" value="<?php echo esc_attr( $extra ); ?>"/>
		<?php
	}


	/**
	 * Method add_slug_handle()
	 *
	 * Add post slug.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 */
	public function add_slug_handle( $post_id, $post_type ) {
		$this->add_extra_handle( 'Slug', '_slug', 'add_slug', $post_id, $post_type );
	}

	/**
	 * Method add_extra_handle()
	 *
	 * Update Post meta & add Security Nonce Prefix.
	 *
	 * @param string $title Post title.
	 * @param string $saveto Where to save.
	 * @param string $prefix Custom prefix.
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 *
	 * @return int  $post_id Post ID.
	 */
	private function add_extra_handle( $title, $saveto, $prefix, $post_id, $post_type ) {
		/**
		 * Verify this came from the our screen and with proper authorization.
		 */
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST[ $prefix . '_nonce' ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ $prefix . '_nonce' ] ), $prefix . '_' . $post_id ) ) {
			return $post_id;
		}

		/**
		 * Verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything.
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		/**
		 * Check permissions.
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		/**
		 * OK, we're authenticated: we need to find and save the data.
		 */
		$_post = get_post( $post_id );
		if ( $_post->post_type === $post_type && isset( $_POST[ $prefix ] ) ) {
			$value = isset( $_POST[ $prefix ] ) ? base64_encode( wp_unslash( $_POST[ $prefix ] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			update_post_meta( $post_id, $saveto, $value );
			return $value;
		}
		// phpcs:enable

		return $post_id;
	}
}
?>
