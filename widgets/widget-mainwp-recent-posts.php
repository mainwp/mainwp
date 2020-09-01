<?php
/**
 * MainWP Recent Posts Widget
 *
 * Displays the Child Sites most recent published draft, pending, trash & future posts.
 *
 * @package MainWP/Widget_Recent_Posts
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Recent_Posts
 *
 * Displays the Child Sites most recent published draft, pending, trash & future posts.
 */
class MainWP_Recent_Posts {

	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class Name
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * Fire off render_sites().
	 */
	public static function render() {
		self::render_sites();
	}

	/**
	 * Method render_sites()
	 *
	 * Build the recent posts list.
	 */
	public static function render_sites() {

		/**
		 * Sets number of recent posts & pages
		 *
		 * Limits the number of recent posts & pages to show in the widget. Min 0, Max 30, Default 5.
		 *
		 * @since 4.0
		 */
		$recent_number = apply_filters( 'mainwp_recent_posts_pages_number', 5 );

		$current_wpid = MainWP_System_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		} else {
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
		}

		$websites = MainWP_DB::instance()->query( $sql );

		$allPosts = array();
		if ( $websites ) {
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( '' == $website->recent_posts ) {
					continue;
				}

				$posts = json_decode( $website->recent_posts, 1 );
				if ( 0 == count( $posts ) ) {
					continue;
				}
				foreach ( $posts as $post ) {
					$post['website'] = (object) array(
						'id'   => $website->id,
						'url'  => $website->url,
						'name' => $website->name,
					);
					$allPosts[]      = $post;
				}
			}
			MainWP_DB::free_result( $websites );
		}

		self::render_top_grid();

		/**
		 * Action: mainwp_recent_posts_widget_top
		 *
		 * Fires at the top of the Recent Posts widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_recent_posts_widget_top' );

		self::render_published_posts( $allPosts, $recent_number );
		self::render_draft_posts( $allPosts, $recent_number );
		self::render_pending_posts( $allPosts, $recent_number );
		self::render_future_posts( $allPosts, $recent_number );
		self::render_trash_posts( $allPosts, $recent_number );

		/**
		 * Action: mainwp_recent_posts_after_lists
		 *
		 * Fires after the recent posts lists, before the bottom actions section.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_recent_posts_after_lists' );
		?>


		<div class="ui hidden divider"></div>

		<div class="ui two column grid">
			<div class="column">
				<a href="<?php echo admin_url( 'admin.php?page=PostBulkManage' ); ?>" title="" class="ui button green basic"><?php esc_html_e( 'Manage Posts', 'mainwp' ); ?></a>
			</div>
			<div class="column right aligned">
				<a href="<?php echo admin_url( 'admin.php?page=PostBulkAdd' ); ?>" title="" class="ui button green"><?php esc_html_e( 'Create New Post', 'mainwp' ); ?></a>
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_recent_posts_widget_bottom
		 *
		 * Fires at the bottom of the Recent Posts widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_recent_posts_widget_bottom' );
	}

	/**
	 * Render MainWP Recent Posts Widget Header
	 */
	public static function render_top_grid() {
		?>
		<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php
					/**
					 * Filter: mainwp_recent_posts_widget_title
					 *
					 * Filters the recent posts widget title text.
					 *
					 * @since 4.1
					 */
					echo esc_html( apply_filters( 'mainwp_recent_posts_widget_title', __( 'Recent Posts', 'mainwp' ) ) );
					?>
					<div class="sub header"><?php esc_html_e( 'The most recent posts from your websites', 'mainwp' ); ?></div>
				</h3>
			</div>
			<div class="four wide column right aligned">
				<div class="ui dropdown right mainwp-dropdown-tab">
						<div class="text"><?php esc_html_e( 'Published', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
						<a class="item recent_posts_published_lnk" data-tab="published" data-value="published" title="<?php esc_attr_e( 'Published', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Published', 'mainwp' ); ?></a>
						<a class="item recent_posts_draft_lnk" data-tab="draft" data-value="draft" title="<?php esc_attr_e( 'Draft', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Draft', 'mainwp' ); ?></a>
						<a class="item recent_posts_pending_lnk" data-tab="pending" data-value="pending" title="<?php esc_attr_e( 'Pending', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Pending', 'mainwp' ); ?></a>
						<a class="item recent_posts_future_lnk" data-tab="future" data-value="future" title="<?php esc_attr_e( 'Scheduled', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Scheduled', 'mainwp' ); ?></a>
						<a class="item recent_posts_trash_lnk" data-tab="trash" data-value="trash" title="<?php esc_attr_e( 'Trash', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
						</div>
				</div>
			</div>
		</div>
		<div class="ui section hidden divider"></div>
		<?php
	}

	/**
	 * Render Published Posts.
	 *
	 * @param array $allPosts      All posts data.
	 * @param int   $recent_number Number of posts.
	 */
	public static function render_published_posts( $allPosts, $recent_number ) {
		$recent_posts_published = MainWP_Utility::get_sub_array_having( $allPosts, 'status', 'publish' );
		$recent_posts_published = MainWP_Utility::sortmulti( $recent_posts_published, 'dts', 'desc' );
		?>
		<div class="recent_posts_published ui tab active" data-tab="published">
			<?php
			/**
			 * Action: mainwp_recent_posts_before_publised_list
			 *
			 * Fires before the list of recent published Posts.
			 *
			 * @param array $allPosts      All posts data.
			 * @param int   $recent_number Number of posts.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_recent_posts_before_publised_list', $allPosts, $recent_number );
			if ( 0 == count( $recent_posts_published ) ) {
				?>
			<h2 class="ui icon header">
				<i class="folder open outline icon"></i>
				<div class="content">
					<?php esc_html_e( 'No published posts found!', 'mainwp' ); ?>
				</div>
			</h2>
				<?php
			}
			?>
			<div class="ui middle aligned divided selection list">
			<?php
			$_count = count( $recent_posts_published );
			for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
				if ( ! isset( $recent_posts_published[ $i ]['title'] ) || ( '' == $recent_posts_published[ $i ]['title'] ) ) {
					$recent_posts_published[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_published[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_published[ $i ]['dts'], '-' ) ) {
						$recent_posts_published[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_posts_published[ $i ]['dts'] ) );
					}
				}

				$name = wp_strip_all_tags( $recent_posts_published[ $i ]['website']->name );

				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_published[ $i ]['id'] ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_published[ $i ]['website']->id ); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_published[ $i ]['website']->url ); ?>?p=<?php echo esc_attr( $recent_posts_published[ $i ]['id'] ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_posts_published[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
						<?php echo esc_html( $recent_posts_published[ $i ]['dts'] ); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_published[ $i ]['website']->url ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-unpublish" href="#"><?php esc_html_e( 'Unpublish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_posts_published[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_published[ $i ]['id'] . '&action=edit' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item" href="<?php echo esc_url( $recent_posts_published[ $i ]['website']->url ) . ( '/' != substr( $recent_posts_published[ $i ]['website']->url, - 1 ) ? '/' : '' ) . '?p=' . esc_attr( $recent_posts_published[ $i ]['id'] ); ?>" target="_blank"><?php esc_html_e( 'View', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			<?php
			/**
			 * Action: mainwp_recent_posts_after_publised_list
			 *
			 * Fires after the list of recent published Posts.
			 *
			 * @param array $allPosts      All posts data.
			 * @param int   $recent_number Number of posts.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_recent_posts_after_publised_list', $allPosts, $recent_number );
			?>
		</div>
		<?php
	}

	/**
	 * Render all draft posts.
	 *
	 * @param array $allPosts      All posts data.
	 * @param int   $recent_number Number of posts.
	 */
	public static function render_draft_posts( $allPosts, $recent_number ) {

		$recent_posts_draft = MainWP_Utility::get_sub_array_having( $allPosts, 'status', 'draft' );
		$recent_posts_draft = MainWP_Utility::sortmulti( $recent_posts_draft, 'dts', 'desc' );
		?>
		<div class="recent_posts_draft ui tab" data-tab="draft">
			<?php
			/**
			 * Action: mainwp_recent_posts_before_draft_list
			 *
			 * Fires before the list of recent draft Posts.
			 *
			 * @param array $allPosts      All posts data.
			 * @param int   $recent_number Number of posts.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_recent_posts_before_draft_list', $allPosts, $recent_number );
			if ( 0 == count( $recent_posts_draft ) ) {
				?>
			<h2 class="ui icon header">
				<i class="folder open outline icon"></i>
				<div class="content">
					<?php esc_html_e( 'No draft posts found!', 'mainwp' ); ?>
				</div>
			</h2>
				<?php
			}
			?>
			<div class="ui middle aligned divided selection list">
			<?php
			$_count = count( $recent_posts_draft );
			for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
				if ( ! isset( $recent_posts_draft[ $i ]['title'] ) || ( '' == $recent_posts_draft[ $i ]['title'] ) ) {
					$recent_posts_draft[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_draft[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_draft[ $i ]['dts'], '-' ) ) {
						$recent_posts_draft[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_posts_draft[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_draft[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_draft[ $i ]['id'] ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_draft[ $i ]['website']->id ); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_draft[ $i ]['website']->url ); ?>?p=<?php echo esc_attr( $recent_posts_draft[ $i ]['id'] ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_posts_draft[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
							<?php echo esc_html( $recent_posts_draft[ $i ]['dts'] ); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_draft[ $i ]['website']->url ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $recent_posts_draft[ $i ]['website']->id ); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_draft[ $i ]['id'] . '&action=edit' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			<?php
			/**
			 * Action: mainwp_recent_posts_after_draft_list
			 *
			 * Fires after the list of recent draft Posts.
			 *
			 * @param array $allPosts      All posts data.
			 * @param int   $recent_number Number of posts.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_recent_posts_after_draft_list', $allPosts, $recent_number );
			?>
		</div>
		<?php
	}

	/**
	 * Render all pending posts.
	 *
	 * @param array $allPosts      All posts data.
	 * @param int   $recent_number Number of posts.
	 */
	public static function render_pending_posts( $allPosts, $recent_number ) {
		$recent_posts_pending = MainWP_Utility::get_sub_array_having( $allPosts, 'status', 'pending' );
		$recent_posts_pending = MainWP_Utility::sortmulti( $recent_posts_pending, 'dts', 'desc' );

		?>
		<div class="recent_posts_pending ui bottom attached tab" data-tab="pending">
				<?php
				/**
				 * Action: mainwp_recent_posts_before_pending_list
				 *
				 * Fires before the list of recent pending Posts.
				 *
				 * @param array $allPosts      All posts data.
				 * @param int   $recent_number Number of posts.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_recent_posts_before_pending_list', $allPosts, $recent_number );
				if ( 0 == count( $recent_posts_pending ) ) {
					?>
				<h2 class="ui icon header">
					<i class="folder open outline icon"></i>
					<div class="content">
						<?php esc_html_e( 'No pending posts found!', 'mainwp' ); ?>
					</div>
				</h2>
					<?php
				}
				?>
			<div class="ui middle aligned divided selection list">
			<?php
			$_count = count( $recent_posts_pending );
			for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
				if ( ! isset( $recent_posts_pending[ $i ]['title'] ) || ( '' == $recent_posts_pending[ $i ]['title'] ) ) {
					$recent_posts_pending[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_pending[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_pending[ $i ]['dts'], '-' ) ) {
						$recent_posts_pending[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_posts_pending[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_pending[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_pending[ $i ]['id'] ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_pending[ $i ]['website']->id ); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_pending[ $i ]['website']->url ); ?>?p=<?php echo esc_attr( $recent_posts_pending[ $i ]['id'] ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_posts_pending[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
							<?php echo esc_html( $recent_posts_pending[ $i ]['dts'] ); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_pending[ $i ]['website']->url ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $recent_posts_pending[ $i ]['website']->id ); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_pending[ $i ]['id'] . '&action=edit' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			<?php
			/**
			 * Action: mainwp_recent_posts_after_pending_list
			 *
			 * Fires after the list of recent pending Posts.
			 *
			 * @param array $allPosts      All posts data.
			 * @param int   $recent_number Number of posts.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_recent_posts_after_pending_list', $allPosts, $recent_number );
			?>
		</div>
		<?php
	}

	/**
	 * Render all future posts.
	 *
	 * @param array $allPosts      All posts data.
	 * @param int   $recent_number Number of posts.
	 */
	public static function render_future_posts( $allPosts, $recent_number ) {
		$recent_posts_future = MainWP_Utility::get_sub_array_having( $allPosts, 'status', 'future' );
		$recent_posts_future = MainWP_Utility::sortmulti( $recent_posts_future, 'dts', 'desc' );
		?>
		<div class="recent_posts_future ui tab" data-tab="future">
				<?php
				/**
				 * Action: mainwp_recent_posts_before_future_list
				 *
				 * Fires before the list of recent future Posts.
				 *
				 * @param array $allPosts      All posts data.
				 * @param int   $recent_number Number of posts.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_recent_posts_before_future_list', $allPosts, $recent_number );
				if ( 0 == count( $recent_posts_future ) ) {
					?>
				<h2 class="ui icon header">
					<i class="folder open outline icon"></i>
					<div class="content">
						<?php esc_html_e( 'No future posts found!', 'mainwp' ); ?>
					</div>
				</h2>
					<?php
				}
				?>
			<div class="ui middle aligned divided selection list">
			<?php
			$_count = count( $recent_posts_future );
			for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
				if ( ! isset( $recent_posts_future[ $i ]['title'] ) || ( '' == $recent_posts_future[ $i ]['title'] ) ) {
					$recent_posts_future[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_future[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_future[ $i ]['dts'], '-' ) ) {
						$recent_posts_future[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_posts_future[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_future[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_future[ $i ]['id'] ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_future[ $i ]['website']->id ); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_future[ $i ]['website']->url ); ?>?p=<?php echo esc_attr( $recent_posts_future[ $i ]['id'] ); ?>" class="mainwp-may-hide-referrer"  target="_blank"><?php echo htmlentities( $recent_posts_future[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
							<?php echo esc_html( $recent_posts_future[ $i ]['dts'] ); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_future[ $i ]['website']->url ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $recent_posts_future[ $i ]['website']->id ); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_future[ $i ]['id'] . '&action=edit' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $recent_posts_future[ $i ]['website']->id ); ?>&newWindow=yes&openUrl=yes&location=<?php echo base64_encode( '?p=' . $recent_posts_future[ $i ]['id'] . '&preview=true' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" target="_blank"><?php esc_html_e( 'Preview', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			<?php
			/**
			 * Action: mainwp_recent_posts_after_future_list
			 *
			 * Fires after the list of recent future Posts.
			 *
			 * @param array $allPosts      All posts data.
			 * @param int   $recent_number Number of posts.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_recent_posts_after_future_list', $allPosts, $recent_number );
			?>
		</div>
		<?php
	}

	/**
	 * Render all trashed posts.
	 *
	 * @param array $allPosts      All posts data.
	 * @param int   $recent_number Number of posts.
	 */
	public static function render_trash_posts( $allPosts, $recent_number ) {
		$recent_posts_trash = MainWP_Utility::get_sub_array_having( $allPosts, 'status', 'trash' );
		$recent_posts_trash = MainWP_Utility::sortmulti( $recent_posts_trash, 'dts', 'desc' );

		?>
		<div class="recent_posts_trash ui tab" data-tab="trash">
				<?php
				/**
				 * Action: mainwp_recent_posts_before_trash_list
				 *
				 * Fires before the list of recent trash Posts.
				 *
				 * @param array $allPosts      All posts data.
				 * @param int   $recent_number Number of posts.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_recent_posts_before_trash_list', $allPosts, $recent_number );
				if ( 0 == count( $recent_posts_trash ) ) {
					?>
				<h2 class="ui icon header">
					<i class="folder open outline icon"></i>
					<div class="content">
						<?php esc_html_e( 'No trashed posts found!', 'mainwp' ); ?>
					</div>
				</h2>
					<?php
				}
				?>
			<div class="ui middle aligned divided selection list">
			<?php
			$_count = count( $recent_posts_trash );
			for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
				if ( ! isset( $recent_posts_trash[ $i ]['title'] ) || ( '' == $recent_posts_trash[ $i ]['title'] ) ) {
					$recent_posts_trash[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_trash[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_trash[ $i ]['dts'], '-' ) ) {
						$recent_posts_trash[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_posts_trash[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_trash[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_trash[ $i ]['id'] ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_trash[ $i ]['website']->id ); ?>"/>
						<div class="six wide column middle aligned">
						<?php echo htmlentities( $recent_posts_trash[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?>
						</div>
						<div class="four wide column middle aligned">
						<?php echo esc_html( $recent_posts_trash[ $i ]['dts'] ); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_trash[ $i ]['website']->url ); ?>" class="mainwp-may-hide-referrer"  target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a href="#" class="item mainwp-post-restore"><?php esc_html_e( 'Restore', 'mainwp' ); ?></a>
										<a href="#" class="item mainwp-post-delete"><?php esc_html_e( 'Delete permanently', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			<?php
			/**
			 * Action: mainwp_recent_posts_after_trash_list
			 *
			 * Fires after the list of recent trash Posts.
			 *
			 * @param array $allPosts      All posts data.
			 * @param int   $recent_number Number of posts.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_recent_posts_after_trash_list', $allPosts, $recent_number );
			?>
		</div>
		<?php
	}

	/**
	 * Method publish()
	 *
	 * Publish Post.
	 */
	public static function publish() {
		self::action( 'publish' );
		die( wp_json_encode( array( 'result' => __( 'Post has been published!', 'mainwp' ) ) ) );
	}

	/**
	 * Method approve()
	 *
	 * Approve Post.
	 */
	public static function approve() {
		self::action( 'publish' );
		die( wp_json_encode( array( 'result' => __( 'Post has been approved!', 'mainwp' ) ) ) );
	}

	/**
	 * Method unpublish()
	 *
	 * Unpublish Post.
	 */
	public static function unpublish() {
		self::action( 'unpublish' );
		die( wp_json_encode( array( 'result' => __( 'Post has been unpublished!', 'mainwp' ) ) ) );
	}

	/**
	 * Method trash()
	 *
	 * Trash Post.
	 */
	public static function trash() {
		self::action( 'trash' );
		die( wp_json_encode( array( 'result' => __( 'Post has been moved to trash!', 'mainwp' ) ) ) );
	}

	/**
	 * Method delete()
	 *
	 * Delete Post.
	 */
	public static function delete() {
		self::action( 'delete' );
		die( wp_json_encode( array( 'result' => __( 'Post has been permanently deleted!', 'mainwp' ) ) ) );
	}

	/**
	 * Method restore()
	 *
	 * Restore Post.
	 */
	public static function restore() {
		self::action( 'restore' );
		die( wp_json_encode( array( 'result' => __( 'Post has been restored!', 'mainwp' ) ) ) );
	}

	/**
	 * Method action()
	 *
	 * Initiate try catch for chosen Action
	 *
	 * @param string $pAction Post Action.
	 * @param string $type Post type.
	 */
	public static function action( $pAction, $type = 'post' ) {
		$postId    = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : false;
		$websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false;

		if ( empty( $postId ) || empty( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => 'Invalid request!' ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => 'You can not edit this website!' ) ) );
		}

		/**
		* Action: mainwp_before_post_action
		*
		* Fires before post/page publish/unpublish/trash/delete/restore actions.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_before_post_action', $type, $pAction, $postId, $website );

		try {
			$information = MainWP_Connect::fetch_url_authed(
				$website,
				'post_action',
				array(
					'action' => $pAction,
					'id'     => $postId,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		/**
		* Action: mainwp_after_post_action
		*
		* Fires after post/page publish/unpublish/trash/delete/restore actions.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_after_post_action', $information, $type, $pAction, $postId, $website );

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' != $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => 'Unexpected error!' ) ) );
		}
	}

	/**
	 * Method action_update()
	 *
	 * Update Post Action.
	 *
	 * @param mixed $pAction Post Action.
	 */
	public static function action_update( $pAction ) {
		$postId    = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : false;
		$websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false;
		$post_data = isset( $_POST['post_data'] ) ? wp_unslash( $_POST['post_data'] ) : array();

		if ( empty( $postId ) || empty( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

		try {
			$information = MainWP_Connect::fetch_url_authed(
				$website,
				'post_action',
				array(
					'action'    => $pAction,
					'id'        => $postId,
					'post_data' => $post_data,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( 'FAIL' );
		}
		if ( ! isset( $information['status'] ) || ( 'SUCCESS' != $information['status'] ) ) {
			die( 'FAIL' );
		}
	}

}
