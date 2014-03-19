<?php
class MainWPDB
{
    //Config
    private $mainwp_db_version = '5.7';
    //Private
    private $table_prefix;
    //Singleton
    private static $instance = null;

    /**
     * @static
     * @return MainWPDB
     */
    static function Instance()
    {
        if (MainWPDB::$instance == null) {
            MainWPDB::$instance = new MainWPDB();
        }

        /** @var $wpdb wpdb */
        global $wpdb;
        if (!@self::ping($wpdb->dbh))
        {
            MainWPLogger::Instance()->info('Trying to reconnect Wordpress DB Connection');
            $wpdb->db_connect();
        }

        return MainWPDB::$instance;
    }

    //Constructor
    function __construct()
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        $this->table_prefix = $wpdb->prefix . "mainwp_";
    }

    private function tableName($suffix, $tablePrefix = null)
    {
        return ($tablePrefix == null ? $this->table_prefix : $tablePrefix) . $suffix;
    }

    //Installs new DB
    function install()
    {
        $currentVersion = get_site_option('mainwp_db_version');

        if ($currentVersion == $this->mainwp_db_version) return;

        $sql = array();
        $tbl = 'CREATE TABLE ' . $this->tableName('wp') . ' (
   id int(11) NOT NULL auto_increment,
   userid int(11) NOT NULL,
   adminname text NOT NULL,
  name text NOT NULL,
  url text NOT NULL,
  pubkey text NOT NULL,
  privkey text NOT NULL,
  nossl tinyint(1) NOT NULL,
  nosslkey text NOT NULL,
  siteurl text NOT NULL,
  ga_id text NOT NULL,
  gas_id int(11) NOT NULL,
  offline_checks text NOT NULL,
  offline_checks_last int(11) NOT NULL,
  offline_check_result int(11) NOT NULL,
  note text NOT NULL,
  statsUpdate int(11) NOT NULL,
  pagerank int(11) NOT NULL,
  indexed int(11) NOT NULL,
  alexia int(11) NOT NULL,
  pagerank_old int(11) DEFAULT NULL,
  indexed_old int(11) DEFAULT NULL,
  alexia_old int(11) DEFAULT NULL,
  directories longtext NOT NULL,
  sync_errors longtext NOT NULL,
  wp_upgrades longtext NOT NULL,
  plugin_upgrades longtext NOT NULL,
  theme_upgrades longtext NOT NULL,
  premium_upgrades longtext NOT NULL,
  uptodate longtext NOT NULL,
  securityIssues longtext NOT NULL,
  recent_comments longtext NOT NULL,
  recent_posts longtext NOT NULL,
  recent_pages longtext NOT NULL,
  themes longtext NOT NULL,
  ignored_themes longtext NOT NULL,
  plugins longtext NOT NULL,
  ignored_plugins longtext NOT NULL,
  pages longtext NOT NULL,
  users longtext NOT NULL,
  categories longtext NOT NULL,
  pluginDir text NOT NULL,
  last_wp_upgrades longtext NOT NULL,
  last_plugin_upgrades longtext NOT NULL,
  last_theme_upgrades longtext NOT NULL,
  dtsAutomaticSync int(11) NOT NULL,
  dtsAutomaticSyncStart int(11) NOT NULL,
  automatic_update tinyint(1) NOT NULL,
  backup_before_upgrade tinyint(1) NOT NULL DEFAULT 1,
  dtsSync int(11) NOT NULL,
  dtsSyncStart int(11) NOT NULL,
  totalsize int(11) NOT NULL,
  extauth text NOT NULL,
  pluginConflicts text NOT NULL,
  themeConflicts text NOT NULL,
  ignored_pluginConflicts text NOT NULL,
  ignored_themeConflicts text NOT NULL,
  last_post_gmt int(11) NOT NULL,
  backups text NOT NULL,
  mainwpdir int(11) NOT NULL';
        if ($currentVersion == '') $tbl .= ',
  PRIMARY KEY  (id)  ';
        $tbl .= ')';
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->tableName('tips') . ' (
  id int(11) NOT NULL auto_increment,
  seq int(11) NOT NULL,
  content text NOT NULL';
          if ($currentVersion == '') $tbl .= ',
  PRIMARY KEY  (id)  ';
          $tbl .= ')';
          $sql[] = $tbl;

        $tbl = "CREATE TABLE " . $this->tableName('users') . " (
  userid int(11) NOT NULL,
  user_email text NOT NULL DEFAULT '',
  tips tinyint(1) NOT NULL DEFAULT '1',
  offlineChecksOnlineNotification tinyint(1) NOT NULL DEFAULT '0',
  heatMap tinyint(1) NOT NULL DEFAULT '0',
  ignored_plugins longtext NOT NULL DEFAULT '',
  trusted_plugins longtext NOT NULL DEFAULT '',
  trusted_plugins_notes longtext NOT NULL DEFAULT '',
  ignored_themes longtext NOT NULL DEFAULT '',
  trusted_themes longtext NOT NULL DEFAULT '',
  trusted_themes_notes longtext NOT NULL DEFAULT '',
  site_view tinyint(1) NOT NULL DEFAULT '0',
  pluginDir text NOT NULL DEFAULT '',
  ignored_pluginConflicts text NOT NULL DEFAULT '',
  ignored_themeConflicts text NOT NULL DEFAULT ''";
          if ($currentVersion == '') $tbl .= ',
  PRIMARY KEY  (userid)  ';
          $tbl .= ')';
          $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->tableName('group') . ' (
  id int(11) NOT NULL auto_increment,
  userid int(11) NOT NULL,
  name text NOT NULL';
          if ($currentVersion == '') $tbl .= ',
  PRIMARY KEY  (id)  ';
          $tbl .= ')';
        $sql[] = $tbl;

        $sql[] = 'CREATE TABLE ' . $this->tableName('wp_group') . ' (
  wpid int(11) NOT NULL,
  groupid int(11) NOT NULL
        )';

        $tbl = 'CREATE TABLE ' . $this->tableName('wp_backup') . ' (
  id int(11) NOT NULL auto_increment,
  userid int(11) NOT NULL,
  name text NOT NULL,
  schedule text NOT NULL,
  type text NOT NULL,
  exclude text NOT NULL,
  sites text NOT NULL,
  groups text NOT NULL,
  ftp_enabled tinyint(1) NOT NULL,
  ftp_address text NOT NULL,
  ftp_username text NOT NULL,
  ftp_password text NOT NULL,
  ftp_path text NOT NULL,
  ftp_port text NOT NULL,
  ftp_ssl tinyint(1) NOT NULL,
  amazon_enabled tinyint(1) NOT NULL,
  amazon_access text NOT NULL,
  amazon_secret text NOT NULL,
  amazon_bucket text NOT NULL,
  amazon_dir text NOT NULL,
  dropbox_enabled tinyint(1) NOT NULL,
  dropbox_username text NOT NULL,
  dropbox_password text NOT NULL,
  dropbox_dir text NOT NULL,
  last int(11) NOT NULL,
  last_run int(11) NOT NULL,
  last_run_manually int(11) NOT NULL,
  completed_sites text NOT NULL,
  completed int(11) NOT NULL,
  backup_errors text NOT NULL,
  subfolder text NOT NULL,
  filename text NOT NULL,
  paused tinyint(1) NOT NULL,
  template tinyint(1) DEFAULT 0';
          if ($currentVersion == '') $tbl .= ',
  PRIMARY KEY  (id)  ';
          $tbl .= ');';
        $sql[] = $tbl;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        foreach ($sql as $query)
        {
            dbDelta($query);
        }

        update_option('mainwp_db_version', $this->mainwp_db_version);
    }

    //Check for update - if required, update..
    function update()
    {
        $currentVersion = get_site_option('mainwp_db_version');
        if ($currentVersion === false) return;

        if (version_compare($currentVersion, '2.5', '<'))
        {
            $requests = array('lastRequest' => time(), 'requests' => base64_encode(serialize(array('main' => MainWPSystem::Instance()->getAPIStatus()))));
            update_option('mainwp_requests', $requests);
        }

        if (version_compare($currentVersion, '2.8', '<')) {
            /** @var $wpdb wpdb */
            global $wpdb;

            $wpdb->update($this->tableName('wp_backup'), array('subfolder' => 'MainWP Backups/%url%/%type%/%date%'), array('template' => '0'));
        }

        if (version_compare($currentVersion, '4.3', '<'))
        {
            global $wpdb;
            $row = $wpdb->get_row('SELECT * FROM ' . $this->tableName('users'), OBJECT);
            if ($row != null)
            {
                $row->userid = 0;
                $this->updateUserExtension($row);
            }
        }

//        if (version_compare($currentVersion, '4.4', '<'))
//        {
//            global $wpdb;
//            $results = $wpdb->get_results('SELECT * FROM ' . $this->tableName('remote_dest'), OBJECT);
//            if ($results)
//            {
//                foreach ($results as $result)
//                {
//                    if ($result->type == 'dropbox')
//                    {
//                        $wpdb->update($this->tableName('remote_dest'), array('field2' => MainWPUtility::encrypt(MainWPUtility::decrypt_legacy($result->field2, MainWPRemoteDestination::$ENCRYPT), MainWPRemoteDestination::$ENCRYPT)), array('id' => $result->id));
//                    }
//                    else if ($result->type == 'ftp')
//                    {
//                        $wpdb->update($this->tableName('remote_dest'), array('field3' => MainWPUtility::encrypt(MainWPUtility::decrypt_legacy($result->field3, MainWPRemoteDestination::$ENCRYPT), MainWPRemoteDestination::$ENCRYPT)), array('id' => $result->id));
//                    }
//                    else if ($result->type == 'amazon')
//                    {
//                        $wpdb->update($this->tableName('remote_dest'), array('field2' => MainWPUtility::encrypt(MainWPUtility::decrypt_legacy($result->field2, MainWPRemoteDestination::$ENCRYPT), MainWPRemoteDestination::$ENCRYPT)), array('id' => $result->id));
//                    }
//                }
//            }
//        }

        if (version_compare($currentVersion, '5.3', '<'))
        {
            if (MainWPSystem::Instance()->isSingleUser())
            {
                /** @var $wpdb wpdb */
                global $wpdb;
                $row = $wpdb->get_row('SELECT * FROM ' . $this->tableName('ga'), OBJECT);

                $wpdb->update($this->tableName('ga'), array('userid' => 0), array('userid' => $row->userid));
            }
        }
    }

    public function getFirstSyncedSite($userId = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        if (($userId == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userId = $current_user->ID;
        }

        $qry = 'SELECT dtsSync FROM '.$this->tableName('wp'). ($userId != null ? ' WHERE userid = '.$userId : '') . ' ORDER BY dtsSync ASC LIMIT 1';

        return $wpdb->get_var($qry);
    }

    public function getRequestsSince($pSeconds)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        $qry = 'SELECT count(*) FROM '.$this->tableName('wp').' WHERE dtsSyncStart > ' . (time() - $pSeconds);

        return $wpdb->get_var($qry);
    }

    //Database actions
    public function getWebsitesCount($userId = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (($userId == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userId = $current_user->ID;
        }

        $qry = 'SELECT COUNT(wp.id) FROM ' . $this->tableName('wp') . ' wp' . ($userId == null ? '' : ' WHERE wp.userid = '.$userId);

        return $wpdb->get_var($qry);
    }

    public function getWebsitesByUserId($userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url')
    {
        return $this->getResultsResult($this->getSQLWebsitesByUserId($userid, $selectgroups, $search_site, $orderBy));
    }


    public function getSQLWebsites()
    {
        return 'SELECT wp.* FROM ' . $this->tableName('wp') . ' wp';
    }

    public function getSQLWebsitesByUserId($userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url', $offset = false, $rowcount = false)
    {
        if (MainWPUtility::ctype_digit($userid)) {
            $where = '';
            if ($search_site !== null) {
                $search_site = trim($search_site);
                $where = ' AND (wp.name LIKE "%'.$search_site.'%" OR wp.url LIKE  "%'.$search_site.'%") ';
            }

            if ($selectgroups) {
                $qry = 'SELECT wp.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as groups
                FROM ' . $this->tableName('wp') . ' wp
                LEFT JOIN ' . $this->tableName('wp_group') . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->tableName('group') . ' gr ON wpgr.groupid = gr.id
                WHERE wp.userid = ' . $userid . "
                $where
                GROUP BY wp.id
                ORDER BY ".$orderBy;
            }
            else
            {
                $qry = 'SELECT wp.*
                FROM ' . $this->tableName('wp') . ' wp
                WHERE wp.userid = ' . $userid . "
                $where
                ORDER BY ".$orderBy;
            }

            if (($offset !== false) && ($rowcount !== false)) $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
            return $qry;
        }
        return null;
    }

    public function getSQLWebsitesForCurrentUser($selectgroups = false, $search_site = null, $orderBy = 'wp.url', $offset = false, $rowcount = false, $extraWhere = null)
    {
        $where = '1 ';
        if (MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $where .= ' AND wp.userid = '.$current_user->ID . ' ';
        }

        if ($search_site !== null) {
            $search_site = trim($search_site);
            $where .= ' AND (wp.name LIKE "%'.$search_site.'%" OR wp.url LIKE  "%'.$search_site.'%") ';
        }

        if ($extraWhere !== null)
        {
            $where .= ' AND ' . $extraWhere;
        }

        if ($selectgroups) {
            $qry = 'SELECT wp.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as groups
            FROM ' . $this->tableName('wp') . ' wp
            LEFT JOIN ' . $this->tableName('wp_group') . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->tableName('group') . ' gr ON wpgr.groupid = gr.id
            WHERE ' . $where . '
            GROUP BY wp.id
            ORDER BY '.$orderBy;
        }
        else
        {
            $qry = 'SELECT wp.*
            FROM ' . $this->tableName('wp') . ' wp
            WHERE ' . $where . '
            ORDER BY '.$orderBy;
        }

        if (($offset !== false) && ($rowcount !== false)) $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
        return $qry;
    }

    public function getGroupByNameForUser($name, $userid = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (($userid == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userid = $current_user->ID;
        }

        return $wpdb->get_row('SELECT * FROM ' . $this->tableName('group') . ' WHERE ' . ($userid != null ? ' userid=' . $userid . ' AND ' : '') . ' name="' . $this->escape($name) . '"');
    }

    public function getGroupById($id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($id))
            return $wpdb->get_row('SELECT * FROM ' . $this->tableName('group') . ' WHERE id=' . $id);
        return null;
    }

    public function getGroupsByUserId($userid)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($userid))
            return $wpdb->get_results('SELECT * FROM ' . $this->tableName('group') . ' WHERE userid = ' . $userid . ' ORDER BY name', OBJECT_K);
        return null;
    }

    public function getGroupsForCurrentUser()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        $where = ' 1 ';
        if (MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $where = ' userid = ' . $current_user->ID . ' ';
        }

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('group') . ' WHERE ' . $where . ' ORDER BY name', OBJECT_K);
    }

    public function getGroupsByWebsiteId($websiteid)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($websiteid))
            return $wpdb->get_results('SELECT * FROM ' . $this->tableName('group') . ' gr
                JOIN ' . $this->tableName('wp_group') . ' wpgr ON gr.id = wpgr.groupid
                WHERE wpgr.wpid = ' . $websiteid . ' ORDER BY name', OBJECT_K);
        return null;
    }

    public function getGroupsAndCount($userid = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (($userid == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userid = $current_user->ID;
        }

        return $wpdb->get_results('SELECT gr.*, COUNT(DISTINCT(wpgr.wpid)) as nrsites
                FROM ' . $this->tableName('group') . ' gr 
                LEFT JOIN ' . $this->tableName('wp_group') . ' wpgr ON gr.id = wpgr.groupid
                ' . ($userid != null ? ' WHERE gr.userid = ' . $userid : '') . '
                GROUP BY gr.id
                ORDER BY gr.name', OBJECT_K);
    }
    
    public function getGroupsByName($name)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        return $wpdb->get_results('SELECT gr.*
            FROM ' . $this->tableName('group') . ' gr
            WHERE gr.name = "' . $this->escape($name) . '"
            ', OBJECT_K);        
    }
    
    

    public function getNotEmptyGroups($userid = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (($userid == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userid = $current_user->ID;
        }

        return $wpdb->get_results('SELECT DISTINCT(g.id), g.name, count(wp.wpid)
              FROM ' . $this->tableName('group') . ' g
              JOIN ' . $this->tableName('wp_group') . ' wp ON g.id = wp.groupid
              ' . ($userid != null ? 'WHERE g.userid = ' . $userid : '') . '
              GROUP BY g.id
              HAVING count(wp.wpid) > 0
              ORDER BY g.name', OBJECT_K);
    }

    public function getWebsitesByUrl($url)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        if (substr($url, -1) != '/') { $url .= '/'; }
        $results = $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp') . ' WHERE url = "' . $this->escape($url) . '"', OBJECT);
        if ($results) return $results;

        if (stristr($url, '/www.'))
        {
            //remove www if it's there!
            $url = str_replace('/www.', '/', $url);
        }
        else
        {
            //add www if it's not there!
            $url = str_replace('https://', 'https://www.', $url);
            $url = str_replace('http://', 'http://www.', $url);
        }

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp') . ' WHERE url = "' . $this->escape($url) . '"', OBJECT);
    }

    public function getWebsiteById($id, $selectGroups = false)
    {
        return $this->getRowResult($this->getSQLWebsiteById($id, $selectGroups));
    }

    public function getSQLWebsiteById($id, $selectGroups = false)
    {
        if (MainWPUtility::ctype_digit($id))
        {
            if ($selectGroups) {
                return 'SELECT wp.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as groups
                FROM ' . $this->tableName('wp') . ' wp
                LEFT JOIN ' . $this->tableName('wp_group') . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->tableName('group') . ' gr ON wpgr.groupid = gr.id
                WHERE wp.id = ' . $id . '
                GROUP BY wp.id';
            }
            return 'SELECT * FROM ' . $this->tableName('wp') . ' WHERE id = ' . $id;
        }
        return null;
    }

    public function getWebsitesByIds($ids, $userId = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        if (($userId == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userId = $current_user->ID;
        }

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp') . ' WHERE id IN (' . implode(',', $ids) . ')' . ($userId != null ? ' AND userid = '.$userId : ''), OBJECT);
    }

    public function getWebsitesByGroupIds($ids, $userId = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        if (($userId == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userId = $current_user->ID;
        }

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp') . ' wp JOIN ' . $this->tableName('wp_group') . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . implode(',', $ids) .') '.($userId != null ? ' AND wp.userid = '.$userId : ''), OBJECT);
    }

    public function getWebsitesByGroupId($id)
    {
        return $this->getResultsResult($this->getSQLWebsitesByGroupId($id));
    }

    public function getSQLWebsitesByGroupId($id, $selectgroups = false, $orderBy = 'wp.url', $offset = false, $rowcount = false, $where = null)
    {
        if (MainWPUtility::ctype_digit($id))
        {
            if ($selectgroups)
            {
                $qry = 'SELECT wp.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as groups
                 FROM ' . $this->tableName('wp') . ' wp
                 JOIN ' . $this->tableName('wp_group') . ' wpgroup ON wp.id = wpgroup.wpid
                 LEFT JOIN ' . $this->tableName('wp_group') . ' wpgr ON wp.id = wpgr.wpid
                 LEFT JOIN ' . $this->tableName('group') . ' gr ON wpgr.groupid = gr.id
                 WHERE wpgroup.groupid = ' . $id . ' ' .
                 ($where == null ? '' : ' AND ' . $where) . '
                 GROUP BY wp.id
                 ORDER BY '.$orderBy;
            }
            else
            {
                $qry = 'SELECT * FROM ' . $this->tableName('wp') . ' wp JOIN ' . $this->tableName('wp_group') . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid = ' . $id . ' ' .
                                 ($where == null ? '' : ' AND ' . $where) . ' ORDER BY ' . $orderBy;
            }
            if (($offset !== false) && ($rowcount !== false)) $qry .= ' LIMIT ' . $offset . ', ' . $rowcount;

            return $qry;
        }

        return null;
    }
    
    public function getWebsitesByGroupName($userid, $groupname)
    {
        return $this->getResultsResult($this->getSQLWebsitesByGroupName($groupname, $userid));
    }

    public function getSQLWebsitesByGroupName($groupname, $userid = null)
    {
        global $wpdb;
        if (($userid == null) && MainWPSystem::Instance()->isMultiUser())
        {
            global $current_user;
            $userid = $current_user->ID;
        }
        $sql = 'SELECT wp.* FROM ' . $this->tableName('wp') . ' wp INNER JOIN ' . $this->tableName('wp_group') . ' wpgroup ON wp.id = wpgroup.wpid JOIN ' . $this->tableName('group') . ' g ON wpgroup.groupid = g.id WHERE g.name="' . $this->escape($groupname). '"';
        if ($userid != null) $sql .= ' AND g.userid = "' . $userid . '"';
        return $sql;
    }

    public function addWebsite($userid, $name, $url, $admin, $pubkey, $privkey, $nossl, $nosslkey, $groupids, $groupnames)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($userid) && ($nossl == 0 || $nossl == 1)) {
            $values = array('userid' => $userid,
                'adminname' => $this->escape($admin),
                'name' => $this->escape(htmlspecialchars($name)),
                'url' => $this->escape($url),
                'pubkey' => $this->escape($pubkey),
                'privkey' => $this->escape($privkey),
                'nossl' => $nossl,
                'nosslkey' => ($nosslkey == null ? '' : $this->escape($nosslkey)),
                'siteurl' => '',
                'ga_id' => '',
                'gas_id' => 0,
                'offline_checks' => '',
                'offline_checks_last' => 0,
                'offline_check_result' => 0,
                'note' => '',
                'statsUpdate' => 0,
                'pagerank' => 0,
                'indexed' => 0,
                'alexia' => 0,
                'pagerank_old' => 0,
                'indexed_old' => 0,
                'alexia_old' => 0,
                'directories' => '',
                'sync_errors' => '',
                'wp_upgrades' => '',
                'plugin_upgrades' => '',
                'theme_upgrades' => '',
                'premium_upgrades' => '',
                'uptodate' => '',
                'securityIssues' => '',
                'recent_comments' => '',
                'recent_posts' => '',
                'recent_pages' => '',
                'themes' => '',
                'ignored_themes' => '',
                'plugins' => '',
                'ignored_plugins' => '',
                'pages' => '',
                'users' => '',
                'categories' => '',
                'pluginDir' => '',
                'last_wp_upgrades' => '',
                'last_plugin_upgrades' => '',
                'last_theme_upgrades' => '',
                'dtsAutomaticSync' => 0,
                'dtsAutomaticSyncStart' => 0,
                'automatic_update' => 0,
                'backup_before_upgrade' => 0,
                'dtsSync' => 0,
                'dtsSyncStart' => 0,
                'totalsize' => 0,
                'extauth' => '',
                'pluginConflicts' => '',
                'themeConflicts' => '',
                'ignored_pluginConflicts' => '',
                'ignored_themeConflicts' => '',
                'last_post_gmt' => 0,
                'backups' => '',
                'mainwpdir' => 0);
            if ($wpdb->insert($this->tableName('wp'), $values)
            ) {
                $websiteid = $wpdb->insert_id;
                foreach ($groupnames as $groupname)
                {
                    if ($wpdb->insert($this->tableName('group'), array('userid' => $userid, 'name' => $this->escape(htmlspecialchars($groupname))))) {
                        $groupids[] = $wpdb->insert_id;
                    }
                }
                //add groupids
                foreach ($groupids as $groupid)
                {
                    $wpdb->insert($this->tableName('wp_group'), array('wpid' => $websiteid, 'groupid' => $groupid));
                }
                return $websiteid;
            }
        }
        return false;
    }

    public function updateGroupSite($groupId, $websiteId)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        $wpdb->insert($this->tableName('wp_group'), array('wpid' => $websiteId, 'groupid' => $groupId));
    }

    public function clearGroup($groupId)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        $wpdb->query('DELETE FROM ' . $this->tableName('wp_group') . ' WHERE groupid=' . $groupId);
    }

    public function addGroup($userid, $name)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($userid)) {
            if ($wpdb->insert($this->tableName('group'), array('userid' => $userid, 'name' => $this->escape($name)))) {
                return $wpdb->insert_id;
            }
        }
        return false;
    }

    public function removeWebsite($websiteid)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($websiteid)) {
            $nr = $wpdb->query('DELETE FROM ' . $this->tableName('wp') . ' WHERE id=' . $websiteid);
            $wpdb->query('DELETE FROM ' . $this->tableName('wp_ga') . ' WHERE wpid=' . $websiteid);
            $wpdb->query('DELETE FROM ' . $this->tableName('wp_group') . ' WHERE wpid=' . $websiteid);
            return $nr;
        }
        return false;
    }

    public function removeGroup($groupid)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($groupid)) {
            $nr = $wpdb->query('DELETE FROM ' . $this->tableName('group') . ' WHERE id=' . $groupid);
            $wpdb->query('DELETE FROM ' . $this->tableName('wp_group') . ' WHERE groupid=' . $groupid);
            return $nr;
        }
        return false;
    }

    public function updateNote($websiteid, $note)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        $wpdb->query('UPDATE ' . $this->tableName('wp') . ' SET note="' . $this->escape($note) . '" WHERE id=' . $websiteid);
    }

    public function updateWebsiteOfflineCheckSetting($websiteid, $offlineChecks)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->query('UPDATE ' . $this->tableName('wp') . ' SET offline_checks="' . $this->escape($offlineChecks) . '" WHERE id=' . $websiteid, OBJECT);
    }

    public function updateWebsiteValues($websiteid, $fields)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (count($fields) > 0) {
            return $wpdb->update($this->tableName('wp'), $fields, array('id' => $websiteid));
        }

        return false;
    }

    public function updateWebsite($websiteid, $userid, $name, $siteadmin, $groupids, $groupnames, $offlineChecks, $pluginDir)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($websiteid) && MainWPUtility::ctype_digit($userid)) {
            $website = MainWPDB::Instance()->getWebsiteById($websiteid);
            if (MainWPUtility::can_edit_website($website)) {
                //update admin
                $wpdb->query('UPDATE ' . $this->tableName('wp') . ' SET name="' . $this->escape($name) . '", adminname="' . $this->escape($siteadmin) . '",offline_checks="' . $this->escape($offlineChecks) . '",pluginDir="'.$this->escape($pluginDir).'" WHERE id=' . $websiteid);
                //remove groups
                $wpdb->query('DELETE FROM ' . $this->tableName('wp_group') . ' WHERE wpid=' . $websiteid);
                //Remove GA stats
                $wpdb->query('DELETE FROM ' . $this->tableName('wp_ga') . ' WHERE wpid=' . $websiteid);
                //add groups with groupnames
                foreach ($groupnames as $groupname)
                {
                    if ($wpdb->insert($this->tableName('group'), array('userid' => $userid, 'name' => $this->escape($groupname)))) {
                        $groupids[] = $wpdb->insert_id;
                    }
                }
                //add groupids
                foreach ($groupids as $groupid)
                {
                    $wpdb->insert($this->tableName('wp_group'), array('wpid' => $websiteid, 'groupid' => $groupid));
                }
                return true;
            }
        }
        return false;
    }

    public function updateGroup($groupid, $groupname)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($groupid))
        {
            //update groupname
            $wpdb->query('UPDATE ' . $this->tableName('group') . ' SET name="' . $this->escape($groupname) . '" WHERE id=' . $groupid);
            return true;
        }
        return false;
    }

    public function removeBackupTask($id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $this->tableName('wp_backup') . ' WHERE id = ' . $id);
    }

    public function getBackupTaskById($id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_row('SELECT * FROM ' . $this->tableName('wp_backup') . ' WHERE id= ' . $id);
    }

    public function getBackupTasksForUser($orderBy = 'name')
    {
        if (MainWPSystem::Instance()->isSingleUser())
        {
            return $this->getBackupTasks(null, $orderBy);
        }

        global $current_user;
        return $this->getBackupTasks($current_user->ID, $orderBy);
    }

    public function getBackupTasks($userid = null, $orderBy = null)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp_backup') . ' WHERE '.($userid == null ? '' : 'userid= ' . $userid . ' AND ') . ' template = 0 ' . ($orderBy != null ? 'ORDER BY ' . $orderBy : ''), OBJECT);
    }

    public function addBackupTask($userid, $name, $schedule, $type, $exclude, $sites, $groups, $subfolder, $filename, $template = 0)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($userid)) {
            $values = array('userid' => $userid,
                'name' => $name,
                'schedule' => $schedule,
                'type' => $type,
                'exclude' => $exclude,
                'sites' => $sites,
                'groups' => $groups,
                'ftp_enabled' => 0,
                'ftp_address' => '',
                'ftp_username' => '',
                'ftp_password' => '',
                'ftp_path' => '',
                'ftp_port' => '',
                'ftp_ssl' => 0,
                'amazon_enabled' => 0,
                'amazon_access' => '',
                'amazon_secret' => '',
                'amazon_bucket' => '',
                'amazon_dir' => '',
                'dropbox_enabled' => 0,
                'dropbox_username' => '',
                'dropbox_password' => '',
                'dropbox_dir' => '',
                'last' => 0,
                'last_run' => 0,
                'last_run_manually' => 0,
                'completed_sites' => '',
                'completed' => 0,
                'backup_errors' => '',
                'subfolder' => $subfolder,
                'filename' => $filename,
                'paused' => 0,
                'template' => $template);
            if ($wpdb->insert($this->tableName('wp_backup'), $values)) {
                return $this->getBackupTaskById($wpdb->insert_id);
            }
        }
        return false;
    }

    public function updateBackupTask($id, $userid, $name, $schedule, $type, $exclude, $sites, $groups, $subfolder, $filename, $ftp_enabled, $ftp_address, $ftp_username, $ftp_password, $ftp_path, $ftp_port, $ftp_ssl, $amazon_enabled, $amazon_access, $amazon_secret, $amazon_bucket, $amazon_dir, $dropbox_enabled, $dropbox_username, $dropbox_password, $dropbox_dir)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($userid) && MainWPUtility::ctype_digit($id)) {
            return $wpdb->update($this->tableName('wp_backup'), array('userid' => $userid, 'name' => $name, 'schedule' => $schedule, 'type' => $type, 'exclude' => $exclude, 'sites' => $sites, 'groups' => $groups, 'subfolder' => $subfolder, 'filename' => $filename, 'ftp_enabled' => $ftp_enabled, 'ftp_address' => $ftp_address, 'ftp_username' => $ftp_username, 'ftp_password' => $ftp_password, 'ftp_path' => $ftp_path, 'ftp_port' => $ftp_port, 'ftp_ssl' => $ftp_ssl, 'amazon_enabled' => $amazon_enabled, 'amazon_access' => $amazon_access, 'amazon_secret' => $amazon_secret, 'amazon_bucket' => $amazon_bucket, 'amazon_dir' => $amazon_dir, 'dropbox_enabled' => $dropbox_enabled, 'dropbox_username' => $dropbox_username, 'dropbox_password' => $dropbox_password, 'dropbox_dir' => $dropbox_dir), array('id' => $id));
        }
        return false;
    }

    public function updateBackupTaskWithValues($id, $values)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (!is_array($values)) return false;

        return $wpdb->update($this->tableName('wp_backup'), $values, array('id' => $id));
    }

    public function updateBackupRun($id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($id))
        {
            return $wpdb->update($this->tableName('wp_backup'), array('last_run' => time(), 'last' => time(), 'completed_sites' => json_encode(array())), array('id' => $id));
        }
        return false;
    }

    public function updateBackupRunManually($id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($id))
        {
            return $wpdb->update($this->tableName('wp_backup'), array('last_run_manually' => time()), array('id' => $id));
        }
        return false;
    }

    public function updateBackupLast($id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($id))
        {
            return $wpdb->update($this->tableName('wp_backup'), array('last' => time()), array('id' => $id));
        }
        return false;
    }

    public function updateBackupCompleted($id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($id))
        {
            return $wpdb->update($this->tableName('wp_backup'), array('completed' => time()), array('id' => $id));
        }
        return false;
    }

    public function updateBackupErrors($id, $errors)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($id))
        {
            if (($errors == null) || ($errors == ''))
            {
                return $wpdb->update($this->tableName('wp_backup'), array('backup_errors' => ''), array('id' => $id));
            }
            else
            {
                $task = $this->getBackupTaskById($id);
                return $wpdb->update($this->tableName('wp_backup'), array('backup_errors' => $task->backup_errors . $errors), array('id' => $id));
            }
        }
        return false;
    }

    public function updateCompletedSites($id, $completedSites)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPUtility::ctype_digit($id))
        {
            return $wpdb->update($this->tableName('wp_backup'), array('completed_sites' => json_encode($completedSites)), array('id' => $id));
        }
        return false;
    }

    public function getOfflineChecks()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp') . ' WHERE (offline_checks="hourly" AND ' . time() . ' - offline_checks_last >= ' . (60 * 60 * 1) . ') OR (offline_checks="2xday" AND ' . time() . ' - offline_checks_last >= ' . (60 * 60 * 12 * 1) . ') OR (offline_checks="daily" AND ' . time() . ' - offline_checks_last >= ' . (60 * 60 * 24 * 1) . ') OR (offline_checks="weekly" AND ' . time() . ' - offline_checks_last >= ' . (60 * 60 * 24 * 7) . ')', OBJECT);
    }

    public function getWebsitesCheckUpdatesCount()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_var('SELECT count(id) FROM ' . $this->tableName('wp') . ' WHERE (dtsAutomaticSyncStart = 0 OR DATE(FROM_UNIXTIME(dtsAutomaticSyncStart)) <> DATE(NOW()))');
    }

    public function getWebsitesCountWhereDtsAutomaticSyncSmallerThenStart()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        //once a day
        return $wpdb->get_var('SELECT count(id) FROM ' . $this->tableName('wp') . ' WHERE (dtsAutomaticSync < dtsAutomaticSyncStart) OR (dtsAutomaticSyncStart = 0) OR (DATE(FROM_UNIXTIME(dtsAutomaticSyncStart)) <> DATE(NOW()))');
    }

    public function getWebsitesLastAutomaticSync()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        //once a day
        return $wpdb->get_var('SELECT MAX(dtsAutomaticSync) FROM ' . $this->tableName('wp'));
    }

    public function getWebsitesCheckUpdates($limit)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        //once a day
        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp') . ' WHERE (dtsAutomaticSyncStart = 0 OR DATE(FROM_UNIXTIME(dtsAutomaticSyncStart)) <> DATE(NOW())) LIMIT 0,'.$limit, OBJECT);
    }

    public function getWebsitesStatsUpdateSQL()
    {
        //once a week
        return 'SELECT * FROM ' . $this->tableName('wp') . ' WHERE (statsUpdate = 0 OR ' . time() . ' - statsUpdate >= ' . (60 * 60 * 24 * 7) . ')';
    }

    public function updateWebsiteStats($websiteid, $pageRank, $indexed, $alexia, $pageRank_old, $indexed_old, $alexia_old)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->update($this->tableName('wp'), array('statsUpdate' => time(), 'pagerank' => $pageRank, 'indexed' => $indexed, 'alexia' => $alexia,
            'pagerank_old' => $pageRank_old, 'indexed_old' => $indexed_old, 'alexia_old' => $alexia_old), array('id' => $websiteid));
    }

    public function getBackupTasksToComplete()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp_backup') . ' WHERE paused = 0 AND completed < last_run AND '. time() . ' - last_run >= 120 AND ' . time() . ' - last >= 120', OBJECT);
    }

    public function getBackupTasksTodoDaily()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp_backup') . ' WHERE paused = 0 AND schedule="daily" AND ' . time() . ' - last_run >= ' . (60 * 60 * 24), OBJECT);
    }

    public function getBackupTasksTodoWeekly()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp_backup') . ' WHERE paused = 0 AND schedule="weekly" AND ' . time() . ' - last_run >= ' . (60 * 60 * 24 * 7), OBJECT);
    }

    public function getBackupTasksTodoMonthly()
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('wp_backup') . ' WHERE paused = 0 AND schedule="monthly" AND ' . time() . ' - last_run >= ' . (60 * 60 * 24 * 30), OBJECT);
    }

    public function getUserNotificationEmail($userid)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        $theUserId = $userid;
        if (MainWPSystem::Instance()->isSingleUser())
        {
            $theUserId = 0;
        }
        $user_email = $wpdb->get_var('SELECT user_email FROM ' . $this->tableName('users') . ' WHERE userid = ' . $theUserId);

        if ($user_email == null || $user_email == '') {
            $user_email = $wpdb->get_var('SELECT user_email FROM ' . $wpdb->prefix . 'users WHERE id = ' . $userid);
        }
        return $user_email;
    }

    public function getUserExtension()
    {
        global $current_user;
        return $this->getUserExtensionByUserId($current_user->ID);
    }

    public function getUserExtensionByUserId($userid)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (MainWPSystem::Instance()->isSingleUser()) $userid = 0;

        $row = $wpdb->get_row('SELECT * FROM ' . $this->tableName('users') . ' WHERE userid= ' . $userid, OBJECT);
        if ($row == null) {
            $this->createUserExtension($userid);
            $row = $wpdb->get_row('SELECT * FROM ' . $this->tableName('users') . ' WHERE userid= ' . $userid, OBJECT);
        }

        return $row;
    }

    protected function createUserExtension($userId)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        $fields = array('userid' => $userId, 'user_email' => '',
         'ignored_plugins' => '',
         'trusted_plugins' => '',
         'trusted_plugins_notes' => '',
         'ignored_themes' => '',
         'trusted_themes' => '',
         'trusted_themes_notes' => '',
         'pluginDir' => '',
         'ignored_pluginConflicts' => '',
         'ignored_themeConflicts' => '');

        $wpdb->insert($this->tableName('users'), $fields);
    }

    public function updateUserExtension($userExtension)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        $userid = $userExtension->userid;
        if ($userid == null)
        {
            if (MainWPSystem::Instance()->isSingleUser())
            {
                $userid = '0';
            }
            else
            {
                global $current_user;
                $userid = $current_user->ID;
            }
        }
        $row = $wpdb->get_row('SELECT * FROM ' . $this->tableName('users') . ' WHERE userid= ' . $userid, OBJECT);
        if ($row == null) {
            $this->createUserExtension($userid);
        }

        $fields = array();
        foreach ($userExtension as $field => $value)
        {
            if ($value != $row->$field) {
                $fields[$field] = $value;
            }
        }

        if (count($fields) > 0) {
            $wpdb->update($this->tableName('users'), $fields, array('userid' => $userid));
        }

        $row = $wpdb->get_row('SELECT * FROM ' . $this->tableName('users') . ' WHERE userid= ' . $userid, OBJECT);

        return $row;
    }

    public function getTips()
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . $this->tableName('tips') . ' ORDER BY seq ASC', OBJECT);
    }

    public function addTip($tip_seq, $tip_content)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        return $wpdb->insert($this->tableName('tips'), array('seq' => $tip_seq, 'content' => $tip_content));
    }

    public function updateTip($tip_id, $tip_seq, $tip_content)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        return $wpdb->update($this->tableName('tips'), array('seq' => $tip_seq, 'content' => $tip_content), array('id' => $tip_id));
    }

    public function deleteTip($tip_id)
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        return $wpdb->query('DELETE FROM ' . $this->tableName('tips') . ' WHERE id = ' . $tip_id);
    }

    public function getMySQLVersion()
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        return $wpdb->get_var('SHOW VARIABLES LIKE "version"', 1);
    }

    public function getRowResult($sql)
    {
        if ($sql == null) return null;

        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_row($sql, OBJECT);
    }

    public function getResultsResult($sql)
    {
        if ($sql == null) return null;

        /** @var $wpdb wpdb */
        global $wpdb;

        return $wpdb->get_results($sql, OBJECT_K);
    }

    public function query($sql)
    {
        if ($sql == null) return false;

        /** @var $wpdb wpdb */
        global $wpdb;
        $result = @self::_query($sql, $wpdb->dbh);

        if (!$result || (@MainWPDB::num_rows($result) == 0)) return false;
        return $result;
    }

    protected function escape($data)
    {
        /** @var $wpdb wpdb */
        global $wpdb;

        if (function_exists('esc_sql')) return esc_sql($data);
        else return $wpdb->escape($data);
    }

    //Support old & new versions of wordpress (3.9+)
    public static function use_mysqli()
    {
        /** @var $wpdb wpdb */
        if (!function_exists( 'mysqli_connect' ) ) return false;

        global $wpdb;
        return ($wpdb->dbh instanceof mysqli);
    }

    public static function ping($link)
    {
        if (self::use_mysqli())
        {
            return mysqli_ping($link);
        }
        else
        {
            return mysql_ping($link);
        }
    }

    public static function _query($query, $link)
    {
        if (self::use_mysqli())
        {
            return mysqli_query($link, $query);
        }
        else
        {
            return mysql_query($query, $link);
        }
    }

    public static function fetch_object($result)
    {
        if (self::use_mysqli())
        {
            return mysqli_fetch_object($result);
        }
        else
        {
            return mysql_fetch_object($result);
        }
    }

    public static function free_result($result)
    {
        if (self::use_mysqli())
        {
            return mysqli_free_result($result);
        }
        else
        {
            return mysql_free_result($result);
        }
    }

    public static function data_seek($result, $offset)
    {
        if (self::use_mysqli())
        {
            return mysqli_data_seek($result, $offset);
        }
        else
        {
            return mysql_data_seek($result, $offset);
        }
    }

    public static function fetch_array($result, $result_type = null)
    {
        if (self::use_mysqli())
        {
            return mysqli_fetch_array($result, ($result_type == null ? MYSQLI_BOTH : $result_type));
        }
        else
        {
            return mysql_fetch_array($result, ($result_type == null ? MYSQL_BOTH : $result_type));
        }
    }

    public static function num_rows($result)
    {
        if (self::use_mysqli())
        {
            return mysqli_num_rows($result);
        }
        else
        {
            return mysql_num_rows($result);
        }
    }

    public static function is_result($result)
    {
        if (self::use_mysqli())
        {
            return ($result instanceof mysqli_result);
        }
        else
        {
            return is_resource($result);
        }
    }
}

?>