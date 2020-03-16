<?php
/**
 * MainWP Recent Posts Widget
 *
 * Displays the Child Sites most recent published draft, pending, trash & future posts.
 *
 * @package MainWP/Widget_Recent_Posts
 */

/**
 * Class MainWP_Recent_Posts
 *
 * Displays the Child Sites most recent published draft, pending, trash & future posts.
 */
class MainWP_Recent_Posts {

	/**
	 * Method getClassName()
	 *
	 * @return string __CLASS__ Class Name
	 */
	public static function getClassName() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * Fire off renderSites().
	 */
	public static function render() {
		self::renderSites( false, false );
	}

	/**
	 * Method renderSites()
	 *
	 * Build the resent posts list.
	 *
	 * @param mixed   $renew
	 * @param boolean $pExit ture|false If $pEixt is true then exit.
	 */
	public static function renderSites( $renew, $pExit = true ) {

		$recent_number = apply_filters( 'mainwp_recent_posts_pages_number', 5 ); // $recent_number: support >=0 and <= 30.

		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		} else {
			$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		}

		$websites = MainWP_DB::Instance()->query( $sql );

		$allPosts = array();
		if ( $websites ) {
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( $website->recent_posts == '' ) {
					continue;
				}

				$posts = json_decode( $website->recent_posts, 1 );
				if ( count( $posts ) == 0 ) {
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

		$recent_posts_published = MainWP_Utility::getSubArrayHaving( $allPosts, 'status', 'publish' );
		$recent_posts_published = MainWP_Utility::sortmulti( $recent_posts_published, 'dts', 'desc' );
		$recent_posts_draft     = MainWP_Utility::getSubArrayHaving( $allPosts, 'status', 'draft' );
		$recent_posts_draft     = MainWP_Utility::sortmulti( $recent_posts_draft, 'dts', 'desc' );
		$recent_posts_pending   = MainWP_Utility::getSubArrayHaving( $allPosts, 'status', 'pending' );
		$recent_posts_pending   = MainWP_Utility::sortmulti( $recent_posts_pending, 'dts', 'desc' );
		$recent_posts_trash     = MainWP_Utility::getSubArrayHaving( $allPosts, 'status', 'trash' );
		$recent_posts_trash     = MainWP_Utility::sortmulti( $recent_posts_trash, 'dts', 'desc' );
		$recent_posts_future    = MainWP_Utility::getSubArrayHaving( $allPosts, 'status', 'future' );
		$recent_posts_future    = MainWP_Utility::sortmulti( $recent_posts_future, 'dts', 'desc' );

		?>

		<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php _e('Recent Posts', 'mainwp'); ?>
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

		<!-- Published List -->

			<div class="recent_posts_published ui tab active" data-tab="published">
				<?php
				if ( count( $recent_posts_published ) == 0 ) {
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
				if ( ! isset( $recent_posts_published[ $i ]['title'] ) || ( $recent_posts_published[ $i ]['title'] == '' ) ) {
					$recent_posts_published[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_published[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_published[ $i ]['dts'], '-' ) ) {
						$recent_posts_published[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_posts_published[ $i ]['dts'] ) );
					}
				}

				$name = wp_strip_all_tags( $recent_posts_published[ $i ]['website']->name );

				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_published[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_published[ $i ]['website']->id); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_published[ $i ]['website']->url); ?>?p=<?php echo esc_attr($recent_posts_published[ $i ]['id']); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_posts_published[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
						<?php echo esc_html($recent_posts_published[ $i ]['dts']); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_published[ $i ]['website']->url); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-unpublish" href="#"><?php esc_html_e( 'Unpublish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_posts_published[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_published[ $i ]['id'] . '&action=edit' ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item" href="<?php echo esc_url($recent_posts_published[ $i ]['website']->url) . ( substr( $recent_posts_published[ $i ]['website']->url, - 1 ) != '/' ? '/' : '' ) . '?p=' . esc_attr($recent_posts_published[ $i ]['id']); ?>" target="_blank"><?php esc_html_e( 'View', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php _e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
		</div>

		<!-- END Published List -->

		<!-- Draft List -->

			<div class="recent_posts_draft ui tab" data-tab="draft">
				<?php
				if ( count( $recent_posts_draft ) == 0 ) {
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
				if ( ! isset( $recent_posts_draft[ $i ]['title'] ) || ( $recent_posts_draft[ $i ]['title'] == '' ) ) {
					$recent_posts_draft[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_draft[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_draft[ $i ]['dts'], '-' ) ) {
						$recent_posts_draft[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_posts_draft[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_draft[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_draft[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_draft[ $i ]['website']->id); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_draft[ $i ]['website']->url); ?>?p=<?php echo esc_attr($recent_posts_draft[ $i ]['id']); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_posts_draft[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
						<?php echo esc_html($recent_posts_draft[ $i ]['dts']); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_draft[ $i ]['website']->url); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr($recent_posts_draft[ $i ]['website']->id); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_draft[ $i ]['id'] . '&action=edit' ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php _e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
		</div>

		<!-- END Draft List -->

		<!-- Pending List -->

			<div class="recent_posts_pending ui bottom attached tab" data-tab="pending">
				<?php
				if ( count( $recent_posts_pending ) == 0 ) {
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
				if ( ! isset( $recent_posts_pending[ $i ]['title'] ) || ( $recent_posts_pending[ $i ]['title'] == '' ) ) {
					$recent_posts_pending[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_pending[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_pending[ $i ]['dts'], '-' ) ) {
						$recent_posts_pending[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_posts_pending[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_pending[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_pending[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_pending[ $i ]['website']->id); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url( $recent_posts_pending[ $i ]['website']->url ); ?>?p=<?php echo esc_attr($recent_posts_pending[ $i ]['id']); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_posts_pending[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
						<?php echo esc_html($recent_posts_pending[ $i ]['dts']); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_pending[ $i ]['website']->url); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr($recent_posts_pending[ $i ]['website']->id); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_pending[ $i ]['id'] . '&action=edit' ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php _e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
		</div>

		<!-- END Pending List -->

		<!-- Future List -->

			<div class="recent_posts_future ui tab" data-tab="future">
				<?php
				if ( count( $recent_posts_future ) == 0 ) {
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
				if ( ! isset( $recent_posts_future[ $i ]['title'] ) || ( $recent_posts_future[ $i ]['title'] == '' ) ) {
					$recent_posts_future[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_future[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_future[ $i ]['dts'], '-' ) ) {
						$recent_posts_future[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_posts_future[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_future[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_future[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $recent_posts_future[ $i ]['website']->id); ?>"/>
						<div class="six wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_future[ $i ]['website']->url); ?>?p=<?php echo esc_attr($recent_posts_future[ $i ]['id']); ?>" class="mainwp-may-hide-referrer"  target="_blank"><?php echo htmlentities( $recent_posts_future[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
						</div>
						<div class="four wide column middle aligned">
						<?php echo esc_html($recent_posts_future[ $i ]['dts']); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_future[ $i ]['website']->url); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
						</div>
						<div class="two wide column right aligned">
							<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr($recent_posts_future[ $i ]['website']->id); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_posts_future[ $i ]['id'] . '&action=edit' ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr($recent_posts_future[ $i ]['website']->id); ?>&newWindow=yes&openUrl=yes&location=<?php echo base64_encode( '?p=' . $recent_posts_future[ $i ]['id'] . '&preview=true' ); ?>" target="_blank"><?php esc_html_e( 'Preview', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
					</div>
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php _e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
		</div>

		<!-- END Future List -->

		<!-- Trash List -->

			<div class="recent_posts_trash ui tab" data-tab="trash">
				<?php
				if ( count( $recent_posts_trash ) == 0 ) {
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
				if ( ! isset( $recent_posts_trash[ $i ]['title'] ) || ( $recent_posts_trash[ $i ]['title'] == '' ) ) {
					$recent_posts_trash[ $i ]['title'] = '(No Title)';
				}
				if ( isset( $recent_posts_trash[ $i ]['dts'] ) ) {
					if ( ! stristr( $recent_posts_trash[ $i ]['dts'], '-' ) ) {
						$recent_posts_trash[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_posts_trash[ $i ]['dts'] ) );
					}
				}
				$name = wp_strip_all_tags( $recent_posts_trash[ $i ]['website']->name );
				?>
				<div class="item">
					<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_trash[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_posts_trash[ $i ]['website']->id); ?>"/>
						<div class="six wide column middle aligned">
						<?php echo htmlentities( $recent_posts_trash[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?>
						</div>
						<div class="four wide column middle aligned">
						<?php echo esc_html($recent_posts_trash[ $i ]['dts']); ?>
						</div>
						<div class="four wide column middle aligned">
							<a href="<?php echo esc_url($recent_posts_trash[ $i ]['website']->url); ?>" class="mainwp-may-hide-referrer"  target="_blank"><?php echo $name; ?></a>
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
					<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php _e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
		</div>
		<!-- END Trash List -->

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
		if ( $pExit == true ) {
			exit();
		}
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
	 * Post action.
	 *
	 * @param mixed $pAction Post Action.
	 */
	public static function action( $pAction ) {
		$postId       = $_POST['postId'];
		$websiteIdEnc = $_POST['websiteId'];

		if ( ! MainWP_Utility::ctype_digit( $postId ) ) {
			die( wp_json_encode( array( 'error' => 'Invalid request!' ) ) );
		}
		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => 'Invalid request!' ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => 'You can not edit this website!' ) ) );
		}

		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'post_action', array(
				'action' => $pAction,
				'id'     => $postId,
			) );
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::getErrorMessage( $e ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( $information['status'] != 'SUCCESS' ) ) {
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
		$postId       = $_POST['postId'];
		$websiteIdEnc = $_POST['websiteId'];
		$post_data    = $_POST['post_data'];

		if ( ! MainWP_Utility::ctype_digit( $postId ) ) {
			die( 'FAIL' );
		}
		$websiteId = $websiteIdEnc;

		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'post_action', array(
				'action'     => $pAction,
				'id'         => $postId,
				'post_data'  => $post_data,
			) );
		} catch ( MainWP_Exception $e ) {
			die( 'FAIL' );
		}
		if ( ! isset( $information['status'] ) || ( $information['status'] != 'SUCCESS' ) ) {
			die( 'FAIL' );
		}
	}

}
