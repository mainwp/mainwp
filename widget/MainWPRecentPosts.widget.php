<?php

class MainWPRecentPosts
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
        return __("Recent Posts",'mainwp');
    }

    public static function render()
    {
        ?>
    <div id="recentposts_list"><?php MainWPRecentPosts::renderSites(false, false); ?></div>
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

        $allPosts = array();
        if ($websites)
        {
            while ($websites && ($website = @MainWPDB::fetch_object($websites)))
            {
                if ($website->recent_posts == '') continue;

                $posts = json_decode($website->recent_posts, 1);
                if (count($posts) == 0) continue;
                foreach ($posts as $post)
                {
                    $post['website'] = (object) array('id' => $website->id, 'url' => $website->url);
                    $allPosts[] = $post;
                }
            }
            @MainWPDB::free_result($websites);
        }

            $recent_posts_published = MainWPUtility::getSubArrayHaving($allPosts, 'status', 'publish');
            $recent_posts_published = MainWPUtility::sortmulti($recent_posts_published, 'dts', 'desc');
            $recent_posts_draft = MainWPUtility::getSubArrayHaving($allPosts, 'status', 'draft');
            $recent_posts_draft = MainWPUtility::sortmulti($recent_posts_draft, 'dts', 'desc');
            $recent_posts_pending = MainWPUtility::getSubArrayHaving($allPosts, 'status', 'pending');
            $recent_posts_pending = MainWPUtility::sortmulti($recent_posts_pending, 'dts', 'desc');
            $recent_posts_trash = MainWPUtility::getSubArrayHaving($allPosts, 'status', 'trash');
            $recent_posts_trash = MainWPUtility::sortmulti($recent_posts_trash, 'dts', 'desc');

            ?>
        <div class="clear">
            <a href="<?php echo admin_url('admin.php?page=PostBulkAdd&select=' . ($current_wpid ? $current_wpid : 'all')); ?>" class="button-primary" style="float: right"><?php _e('Add New','mainwp'); ?></a>
            <a class="mainwp_action left mainwp_action_down recent_posts_published_lnk" href="#"><?php _e('Published','mainwp'); ?> (<?php echo count($recent_posts_published); ?>)</a><a class="mainwp_action mid recent_posts_draft_lnk" href="#" ><?php _e('Draft','mainwp'); ?> (<?php echo count($recent_posts_draft); ?>)</a><a class="mainwp_action mid recent_posts_pending_lnk" href="#"><?php _e('Pending','mainwp'); ?> (<?php echo count($recent_posts_pending); ?>)</a><a class="mainwp_action right recent_posts_trash_lnk" href="#"><?php _e('Trash','mainwp'); ?> (<?php echo count($recent_posts_trash); ?>)</a><br/><br/>
            <div class="recent_posts_published">
                <?php
                for ($i = 0; $i < count($recent_posts_published) && $i < 5; $i++)
                {
                    if (!isset($recent_posts_published[$i]['title']) || ($recent_posts_published[$i]['title'] == '')) $recent_posts_published[$i]['title'] = '(No Title)';
                    if (isset($recent_posts_published[$i]['dts']))
                    {
                        if (!stristr($recent_posts_published[$i]['dts'], '-'))
                        {
                            $recent_posts_published[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_posts_published[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_posts_published[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_posts_published[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col"><a href="<?php echo $recent_posts_published[$i]['website']->url; ?>?p=<?php echo $recent_posts_published[$i]['id']; ?>" target="_blank"><?php echo $recent_posts_published[$i]['title']; ?></a></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_posts_published[$i]['website']->id.'&postid='.$recent_posts_published[$i]['id']); ?>" title="<?php echo $recent_posts_published[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_posts_published[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_posts_published[$i]['website']->url); ?> <br/><?php echo $recent_posts_published[$i]['dts']; ?></span>

                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions"><a href="#" class="mainwp-post-unpublish"><?php _e('Unpublish','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $recent_posts_published[$i]['website']->id; ?>&location=<?php echo base64_encode('post.php?action=editpost&post=' . $recent_posts_published[$i]['id'] . '&action=edit'); ?>" title="Edit this post"><?php _e('Edit','mainwp'); ?></a> | <a href="#" class="mainwp-post-trash"><?php _e('Trash','mainwp'); ?></a>| <a href="<?php echo $recent_posts_published[$i]['website']->url . (substr($recent_posts_published[$i]['website']->url, -1) != '/' ? '/' : '') . '?p=' . $recent_posts_published[$i]['id']; ?>" target="_blank" title="View â€œ<?php echo $recent_posts_published[$i]['title']; ?>ï¿½?" rel="permalink"><?php _e('View','mainwp'); ?></a> | <a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e('View All','mainwp'); ?></a></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    <div>&nbsp;</div>
                    </div>
                </div>
                <?php } ?>
            </div>

            <div class="recent_posts_draft" style="display: none">
                <?php
                for ($i = 0; $i < count($recent_posts_draft) && $i < 5; $i++)
                {
                    if (!isset($recent_posts_draft[$i]['title']) || ($recent_posts_draft[$i]['title'] == '')) $recent_posts_draft[$i]['title'] = '(No Title)';
                    if (isset($recent_posts_draft[$i]['dts']))
                    {
                        if (!stristr($recent_posts_draft[$i]['dts'], '-'))
                        {
                            $recent_posts_draft[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_posts_draft[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_posts_draft[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_posts_draft[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col"><a href="<?php echo $recent_posts_draft[$i]['website']->url; ?>?p=<?php echo $recent_posts_draft[$i]['id']; ?>" target="_blank"><?php echo $recent_posts_draft[$i]['title']; ?></a></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_posts_draft[$i]['website']->id.'&postid='.$recent_posts_draft[$i]['id']); ?>" title="<?php echo $recent_posts_draft[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_posts_draft[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_posts_draft[$i]['website']->url); ?> <br/><?php echo $recent_posts_draft[$i]['dts']; ?></span>

                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions"><a href="#" class="mainwp-post-publish"><?php _e('Publish','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $recent_posts_draft[$i]['website']->id; ?>&location=<?php echo base64_encode('post.php?action=editpost&post=' . $recent_posts_draft[$i]['id'] . '&action=edit'); ?>" title="Edit this post"><?php _e('Edit','mainwp'); ?></a> | <a href="#" class="mainwp-post-trash"><?php _e('Trash','mainwp'); ?></a> | <a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e('View All','mainwp'); ?></a></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    </div>
                    <div>&nbsp;</div>
                </div>
                <?php } ?>
            </div>

            <div class="recent_posts_pending" style="display: none">
                <?php
                for ($i = 0; $i < count($recent_posts_pending) && $i < 5; $i++)
                {
                    if (!isset($recent_posts_pending[$i]['title']) || ($recent_posts_pending[$i]['title'] == '')) $recent_posts_pending[$i]['title'] = '(No Title)';
                    if (isset($recent_posts_pending[$i]['dts']))
                    {
                        if (!stristr($recent_posts_pending[$i]['dts'], '-'))
                        {
                            $recent_posts_pending[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_posts_pending[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_posts_pending[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_posts_pending[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col"><a href="<?php echo $recent_posts_pending[$i]['website']->url; ?>?p=<?php echo $recent_posts_pending[$i]['id']; ?>" target="_blank"><?php echo $recent_posts_pending[$i]['title']; ?></a></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_posts_pending[$i]['website']->id.'&postid='.$recent_posts_pending[$i]['id']); ?>" title="<?php echo $recent_posts_pending[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_posts_pending[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_posts_pending[$i]['website']->url); ?> <br/><?php echo $recent_posts_pending[$i]['dts']; ?></span>

                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions"><a href="#" class="mainwp-post-publish"><?php _e('Publish','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $recent_posts_pending[$i]['website']->id; ?>&location=<?php echo base64_encode('post.php?action=editpost&post=' . $recent_posts_pending[$i]['id'] . '&action=edit'); ?>" title="Edit this post"><?php _e('Edit','mainwp'); ?></a> | <a href="#" class="mainwp-post-trash"><?php _e('Trash','mainwp'); ?></a> | <a href="admin.php?page=PostBulkManage" class="mainwp-post-viewall"><?php _e('View All','mainwp'); ?></a></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    </div>
                    <div>&nbsp;</div>
                </div>
                <?php } ?>
            </div>
            <div class="recent_posts_trash" style="display: none">
                <?php
                for ($i = 0; $i < count($recent_posts_trash) && $i < 5; $i++)
                {
                    if (!isset($recent_posts_trash[$i]['title']) || ($recent_posts_trash[$i]['title'] == '')) $recent_posts_trash[$i]['title'] = '(No Title)';
                    if (isset($recent_posts_trash[$i]['dts']))
                    {
                        if (!stristr($recent_posts_trash[$i]['dts'], '-'))
                        {
                            $recent_posts_trash[$i]['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($recent_posts_trash[$i]['dts']));
                        }
                    }
                ?>
                <div class="mainwp-row mainwp-recent">
                    <input class="postId" type="hidden" name="id" value="<?php echo $recent_posts_trash[$i]['id']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $recent_posts_trash[$i]['website']->id; ?>"/>
                    <span class="mainwp-left-col"><?php echo $recent_posts_trash[$i]['title']; ?></span>
                    <span class="mainwp-mid-col">
                            <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$recent_posts_trash[$i]['website']->id.'&postid='.$recent_posts_trash[$i]['id']); ?>" title="<?php echo $recent_posts_trash[$i]['comment_count']; ?>" class="post-com-count" style="display: inline-block !important;">
                                <span class="comment-count"><?php echo $recent_posts_trash[$i]['comment_count']; ?></span>
                            </a>
                    </span>
                    <span class="mainwp-right-col"><?php echo MainWPUtility::getNiceURL($recent_posts_trash[$i]['website']->url); ?> <br/><?php echo $recent_posts_trash[$i]['dts']; ?></span>

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

    public static function publish()
    {
        MainWPRecentPosts::action('publish');
        die(json_encode(array('result' => __('Post has been published','mainwp'))));
    }

    public static function approve()
    {
        MainWPRecentPosts::action('publish');
        die(json_encode(array('result' => __('Post has been approved','mainwp'))));
    }

    public static function unpublish()
    {
        MainWPRecentPosts::action('unpublish');
        die(json_encode(array('result' => __('Post has been unpublished','mainwp'))));
    }

    public static function trash()
    {
        MainWPRecentPosts::action('trash');
        die(json_encode(array('result' => __('Post has been moved to trash','mainwp'))));
    }

    public static function delete()
    {
        MainWPRecentPosts::action('delete');
        die(json_encode(array('result' => __('Post has been permanently deleted','mainwp'))));
    }

    public static function restore()
    {
        MainWPRecentPosts::action('restore');
        die(json_encode(array('result' => __('Post has been restored','mainwp'))));
    }

    public static function action($pAction)
    {
        $postId = $_POST['postId'];
        $websiteIdEnc = $_POST['websiteId'];

        if (!MainWPUtility::ctype_digit($postId)) die(json_encode(array('error' => 'Invalid Request.')));
        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) die(json_encode(array('error' => 'Invalid Request.')));

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die(json_encode(array('error' => 'You can not edit this website.')));

        try
        {
            $information = MainWPUtility::fetchUrlAuthed($website, 'post_action', array(
                'action' => $pAction,
                'id' => $postId));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => $e->getMessage())));
        }

        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) die(json_encode(array('error' => 'Unexpected error.')));
    }

    public static function action_update($pAction)
     {

        $postId = $_POST['postId'];
        $websiteIdEnc = $_POST['websiteId'];
        $post_data = $_POST['post_data'];

        if (!MainWPUtility::ctype_digit($postId)) die('FAIL');
        $websiteId = $websiteIdEnc;

        if (!MainWPUtility::ctype_digit($websiteId)) die('FAIL');

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die('FAIL');

        try
        {
            $information = MainWPUtility::fetchUrlAuthed($website, 'post_action', array(
                'action' => $pAction,
                'id' => $postId,
                'post_data' => $post_data ));
        }

        catch (MainWPException $e)
        {
            die('FAIL');
        }
        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) die('FAIL');
    }
}
