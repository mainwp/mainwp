<?php
/**
 * This file handles the addintion and updating of Post Meta Boxes.
 */
namespace MainWP\Dashboard;

/**
 * MainWP Meta Boxes
 */
class MainWP_Meta_Boxes {

	/**
	 * Select Sites.
	 *
	 * @param mixed $post Post Array
	 *
	 * @return html Select Sites Form.
	 */
	public function select_sites( $post ) {

		$val            = get_post_meta( $post->ID, '_selected_sites', true );
		$selected_sites = MainWP_Utility::maybe_unserialyze( $val );

		if ( '' == $selected_sites ) {
			$selected_sites = array();
		}

		if ( isset( $_REQUEST['select'] ) ) {
			$selected_sites = ( 'all' === $_REQUEST['select'] ? 'all' : array( $_REQUEST['select'] ) );
		}

		$val             = get_post_meta( $post->ID, '_selected_groups', true );
		$selected_groups = MainWP_Utility::maybe_unserialyze( $val );

		if ( '' == $selected_groups ) {
			$selected_groups = array();
		}
		?>
		<input type="hidden" name="select_sites_nonce" id="select_sites_nonce" value="<?php echo wp_create_nonce( 'select_sites_' . $post->ID ); ?>" />
		<div class="mainwp-select-sites">
			<?php MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups, false, $post->ID ); ?>
		</div>
		<?php
	}

	/**
	 * Update Post meta for Select Sites Meta boxes.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post_type Post Type
	 *
	 * @return int $post_id
	 */
	public function select_sites_handle( $post_id, $post_type ) {
		/**
		 * verify this came from the our screen and with proper authorization.
		 */
		if ( ! isset( $_POST['select_sites_nonce'] ) || ! wp_verify_nonce( $_POST['select_sites_nonce'], 'select_sites_' . $post_id ) ) {
			return $post_id;
		}

		/**
		 * verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything
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
		if ( $_post->post_type == $post_type && isset( $_POST['select_by'] ) ) {
			$selected_wp = array();
			if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
				foreach ( $_POST['selected_sites'] as $selected ) {
					$selected_wp[] = $selected;
				}
			}
			update_post_meta( $post_id, '_selected_sites', $selected_wp );

			$selected_group = array();
			if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
				foreach ( $_POST['selected_groups'] as $selected ) {
					$selected_group[] = $selected;
				}
			}
			update_post_meta( $post_id, '_selected_groups', $selected_group );
			update_post_meta( $post_id, '_selected_by', $_POST['select_by'] );

			if ( ( 'group' === $_POST['select_by'] && 0 < count( $selected_group ) ) || ( 'site' === $_POST['select_by'] && 0 < count( $selected_wp ) ) ) {
				return $_POST['select_by'];
			}
		}

		return $post_id;
	}

	/**
	 * Render Add Categories.
	 *
	 * @param mixed $post Post array.
	 *
	 * @return html
	 */
	public function add_categories( $post ) {
		$categories = apply_filters( 'mainwp_bulkpost_saved_categories', $post, array() );
		if ( empty( $categories ) || ! is_array( $categories ) || ( is_array( $categories ) && 1 == count( $categories ) && empty( $categories[0] ) ) ) {
			if ( $post ) {
				$categories = base64_decode( get_post_meta( $post->ID, '_categories', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_decode function is used for begin reasons.
				$categories = explode( ',', $categories );
			}
		}

		if ( ! is_array( $categories ) ) {
			$categories = array();
		}
		$uncat = __( 'Uncategorized', 'mainwp' );

		$post_only = false;
		if ( $post ) {
			$post_only = get_post_meta( $post->ID, '_post_to_only_existing_categories', true );
		}
		?>
		<input type="hidden" name="post_category_nonce" value="<?php echo esc_attr( wp_create_nonce( 'post_category_' . $post->ID ) ); ?>" />

		<div id="taxonomy-category" class="categorydiv">
			<div>
				<label>
					<input value="1" type="checkbox" <?php echo $post_only ? 'checked' : ''; ?> name="post_only_existing"><?php esc_html_e( 'Post only to existing categories', 'mainwp' ); ?>
				</label>
			</div>
			<ul id="category-tabs" class="category-tabs">
				<li class="tabs"><a href="#category-all"><?php esc_html_e( 'All categories', 'mainwp' ); ?></a></li>
			</ul>

			<div id="category-all" class="tabs-panel" style="display: block;">
				<ul id="categorychecklist" data-wp-lists="list:category" class="categorychecklist form-no-clear post_add_categories">
				<?php if ( ! in_array( $uncat, $categories ) ) { ?>
					<li class="popular-category sitecategory">
						<label class="selectit">
							<input value="Uncategorized" type="checkbox" name="post_category[]"><?php esc_html_e( 'Uncategorized', 'mainwp' ); ?>
						</label>
					</li>
				<?php } ?>
				<?php
				foreach ( $categories as $cat ) {
					if ( empty( $cat ) ) {
						continue;
					}
					$cat_name = rawurldecode( $cat );
					?>
					<li class="popular-category sitecategory">
						<label class="selectit">
							<input value="<?php echo esc_attr( $cat ); ?>" type="checkbox" checked name="post_category[]"><?php echo esc_attr( $cat_name ); ?>
						</label>
					</li>
					<?php } ?>
				</ul>
			</div>

			<div id="category-adder" class="wp-hidden-children">
				<h4><a id="category-add-toggle" href="#category-add" class="hide-if-no-js"><?php esc_html_e( '+ Add new category', 'mainwp' ); ?></a></h4>

				<p id="category-add" class="category-add wp-hidden-child">
					<label class="screen-reader-text" for="newcategory"><?php esc_html_e( 'Add new category', 'mainwp' ); ?></label>
					<input type="text" name="newcategory" id="newcategory" class="form-required" value="<?php esc_attr_e( 'New category name', 'mainwp' ); ?>" aria-required="true">
					<input type="button" id="mainwp-category-add-submit" class="button mainwp-category-add-submit" value="<?php esc_attr_e( 'Add new category', 'mainwp' ); ?>">
					<input type="hidden" id="_ajax_nonce-add-category" name="_ajax_nonce-add-category" value="<?php echo esc_attr( wp_create_nonce( 'add-category' . $post->ID ) ); ?>">
					<span id="category-ajax-response"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle adding Categories.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post_type Post Type.
	 *
	 * @return self
	 */
	public function add_categories_handle( $post_id, $post_type ) {
		/**
		 * verify this came from the our screen and with proper authorization.
		 */
		if ( ! isset( $_POST['post_category_nonce'] ) || ! wp_verify_nonce( $_POST['post_category_nonce'], 'post_category_' . $post_id ) ) {
			return;
		}

		/**
		 * verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		/**
		 * Check permissions
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		/**
		 * OK, we're authenticated: we need to find and save the data
		 */
		$_post = get_post( $post_id );
		if ( $_post->post_type == $post_type ) {
			if ( isset( $_POST['post_category'] ) && is_array( $_POST['post_category'] ) ) {
				update_post_meta( $post_id, '_categories', base64_encode( implode( ',', $_POST['post_category'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				do_action( 'mainwp_bulkpost_categories_handle', $post_id, $_POST['post_category'] );
			}

			$post_existing = ( isset( $_POST['post_only_existing'] ) && $_POST['post_only_existing'] ) ? 1 : 0;
			update_post_meta( $post_id, '_post_to_only_existing_categories', $post_existing );

			return;
		}
	}

	/**
	 * Add tags to Post array.
	 *
	 * @param mixed $post Post array.
	 */
	public function add_tags( $post ) {
		$this->add_extra( 'Tags', '_tags', 'add_tags', $post );
	}

	/**
	 * Add Tags to post array handler.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post_type post Type.
	 */
	public function add_tags_handle( $post_id, $post_type ) {
		$this->add_extra_handle( 'Tags', '_tags', 'add_tags', $post_id, $post_type );
		if ( isset( $_POST['add_tags'] ) ) {
			do_action( 'mainwp_bulkpost_tags_handle', $post_id, $post_type, $_POST['add_tags'] );
		}
	}

	/**
	 * Add Slug to Post array.
	 *
	 * @param $post Post Array.
	 */
	public function add_slug( $post ) {
		$this->add_extra( 'Slug', '_slug', 'add_slug', $post );
	}

	/**
	 * Add nounce to post array.
	 *
	 * @param mixed $title Post Title
	 * @param mixed $saveto
	 * @param mixed $prefix
	 * @param mixed $post
	 *
	 * @return void
	 */
	private function add_extra( $title, $saveto, $prefix, $post ) {
		$extra = base64_decode( get_post_meta( $post->ID, $saveto, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_decode function is used for begin reasons.
		?>
		<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>_nonce" value="<?php echo esc_attr( wp_create_nonce( $prefix . '_' . $post->ID ) ); ?>"/>
		<input type="text" name="<?php echo esc_attr( $prefix ); ?>" value="<?php echo esc_attr( $extra ); ?>"/>
		<?php
	}


	/**
	 * Add Post Slug.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post_type Post Type.
	 */
	public function add_slug_handle( $post_id, $post_type ) {
		$this->add_extra_handle( 'Slug', '_slug', 'add_slug', $post_id, $post_type );
	}

	/**
	 * Update Post meta & add Security Nonce Prefix.
	 *
	 * @param mixed $title Post title.
	 * @param mixed $saveto Where to save.
	 * @param mixed $prefix Nounce Prefix.
	 * @param mixed $post_id Post ID.
	 * @param mixed $post_type Post Type.
	 *
	 * @return int  $post_id Post ID.
	 */
	private function add_extra_handle( $title, $saveto, $prefix, $post_id, $post_type ) {
		/**
		 * verify this came from the our screen and with proper authorization.
		 */
		if ( ! isset( $_POST[ $prefix . '_nonce' ] ) || ! wp_verify_nonce( $_POST[ $prefix . '_nonce' ], $prefix . '_' . $post_id ) ) {
			return $post_id;
		}

		/**
		 * verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		/**
		 * Check permissions
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		/**
		 * OK, we're authenticated: we need to find and save the data
		 */
		$_post = get_post( $post_id );
		if ( $_post->post_type == $post_type && isset( $_POST[ $prefix ] ) ) {
			update_post_meta( $post_id, $saveto, base64_encode( $_POST[ $prefix ] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.

			return base64_encode( $_POST[ $prefix ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		}

		return $post_id;
	}

}
?>
