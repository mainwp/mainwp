<?php

class MainWP_Recent_Pages {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function test() {

	}

	public static function getName() {
		return '<i class="fa fa-file"></i> ' . __( 'Recent pages', 'mainwp' );
	}

	public static function render() {
		?>
		<div id="recentpages_list"><?php MainWP_Recent_Pages::renderSites( false, false ); ?></div>
		<?php
	}

	public static function renderSites( $renew, $pExit = true ) {
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		} else {
			$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		}

		$websites = MainWP_DB::Instance()->query( $sql );

		$allPages = array();
		if ( $websites ) {
			while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
				if ( $website->recent_pages == '' ) {
					continue;
				}

				$pages = json_decode( $website->recent_pages, 1 );
				if ( count( $pages ) == 0 ) {
					continue;
				}
				foreach ( $pages as $page ) {
					$page['website'] = (object) array( 'id' => $website->id, 'url' => $website->url );
					$allPages[]      = $page;
				}
			}
			@MainWP_DB::free_result( $websites );
		}

		$recent_pages_published = MainWP_Utility::getSubArrayHaving( $allPages, 'status', 'publish' );
		$recent_pages_published = MainWP_Utility::sortmulti( $recent_pages_published, 'dts', 'desc' );
		$recent_pages_draft     = MainWP_Utility::getSubArrayHaving( $allPages, 'status', 'draft' );
		$recent_pages_draft     = MainWP_Utility::sortmulti( $recent_pages_draft, 'dts', 'desc' );
		$recent_pages_pending   = MainWP_Utility::getSubArrayHaving( $allPages, 'status', 'pending' );
		$recent_pages_pending   = MainWP_Utility::sortmulti( $recent_pages_pending, 'dts', 'desc' );
		$recent_pages_trash     = MainWP_Utility::getSubArrayHaving( $allPages, 'status', 'trash' );
		$recent_pages_trash     = MainWP_Utility::sortmulti( $recent_pages_trash, 'dts', 'desc' );
                $recent_pages_future     = MainWP_Utility::getSubArrayHaving( $allPages, 'status', 'future' );
		$recent_pages_future     = MainWP_Utility::sortmulti( $recent_pages_future, 'dts', 'desc' );   
                
		?>
		<div class="mainwp-clear">
		<div class="mainwp-postbox-actions-top">
			<a class="mainwp_action left mainwp_action_down recent_posts_published_lnk" href="#"><?php _e( 'Published', 'mainwp' ); ?> (<?php echo count( $recent_pages_published ); ?>)</a><a class="mainwp_action mid recent_posts_draft_lnk" href="#"><?php _e( 'Draft', 'mainwp' ); ?> (<?php echo count( $recent_pages_draft ); ?>)</a><a class="mainwp_action mid recent_posts_pending_lnk" href="#"><?php _e( 'Pending', 'mainwp' ); ?> (<?php echo count( $recent_pages_pending ); ?>)</a><a class="mainwp_action mid recent_posts_future_lnk" href="#"><?php _e( 'Scheduled', 'mainwp' ); ?> (<?php echo count( $recent_pages_future ); ?>)</a><a class="mainwp_action right recent_posts_trash_lnk" href="#"><?php _e( 'Trash', 'mainwp' ); ?> (<?php echo count( $recent_pages_trash ); ?>)</a>
		</div>
			<div class="recent_posts_published">
				<?php
                                if (count( $recent_pages_published ) == 0)
                                    echo '<div class="inside">' . __('No published pages', 'mainwp') . '</div>';
                                
				for ( $i = 0; $i < count( $recent_pages_published ) && $i < 5; $i ++ ) {
					if ( ! isset( $recent_pages_published[ $i ]['title'] ) || ( $recent_pages_published[ $i ]['title'] == '' ) ) {
						$recent_pages_published[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_published[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_published[ $i ]['dts'], '-' ) ) {
							$recent_pages_published[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_pages_published[ $i ]['dts'] ) );
						}
					}
					?>
					<div class="mainwp-row mainwp-recent">
						<input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_published[ $i ]['id']; ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_published[ $i ]['website']->id; ?>"/>
						<span class="mainwp-left mainwp-cols-2"><a href="<?php echo $recent_pages_published[ $i ]['website']->url; ?>?p=<?php echo $recent_pages_published[ $i ]['id']; ?>" target="_blank"><?php echo htmlentities( $recent_pages_published[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a></span>
						<span class="mainwp-cols-10">
							<a href="<?php echo admin_url( 'admin.php?page=CommentBulkManage&siteid=' . $recent_pages_published[ $i ]['website']->id . '&postid=' . $recent_pages_published[ $i ]['id'] ); ?>" title="<?php echo $recent_pages_published[ $i ]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
								<span class="comment-count"><i class="fa fa-comment" aria-hidden="true"></i> <?php echo $recent_pages_published[ $i ]['comment_count']; ?></span>
							</a>
						</span>
						<span class="mainwp-right mainwp-cols-3">
							<a href="<?php echo $recent_pages_published[ $i ]['website']->url; ?>" target="_blank">
								<i class="fa fa-external-link"></i> <?php echo MainWP_Utility::getNiceURL( $recent_pages_published[ $i ]['website']->url ); ?>
							</a>
							<span class="mainwp-small"><?php echo $recent_pages_published[ $i ]['dts']; ?></span>
						</span>
						<div class="mainwp-clear"></div>
						<div class="mainwp-row-actions">
							<a href="#" class="mainwp-post-unpublish"><?php _e( 'Unpublish', 'mainwp' ); ?></a> |
							<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_pages_published[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_pages_published[ $i ]['id'] . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php _e( 'Edit', 'mainwp' ); ?></a> |
							<a href="#" class="mainwp-post-trash"><?php _e( 'Trash', 'mainwp' ); ?></a>|
							<a href="<?php echo $recent_pages_published[ $i ]['website']->url . ( substr( $recent_pages_published[ $i ]['website']->url, - 1 ) != '/' ? '/' : '' ) . '?p=' . $recent_pages_published[ $i ]['id']; ?>" target="_blank" title="View '<?php echo $recent_pages_published[ $i ]['title']; ?>'" rel="permalink"><?php _e( 'View', 'mainwp' ); ?></a> |
							<a href="admin.php?page=PageBulkManage" class="mainwp-post-viewall"><?php _e( 'View all', 'mainwp' ); ?></a>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
						<div>&nbsp;</div>
						<div class="mainwp-clear"></div>
					</div>
				<?php } ?>
			</div>

			<div class="recent_posts_draft" style="display: none">
				<?php
                                if (count( $recent_pages_draft ) == 0)
                                    echo '<div class="inside">' . __('No draft pages', 'mainwp') . '</div>';
                                
				for ( $i = 0; $i < count( $recent_pages_draft ) && $i < 5; $i ++ ) {
					if ( ! isset( $recent_pages_draft[ $i ]['title'] ) || ( $recent_pages_draft[ $i ]['title'] == '' ) ) {
						$recent_pages_draft[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_draft[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_draft[ $i ]['dts'], '-' ) ) {
							$recent_pages_draft[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_pages_draft[ $i ]['dts'] ) );
						}
					}
					?>
					<div class="mainwp-row mainwp-recent">
						<input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_draft[ $i ]['id']; ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_draft[ $i ]['website']->id; ?>"/>
						<span class="mainwp-left mainwp-cols-2"><a href="<?php echo $recent_pages_draft[ $i ]['website']->url; ?>?p=<?php echo $recent_pages_draft[ $i ]['id']; ?>" target="_blank"><?php echo htmlentities( $recent_pages_draft[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a></span>
						<span class="mainwp-cols-10">
							<a href="<?php echo admin_url( 'admin.php?page=CommentBulkManage&siteid=' . $recent_pages_draft[ $i ]['website']->id . '&postid=' . $recent_pages_draft[ $i ]['id'] ); ?>" title="<?php echo $recent_pages_draft[ $i ]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
								<span class="comment-count"><i class="fa fa-comment" aria-hidden="true"></i> <?php echo $recent_pages_draft[ $i ]['comment_count']; ?></span>
							</a>
						</span>
						<span class="mainwp-right mainwp-cols-3"><?php echo MainWP_Utility::getNiceURL( $recent_pages_draft[ $i ]['website']->url ); ?>
							<div class="mainwp-small"><?php echo $recent_pages_draft[ $i ]['dts']; ?></div>
                                                </span>
						<div class="mainwp-clear"></div>
						<div class="mainwp-row-actions">
							<a href="#" class="mainwp-post-publish"><?php _e( 'Publish', 'mainwp' ); ?></a> |
							<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_pages_draft[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_pages_draft[ $i ]['id'] . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php _e( 'Edit', 'mainwp' ); ?></a> |
							<a href="#" class="mainwp-post-trash"><?php _e( 'Trash', 'mainwp' ); ?></a> |
							<a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e( 'View all', 'mainwp' ); ?></a>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
						<div>&nbsp;</div>
						<div class="mainwp-clear"></div>
					</div>
				<?php } ?>
			</div>

			<div class="recent_posts_pending" style="display: none">
				<?php
                                if (count( $recent_pages_pending ) == 0)
                                    echo '<div class="inside">' . __('No pending pages', 'mainwp') . '</div>';
                                
				for ( $i = 0; $i < count( $recent_pages_pending ) && $i < 5; $i ++ ) {
					if ( ! isset( $recent_pages_pending[ $i ]['title'] ) || ( $recent_pages_pending[ $i ]['title'] == '' ) ) {
						$recent_pages_pending[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_pending[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_pending[ $i ]['dts'], '-' ) ) {
							$recent_pages_pending[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_pages_pending[ $i ]['dts'] ) );
						}
					}
					?>
					<div class="mainwp-row mainwp-recent">
						<input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_pending[ $i ]['id']; ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_pending[ $i ]['website']->id; ?>"/>
						<span class="mainwp-left mainwp-cols-2"><a href="<?php echo $recent_pages_pending[ $i ]['website']->url; ?>?p=<?php echo $recent_pages_pending[ $i ]['id']; ?>" target="_blank"><?php echo htmlentities( $recent_pages_pending[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a></span>
	                    <span class="mainwp-cols-10">
	                            <a href="<?php echo admin_url( 'admin.php?page=CommentBulkManage&siteid=' . $recent_pages_pending[ $i ]['website']->id . '&postid=' . $recent_pages_pending[ $i ]['id'] ); ?>" title="<?php echo $recent_pages_pending[ $i ]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
		                            <span class="comment-count"><i class="fa fa-comment" aria-hidden="true"></i> <?php echo $recent_pages_pending[ $i ]['comment_count']; ?></span>
	                            </a>
	                    </span>
						<span class="mainwp-right mainwp-cols-3"><?php echo MainWP_Utility::getNiceURL( $recent_pages_pending[ $i ]['website']->url ); ?>
							<div class="mainwp-small"><?php echo $recent_pages_pending[ $i ]['dts']; ?></div>
                                                </span>        
						<div class="mainwp-clear"></div>
						<div class="mainwp-row-actions">
							<a href="#" class="mainwp-post-publish"><?php _e( 'Publish', 'mainwp' ); ?></a> |
							<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_pages_pending[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_pages_pending[ $i ]['id'] . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php _e( 'Edit', 'mainwp' ); ?></a> |
							<a href="#" class="mainwp-post-trash"><?php _e( 'Trash', 'mainwp' ); ?></a> |
							<a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e( 'View all', 'mainwp' ); ?></a>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
						<div>&nbsp;</div>
						<div class="mainwp-clear"></div>
					</div>
				<?php } ?>
			</div>
                    
                        <div class="recent_posts_future" style="display: none">
				<?php
                                if (count( $recent_pages_future ) == 0)
                                    echo '<div class="inside">' . __('No scheduled pages', 'mainwp') . '</div>';
                                 
				for ( $i = 0; $i < count( $recent_pages_future ) && $i < 5; $i ++ ) {
					if ( ! isset( $recent_pages_future[ $i ]['title'] ) || ( $recent_pages_future[ $i ]['title'] == '' ) ) {
						$recent_pages_future[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_future[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_future[ $i ]['dts'], '-' ) ) {
							$recent_pages_future[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_pages_future[ $i ]['dts'] ) );
						}
					}
					?>
					<div class="mainwp-row mainwp-recent">
						<input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_future[ $i ]['id']; ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_future[ $i ]['website']->id; ?>"/>
						<span class="mainwp-left mainwp-cols-2"><a href="<?php echo $recent_pages_future[ $i ]['website']->url; ?>?p=<?php echo $recent_pages_future[ $i ]['id']; ?>" target="_blank"><?php echo htmlentities( $recent_pages_future[ $i ]['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8' ); ?></a></span>
                                                <span class="mainwp-cols-10">
                                                        <a href="<?php echo admin_url( 'admin.php?page=CommentBulkManage&siteid=' . $recent_pages_future[ $i ]['website']->id . '&postid=' . $recent_pages_future[ $i ]['id'] ); ?>" title="<?php echo $recent_pages_future[ $i ]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                                                <span class="comment-count"><i class="fa fa-comment" aria-hidden="true"></i> <?php echo $recent_pages_future[ $i ]['comment_count']; ?></span>
                                                        </a>
                                                </span>
						<span class="mainwp-right mainwp-cols-3"><?php echo MainWP_Utility::getNiceURL( $recent_pages_future[ $i ]['website']->url ); ?>
							<div class="mainwp-small"><?php echo $recent_pages_future[ $i ]['dts']; ?></div>
                                                </span>
						<div class="mainwp-clear"></div>                                                
						<div class="mainwp-row-actions">
							<a href="#" class="mainwp-post-publish"><?php _e( 'Publish', 'mainwp' ); ?></a> |
							<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_pages_future[ $i ]['website']->id; ?>&location=<?php echo base64_encode( 'post.php?action=editpost&post=' . $recent_pages_future[ $i ]['id'] . '&action=edit' ); ?>" title="Edit this post" target="_blank"><?php _e( 'Edit', 'mainwp' ); ?></a> |
							<a href="#" class="mainwp-post-trash"><?php _e( 'Trash', 'mainwp' ); ?></a> |
                                                        <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $recent_pages_future[ $i ]['website']->id; ?>&newWindow=yes&openUrl=yes&location=<?php echo base64_encode( '?p=' . $recent_pages_future[ $i ]['id'] . '&preview=true' ); ?>" target="_blank" title="Preview '<?php echo $recent_pages_future[ $i ]['title']; ?>'" rel="permalink"><?php _e( 'Preview', 'mainwp' ); ?></a> |
							<a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e( 'View all', 'mainwp' ); ?></a>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
						<div>&nbsp;</div>
						<div class="mainwp-clear"></div>
					</div>
				<?php } ?>
			</div>
                    
			<div class="recent_posts_trash" style="display: none">
				<?php
                                if (count( $recent_pages_trash ) == 0)
                                    echo '<div class="inside">' . __('No trash pages', 'mainwp') . '</div>';
                                
				for ( $i = 0; $i < count( $recent_pages_trash ) && $i < 5; $i ++ ) {
					if ( ! isset( $recent_pages_trash[ $i ]['title'] ) || ( $recent_pages_trash[ $i ]['title'] == '' ) ) {
						$recent_pages_trash[ $i ]['title'] = '(No Title)';
					}
					if ( isset( $recent_pages_trash[ $i ]['dts'] ) ) {
						if ( ! stristr( $recent_pages_trash[ $i ]['dts'], '-' ) ) {
							$recent_pages_trash[ $i ]['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $recent_pages_trash[ $i ]['dts'] ) );
						}
					}
					?>
					<div class="mainwp-row mainwp-recent">
						<input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_trash[ $i ]['id']; ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_trash[ $i ]['website']->id; ?>"/>
						<span class="mainwp-left mainwp-cols-2"><?php echo $recent_pages_trash[ $i ]['title']; ?></span>
						<span class="mainwp-cols-10">
							<a href="<?php echo admin_url( 'admin.php?page=CommentBulkManage&siteid=' . $recent_pages_trash[ $i ]['website']->id . '&postid=' . $recent_pages_trash[ $i ]['id'] ); ?>" title="<?php echo $recent_pages_trash[ $i ]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
								<span class="comment-count"><i class="fa fa-comment" aria-hidden="true"></i> <?php echo $recent_pages_trash[ $i ]['comment_count']; ?></span>
							</a>
						</span>
						<span class="mainwp-right mainwp-cols-3"><?php echo MainWP_Utility::getNiceURL( $recent_pages_trash[ $i ]['website']->url ); ?>
							<div class="mainwp-small"><?php echo $recent_pages_trash[ $i ]['dts']; ?></div>
                                                </span>
						<div class="mainwp-clear"></div>
						<div class="mainwp-row-actions">
							<a href="#" class="mainwp-post-restore"><?php _e( 'Restore', 'mainwp' ); ?></a> |
							<a href="#" class="mainwp-post-delete delete" style="color: red;"><?php _e( 'Delete permanently', 'mainwp' ); ?></a>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
						<div>&nbsp;</div>
						<div class="mainwp-clear"></div>
					</div>
				<?php } ?>
			</div>
			<div class="mainwp-postbox-actions-bottom">
				<a href="<?php echo admin_url( 'admin.php?page=PageBulkAdd&select=' . ( $current_wpid ? $current_wpid : 'all' ) ); ?>" class="button-primary"><?php _e( 'Create new page', 'mainwp' ); ?></a>
			</div>
		</div>
		<div class="clear"></div>
		<?php
		if ( $pExit == true ) {
			exit();
		}
	}
}
