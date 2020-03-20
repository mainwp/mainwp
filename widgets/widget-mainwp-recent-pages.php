<?php
/**
 * MainWP Recent Pages Widget
 *
 * Displays the Child Sites most recent published draft, pending, trash & future Pages.
 *
 * @package MainWP/Widget_Mainwp_Recent_Pages
 */

/**
 * Class MainWP_Recent_Pages
 *
 * Displays the Child Sites most recent published draft, pending, trash & future Pages.
 */
class MainWP_Recent_Pages {

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
	 * Fire off Method renderSites().
	 */
	public static function render() {
		self::renderSites( false, false );
	}

	/**
	 * Method renderSites()
	 *
	 * Build the resent pages list.
	 *
	 * @param mixed   $renew Site Data.
	 * @param boolean $pExit true|false If $pExit is true exit.
	 */
	public static function renderSites( $renew, $pExit = true ) {

		$recent_number = apply_filters('mainwp_recent_posts_pages_number', 5);

		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		} else {
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
		}

		$websites = MainWP_DB::instance()->query( $sql );

		$allPages = array();
		if ( $websites ) {
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( $website->recent_pages == '' ) {
					continue;
				}

				$pages = json_decode( $website->recent_pages, 1 );
				if ( count( $pages ) == 0 ) {
					continue;
				}
				foreach ( $pages as $page ) {
					$page['website'] = (object) array(
						'id'   => $website->id,
						'url'  => $website->url,
						'name' => $website->name,
					);
					$allPages[]      = $page;
				}
			}
			MainWP_DB::free_result( $websites );
		}

		$recent_pages_published = MainWP_Utility::get_sub_array_having( $allPages, 'status', 'publish' );
		$recent_pages_published = MainWP_Utility::sortmulti( $recent_pages_published, 'dts', 'desc' );
		$recent_pages_draft     = MainWP_Utility::get_sub_array_having( $allPages, 'status', 'draft' );
		$recent_pages_draft     = MainWP_Utility::sortmulti( $recent_pages_draft, 'dts', 'desc' );
		$recent_pages_pending   = MainWP_Utility::get_sub_array_having( $allPages, 'status', 'pending' );
		$recent_pages_pending   = MainWP_Utility::sortmulti( $recent_pages_pending, 'dts', 'desc' );
		$recent_pages_trash     = MainWP_Utility::get_sub_array_having( $allPages, 'status', 'trash' );
		$recent_pages_trash     = MainWP_Utility::sortmulti( $recent_pages_trash, 'dts', 'desc' );
		$recent_pages_future    = MainWP_Utility::get_sub_array_having( $allPages, 'status', 'future' );
		$recent_pages_future    = MainWP_Utility::sortmulti( $recent_pages_future, 'dts', 'desc' );

		// MainWP_UI::renderBeginReadyPopup();
		?>

		<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php esc_html_e('Recent Pages', 'mainwp'); ?>
					<div class="sub header"><?php esc_html_e( 'The most recent pages from your websites', 'mainwp' ); ?></div>
				</h3>
			</div>
			<div class="four wide column right aligned">
				<div class="ui dropdown right mainwp-dropdown-tab">
						<div class="text"><?php esc_html_e( 'Published', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
							<a class="item recent_posts_published_lnk" data-tab="page-published" data-value="published" title="<?php esc_attr_e( 'Published', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Published', 'mainwp' ); ?></a>
							<a class="item recent_posts_draft_lnk" data-tab="page-draft" data-value="draft" title="<?php esc_attr_e( 'Draft', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Draft', 'mainwp' ); ?></a>
							<a class="item recent_posts_pending_lnk" data-tab="page-pending" data-value="pending" title="<?php esc_attr_e( 'Pending', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Pending', 'mainwp' ); ?></a>
							<a class="item recent_posts_future_lnk" data-tab="page-future" data-value="future" title="<?php esc_attr_e( 'Scheduled', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Scheduled', 'mainwp' ); ?></a>
							<a class="item recent_posts_trash_lnk" data-tab="page-trash" data-value="trash" title="<?php esc_attr_e( 'Trash', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
						</div>
				</div>
			</div>
			</div>

			<div class="ui section hidden divider"></div>

			<!-- Published List -->

			<div class="recent_posts_published ui tab active" data-tab="page-published">
				<?php if ( count( $recent_pages_published ) == 0 ) : ?>
				<h2 class="ui icon header">
					<i class="folder open outline icon"></i>
					<div class="content">
						<?php esc_html_e( 'No pages found!', 'mainwp' ); ?>
					</div>
				</h2>
				<?php endif; ?>
				<div class="ui middle aligned divided selection list">
				<?php
				$_count = count( $recent_pages_published );
				for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
					if ( ! isset( $recent_pages_published[ $i ]['title'] ) || ( $recent_pages_published[ $i ]['title'] == '' ) ) {
						$recent_pages_published[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_published[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_published[ $i ]['dts'], '-' ) ) {
							$recent_pages_published[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_pages_published[ $i ]['dts'] ) );
						}
					}

					$name = wp_strip_all_tags( $recent_pages_published[ $i ]['website']->name );

					?>
					<div class="item">
						<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_published[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_published[ $i ]['website']->id); ?>"/>
							<div class="six wide column middle aligned">
								<a href="<?php echo esc_url($recent_pages_published[ $i ]['website']->url); ?>?p=<?php echo esc_attr($recent_pages_published[ $i ]['id']); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_pages_published[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
							</div>
							<div class="four wide column middle aligned">
							<?php echo esc_html($recent_pages_published[ $i ]['dts']); ?>
						</div>
							<div class="four wide column middle aligned">
								<a href="<?php echo esc_url($recent_pages_published[ $i ]['website']->url); ?>" target="_blank"><?php echo $name; ?></a>
							</div>
							<div class="two wide column right aligned">
								<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
								<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-unpublish" href="#"><?php esc_html_e( 'Unpublish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr($recent_pages_published[ $i ]['website']->id); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . esc_attr($recent_pages_published[ $i ]['id']) . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#" ><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item" href="<?php echo esc_url($recent_pages_published[ $i ]['website']->url) . ( substr( $recent_pages_published[ $i ]['website']->url, - 1 ) != '/' ? '/' : '' ) . '?p=' . esc_attr($recent_pages_published[ $i ]['id']); ?>" target="_blank" class="mainwp-may-hide-referrer" title="View '<?php echo esc_attr($recent_pages_published[ $i ]['title']); ?>'" rel="permalink"><?php esc_html_e( 'View', 'mainwp' ); ?></a>
										<a class="item mainwp-post-viewall" href="admin.php?page=PageBulkManage" ><?php esc_html_e( 'View all', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
						</div>
						<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			</div>

			<!-- END Published List -->

			<!-- Draft List -->

			<div class="recent_posts_draft ui tab" data-tab="page-draft">
				<?php
				if ( count( $recent_pages_draft ) == 0 ) {
					?>
					<h2 class="ui icon header">
						<i class="folder open outline icon"></i>
						<div class="content">
							<?php esc_html_e( 'No draft pages found!', 'mainwp' ); ?>
						</div>
					</h2>
					<?php
				}
				?>
				<div class="ui middle aligned divided selection list">
				<?php
				$_count = count( $recent_pages_draft );
				for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
					if ( ! isset( $recent_pages_draft[ $i ]['title'] ) || ( $recent_pages_draft[ $i ]['title'] == '' ) ) {
						$recent_pages_draft[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_draft[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_draft[ $i ]['dts'], '-' ) ) {
							$recent_pages_draft[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_pages_draft[ $i ]['dts'] ) );
						}
					}
					$name = wp_strip_all_tags( $recent_pages_draft[ $i ]['website']->name );
					?>
					<div class="item">
						<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_draft[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_draft[ $i ]['website']->id); ?>"/>
							<div class="six wide column middle aligned">
								<a href="<?php echo esc_url($recent_pages_draft[ $i ]['website']->url); ?>?p=<?php echo esc_attr($recent_pages_draft[ $i ]['id']); ?>" target="_blank" class="mainwp-may-hide-referrer" ><?php echo htmlentities( $recent_pages_draft[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
							</div>
							<div class="four wide column middle aligned">
							<?php echo esc_html($recent_pages_draft[ $i ]['dts']); ?>
						</div>
							<div class="four wide column middle aligned">
								<a href="<?php echo esc_url($recent_pages_draft[ $i ]['website']->url); ?>" target="_blank" class="mainwp-may-hide-referrer" ><?php echo $name; ?></a>
							</div>
							<div class="two wide column right aligned">
								<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
									<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_pages_draft[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_pages_draft[ $i ]['id'] . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item mainwp-post-viewall" href="admin.php?page=PostBulkManage"><?php esc_html_e( 'View all', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
						</div>
						 <div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			</div>

			<!-- END Draft List -->

			<!-- Pending List -->

			<div class="recent_posts_pending ui bottom attached tab" data-tab="page-pending">
				<?php
				if ( count( $recent_pages_pending ) == 0 ) {
					?>
					<h2 class="ui icon header">
						<i class="folder open outline icon"></i>
						<div class="content">
							<?php esc_html_e( 'No pending pages found!', 'mainwp' ); ?>
						</div>
					</h2>
					<?php
				}
				?>
				<div class="ui middle aligned divided selection list">
				<?php
				$_count = count( $recent_pages_pending );
				for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
					if ( ! isset( $recent_pages_pending[ $i ]['title'] ) || ( $recent_pages_pending[ $i ]['title'] == '' ) ) {
						$recent_pages_pending[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_pending[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_pending[ $i ]['dts'], '-' ) ) {
							$recent_pages_pending[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_pages_pending[ $i ]['dts'] ) );
						}
					}
					$name = wp_strip_all_tags( $recent_pages_pending[ $i ]['website']->name );
					?>
					<div class="item">
						<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_pending[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_pending[ $i ]['website']->id); ?>"/>
							<div class="six wide column middle aligned">
								<a href="<?php echo esc_url( $recent_pages_pending[ $i ]['website']->url ); ?>?p=<?php echo esc_attr( $recent_pages_pending[ $i ]['id'] ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_pages_pending[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
							</div>
							<div class="four wide column middle aligned">
							<?php echo esc_html( $recent_pages_pending[ $i ]['dts'] ); ?>
						</div>
							<div class="four wide column middle aligned">
								<a href="<?php echo esc_url( $recent_pages_pending[ $i ]['website']->url ); ?>" class="mainwp-may-hide-referrer" target="_blank" ><?php echo $name; ?></a>
							</div>
							<div class="two wide column right aligned">
								<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
									<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_pages_pending[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_pages_pending[ $i ]['id'] . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item mainwp-post-viewall" href="admin.php?page=PostBulkManage"><?php esc_html_e( 'View all', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
						</div>
						<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			</div>

			<!-- END Pending List -->

			<!-- Future List -->

			<div class="recent_posts_future ui tab" data-tab="page-future">
				<?php
				if ( count( $recent_pages_future ) == 0 ) {
					?>
					<h2 class="ui icon header">
						<i class="folder open outline icon"></i>
						<div class="content">
							<?php esc_html_e( 'No future pages found!', 'mainwp' ); ?>
						</div>
					</h2>
					<?php
				}
				?>
				<div class="ui middle aligned divided selection list">
				<?php
				$_count = count( $recent_pages_future );
				for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
					if ( ! isset( $recent_pages_future[ $i ]['title'] ) || ( $recent_pages_future[ $i ]['title'] == '' ) ) {
						$recent_pages_future[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_future[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_future[ $i ]['dts'], '-' ) ) {
							$recent_pages_future[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_pages_future[ $i ]['dts'] ) );
						}
					}
					$name = wp_strip_all_tags( $recent_pages_future[ $i ]['website']->name );
					?>
					<div class="item">
						<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_future[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_future[ $i ]['website']->id); ?>"/>
							<div class="six wide column middle aligned">
								<a href="<?php echo esc_url($recent_pages_future[ $i ]['website']->url); ?>?p=<?php echo esc_attr($recent_pages_future[ $i ]['id']); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo htmlentities( $recent_pages_future[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a>
							</div>
							<div class="four wide column middle aligned">
							<?php echo esc_html($recent_pages_future[ $i ]['dts']); ?>
						</div>
							<div class="four wide column middle aligned">
								<a href="<?php echo esc_url($recent_pages_future[ $i ]['website']->url); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo $name; ?></a>
							</div>
							<div class="two wide column right aligned">
								<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
									<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item mainwp-post-publish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr($recent_pages_future[ $i ]['website']->id); ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_pages_future[ $i ]['id'] . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item mainwp-post-trash" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr($recent_pages_future[ $i ]['website']->id); ?>&newWindow=yes&openUrl=yes&location=<?php echo base64_encode( '?p=' . $recent_pages_future[ $i ]['id'] . '&preview=true' ); ?>" target="_blank" title="Preview '<?php echo esc_attr($recent_pages_future[ $i ]['title']); ?>'" rel="permalink"><?php esc_html_e( 'Preview', 'mainwp' ); ?></a>
										<a class="item mainwp-post-viewall" href="admin.php?page=PostBulkManage"><?php esc_html_e( 'View all', 'mainwp' ); ?></a>
									</div>
							</div>
						</div>
						</div>
						<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
			</div>

			<!-- END Future  List -->

			<!-- Trash List -->

			<div class="recent_posts_trash ui tab" data-tab="page-trash">
				<?php
				if ( count( $recent_pages_trash ) == 0 ) {
					?>
					<h2 class="ui icon header">
						<i class="folder open outline icon"></i>
						<div class="content">
							<?php esc_html_e( 'No trashed pages found!', 'mainwp' ); ?>
						</div>
					</h2>
					<?php
				}
				?>
				<div class="ui middle aligned divided selection list">
				<?php
				$_count = count( $recent_pages_trash );
				for ( $i = 0; $i < $_count && $i < $recent_number; $i ++ ) {
					if ( ! isset( $recent_pages_trash[ $i ]['title'] ) || ( $recent_pages_trash[ $i ]['title'] == '' ) ) {
						$recent_pages_trash[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_trash[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_trash[ $i ]['dts'], '-' ) ) {
							$recent_pages_trash[ $i ]['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $recent_pages_trash[ $i ]['dts'] ) );
						}
					}

					$name = wp_strip_all_tags( $recent_pages_trash[ $i ]['website']->name );
					?>
					<div class="item">
						<div class="ui grid">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_trash[ $i ]['id']); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($recent_pages_trash[ $i ]['website']->id); ?>"/>
							<div class="six wide column middle aligned">
								<?php echo esc_html($recent_pages_trash[ $i ]['title']); ?>
							</div>
							<div class="four wide column middle aligned">
							<?php echo esc_html($recent_pages_trash[ $i ]['dts']); ?>
						</div>
							<div class="four wide column middle aligned">
							<?php echo $name; ?>
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
						<div class="mainwp-row-actions-working"><i class="notched circle loading icon"></i><?php esc_html_e('Please wait...', 'mainwp' ); ?></div>
					</div>
				<?php } ?>
			</div>
		</div>

		<div class="ui hidden divider"></div>

		<div class="ui two column grid">
			<div class="column">
				<a href="<?php echo admin_url( 'admin.php?page=PageBulkManage' ); ?>" title="" class="ui button green basic"><?php esc_html_e( 'Manage Pages', 'mainwp' ); ?></a>
			</div>
			<div class="column right aligned">
				<a href="<?php echo admin_url( 'admin.php?page=PageBulkAdd' ); ?>" title="" class="ui button green"><?php esc_html_e( 'Create New Page', 'mainwp' ); ?></a>
			</div>
		</div>

		<?php
		if ( $pExit == true ) {
			exit();
		}
	}

}
