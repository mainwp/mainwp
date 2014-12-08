<?php
/**
 * @see MainWPBulkAdd
 */
class MainWPPage
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;
    
    public static function init()
    {
        add_action('mainwp-pageheader-page', array(MainWPPage::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-page', array(MainWPPage::getClassName(), 'renderFooter'));
    }
    
    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Pages','mainwp'), '<span id="mainwp-Pages">'.__('Pages','mainwp').'</span>', 'read', 'PageBulkManage', array(MainWPPage::getClassName(), 'render'));
        add_submenu_page('mainwp_tab', 'Pages', '<div class="mainwp-hidden">Add New</div>', 'read', 'PageBulkAdd', array(MainWPPage::getClassName(), 'renderBulkAdd'));
        add_submenu_page('mainwp_tab', 'Posting new bulkpage', '<div class="mainwp-hidden">Add New Page</div>', 'read', 'PostingBulkPage', array(MainWPPage::getClassName(), 'posting')); //removed from menu afterwards
        add_submenu_page('mainwp_tab', __('Pages Help','mainwp'), '<div class="mainwp-hidden">'.__('Pages Help','mainwp').'</div>', 'read', 'PagesHelp', array(MainWPPage::getClassName(), 'QSGManagePages'));

        self::$subPages = apply_filters('mainwp-getsubpages-page', array());
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Page' . $subPage['slug'], $subPage['callback']);
            }
        }
    }
    

    public static function initMenuSubPages()
    {
        ?>
       <div id="menu-mainwp-Pages" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <?php if (mainwp_current_user_can("dashboard", "manage_pages")) { ?>
                    <a href="<?php echo admin_url('admin.php?page=PageBulkManage'); ?>" class="mainwp-submenu"><?php _e('Manage Pages','mainwp'); ?></a>                    
                    <a href="<?php echo admin_url('admin.php?page=PageBulkAdd'); ?>" class="mainwp-submenu"><?php _e('Add New','mainwp'); ?></a>
                    <?php } ?>
                        <?php
                        if (isset(self::$subPages) && is_array(self::$subPages))
                        {
                            foreach (self::$subPages as $subPage)
                            {
                                if (!isset($subPage['menu_hidden']) || (isset($subPage['menu_hidden']) && $subPage['menu_hidden'] != true))
                                {
							?>
                                    <a href="<?php echo admin_url('admin.php?page=Page'.$subPage['slug']); ?>" class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
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
        <a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP" /></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-page.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Page" height="32"/>
        <h2><?php _e('Pages','mainwp'); ?></h2><div style="clear: both;"></div><br/>
        <div id="mainwp-tip-zone">
          <?php if ($shownPage == 'BulkManage') { ?> 
                <div class="mainwp-tips mainwp_info-box-blue"><span class="mainwp-tip"><strong><?php _e('MainWP Tip','mainwp'); ?>: </strong><?php _e('You can also quickly see all Published, Draft, Pending and Trash Pages for a single site from your Individual Site Dashboard Recent Pages widget by visiting Sites &rarr; Manage Sites &rarr; Child Site &rarr; Dashboard.','mainwp'); ?></span><span><a href="#" class="mainwp-dismiss" ><?php _e('Dismiss','mainwp'); ?></a></span></div>
          <?php } ?>
        </div>
        <div class="mainwp-tabs" id="mainwp-tabs">
                <?php if (mainwp_current_user_can("dashboard", "manage_pages")) { ?>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'BulkManage') { echo "nav-tab-active"; } ?>" href="admin.php?page=PageBulkManage"><?php _e('Manage','mainwp'); ?></a>                
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'BulkAdd') { echo "nav-tab-active"; } ?>" href="admin.php?page=PageBulkAdd"><?php _e('Add New','mainwp'); ?></a>
                <?php } ?>
                <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'PagesHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=PagesHelp"><?php _e('Help','mainwp'); ?></a>

                <?php
                if (isset(self::$subPages) && is_array(self::$subPages))
                {
                    foreach (self::$subPages as $subPage)
                    {
						if (isset($subPage['tab_link_hidden']) && $subPage['tab_link_hidden'] == true)
							$tab_link = "#";
						else
							$tab_link = "admin.php?page=Page". $subPage['slug'];
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
        if (!mainwp_current_user_can("dashboard", "manage_pages")) {
            mainwp_do_not_have_permissions ("manage pages");
            return;
        }
        
        $cachedSearch = MainWPCache::getCachedContext('Page');

        //Loads the page screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
        ?>   
        <?php self::renderHeader('BulkManage'); ?>
    
            <div class="mainwp_info-box"><strong><?php _e('Use this to bulk change pages. To add new pages click on the "Add New" tab.','mainwp'); ?></strong></div>
        <br/>
        <div class="mainwp-search-form">
               <div class="postbox mainwp-postbox">
            <h3 class="mainwp_box_title"><?php _e('Search Pages','mainwp'); ?></h3>
            <div class="inside">
            <ul class="mainwp_checkboxes">
                <li>
                    <input type="checkbox" id="mainwp_page_search_type_publish" <?php echo ($cachedSearch == null || ($cachedSearch != null && in_array('publish', $cachedSearch['status']))) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_page_search_type_publish" class="mainwp-label2">Published</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_page_search_type_pending" <?php echo ($cachedSearch != null && in_array('pending', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_page_search_type_pending" class="mainwp-label2">Pending</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_page_search_type_private" <?php echo ($cachedSearch != null && in_array('private', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_page_search_type_private" class="mainwp-label2">Private</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_page_search_type_future" <?php echo ($cachedSearch != null && in_array('future', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_page_search_type_future" class="mainwp-label2">Future</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_page_search_type_draft" <?php echo ($cachedSearch != null && in_array('draft', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_page_search_type_draft" class="mainwp-label2">Draft</label>
                </li>
                <li>
                    <input type="checkbox" id="mainwp_page_search_type_trash" <?php echo ($cachedSearch != null && in_array('trash', $cachedSearch['status'])) ? 'checked="checked"' : ''; ?> class="mainwp-checkbox2"/>
                    <label for="mainwp_page_search_type_trash" class="mainwp-label2">Trash</label>
                </li>
            </ul>
            <p>
                <?php _e('Containing Keyword:','mainwp'); ?><br />
                <input type="text" id="mainwp_page_search_by_keyword" class="mainwp-field mainwp-keyword" size="50" value="<?php if ($cachedSearch != null) { echo $cachedSearch['keyword']; } ?>"/>
            </p>
            <p>
                <?php _e('Date Range:','mainwp'); ?><br />
                <input type="text" id="mainwp_page_search_by_dtsstart" class="mainwp_datepicker  mainwp-field mainwp-date" size="12" value="<?php if ($cachedSearch != null) { echo $cachedSearch['dtsstart']; } ?>"/> to <input type="text" id="mainwp_page_search_by_dtsstop" class="mainwp_datepicker  mainwp-field mainwp-date" size="12" value="<?php if ($cachedSearch != null) { echo $cachedSearch['dtsstop']; } ?>"/>
            </p>
             </div>
            </div>
            <?php MainWPUI::select_sites_box(__("Select Sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_left'); ?>
            <div style="clear: both;"></div>
            <input type="button" name="mainwp_show_pages" id="mainwp_show_pages" class="button-primary" value="<?php _e('Show Pages','mainwp'); ?>"/>
            <span id="mainwp_pages_loading">&nbsp;<em><?php _e('Grabbing information from Child Sites','mainwp') ?></em>&nbsp;&nbsp;<img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
            <br/><br/>
        </div>
        <div class="clear"></div>

        <div id="mainwp_pages_error"></div>
        <div id="mainwp_pages_main" <?php if ($cachedSearch != null) { echo 'style="display: block;"'; } ?>>
            <div class="alignleft">
                <select name="bulk_action" id="mainwp_bulk_action">
                    <option value="none"><?php _e('Bulk Action','mainwp'); ?></option>
                    <option value="trash"><?php _e('Move to Trash','mainwp'); ?></option>
                    <option value="restore"><?php _e('Restore','mainwp'); ?></option>
                    <option value="delete"><?php _e('Delete Permanently','mainwp'); ?></option>
                </select> <input type="button" name="" id="mainwp_bulk_page_action_apply" class="button" value="<?php _e('Apply','mainwp'); ?>"/>
            </div>
            <div class="alignright" id="mainwp_pages_total_results">
                <?php _e('Total Results:','mainwp'); ?> <span id="mainwp_pages_total"><?php if ($cachedSearch != null) { echo $cachedSearch['count']; } ?></span>
            </div>
            <div class="clear"></div>
            <div id="mainwp_pages_content">
                <table class="wp-list-table widefat fixed pages tablesorter" id="mainwp_pages_table"
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

                    <tbody id="the-posts-list" class="list:pages">
                        <?php MainWPCache::echoBody('Page'); ?>
                    </tbody>
                </table>
                <div class="pager" id="pager">
                    <form>
                        <img src="<?php echo plugins_url('images/first.png', dirname(__FILE__)); ?>" class="first">
                        <img src="<?php echo plugins_url('images/prev.png', dirname(__FILE__)); ?>" class="prev">
                        <input type="text" class="pagedisplay">
                        <img src="<?php echo plugins_url('images/next.png', dirname(__FILE__)); ?>" class="next">
                        <img src="<?php echo plugins_url('images/last.png', dirname(__FILE__)); ?>" class="last">
                        <span>&nbsp;&nbsp;<?php _e('Show:','mainwp'); ?> </span><select class="pagesize">
                            <option selected="selected" value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="1000000000">All</option>
                        </select><span> <?php _e('Pages per page','mainwp'); ?></span>
                    </form>
                </div>
                <div class="clear"></div>
            </div>
        </div>
    </div>
    </div>
    <?php
        if ($cachedSearch != null) { echo '<script>mainwp_pages_table_reinit();</script>'; }
    }

    public static function renderTable($keyword, $dtsstart, $dtsstop, $status, $groups, $sites)
    {
        MainWPCache::initCache('Page');

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
                        if ($website->sync_errors != '') continue;
                        $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                    }
                    @MainWPDB::free_result($websites);
                }
            }
        }

        $output = new stdClass();
        $output->errors = array();
        $output->pages = 0;

        if (count($dbwebsites) > 0) {
            $post_data = array(
                'keyword' => $keyword,
                'dtsstart' => $dtsstart,
                'dtsstop' => $dtsstop,
                'status' => $status,
                'maxRecords' => ((get_option('mainwp_maximumPosts') === false) ? 50 : get_option('mainwp_maximumPosts'))
            );
            MainWPUtility::fetchUrlsAuthed($dbwebsites, 'get_all_pages', $post_data, array(MainWPPage::getClassName(), 'PagesSearch_handler'), $output);
        }

        MainWPCache::addContext('Page', array('count' => $output->pages, 'keyword' => $keyword, 'dtsstart' => $dtsstart, 'dtsstop' => $dtsstop, 'status' => $status));
        //Sort if required

        if ($output->pages == 0) {
            ob_start();
            ?>
        <tr>
            <td colspan="7">No pages found</td>
        </tr>
        <?php
            $newOutput = ob_get_clean();
            echo $newOutput;
            MainWPCache::addBody('Page', $newOutput);
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

    public static function PagesSearch_handler($data, $website, &$output)
    {
        if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $pages = unserialize(base64_decode($results[1]));
            unset($results);
            foreach ($pages as $page)
            {
                if (isset($page['dts']))
                {
                    if (!stristr($page['dts'], '-'))
                    {
                        $page['dts'] = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($page['dts']));
                    }
                }

                if (!isset($page['title']) || ($page['title'] == ''))
                {
                    $page['title'] = '(No Title)';
                }
                ob_start();
                ?>
            <tr id="page-1"
                class="page-1 page type-page status-publish format-standard hentry category-uncategorized alternate iedit author-self"
                valign="top">
                <th scope="row" class="check-column"><input type="checkbox" name="page[]" value="1"></th>
                <td class="page-title page-title column-title">
                    <input class="pageId" type="hidden" name="id" value="<?php echo $page['id']; ?>"/>
                    <input class="allowedBulkActions" type="hidden" name="allowedBulkActions" value="|trash|delete|<?php if ($page['status'] == 'trash') { echo 'restore|'; } ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>

                    <strong>
                        <abbr title="<?php echo $page['title']; ?>">
                        <?php if ($page['status'] != 'trash') { ?>
                        <a class="row-title"
                           href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>&location=<?php echo base64_encode('post.php?post=' . $page['id'] . '&action=edit'); ?>"
                           title="Edit “<?php echo $page['title']; ?>�?"><?php echo $page['title']; ?></a>
                        <?php } else { ?>
                        <?php echo $page['title']; ?>
                        <?php } ?>
                        </abbr>
                    </strong>

                    <div class="row-actions">
                        <?php if ($page['status'] != 'trash') { ?>
                        <span class="edit"><a
                                href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>&location=<?php echo base64_encode('post.php?post=' . $page['id'] . '&action=edit'); ?>"
                                title="Edit this item"><?php _e('Edit','mainwp'); ?></a></span>
                        <span class="trash">
                            | <a class="page_submitdelete" title="Move this item to the Trash" href="#"><?php _e('Trash','mainwp'); ?></a>
                    </span>
                        <?php } ?>

                        <?php if ($page['status'] == 'publish') { ?>
                        <span class="view">
                            | <a
                                href="<?php echo $website->url . (substr($website->url, -1) != '/' ? '/' : '') . '?p=' . $page['id']; ?>"
                                target="_blank" title="View “<?php echo $page['title']; ?>�?" rel="permalink"><?php _e('View','mainwp'); ?></a></span>
                        <?php } ?>
                        <?php if ($page['status'] == 'trash') { ?>
                        <span class="restore">
                           <a class="page_submitrestore" title="Restore this item" href="#"><?php _e('Restore','mainwp'); ?></a>
                        </span>
                        <span class="trash">
                            | <a class="page_submitdelete_perm" title="Delete this item permanently" href="#"><?php _e('Delete
                            Permanently','mainwp'); ?></a>
                        </span>
                        <?php } ?>
                    </div>
                    <div class="row-actions-working"><img
                            src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?>
                    </div>
                </td>
                <td class="author column-author">
                    <?php echo $page['author']; ?>
                </td>
                <td class="comments column-comments">
                    <div class="page-com-count-wrapper">
                        <a href="#" title="0 pending" class="post-com-count"><span
                                class="comment-count"><abbr title="<?php echo $page['comment_count']; ?>"><?php echo $page['comment_count']; ?></abbr></span></a>
                    </div>
                </td>
                <td class="date column-date"><abbr
                        title="<?php echo $page['dts']; ?>"><?php echo $page['dts']; ?></abbr>
                </td>
                <td class="status column-status"><?php echo MainWPPage::getStatus($page['status']); ?>
                </td>
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
                MainWPCache::addBody('Page', $newOutput);
                $output->pages++;
            }
            unset($pages);
        } else {
            $output->errors[$website->id] = MainWPErrorHelper::getErrorMessage(new MainWPException('NOMAINWP', $website->url));
        }
    }

    public static function publish()
    {
        MainWPRecentPosts::action('publish');
        die(json_encode(array('result' => 'Page has been published')));
    }

    public static function unpublish()
    {
        MainWPRecentPosts::action('unpublish');
        die(json_encode(array('result' => 'Page has been unpublished')));
    }

    public static function trash()
    {
        MainWPRecentPosts::action('trash');
        die(json_encode(array('result' => 'Page has been moved to trash')));
    }

    public static function delete()
    {
        MainWPRecentPosts::action('delete');
        die(json_encode(array('result' => 'Page has been permanently deleted')));
    }

    public static function restore()
    {
        MainWPRecentPosts::action('restore');
        die(json_encode(array('result' => 'Page has been restored')));
    }

    public static function renderBulkAdd()
    {
        if (!mainwp_current_user_can("dashboard", "manage_pages")) {            
            mainwp_do_not_have_permissions("manage pages");
            return;
        }
        
        $src = get_site_url() . '/wp-admin/post-new.php?post_type=bulkpage&hideall=1';        
        $src = apply_filters('mainwp_bulkpost_edit_source', $src);
        //Loads the post screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
        ?>
        <?php self::renderHeader('BulkAdd'); ?>
            <div class="mainwp_info-box"><strong><?php _e('Use this to add a new page. To bulk change pages click on the "Manage" tab.','mainwp'); ?></strong></div>
            <iframe scrolling="auto" id="mainwp_iframe"
                    src="<?php echo $src; ?>"></iframe>
        </div>
    </div>
    <?php
    }

    public static function posting()
    {
        ?>
    <div class="wrap">
        <?php //self::renderHeader(false, true); ?>
      <?php //  Use this to add a new page. To bulk change pages click on the "Manage" tab.
          
        do_action("mainwp_bulkpage_before_post", $_GET['id']);               
        
        $skip_post = false;
        if (isset($_GET['id'])) {              
           if ('yes' == get_post_meta($_GET['id'], '_mainwp_skip_posting', true)) {
                $skip_post = true;
                wp_delete_post($_GET['id'], true);              
           }           
        }       
       
        if (!$skip_post) {
            //Posts the saved sites
            if (isset($_GET['id'])) {
                $id = $_GET['id'];
                $post = get_post($id);
                if ($post) {
                    $selected_by = get_post_meta($id, '_selected_by', true);
                    $selected_sites = unserialize(base64_decode(get_post_meta($id, '_selected_sites', true)));
                    $selected_groups = unserialize(base64_decode(get_post_meta($id, '_selected_groups', true)));               
                    $post_slug = base64_decode(get_post_meta($id, '_slug', true));
                    $post_custom = get_post_custom($id);
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
                        'post_type' => 'page',
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
                                    if ($website->sync_errors != '') continue;
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
                            'post_featured_image' => base64_encode($post_featured_image),
                            'mainwp_upload_dir' => base64_encode(serialize($mainwp_upload_dir))
                        );
                        $post_data = apply_filters("mainwp_bulkpage_posting", $post_data, $id);
                        MainWPUtility::fetchUrlsAuthed($dbwebsites, 'newpost', $post_data, array(MainWPBulkAdd::getClassName(), 'PostingBulk_handler'), $output);
                    }

                    $failed_posts = array(); 
                    foreach ($dbwebsites as $website)
                    {
                        if (($output->ok[$website->id] == 1) && (isset($output->added_id[$website->id])))
                        {
                            do_action('mainwp-post-posting-page', $website, $output->added_id[$website->id], (isset($output->link[$website->id]) ? $output->link[$website->id] : null));
                            do_action('mainwp-bulkposting-done', $post, $website, $output);
                        } else {
                            $failed_posts[] =  $website->id;
                        } 
                    }

                    $del_post = true;
                    $saved_draft = get_post_meta($id, "_saved_as_draft", true);
                    if ($saved_draft == "yes") {
                        if (count($failed_posts) > 0) {
                            $del_post = false;
                            update_post_meta($post->ID, "_selected_sites", base64_encode(serialize($failed_posts)));
                            update_post_meta($post->ID, "_selected_groups", "");
                            wp_update_post( array("ID" => $id, 'post_status' => 'draft') ); 
                        }
                    }

                    if ($del_post)    
                        wp_delete_post($id, true);                 
                }
                ?>
                <div id="message" class="updated">
                    <?php foreach ($dbwebsites as $website) { ?>
                    <p><a href="<?php echo admin_url('admin.php?page=managesites&dashboard=' . $website->id); ?>"><?php echo $website->name; ?></a>
                        : <?php echo (isset($output->ok[$website->id]) && $output->ok[$website->id] == 1 ? 'New page created. '."<a href=\"".$output->link[$website->id]."\"  target=\"_blank\">View Page</a>" : 'ERROR: ' . $output->errors[$website->id]); ?></p>
                    <?php } ?>
                </div>
               
                <?php
            } else {
                ?>
                <div class="error below-h2">
                    <p><strong>ERROR</strong>: <?php _e('An undefined error occured.','mainwp'); ?></p>
                </div>               
                <?php
            }
        } // no skip posting
        ?>
        <br/>
        <a href="<?php echo get_admin_url() ?>admin.php?page=PageBulkAdd" class="add-new-h2" target="_top"><?php _e('Add New','mainwp'); ?></a>
        <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return
            to Dashboard','mainwp'); ?></a>
                
    </div>
    <?php
    }

    public static function QSGManagePages() {
        self::renderHeader('PagesHelp');
                    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Manage Pages','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="2"><?php _e('Create a New Page','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Manage Pages</h3>
                            <p>
                                <ol>
                                    <li>
                                        Select statuses of your pages you want to find. Select between standard WordPress page statuses: Published, Draft, Pending, Future, Private and Trash<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-pages-status.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Optionaly, enter a Keyword and use the provided Date Picker to select date range for wanted pages <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-pages-keyword.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Use the Select Sites box to select the sites to be searched <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-pages-sites.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Click the Show Pages button and MainWP Plugin will gather all your pages based on your search parameters in one list
                                    </li>
                                    <li>
                                        You can see the page Title, Author, Categories, Tags, Number of Comments and Date.
                                    </li>
                                    <li>
                                        Use the provided Quick Links to Edit, Delete, Publish, Unpublish or View page.
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="2">
                            <h3>Create a New Page</h3>
                            <p>
                                <ol>
                                    <li>
                                        To add a New Page in one or more sites in your network, go to Add New Tab.
                                    </li>
                                    <li>
                                        Here is the standard WordPress publishing mechanism. Only difference is the Select Sites box which enables you to select sites where you want to create a new page. <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-post-new-1024x480.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
    <?php
    self::renderFooter('PagesHelp');
    }

}

?>
