<?php
/**
 * Post Page Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Post_Page_Handler
 *
 * @uses MainWP_Bulk_Add
 */
class MainWP_Post_Page_Handler {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method add_meta()
	 *
	 * Add post meta data defined in $_POST superglobal for post with given ID.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_ID Post or Page ID.
	 * @return mixed False or add_post_meta()
	 */
	public static function add_meta( $post_ID ) {
		$post_ID = (int) $post_ID;

		$metakeyselect = isset( $_POST['metakeyselect'] ) ? sanitize_text_field( wp_unslash( $_POST['metakeyselect'] ) ) : '';
		$metakeyinput  = isset( $_POST['metakeyinput'] ) ? sanitize_text_field( wp_unslash( $_POST['metakeyinput'] ) ) : '';
		$metavalue     = isset( $_POST['metavalue'] ) ? sanitize_text_field( wp_unslash( $_POST['metavalue'] ) ) : '';
		if ( is_string( $metavalue ) ) {
			$metavalue = trim( $metavalue );
		}

		if ( ( ( '#NONE#' !== $metakeyselect ) && ! empty( $metakeyselect ) ) || ! empty( $metakeyinput ) ) {
			if ( '#NONE#' !== $metakeyselect ) {
				$metakey = $metakeyselect;
			}

			if ( $metakeyinput ) {
				$metakey = $metakeyinput;
			}

			if ( is_protected_meta( $metakey, 'post' ) || ! current_user_can( 'add_post_meta', $post_ID, $metakey ) ) {
				return false;
			}

			$metakey = wp_slash( $metakey );

			return add_post_meta( $post_ID, $metakey, $metavalue );
		}

		return false;
	}

	/**
	 * Method ajax_add_meta()
	 *
	 * Ajax process to add post meta data.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post_Handler::secure_request()
	 * @uses \MainWP\Dashboard\MainWP_Post::list_meta_row()
	 */
	public static function ajax_add_meta() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		MainWP_Post_Handler::instance()->secure_request( 'mainwp_post_addmeta' );

		$c   = 0;
		$pid = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( isset( $_POST['metakeyselect'] ) || isset( $_POST['metakeyinput'] ) ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				wp_die( -1 );
			}
			if ( isset( $_POST['metakeyselect'] ) && '#NONE#' === $_POST['metakeyselect'] && empty( $_POST['metakeyinput'] ) ) {
				wp_die( 1 );
			}
			$mid = self::add_meta( $pid );
			if ( ! $mid ) {
				wp_send_json( array( 'error' => __( 'Please provide a custom field value.', 'mainwp' ) ) );
			}

			$meta = get_metadata_by_mid( 'post', $mid );
			$pid  = (int) $meta->post_id;
			$meta = get_object_vars( $meta );

			$data = MainWP_Post::list_meta_row( $meta, $c );

		} elseif ( isset( $_POST['delete_meta'] ) && 'yes' === $_POST['delete_meta'] ) {
			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

			check_ajax_referer( "delete-meta_$id", 'meta_nonce' );
			$meta = get_metadata_by_mid( 'post', $id );
			if ( ! $meta ) {
				wp_send_json( array( 'ok' => 1 ) );
			}

			if ( is_protected_meta( $meta->meta_key, 'post' ) || ! current_user_can( 'delete_post_meta', $meta->post_id, $meta->meta_key ) ) {
				wp_die( -1 );
			}

			if ( delete_meta( $meta->meta_id ) ) {
				wp_send_json( array( 'ok' => 1 ) );
			}

			wp_die( 0 );

		} else {
			$mid   = isset( $_POST['meta'] ) ? (int) key( $_POST['meta'] ) : 0;
			$key   = isset( $_POST['meta'][ $mid ]['key'] ) ? sanitize_text_field( wp_unslash( $_POST['meta'][ $mid ]['key'] ) ) : '';
			$value = isset( $_POST['meta'][ $mid ]['value'] ) ? sanitize_text_field( wp_unslash( $_POST['meta'][ $mid ]['value'] ) ) : '';
			if ( '' == trim( $key ) ) {
				wp_send_json( array( 'error' => __( 'Please provide a custom field name.', 'mainwp' ) ) );
			}
			$meta = get_metadata_by_mid( 'post', $mid );
			if ( ! $meta ) {
				wp_die( 0 );
			}
			if ( is_protected_meta( $meta->meta_key, 'post' ) || is_protected_meta( $key, 'post' ) ||
				! current_user_can( 'edit_post_meta', $meta->post_id, $meta->meta_key ) ||
				! current_user_can( 'edit_post_meta', $meta->post_id, $key ) ) {
				wp_die( -1 );
			}
			if ( $meta->meta_value != $value || $meta->meta_key != $key ) {
				$u = update_metadata_by_mid( 'post', $mid, $value, $key );
				if ( ! $u ) {
					wp_die( 0 );
				}
			}

			$data = MainWP_Post::list_meta_row(
				array(
					'meta_key'   => $key,
					'meta_value' => $value,
					'meta_id'    => $mid,
				),
				$c
			);
		}

		wp_send_json( array( 'result' => $data ) );
	}


	/**
	 * Method get_categories()
	 *
	 * Get categories.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_ids()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_group_ids()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public static function get_categories() {
		$websites = array();
		if ( isset( $_REQUEST['sites'] ) && ( '' !== $_REQUEST['sites'] ) ) {
			$siteIds          = explode( ',', wp_unslash( urldecode( $_REQUEST['sites'] ) ) ); // do not sanitize encoded values.
			$siteIdsRequested = array();
			foreach ( $siteIds as $siteId ) {
				$siteId = $siteId;
				if ( ! MainWP_Utility::ctype_digit( $siteId ) ) {
					continue;
				}
				$siteIdsRequested[] = $siteId;
			}

			$websites = MainWP_DB::instance()->get_websites_by_ids( $siteIdsRequested );
		} elseif ( isset( $_REQUEST['groups'] ) && ( '' !== $_REQUEST['groups'] ) ) {
			$groupIds          = explode( ',', sanitize_text_field( wp_unslash( urldecode( $_REQUEST['groups'] ) ) ) );  // sanitize ok.
			$groupIdsRequested = array();
			foreach ( $groupIds as $groupId ) {
				$groupId = $groupId;

				if ( ! MainWP_Utility::ctype_digit( $groupId ) ) {
					continue;
				}
				$groupIdsRequested[] = $groupId;
			}

			$websites = MainWP_DB::instance()->get_websites_by_group_ids( $groupIdsRequested );
		} elseif ( isset( $_REQUEST['clients'] ) && ( '' !== $_REQUEST['clients'] ) ) {
			$clientIds          = explode( ',', sanitize_text_field( wp_unslash( urldecode( $_REQUEST['clients'] ) ) ) );  // sanitize ok.
			$clientIdsRequested = array();
			foreach ( $clientIds as $clientId ) {

				if ( ! MainWP_Utility::ctype_digit( $clientId ) ) {
					continue;
				}
				$clientIdsRequested[] = $clientId;
			}

			$data_fields = array(
				'id',
				'url',
				'name',
				'categories',
				'sync_errors',
			);
			$websites    = MainWP_DB_Client::instance()->get_websites_by_client_ids(
				$clientIdsRequested,
				array(
					'select_data' => $data_fields,
				)
			);
		}

		$selectedCategories  = array();
		$selectedCategories2 = array();

		if ( isset( $_REQUEST['selected_categories'] ) && ( '' !== $_REQUEST['selected_categories'] ) ) {
			$selectedCategories = explode( ',', sanitize_text_field( wp_unslash( urldecode( $_REQUEST['selected_categories'] ) ) ) );
		}

		if ( ! is_array( $selectedCategories ) ) {
			$selectedCategories = array();
		}

		$allCategories = array( 'Uncategorized' );
		if ( 0 < count( $websites ) ) {
			foreach ( $websites as $website ) {
				$cats = json_decode( $website->categories, true );
				if ( is_array( $cats ) && ( 0 < count( $cats ) ) ) {
					$allCategories = array_unique( array_merge( $allCategories, $cats ) );
				}
			}
		}
		$allCategories = array_unique( array_merge( $allCategories, $selectedCategories ) );

		if ( 0 < count( $allCategories ) ) {
			natcasesort( $allCategories );
			foreach ( $allCategories as $category ) {
				echo '<option value="' . esc_attr( $category ) . '" class="sitecategory">' . esc_html( $category ) . '</option>';
			}
		}
		die();
	}

	/**
	 * Method posting_bulk()
	 *
	 * Create bulk posts on sites.
	 */
	public static function posting_bulk() {
		$p_id               = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : false;
		$posting_bulk_sites = apply_filters( 'mainwp_posts_posting_bulk_sites', false );
		?>
		<input type="hidden" name="bulk_posting_id" id="bulk_posting_id" value="<?php echo intval( $p_id ); ?>"/>						
		<?php
		if ( ! $posting_bulk_sites ) {
			self::posting( $p_id );
		} else {
			self::posting_prepare( $p_id );
		}
	}

	/**
	 * Method posting()
	 *
	 * Create bulk posts on sites.
	 *
	 * @param int $post_id Post or Page ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::maybe_unserialyze()
	 * @uses \MainWP\Dashboard\MainWP_Twitter::update_twitter_info()
	 * @uses \MainWP\Dashboard\MainWP_Twitter::enabled_twitter_messages()
	 * @uses \MainWP\Dashboard\MainWP_Twitter::get_twit_to_send()
	 * @uses \MainWP\Dashboard\MainWP_Twitter::get_twitter_notice()
	 * @uses \MainWP\Dashboard\MainWP_Twitter
	 * @uses \MainWP\Dashboard\MainWP_Bulk_Add::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public static function posting( $post_id ) { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$p_id = $post_id;

		$edit_id = get_post_meta( $post_id, '_mainwp_edit_post_id', true );

		?>
		<div class="ui modal" id="mainwp-posting-post-modal">
			<div class="header"><?php $edit_id ? esc_html_e( 'Edit Post', 'mainwp' ) : esc_html_e( 'New Post', 'mainwp' ); ?></div>
			<div class="scrolling content">
				<?php
				/**
				 * Before Post post action
				 *
				 * Fires right before posting the 'bulkpost' to child sites.
				 *
				 * @param int $p_id Page ID.
				 *
				 * @since Unknown
				 */
				do_action( 'mainwp_bulkpost_before_post', $p_id );

				$skip_post = false;
				if ( $p_id ) {
					if ( 'yes' === get_post_meta( $p_id, '_mainwp_skip_posting', true ) ) {
						$skip_post = true;
						wp_delete_post( $p_id, true );
					}
				}

				if ( ! $skip_post ) {
					if ( $p_id ) {
						self::posting_posts( $p_id, 'posting' );
					} else {
						?>
					<div class="error">
						<p>
							<strong><?php esc_html_e( 'ERROR', 'mainwp' ); ?></strong>: <?php esc_html_e( 'An undefined error occured!', 'mainwp' ); ?>
						</p>
					</div>
						<?php
					}
				}
				?>
		</div>
		<div class="actions">
			<a href="admin.php?page=PostBulkAdd" class="ui green button"><?php esc_html_e( 'New Post', 'mainwp' ); ?></a>
			<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
		</div>
	</div>
	<div class="ui active inverted dimmer" id="mainwp-posting-running">
	<div class="ui indeterminate large text loader"><?php esc_html_e( 'Running ...', 'mainwp' ); ?></div>
	</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( "#mainwp-posting-running" ).hide();
				jQuery( "#mainwp-posting-post-modal" ).modal( {
					closable: true,
					onHide: function() {
						location.href = 'admin.php?page=PostBulkManage';
					}
				} ).modal( 'show' );
			} );
		</script>
		<?php
	}

	/**
	 * Method posting_prepare()
	 *
	 * Posting posts.
	 *
	 * @param int $post_id Post or Page ID.
	 */
	public static function posting_prepare( $post_id ) {
		$edit_id = get_post_meta( $post_id, '_mainwp_edit_post_id', true );
		?>
		<div class="ui modal" id="mainwp-posting-post-modal">
			<div class="header"><?php $edit_id ? esc_html_e( 'Edit Post', 'mainwp' ) : esc_html_e( 'New Post', 'mainwp' ); ?></div>
			<div class="scrolling content">
				<?php
				if ( $post_id ) {
					self::posting_posts( $post_id, 'preparing' );
				} else {
					?>
					<div class="error">
						<p>
							<strong><?php esc_html_e( 'ERROR', 'mainwp' ); ?></strong>: <?php esc_html_e( 'An undefined error occured!', 'mainwp' ); ?>
						</p>
					</div>
					<?php
				}
				?>
			</div>
		<div class="actions">
			<a href="admin.php?page=PostBulkAdd" class="ui green button"><?php esc_html_e( 'New Post', 'mainwp' ); ?></a>
			<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
		</div>
	</div>
	<div class="ui active inverted dimmer" id="mainwp-posting-running">
	<div class="ui indeterminate large text loader"><?php esc_html_e( 'Running ...', 'mainwp' ); ?></div>
	</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( "#mainwp-posting-running" ).hide();
				jQuery( "#mainwp-posting-post-modal" ).modal( {
					closable: true,
					onHide: function() {
						location.href = 'admin.php?page=PostBulkManage';
					}
				} ).modal( 'show' );
				mainwp_post_posting_start_next( true );
			} );			
		</script>
		<?php
	}


	/**
	 * Method ajax_posting_posts()
	 *
	 * Ajax Posting posts.
	 */
	public static function ajax_posting_posts() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_post_postingbulk' );
		$post_id = isset( $_POST['post_id'] ) && $_POST['post_id'] ? intval( $_POST['post_id'] ) : false;
		if ( $post_id ) {
			self::posting_posts( $post_id, 'ajax_posting' );
		}
		die();
	}

	/**
	 * Method ajax_get_sites_of_groups()
	 *
	 * Ajax Get sites of groups.
	 */
	public static function ajax_get_sites_of_groups() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_get_sites_of_groups' );
		$groups   = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : '';
		$websites = MainWP_DB::instance()->get_websites_by_group_ids( $groups );
		$site_Ids = array();
		if ( $websites ) {
			foreach ( $websites as $website ) {
				$site_Ids[] = $website->id;
			}
		}
		die( wp_json_encode( $site_Ids ) );
	}

	/**
	 * Method posting_posts()
	 *
	 * Posting posts.
	 *
	 * @param int    $post_id Post or Page ID.
	 * @param string $what What posting process.
	 */
	public static function posting_posts( $post_id, $what ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		if ( empty( $post_id ) ) {
			return false;
		}

		$succes_message = '';
		$edit_id        = get_post_meta( $post_id, '_mainwp_edit_post_id', true );
		if ( $edit_id ) {
			$succes_message = __( 'Post has been updated successfully', 'mainwp' );
		} else {
			$succes_message = __( 'New post created', 'mainwp' );
		}

		$id    = $post_id;
		$_post = get_post( $id );

		if ( $_post ) {
			$selected_by      = 'site';
			$selected_groups  = array();
			$selected_sites   = array();
			$selected_clients = array();

			if ( 'posting' == $what || 'preparing' == $what ) {
				$selected_by      = get_post_meta( $id, '_selected_by', true );
				$val              = get_post_meta( $id, '_selected_sites', true );
				$selected_sites   = MainWP_System_Utility::maybe_unserialyze( $val );
				$val              = get_post_meta( $id, '_selected_groups', true );
				$selected_groups  = MainWP_System_Utility::maybe_unserialyze( $val );
				$selected_clients = get_post_meta( $id, '_selected_clients', true );
				$selected_by      = apply_filters( 'mainwp_posting_post_selected_by', $selected_by, $id );
			} elseif ( 'ajax_posting' == $what ) {
				$site_id = $_POST['site_id'] ? $_POST['site_id'] : 0;
				if ( $site_id ) {
					$selected_sites = array( $site_id );
				}
			}

			$selected_sites   = apply_filters( 'mainwp_posting_post_selected_sites', $selected_sites, $id );
			$selected_groups  = apply_filters( 'mainwp_posting_selected_groups', $selected_groups, $id );
			$selected_clients = apply_filters( 'mainwp_posting_selected_clients', $selected_clients, $id );

			if ( 'preparing' != $what ) {
				$post_category = base64_decode( get_post_meta( $id, '_categories', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

				$post_tags   = base64_decode( get_post_meta( $id, '_tags', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				$post_slug   = base64_decode( get_post_meta( $id, '_slug', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				$post_custom = get_post_custom( $id );

				$galleries           = get_post_gallery( $id, false );
				$post_gallery_images = array();

				if ( is_array( $galleries ) && isset( $galleries['ids'] ) ) {
					$attached_images = explode( ',', $galleries['ids'] );
					foreach ( $attached_images as $attachment_id ) {
						$attachment = get_post( $attachment_id );
						if ( $attachment ) {
							$post_gallery_images[] = array(
								'id'          => $attachment_id,
								'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
								'caption'     => htmlspecialchars( $attachment->post_excerpt ),
								'description' => $attachment->post_content,
								'src'         => $attachment->guid,
								'title'       => htmlspecialchars( $attachment->post_title ),
							);
						}
					}
				}

				include_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php';
				$featured_image_id   = get_post_thumbnail_id( $id );
				$post_featured_image = null;
				$featured_image_data = null;
				$mainwp_upload_dir   = wp_upload_dir();

				// to fix.
				$post_status = $_post->post_status;
				if ( 'publish' == $post_status ) {
					$post_status = get_post_meta( $id, '_edit_post_status', true );
				}

				/**
				 * Post status
				 *
				 * Sets post status when posting 'bulkpost' to child sites.
				 *
				 * @param int $id Post ID.
				 *
				 * @since Unknown
				 */
				$post_status = apply_filters( 'mainwp_posting_bulkpost_post_status', $post_status, $id );
				$new_post    = array(
					'post_title'     => htmlspecialchars( $_post->post_title ),
					'post_content'   => $_post->post_content,
					'post_status'    => $post_status,
					'post_date'      => $_post->post_date,
					'post_date_gmt'  => $_post->post_date_gmt,
					'post_tags'      => $post_tags,
					'post_name'      => $post_slug,
					'post_excerpt'   => htmlspecialchars( $_post->post_excerpt ),
					'post_password'  => $_post->post_password,
					'comment_status' => $_post->comment_status,
					'ping_status'    => $_post->ping_status,
					'mainwp_post_id' => $_post->ID,
				);

				if ( null != $featured_image_id ) {
					$img                 = wp_get_attachment_image_src( $featured_image_id, 'full' );
					$post_featured_image = $img[0];
					$attachment          = get_post( $featured_image_id );
					$featured_image_data = array(
						'alt'         => get_post_meta( $featured_image_id, '_wp_attachment_image_alt', true ),
						'caption'     => htmlspecialchars( $attachment->post_excerpt ),
						'description' => $attachment->post_content,
						'title'       => htmlspecialchars( $attachment->post_title ),
					);
				}
			}

			$data_fields = array(
				'id',
				'url',
				'name',
				'adminname',
				'nossl',
				'privkey',
				'nosslkey',
				'http_user',
				'http_pass',
				'ssl_version',
				'sync_errors',
			);

			$dbwebsites = array();

			if ( 'site' === $selected_by ) {
				foreach ( $selected_sites as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$website = MainWP_DB::instance()->get_website_by_id( $k );
						if ( '' == $website->sync_errors && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
						}
					}
				}
			} elseif ( 'client' === $selected_by ) {
				$websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
					$selected_clients,
					array(
						'select_data' => $data_fields,
					)
				);
				if ( $websites ) {
					foreach ( $websites as $website ) {
						if ( '' != $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
							continue;
						}
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
					}
				}
			} elseif ( 'group' === $selected_by ) {
				foreach ( $selected_groups as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $k ) );
						while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' != $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}

			if ( 'preparing' == $what ) {
				?>
				<div class="ui relaxed list">
				<?php
				foreach ( $dbwebsites as $website ) {
					?>
					<div class="item site-bulk-posting" site-id="<?php echo intval( $website->id ); ?>" status="queue"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
					<div class="right floated content progress"><i class="clock outline icon"></i></div>
					</div>
			<?php } ?>
			</div>
				<?php
			} else {

				$output         = new \stdClass();
				$output->ok     = array();
				$output->errors = array();
				$startTime      = time();

				if ( 0 < count( $dbwebsites ) ) {
					$post_data = array(
						'new_post'            => base64_encode( serialize( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
						'post_custom'         => base64_encode( serialize( $post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
						'post_category'       => base64_encode( $post_category ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
						'post_featured_image' => base64_encode( $post_featured_image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
						'post_gallery_images' => base64_encode( serialize( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
						'mainwp_upload_dir'   => base64_encode( serialize( $mainwp_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
						'featured_image_data' => base64_encode( serialize( $featured_image_data ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
					);
					MainWP_Connect::fetch_urls_authed(
						$dbwebsites,
						'newpost',
						$post_data,
						array(
							MainWP_Bulk_Add::get_class_name(),
							'posting_bulk_handler',
						),
						$output
					);
				}

				foreach ( $dbwebsites as $website ) {
					if ( isset( $output->ok[ $website->id ] ) && ( 1 == $output->ok[ $website->id ] ) && ( isset( $output->added_id[ $website->id ] ) ) ) {
						$links = isset( $output->link[ $website->id ] ) ? $output->link[ $website->id ] : null;
						do_action_deprecated( 'mainwp-post-posting-post', array( $website, $output->added_id[ $website->id ], $links ), '4.0.7.2', 'mainwp_post_posting_post' ); // @deprecated Use 'mainwp_post_posting_page' instead.
						do_action_deprecated( 'mainwp-bulkposting-done', array( $_post, $website, $output ), '4.0.7.2', 'mainwp_bulkposting_done' ); // @deprecated Use 'mainwp_bulkposting_done' instead.

						/**
						 * Posting post
						 *
						 * Fires while posting post.
						 *
						 * @param object $website                          Object containing child site data.
						 * @param int    $output->added_id[ $website->id ] Child site ID.
						 * @param array  $links                            Links.
						 *
						 * @since Unknown
						 */
						do_action( 'mainwp_post_posting_post', $website, $output->added_id[ $website->id ], $links );

						/**
						 * Posting post completed
						 *
						 * Fires after the post posting process is completed.
						 *
						 * @param array  $_post   Array containing the post data.
						 * @param object $website Object containing child site data.
						 * @param array  $output  Output data.
						 *
						 * @since Unknown
						 */
						do_action( 'mainwp_bulkposting_done', $_post, $website, $output );
					}
				}

				/**
				 * After posting a new post
				*
				* Sets data after the posting process to show the process feedback.
				*
				* @param array $_post      Array containing the post data.
				* @param array $dbwebsites Array containing processed sites.
				* @param array $output     Output data.
				*
				* @since Unknown
				*/
				$newExtensions = apply_filters_deprecated( 'mainwp-after-posting-bulkpost-result', array( false, $_post, $dbwebsites, $output ), '4.0.7.2', 'mainwp_after_posting_bulkpost_result' );
				$after_posting = apply_filters( 'mainwp_after_posting_bulkpost_result', $newExtensions, $_post, $dbwebsites, $output );

				$posting_succeed = false;

				if ( false === $after_posting ) {
					if ( 'posting' == $what ) {
						?>
					<div class="ui relaxed list">
						<?php
						foreach ( $dbwebsites as $website ) {
							?>
							<div class="item"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
							: 
							<?php
							if ( isset( $output->ok[ $website->id ] ) && 1 == $output->ok[ $website->id ] ) {
								echo esc_html( $succes_message ) . ' <a href="' . esc_html( $output->link[ $website->id ] ) . '" class="mainwp-may-hide-referrer" target="_blank">View Post</a>';
								$posting_succeed = true;
							} else {
								echo $output->errors[ $website->id ];
							}
							?>
							</div>
					<?php } ?>
					</div>
				<?php } ?>				
					<?php
				} else {
					$posting_succeed = true;
				}

				$ajax_result = '';
				if ( 'ajax_posting' == $what ) {
					if ( isset( $output->ok[ $website->id ] ) && 1 == $output->ok[ $website->id ] ) {
						$ajax_result     = esc_html( $succes_message ) . ' <a href="' . esc_html( $output->link[ $website->id ] ) . '" class="mainwp-may-hide-referrer" target="_blank">View Post</a>';
						$posting_succeed = true;
					} else {
						$ajax_result = $output->errors[ $website->id ];
					}
				}

				$delete_bulk_post = apply_filters( 'mainwp_after_posting_delete_bulk_post', true, $posting_succeed );
				$do_not_del       = get_post_meta( $id, '_bulkpost_do_not_del', true );

				$last_ajax_posting = false;
				if ( 'ajax_posting' == $what ) {
					$total           = isset( $_POST['total'] ) ? intval( $_POST['total'] ) : 0;
					$count           = isset( $_POST['count'] ) ? intval( $_POST['count'] ) : 0;
					$delete_bulkpost = isset( $_POST['delete_bulkpost'] ) && ! empty( $_POST['delete_bulkpost'] ) ? true : false;

					if ( $delete_bulkpost ) {
						$last_ajax_posting = true;
					}
				}

				$deleted_bulk_post = false;
				if ( 'yes' !== $do_not_del && $delete_bulk_post && ( 'posting' == $what || $last_ajax_posting ) ) {
					wp_delete_post( $id, true );
					$deleted_bulk_post = true;
				}

				$edit_link = '';
				if ( ! $deleted_bulk_post ) {
					if ( 'posting' == $what ) {
						?>
						<div class="item">
							<a href="<?php echo admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $id ); ?>"><?php esc_html_e( 'Edit Post', 'mainwp' ); ?></a>
						</div>
						<?php
					} elseif ( $last_ajax_posting ) {
						$edit_link = '<div class="item"><a href="' . admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $id ) . '">' . esc_html__( 'Edit Post', 'mainwp' ) . '</a></div>';
					}
				}

				if ( 'ajax_posting' == $what ) {
					die(
						wp_json_encode(
							array(
								'result'    => $ajax_result,
								'edit_link' => $edit_link,
							)
						)
					);
				}
			}

			if ( MainWP_Twitter::enabled_twitter_messages() ) {
				if ( 'posting' == $what ) {
					$countRealItems = 0;
					foreach ( $dbwebsites as $website ) {
						if ( isset( $output->ok[ $website->id ] ) && 1 == $output->ok[ $website->id ] ) {
							$countRealItems++;
						}
					}
					if ( ! empty( $countRealItems ) ) {
						$seconds = ( time() - $startTime );
						MainWP_Twitter::update_twitter_info( 'new_post', $countRealItems, $seconds, $countRealItems, $startTime, 1 );
					}

					$twitters = MainWP_Twitter::get_twitter_notice( 'new_post' );
					if ( is_array( $twitters ) ) {
						foreach ( $twitters as $timeid => $twit_mess ) {
							if ( ! empty( $twit_mess ) ) {
								$sendText = MainWP_Twitter::get_twit_to_send( 'new_post', $timeid );
								?>
							<div class="mainwp-tips ui info message twitter" style="margin:0">
								<i class="ui close icon mainwp-dismiss-twit"></i><span class="mainwp-tip" twit-what="new_post" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::gen_twitter_button( $sendText ); ?>
							</div>
								<?php
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Method get_post()
	 *
	 * Get post from child site to edit.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function get_post() {
		$postId        = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : false;
		$postType      = isset( $_POST['postType'] ) ? sanitize_text_field( wp_unslash( $_POST['postType'] ) ) : '';
		$websiteId     = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false;
		$replaceadvImg = isset( $_POST['replace_advance_img'] ) && ! empty( $_POST['replace_advance_img'] ) ? true : true;

		if ( empty( $postId ) || empty( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => 'Post ID or site ID not found. Please, reload the page and try again.' ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => 'You can not edit this website!' ) ) );
		}

		try {
			$information = MainWP_Connect::fetch_url_authed(
				$website,
				'post_action',
				array(
					'action'    => 'get_edit',
					'id'        => $postId,
					'post_type' => $postType,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		if ( is_array( $information ) && isset( $information['error'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html( $information['error'] ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => 'Unexpected error.' ) ) );
		} else {
			$ret = self::new_post( $information['my_post'], $replaceadvImg, $website );
			if ( is_array( $ret ) && isset( $ret['id'] ) ) {
				// to support edit post.
				update_post_meta( $ret['id'], '_selected_sites', array( $websiteId ) );
				update_post_meta( $ret['id'], '_mainwp_edit_post_site_id', $websiteId );
			}
			$ret = apply_filters( 'mainwp_manageposts_get_post_result', $ret, $information['my_post'], $websiteId );
			wp_send_json( $ret );
		}
	}

	/**
	 * Method new_post()
	 *
	 * Create new post.
	 *
	 * @param array $post_data Array of post data.
	 * @param bool  $replaceadvImg replace advanced images of post or not.
	 * @param mixed $website The website object.
	 *
	 * @return array result
	 */
	public static function new_post( $post_data = array(), $replaceadvImg = false, $website = false ) {
		$new_post            = maybe_unserialize( base64_decode( $post_data['new_post'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		$post_custom         = maybe_unserialize( base64_decode( $post_data['post_custom'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		$post_category       = rawurldecode( isset( $post_data['post_category'] ) ? base64_decode( $post_data['post_category'] ) : null ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		$post_tags           = rawurldecode( isset( $new_post['post_tags'] ) ? $new_post['post_tags'] : null );
		$post_featured_image = base64_decode( $post_data['post_featured_image'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		$post_gallery_images = base64_decode( $post_data['post_gallery_images'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		$upload_dir          = maybe_unserialize( base64_decode( $post_data['child_upload_dir'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		$post_gallery_images = base64_decode( $post_data['post_gallery_images'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		return self::create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $post_gallery_images, $replaceadvImg, $website );
	}

	/**
	 * Method create_post()
	 *
	 * Create post.
	 *
	 * @param mixed $new_post Post type.
	 * @param mixed $post_custom Custom Post.
	 * @param mixed $post_category Post Category.
	 * @param mixed $post_featured_image Post Featured Image.
	 * @param mixed $upload_dir Child Site upload directory.
	 * @param mixed $post_tags Post tags.
	 * @param mixed $post_gallery_images Post Gallery Images.
	 * @param bool  $replaceadvImg replace advanced images of post or not.
	 * @param mixed $website The website object.
	 *
	 * @return array result
	 */
	public static function create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $post_gallery_images, $replaceadvImg = false, $website = false ) { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		if ( ! isset( $new_post['edit_id'] ) ) {
			return array( 'error' => 'Empty post id' );
		}

		$post_author             = $current_user->ID;
		$new_post['post_author'] = $post_author;
		$post_type               = isset( $new_post['post_type'] ) ? $new_post['post_type'] : '';
		$new_post['post_type']   = 'page' === $post_type ? 'bulkpage' : 'bulkpost';

		$foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg))\")/ix', $new_post['post_content'], $matches, PREG_SET_ORDER );
		if ( 0 < $foundMatches ) {
			foreach ( $matches as $match ) {
				$hrefLink = $match[2];
				$imgUrl   = $match[4];

				if ( ! isset( $upload_dir['baseurl'] ) || ( false === strripos( $imgUrl, $upload_dir['baseurl'] ) ) ) { // url of image is not in child site.
					continue;
				}

				if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
					$search         = $imgMatches[0];
					$replace        = '.' . $match[6];
					$originalImgUrl = str_replace( $search, $replace, $imgUrl );
				} else {
					$originalImgUrl = $imgUrl;
				}

				try {
					$downloadfile = self::upload_image( $originalImgUrl );
					$localUrl     = $downloadfile['url'];

					$linkToReplaceWith = dirname( $localUrl );
					if ( '' !== $hrefLink ) {
						$server     = get_option( 'mainwp_child_server' );
						$serverHost = wp_parse_url( $server, PHP_URL_HOST );
						if ( ! empty( $serverHost ) && false !== strpos( $hrefLink, $serverHost ) ) {
							$serverHref               = 'href="' . $serverHost;
							$replaceServerHref        = 'href="' . wp_parse_url( $localUrl, PHP_URL_SCHEME ) . '://' . wp_parse_url( $localUrl, PHP_URL_HOST );
							$new_post['post_content'] = str_replace( $serverHref, $replaceServerHref, $new_post['post_content'] );
						}
					}
					$lnkToReplace = dirname( $imgUrl );
					if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
						$new_post['post_content'] = str_replace( $imgUrl, $localUrl, $new_post['post_content'] ); // replace src image.
						$new_post['post_content'] = str_replace( $lnkToReplace, $linkToReplaceWith, $new_post['post_content'] );
					}
				} catch ( \Exception $e ) {
					// ok.
				}
			}
		}

		if ( has_shortcode( $new_post['post_content'], 'gallery' ) ) {
			if ( preg_match_all( '/\[gallery[^\]]+ids=\"(.*?)\"[^\]]*\]/ix', $new_post['post_content'], $matches, PREG_SET_ORDER ) ) {
				$replaceAttachedIds = array();
				if ( is_array( $post_gallery_images ) ) {
					foreach ( $post_gallery_images as $gallery ) {
						if ( isset( $gallery['src'] ) ) {
							try {
								$upload = self::upload_image( $gallery['src'], $gallery, true );
								if ( null !== $upload ) {
									$replaceAttachedIds[ $gallery['id'] ] = $upload['id'];
								}
							} catch ( \Exception $e ) {
								// ok.
							}
						}
					}
				}
				if ( 0 < count( $replaceAttachedIds ) ) {
					foreach ( $matches as $match ) {
						$idsToReplace     = $match[1];
						$idsToReplaceWith = '';
						$originalIds      = explode( ',', $idsToReplace );
						foreach ( $originalIds as $attached_id ) {
							if ( ! empty( $originalIds ) && isset( $replaceAttachedIds[ $attached_id ] ) ) {
								$idsToReplaceWith .= $replaceAttachedIds[ $attached_id ] . ',';
							}
						}
						$idsToReplaceWith = rtrim( $idsToReplaceWith, ',' );
						if ( ! empty( $idsToReplaceWith ) ) {
							$new_post['post_content'] = str_replace( '"' . $idsToReplace . '"', '"' . $idsToReplaceWith . '"', $new_post['post_content'] );
						}
					}
				}
			}
		}

		if ( $replaceadvImg && $website ) {
			$new_post['post_content'] = self::replace_advanced_image( $new_post['post_content'], $upload_dir, $website );
			$new_post['post_content'] = self::replace_advanced_image( $new_post['post_content'], $upload_dir, $website, true ); // to fix images url with slashes.
		}

		$is_sticky = false;
		if ( isset( $new_post['is_sticky'] ) ) {
			$is_sticky = ! empty( $new_post['is_sticky'] ) ? true : false;
			unset( $new_post['is_sticky'] );
		}
		$edit_id = $new_post['edit_id'];
		unset( $new_post['edit_id'] );

		$wp_error = null;
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$post_status             = $new_post['post_status'];
		$new_post['post_status'] = 'auto-draft';
		$new_post_id             = wp_insert_post( $new_post, $wp_error );

		if ( is_wp_error( $wp_error ) ) {
			return array( 'error' => $wp_error->get_error_message() );
		}

		if ( empty( $new_post_id ) ) {
			return array( 'error' => 'Undefined error' );
		}

		wp_update_post(
			array(
				'ID'          => $new_post_id,
				'post_status' => $post_status,
			)
		);

		foreach ( $post_custom as $meta_key => $meta_values ) {
			foreach ( $meta_values as $meta_value ) {
				if ( is_serialized( $meta_value ) ) {
					$meta_value = unserialize( $meta_value ); // phpcs:ignore -- compatible.
					update_post_meta( $new_post_id, $meta_key, $meta_value );
				} else {
					update_post_meta( $new_post_id, $meta_key, $meta_value );
				}
			}
		}

		update_post_meta( $new_post_id, '_mainwp_edit_post_id', $edit_id );
		update_post_meta( $new_post_id, '_slug', base64_encode( $new_post['post_name'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		if ( isset( $post_category ) && '' !== $post_category ) {
			update_post_meta( $new_post_id, '_categories', base64_encode( $post_category ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		}

		if ( isset( $post_tags ) && '' !== $post_tags ) {
			update_post_meta( $new_post_id, '_tags', base64_encode( $post_tags ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		}
		if ( $is_sticky ) {
			update_post_meta( $new_post_id, '_sticky', base64_encode( 'sticky' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		}

		if ( null !== $post_featured_image ) {
			try {
				$upload = self::upload_image( $post_featured_image );

				if ( null !== $upload ) {
					update_post_meta( $new_post_id, '_thumbnail_id', $upload['id'] );
				}
			} catch ( \Exception $e ) {
				// ok.
			}
		}

		$ret            = array();
		$ret['success'] = true;
		$ret['id']      = $new_post_id;
		return $ret;
	}

	/**
	 * Method replace_advanced_image()
	 *
	 * Handle upload advanced image.
	 *
	 * @param array $content post content data.
	 * @param array $upload_dir upload directory info.
	 * @param mixed $website The website.
	 * @param bool  $withslashes to use preg pattern with slashes.
	 *
	 * @return mixed array of result.
	 */
	public static function replace_advanced_image( $content, $upload_dir, $website, $withslashes = false ) {

		if ( empty( $upload_dir ) || ! isset( $upload_dir['baseurl'] ) ) {
			return $content;
		}

		$dashboard_url   = get_site_url();
		$site_url_source = $website->url;

		// to fix url with slashes.
		if ( $withslashes ) {
			$site_url_source = str_replace( '/', '\/', $site_url_source );
			$dashboard_url   = str_replace( '/', '\/', $dashboard_url );
		}

		$foundMatches = preg_match_all( '#(' . preg_quote( $site_url_source, null ) . ')[^\.]*(\.(png|gif|jpg|jpeg))#ix', $content, $matches, PREG_SET_ORDER ); // phpcs:ignore -- Current complexity.

		if ( 0 < $foundMatches ) {

			$matches_checked = array();
			$check_double    = array();
			foreach ( $matches as $match ) {
				// to avoid double images.
				if ( ! in_array( $match[0], $check_double ) ) {
					$check_double[]    = $match[0];
					$matches_checked[] = $match;
				}
			}
			foreach ( $matches_checked as $match ) {

				$imgUrl = $match[0];
				if ( false === strripos( wp_unslash( $imgUrl ), $upload_dir['baseurl'] ) ) {
					continue;
				}

				if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
					$search         = $imgMatches[0];
					$replace        = '.' . $match[3];
					$originalImgUrl = str_replace( $search, $replace, $imgUrl );
				} else {
					$originalImgUrl = $imgUrl;
				}

				try {
					$downloadfile      = self::upload_image( wp_unslash( $originalImgUrl ) );
					$localUrl          = $downloadfile['url'];
					$linkToReplaceWith = dirname( $localUrl );
					$lnkToReplace      = dirname( $imgUrl );
					if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
						$content = str_replace( $imgUrl, $localUrl, $content ); // replace src image.
						$content = str_replace( $lnkToReplace, $linkToReplaceWith, $content );
					}
				} catch ( \Exception $e ) {
					// ok.
				}
			}
			if ( false === strripos( $site_url_source, $dashboard_url ) ) {
				// replace other images src outside upload folder.
				$content = str_replace( $site_url_source, $dashboard_url, $content );
			}
		}
		return $content;
	}

	/**
	 * Method upload_image()
	 *
	 * Handle upload image.
	 *
	 * @throws \Exception Error upload file.
	 *
	 * @param string $img_url URL for the image.
	 * @param array  $img_data Array of image data.
	 *
	 * @return mixed array of result or null.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 */
	public static function upload_image( $img_url, $img_data = array() ) {
		if ( ! is_array( $img_data ) ) {
			$img_data = array();
		}
		include_once ABSPATH . 'wp-admin/includes/file.php';
		$upload_dir     = wp_upload_dir();
		$temporary_file = download_url( $img_url );

		if ( is_wp_error( $temporary_file ) ) {
			throw new \Exception( 'Error: ' . $temporary_file->get_error_message() );
		} else {
			$upload_dir     = wp_upload_dir();
			$local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename( $img_url );
			$local_img_url  = $upload_dir['url'] . '/' . basename( $img_url );
			$moved          = false;
			if ( MainWP_Utility::check_image_file_name( $local_img_path ) ) {
				$moved = rename( $temporary_file, $local_img_path );
			}
			if ( $moved ) {
				$wp_filetype = wp_check_filetype( basename( $img_url ), null );
				$attachment  = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title'     => isset( $img_data['title'] ) && ! empty( $img_data['title'] ) ? $img_data['title'] : preg_replace( '/\.[^.]+$/', '', basename( $img_url ) ),
					'post_content'   => isset( $img_data['description'] ) && ! empty( $img_data['description'] ) ? $img_data['description'] : '',
					'post_excerpt'   => isset( $img_data['caption'] ) && ! empty( $img_data['caption'] ) ? $img_data['caption'] : '',
					'post_status'    => 'inherit',
				);
				$attach_id   = wp_insert_attachment( $attachment, $local_img_path );
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attach_id, $local_img_path );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				if ( isset( $img_data['alt'] ) && ! empty( $img_data['alt'] ) ) {
					update_post_meta( $attach_id, '_wp_attachment_image_alt', $img_data['alt'] );
				}
				return array(
					'id'  => $attach_id,
					'url' => $local_img_url,
				);
			}
		}

		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		if ( $wp_filesystem->exists( $temporary_file ) ) {
			$wp_filesystem->delete( $temporary_file );
		}

		return null;
	}

	/**
	 * Method add_sticky_handle()
	 *
	 * Add post meta.
	 *
	 * @param mixed $post_id Post ID.
	 *
	 * @return int $post_id Post ID.
	 */
	public static function add_sticky_handle( $post_id ) {
		$_post = get_post( $post_id );
		if ( 'bulkpost' === $_post->post_type && isset( $_POST['sticky'] ) ) {
			update_post_meta( $post_id, '_sticky', base64_encode( sanitize_text_field( wp_unslash( $_POST['sticky'] ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			return base64_encode( sanitize_text_field( wp_unslash( $_POST['sticky'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		}
		return $post_id;
	}


	/**
	 * Method add_status_handle()
	 *
	 * Add edit post status handle.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int $post_id Post id with status handle added to it.
	 */
	public static function add_status_handle( $post_id ) {
		$_post = get_post( $post_id );
		if ( ( 'bulkpage' == $_post->post_type || 'bulkpost' == $_post->post_type ) && isset( $_POST['mainwp_edit_post_status'] ) ) {
			update_post_meta( $post_id, '_edit_post_status', sanitize_text_field( wp_unslash( $_POST['mainwp_edit_post_status'] ) ) );
		}
		return $post_id;
	}

}
