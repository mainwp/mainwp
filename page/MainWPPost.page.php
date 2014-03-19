<?php
/**
 * @see MainWPBulkAdd
 */
class MainWPPost
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;

    public static function init()
    {
        add_action('mainwp-pageheader-post', array(MainWPPost::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-post', array(MainWPPost::getClassName(), 'renderFooter'));
    }

    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Posts','mainwp'), '<span id="mainwp-Posts">'.__('Posts','mainwp').'</span>', 'read', 'PostBulkManage', array(MainWPPost::getClassName(), 'render'));
        add_submenu_page('mainwp_tab', 'Posts', '<div class="mainwp-hidden">Add New</div>', 'read', 'PostBulkAdd', array(MainWPPost::getClassName(), 'renderBulkAdd'));
        add_submenu_page('mainwp_tab', 'Posting new bulkpost', '<div class="mainwp-hidden">Posts</div>', 'read', 'PostingBulkPost', array(MainWPPost::getClassName(), 'posting')); //removed from menu afterwards
        add_submenu_page('mainwp_tab', __('Posts Help','mainwp'), '<div class="mainwp-hidden">'.__('Posts Help','mainwp').'</div>', 'read', 'PostsHelp', array(MainWPPost::getClassName(), 'QSGManagePosts'));

        self::$subPages = apply_filters('mainwp-getsubpages-post', array());
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Post' . $subPage['slug'], $subPage['callback']);
            }
        }
    }

    public static function initMenuSubPages()
    {
        ?>
        <div id="menu-mainwp-Posts" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo admin_url('admin.php?page=PostBulkManage'); ?>" class="mainwp-submenu"><?php _e('All Posts','mainwp'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=PostBulkAdd'); ?>" class="mainwp-submenu"><?php _e('Add New','mainwp'); ?></a>
                    <?php
                    if (isset(self::$subPages) && is_array(self::$subPages))
                    {
                        foreach (self::$subPages as $subPage)
                        {
                            if (!isset($subPage['menu_hidden']) || (isset($subPage['menu_hidden']) && $subPage['menu_hidden'] != true))
                            {
                    ?>
                            <a href="<?php echo admin_url('admin.php?page=Post'.$subPage['slug']); ?>" class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
                    <?php
							}
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function renderHeader($shownPage)
    {
        ?>
    <div class="wrap">
        <a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP" /></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-post.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Post" height="32"/>
        <h2><?php _e('Posts','mainwp'); ?></h2><div style="clear: both;"></div><br/>
        <div class="mainwp-tabs" id="mainwp-tabs">
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'BulkManage') { echo "nav-tab-active"; } ?>" href="admin.php?page=PostBulkManage"><?php _e('Manage','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'BulkAdd') { echo "nav-tab-active"; } ?>" href="admin.php?page=PostBulkAdd"><?php _e('Add New','mainwp'); ?></a>
                <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'PostsHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=PostsHelp"><?php _e('Help','mainwp'); ?></a>
        <?php
                if (isset(self::$subPages) && is_array(self::$subPages))
                {
                    foreach (self::$subPages as $subPage)
                    {
                        if (isset($subPage['tab_link_hidden']) && $subPage['tab_link_hidden'] == true)
                            $tab_link = "#";
                        else
                            $tab_link = "admin.php?page=Post". $subPage['slug'];
                        ?>
                        <a class="nav-tab pos-nav-tab <?php if ($shownPage === $subPage['slug']) { echo "nav-tab-active"; } ?>" href="<?php echo $tab_link; ?>"><?php echo $subPage['title']; ?></a>
                    <?php
                    }
                }
                ?>
        </div>
        <div id="mainwp_wrap-inside">
        <?php
    }

    public static function renderFooter($shownPage)
    {
        ?>
        </div>
    </div>
        <?php
    }

    public static function render()
    {
        $cachedSearch = MainWPCache::getCachedContext('Post');

        //Loads the post screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
        self::renderHeader('BulkManage'); ?>
        <div class="mainwp_info-box"><strong><?php _e('Use this to bulk change posts. To add new posts click on the "Add New" tab.','mainwp'); ?></strong></div>
        <br/>
        <div class="mainwp-search-form">
            <?php MainWPUI::select_sites_box(__("Select Sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_right'); ?>

            <h3><?php _e('Search Posts','mainwp'); ?></h3>
            <ul class="mainwp_checkboxes">
                <li>
                    <input type="checkbox" id="mainwp_post_search_type_publish" <?php echo ($cachedSearch == null || ($cachedSearch != null && in_array('publish', $cachedSearch['status']))) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_post_search_type_publish" class="mainwp-label2">Published</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_post_search_type_pending" <?php echo ($cachedSearch != null && in_array('pending', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2" />
                    <label for="mainwp_post_search_type_pending" class="mainwp-label2">Pending</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_post_search_type_private" <?php echo ($cachedSearch != null && in_array('private', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2" />
                    <label for="mainwp_post_search_type_private" class="mainwp-label2">Private</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_post_search_type_future" <?php echo ($cachedSearch != null && in_array('future', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2" />
                    <label for="mainwp_post_search_type_future" class="mainwp-label2">Future</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_post_search_type_draft" <?php echo ($cachedSearch != null && in_array('draft', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2" />
                    <label for="mainwp_post_search_type_draft" class="mainwp-label2">Draft</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_post_search_type_trash" <?php echo ($cachedSearch != null && in_array('trash', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2" />
                    <label for="mainwp_post_search_type_trash" class="mainwp-label2">Trash</label>
                </li>
            </ul>
            <p>
                <?php _e('Containing Keyword:','mainwp'); ?><br />
                <input type="text" id="mainwp_post_search_by_keyword" size="50" value="<?php if ($cachedSearch != null) { echo $cachedSearch['keyword']; } ?>"/>
            </p>
            <p>
                <?php _e('Date Range:','mainwp'); ?><br />
                <input type="text" id="mainwp_post_search_by_dtsstart" class="mainwp_datepicker" size="12" value="<?php if ($cachedSearch != null) { echo $cachedSearch['dtsstart']; } ?>"/> <?php _e('to','mainwp'); ?> <input type="text" id="mainwp_post_search_by_dtsstop" class="mainwp_datepicker" size="12" value="<?php if ($cachedSearch != null) { echo $cachedSearch['dtsstop']; } ?>"/>
            </p>
            <p>&nbsp;</p>

            <input type="button" name="mainwp_show_posts" id="mainwp_show_posts" class="button-primary" value="<?php _e('Show Posts','mainwp'); ?>"/>
            <?php
            if (isset($_REQUEST['siteid']) && isset($_REQUEST['postid']))
            {
                echo '<script>jQuery(document).ready(function() { mainwp_show_post('.$_REQUEST['siteid'].', '.$_REQUEST['postid'].', undefined)});</script>';
            }
            else if (isset($_REQUEST['siteid']) && isset($_REQUEST['userid']))
            {
                echo '<script>jQuery(document).ready(function() { mainwp_show_post('.$_REQUEST['siteid'].', undefined, '.$_REQUEST['userid'].')});</script>';
            }
            ?>
            <span id="mainwp_posts_loading">&nbsp;<em><?php _e('Grabbing information from Child Sites','mainwp') ?></em>&nbsp;&nbsp;<img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
        </div>
        <div class="clear"></div>
        <div id="mainwp_posts_error"></div>
        <div id="mainwp_posts_main" <?php if ($cachedSearch != null) { echo 'style="display: block;"'; } ?>>
            <div class="alignleft">
                <select name="bulk_action" id="mainwp_bulk_action">
                    <option value="none"><?php _e('Bulk Action','mainwp'); ?></option>
                    <option value="publish"><?php _e('Publish','mainwp'); ?></option>
                    <option value="unpublish"><?php _e('Unpublish','mainwp'); ?></option>
                    <option value="trash"><?php _e('Move to Trash','mainwp'); ?></option>
                    <option value="restore"><?php _e('Restore','mainwp'); ?></option>
                    <option value="delete"><?php _e('Delete Permanently','mainwp'); ?></option>
                </select> <input type="button" name="" id="mainwp_bulk_post_action_apply" class="button" value="<?php _e('Apply','mainwp'); ?>"/>
            </div>
            <div class="alignright" id="mainwp_posts_total_results">
                <?php _e('Total Results:','mainwp'); ?> <span id="mainwp_posts_total"><?php echo $cachedSearch != null ? $cachedSearch['count'] : '0'; ?></span>
            </div>
            <div class="clear"></div>
            <div id="mainwp_posts_content">
                <table class="wp-list-table widefat fixed posts tablesorter" id="mainwp_posts_table"
                       cellspacing="0">
                    <thead>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                type="checkbox"></th>
                        <th scope="col" id="title" class="manage-column column-title sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Title','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="author" class="manage-column column-author sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Author','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="categories" class="manage-column column-categories sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Categories','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="tags" class="manage-column column-tags sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Tags','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="comments" class="manage-column column-comments num sortable desc" style="">
                            <a href="#" onclick="return false;">
                                <span><span class="vers"><img alt="Comments"
                                                              src="<?php echo admin_url('images/comment-grey-bubble.png'); ?>"></span></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th scope="col" id="date" class="manage-column column-date sortable asc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Date','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="status" class="manage-column column-status sortable asc" style="width: 120px;">
                            <a href="#" onclick="return false;"><span><?php _e('Status','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="categories" class="manage-column column-categories sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Website','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                    </tr>
                    </thead>

                    <tfoot>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                type="checkbox"></th>
                        <th scope="col" id="title" class="manage-column column-title sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Title','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="author" class="manage-column column-author sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Author','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="categories" class="manage-column column-categories sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Categories','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="tags" class="manage-column column-tags sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Tags','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="comments" class="manage-column column-comments num sortable desc" style="">
                            <a href="#" onclick="return false;">
                                <span><span class="vers"><img alt="Comments"
                                                              src="<?php echo admin_url('images/comment-grey-bubble.png'); ?>"></span></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th scope="col" id="date" class="manage-column column-date sortable asc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Date','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="status" class="manage-column column-status sortable asc" style="width: 120px;">
                            <a href="#" onclick="return false;"><span><?php _e('Status','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                        <th scope="col" id="categories" class="manage-column column-categories sortable desc" style="">
                            <a href="#" onclick="return false;"><span><?php _e('Website','mainwp'); ?></span><span class="sorting-indicator"></span></a>
                        </th>
                    </tr>
                    </tfoot>

                    <tbody id="the-posts-list" class="list:posts">
                    <?php MainWPCache::echoBody('Post'); ?>
                    </tbody>
                </table>
                <div class="pager" id="pager">
                    <form>
                        <img src="<?php echo plugins_url('images/first.png', dirname(__FILE__)); ?>" class="first">
                        <img src="<?php echo plugins_url('images/prev.png', dirname(__FILE__)); ?>" class="prev">
                        <input type="text" class="pagedisplay" />
                        <img src="<?php echo plugins_url('images/next.png', dirname(__FILE__)); ?>" class="next">
                        <img src="<?php echo plugins_url('images/last.png', dirname(__FILE__)); ?>" class="last">
                        <span>&nbsp;&nbsp;<?php _e('Show:','mainwp'); ?> </span><select class="pagesize">
                            <option selected="selected" value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="1000000000">All</option>
                        </select><span> <?php _e('Posts per page','mainwp'); ?></span>
                    </form>
                </div>
                <div class="clear"></div>
            </div>
        </div>
    <?php
        if ($cachedSearch != null) { echo '<script>mainwp_posts_table_reinit();</script>'; }
        self::renderFooter('BulkManage');
    }

    public static function renderTable($keyword, $dtsstart, $dtsstop, $status, $groups, $sites, $postId, $userId)
    {
        MainWPCache::initCache('Post');

        //Fetch all!
        //Build websites array
        $dbwebsites = array();
        if ($sites != '') {
            foreach ($sites as $k => $v) {
                if (MainWPUtility::ctype_digit($v)) {
                    $website = MainWPDB::Instance()->getWebsiteById($v);
                    $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                }
            }
        }
        if ($groups != '') {
            foreach ($groups as $k => $v) {
                if (MainWPUtility::ctype_digit($v)) {
                    $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($v));
                    while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                    {
                        $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                    }
                    @MainWPDB::free_result($websites);
                }
            }
        }

        $output = new stdClass();
        $output->errors = array();
        $output->posts = 0;

        if (count($dbwebsites) > 0) {
            $post_data = array(
                'keyword' => $keyword,
                'dtsstart' => $dtsstart,
                'dtsstop' => $dtsstop,
                'status' => $status,
                'maxRecords' => ((get_option('mainwp_maximumPosts') === false) ? 50 : get_option('mainwp_maximumPosts'))
            );
            if (isset($postId) && ($postId != ''))
            {
                $post_data['postId'] = $postId;
            }
            else if (isset($userId) && ($userId != ''))
            {
                $post_data['userId'] = $userId;
            }
            MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_all_posts', $post_data, array(MainWPPost::getClassName(), 'PostsSearch_handler'), $output);
        }

        MainWPCache::addContext('Post', array('count' => $output->posts, 'keyword' => $keyword, 'dtsstart' => $dtsstart, 'dtsstop' => $dtsstop, 'status' => $status));

        //Sort if required
        if ($output->posts == 0) {
            ob_start();
            ?>
        <tr>
            <td colspan="9">No posts found</td>
        </tr>
        <?php
            $newOutput = ob_get_clean();
            echo $newOutput;
            MainWPCache::addBody('Post', $newOutput);
            return;
        }

    }

    private static function getStatus($status)
    {
        if ($status == 'publish') {
            return 'Published';
        }
        return ucfirst($status);
    }

    public static function PostsSearch_handler($data, $website, &$output)
    {
        if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $posts = unserialize(base64_decode($results[1]));
            unset($results);
            foreach ($posts as $post)
            {
                if (isset($post['dts']))
                {
                    if (!stristr($post['dts'], '-'))
                    {
                        $post['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($post['dts']));
                    }
                }

                if (!isset($post['title']) || ($post['title'] == ''))
                {
                    $post['title'] = '(No Title)';
                }

                ob_start();
                ?>
            <tr id="post-1"
                class="post-1 post type-post status-publish format-standard hentry category-uncategorized alternate iedit author-self"
                valign="top">
                <th scope="row" class="check-column"><input type="checkbox" name="post[]" value="1"></th>
                <td class="post-title page-title column-title">
                    <input class="postId" type="hidden" name="id" value="<?php echo $post['id']; ?>"/>
                    <input class="allowedBulkActions" type="hidden" name="allowedBulkActions" value="|trash|delete|<?php if ($post['status'] == 'publish') { echo 'unpublish|'; } ?><?php if ($post['status'] == 'pending') { echo 'approve|'; } ?><?php if ($post['status'] == 'trash') { echo 'restore|'; } ?><?php if ($post['status'] == 'future' || $post['status'] == 'draft') { echo 'publish|'; } ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>

                    <strong>
                        <abbr title="<?php echo $post['title']; ?>">
                        <?php if ($post['status'] != 'trash') { ?>
                        <a class="row-title"
                           href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>&location=<?php echo base64_encode('post.php?post=' . $post['id'] . '&action=edit'); ?>"
                           title="Edit '<?php echo $post['title']; ?>'"><?php echo $post['title']; ?></a>
                        <?php } else { ?>
                        <?php echo $post['title']; ?>
                        <?php } ?>
                        </abbr>
                    </strong>

                    <div class="row-actions">
                        <?php if ($post['status'] != 'trash') { ?>
                        <span class="edit"><a
                                href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>&location=<?php echo base64_encode('post.php?post=' . $post['id'] . '&action=edit'); ?>"
                                title="Edit this item"><?php _e('Edit','mainwp'); ?></a></span>
                        <span class="trash">
                            | <a class="post_submitdelete" title="Move this item to the Trash" href="#"><?php _e('Trash','mainwp'); ?></a>
                        </span>
                        <?php } ?>

                        <?php if ($post['status'] == 'future' || $post['status'] == 'draft') { ?>
                        <span class="publish">
                            | <a class="post_submitpublish" title="Publish this item" href="#"><?php _e('Publish','mainwp'); ?></a>
                        </span>
                        <?php } ?>

                        <?php if ($post['status'] == 'pending') { ?>
                        <span class="post-approve">
                            | <a class="post_submitapprove" title="Approve this item" href="#"><?php _e('Approve','mainwp'); ?></a>
                        </span>
                        <?php } ?>

                        <?php if ($post['status'] == 'publish') { ?>
                        <span class="view">
                            | <a
                                href="<?php echo $website->url . (substr($website->url, -1) != '/' ? '/' : '') . '?p=' . $post['id']; ?>"
                                target="_blank" title="View “<?php echo $post['title']; ?>�?" rel="permalink"><?php _e('View','mainwp'); ?></a>
                        </span>
                        <span class="unpublish">
                            | <a class="post_submitunpublish" title="Unpublish this item" href="#"><?php _e('Unpublish','mainwp'); ?></a>
                        </span>
                        <?php } ?>

                        <?php if ($post['status'] == 'trash') { ?>
                        <span class="restore">
                           <a class="post_submitrestore" title="Restore this item" href="#"><?php _e('Restore','mainwp'); ?></a>
                        </span>
                        <span class="trash">
                            | <a class="post_submitdelete_perm" title="Delete this item permanently" href="#"><?php _e('Delete
                            Permanently','mainwp'); ?></a>
                        </span>
                        <?php } ?>
                    </div>
                    <div class="row-actions-working"><img
                            src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?></div>
                </td>
                <td class="author column-author">
                    <?php echo $post['author']; ?>
                </td>
                <td class="categories column-categories">
                    <?php echo $post['categories']; ?>
                </td>
                <td class="tags column-tags"><?php echo ($post['tags'] == "" ? "No Tags" : $post['tags']); ?></td>
                <td class="comments column-comments">
                    <div class="post-com-count-wrapper">
                        <a href="<?php echo admin_url('admin.php?page=CommentBulkManage&siteid='.$website->id.'&postid='.$post['id']); ?>" title="0 pending" class="post-com-count"><span
                                class="comment-count"><abbr title="<?php echo $post['comment_count']; ?>"><?php echo $post['comment_count']; ?></abbr></span></a>
                    </div>
                </td>
                <td class="date column-date"><abbr
                        title="<?php echo $post['dts']; ?>"><?php echo $post['dts']; ?></abbr>
                </td>
                <td class="date column-status"><?php echo self::getStatus($post['status']); ?></td>
                <td class="categories column-categories">
                    <a href="<?php echo $website->url; ?>" target="_blank"><?php echo $website->url; ?></a>
                    <div class="row-actions">
                        <span class="edit"><a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>"><?php _e('Dashboard','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>"><?php _e('WP Admin','mainwp'); ?></a></span>
                    </div>
                </td>
            </tr>
            <?php
                $newOutput = ob_get_clean();
                echo $newOutput;

                MainWPCache::addBody('Post', $newOutput);
                $output->posts++;
            }
            unset($posts);
        } else {
            $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException('NOMAINWP', $website->url));
        }
    }

    public static function renderBulkAdd()
    {
        //Loads the post screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
        self::renderHeader('BulkAdd'); ?>
        <div class="mainwp_info-box"><strong><?php _e('Use this to add new posts. To bulk change posts click on the "Manage" tab.','mainwp'); ?> </strong></div>
        <iframe scrolling="auto" id="mainwp_iframe"
                src="<?php echo get_site_url() . '/wp-admin/post-new.php?post_type=bulkpost&hideall=1' . (isset($_REQUEST['select']) ? '&select='.$_REQUEST['select'] : ''); ?>"></iframe>
    <?php
        self::renderFooter('BulkAdd');
    }

    public static function getCategories()
    {
        $websites = array();
        if (isset($_REQUEST['sites']) && ($_REQUEST['sites'] != ''))
        {
            $siteIds = explode(',', urldecode($_REQUEST['sites']));
            $siteIdsRequested = array();
            foreach ($siteIds as $siteId)
            {
                $siteId = $siteId;
                if (!MainWPUtility::ctype_digit($siteId)) continue;
                $siteIdsRequested[] = $siteId;
            }

            $websites = MainWPDB::Instance()->getWebsitesByIds($siteIdsRequested);
        }
        else if (isset($_REQUEST['groups']) && ($_REQUEST['groups'] != ''))
        {
            $groupIds = explode(',', urldecode($_REQUEST['groups']));
            $groupIdsRequested = array();
            foreach ($groupIds as $groupId)
            {
                $groupId = $groupId;

                if (!MainWPUtility::ctype_digit($groupId)) continue;
                $groupIdsRequested[] = $groupId;
            }

            $websites = MainWPDB::Instance()->getWebsitesByGroupIds($groupIdsRequested);
        }

        $selectedCategories = array();
        if (isset($_REQUEST['selected_categories']) && ($_REQUEST['selected_categories'] != ''))
        {
            $selectedCategories = explode(',', urldecode($_REQUEST['selected_categories']));
        }

        $allCategories = array('Uncategorized');
        if (count($websites) > 0)
        {
            foreach ($websites as $website)
            {
                $cats = json_decode($website->categories, TRUE);
                if (is_array($cats) && (count($cats) > 0))
                {
                    $allCategories = array_unique(array_merge($allCategories, $cats));
                }
            }
        }

        if (count($allCategories) > 0 ) {
            natcasesort($allCategories);
            foreach ($allCategories as $category)
            {
                echo '<li class="popular-category sitecategory"><label class="selectit"><input value="'.$category.'" type="checkbox" name="post_category[]" '.(in_array($category, $selectedCategories) ? 'checked' : '').'> '.$category.'</label></li>';
            }
        }
        die();
    }

    public static function posting()
    {
        //Posts the saved sites
        ?>
    <div class="wrap">
<!--        <img src="--><?php //echo plugins_url('images/icons/mainwp-post.png', dirname(__FILE__)); ?><!--" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Post" height="32"/>-->
        <h2>New Post</h2>
        <?php
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            $post = get_post($id);
            if ($post) {
//                die('<pre>'.print_r($post, 1).'</pre>');
                $selected_by = get_post_meta($id, '_selected_by', true);
                $selected_sites = unserialize(base64_decode(get_post_meta($id, '_selected_sites', true)));
                $selected_groups = unserialize(base64_decode(get_post_meta($id, '_selected_groups', true)));

                /** @deprecated */
                $post_category = base64_decode(get_post_meta($id, '_categories', true));

                $post_tags = base64_decode(get_post_meta($id, '_tags', true));
                $post_slug = base64_decode(get_post_meta($id, '_slug', true));
                $post_custom = get_post_custom($id);
//                if (isset($post_custom['_tags'])) $post_custom['_tags'] = base64_decode(trim($post_custom['_tags']));

                include_once(ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php');
                $post_featured_image = get_post_thumbnail_id($id);
                $mainwp_upload_dir = wp_upload_dir();
//                $results = apply_filters('mainwp-pre-posting-posts', array($post), true);
//                $post = $results[0];                
                $new_post = array(
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_status' => $post->post_status, //was 'publish'
                    'post_date' => $post->post_date,
                    'post_date_gmt' => $post->post_date_gmt,
                    'post_tags' => $post_tags,
                    'post_name' => $post_slug,
                    'post_excerpt' => $post->post_excerpt,
                    'comment_status' => $post->comment_status,
                    'ping_status' => $post->ping_status,
                    'id_spin' => $post->ID,
                );

                if ($post_featured_image != null) { //Featured image is set, retrieve URL
                    $img = wp_get_attachment_image_src($post_featured_image, 'full');
                    $post_featured_image = $img[0];
                }

                $dbwebsites = array();
                if ($selected_by == 'site') { //Get all selected websites
                    foreach ($selected_sites as $k) {
                        if (MainWPUtility::ctype_digit($k)) {
                            $website = MainWPDB::Instance()->getWebsiteById($k);
                            $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                        }
                    }
                } else { //Get all websites from the selected groups
                    foreach ($selected_groups as $k) {
                        if (MainWPUtility::ctype_digit($k)) {
                            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($k));
                            while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                            {
                                $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                            }
                            @MainWPDB::free_result($websites);
                        }
                    }
                }

                $output = new stdClass();
                $output->ok = array();
                $output->errors = array();

                if (count($dbwebsites) > 0) {
                    $post_data = array(
                        'new_post' => base64_encode(serialize($new_post)),
                        'post_custom' => base64_encode(serialize($post_custom)),
                        'post_category' => base64_encode($post_category),
                        'post_featured_image' => base64_encode($post_featured_image),
                        'mainwp_upload_dir' => base64_encode(serialize($mainwp_upload_dir)));

                    MainWPUtility::fetchUrlsAuthed($dbwebsites, 'newpost', $post_data, array(MainWPBulkAdd::getClassName(), 'PostingBulk_handler'), $output);
                }

                foreach ($dbwebsites as $website)
                {
                    if (($output->ok[$website->id] == 1) && (isset($output->added_id[$website->id])))
                    {
                        do_action('mainwp-post-posting-post', $website, $output->added_id[$website->id], (isset($output->link[$website->id]) ? $output->link[$website->id] : null));
                        do_action('mainwp-bulkposting-done', $post, $website, $output);
                    }
                }

                wp_delete_post($id, true);
            }
            ?>
            <div id="message" class="updated">
                <?php foreach ($dbwebsites as $website) {
                ?>
                <p><?php echo $website->name; ?>
                    : <?php echo (isset($output->ok[$website->id]) && $output->ok[$website->id] == 1 ? 'New post created. '."<a href=\"".$output->link[$website->id]."\" target=\"_blank\">View Post</a>" : 'ERROR: ' . $output->errors[$website->id]); ?></p>
                <?php } ?>
            </div>
            <br/>
            <a href="<?php echo get_admin_url() ?>admin.php?page=PostBulkAdd" class="add-new-h2" target="_top"><?php _e('Add New','mainwp'); ?></a>
            <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return
                to Dashboard','mainwp'); ?></a>
            <?php
        } else {
            ?>
            <div class="error below-h2">
                <p><strong><?php _e('ERROR','mainwp'); ?></strong>: <?php _e('An undefined error occured.','mainwp'); ?></p>
            </div>
            <br/>
            <a href="<?php echo get_admin_url() ?>admin.php?page=PostBulkAdd" class="add-new-h2" target="_top"><?php _e('Add New','mainwp'); ?></a>
            <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return
                to Dashboard','mainwp'); ?></a>
            <?php
        }
        ?>
    </div>
    <?php
    }
    
    // public static function postingContent($id, $timestamp, $cats, $websiteId )
    // {
        // if (intval($id) > 0) {
            // $post = get_post($id);
            // if ($post) {
                // $cats = is_array($cats) ? $cats  : array();
                // $post_tags = base64_decode(get_post_meta($id, '_tags', true));
                // $post_slug = base64_decode(get_post_meta($id, '_slug', true));
                // $post_custom = get_post_custom($id);
                       // $results = apply_filters('mainwp-pre-posting-posts', array($post), true);
                       // $post = $results[0];
                // $new_post = array(
                    // 'post_title' => $post->post_title,
                    // 'post_content' => $post->post_content,
                    // 'post_date' => date('Y-m-d H:i:s', $timestamp),
                    // 'post_status' => $post->post_status,
                    // 'post_type' => $post->post_type,
                    // 'id_spin' => $post->ID,
                // );

                // $website = MainWPDB::Instance()->getWebsiteById($websiteId);
                // if (!MainWPUtility::can_edit_website($website)) return false;

                // $post_data = array(
                    // 'new_post' => base64_encode(serialize($new_post)),
                    // 'post_custom' => base64_encode(serialize($post_custom)),
                    // '_ezin_post_category' => base64_encode(serialize($cats))
                // );

                // try
                // {
                    // $information = MainWPUtility::fetchUrlAuthed($website, 'newpost', $post_data);
                // }
                // catch (Exception $e)
                // {
                    // throw  $e;
                // }

                // $ret = array();
                // if (is_array($information))
                // {
                    // $ret['wp_insert_id'] = $information['added_id'];
                    // $ret['wp_insert_link'] = $information['link'];

                    // if (($information['added'] == 1) && (isset($information['added_id'])))
                    // {
                        // do_action('mainwp-post-posting-post', $website, $information['added_id'], (isset($information['link']) ? $information['link'] : null));
                    // }
                // }
                // return $ret;
            // }
        // }

        // return false;
    // }
    
     public static function PostsGetTerms_handler($data, $website, &$output)
    {
        if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $result = $results[1];
            $cats = unserialize(base64_decode($result));
            $output->cats[$website->id] = is_array($cats) ? $cats : array();
        } else {            
            $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException('NOMAINWP', $website->url));
        }
    }
    
    public static function getTerms($websiteid, $prefix = '', $what = 'site', $gen_type = 'post')
    {                  
            $output = new stdClass();
            $output->errors = array();
            $output->cats = array();    
            $dbwebsites = array();            
            if ($what == 'group')
                    $input_name = 'groups_selected_cats_'.$prefix.'[]'; 
            else
                    $input_name = 'sites_selected_cats_'.$prefix.'[]'; 
            
            if (!empty($websiteid)) {                     
                    if (MainWPUtility::ctype_digit($websiteid)) {
                        $website = MainWPDB::Instance()->getWebsiteById($websiteid);
                        $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                    }           
            } 
            
            if ($gen_type == 'post') {
                    $bkc_option_path = 'default_keywords_post';
                    $keyword_option = 'keywords_page';
            } else if ($gen_type == 'page') {
                    $bkc_option_path = 'default_keywords_page';
                    $keyword_option = 'keywords_page';
            }
            
            if ($prefix == 'bulk') {
                    $opt  = apply_filters('mainwp-get-options', $value = '', 'mainwp_content_extension', 'bulk_keyword_cats', $bkc_option_path);
                    $selected_cats = unserialize(base64_decode($opt));                   
            } 
            else  // is number 0,1,2, ... 
            {
                    $opt  = apply_filters('mainwp-get-options', $value = '', 'mainwp_content_extension', $keyword_option);
                    if (is_array($opt) && is_array($opt[$prefix]))					
                        $selected_cats = unserialize(base64_decode($opt[$prefix]['selected_cats']));
            }
            $selected_cats = is_array($selected_cats) ? $selected_cats : array();               
            $ret = ""; 
            if (count($dbwebsites) > 0) 
             {          
                        $opt = apply_filters('mainwp-get-options', $value = '', 'mainwp_content_extension', 'taxonomy');
                        $post_data = array(
                                  'taxonomy' => base64_encode($opt)                                          
                         );                                                
                        MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_terms', $post_data, array(MainWPPost::getClassName(), 'PostsGetTerms_handler'), $output);
                        foreach($dbwebsites as $siteid => $website)  {   
                                $cats = array();   
                                if (is_array($selected_cats[$siteid]))
                                        foreach($selected_cats[$siteid] as $val)
                                                $cats[] = $val['term_id'];                                
                                if (!empty($output->errors[$siteid]))  
                                {
                                       $ret .= '<p> Error - '.$output->errors[$siteid].'</p>';
                                }
                                else 
                                {                                                 
                                        if (count($output->cats[$siteid]) > 0) {
                                               foreach ($output->cats[$siteid] as $cat) {    
                                                   if ($cat->term_id) {
                                                            if (in_array($cat->term_id,  $cats))
                                                                    $checked = ' checked="checked" ';
                                                            else
                                                                    $checked = '';                                                            
                                                           $ret .=   '<div class="mainwp_selected_sites_item ' .(!empty($checked) ? 'selected_sites_item_checked' : ''). '"><input type="checkbox" name="'.$input_name.'" value="' . $siteid . "," .  $cat->term_id . "," . $cat->name . '" ' . $checked . '/><label>'.$cat->name.'</label></div>';
                                                   }
                                               }                                               
                                         }
                                         else 
                                         {
                                               $ret .=   '<p>No categories have been found</p>'; 
                                         }
                                }                                
                        }               
             }
             else 
                    $ret .=  '<p>Error - no site</p>';             
             echo $ret;
    }
    
     public static function testPost()
    {           
          do_action('mainwp-do-action', 'test_post');
    }
    
    public static function updatePostMeta($websiteIdEnc, $postId, $values )
    {
        $values =  base64_encode(serialize($values));

        if (!MainWPUtility::ctype_digit($postId)) return;
        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) return;

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) return;

        try
        {
            $information = MainWPUtility::fetchUrlAuthed($website, 'post_action', array(
                'action' => 'upate_meta',
                'id' => $postId,
                'values' =>$values));                    
        }
        catch (MainWPException $e)
        {
            return;
        }

        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) return;
    }
    
    public static function setTerms($postId, $cat_id, $taxonomy, $websiteIdEnc )
    {
        if (!MainWPUtility::ctype_digit($postId)) return;
        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) return;

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) return;

        try
        {
            $information = MainWPUtility::fetchUrlAuthed($website, 'set_terms', array(
                'id' => base64_encode($postId),
                'terms' => base64_encode($cat_id),
                'taxonomy' => base64_encode($taxonomy)));
        }
        catch (MainWPException $e)
        {
            return;
        }
        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) return;
    }

    public static function insertComments($postId, $comments, $websiteId )
    {
        if (!MainWPUtility::ctype_digit($postId)) return;
        if (!MainWPUtility::ctype_digit($websiteId)) return;
        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) return;
        try
        {
            MainWPUtility::fetchUrlAuthed($website, 'insert_comment', array(
                'id' => $postId,
                'comments' => base64_encode(serialize($comments)),
            ));
        }
        catch (MainWPException $e)
        {
            return;
        }
        return;
    }    
    
    public static function getPostMeta($postId, $keys,  $value, $websiteId )
    {
        if (!MainWPUtility::ctype_digit($postId)) return;
        if (!MainWPUtility::ctype_digit($websiteId)) return;

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) return;

        try
        {
            $results = MainWPUtility::fetchUrlAuthed($website, 'get_post_meta', array('id' => base64_encode($postId),
                    'keys' => base64_encode($keys),
                    'value' => base64_encode($value)));
        }
        catch (MainWPException $e)
        {
            return;
        }
        return $results;
    }    
    
    // public static function getTotalEZinePost($startdate, $enddate,  $keyword_meta, $websiteId )
    // {
        // if (empty($keyword_meta)) return;
        // if (!MainWPUtility::ctype_digit($websiteId)) return;
        // $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        // if (!MainWPUtility::can_edit_website($website)) return;
        // try
        // {
            // $results = MainWPUtility::fetchUrlAuthed($website, 'get_total_ezine_post', array('start_date' => base64_encode($startdate),
                // 'end_date' => base64_encode($enddate),
                // 'keyword_meta' => base64_encode($keyword_meta)));
        // }
        // catch (MainWPException $e)
        // {
            // return;
        // }
        // return $results;

    // }
    
    // public static function GetNextTime_handler($data, $website, &$output)
    // {
        // if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            // $result = $results[1];
            // $information = unserialize(base64_decode($result));
            // unset($results);
            // unset($result);
			// if(isset($information['error']))
			// {
				// $output->error = $information['error'];
			// }
			// else
			// {
					// if (is_array($information) && isset($information['next_post_date'])) {
							// $time = strtotime($information['next_post_date']);
							// $time_next = strtotime($output->next_post_date);
							// if ($time > 0)
							// {
								// if ($time_next == 0 || $time_next > $time)
								// {
									// $output->next_post_date =  date('Y-m-d H:i:s', $time);
									// $output->next_post_id = $information['next_post_id'];   ;
									// $output->next_post_website_id = $website->id;
									// $output->next_posts= $information['next_posts'];
									// $output->error = NULL;
								// }
							// }
					// }
			// }
        // }
    // }

    // public static function getNextTimeToPost($post_type_function = 'get_next_time_to_post')
    // {
        // $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        // $dbwebsites = array();
        // while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        // {
            // $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
        // }
        // @MainWPDB::free_result($websites);
        // $output = new stdClass();
        // if (count($dbwebsites) > 0)
        // {
            // MainWPUtility::fetchUrlsAuthed($dbwebsites, $post_type_function, '', array(MainWPPost::getClassName(), 'GetNextTime_handler'), $output);
        // }
        // return get_object_vars($output);
    // }

    public static function addStickyOption()
    {
        global $wp_meta_boxes;

        if (isset($wp_meta_boxes['bulkpost']['side']['core']['submitdiv']))
        {
            $wp_meta_boxes['bulkpost']['side']['core']['submitdiv']['callback'] = array(self::getClassName(), 'post_submit_meta_box');
        }
    }

    public static function post_submit_meta_box($post)
    {
        @ob_start();
        post_submit_meta_box($post);

        $out = @ob_get_contents();
        @ob_end_clean();
        $find = ' <label for="visibility-radio-public" class="selectit">' . translate('Public') . '</label><br />';
        $replace = '<span id="sticky-span"><input id="sticky" name="sticky" type="checkbox" value="sticky" /> <label for="sticky" class="selectit">' . translate( 'Stick this post to the front page' ) . '</label><br /></span>';
        $replace .= '<input type="checkbox" style="display:none" name="hidden_post_sticky" id="hidden-post-sticky" value="sticky" />';
        echo str_replace($find, $find . $replace, $out);
    }

    public static function add_sticky_handle($post_id)
    {
        // OK, we're authenticated: we need to find and save the data
        $post = get_post($post_id);
        if ($post->post_type == 'bulkpost' && isset($_POST['sticky']))
        {
            update_post_meta($post_id, '_sticky', base64_encode($_POST['sticky']));
            return base64_encode($_POST['sticky']);
        }
        return $post_id;
    }

    public static function QSGManagePosts() {
        self::renderHeader('PostsHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Manage Posts','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="2"><?php _e('Create a New Post','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Manage Posts</h3>
                            <p>
                                <ol>
                                    <li>
                                        Select statuses of your posts you want to find. Select between standard WordPress post statuses: Published, Draft, Pending, Future, Private and Trash<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-post-status.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Optionaly, enter a Keyword <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-post-keyword.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Optionaly, use the provided Date Picker to select date range for wanted posts <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-post-date.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Use the Select Sites box to select the sites to be searched <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-post-sites.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Click the Show Posts button and MainWP Plugin will gather all your posts based on your search parameters in one list
                                    </li>
                                    <li>
                                        You can see the post Title, Author, Categories, Tags, Number of Comments and Date.
                                    </li>
                                    <li>
                                        Use the provided Quick Links to Edit, Delete, Publish, Unpublish or View post.
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="2">
                            <h3>Create a New Post</h3>
                            <p>
                                <ol>
                                    <li>
                                        To add a New Post in one or more sites in your network, go to Add New Tab.
                                    </li>
                                    <li>
                                        Here is the standard WordPress publishing mechanism. Only difference is the Select Sites box which enables you to select sites where you want to post the article. <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-post-new-1024x480.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
    <?php
     self::renderFooter('PostsHelp');
    }
}
