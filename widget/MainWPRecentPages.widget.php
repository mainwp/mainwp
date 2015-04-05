<?php

class MainWPRecentPages
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function test()
    {

    }

    public static function getName()
    {
        return __('<i class="fa fa-file"></i> Recent Pages','mainwp');
    }

    public static function render()
    {
        ?>
    <div id="recentpages_list"><?php MainWPRecentPages::renderSites(false, false); ?></div>
    <?php
    }

    public static function renderSites($renew, $pExit = true)
    {
        $current_wpid = MainWPUtility::get_current_wpid();

        if ($current_wpid)
        {
            $sql = MainWPDB::Instance()->getSQLWebsiteById($current_wpid);
        }
        else
        {
            $sql = MainWPDB::Instance()->getSQLWebsitesForCurrentUser();
        }

        $websites = MainWPDB::Instance()->query($sql);

        $allPages = array();
        if ($websites)
        {
            while ($websites && ($website = @MainWPDB::fetch_object($websites)))
            {
                if ($website->recent_pages == '') continue;

                $pages = json_decode($website->recent_pages, 1);
                if (count($pages) == 0) continue;
                foreach ($pages as $page)
                {
                    $page['website'] = (object) array('id' => $website->id, 'url' => $website->url);
                    $allPages[] = $page;
                }
            }
            @MainWPDB::free_result($websites);
        }

            $recent_pages_published = MainWPUtility::getSubArrayHaving($allPages, 'status', 'publish');
            $recent_pages_published = MainWPUtility::sortmulti($recent_pages_published, 'dts', 'desc');
            $recent_pages_draft = MainWPUtility::getSubArrayHaving($allPages, 'status', 'draft');
            $recent_pages_draft = MainWPUtility::sortmulti($recent_pages_draft, 'dts', 'desc');
            $recent_pages_pending = MainWPUtility::getSubArrayHaving($allPages, 'status', 'pending');
            $recent_pages_pending = MainWPUtility::sortmulti($recent_pages_pending, 'dts', 'desc');
            $recent_pages_trash = MainWPUtility::getSubArrayHaving($allPages, 'status', 'trash');
            $recent_pages_trash = MainWPUtility::sortmulti($recent_pages_trash, 'dts', 'desc');

            ?>
        <div class="clear">
            <a href="<?php echo admin_url('admin.php?page=PageBulkAdd&select=' . ($current_wpid ? $current_wpid : 'all')); ?>" class="button-primary" style="float: right"><?php _e('Add New','mainwp'); ?></a>
            <a class="mainwp_action left mainwp_action_down recent_posts_published_lnk" href="#"><?php _e('Published','mainwp'); ?> (<?php echo count($recent_pages_published); ?>)</a><a class="mainwp_action mid recent_posts_draft_lnk" href="#"><?php _e('Draft','mainwp'); ?> (<?php echo count($recent_pages_draft); ?>)</a><a class="mainwp_action mid recent_posts_pending_lnk" href="#"><?php _e('Pending','mainwp'); ?> (<?php echo count($recent_pages_pending); ?>)</a><a class="mainwp_action right recent_posts_trash_lnk" href="#"><?php _e('Trash','mainwp'); ?> (<?php echo count($recent_pages_trash); ?>)</a><br/><br/>
            <div class="recent_posts_published">
                <?php
                for ($i = 0; $i < count($recent_pages_published) && $i < 5; $i++)
                {
                    if (!isset($recent_pages_published[$i]['title']) || ($recent_pages_published[$i]['title'] == '')) $recent_pages_published[$i]['title'] = '(No Title)';
                    if (isset($recent_pages_published[$i]['dts']))
                    {
                        if (!stristr($recent_pages_published[$i]['dts'], '-'))
                        {
                            $recent_pages_published[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_pages_published[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_published[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_published[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col" style="width: 60% !important; margin-right: 1em;"><a href="<?php echo $recent_pages_published[$i]['website']->url; ?>?p=<?php echo $recent_pages_published[$i]['id']; ?>" target="_blank"><?php echo htmlentities($recent_pages_published[$i]['title'],ENT_COMPAT | ENT_HTML401, "UTF-8"); ?></a></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_pages_published[$i]['website']->id.'&postid='.$recent_pages_published[$i]['id']); ?>" title="<?php echo $recent_pages_published[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_pages_published[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_pages_published[$i]['website']->url); ?> <br/><?php echo $recent_pages_published[$i]['dts']; ?></span>

                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions"><a href="#" class="mainwp-post-unpublish"><?php _e('Unpublish','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $recent_pages_published[$i]['website']->id; ?>&location=<?php echo base64_encode('post.php?action=editpost&post=' . $recent_pages_published[$i]['id'] . '&action=edit'); ?>" title="Edit this post"><?php _e('Edit','mainwp'); ?></a> | <a href="#" class="mainwp-post-trash"><?php _e('Trash','mainwp'); ?></a>| <a href="<?php echo $recent_pages_published[$i]['website']->url . (substr($recent_pages_published[$i]['website']->url, -1) != '/' ? '/' : '') . '?p=' . $recent_pages_published[$i]['id']; ?>" target="_blank" title="View '<?php echo $recent_pages_published[$i]['title']; ?>'" rel="permalink"><?php _e('View','mainwp'); ?></a> | <a href="admin.php?page=PageBulkManage" class="mainwp-post-viewall"><?php _e('View All','mainwp'); ?></a></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    <div>&nbsp;</div>
                    </div>
                </div>
                <?php } ?>
            </div>

            <div class="recent_posts_draft" style="display: none">
                <?php
                for ($i = 0; $i < count($recent_pages_draft) && $i < 5; $i++)
                {
                    if (!isset($recent_pages_draft[$i]['title']) || ($recent_pages_draft[$i]['title'] == '')) $recent_pages_draft[$i]['title'] = '(No Title)';
                    if (isset($recent_pages_draft[$i]['dts']))
                    {
                        if (!stristr($recent_pages_draft[$i]['dts'], '-'))
                        {
                            $recent_pages_draft[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_pages_draft[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_draft[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_draft[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col" style="width: 60% !important;  margin-right: 1em;"><a href="<?php echo $recent_pages_draft[$i]['website']->url; ?>?p=<?php echo $recent_pages_draft[$i]['id']; ?>" target="_blank"><?php echo htmlentities($recent_pages_draft[$i]['title'],ENT_COMPAT | ENT_HTML401, "UTF-8"); ?></a></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_pages_draft[$i]['website']->id.'&postid='.$recent_pages_draft[$i]['id']); ?>" title="<?php echo $recent_pages_draft[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_pages_draft[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_pages_draft[$i]['website']->url); ?> <br/><?php echo $recent_pages_draft[$i]['dts']; ?></span>

                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions"><a href="#" class="mainwp-post-publish"><?php _e('Publish','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $recent_pages_draft[$i]['website']->id; ?>&location=<?php echo base64_encode('post.php?action=editpost&post=' . $recent_pages_draft[$i]['id'] . '&action=edit'); ?>" title="Edit this post"><?php _e('Edit','mainwp'); ?></a> | <a href="#" class="mainwp-post-trash"><?php _e('Trash','mainwp'); ?></a> | <a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e('View All','mainwp'); ?></a></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    </div>
                    <div>&nbsp;</div>
                </div>
                <?php } ?>
            </div>

            <div class="recent_posts_pending" style="display: none">
                <?php
                for ($i = 0; $i < count($recent_pages_pending) && $i < 5; $i++)
                {
                    if (!isset($recent_pages_pending[$i]['title']) || ($recent_pages_pending[$i]['title'] == '')) $recent_pages_pending[$i]['title'] = '(No Title)';
                    if (isset($recent_pages_pending[$i]['dts']))
                    {
                        if (!stristr($recent_pages_pending[$i]['dts'], '-'))
                        {
                            $recent_pages_pending[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_pages_pending[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_pending[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_pending[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col" style="width: 60% !important;  margin-right: 1em;"><a href="<?php echo $recent_pages_pending[$i]['website']->url; ?>?p=<?php echo $recent_pages_pending[$i]['id']; ?>" target="_blank"><?php echo htmlentities($recent_pages_pending[$i]['title'],ENT_COMPAT | ENT_HTML401, "UTF-8"); ?></a></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_pages_pending[$i]['website']->id.'&postid='.$recent_pages_pending[$i]['id']); ?>" title="<?php echo $recent_pages_pending[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_pages_pending[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_pages_pending[$i]['website']->url); ?> <br/><?php echo $recent_pages_pending[$i]['dts']; ?></span>

                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions"><a href="#" class="mainwp-post-publish"><?php _e('Publish','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $recent_pages_pending[$i]['website']->id; ?>&location=<?php echo base64_encode('post.php?action=editpost&post=' . $recent_pages_pending[$i]['id'] . '&action=edit'); ?>" title="Edit this post"><?php _e('Edit','mainwp'); ?></a> | <a href="#" class="mainwp-post-trash"><?php _e('Trash','mainwp'); ?></a> | <a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e('View All','mainwp'); ?></a></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    </div>
                    <div>&nbsp;</div>
                </div>
                <?php } ?>
            </div>
            <div class="recent_posts_trash" style="display: none">
                <?php
                for ($i = 0; $i < count($recent_pages_trash) && $i < 5; $i++)
                {
                    if (!isset($recent_pages_trash[$i]['title']) || ($recent_pages_trash[$i]['title'] == '')) $recent_pages_trash[$i]['title'] = '(No Title)';
                    if (isset($recent_pages_trash[$i]['dts']))
                    {
                        if (!stristr($recent_pages_trash[$i]['dts'], '-'))
                        {
                            $recent_pages_trash[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_pages_trash[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_pages_trash[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_pages_trash[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col" style="width: 60% !important;  margin-right: 1em;"><?php echo $recent_pages_trash[$i]['title']; ?></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_pages_trash[$i]['website']->id.'&postid='.$recent_pages_trash[$i]['id']); ?>" title="<?php echo $recent_pages_trash[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_pages_trash[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_pages_trash[$i]['website']->url); ?> <br/><?php echo $recent_pages_trash[$i]['dts']; ?></span>

                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions"><a href="#" class="mainwp-post-restore"><?php _e('Restore','mainwp'); ?></a> | <a href="#" class="mainwp-post-delete delete" style="color: red;"><?php _e('Delete Permanently','mainwp'); ?></a></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    </div>
                    <div>&nbsp;</div>
                </div>
                <?php } ?>
            </div>
        </div>
    <div class="clear"></div>
    <?php
        if ($pExit == true) exit();
    }
}
