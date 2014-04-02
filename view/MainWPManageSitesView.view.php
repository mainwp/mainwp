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
            <h3><?php _e('Test a Site Connection','mainwp'); ?></h3>

            <form method="POST" action="" enctype="multipart/form-data" id="mainwp_testconnection_form">
                <table class="form-table">
                    <tr class="form-field form-required">
                        <th scope="row"><?php _e('Site URL:','mainwp'); ?></th>
                        <td>
                            <input type="text" id="mainwp_managesites_test_wpurl"
                                   name="mainwp_managesites_add_wpurl"
                                   value="<?php if (isset($_REQUEST['site'])) echo $_REQUEST['site']; ?>" autocompletelist="mainwp-test-sites" class="mainwp_autocomplete" /><span class="mainwp-form_hint">Proper Format: <strong>http://address.com</strong> or <strong>http://www.address.com</strong></span>
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
                </table>
                <p class="submit"><input type="button" name="mainwp_managesites_test"
                                         id="mainwp_managesites_test"
                                         class="button-primary" value="<?php _e('Test Connection','mainwp'); ?>"/></p>
            </form>
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
       <fieldset class="mainwp-fieldset-box">
       <legend><?php _e('Add a Single Site','mainwp'); ?></legend>
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
                              value=""/><span class="mainwp-form_hint">Proper format "http://address.com"</span></td>
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
               </fieldset>
               <fieldset class="mainwp-fieldset-box">
               <legend><?php _e('Bulk Upload','mainwp'); ?></legend>
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
           </fieldset>


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
        $upload_dir = wp_upload_dir();
        $upload_base_dir = $upload_dir['basedir'];
        $upload_base_url = $upload_dir['baseurl'];

        $output = '';
        echo '<table>';

        foreach ($fullBackups as $key => $fullBackup)
        {
            $output .= '<tr><td style="width: 400px;">' . MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp(filemtime($fullBackup))) . ' - ' . MainWPUtility::human_filesize(filesize($fullBackup));
            $output .= '</td><td><a title="'.basename($fullBackup).'" href="' . str_replace(array(basename($fullBackup), $upload_base_dir), array(rawurlencode(basename($fullBackup)), $upload_base_url), $fullBackup) . '" class="button">Download</a></td>';
            $output .= '<td><a href="admin.php?page=SiteRestore&websiteid=' . $website->id.'&file='.base64_encode(str_replace(array(basename($fullBackup), $upload_base_dir), array(rawurlencode(basename($fullBackup)), ''), $fullBackup)) . '" class="mainwp-upgrade-button button" target="_blank" title="'.basename($fullBackup).'">Restore</a></td></tr>';
        }
        if ($output == '') echo '<br />' . __('No full backup has been taken yet','mainwp') . '<br />';
        else echo '<strong style="font-size: 14px">'. __('Last backups from your files:','mainwp') . '</strong>' . $output;

        echo '</table><br/><table>';

        $output = '';
        foreach ($dbBackups as $key => $dbBackup)
        {
            $output .= '<tr><td style="width: 400px;">' . MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp(filemtime($dbBackup))) . ' - ' . MainWPUtility::human_filesize(filesize($dbBackup)) . '</td><td><a title="'.basename($dbBackup).'" href="' . str_replace(array(basename($dbBackup), $upload_base_dir), array(rawurlencode(basename($dbBackup)), $upload_base_url), $dbBackup) . '" download class="button">Download</a></td></tr>';
        }
        if ($output == '') echo '<br />'. __('No database only backup has been taken yet','mainwp') . '<br /><br />';
        else echo '<strong style="font-size: 14px">'. __('Last backups from your database:','mainwp') . '</strong>' . $output;
        echo '</table>';
    }


    public static function renderSettings()
    {
        $backupsOnServer = get_option('mainwp_backupsOnServer');
        $backupOnExternalSources = get_option('mainwp_backupOnExternalSources');
        $maximumFileDescriptors = get_option('mainwp_maximumFileDescriptors');
        $notificationOnBackupFail = get_option('mainwp_notificationOnBackupFail');
        $notificationOnBackupStart = get_option('mainwp_notificationOnBackupStart');
        $chunkedBackupTasks = get_option('mainwp_chunkedBackupTasks');
        ?>
    <fieldset class="mainwp-fieldset-box">
    <legend>Backup options</legend>
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
            <th scope="row">Maximum File Descriptors on Child <?php MainWPUtility::renderToolTip('The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/'); ?></th>
            <td>
                <input type="text" name="mainwp_options_maximumFileDescriptors"
                       value="<?php echo ($maximumFileDescriptors === false ? 0 : $maximumFileDescriptors); ?>"/><span class="mainwp-form_hint"><?php _e('The maximum number of open file descriptors on the child hosting.  0 sets unlimited.','mainwp'); ?></span>
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
                   <input type="checkbox" id="mainwp_options_chunkedBackupTasks" name="mainwp_options_chunkedBackupTasks"  <?php echo ($chunkedBackupTasks == 0 ? '' : 'checked="checked"'); ?> "/>
                   <label for="mainwp_options_chunkedBackupTasks"></label>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
    </fieldset>
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
        if ($website->totalsize > 100)
        {
            ?>
            <div class="mainwp_info-box-yellow"><?php _e('A full backup might fail because the total file size of this website is','mainwp'); ?> <?php echo $website->totalsize; ?><?php _e('MB, you could exclude folders to decrease the filesize.','mainwp'); ?></div>
            <?php
        }
        ?>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <div id="ajax-information-zone" class="updated" style="display: none;"></div>
        <div id="mainwp_background-box">
        	<fieldset class="mainwp-fieldset-box">
            <legend><?php _e('Backup Details','mainwp'); ?></legend>
            <?php
            if (!MainWPUtility::can_edit_website($website))
            {
                die('This is not your website.');
            }

            MainWPManageSites::showBackups($website);
            ?>
            </fieldset>
            <fieldset class="mainwp-fieldset-box">
            <legend><?php _e('Backup Options','mainwp'); ?></legend>
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
                        <br /><a href="#" id="mainwp_backup_exclude_files"><?php _e('EXCLUDE FILES','mainwp'); ?></a>
                    </td>
                </tr>
                <tr id="mainwp_backup_exclude_files_content" style="display: none;">
                    <th scope="row" style="vertical-align: top"></th>
                    <td>
                        <table class="mainwp_excluded_folders_cont">
                            <tr>
                                <td style="width: 280px">
                                    <?php _e('Click directories to navigate or click to exclude.','mainwp'); ?>
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
            </table>

                <input type="hidden" name="site_id" id="backup_site_id" value="<?php echo $website->id; ?>"/>
                <p class="submit"><input type="button" name="backup_btnSubmit" id="backup_btnSubmit"
                                         class="button-primary"
                                         value="Backup Now"/></p>
            </form>
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
        <form method="POST" action="" id="mainwp-edit-single-site-form">
            <fieldset class="mainwp-fieldset-box">
            <legend><?php _e('General Options','mainwp'); ?></legend>
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
                               id="mainwp_backup_before_upgrade" <?php echo ($website->backup_before_upgrade == 1 ? 'checked="true"' : ''); ?>"/>
                        <label for="mainwp_backup_before_upgrade"></label>
                        </div>
                    </td>
                </tr>
                <?php do_action('mainwp_extension_sites_edit_tablerow', $website); ?>
                </tbody>
            </table>
            </fieldset>
            <?php
            if ($hasRemoteDestinations !== null)
            {
            ?>
            <div class="clear"></div>
            <fieldset class="mainwp-fieldset-box">
            <legend><?php _e('Backup Settings','mainwp'); ?></legend>
            <table class="form-table" style="width: 100%">
                <?php do_action('mainwp_backups_remote_settings', array('website' => $website->id, 'hide' => 'no')); ?>
            </table>
            </fieldset>
            <?php
            }
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
                if (MainWPSync::syncSite($website))
                {
                    return true;
                }

                //Add
                if (function_exists('openssl_pkey_new'))
                {
                    $conf = array('private_key_bits' => 384);
                    $res = openssl_pkey_new($conf);
                    openssl_pkey_export($res, $privkey);
                    $pubkey = openssl_pkey_get_details($res);
                    $pubkey = $pubkey["key"];
                }
                else
                {
                    $privkey = '-1';
                    $pubkey = '-1';
                }

                $information = MainWPUtility::fetchUrlNotAuthed($website->url, $website->adminname, 'register', array('pubkey' => $pubkey, 'server' => get_admin_url()));

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
                    openssl_pkey_export($res, $privkey);
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

                $information = MainWPUtility::fetchUrlNotAuthed($url, $_POST['managesites_add_wpadmin'], 'register',
                    array('pubkey' => $pubkey,
                        'server' => get_admin_url(),
                        'uniqueId' => $_POST['managesites_add_uniqueId'],
                        'pluginConflicts' => json_encode($pluginConflicts),
                        'themeConflicts' => json_encode($themeConflicts)));

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
                                ? $information['nosslkey'] : null), $groupids, $groupnames);
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