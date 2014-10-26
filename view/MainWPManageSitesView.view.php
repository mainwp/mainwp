<?php
class MainWPManageSitesView
{
    public static function initMenu()
    {
        return add_submenu_page('mainwp_tab', __('Sites','mainwp'), '<span id="mainwp-Sites">'.__('Sites','mainwp').'</span>', 'read', 'managesites', array(MainWPManageSites::getClassName(), 'renderAllSites'));
    }

    public static function initMenuSubPages(&$subPages)
    {
        ?>
    <div id="menu-mainwp-Sites" class="mainwp-submenu-wrapper">
        <div class="wp-submenu sub-open" style="">
            <div class="mainwp_boxout">
                <div class="mainwp_boxoutin"></div>
                <a href="<?php echo admin_url('admin.php?page=managesites'); ?>" class="mainwp-submenu"><?php _e('All Sites','mainwp'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=managesites&do=new'); ?>" class="mainwp-submenu"><?php _e('Add New','mainwp'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=managesites&do=test'); ?>" class="mainwp-submenu"><?php _e('Test Connection','mainwp'); ?></a>
                <?php
                if (isset($subPages) && is_array($subPages))
                {
                    foreach ($subPages as $subPage)
                    {
                        if (!isset($subPage['menu_hidden']) || (isset($subPage['menu_hidden']) && $subPage['menu_hidden'] != true)) {
                    ?>
                        <a href="<?php echo admin_url('admin.php?page=ManageSites' . $subPage['slug']); ?>"
                           class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
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

    public static function renderHeader($shownPage, &$subPages)
    {
        ?>
    <div class="wrap">
        <a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
                src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50"
                alt="MainWP"/></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-sites.png', dirname(__FILE__)); ?>"
             style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Sites" height="32"/>
        <h2><?php _e('Sites','mainwp'); ?></h2><div style="clear: both;"></div><br/>
        <div class="mainwp-tabs" id="mainwp-tabs">
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == '') { echo "nav-tab-active"; } ?>" href="admin.php?page=managesites"><?php _e('Manage','mainwp'); ?></a>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'AddNew') { echo "nav-tab-active"; } ?>" href="admin.php?page=managesites&do=new"><?php _e('Add New','mainwp'); ?></a>
            <?php if ($shownPage == 'ManageSitesBulkUpload') { ?><a class="nav-tab pos-nav-tab nav-tab-active" href="#"><?php _e('Bulk upload','mainwp'); ?></a><?php } ?>
            <?php if ($shownPage == 'ManageSitesEdit') { ?>
                <a class="nav-tab pos-nav-tab" href="admin.php?page=managesites&dashboard=<?php echo $_GET['id'] ?>"><?php _e('Dashboard','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab nav-tab-active" href="#"><?php _e('Edit','mainwp'); ?></a>
            <?php } ?>
            <?php if ($shownPage == 'ManageSitesDashboard') { ?>
                <a class="nav-tab pos-nav-tab nav-tab-active" href="#"><?php _e('Dashboard','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab " href="admin.php?page=managesites&id=<?php echo $_GET['dashboard'] ?>"><?php _e('Edit','mainwp'); ?></a>
            <?php } ?>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'Test') { echo "nav-tab-active"; } ?>" href="admin.php?page=managesites&do=test"><?php _e('Test Connection','mainwp'); ?></a>
            <a style="float: right;" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage == 'SitesHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=SitesHelp"><?php _e('Help','mainwp'); ?></a>
            <?php
            if (isset($subPages) && is_array($subPages))
            {
                foreach ($subPages as $subPage)
                {
                    if ($shownPage === $subPage['slug'] || !isset($subPage['menu_hidden']) || (isset($subPage['menu_hidden']) && $subPage['menu_hidden'] != true)) {
                ?>
                    <a class="nav-tab pos-nav-tab <?php if ($shownPage === $subPage['slug']) { echo "nav-tab-active"; } ?>" href="admin.php?page=ManageSites<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
                <?php
                    }
                }
            }
            ?>
        </div>

        <div id="mainwp_wrap-inside">
    <?php
    }

    public static function renderFooter($shownPage, &$subPages)
    {
        ?>
        </div>
    </div>
        <?php
    }


    public static function renderTest()
    {
        ?>
            <div id="mainwp_managesites_test_errors" class="mainwp_error error"></div>
            <div id="mainwp_managesites_test_message" class="mainwp_updated updated"></div>
            <div class="mainwp_info-box"><strong><?php _e('Please only use the domain URL, do not add /wp-admin.','mainwp'); ?></strong></div>
            <div class="postbox">
            <h3 class="mainwp_box_title"><span><?php _e('Test a Site Connection','mainwp'); ?></span></h3>
            <div class="inside">
            <form method="POST" action="" enctype="multipart/form-data" id="mainwp_testconnection_form">
                <table class="form-table">
                    <tr class="form-field form-required">
                        <th scope="row"><?php _e('Site URL:','mainwp'); ?></th>
                        <td>
                            <input type="text" id="mainwp_managesites_test_wpurl"
                                   name="mainwp_managesites_add_wpurl"
                                   value="<?php if (isset($_REQUEST['site'])) echo $_REQUEST['site']; ?>" autocompletelist="mainwp-test-sites" class="mainwp_autocomplete" /><span class="mainwp-form_hint">Proper Format: <strong>http://address.com/</strong> or <strong>http://www.address.com/</strong></span>
                            <datalist id="mainwp-test-sites">
                               <?php
                                $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
                                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                                {
                                    echo '<option>'.$website->url.'</option>';
                                }
                                @MainWPDB::free_result($websites);
                               ?>
                            </datalist>
                        </td>
                    </tr>
                    <tr class="form-field form-required">
                       <th scope="row"><?php _e('Verify certificate','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.','mainwp')); ?></th>
                        <td>
                            <select id="mainwp_managesites_test_verifycertificate" name="mainwp_managesites_test_verifycertificate">
                                 <option selected value="1"><?php _e('Yes','mainwp'); ?></option>
                                 <option value="0"><?php _e('No','mainwp'); ?></option>
                                 <option value="2"><?php _e('Use Global Setting','mainwp'); ?></option>
                             </select> <i>(Default: Yes)</i>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="button" name="mainwp_managesites_test"
                                         id="mainwp_managesites_test"
                                         class="button-primary" value="<?php _e('Test Connection','mainwp'); ?>"/></p>
            </form>
        </div>
    </div>
    <?php
    }

    public static function renderBulkUpload()
    {
        ?>
            <div id="MainWPBulkUploadSitesLoading" class="updated" style="display: none;">
                <div><img src="images/loading.gif"/> <?php _e('Importing sites','mainwp'); ?></div>
            </div>
            <?php
            $errors = array();
            if ($_FILES['mainwp_managesites_file_bulkupload']['error'] == UPLOAD_ERR_OK)
            {
                if (is_uploaded_file($_FILES['mainwp_managesites_file_bulkupload']['tmp_name']))
                {
                    $content = file_get_contents($_FILES['mainwp_managesites_file_bulkupload']['tmp_name']);
                    $lines = explode("\r", $content);

                    if (is_array($lines) && (count($lines) > 0))
                    {
                        $i = 0;
                        if ($_POST['mainwp_managesites_chk_header_first'])
                        {
                            $header_line = trim(trim($lines[0]), '"') . "\n";
                            unset($lines[0]);
                        }

                        foreach ($lines as $line)
                        {
                            $line = trim($line);
                            $items = explode(',', $line);
//                                    $group_name = "";
//
//                                    if (trim($items[3]) != '')
//                                        $group_name = "," . trim($items[3]);

                            $line = trim(trim($items[0]), '"') . "," . trim(trim($items[1]), '"') . "," . trim(trim($items[2]), '"') . ',' . trim(trim($items[3]), '"') . ',' . trim(trim($items[4]), '"');

                            ?>
                            <input type="hidden"
                                   id="mainwp_managesites_import_csv_line_<?php echo ($i + 1) // start from 1 ?>"
                                   value="<?php echo $line ?>"/>
                            <?php
                            $i++;
                        }

                        ?>
                        <div class="mainwp_info-box"><strong><?php _e('Importing new sites.','mainwp'); ?></strong></div>
                        <input type="hidden" id="mainwp_managesites_do_import" value="1"/>
                        <input type="hidden" id="mainwp_managesites_total_import" value="<?php echo $i ?>"/>

                        <p>
                        <div class="mainwp_managesites_import_listing" id="mainwp_managesites_import_logging">
                            <pre class="log"><?php echo $header_line; ?></pre>
                        </div></p>

                        <p class="submit"><input type="button" name="mainwp_managesites_btn_import"
                                                 id="mainwp_managesites_btn_import"
                                                 class="button-primary" value="<?php _e('Pause','mainwp'); ?>"/>
                            <input type="button" name="mainwp_managesites_btn_save_csv"
                                   id="mainwp_managesites_btn_save_csv" disabled="disabled"
                                   class="button-primary" value="<?php _e('Save failed','mainwp'); ?>"/>
                        </p>

                        <p>
                        <div class="mainwp_managesites_import_listing"
                             id="mainwp_managesites_import_fail_logging" style="display: none;">
                            <pre class="log"><?php echo $header_line; ?></pre>
                        </div></p>
                        <?php
                    }
                    else
                    {
                        $errors[] = "Error: Data is not valid. <br />";
                    }
                }
                else
                {
                    $errors[] = "Error: Upload error. <br />";
                }
            }
            else
            {
                $errors[] = "Error: Upload error. <br />";
            }


            if (count($errors) > 0)
            {
                ?>
                <div class="error below-h2">
                    <?php foreach ($errors as $error)
                { ?>
                    <p><strong>ERROR</strong>: <?php echo $error ?></p>
                    <?php } ?>
                </div>
                <br/>
                <a href="<?php echo get_admin_url() ?>admin.php?page=managesites" class="add-new-h2" target="_top"><?php _e('Add
                    New','mainwp'); ?></a>
                <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return
                    to Dashboard','mainwp'); ?></a>
                <?php
            }
    }

    public static function _renderNewSite(&$groups)
    {
        ?>
       <div id="mainwp_managesites_add_errors" class="mainwp_error"></div>
       <div id="mainwp_managesites_add_message" class="mainwp_updated updated"></div>
       <div class="postbox" id="mainwp-add-a-single-site">
       <h3 class="mainwp_box_title"><span><?php _e('Add a Single Site','mainwp'); ?></span></h3>
       <div class="inside">
       <div id="mainwp-add-site-notice-show" style="display: none; text-align: left;"><a href="#" class="button mainwp-button-red" id="mainwp-add-site-notice-show-link"><?php _e('Having trouble adding your site?','mainwp'); ?></a></div>
       <div id="mainwp-add-site-notice" class="mainwp_info-box-red" style="padding: 1em">
         <p>
           <?php _e('If you are having trouble adding your site please use the Test Connection tab. This tells you the header response being received by your dashboard from that child site. The Test Connection feature is specifically testing what your Dashboard can "see" and what your Dashboard "sees" and what my Dashboard "sees" or what your browser "sees" can be completely different things.','mainwp'); ?>
         </p>
         <p>
           <strong><?php _e('The two most common reasons for sites not being added are:','mainwp'); ?></strong>
           <ol>
             <li><?php _e('You have a Security Plugin blocking the connection. If you have a security plugin installed and are having an issue please check the <a href="http://docs.mainwp.com/known-plugin-conflicts/" style="text-decoration: none;">Plugin Conflict page</a> for how to resolve.','mainwp'); ?></li>
             <li><?php _e('Your Dashboard is on the same host as your Child site. Some hosts will not allow two sites on the same server to communicate with each other. In this situation you would contact your host for assistance or move your Dashboard or Child site to a different host.','mainwp'); ?></li>
           </ol>
         </p>
         <p style="text-align: center;"><a href="#" class="button button-primary" style="text-decoration: none;" id="mainwp-add-site-notice-dismiss"><?php _e('Hide this message','mainwp'); ?></a></p>
       </div>

       <form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_add_form">
           <table class="form-table">
               <tr class="form-field form-required">
                   <th scope="row"><?php _e('Site Name:','mainwp'); ?></th>
                   <td><input type="text" id="mainwp_managesites_add_wpname"
                              name="mainwp_managesites_add_wpname"
                              value=""/></td>
               </tr>
               <tr class="form-field form-required">
                   <th scope="row"><?php _e('Site URL:','mainwp'); ?></th>
                   <td><input type="text" id="mainwp_managesites_add_wpurl"
                              name="mainwp_managesites_add_wpurl"
                              value="http://"/><span class="mainwp-form_hint">Proper format "http://address.com/"</span></td>
               </tr>
               <tr class="form-field form-required">
                   <th scope="row"><?php _e('Administrator Username:','mainwp'); ?></th>
                   <td><input type="text" id="mainwp_managesites_add_wpadmin"
                              name="mainwp_managesites_add_wpadmin" value=""/></td>
               </tr>
               <tr class="form-field form-required">
                   <th scope="row"><?php _e('Child Unique Security
                       ID: ','mainwp'); ?><?php MainWPUtility::renderToolTip('The Unique Security ID adds additional protection between the Child plugin and your Main Dashboard. The Unique Security ID will need to match when being added to the Main Dashboard. This is additional security and should not be needed in most situations.'); ?></th>
                   <td><input type="text" id="mainwp_managesites_add_uniqueId"
                              name="mainwp_managesites_add_uniqueId" value=""/><span class="mainwp-form_hint">The Unique Security ID adds additional protection between the Child plugin and your Main Dashboard. The Unique Security ID will need to match when being added to the Main Dashboard. This is additional security and should not be needed in most situations.</span></td>
               </tr>
               <tr>
                   <th scope="row"><?php _e('Groups','mainwp'); ?></th>
                   <td>
                       <input type="text" name="mainwp_managesites_add_addgroups"
                              id="mainwp_managesites_add_addgroups" value=""
                              class="regular-text"/> <span
                           class="mainwp-form_hint">Separate groups by commas (e.g. Group 1, Group 2).</span>

                       <div id="selected_groups" style="display: block; width: 25em">
                           <?php
                           if (count($groups) == 0)
                           {
                               echo 'No groups added yet.';
                           }
                           foreach ($groups as $group)
                           {
                               echo '<div class="mainwp_selected_groups_item"><input type="checkbox" name="selected_groups[]" value="' . $group->id . '" /> &nbsp ' . $group->name . '</div>';
                           }
                           ?>
                       </div>
                       <span class="description"><?php _e('Or assign existing groups.','mainwp'); ?></span>
                   </td>
               </tr>
               </table>
               </div>
               </div>
                
            <div class="postbox" id="mainwp-managesites-adv-options">
                <h3 class="mainwp_box_title"><span><?php _e('Advanced Options','mainwp'); ?></span></h3>
                <div class="inside">
                    <table class="form-table">
                            <tr class="form-field form-required">
                            <th scope="row"><?php _e('Verify certificate','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.','mainwp')); ?></th>
                            <td>
                                <select id="mainwp_managesites_verify_certificate" name="mainwp_managesites_verify_certificate">
                                     <option selected value="1"><?php _e('Yes','mainwp'); ?></option>
                                     <option value="0"><?php _e('No','mainwp'); ?></option>
                                     <option value="2"><?php _e('Use Global Setting','mainwp'); ?></option>
                                 </select> <i>(Default: Yes)</i>
                            </td>
                            </tr>
                    </table>
                    </div>
                </div>

               <div class="postbox" id="mainwp-bulk-upload-sites">
               <h3 class="mainwp_box_title"><span><?php _e('Bulk Upload','mainwp'); ?></span></h3>
               <div class="inside">
               <table>
                   <th scope="row"></th>
                   <td>
                       <input type="file" name="mainwp_managesites_file_bulkupload"
                              id="mainwp_managesites_file_bulkupload"
                              accept="text/comma-separated-values"
                              class="regular-text" disabled="disabled"/>
                      <span
                              class="description"><?php _e('File must be in CSV format.','mainwp'); ?> <a
                              href="<?php echo plugins_url('csv/sample.csv', dirname(__FILE__)); ?>"><?php _e('Click
                          here to download sample CSV file.','mainwp'); ?></a></span>

                       <div>
                           <p>
                               <input type="checkbox" name="mainwp_managesites_chk_bulkupload"
                                      id="mainwp_managesites_chk_bulkupload" value="1"/>
                               <span class="description"><?php _e('Upload file','mainwp'); ?></span>
                           </p>

                           <p>
                               <input type="checkbox" name="mainwp_managesites_chk_header_first"
                                      disabled="disabled" checked="checked"
                                      id="mainwp_managesites_chk_header_first" value="1"/>
                               <span class="description"><?php _e('CSV file contains a header.','mainwp'); ?></span>
                           </p>
                       </div>
                   </td>
           </table>
           </div>
           </div>
        
       

           <p class="submit"><input type="button" name="mainwp_managesites_add"
                                    id="mainwp_managesites_add"
                                    class="button-primary" value="<?php _e('Add New Site','mainwp'); ?>"/></p>
       </form>
<?php
    }

    public static function renderSeoPage(&$website)
      {
          ?>
      <div class="wrap"><a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
              src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP"/></a>
          <img src="<?php echo plugins_url('images/icons/mainwp-sites.png', dirname(__FILE__)); ?>"
               style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Sites" height="32"/>

          <h2><?php echo $website->name; ?> (<?php echo $website->url; ?>)</h2>

          <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
          <div id="ajax-information-zone" class="updated" style="display: none;"></div>
          <div id="mainwp_background-box">
              <?php
              if ($website->statsUpdate == 0)
              {
                  ?>
                  <h3><?php _e('SEO Details','mainwp'); ?></h3>
                  <?php _e('Not updated yet.','mainwp'); ?>
                  <?php
              }
              else
              {
                  ?>
                  <h3><?php _e('SEO Details','mainwp'); ?> (Last Updated <?php echo MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($website->statsUpdate)); ?>)</h3>
                  <?php
                  if (get_option('mainwp_seo') == 0 ) {
                    ?>
                    <div class="mainwp_info-box-red"><?php _e('Basic SEO turned Off. <strong>Historic Information Only</strong>. You can turn back on in the <a href="admin.php?page=Settings">Settings page</a>.','mainwp'); ?></div>
                    <?php
                  }
                  ?>
                  <table>
                      <tr>
                          <th style="text-align: left; width: 180px;">Alexa Rank:</th>
                          <td><?php echo $website->alexia; ?> <?php echo ($website->alexia_old != '' ? '(' . $website->alexia_old . ')' : ''); ?></td>
                      </tr>
                      <tr>
                          <th style="text-align: left">Google Page Rank:</th>
                          <td><?php echo $website->pagerank; ?> <?php echo ($website->pagerank_old != '' ? '(' . $website->pagerank_old . ')' : ''); ?></td>
                      </tr>
                      <tr>
                          <th style="text-align: left">Indexed Links on Google:</th>
                          <td><?php echo $website->indexed; ?> <?php echo ($website->indexed_old != '' ? '(' . $website->indexed_old . ')' : ''); ?></td>
                      </tr>
                  </table>
                  <?php
              }
              ?>
          </div>
      </div>
      <?php
      }

      public static function showSEOWidget(&$website) {
      if ($website->statsUpdate == 0)
              {
                  echo $website->url ?> &nbsp;-&nbsp; <em><?php _e('Not updated yet','mainwp'); ?></em> <?php
              }
              else
              {


      echo $website->url; ?> &nbsp;-&nbsp; <em><?php _e('Last Updated','mainwp'); ?> <?php echo MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($website->statsUpdate)); ?></em>
      <br /><br />
      <table>
        <tr>
          <th style="text-align: left; width: 300px;">Alexa Rank:</th>
          <?php if ($website->alexia > $website->alexia_old) { ?> <td style="width: 100px" class="mainwp-seo-up"><?php echo $website->alexia; ?></td><?php } else if ($website->alexia === $website->alexia_old) { ?><td style="width: 100px" class="mainwp-seo-same"><?php echo $website->alexia; ?></td><?php } else { ?>
          <td style="width: 100px" class="mainwp-seo-down"><?php echo $website->alexia; ?></td>
          <?php } ?>
          <td style="width: 100px; color: #7B848B;"><?php echo ($website->alexia_old != '' ? $website->alexia_old : ''); ?></td>
        </tr>
        <tr>
          <th style="text-align: left; width: 300px;">Google Page Rank:</th>
          <?php if ($website->pagerank > $website->pagerank_old) { ?> <td style="width: 100px" class="mainwp-seo-up"><?php echo $website->pagerank; ?></td><?php } else if ($website->pagerank === $website->pagerank_old) { ?><td style="width: 100px" class="mainwp-seo-same"><?php echo $website->pagerank; ?></td><?php } else { ?>
          <td style="width: 100px" class="mainwp-seo-down"><?php echo $website->pagerank; ?></td>
          <?php } ?>
          <td style="width: 100px; color: #7B848B;"><?php echo ($website->pagerank_old != '' ? $website->pagerank_old : ''); ?></td>
        </tr>
        <tr>
          <th style="text-align: left; width: 300px;">Indexed Links on Google:</th>
          <?php if ($website->indexed > $website->indexed_old) { ?> <td style="width: 100px" class="mainwp-seo-up"><?php echo $website->indexed; ?></td><?php } else if ($website->indexed === $website->indexed_old) { ?><td style="width: 100px" class="mainwp-seo-same"><?php echo $website->indexed; ?></td><?php } else { ?>
          <td style="width: 100px" class="mainwp-seo-down"><?php echo $website->indexed; ?></td>
          <?php } ?>
          <td style="width: 100px; color: #7B848B;"><?php echo ($website->indexed_old != '' ? $website->indexed_old : ''); ?></td>
        </tr>
      </table>

      <?php
    }
    }

    public static function showBackups(&$website, $fullBackups, $dbBackups)
    {
        $output = '';
        echo '<table>';

        $mwpDir = MainWPUtility::getMainWPDir();
        $mwpDir = $mwpDir[0];
        foreach ($fullBackups as $key => $fullBackup)
        {
            $downloadLink = admin_url('?sig=' . md5(filesize($fullBackup)) . '&mwpdl=' . rawurlencode(str_replace($mwpDir, "", $fullBackup)));
            $output .= '<tr><td style="width: 400px;">' . MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp(filemtime($fullBackup))) . ' - ' . MainWPUtility::human_filesize(filesize($fullBackup));
            $output .= '</td><td><a title="'.basename($fullBackup).'" href="' . $downloadLink . '" class="button">Download</a></td>';
            $output .= '<td><a href="admin.php?page=SiteRestore&websiteid=' . $website->id . '&f=' . base64_encode($downloadLink) . '&size='.filesize($fullBackup).'" class="mainwp-upgrade-button button" target="_blank" title="' . basename($fullBackup) . '">Restore</a></td></tr>';
        }
        if ($output == '') echo '<br />' . __('No full backup has been taken yet','mainwp') . '<br />';
        else echo '<strong style="font-size: 14px">'. __('Last backups from your files:','mainwp') . '</strong>' . $output;

        echo '</table><br/><table>';

        $output = '';
        foreach ($dbBackups as $key => $dbBackup)
        {
            $downloadLink = admin_url('?sig=' . md5(filesize($dbBackup)) . '&mwpdl=' . rawurlencode(str_replace($mwpDir, "", $dbBackup)));
            $output .= '<tr><td style="width: 400px;">' . MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp(filemtime($dbBackup))) . ' - ' . MainWPUtility::human_filesize(filesize($dbBackup)) . '</td><td><a title="'.basename($dbBackup).'" href="' . $downloadLink . '" download class="button">Download</a></td></tr>';
        }
        if ($output == '') echo '<br />'. __('No database only backup has been taken yet','mainwp') . '<br /><br />';
        else echo '<strong style="font-size: 14px">'. __('Last backups from your database:','mainwp') . '</strong>' . $output;
        echo '</table>';
    }


    public static function renderSettings()
    {
        $backupsOnServer = get_option('mainwp_backupsOnServer');
        $backupOnExternalSources = get_option('mainwp_backupOnExternalSources');
        $archiveFormat = get_option('mainwp_archiveFormat');
        $maximumFileDescriptors = get_option('mainwp_maximumFileDescriptors');
        $maximumFileDescriptorsAuto = get_option('mainwp_maximumFileDescriptorsAuto');
        $maximumFileDescriptorsAuto = ($maximumFileDescriptorsAuto == 1 || $maximumFileDescriptorsAuto === false);

        $notificationOnBackupFail = get_option('mainwp_notificationOnBackupFail');
        $notificationOnBackupStart = get_option('mainwp_notificationOnBackupStart');
        $chunkedBackupTasks = get_option('mainwp_chunkedBackupTasks');

        $loadFilesBeforeZip = get_option('mainwp_options_loadFilesBeforeZip');
        $loadFilesBeforeZip = ($loadFilesBeforeZip == 1 || $loadFilesBeforeZip === false);
        ?>
    <div class="postbox" id="mainwp-backup-options-settings">
    <h3 class="mainwp_box_title"><span>Backup Options</span></h3>
    <div class="inside">
    <table class="form-table">
        <tbody>
        <tr>
            <th scope="row">Backups on Server <?php MainWPUtility::renderToolTip('The number of backups to keep on your server.  This does not affect external sources.', 'http://docs.mainwp.com/recurring-backups-with-mainwp/'); ?></th>
            <td>
                <input type="text" name="mainwp_options_backupOnServer"
                       value="<?php echo ($backupsOnServer === false ? 1 : $backupsOnServer); ?>"/><span class="mainwp-form_hint"><?php _e('The number of backups to keep on your server.  This does not affect external sources.','mainwp'); ?></span>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Backups on external sources','mainwp'); ?> <?php MainWPUtility::renderToolTip('The number of backups to keep on your external sources.  This does not affect backups on the server.  0 sets unlimited.', 'http://docs.mainwp.com/recurring-backups-with-mainwp/'); ?></th>
            <td>
                <input type="text" name="mainwp_options_backupOnExternalSources"
                       value="<?php echo ($backupOnExternalSources === false ? 1 : $backupOnExternalSources); ?>"/><span class="mainwp-form_hint"><?php _e('The number of backups to keep on your external sources.  This does not affect backups on the server.  0 sets unlimited.','mainwp'); ?></span>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Archive format','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('','mainwp')); ?></th>
            <td>
                <table class="mainwp-nomarkup">
                    <tr>
                        <td valign="top">
                            <span class="mainwp-select-bg"><select name="mainwp_archiveFormat" id="mainwp_archiveFormat">
                                <option value="zip" <?php if ($archiveFormat == 'zip'): ?>selected<?php endif; ?>>Zip</option>
                                <option value="tar" <?php if ($archiveFormat == 'tar'): ?>selected<?php endif; ?>>Tar</option>
                                <option value="tar.gz" <?php if (($archiveFormat === false) || ($archiveFormat == 'tar.gz')): ?>selected<?php endif; ?>>Tar GZip</option>
                                <option value="tar.bz2" <?php if ($archiveFormat == 'tar.bz2'): ?>selected<?php endif; ?>>Tar BZip2</option>
                            </select><label></label></span>
                        </td>
                        <td>
                            <i>
                            <span id="info_zip" class="archive_info" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)</span>
                            <span id="info_tar" class="archive_info" <?php if ($archiveFormat != 'tar'): ?>style="display: none;"<?php endif; ?>>Creates an uncompressed tar-archive. (No compression, fast, low memory usage)</span>
                            <span id="info_tar.gz" class="archive_info" <?php if ($archiveFormat != 'tar.gz' && $archiveFormat !== false): ?>style="display: none;"<?php endif; ?>>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)</span>
                            <span id="info_tar.bz2" class="archive_info" <?php if ($archiveFormat != 'tar.bz2'): ?>style="display: none;"<?php endif; ?>>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)</span>
                            </i>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr class="archive_method archive_zip" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>
            <th scope="row"><?php _e('Maximum File Descriptors on Child','mainwp'); ?> <?php MainWPUtility::renderToolTip('The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/'); ?></th>
            <td>
                <div style="float: left">Auto detect:&nbsp;</div><div class="mainwp-checkbox"><input type="checkbox" id="mainwp_maximumFileDescriptorsAuto" name="mainwp_maximumFileDescriptorsAuto" <?php echo ($maximumFileDescriptorsAuto ? 'checked="checked"' : ''); ?> /> <label for="mainwp_maximumFileDescriptorsAuto"></label></div><div style="float: left"><i>(<?php _e('Enter a fallback value because not all hosts support this function.','mainwp'); ?>)</i></div><div style="clear:both"></div>
                <input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors"
                       value="<?php echo ($maximumFileDescriptors === false ? 150 : $maximumFileDescriptors); ?>"/><span class="mainwp-form_hint"><?php _e('The maximum number of open file descriptors on the child hosting.  0 sets unlimited.','mainwp'); ?></span>
            </td>
        </tr>
        <tr class="archive_method archive_zip" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>
            <th scope="row"><?php _e('Load files in memory before zipping','mainwp');?> <?php MainWPUtility::renderToolTip('This causes the files to be opened and closed immediately, using less simultaneous I/O operations on the disk. For huge sites with a lot of files we advise to disable this, memory usage will drop but we will use more file handlers when backing up.', 'http://docs.mainwp.com/load-files-memory/'); ?></th>
            <td>
                <div class="mainwp-checkbox">
                <input type="checkbox" id="mainwp_options_loadFilesBeforeZip" name="mainwp_options_loadFilesBeforeZip" <?php echo ($loadFilesBeforeZip ? 'checked="checked"' : ''); ?>"/>
                <label for="mainwp_options_loadFilesBeforeZip"></label>
                </div>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <?php _e('Send Email when a backup fails','mainwp'); ?></th>
                <td>
                  <div class="mainwp-checkbox">
                    <input type="checkbox" id="mainwp_options_notificationOnBackupFail" name="mainwp_options_notificationOnBackupFail"  <?php echo ($notificationOnBackupFail == 0 ? '' : 'checked="checked"'); ?> "/>
                    <label for="mainwp_options_notificationOnBackupFail"></label>
                  </div>
               </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Send Email when a backup starts','mainwp'); ?></th>
               <td>
                 <div class="mainwp-checkbox">
                   <input type="checkbox" id="mainwp_options_notificationOnBackupStart" name="mainwp_options_notificationOnBackupStart"  <?php echo ($notificationOnBackupStart == 0 ? '' : 'checked="checked"'); ?> "/>
                   <label for="mainwp_options_notificationOnBackupStart"></label>
                </div>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Execute backuptasks in chunks','mainwp'); ?></th>
               <td>
                 <div class="mainwp-checkbox">
                   <input type="checkbox" id="mainwp_options_chunkedBackupTasks" name="mainwp_options_chunkedBackupTasks"  <?php echo ($chunkedBackupTasks == 0 ? '' : 'checked="checked"'); ?> />
                   <label for="mainwp_options_chunkedBackupTasks"></label>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
    </div>
    </div>
    <?php
    }


    public static function renderDashboard(&$website, &$page)
    {
        ?>
            <div id="howto-metaboxes-general" class="wrap">
                <?php
                if ($website->mainwpdir == -1)
                {
                    echo '<div class="mainwp_info-box-yellow"><span class="mainwp_conflict" siteid="' . $website->id . '"><strong>Configuration issue detected</strong>: MainWP has no write privileges to the uploads directory. Because of this some of the functionality might not work, please check <a href="http://docs.mainwp.com/install-or-update-of-a-plugin-fails-on-managed-site/" target="_blank">this FAQ for further information</a></span></div>';
                }
                $userExtension = MainWPDB::Instance()->getUserExtension();

                $ignoredPluginConflicts = json_decode($website->ignored_pluginConflicts, true);
                if (!is_array($ignoredPluginConflicts)) $ignoredPluginConflicts = array();
                $globalIgnoredPluginConflicts = json_decode($userExtension->ignored_pluginConflicts, true);
                if (!is_array($globalIgnoredPluginConflicts)) $globalIgnoredPluginConflicts = array();
                $ignoredThemeConflicts = json_decode($website->ignored_themeConflicts, true);
                if (!is_array($ignoredThemeConflicts)) $ignoredThemeConflicts = array();
                $globalIgnoredThemeConflicts = json_decode($userExtension->ignored_themeConflicts, true);
                if (!is_array($globalIgnoredThemeConflicts)) $globalIgnoredThemeConflicts = array();

                $pluginConflicts = json_decode($website->pluginConflicts, true);
                $themeConflicts = json_decode($website->themeConflicts, true);
                $savedPluginConflicts = get_option('mainwp_pluginConflicts');
                $savedThemeConflicts = get_option('mainwp_themeConflicts');

                $startShown = false;
                $found = false;
                if (count($pluginConflicts) > 0)
                {
                    foreach ($pluginConflicts as $pluginConflict)
                    {
                        if (in_array($pluginConflict, $ignoredPluginConflicts) || in_array($pluginConflict, $globalIgnoredPluginConflicts)) continue;

                        if (!$startShown) { echo '<div class="mainwp_info-box-yellow">'; $startShown = true; }

                        if (!$found)
                        {
                            $found = true;
                        }
                        else
                        {
                            echo '<br />';
                        }
                        echo '<span class="mainwp_conflict" siteid="' . $website->id . '" plugin="' . urlencode($pluginConflict) . '"><strong>Conflict Plugin Detected</strong>: MainWP has found "' . $pluginConflict . '" installed on this site, please check <a href="' . $savedPluginConflicts[$pluginConflict] . '">this FAQ for further information</a> - <strong><a href="#" class="mainwp_conflict_ignore">Ignore</a></strong> - <strong><a href="#" class="mainwp_conflict_ignore_globally">Ignore Globally</a></strong></span>';
                    }
                }

                if (count($themeConflicts) > 0)
                {
                    foreach ($themeConflicts as $themeConflict)
                    {
                        if (in_array($themeConflict, $ignoredThemeConflicts) || in_array($themeConflict, $globalIgnoredThemeConflicts)) continue;

                        if (!$startShown) { echo '<div class="mainwp_info-box-yellow">'; $startShown = true; }

                        if (!$found)
                        {
                            $found = true;
                        }
                        else
                        {
                            echo '<br />';
                        }
                        echo '<span class="mainwp_conflict" siteid="' . $website->id . '" theme="' . urlencode($themeConflict) . '"><strong>Conflict Theme Detected</strong>: MainWP has found "' . $themeConflict . '" installed on this site, please check <a href="' . $savedThemeConflicts[$themeConflict] . '">this FAQ for further information</a> - <strong><a href="#" class="mainwp_conflict_ignore">Ignore</a></strong> - <strong><a href="#" class="mainwp_conflict_ignore_globally">Ignore Globally</a></strong></span>';
                    }
                }
                if ($startShown) echo '</div>';

                global $screen_layout_columns;
                MainWPMain::renderDashboardBody(array($website), $page, $screen_layout_columns);
                ?>
            </div>
    <?php
    }

    public static function renderBackupSite(&$website)
    {
        $remote_destinations = apply_filters('mainwp_backups_remote_get_destinations', null, array('website' => $website->id));
        $hasRemoteDestinations = ($remote_destinations == null ? $remote_destinations : count($remote_destinations));
        ?>
    <div class="wrap"><a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
            src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP"/></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-sites.png', dirname(__FILE__)); ?>"
             style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Sites" height="32"/>
        <h2>Backup <?php echo $website->name; ?></h2>

        <?php
//        if ($website->totalsize > 100)
//        {
//            ?>
<!--            <div class="mainwp_info-box-yellow">--><?php //_e('A full backup might fail because the total file size of this website is','mainwp'); ?><!-- --><?php //echo $website->totalsize; ?><!----><?php //_e('MB, you could exclude folders to decrease the filesize.','mainwp'); ?><!--</div>-->
<!--            --><?php
//        }
        ?>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <div id="ajax-information-zone" class="updated" style="display: none;"></div>
        <div id="mainwp_background-box">
        	<div class="postbox" id="mainwp-backup-details">
                <h3 class="mainwp_box_title"><span><?php _e('Backup Details','mainwp'); ?></span></h3>
                <div class="inside">
                <?php
                if (!MainWPUtility::can_edit_website($website))
                {
                    die('This is not your website.');
                }

                MainWPManageSites::showBackups($website);
                ?>
                </div>
            </div>
            <div class="postbox" id="mainwp-backup-optins-site">
            <h3 class="mainwp_box_title"><span><?php _e('Backup Options','mainwp'); ?></span></h3>
            <div class="inside">
            <form method="POST" action="" id="mainwp_backup_sites_page">
            <table class="form-table">
                <tbody>
                <tr>
                    <th scope="row"><?php _e('Backup File Name:','mainwp'); ?></th>
                    <td><input type="text" name="backup_filename" id="backup_filename" value="" /><span class="mainwp-form_hint" style="display: inline; max-width: 500px;"><?php _e('Allowed Structure Tags:','mainwp'); ?> <strong>%sitename%</strong>, <strong>%url%</strong>, <strong>%date%</strong>, <strong>%time%</strong>, <strong>%type%</strong></span>
                    </td>
                </tr>
                <tr><td colspan="2"><hr /></td></tr>
                <tr>
                    <th scope="row"><?php _e('Backup Type:','mainwp'); ?></th>
                    <td>
                        <a class="mainwp_action left mainwp_action_down" href="#" id="backup_type_full"><?php _e('FULL BACKUP','mainwp'); ?></a><a class="mainwp_action right" href="#" id="backup_type_db"><?php _e('DATABASE BACKUP','mainwp'); ?></a>
                    </td>
                </tr>
                <tr class="mainwp_backup_exclude_files_content"><td colspan="2"><hr /></td></tr>
                <tr class="mainwp-exclude-suggested">
                    <th scope="row" style="vertical-align: top"><?php _e('Suggested Exclude', 'mainwp'); ?>:</th>
                    <td><p style="background: #7fb100; color: #ffffff; padding: .5em;"><?php _e('Every WordPress website is different but the sections below generally do not need to be backed up and since many of them are large in size they can even cause issues with your backup including server timeouts.', 'mainwp'); ?></p></td>
                </tr>
                <tr class="mainwp-exclude-backup-locations">
                    <td colspan="2"><h4><?php _e('Known Backup Locations', 'mainwp'); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-backup-locations">
                    <td><label for="mainwp-known-backup-locations"><?php _e('Exclude', 'mainwp'); ?></label><input type="checkbox" id="mainwp-known-backup-locations" checked></td>
                    <td class="mainwp-td-des"><a href="#" id="mainwp-show-kbl-folders"><?php _e('+ Show Excluded Folders', 'mainwp'); ?></a><a href="#" id="mainwp-hide-kbl-folders"><?php _e('- Hide Excluded Folders', 'mainwp'); ?></a><br/>
                        <textarea id="mainwp-kbl-content" disabled></textarea>
                        <br/><?php _e('This adds known backup locations of popular WordPress backup plugins to the exclude list.  Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp'); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp-exclude-cache-locations">
                    <td colspan="2"><h4><?php _e('Known Cache Locations', 'mainwp'); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-cache-locations">
                    <td><label for="mainwp-known-cache-locations"><?php _e('Exclude', 'mainwp'); ?></label><input type="checkbox" id="mainwp-known-cache-locations" checked></td>
                    <td class="mainwp-td-des"><a href="#" id="mainwp-show-kcl-folders"><?php _e('+ Show Excluded Folders', 'mainwp'); ?></a><a href="#" id="mainwp-hide-kcl-folders"><?php _e('- Hide Excluded Folders', 'mainwp'); ?></a><br/>
                        <textarea id="mainwp-kcl-content" disabled></textarea>
                        <br/><?php _e('This adds known cache locations of popular WordPress cache plugins to the exclude list.  A cache can be massive with thousands of files and can cause your current MainWP backup to timeout.  Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp'); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp-exclude-nonwp-folders">
                    <td colspan="2"><h4><?php _e('Non-WordPress Folders', 'mainwp'); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-nonwp-folders">
                    <td><label for="mainwp-non-wordpress-folders"><?php _e('Exclude', 'mainwp'); ?></label><input type="checkbox" id="mainwp-non-wordpress-folders" checked></td>
                    <td class="mainwp-td-des"><a href="#" id="mainwp-show-nwl-folders"><?php _e('+ Show Excluded Folders', 'mainwp'); ?></a><a href="#" id="mainwp-hide-nwl-folders"><?php _e('- Hide Excluded Folders', 'mainwp'); ?></a><br/>
                        <textarea id="mainwp-nwl-content" disabled></textarea>
                        <br/><?php _e('This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp'); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp-exclude-zips">
                    <td colspan="2"><h4><?php _e('ZIP Archives', 'mainwp'); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-zips">
                    <td><label for="mainwp-zip-archives"><?php _e('Exclude', 'mainwp'); ?></label><input type="checkbox" id="mainwp-zip-archives" checked></td>
                    <td class="mainwp-td-des"><?php _e('Zip files can be large and are often not needed for a WordPress backup. Be sure to deselect this option if you do have zip files you need backed up.', 'mainwp'); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp_backup_exclude_files_content">
                    <th scope="row" style="vertical-align: top"><h4 class="mainwp-custom-excludes"><?php _e('Custom Excludes', 'mainwp'); ?></h4></th>
                    <td>
                        <p style="background: #7fb100; color: #ffffff; padding: .5em;"><?php _e('Exclude any additional files that you do not need backed up for this site. Click a folder name to drill down into the directory.', 'mainwp'); ?></p>
                        <br />
                        <?php printf(__('Click directories to navigate. Click the red sign ( <img style="margin-bottom: -3px;" src="%s"> ) to exclude a folder.','mainwp'), plugins_url('images/exclude.png', dirname(__FILE__))); ?><br /><br />
                        <table class="mainwp_excluded_folders_cont">
                            <tr>
                                <td style="width: 280px;">
                                    <div id="backup_exclude_folders"
                                         siteid="<?php echo $website->id; ?>"
                                         class="mainwp_excluded_folders"></div>
                                </td>
                                <td>
                                    <?php _e('Excluded files & directories:','mainwp'); ?><br/>
                                    <textarea id="excluded_folders_list"></textarea>
                                </td>
                            </tr>
                        </table>
                        <span class="description"><strong><?php _e('ATTENTION:','mainwp'); ?></strong> <?php _e('Do not exclude any folders if you are using this backup to clone or migrate the wordpress installation.','mainwp'); ?></span>
                    </td>
                </tr>
                <?php
                if ($hasRemoteDestinations !== null)
                {
                ?>
                <tr><td colspan="2"><hr /></td></tr>
                <tr>
                    <th scope="row"><?php _e('Store Backup In:','mainwp'); ?></th>
                    <td>
                        <a class="mainwp_action left <?php echo (!$hasRemoteDestinations ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_location_local"><?php _e('LOCAL SERVER ONLY','mainwp'); ?></a><a class="mainwp_action right <?php echo ($hasRemoteDestinations ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_location_remote"><?php _e('REMOTE DESTINATION','mainwp'); ?></a>
                    </td>
                </tr>
                <tr class="mainwp_backup_destinations" <?php echo (!$hasRemoteDestinations ? 'style="display: none;"' : ''); ?>>
                    <th scope="row"><?php _e('Backup Subfolder:','mainwp'); ?></th>
                    <td><input type="text" id="backup_subfolder" name="backup_subfolder"
                                                           value="MainWP Backups/%url%/%type%/%date%"/><span class="mainwp-form_hint" style="display: inline; max-width: 500px;">Allowed Structure Tags: <strong>%sitename%</strong>, <strong>%url%</strong>, <strong>%date%</strong>, <strong>%task%</strong>, <strong>%type%</strong></span></td>
                </tr>
                <?php
                }
                ?>
                    <?php do_action('mainwp_backups_remote_settings', array('website' => $website->id)); ?>

                <?php
                $globalArchiveFormat = get_option('mainwp_archiveFormat');
                if ($globalArchiveFormat == false) $globalArchiveFormat = 'tar.gz';
                if ($globalArchiveFormat == 'zip')
                {
                    $globalArchiveFormatText = 'Zip';
                }
                else if ($globalArchiveFormat == 'tar')
                {
                    $globalArchiveFormatText = 'Tar';
                }
                else if ($globalArchiveFormat == 'tar.gz')
                {
                    $globalArchiveFormatText = 'Tar GZip';
                }
                else if ($globalArchiveFormat == 'tar.bz2')
                {
                    $globalArchiveFormatText = 'Tar BZip2';
                }

                $backupSettings = MainWPDB::Instance()->getWebsiteBackupSettings($website->id);
                $archiveFormat = $backupSettings->archiveFormat;
                $useGlobal = ($archiveFormat == 'global');
                ?>
                <tr><td colspan="2"><hr /></td></tr>
                <tr>
                    <th scope="row"><?php _e('Archive format','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('','mainwp')); ?></th>
                    <td>
                        <table class="mainwp-nomarkup">
                            <tr>
                                <td valign="top">
                                    <span class="mainwp-select-bg"><select name="mainwp_archiveFormat" id="mainwp_archiveFormat">
                                        <option value="global" <?php if ($useGlobal): ?>selected<?php endif; ?>>Global setting (<?php echo $globalArchiveFormatText; ?>)</option>
                                        <option value="zip" <?php if ($archiveFormat == 'zip'): ?>selected<?php endif; ?>>Zip</option>
                                        <option value="tar" <?php if ($archiveFormat == 'tar'): ?>selected<?php endif; ?>>Tar</option>
                                        <option value="tar.gz" <?php if ($archiveFormat == 'tar.gz'): ?>selected<?php endif; ?>>Tar GZip</option>
                                        <option value="tar.bz2" <?php if ($archiveFormat == 'tar.bz2'): ?>selected<?php endif; ?>>Tar BZip2</option>
                                    </select><label></label></span>
                                </td>
                                <td>
                                    <i>
                                    <span id="info_global" class="archive_info" <?php if (!$useGlobal): ?>style="display: none;"<?php endif; ?>><?php
                                        if ($globalArchiveFormat == 'zip'): ?>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)<?php
                                        elseif ($globalArchiveFormat == 'tar'): ?>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)<?php
                                        elseif ($globalArchiveFormat == 'tar.gz'): ?>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)<?php
                                        elseif ($globalArchiveFormat == 'tar.bz2'): ?>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)<?php endif; ?></span>
                                    <span id="info_zip" class="archive_info" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)</span>
                                    <span id="info_tar" class="archive_info" <?php if ($archiveFormat != 'tar'): ?>style="display: none;"<?php endif; ?>>Creates an uncompressed tar-archive. (No compression, fast, low memory usage)</span>
                                    <span id="info_tar.gz" class="archive_info" <?php if ($archiveFormat != 'tar.gz'): ?>style="display: none;"<?php endif; ?>>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)</span>
                                    <span id="info_tar.bz2" class="archive_info" <?php if ($archiveFormat != 'tar.bz2'): ?>style="display: none;"<?php endif; ?>>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)</span>
                                    </i>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <?php
                $maximumFileDescriptorsOverride = ($website->maximumFileDescriptorsOverride == 1);
                $maximumFileDescriptorsAuto= ($website->maximumFileDescriptorsAuto == 1);
                $maximumFileDescriptors = $website->maximumFileDescriptors;
                ?>
                <tr class="archive_method archive_zip" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>
                    <th scope="row"><?php _e('Maximum File Descriptors on Child','mainwp'); ?> <?php MainWPUtility::renderToolTip('The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/'); ?></th>
                    <td>
                        <div class="mainwp-radio" style="float: left;">
                          <input type="radio" value="" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_global" <?php echo (!$maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?>"/>
                          <label for="mainwp_options_maximumFileDescriptorsOverride_global"></label>
                        </div>Global Setting (<a href="<?php echo admin_url('admin.php?page=Settings'); ?>">Change Here</a>)<br/>
                        <div class="mainwp-radio" style="float: left;">
                          <input type="radio" value="override" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_override" <?php echo ($maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?>"/>
                          <label for="mainwp_options_maximumFileDescriptorsOverride_override"></label>
                        </div>Override<br/><br />

                        <div style="float: left">Auto detect:&nbsp;</div><div class="mainwp-checkbox"><input type="checkbox" id="mainwp_maximumFileDescriptorsAuto" name="mainwp_maximumFileDescriptorsAuto" <?php echo ($maximumFileDescriptorsAuto ? 'checked="checked"' : ''); ?> /> <label for="mainwp_maximumFileDescriptorsAuto"></label></div><div style="float: left"><i>(<?php _e('Enter a fallback value because not all hosts support this function.','mainwp'); ?>)</i></div><div style="clear:both"></div>
                        <input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors"
                               value="<?php echo $maximumFileDescriptors; ?>"/><span class="mainwp-form_hint"><?php _e('The maximum number of open file descriptors on the child hosting.  0 sets unlimited.','mainwp'); ?></span>
                    </td>
                </tr>
                <tr class="archive_method archive_zip" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>
                    <th scope="row">Load files in memory before zipping <?php MainWPUtility::renderToolTip('This causes the files to be opened and closed immediately, using less simultaneous I/O operations on the disk. For huge sites with a lot of files we advise to disable this, memory usage will drop but we will use more file handlers when backing up.', 'http://docs.mainwp.com/load-files-memory/'); ?></th>
                    <td>
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_global" value="1" <?php if ($website->loadFilesBeforeZip == false || $website->loadFilesBeforeZip == 1): ?>checked="true"<?php endif; ?>/> Global setting (<a href="<?php echo admin_url('admin.php?page=Settings'); ?>">Change Here</a>)<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_yes" value="2" <?php if ($website->loadFilesBeforeZip == 2): ?>checked="true"<?php endif; ?>/> Yes<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_no" value="0" <?php if ($website->loadFilesBeforeZip == 0): ?>checked="true"<?php endif; ?>/> No<br />
                    </td>
                </tr>
            </table>

                <input type="hidden" name="site_id" id="backup_site_id" value="<?php echo $website->id; ?>"/>
                <input type="hidden" name="backup_site_full_size" id="backup_site_full_size" value="<?php echo $website->totalsize; ?>"/>
                <input type="hidden" name="backup_site_db_size" id="backup_site_db_size" value="<?php echo $website->dbsize; ?>"/>

                <p class="submit"><input type="button" name="backup_btnSubmit" id="backup_btnSubmit"
                                         class="button-primary"
                                         value="Backup Now"/></p>
            </form>
            </div>
        </div>
    </div>

    <div id="managesite-backup-status-box" title="Backup <?php echo $website->name; ?>" style="display: none; text-align: center">
        <div style="height: 190px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="managesite-backup-status-text">
        </div>
        <input id="managesite-backup-status-close" type="button" name="Close" value="Cancel" class="button" />
    </div>
    <?php
    }

    public static function _renderInfo()
    {
        ?><div class="mainwp_info-box"><strong><?php _e('Use this to manage your Sites. To add new Site click on the "Add new" Button.','mainwp'); ?></strong></div><?php
    }
    public static function _renderNotes()
    {
        ?>
        <div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
        <div id="mainwp_notes" class="mainwp_popup">
            <a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

            <div id="mainwp_notes_title" class="mainwp_popup_title"></span>
            </div>
            <div id="mainwp_notes_content">
                <textarea style="width: 580px !important; height: 300px;"
                          id="mainwp_notes_note"></textarea>
            </div>
            <div><em>Allowed HTML Tags: &lt;p&gt;, &lt;srtong&gt;, &lt;em&gt;, &lt;br/&gt;, &lt;hr/&gt;, &lt;a&gt; </em></div><br/>
            <form>
                <div style="float: right" id="mainwp_notes_status"></div>
                <input type="button" class="button cont button-primary" id="mainwp_notes_save" value="<?php _e('Save Note','mainwp'); ?>"/>
                <input type="button" class="button cont" id="mainwp_notes_cancel" value="<?php _e('Close','mainwp'); ?>"/>
                <input type="hidden" id="mainwp_notes_websiteid" value=""/>
            </form>
        </div>
        <?php
    }


    public static function renderAllSites(&$website, $updated, $groups, $statusses, $pluginDir)
    {
        $remote_destinations = apply_filters('mainwp_backups_remote_get_destinations', null, array('website' => $website->id));
        $hasRemoteDestinations = ($remote_destinations == null ? $remote_destinations : count($remote_destinations));
        ?>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <div id="ajax-information-zone" class="updated" style="display: none;"></div>
        <?php
        if ($updated)
        {
            ?>
            <div id="mainwp_managesites_edit_message" class="updated"><p><?php _e('Website updated.','mainwp'); ?></p></div>
            <?php
        }
        ?>
        <form method="POST" action="" id="mainwp-edit-single-site-form" enctype="multipart/form-data">
            <div class="postbox">
            <h3 class="mainwp_box_title"><?php _e('General Options','mainwp'); ?></h3>
            <div class="inside">
            <table class="form-table">
                <tbody>
                <tr>
                    <th scope="row"><?php _e('Site Name','mainwp'); ?></th>
                    <td><input type="text" name="mainwp_managesites_edit_sitename"
                               value="<?php echo $website->name; ?>" class="regular-text"/></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Site URL','mainwp'); ?></th>
                    <td><input type="text" id="mainwp_managesites_edit_siteurl" disabled="disabled"
                               value="<?php echo $website->url; ?>" class="regular-text"/> <span
                            class="mainwp-form_hint-display"><?php _e('Site URL cannot be changed.','mainwp'); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Administrator Username','mainwp'); ?></th>
                    <td><input type="text" name="mainwp_managesites_edit_siteadmin"
                               id="mainwp_managesites_edit_siteadmin"
                               value="<?php echo $website->adminname; ?>"
                               class="regular-text"/></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Groups','mainwp'); ?></th>
                    <td>
                        <input type="text" name="mainwp_managesites_edit_addgroups"
                               id="mainwp_managesites_edit_addgroups" value=""
                               class="regular-text"/> <span
                            class="mainwp-form_hint"><?php _e('Separate groups by commas (e.g. Group 1, Group 2).','mainwp'); ?></span>

                        <div id="selected_groups" style="display: block; width: 25em">
                            <?php
                            if (count($groups) == 0)
                            {
                                echo 'No groups added yet.';
                            }
                            $groupsSite = MainWPDB::Instance()->getGroupsByWebsiteId($website->id);
                            foreach ($groups as $group)
                            {
                                echo '<div class="mainwp_selected_groups_item"><input type="checkbox" name="selected_groups[]" value="' . $group->id . '" ' . (isset($groupsSite[$group->id]) && $groupsSite[$group->id] ? 'checked' : '') . ' />&nbsp' . $group->name . '</div>';
                            }
                            ?>
                        </div>
                        <span class="description"><?php _e('Or assign existing groups.','mainwp'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Offline Checks','mainwp'); ?></th>
                    <td>
                        <input type="radio" name="offline_checks" id="check_disabled" value="disabled"
                            <?php echo (!in_array($website->offline_checks, $statusses) ? 'checked="true"'
                                : ''); ?> /> Disabled &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="offline_checks" id="check_hourly"
                               value="hourly" <?php echo ($website->offline_checks == 'hourly' ? 'checked="true"'
                                : ''); ?>/> Hourly &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="offline_checks" id="check_2xday"
                               value="2xday" <?php echo ($website->offline_checks == '2xday' ? 'checked="true"'
                                : ''); ?>/> 2x Day &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="offline_checks" id="check_daily"
                               value="daily" <?php echo ($website->offline_checks == 'daily' ? 'checked="true"'
                                : ''); ?>/> Daily &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="offline_checks" id="check_weekly" value="weekly"
                            <?php echo ($website->offline_checks == 'weekly' ? 'checked="true"' : ''); ?>/>
                        Weekly  &nbsp;
                    <span class="mainwp-form_hint-display">Notifications are sent to: <?php echo MainWPUtility::getNotificationEmail(); ?>
                        (this address can be changed <a
                                href="<?php echo get_admin_url(); ?>admin.php?page=Settings">here</a>)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Client Plugin Folder Option','mainwp'); ?> <?php MainWPUtility::renderToolTip('Default, files/folders on the child site are viewable.<br />Hidden, when attempting to view files a 404 file will be returned, however a footprint does still exist.<br /><strong>Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.</strong>'); ?></th>
                    <td>
                        <div class="mainwp-radio" style="float: left;">
                          <input type="radio" value="" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_global" <?php echo ($pluginDir == '' ? 'checked="true"' : ''); ?>"/>
                          <label for="mainwp_options_footprint_plugin_folder_global"></label>
                        </div>Global Setting (<a href="<?php echo admin_url('admin.php?page=Settings#network-footprint'); ?>">Change Here</a>)<br/>
                        <div class="mainwp-radio" style="float: left;">
                          <input type="radio" value="default" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_default" <?php echo ($pluginDir == 'default' ? 'checked="true"' : ''); ?>"/>
                          <label for="mainwp_options_footprint_plugin_folder_default"></label>
                        </div>Default<br/>
                        <div class="mainwp-radio" style="float: left;">
                          <input type="radio" value="hidden" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_hidden" <?php echo ($pluginDir == 'hidden' ? 'checked="true"' : ''); ?>"/>
                          <label for="mainwp_options_footprint_plugin_folder_hidden"></label>
                        </div>Hidden (<strong>Note: </strong><i>If the heatmap is turned on, the heatmap javascript will still be visible.</i>) <br/>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Auto update core <?php MainWPUtility::renderToolTip('Auto update only works when enabled in the global settings as well.', admin_url('admin.php?page=Settings')); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_automaticDailyUpdate"
                               id="mainwp_automaticDailyUpdate" <?php echo ($website->automatic_update == 1 ? 'checked="true"' : ''); ?>"/>
                        <label for="mainwp_automaticDailyUpdate"></label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Require backup before upgrade','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Backup only works when enabled in the global settings as well.','mainwp'), admin_url('admin.php?page=Settings')); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_backup_before_upgrade"
                               id="mainwp_backup_before_upgrade" <?php echo ($website->backup_before_upgrade == 1 ? 'checked="true"' : ''); ?>/>
                        <label for="mainwp_backup_before_upgrade"></label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ignore Core Updates <?php MainWPUtility::renderToolTip('Set to YES if you want to Ignore Core Updates.'); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_is_ignoreCoreUpdates"
                               id="mainwp_is_ignoreCoreUpdates" <?php echo ($website->is_ignoreCoreUpdates == 1 ? 'checked="true"' : ''); ?>"/>
                        <label for="mainwp_is_ignoreCoreUpdates"></label>
                        </div>
                    </td>
                </tr>  
                <tr>
                    <th scope="row">Ignore All Plugin Updates <?php MainWPUtility::renderToolTip('Set to YES if you want to Ignore All Plugin Updates.'); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_is_ignorePluginUpdates"
                               id="mainwp_is_ignorePluginUpdates" <?php echo ($website->is_ignorePluginUpdates == 1 ? 'checked="true"' : ''); ?>"/>
                        <label for="mainwp_is_ignorePluginUpdates"></label>
                        </div>
                    </td>
                </tr>  
                <tr>
                    <th scope="row">Ignore All Theme Updates <?php MainWPUtility::renderToolTip('Set to YES if you want to Ignore All Theme Updates.'); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_is_ignoreThemeUpdates"
                               id="mainwp_is_ignoreThemeUpdates" <?php echo ($website->is_ignoreThemeUpdates == 1 ? 'checked="true"' : ''); ?>"/>
                        <label for="mainwp_is_ignoreThemeUpdates"></label>
                        </div>
                    </td>
                </tr>  
                <?php do_action('mainwp_extension_sites_edit_tablerow', $website); ?>
                </tbody>
            </table>
            </div>
            </div>
            <div class="clear"></div>
            <div class="postbox">
            <h3 class="mainwp_box_title"><span><?php _e('Advanced Options','mainwp'); ?></span></h3>
            <div class="inside">
            <table class="form-table" style="width: 100%">
                 <tr class="form-field form-required">
                    <th scope="row"><?php _e('Verify certificate','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.','mainwp')); ?></th>
                    <td>
                        <select id="mainwp_managesites_edit_verifycertificate" name="mainwp_managesites_edit_verifycertificate">
                             <option <?php echo ($website->verify_certificate == 1) ? "selected" : ""; ?> value="1"><?php _e('Yes','mainwp'); ?></option>
                             <option <?php echo ($website->verify_certificate == 0) ? "selected" : ""; ?> value="0"><?php _e('No','mainwp'); ?></option>
                             <option <?php echo ($website->verify_certificate == 2) ? "selected" : ""; ?> value="2"><?php _e('Use Global Setting','mainwp'); ?></option>
                         </select> <i>(Default: Yes)</i>
                    </td>
                </tr>
            </table>
            </div>
            </div>
            
            <div class="clear"></div>
            <div class="postbox">
            <h3 class="mainwp_box_title"><span><?php _e('Backup Settings','mainwp'); ?></span></h3>
            <div class="inside">
            <table class="form-table" style="width: 100%">
                <?php
                $globalArchiveFormat = get_option('mainwp_archiveFormat');
                if ($globalArchiveFormat == false) $globalArchiveFormat = 'tar.gz';
                if ($globalArchiveFormat == 'zip')
                {
                    $globalArchiveFormatText = 'Zip';
                }
                else if ($globalArchiveFormat == 'tar')
                {
                    $globalArchiveFormatText = 'Tar';
                }
                else if ($globalArchiveFormat == 'tar.gz')
                {
                    $globalArchiveFormatText = 'Tar GZip';
                }
                else if ($globalArchiveFormat == 'tar.bz2')
                {
                    $globalArchiveFormatText = 'Tar BZip2';
                }

                $backupSettings = MainWPDB::Instance()->getWebsiteBackupSettings($website->id);
                $archiveFormat = $backupSettings->archiveFormat;
                $useGlobal = ($archiveFormat == 'global');
                ?>
                <tr>
                    <th scope="row"><?php _e('Archive format','mainwp'); ?> <?php MainWPUtility::renderToolTip(__('','mainwp')); ?></th>
                    <td>
                        <table class="mainwp-nomarkup">
                            <tr>
                                <td valign="top">
                                    <span class="mainwp-select-bg"><select name="mainwp_archiveFormat" id="mainwp_archiveFormat">
                                        <option value="global" <?php if ($useGlobal): ?>selected<?php endif; ?>>Global setting (<?php echo $globalArchiveFormatText; ?>)</option>
                                        <option value="zip" <?php if ($archiveFormat == 'zip'): ?>selected<?php endif; ?>>Zip</option>
                                        <option value="tar" <?php if ($archiveFormat == 'tar'): ?>selected<?php endif; ?>>Tar</option>
                                        <option value="tar.gz" <?php if ($archiveFormat == 'tar.gz'): ?>selected<?php endif; ?>>Tar GZip</option>
                                        <option value="tar.bz2" <?php if ($archiveFormat == 'tar.bz2'): ?>selected<?php endif; ?>>Tar BZip2</option>
                                    </select><label></label></span>
                                </td>
                                <td>
                                    <i>
                                    <span id="info_global" class="archive_info" <?php if (!$useGlobal): ?>style="display: none;"<?php endif; ?>><?php
                                        if ($globalArchiveFormat == 'zip'): ?>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)<?php
                                        elseif ($globalArchiveFormat == 'tar'): ?>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)<?php
                                        elseif ($globalArchiveFormat == 'tar.gz'): ?>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)<?php
                                        elseif ($globalArchiveFormat == 'tar.bz2'): ?>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)<?php endif; ?></span>
                                    <span id="info_zip" class="archive_info" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)</span>
                                    <span id="info_tar" class="archive_info" <?php if ($archiveFormat != 'tar'): ?>style="display: none;"<?php endif; ?>>Creates an uncompressed tar-archive. (No compression, fast, low memory usage)</span>
                                    <span id="info_tar.gz" class="archive_info" <?php if ($archiveFormat != 'tar.gz'): ?>style="display: none;"<?php endif; ?>>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)</span>
                                    <span id="info_tar.bz2" class="archive_info" <?php if ($archiveFormat != 'tar.bz2'): ?>style="display: none;"<?php endif; ?>>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)</span>
                                    </i>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <?php
                $maximumFileDescriptorsOverride = ($website->maximumFileDescriptorsOverride == 1);
                $maximumFileDescriptorsAuto= ($website->maximumFileDescriptorsAuto == 1);
                $maximumFileDescriptors = $website->maximumFileDescriptors;
                ?>
                <tr class="archive_method archive_zip" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>
                    <th scope="row"><?php _e('Maximum File Descriptors on Child','mainwp'); ?> <?php MainWPUtility::renderToolTip('The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/'); ?></th>
                    <td>
                        <div class="mainwp-radio" style="float: left;">
                          <input type="radio" value="" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_global" <?php echo (!$maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?>"/>
                          <label for="mainwp_options_maximumFileDescriptorsOverride_global"></label>
                        </div>Global Setting (<a href="<?php echo admin_url('admin.php?page=Settings'); ?>">Change Here</a>)<br/>
                        <div class="mainwp-radio" style="float: left;">
                          <input type="radio" value="override" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_override" <?php echo ($maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?>"/>
                          <label for="mainwp_options_maximumFileDescriptorsOverride_override"></label>
                        </div>Override<br/><br />

                        <div style="float: left">Auto detect:&nbsp;</div><div class="mainwp-checkbox"><input type="checkbox" id="mainwp_maximumFileDescriptorsAuto" name="mainwp_maximumFileDescriptorsAuto" <?php echo ($maximumFileDescriptorsAuto ? 'checked="checked"' : ''); ?> /> <label for="mainwp_maximumFileDescriptorsAuto"></label></div><div style="float: left"><i>(<?php _e('Enter a fallback value because not all hosts support this function.','mainwp'); ?>)</i></div><div style="clear:both"></div>
                        <input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors"
                               value="<?php echo $maximumFileDescriptors; ?>"/><span class="mainwp-form_hint"><?php _e('The maximum number of open file descriptors on the child hosting.  0 sets unlimited.','mainwp'); ?></span>
                    </td>
                </tr>
                <tr class="archive_method archive_zip" <?php if ($archiveFormat != 'zip'): ?>style="display: none;"<?php endif; ?>>
                    <th scope="row">Load files in memory before zipping <?php MainWPUtility::renderToolTip('This causes the files to be opened and closed immediately, using less simultaneous I/O operations on the disk. For huge sites with a lot of files we advise to disable this, memory usage will drop but we will use more file handlers when backing up.', 'http://docs.mainwp.com/load-files-memory/'); ?></th>
                    <td>
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_global" value="1" <?php if ($website->loadFilesBeforeZip == false || $website->loadFilesBeforeZip == 1): ?>checked="true"<?php endif; ?>/> Global setting (<a href="<?php echo admin_url('admin.php?page=Settings'); ?>">Change Here</a>)<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_yes" value="2" <?php if ($website->loadFilesBeforeZip == 2): ?>checked="true"<?php endif; ?>/> Yes<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_no" value="0" <?php if ($website->loadFilesBeforeZip == 0): ?>checked="true"<?php endif; ?>/> No<br />
                    </td>
                </tr>
                <?php if ($hasRemoteDestinations !== null) { do_action('mainwp_backups_remote_settings', array('website' => $website->id, 'hide' => 'no')); } ?>
            </table>
            </div>
            </div>
            
            <?php
                
                $plugin_upgrades = json_decode($website->plugin_upgrades, true);
                if (!is_array($plugin_upgrades)) $plugin_upgrades = array();
                error_log(print_r($plugin_upgrades, true));
                
                $userExtension = MainWPDB::Instance()->getUserExtension();
                $globalIgnoredPluginConflicts = json_decode($userExtension->ignored_pluginConflicts, true);
                
            ?>
            <?php
            do_action('mainwp-extension-sites-edit', $website);
            ?><p class="submit"><input type="submit" name="submit" id="submit" class="button-primary"
                                     value="<?php _e('Update Site','mainwp'); ?>"/></p>
        </form>
        <?php
    }

    public static function _reconnectSite($website)
    {
        if (MainWPUtility::can_edit_website($website))
        {
            try
            {
                //Try to refresh stats first;
                if (MainWPSync::syncSite($website, true))
                {
                    return true;
                }

                //Add
                if (function_exists('openssl_pkey_new'))
                {                    
                    $conf = array('private_key_bits' => 384);                    
                    $res = openssl_pkey_new($conf);                                                          
                    @openssl_pkey_export($res, $privkey, NULL, $conf);                    
                    $pubkey = openssl_pkey_get_details($res);
                    $pubkey = $pubkey["key"];
                }
                else
                {
                    $privkey = '-1';
                    $pubkey = '-1';
                }

                $information = MainWPUtility::fetchUrlNotAuthed($website->url, $website->adminname, 'register', array('pubkey' => $pubkey, 'server' => get_admin_url()), true, $website->verify_certificate);

                if (isset($information['error']) && $information['error'] != '')
                {
                    throw new Exception($information['error']);
                }
                else
                {
                    if (isset($information['register']) && $information['register'] == 'OK')
                    {
                        //Update website
                        MainWPDB::Instance()->updateWebsiteValues($website->id, array('pubkey' => base64_encode($pubkey), 'privkey' => base64_encode($privkey), 'nossl' => $information['nossl'], 'nosslkey' => (isset($information['nosslkey']) ? $information['nosslkey'] : '')));
                        MainWPSync::syncInformationArray($website, $information);
                        return true;
                    }
                    else
                    {
                        throw new Exception(__('Undefined error','mainwp'));
                    }
                }
            }
            catch (MainWPException $e)
            {
                if ($e->getMessage() == 'HTTPERROR')
                {
                    throw new Exception('HTTP error' . ($e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : ''));
                }
                else if ($e->getMessage() == 'NOMAINWP')
                {
                    $error = __('No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue please ','mainwp');
                    if ($e->getMessageExtra() != null) $error .= __('test your connection <a href="' . admin_url('admin.php?page=managesites&do=test&site=' . urlencode($e->getMessageExtra())) . '">here</a> or ','mainwp');
                    $error .= __('post as much information as possible on the error in the <a href="http://mainwp.com/forum/">support forum</a>.','mainwp');

                    throw new Exception($error);
                }
            }
        }
        else
        {
           throw new Exception(__('Not allowed this operation.','mainwp'));
        }

        return false;
    }

    public static function addSite($website)
    {
        $error = '';
        $message = '';

        if ($website)
        {
            $error = __('Your site is already added to MainWP','mainwp');
        }
        else
        {
            try
            {
                //Add
                if (function_exists('openssl_pkey_new'))
                {
                    $conf = array('private_key_bits' => 384);                    
                    $res = openssl_pkey_new($conf);                                                          
                    @openssl_pkey_export($res, $privkey, NULL, $conf);
                    $pubkey = openssl_pkey_get_details($res);
                    $pubkey = $pubkey["key"];
                }
                else
                {
                    $privkey = '-1';
                    $pubkey = '-1';
                }

                $url = $_POST['managesites_add_wpurl'];

                $pluginConflicts = get_option('mainwp_pluginConflicts');
                if ($pluginConflicts !== false)
                {
                    $pluginConflicts = array_keys($pluginConflicts);
                }

                $themeConflicts = get_option('mainwp_themeConflicts');
                if ($themeConflicts !== false)
                {
                    $themeConflicts = array_keys($themeConflicts);
                }

                 // to fix bug
                if (is_array($pluginConflicts))
                    $pluginConflicts = array_filter($pluginConflicts);
                if (is_array($themeConflicts))
                    $themeConflicts = array_filter($themeConflicts);
                $verifyCertificate = $_POST['verify_certificate'];
                $information = MainWPUtility::fetchUrlNotAuthed($url, $_POST['managesites_add_wpadmin'], 'register',
                    array('pubkey' => $pubkey,
                        'server' => get_admin_url(),
                        'uniqueId' => $_POST['managesites_add_uniqueId'],
                        'pluginConflicts' => json_encode($pluginConflicts),
                        'themeConflicts' => json_encode($themeConflicts)), 
                    false,
                    $verifyCertificate    
                );
                
                error_log(print_r($information, true));
                
                if (isset($information['error']) && $information['error'] != '')
                {
                    $error = $information['error'];
                }
                else
                {
                    if (isset($information['register']) && $information['register'] == 'OK')
                    {
                        //Add website to database
                        $groupids = array();
                        $groupnames = array();
                        if (isset($_POST['groupids']))
                        {
                            foreach ($_POST['groupids'] as $group)
                            {
                                $groupids[] = $group;
                            }
                        }
                        if ((isset($_POST['groupnames']) && $_POST['groupnames'] != '') || (isset($_POST['groupnames_import']) && $_POST['groupnames_import'] != ''))
                        {
                            if ($_POST['groupnames'])
                                $tmpArr = explode(',', $_POST['groupnames']);
                            else if ($_POST['groupnames_import'])
                                $tmpArr = explode(';', $_POST['groupnames_import']);

                            foreach ($tmpArr as $tmp)
                            {
                                $group = MainWPDB::Instance()->getGroupByNameForUser(trim($tmp));
                                if ($group)
                                {
                                    if (!in_array($group->id, $groupids))
                                    {
                                        $groupids[] = $group->id;
                                    }
                                }
                                else
                                {
                                    $groupnames[] = trim($tmp);
                                }
                            }
                        }

                        global $current_user;
                        $id = MainWPDB::Instance()->addWebsite($current_user->ID, $_POST['managesites_add_wpname'], $_POST['managesites_add_wpurl'], $_POST['managesites_add_wpadmin'], base64_encode($pubkey), base64_encode($privkey), $information['nossl'], (isset($information['nosslkey'])
                                ? $information['nosslkey'] : null), $groupids, $groupnames, $verifyCertificate);
                        $message = 'Site successfully added';
                        $website = MainWPDB::Instance()->getWebsiteById($id);
                        MainWPSync::syncInformationArray($website, $information);
                    }
                    else
                    {
                        $error = 'Undefined error';
                    }
                }
            }
            catch (MainWPException $e)
            {
                if ($e->getMessage() == 'HTTPERROR')
                {
                    $error = 'HTTP error' . ($e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : '');
                }
                else if ($e->getMessage() == 'NOMAINWP')
                {
                    $error = __('No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue please ','mainwp');
                    if ($e->getMessageExtra() != null) $error .= __('test your connection <a href="' . admin_url('admin.php?page=managesites&do=test&site=' . urlencode($e->getMessageExtra())) . '">here</a> or ','mainwp');
                    $error .= __('post as much information as possible on the error in the <a href="http://mainwp.com/forum/">support forum</a>.','mainwp');
                }
                else
                {
                    $error = $e->getMessage();
                }
            }
        }

        return array($message, $error);
    }

    public static function sitesPerPage()
    {
        return __('Sites per page', 'mainwp');
    }
}