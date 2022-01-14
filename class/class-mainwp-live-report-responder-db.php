<?php
/**
 * MainWP Client Live Reports
 *
 * Legacy Client Reports Extension.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Live_Reports_Responder_DB
 *
 * @package MainWP\Dashboard
 */
class MainWP_Live_Reports_Responder_DB {
	//  phpcs:disable PSR1.Classes.ClassDeclaration,Generic.Files.OneObjectStructurePerFile,WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions - Deprecated

	/**
	 * @var string $mainwp_wpcreport_db_version WordPress Client Report database version.
	 */
	private $mainwp_wpcreport_db_version = '5.6';

	/**
	 * @var string $table_prefix Table prefix.
	 */
	private $table_prefix;

	/**
	 * @staic
	 * @var null Public static instance.
	 */
	private static $instance = null;

	/**
	 * MainWP_Live_Reports_Responder_DB constructor.
	 *
	 * Run each time the class is called.
	 * Initialize default tokens upon creation of the object.
	 */
	public function __construct() {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$this->table_prefix      = $wpdb->prefix . 'mainwp_';
		$this->default_tokens    = array(
			'client.site.name'         => 'Displays the Site Name',
			'client.site.url'          => 'Displays the Site Url',
			'client.name'              => 'Displays the Client Name',
			'client.contact.name'      => 'Displays the Client Contact Name',
			'client.contact.address.1' => 'Displays the Client Contact Address 1',
			'client.contact.address.2' => 'Displays the Client Contact Address 2',
			'client.company'           => 'Displays the Client Company',
			'client.city'              => 'Displays the Client City',
			'client.state'             => 'Displays the Client State',
			'client.zip'               => 'Displays the Client Zip',
			'client.phone'             => 'Displays the Client Phone',
			'client.email'             => 'Displays the Client Email',
		);
		$default_report_logo     = MAINWP_PLUGIN_URL . 'assets/images/default-report-logo.png';
		$this->default_reports[] = array(
			'title'  => 'Default Basic Report',
			'header' => '<img style="float:left" src="' . $default_report_logo . '" alt="default-report-logo" width="300" height="56" /><br/><br/>Hello [client.contact.name],',
			'body'   => '<h3>Activity report for the [client.site.url]:</h3>
			<h3>Plugins</h3>
			<strong>Installed Plugins:</strong> [plugin.installed.count]
			<strong>Activated Plugins:</strong> [plugin.activated.count]
			<strong>Edited Plugins:</strong> [plugin.edited.count]
			<strong>Deactivated Plugins:</strong> [plugin.deactivated.count]
			<strong>Updated Plugins:</strong> [plugin.updated.count]
			<strong>Deleted Plugins:</strong> [plugin.deleted.count]
			<h3>Themes</h3>
			<strong>Installed Themes:</strong> [theme.installed.count]
			<strong>Activated Themes:</strong> [theme.activated.count]
			<strong>Edited Themes:</strong> [theme.edited.count]
			<strong>Updated Themes:</strong> [theme.updated.count]
			<strong>Deleted Themes:</strong> [theme.deleted.count]
			<h3>Posts</h3>
			<strong>Created Posts: </strong> [post.created.count]
			<strong>Updated Posts: </strong> [post.updated.count]
			<strong>Trashed Posts: </strong> [post.trashed.count]
			<strong>Deleted Posts: </strong> [post.deleted.count]
			<strong>Restored Posts: </strong> [post.restored.count]
			<h3>Pages</h3>
			<strong>Created Pages:</strong> [page.created.count]
			<strong>Updated Pages:</strong> [page.updated.count]
			<strong>Trashed Pages:</strong> [page.trashed.count]
			<strong>Deleted Pages:</strong> [page.deleted.count]
			<strong>Restored Pages: </strong> [page.restored.count]
			<h3>Users</h3>
			<strong>Created Users:</strong> [user.created.count]
			<strong>Updated Users:</strong> [user.updated.count]
			<strong>Deleted Users:</strong> [user.deleted.count]
			<h3>Comments</h3>
			<strong>Created Comments:</strong> [commet.created.count]
			<strong>Trashed Comments:</strong> [comment.trashed.count]
			<strong>Deleted Comments:</strong> [comment.deleted.count]
			<strong>Edited Comments:</strong> [comment.edited.count]
			<strong>Restored Comments:</strong> [comment.restored.count]
			<strong>Approved Comments:</strong> [comment.approved.count]
			<strong>Spammed Comments:</strong> [comment.spam.count]
			<strong>Replied Comments:</strong> [comment.replied.count]
			<h3>Media</h3>
			<strong>Uploaded Media:</strong> [media.uploaded.count]
			<strong>Updated Media:</strong> [media.updated.count]
			<strong>Deleted Media:</strong> [media.deleted.count]
			<h3>Widgets</h3>
			<strong>Added Widgets:</strong> [widget.added.count]
			<strong>Updated Widgets:</strong> [widget.updated.count]
			<strong>Deleted Widgets:</strong> [widget.deleted.count]
			<h3>Menus</h3>
			<strong>Created Menus:</strong> [menu.created.count]
			<strong>Updated Menus:</strong> [menu.updated.count]
			<strong>Deleted Menus:</strong> [menu.deleted.count]
			<h3>WordPress</h3>
			<strong>WordPress Updates:</strong> [wordpress.updated.count]',
		);

		$this->default_reports[] = array(
			'title'  => 'Default Full Report',
			'header' => '<img style="float:left" src="' . $default_report_logo . '" alt="default-report-logo" width="300" height="56" /><br/><br/><br/>Hello [client.contact.name],',
			'body'   => '<h3>Activity report for the [client.site.url]:</h3>
			<h3>Plugins</h3>
			<strong>[plugin.installed.count] Plugins Installed</strong>
			[section.plugins.installed]
			([plugin.installed.date]) [plugin.name] by [plugin.installed.author];
			[/section.plugins.installed]

			<strong>[plugin.activated.count] Plugins Activated</strong>
			[section.plugins.activated]
			([plugin.activated.date]) [plugin.name] by [plugin.activated.author];
			[/section.plugins.activated]

			<strong>[plugin.edited.count] Plugins Edited</strong>
			[section.plugins.edited]
			([plugin.edited.date]) [plugin.name] by [plugin.edited.author];
			[/section.plugins.edited]

			<strong>[plugin.deactivated.count] Plugins Deactivated</strong>
			[section.plugins.deactivated]
			([plugin.deactivated.date]) [plugin.name] by [plugin.deactivated.author];
			[/section.plugins.deactivated]

			<strong>[plugin.updated.count] Plugins Updated</strong>
			[section.plugins.updated]
			([plugin.updated.date]) [plugin.name] by [plugin.updated.author] - [plugin.old.version] to [plugin.current.version];
			[/section.plugins.updated]

			<strong>[plugin.deleted.count] Plugins Deleted</strong>
			[section.plugins.deleted]
			([plugin.deleted.date]) [plugin.name] by [plugin.deleted.author];
			[/section.plugins.deleted]
			<h3>Themes</h3>
			<strong>[theme.installed.count] Themes Installed</strong>
			[section.themes.installed]
			([theme.installed.date]) [theme.name] by [theme.installed.author];
			[/section.themes.installed]

			<strong>[theme.activated.count] Themes Activated</strong>
			[section.themes.activated]
			([theme.activated.date]) [theme.name] by [theme.activated.author];
			[/section.themes.activated]

			<strong>[theme.edited.count] Themes Edited</strong>
			[section.themes.edited]
			([theme.edited.date]) [theme.name] by [theme.edited.author];
			[/section.themes.edited]

			<strong>[theme.updated.count] Themes Updated</strong>
			[section.themes.updated]
			([theme.updated.date]) [theme.name] by [theme.updated.author] - [theme.old.version] to [theme.current.version] ;
			[/section.themes.updated]

			<strong>[theme.deleted.count] Themes Deleted</strong>
			[section.themes.deleted]
			([theme.deleted.date]) [theme.name] by [theme.deleted.author];
			[/section.themes.deleted]
			<h3>Posts</h3>
			<strong>[post.created.count] Created Posts</strong>
			[section.posts.created]
			([post.created.date]) [post.title] by [post.created.author];
			[/section.posts.created]

			<strong>[post.updated.count] Updated Posts</strong>
			[section.posts.updated]
			([post.updated.date]) [post.title] by [post.updated.author];
			[/section.posts.updated]

			<strong>[post.trashed.count] Trashed Posts</strong>
			[section.posts.trashed]
			([post.trashed.date]) [post.title] by [post.trashed.author];
			[/section.posts.trashed]

			<strong>[post.deleted.count] Deleted Posts</strong>
			[section.posts.deleted]
			([post.deleted.date]) [post.title] by [post.deleted.author];
			[/section.posts.deleted]

			<strong>[post.restored.count] Restored Posts</strong>
			[section.posts.restored]
			([post.restored.date]) [post.title] by [post.restored.author];
			[/section.posts.restored]
			<h3>Pages</h3>
			<strong>[page.created.count] Created Pages</strong>
			[section.pages.created]
			([page.created.date]) [page.title] by [page.created.author];
			[/section.pages.created]

			<strong>[page.updated.count] Updated Pages</strong>
			[section.pages.updated]
			([page.updated.date]) [page.title] by [post.page.author];
			[/section.page.updated]

			<strong>[page.trashed.count] Trashed Pages</strong>
			[section.pages.trashed]
			([page.trashed.date]) [page.title] by [page.trashed.author];
			[/section.pages.trashed]

			<strong>[page.deleted.count] Deleted Pages</strong>
			[section.pages.deleted]
			([page.deleted.date]) [page.title] by [page.deleted.author];
			[/section.pages.deleted]

			<strong>[page.restored.count] Restored Pages</strong>
			[section.pages.restored]
			([page.restored.date]) [page.title] by [page.restored.author];
			[/section.pages.restored]
			<h3>Users</h3>
			<strong>[user.created.count] Created Users</strong>
			[section.users.created]
			([user.created.date]) [user.name] ([user.created.role]) by [user.created.author];
			[/section.users.created]

			<strong>[user.updated.count] Updated Users</strong>
			[section.users.updated]
			([user.updated.date]) [user.name] ([user.updated.role]) by [user.updated.author];
			[/section.users.updated]

			<strong>[user.deleted.count] Deleted Users</strong>
			[section.users.deleted]
			([user.deleted.date]) [user.name] by [user.deleted.author];
			[/section.users.deleted]
			<h3>Comments</h3>
			<strong>[comment.created.count] Created Comments</strong>
			[section.comments.created]
			([comment.created.date]) [comment.title] by [comment.created.author];
			[/section.comments.created]

			<strong>[comment.trashed.count] Trashed Comments</strong>
			[section.comments.trashed]
			([comment.trashed.date]) [comment.title] by [comment.trashed.author];
			[/section.comments.trashed]

			<strong>[comment.deleted.count] Deleted Comments</strong>
			[section.comments.deleted]
			([comment.deleted.date]) [comment.title] by [comment.deleted.author];
			[/section.comments.deleted]

			<strong>[comment.edited.count] Edited Comments</strong>
			[section.comments.edited]
			([comment.edited.date]) [comment.title] by [comment.edited.author];
			[/section.comments.edited]

			<strong>[comment.restored.count] Restored Comments</strong>
			[section.comments.restored]
			([comment.restored.date]) [comment.title] by [comment.restored.author];
			[/section.comments.restored]

			<strong>[comment.approved.count] Approved Comments</strong>
			[section.comments.approved]
			([comment.approved.date]) [comment.title] by [comment.approved.author];
			[/section.comments.approved]

			<strong>[comment.spam.count] Spammed Comments</strong>
			[section.comments.spam]
			([comment.spam.date]) [comment.title] by [comment.spam.author];
			[/section.comments.spam]

			<strong>[comment.replied.count] Replied Comments</strong>
			[section.comments.replied]
			([comment.replied.date]) [comment.title] by [comment.replied.author];
			[/section.comments.replied]
			<h3>Media</h3>
			<strong>[media.uploaded.count] Uploaded Media</strong>
			[section.media.uploaded]
			([media.uploaded.date]) [media.name] by [media.uploaded.author];
			[/section.media.uploaded]

			<strong>[media.updated.count] Updated Media</strong>
			[section.media.updated]
			([media.updated.date]) [media.name] by [media.updated.author];
			[/section.media.updated]

			<strong>[media.deleted.count] Deleted Media</strong>
			[section.media.deleted]
			([media.deleted.date]) [media.name] by [media.deleted.author];
			[/section.media.deleted]
			<h3>Widgets</h3>
			<strong>[widget.added.count] Added Widgets</strong>
			[section.widgets.added]
			([widget.added.date]) [widget.title] added in [widget.added.area] by [widget.added.author];
			[/section.widgets.added]

			<strong>[widget.updated.count] Updated Widgets</strong>
			[section.widgets.updated]
			([widget.updated.date]) [widget.title] in [widget.updated.area] by [widget.updated.author];
			[/section.widgets.updated]

			<strong>[widget.deleted.count] Deleted Widgets</strong>
			[section.widgets.deleted]
			([widget.deleted.date]) [widget.title] in [widget.deleted.area] by [widget.deleted.author];
			[/section.widgets.deleted]
			<h3>Menus</h3>
			<strong>[menu.created.count] Created Menus</strong>
			[section.menus.created]
			([menu.added.date]) [menu.title] by [menu.added.author];
			[/section.menus.created]

			<strong>[menu.updated.count] Updated Menus</strong>
			[section.menus.updated]
			([menu.updated.date]) [menu.title] by [menu.updated.author];
			[/section.menus.updated]

			<strong>[menu.deleted.count] Deleted Menus</strong>
			[section.menus.deleted]
			([menu.deleted.date]) [menu.title] by [menu.deleted.author];
			[/section.menus.deleted]
			<h3>WordPress</h3>
			<strong>[wordpress.updated.count] Updates WordPress</strong>
			[section.wordpress.updated]
			([wordpress.updated.date]) Updated by [wordpress.updated.author] - [wordpress.old.version] to [wordpress.current.version]
			[/section.wordpress.updated]',
		);
		$this->default_formats   = array(
			array(
				'title'   => 'Default Header',
				'type'    => 'H',
				'content' => $this->default_reports[0]['header'],
			),
			array(
				'title'   => ' Basic Report',
				'type'    => 'B',
				'content' => $this->default_reports[0]['body'],
			),
			array(
				'title'   => 'Full Report',
				'type'    => 'B',
				'content' => $this->default_reports[1]['body'],
			),
		);
	}

	/**
	 * Method table_name()
	 *
	 * Add suffix to table_prefix.
	 *
	 * @param mixed $suffix Given table suffix.
	 *
	 * @return string Table name.
	 */
	public function table_name( $suffix ) {
		return $this->table_prefix . $suffix;
	}

	/**
	 * Method use_mysqli()
	 *
	 * Determine whether a $wpdb variable is an instantiated object of mysqli.
	 *
	 * @return (bool) Return true on seuccess and false on failer.
	 */
	public static function use_mysqli() {
		if ( ! function_exists( '\mysqli_connect' ) ) {
			return false;
		}

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		return ( $wpdb->dbh instanceof \mysqli );
	}

	/**
	 * Method install()
	 *
	 * Create database structure.
	 *
	 * @return (int|false) Return report ID on success and false on failer.
	 */
	public function install() {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$currentVersion = get_site_option( 'mainwp_wpcreport_db_version' );
		if ( ! empty( $currentVersion ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = array();

		$tbl = 'CREATE TABLE `' . $this->table_name( 'client_report_token' ) . '` (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`token_name` varchar(512) NOT NULL DEFAULT "",
		`token_description` text NOT NULL,
		`type` tinyint(1) NOT NULL DEFAULT 0';
		if ( '' == $currentVersion ) {
			$tbl .= ',
			PRIMARY KEY  (`id`)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE `' . $this->table_name( 'client_report_site_token' ) . '` (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`site_url` varchar(255) NOT NULL,
		`token_id` int(12) NOT NULL,
		`token_value` varchar(512) NOT NULL';
		if ( '' == $currentVersion ) {
			$tbl .= ',
			PRIMARY KEY  (`id`)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE `' . $this->table_name( 'client_report' ) . '` (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`title` text NOT NULL,
		`date_from` int(11) NOT NULL,
		`date_to` int(11) NOT NULL,
		`fname` VARCHAR(512),
		`fcompany` VARCHAR(512),
		`femail` VARCHAR(128),
		`bcc_email` VARCHAR(128),
		`client_id` int(11) NOT NULL,
		`header` longtext NOT NULL,
		`body` longtext NOT NULL,
		`footer` longtext NOT NULL,
		`attach_files` text NOT NULL,
		`lastsend` int(11) NOT NULL,
		`nextsend` int(11) NOT NULL,
		`subject` text NOT NULL,
		`recurring_schedule` VARCHAR(32) NOT NULL DEFAULT "",
		`recurring_day` VARCHAR(10) DEFAULT NULL,
		`schedule_send_email` VARCHAR(32) NOT NULL,
		`schedule_bcc_me` tinyint(1) NOT NULL DEFAULT 0,
		`scheduled` tinyint(1) NOT NULL DEFAULT 0,
		`schedule_nextsend` int(11) NOT NULL,
		`schedule_lastsend` int(11) NOT NULL,
		`completed` int(11) NOT NULL,
		`completed_sites` text NOT NULL,
		`sending_errors` text NOT NULL,
		`is_archived` tinyint(1) NOT NULL DEFAULT 0,
		`sites` text NOT NULL,
		`groups` text NOT NULL';

		if ( '' === $currentVersion ) {
			$tbl .= ',
			PRIMARY KEY  (`id`)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE `' . $this->table_name( 'client_report_client' ) . '` (
		`clientid` int(11) NOT NULL AUTO_INCREMENT,
		`client` text NOT NULL,
		`name` VARCHAR(512),
		`company` VARCHAR(512),
		`email` text NOT NULL';
		if ( '' == $currentVersion ) {
			$tbl .= ',
			PRIMARY KEY  (`clientid`)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE `' . $this->table_name( 'client_report_format' ) . '` (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`title` VARCHAR(512),
		`content` longtext NOT NULL,
		`type` CHAR(1)';
		if ( '' === $currentVersion || '1.3' === $currentVersion ) {
			$tbl .= ',
			PRIMARY KEY  (`id`)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		foreach ( $this->default_tokens as $token_name => $token_description ) {
			$token   = array(
				'type'              => 1,
				'token_name'        => $token_name,
				'token_description' => $token_description,
			);
			$current = $this->get_tokens_by( 'token_name', $token_name );
			if ( $current ) {
				$this->update_token( $current->id, $token );
			} else {
				$this->add_token( $token );
			}
		}

		foreach ( $this->default_reports as $report ) {
			$current = $this->get_report_by( 'title', $report['title'] );
			if ( $current ) {
				$current               = current( $current );
				$report['id']          = $current->id;
				$report['is_archived'] = 0;
				$this->update_report( $report );
			} else {
				$this->update_report( $report );
			}
		}

		foreach ( $this->default_formats as $format ) {
			$current = $this->get_format_by( 'title', $format['title'], $format['type'] );
			if ( $current ) {
				$format['id'] = $current->id;
				$this->update_format( $format );
			} else {
				$this->update_format( $format );
			}
		}

		update_option( 'mainwp_wpcreport_db_version', $this->mainwp_wpcreport_db_version );
	}

	/**
	 * Method get_instance()
	 *
	 * Create a new public static instance of
	 * MainWP_Live_Reports_Responder_DB().
	 *
	 * @return void $instance New public static Instance.
	 */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new MainWP_Live_Reports_Responder_DB();
		}
		return self::$instance;
	}

	/**
	 * Method add_token()
	 *
	 * Add Report token.
	 *
	 * @param array $token Token Array.
	 *
	 * @return (int|bool) Return int Token ID on success and false on failer.
	 */
	public function add_token( $token ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( ! empty( $token['token_name'] ) && ! empty( $token['token_description'] ) ) {
			$current = $this->get_tokens_by( 'token_name', $token['token_name'] );
			if ( $current ) {
				return false;
			}
			if ( $wpdb->insert( $this->table_name( 'client_report_token' ), $token ) ) {
				return $this->get_tokens_by( 'id', $wpdb->insert_id );
			}
		}
		return false;
	}

	/**
	 * Method update_token()
	 *
	 * Update report token.
	 *
	 * @param mixed $id Report ID.
	 * @param mixed $token Token ID.
	 *
	 * @return (int|bool) Return int token ID or false on failer.
	 */
	public function update_token( $id, $token ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$id = intval( $id );
		if ( $id && ! empty( $token['token_name'] ) && ! empty( $token['token_description'] ) ) {
			if ( $wpdb->update( $this->table_name( 'client_report_token' ), $token, array( 'id' => intval( $id ) ) ) ) {
				return $this->get_tokens_by( 'id', $id );
			}
		}
		return false;
	}

	/**
	 * Method get_tokens_by()
	 *
	 * Get report tokens by ID, name or URL.
	 *
	 * @param string $by By token name or token ID. Default: id.
	 * @param null   $value Token ID.
	 * @param string $site_url Child Site URL.
	 *
	 * @return (array|object|null|void) Database query result by token or null on failure
	 */
	public function get_tokens_by( $by = 'id', $value = null, $site_url = '' ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( empty( $by ) || empty( $value ) ) {
			return null;
		}

		if ( 'token_name' === $by ) {
			$value = str_replace( array( '[', ']' ), '', $value );
		}

		$sql = '';
		if ( 'id' === $by ) {
			$sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE `id`=%d ', $value );
		} elseif ( 'token_name' === $by ) {
			$sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE `token_name` = %s ', $value );
		}

		$token = null;
		if ( ! empty( $sql ) ) {
			$token = $wpdb->get_row( $sql );
		}

		$site_url = trim( $site_url );

		if ( empty( $site_url ) ) {
			return $token;
		}

		if ( $token && ! empty( $site_url ) ) {
			$sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'client_report_site_token' ) . ' WHERE site_url =%s  AND token_id = %d', $this->escape( $site_url ), $token->id );

			$site_token = $wpdb->get_row( $sql );
			if ( $site_token ) {
				$token->site_token = $site_token;
				return $token;
			} else {
				return null;
			}
		}
		return null;
	}

	/**
	 * Method get_tokens()
	 *
	 * Get all report tokens.
	 *
	 * @return (array|object|null) Database query results.
	 */
	public function get_tokens() {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE 1 = 1 ORDER BY type DESC, token_name ASC' );
	}

	/**
	 * Method get_site_token_values()
	 *
	 * Get Child site token values.
	 *
	 * @param mixed $id Token ID.
	 *
	 * @return (array|object|null) Database query results.
	 *
	 * @return void
	 */
	public function get_site_token_values( $id ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( empty( $id ) ) {
			return false;
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT st.* FROM ' . $this->table_name( 'client_report_site_token' ) . ' st WHERE st.token_id = %d', $id ) );
	}

	/**
	 * Method get_site_tokens()
	 *
	 * @param mixed  $site_url Child Site URL.
	 *
	 * @param string $index Default: id.
	 *
	 * @return array $return Array of tokens.
	 */
	public function get_site_tokens( $site_url, $index = 'id' ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$site_url = trim( $site_url );
		if ( empty( $site_url ) ) {
			return false;
		}

		$site_tokens = $wpdb->get_results( $wpdb->prepare( ' SELECT st.*, t.token_name FROM ' . $this->table_name( 'client_report_site_token' ) . ' st , ' . $this->table_name( 'client_report_token' ) . ' t WHERE st.site_url = %s AND st.token_id = t.id', $site_url ) );

		$return = array();
		if ( is_array( $site_tokens ) ) {
			foreach ( $site_tokens as $token ) {
				if ( 'id' === $index ) {
					$return[ $token->token_id ] = $token;
				} else {
					$return[ $token->token_name ] = $token;
				}
			}
		}
		$tokens = $this->get_tokens();
		if ( is_array( $tokens ) ) {
			foreach ( $tokens as $token ) {
				if ( is_object( $token ) ) {
					if ( 'id' === $index ) {
						if ( 1 === $token->type && ( ! isset( $return[ $token->id ] ) || empty( $return[ $token->id ] ) ) ) {
							if ( ! isset( $return[ $token->id ] ) ) {
								$return[ $token->id ] = new \stdClass();
							}
							$return[ $token->id ]->token_value = $this->get_default_token_site( $token->token_name, $site_url );
						}
					} else {
						if ( 1 === $token->type && ( ! isset( $return[ $token->token_name ] ) || empty( $return[ $token->token_name ] ) ) ) {
							if ( ! isset( $return[ $token->token_name ] ) ) {
								$return[ $token->token_name ] = new \stdClass();
							}
							$return[ $token->token_name ]->token_value = $this->get_default_token_site( $token->token_name, $site_url );
						}
					}
				}
			}
		}
		return $return;
	}

	/**
	 * Method get_default_token_site()
	 *
	 * Get default Child Site token.
	 *
	 * @param mixed $token_name Token name.
	 * @param mixed $site_url Child ite URL.
	 *
	 * @return (string|bool) Return string Child Site name|URL or false on failer.
	 */
	public function get_default_token_site( $token_name, $site_url ) {

		$website = apply_filters( 'mainwp_getwebsitesbyurl', $site_url );

		if ( empty( $this->default_tokens[ $token_name ] ) || ! $website ) {
			return false;
		}

		$website = current( $website );
		if ( is_object( $website ) ) {
			$url_site  = $website->url;
			$name_site = $website->name;
		} else {
			return false;
		}

		switch ( $token_name ) {
			case 'client.site.url':
				$token_value = $url_site;
				break;
			case 'client.site.name':
				$token_value = $name_site;
				break;
			default:
				$token_value = '';
				break;
		}

		return $token_value;
	}

	/**
	 * Method add_token_site()
	 *
	 * Add Child Site token.
	 *
	 * @param mixed $token_id Token ID.
	 * @param mixed $token_value Token value.
	 * @param mixed $site_url Child Site URL.
	 *
	 * @return string Child Site token value.
	 */
	public function add_token_site( $token_id, $token_value, $site_url ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( empty( $token_id ) ) {
			return false;
		}

		$website = apply_filters( 'mainwp_getwebsitesbyurl', $site_url );
		if ( empty( $website ) ) {
			return false;
		}

		if ( $wpdb->insert(
			$this->table_name( 'client_report_site_token' ),
			array(
				'token_id'    => $token_id,
				'token_value' => $token_value,
				'site_url'    => $site_url,
			)
		)
		) {
			return $this->get_tokens_by( 'id', $token_id, $site_url );
		}

		return false;
	}

	/**
	 * Method update_token_site()
	 *
	 * Update Child Site token value.
	 *
	 * @param mixed $token_id Token ID.
	 * @param mixed $token_value Token value.
	 * @param mixed $site_url Child Site URL.
	 *
	 * @return (string|bool) Return token value or false on failer.
	 */
	public function update_token_site( $token_id, $token_value, $site_url ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( empty( $token_id ) ) {
			return false;
		}

		$website = apply_filters( 'mainwp_getwebsitesbyurl', $site_url );
		if ( empty( $website ) ) {
			return false;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table_name( 'client_report_site_token' ) . 'SET `token_value` = %s WHERE `token_id` = %d AND site_url = %s',
				$this->escape( $token_value ),
				intval( $token_id ),
				$this->escape( $site_url )
			)
		);

		if ( $result ) {
			return $this->get_tokens_by( 'id', $token_id, $site_url );
		}

		return false;
	}

	/**
	 * Method delete_site_tokens()
	 *
	 * Delete Child Site token value.
	 *
	 * @param null $token_id Token ID.
	 * @param null $site_url Child SIte URL.
	 *
	 * @return (int|bool) Number of rows affected/selected for all other queries and Boolean true. Boolean false on error.
	 */
	public function delete_site_tokens( $token_id = null, $site_url = null ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( ! empty( $token_id ) ) {
			return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_site_token' ) . ' WHERE token_id = %d ', $token_id ) );
		} elseif ( ! empty( $site_url ) ) {
			return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_site_token' ) . ' WHERE site_url = %s ', $site_url ) );
		}
		return false;
	}

	/**
	 * Method delete_token_by()
	 *
	 * Delete Child Site token by id.
	 *
	 * @param string $by Query type. Default: 'id'.
	 * @param null   $value Token id.
	 *
	 * @return (bool) Boolean true on success. Boolean false on error.
	 */
	public function delete_token_by( $by = 'id', $value = null ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( 'id' === $by ) {
			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE id=%d ', $value ) ) ) {
				$this->delete_site_tokens( $value );
				return true;
			}
		}
		return false;
	}

	/**
	 * Method update_report()
	 *
	 * Update Client Report.
	 *
	 * @param array $report Client Report array.
	 *
	 * @return (string|bool) Client Report token value. Boolean false on failer.
	 */
	public function update_report( $report ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$id            = isset( $report['id'] ) ? $report['id'] : 0;
		$updatedClient = false;

		if ( ! empty( $report['client'] ) || ! empty( $report['email'] ) ) {
			$client_id = 0;
			if ( ! empty( $report['client'] ) ) {
				$update_client = array(
					'client'  => isset( $report['client'] ) ? $report['client'] : '',
					'name'    => isset( $report['name'] ) ? $report['name'] : '',
					'company' => isset( $report['company'] ) ? $report['company'] : '',
					'email'   => isset( $report['email'] ) ? $report['email'] : '',
				);

				if ( isset( $report['client_id'] ) && ! empty( $report['client_id'] ) ) {
					$update_client['clientid'] = $report['client_id'];
				} else {
					$client = null;
					$client = $this->get_client_by( 'client', $report['client'] );
					if ( empty( $client ) && ! empty( $report['email'] ) ) {
						$client = $this->get_client_by( 'email', $report['client'] );
					}

					if ( ! empty( $client ) ) {
						$client_id                 = $client->clientid;
						$update_client['clientid'] = $client_id;
					}
				}

				$updatedClient = $this->update_client( $update_client );
				if ( $updatedClient ) {
					$client_id = $updatedClient->clientid;
				}
			} elseif ( ! empty( $report['email'] ) ) {
				$client = $this->get_client_by( 'email', $report['client'] );
				if ( ! empty( $client ) ) {
					$client_id = $client->clientid;
				} else {
					$update_client = array(
						'client'  => '',
						'name'    => isset( $report['name'] ) ? $report['name'] : '',
						'company' => isset( $report['company'] ) ? $report['company'] : '',
						'email'   => isset( $report['email'] ) ? $report['email'] : '',
					);
					$updatedClient = $this->update_client( $update_client );
					if ( $updatedClient ) {
						$client_id = $updatedClient->clientid;
					}
				}
			}

			if ( empty( $client_id ) && ! empty( $report['client_id'] ) ) {
				$client_id = $report['client_id'];
			}

			$report['client_id'] = $client_id;
		} else {
			if ( isset( $report['client_id'] ) ) {
				$report['client_id'] = 0;
			}
		}

		$report_fields = array(
			'id',
			'title',
			'date_from',
			'date_to',
			'fname',
			'fcompany',
			'femail',
			'bcc_email',
			'client_id',
			'header',
			'body',
			'footer',
			'logo_file',
			'lastsend',
			'nextsend',
			'subject',
			'recurring_schedule',
			'recurring_day',
			'schedule_send_email',
			'schedule_bcc_me',
			'is_archived',
			'attach_files',
			'scheduled',
			'schedule_lastsend',
			'schedule_nextsend',
			'sites',
			'groups',
		);

		$update_report = array();
		foreach ( $report as $key => $value ) {
			if ( in_array( $key, $report_fields ) ) {
				$update_report[ $key ] = $value;
			}
		}
		if ( ! empty( $id ) ) {
			$updatedReport = $wpdb->update( $this->table_name( 'client_report' ), $update_report, array( 'id' => intval( $id ) ) );
			if ( ! empty( $updatedReport ) || ! empty( $updatedClient ) ) {
				return $this->get_report_by( 'id', $id );
			}
		} else {
			if ( $wpdb->insert( $this->table_name( 'client_report' ), $update_report ) ) {
				return $this->get_report_by( 'id', $wpdb->insert_id );
			}
		}
		return false;
	}

	/**
	 * Method get_report_by()
	 *
	 * Get Client Report by given query type $by.
	 *
	 * @param string $by Query type. Default: 'id'. Choices: id, client, site, title, all.
	 * @param null   $value Further variables to substitute into the query's placeholders if being called with individual arguments.
	 * @param string $orderby Order By. Default: null. Choices: client, name.
	 * @param string $order Order. Default: null. Choices: client, name.
	 * @param object $output Report object.
	 *
	 * @return (object|bool) Return Client Report object or false on failer.
	 */
	public function get_report_by( $by = 'id', $value = null, $orderby = null, $order = null, $output = OBJECT ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( empty( $by ) || ( 'all' !== $by && empty( $value ) ) ) {
			return false;
		}

		$_order_by = '';
		if ( ! empty( $orderby ) ) {
			if ( 'client' === $orderby || 'name' === $orderby ) {
				$orderby = 'c.' . $orderby;
			} else {
				$orderby = 'rp.' . $orderby;
			}
			$_order_by = ' ORDER BY ' . $orderby;
			if ( ! empty( $order ) ) {
				$_order_by .= ' ' . $order;
			}
		}

		$sql = '';
		if ( 'id' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp  LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c  ON rp.client_id = c.clientid  WHERE `id`=%d ' . $_order_by,
				$value
			);
		} if ( 'client' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp  LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c  ON rp.client_id = c.clientid  WHERE `client_id` = %d ' . $_order_by,
				$value
			);
			return $wpdb->get_results( $sql, $output );
		} if ( 'site' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp  LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c ON rp.client_id = c.clientid WHERE `selected_site` = %d ' . $_order_by,
				$value
			);
			return $wpdb->get_results( $sql, $output );
		} if ( 'title' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp  LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c  ON rp.client_id = c.clientid  WHERE `title` = %s ' . $_order_by,
				$value
			);
			return $wpdb->get_results( $sql, $output );
		} elseif ( 'all' === $by ) {
			$sql = 'SELECT * FROM ' . $this->table_name( 'client_report' ) . ' rp '
			. 'LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '
			. ' ON rp.client_id = c.clientid '
			. ' WHERE 1 = 1 ' . $_order_by;
			return $wpdb->get_results( $sql, $output );
		}
		if ( ! empty( $sql ) ) {
			return $wpdb->get_row( $sql, $output );
		}

		return false;
	}

	/**
	 * Method get_avail_archive_reports()
	 *
	 * Get available achived client reports.
	 *
	 * @return (object|bool) Return Client Report object or false on failer.
	 */
	public function get_avail_archive_reports() {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$sql = 'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '
		. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '
		. ' ON rp.client_id = c.clientid '
		. ' WHERE rp.is_archived = 0 AND rp.scheduled = 0'
		. ' AND rp.date_from <= ' . ( time() - 3600 * 24 * 30 ) . '  '
		. ' AND rp.selected_site != 0 AND c.email IS NOT NULL '
		. '';

		return $wpdb->get_results( $sql );
	}

	/**
	 * Method get_schedule_reports()
	 *
	 * Get schedualed client reports.
	 *
	 * @return (object|bool) Return Client Report object or false on failer.
	 */
	public function get_schedule_reports() {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$sql = 'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '
		. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '
		. ' ON rp.client_id = c.clientid '
		. " WHERE rp.recurring_schedule != '' AND rp.scheduled = 1";

		return $wpdb->get_results( $sql );
	}

	/**
	 * Method delete_report_by()
	 *
	 * Delete Client Report by id.
	 *
	 * @param string $by Query type. Default: 'id'.
	 * @param null   $value Client Report ID.
	 *
	 * @return (bool) Return true on success and false on failer.
	 */
	public function delete_report_by( $by = 'id', $value = null ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( 'id' === $by ) {
			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report' ) . ' WHERE id=%d ', $value ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Method get_clients()
	 *
	 * Get all clients.
	 *
	 * @return (object|bool) Return Clients object or false on failer.
	 */
	public function get_clients() {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'client_report_client' ) . ' WHERE 1 = 1 ORDER BY client ASC' );
	}

	/**
	 * Method get_client_by()
	 *
	 * Get client by clientid.
	 *
	 * @param string $by Query type. Defualt: 'clientid'.
	 * @param null   $value Query value placeholder.
	 *
	 * @return (array|object|null|void) Database query result for client or null on failure.
	 */
	public function get_client_by( $by = 'clientid', $value = null ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( empty( $value ) ) {
			return false;
		}

		$sql = '';
		if ( 'clientid' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM ' . $this->table_name( 'client_report_client' ) . ' WHERE `clientid` =%d ',
				$value
			);
		} elseif ( 'client' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM ' . $this->table_name( 'client_report_client' ) . ' WHERE `client` = %s ',
				$value
			);
		} elseif ( 'email' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM ' . $this->table_name( 'client_report_client' ) . ' WHERE `email` = %s ',
				$value
			);
		}

		if ( ! empty( $sql ) ) {
			return $wpdb->get_row( $sql );
		}

		return false;
	}

	/**
	 * Method update_client()
	 *
	 * Update Client.
	 *
	 * @param object $client Client object.
	 *
	 * @return (int|bool) int Client ID or false on failer.
	 */
	public function update_client( $client ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$id = isset( $client['clientid'] ) ? $client['clientid'] : 0;

		if ( ! empty( $id ) ) {
			if ( $wpdb->update( $this->table_name( 'client_report_client' ), $client, array( 'clientid' => intval( $id ) ) ) ) {
				return $this->get_client_by( 'clientid', $id );
			}
		} else {
			if ( $wpdb->insert( $this->table_name( 'client_report_client' ), $client ) ) {
				return $this->get_client_by( 'clientid', $wpdb->insert_id );
			}
		}
		return false;
	}

	/**
	 * Method get_formats()
	 *
	 * Get Client Report format.
	 *
	 * @param null $type Format type.
	 *
	 * @return (object|bool) Return report format object or false on failer.
	 */
	public function get_formats( $type = null ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		return $wpdb->prepare(
			'SELECT * FROM ' . $this->table_name( 'client_report_format' ) . ' WHERE `type` =%s ORDER BY title',
			$type
		);
	}

	/**
	 * Method get_format_by()
	 *
	 * Get Client Report format by.
	 *
	 * @param string $by Query type. id|title.
	 * @param mixed  $value Id or title to grab.
	 * @param null   $type format type. Default: null as query placeholder.
	 *
	 * @return (array|object|null|void) Database query result or null on failure.
	 */
	public function get_format_by( $by, $value, $type = null ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( empty( $value ) ) {
			return false;
		}
		$sql = '';
		if ( 'id' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM ' . $this->table_name( 'client_report_format' ) . ' WHERE `id` =%d ',
				$value
			);
		} elseif ( 'title' === $by ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM ' . $this->table_name( 'client_report_format' ) . ' WHERE `title` =%s AND type = %s',
				$value,
				$type
			);
		}
		if ( ! empty( $sql ) ) {
			return $wpdb->get_row( $sql );
		}
		return false;
	}

	/**
	 * Method update_format()
	 *
	 * Update Client Report format.
	 *
	 * @param object $format Client Report format object.
	 *
	 * @return (int|false) The number of rows inserted, or false on error.
	 */
	public function update_format( $format ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$id = isset( $format['id'] ) ? $format['id'] : 0;

		if ( ! empty( $id ) ) {
			if ( $wpdb->update( $this->table_name( 'client_report_format' ), $format, array( 'id' => intval( $id ) ) ) ) {
				return $this->get_format_by( 'id', $id );
			}
		} else {
			if ( $wpdb->insert( $this->table_name( 'client_report_format' ), $format ) ) {
				return $this->get_format_by( 'id', $wpdb->insert_id );
			}
		}
		return false;
	}

	/**
	 * Method delete_format_by()
	 *
	 * Delete Client Report format by id.
	 *
	 * @param string $by Query type. Default: 'id'.
	 * @param null   $value Query value placeholder.
	 *
	 * @return (bool) Return true on success and false on failer.
	 */
	public function delete_format_by( $by = 'id', $value = null ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		if ( 'id' === $by ) {
			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_format' ) . ' WHERE id=%d ', $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Method escape()
	 *
	 * Escape the given data.
	 *
	 * @param mixed $data Given data.
	 *
	 * @deprecated $wpdb->escape is deprecated - Replace with wpdb::prepare() https://developer.wordpress.org/reference/classes/wpdb/escape/.
	 *
	 * @return (string|bool) Escaped data or false on failer.
	 */
	protected function escape( $data ) {

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;
		if ( function_exists( 'esc_sql' ) ) {
			return esc_sql( $data );
		} else {
			return $wpdb->escape( $data );
		}
	}

	/**
	 * Method query()
	 *
	 * SQL Query.
	 *
	 * @param mixed $sql Given SQL Query.
	 *
	 * @return (bool|object) Returns false on failure. For successful SELECT, SHOW, DESCRIBE or EXPLAIN queries
	 * mysqli_query() will return a mysqli_result object. For other successful queries mysqli_query() will return TRUE.
	 */
	public function query( $sql ) {
		if ( null == $sql ) {
			return false;
		}

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		$result = self::m_query( $sql, $wpdb->dbh );

		if ( ! $result || ( 0 === self::num_rows( $result ) ) ) {
			return false;
		}
		return $result;
	}

	/**
	 * Method m_query()
	 *
	 * MySQLi or MySQL Query.
	 *
	 * @param mixed $query SQL query.
	 * @param mixed $link mysqli_connect link.
	 *
	 * @return (bool) Returns false on failure. For successful SELECT, SHOW, DESCRIBE or EXPLAIN queries
	 * mysqli_query() will return a mysqli_result object. For other successful queries mysqli_query() will return true.
	 */
	public static function m_query( $query, $link ) {
		if ( self::use_mysqli() ) {
			return \mysqli_query( $link, $query );
		} else {
			return \mysql_query( $query, $link );
		}
	}
	/**
	 * Method fetch_object(
	 *
	 * MySQLi Query.
	 *
	 * @param object $result SQL Query.
	 *
	 * @return (object|null) Returns an object with string properties that corresponds to the fetched row or null if there are no more rows in resultset.
	 */
	public static function fetch_object( $result ) {
		if ( self::use_mysqli() ) {
			return \mysqli_fetch_object( $result );
		} else {
			return \mysql_fetch_object( $result );
		}
	}

	/**
	 * Method free_result()
	 *
	 * @param object $result SQL Query.
	 *
	 * @return (bool) Returns true on success or false on failure.
	 */
	public static function free_result( $result ) {
		if ( is_bool( $result ) ) {
			return $result;
		}
		if ( self::use_mysqli() ) {
			return \mysqli_free_result( $result );
		} else {
			return \mysql_free_result( $result );
		}
	}

	/**
	 * Method data_seek()
	 *
	 * Data Seek.
	 *
	 * @param mixed $result Required. Specifies a result set identifier returned by mysqli_query(), mysqli_store_result() or mysqli_use_result()
	 * @param mixed $offset Required. Specifies the field offset. Must be between 0 and the total number of rows - 1.
	 *
	 * @return (bool) Returns true on success or false on failure.
	 */
	public static function data_seek( $result, $offset ) {
		if ( self::use_mysqli() ) {
			return \mysqli_data_seek( $result, $offset );
		} else {
			return \mysql_data_seek( $result, $offset );
		}
	}

	/**
	 * Method fetch_array()
	 *
	 * Fetch array.
	 *
	 * @param mixed $result Required. Specifies which data pointer to use. The data pointer is the result from the mysql_query() function
	 * @param null  $result_type Optional. Specifies what kind of array to return. Placeholder: null.
	 *
	 * @return array The array that was fetched.
	 */
	public static function fetch_array( $result, $result_type = null ) {
		if ( self::use_mysqli() ) {
			return \mysqli_fetch_array( $result, ( null == $result_type ? MYSQLI_BOTH : $result_type ) );
		} else {
			return \mysql_fetch_array( $result, ( null == $result_type ? MYSQL_BOTH : $result_type ) );
		}
	}

	/**
	 * Method num_rows()
	 *
	 * Num Rows.
	 *
	 * @param mixed $result
	 *
	 * @return (int|bool) The number of rows in a result set on success or false on failure.
	 */
	public static function num_rows( $result ) {
		if ( self::use_mysqli() ) {
			return \mysqli_num_rows( $result );
		} else {
			return \mysql_num_rows( $result );
		}
	}

	/**
	 * Method is_result()
	 *
	 * Is result.
	 *
	 * @param mixed $result SQL Result.
	 *
	 * @return bool Returns TRUE if var is a resource, FALSE otherwise.
	 */
	public static function is_result( $result ) {
		if ( self::use_mysqli() ) {
			return ( $result instanceof \mysqli_result );
		} else {
			return is_resource( $result );
		}
	}

	/**
	 * Method get_results_result()
	 *
	 * Get results result.
	 *
	 * @param mixed $sql SQL query.
	 *
	 * @return (array|object|null) Database query results.
	 */
	public function get_results_result( $sql ) {
		if ( null == $sql ) {
			return null;
		}

		/** @global object $wpdb WordPress Database Access Abstraction Object */
		global $wpdb;

		return $wpdb->get_results( $sql, OBJECT_K );
	}

}
