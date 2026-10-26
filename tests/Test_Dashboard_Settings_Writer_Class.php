<?php
/**
 * Shared settings writers must enforce rights independently of their callers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Exit capture and plugin-operation fixtures belong to this test harness.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Requests exercise the production nonce checks.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Captured responses never reach a browser.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Temporary files and source copies isolate filesystem effects.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Temporary tables use only table names supplied by the Dashboard DB helper.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Temporary tables isolate SQL effects without changing the persistent schema.
// phpcs:disable WordPress.DB.SlowDBQuery -- Single-row metadata fixtures cannot cause a slow production query.

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Notification_Settings;
use MainWP\Dashboard\MainWP_Notification_Template;
use MainWP\Dashboard\MainWP_System_Handler;
use MainWP\Dashboard\Module\Log\Log_DB_Archive;
use MainWP\Dashboard\Module\Log\Log_DB_Helper;
use MainWP\Dashboard\Module\Log\Log_Install;
use MainWP\Dashboard\Module\Log\Log_Manager;
use MainWP\Dashboard\Writer_Class_Extension_Handler;
use MainWP\Dashboard\Writer_Class_Settings;
use MainWP\Dashboard\Writer_Class_Setup_Wizard;
use MainWP\Dashboard\Module\Log\Writer_Class_Insights_Page;
use MainWP\Dashboard\Module\Log\Writer_Class_Log_Admin;

/** An Error escapes legacy handlers that catch Exception. */
class Dashboard_Writer_Response_Stop extends \Error {}

/** A nonexistent slug must never reach WordPress's installed plugins. */
class Dashboard_Writer_Plugin_Fixture {

    /**
     * Temporary plugin file.
     *
     * @var string
     */
    public static $file = '';

    /**
     * Observe activation without including a real plugin.
     *
     * @param string $slug Plugin slug.
     */
    public static function activate( $slug ) {
        update_option( 'active_plugins', array_merge( get_option( 'active_plugins' ), array( $slug ) ) );
        update_option( 'mainwp_writer_plugin_calls', array( 'active', $slug ) );
    }

    /**
     * Observe deactivation without running real plugin hooks.
     *
     * @param string $slug Plugin slug.
     */
    public static function deactivate( $slug ) {
        update_option( 'active_plugins', array_values( array_diff( get_option( 'active_plugins' ), array( $slug ) ) ) );
        update_option( 'mainwp_writer_plugin_calls', array( 'disable', $slug ) );
    }

    /**
     * Delete only the temporary fixture.
     *
     * @param array $slugs Plugin slugs.
     * @return bool Whether the fixture was deleted.
     */
    public static function delete( $slugs ) {
        update_option( 'mainwp_writer_plugin_calls', array( 'remove', $slugs[0] ) );
        return unlink( self::$file );
    }
}

/** Refusal preserves stored state; grants retain the existing writes. */
class Test_Dashboard_Settings_Writer_Class extends \WP_UnitTestCase {

    /**
     * Test-owned filters.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Temporary fixture directory.
     *
     * @var string
     */
    private $root = '';

    /**
     * Original template paths.
     *
     * @var array
     */
    private $template_paths = array();

    /**
     * Temporary tables isolate the archive writers.
     *
     * @var array
     */
    private $tables = array();

    /**
     * Original archive DB prefixes.
     *
     * @var array
     */
    private $db_prefixes = array();

    /**
     * Original cached logging settings.
     *
     * @var array
     */
    private $log_options = array();

    /**
     * Notice fixtures must not leave database-update state behind.
     *
     * @var array
     */
    private $notice_options = array();

    /** Raw exits must not prevent assertions on the unchanged writers. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $files = array(
            'class/class-mainwp-post-extension-handler.php' => array( 'MainWP_Post_Extension_Handler', 'Writer_Class_Extension_Handler' ),
            'pages/page-mainwp-settings.php'           => array( 'MainWP_Settings', 'Writer_Class_Settings' ),
            'pages/page-mainwp-setup-wizard.php'       => array( 'MainWP_Setup_Wizard', 'Writer_Class_Setup_Wizard' ),
            'modules/logs/pages/page-log-insights-page.php' => array( 'Log_Insights_Page', 'Writer_Class_Insights_Page' ),
            'modules/logs/classes/class-log-admin.php' => array( 'Log_Admin', 'Writer_Class_Log_Admin' ),
        );
        foreach ( $files as $file => $names ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( ! is_array( $token ) ) {
                    $code .= $token;
                    continue;
                }
                $text = $token[1];
                if ( T_EXIT === $token[0] ) {
                    $text = '\\wp_die';
                } elseif ( T_STRING === $token[0] && $names[0] === $text ) {
                    $text = $names[1];
                } elseif ( T_STRING === $token[0] && 'MainWP_Post_Extension_Handler' === $names[0] ) {
                    $operations = array(
                        'activate_plugin'    => 'activate',
                        'deactivate_plugins' => 'deactivate',
                        'delete_plugins'     => 'delete',
                    );
                    if ( isset( $operations[ $text ] ) ) {
                        $text = '\\MainWP\\Dashboard\\Tests\\Dashboard_Writer_Plugin_Fixture::' . $operations[ $text ];
                    }
                }
                $code .= $text;
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture exits and isolate plugin operations while retaining the source authorization and writes.
        }
    }

    /** WordPress admin capabilities stay granted unless the row denies one. */
    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        set_current_screen( 'dashboard' );
        WP_Filesystem();
        wp_create_nonce( 'mainwp-writer-fixture' );
        $this->log_options = Log_Manager::instance()->settings->options;
        $this->root        = sys_get_temp_dir() . '/mainwp-writer-class-' . wp_generate_uuid4();
        mkdir( $this->root );
    }

    /** Restore singleton paths, cached settings and every test-owned filter. */
    public function tearDown(): void {
        global $wpdb;
        foreach ( $this->tables as $table ) {
            $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS $table" );
        }
        foreach ( $this->db_prefixes as $class => $original ) {
            $property = new \ReflectionProperty( $class, 'table_prefix' );
            $property->setAccessible( true );
            $property->setValue( $class::instance(), $original );
        }
        foreach ( $this->template_paths as $name => $original ) {
            $property = new \ReflectionProperty( MainWP_Notification_Template::class, $name );
            $property->setAccessible( true );
            $property->setValue( MainWP_Notification_Template::instance(), $original );
        }
        Log_Manager::instance()->settings->options = $this->log_options;
        foreach ( $this->notice_options as $name => $original ) {
            if ( null === $original ) {
                delete_option( $name );
            } else {
                update_option( $name, $original );
            }
        }
        if ( $this->root && is_dir( $this->root ) ) {
            foreach ( glob( $this->root . '/*' ) as $file ) {
                unlink( $file );
            }
            rmdir( $this->root );
        }
        Dashboard_Writer_Plugin_Fixture::$file = '';
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Every writer gets the same request with and without its MainWP right.
     *
     * @return array Writer, right, and independently missing WP capability.
     */
	public static function writers(): array {
		$writers = array(
			'C1 active'        => 'plugin_active',
			'C1 disable'       => 'plugin_disable',
			'C1 remove'        => 'plugin_remove',
			'H1 updates'       => 'updates',
			'H2 delete'        => 'template_delete',
			'H2 copy'          => 'template_copy',
			'H2 save route'    => 'template_save',
			'H2 save direct'   => 'template_direct',
			'H5 summary tours' => 'wizard_ready',
			'H5 system'        => 'wizard_system',
			'M6 archive'       => 'archive',
			'M6 cancel'        => 'cancel',
			'M8 health on'     => 'monitoring_checked',
			'M8 health off'    => 'monitoring_cleared',
			'M9 manage sites'  => 'manage_sites',
			'D1 email'         => 'email',
			'D2 tools'         => 'tools',
			'D3 activation'    => 'activation',
			'D4 truncate'      => 'truncate',
			'D5 logs settings' => 'logs_settings',
		);
		$rows    = array();
		foreach ( $writers as $label => $writer ) {
			$rows[ $label . ' refused' ] = array( $writer, false, '' );
			$rows[ $label . ' allowed' ] = array( $writer, true, '' );
		}
		foreach ( array( 'active', 'disable', 'remove' ) as $action ) {
			$rows[ 'C1 ' . $action . ' WP capability refused' ] = array( 'plugin_' . $action, true, 'remove' === $action ? 'delete_plugins' : 'activate_plugins' );
		}
		return $rows;
	}

    /**
     * Missing checks must fail through changed state, not source-text assertions.
     *
     * @dataProvider writers
     * @param string $writer Writer scenario.
     * @param bool   $right MainWP right.
     * @param string $missing_cap Missing WP capability.
     */
    public function test_writer_right( string $writer, bool $right, string $missing_cap ): void {
        $cap = 0 === strpos( $writer, 'plugin_' ) ? 'manage_extensions' : 'manage_dashboard_settings';
        $this->right( $cap, $right );
        if ( $missing_cap ) {
            $this->filter(
                'user_has_cap',
                static function ( $caps ) use ( $missing_cap ) {
                    $caps[ $missing_cap ] = false;
                    return $caps;
                }
            );
            $this->assertFalse( current_user_can( $missing_cap ) );
        }
        $allowed = $right && ! $missing_cap;
        if ( 0 === strpos( $writer, 'plugin_' ) ) {
            $this->plugin( substr( $writer, 7 ), $allowed );
        } elseif ( 0 === strpos( $writer, 'template_' ) ) {
            $this->template( substr( $writer, 9 ), $allowed );
        } elseif ( 0 === strpos( $writer, 'wizard_' ) ) {
            $this->wizard( substr( $writer, 7 ), $allowed );
        } else {
            $this->$writer( $allowed );
        }
    }

    /**
     * Notice controls invoke AJAX actions that require the settings right.
     *
     * @return array Status and settings grant.
     */
    public static function update_notice_rights(): array {
        return array(
            'running refused'        => array( 'running', false ),
            'running allowed'        => array( 'running', true ),
            'require_update refused' => array( 'require_update', false ),
            'require_update allowed' => array( 'require_update', true ),
        );
    }

    /**
     * Page loads must not start refused AJAX requests or delete shared state.
     *
     * @dataProvider update_notice_rights
     * @param string $status Database-update status.
     * @param bool   $allowed Settings grant.
     */
    public function test_update_db_notice_right( string $status, bool $allowed ): void {
        $this->right( 'manage_dashboard_settings', $allowed );
        $options = array(
            'mainwp_module_log_db_version' => '1.0.1.26',
            'mainwp_module_logs_updates_dismissed_db_process_status' => $status,
            'mainwp_module_logs_updates_dismissed_db_cancelled' => 0,
        );
        foreach ( $options as $name => $value ) {
            $this->notice_options[ $name ] = get_option( $name, null );
            update_option( $name, $value );
        }
        $this->assertSame( '1.0.1.26', Log_Install::instance()->get_current_logs_db_ver() );
        $admin = ( new \ReflectionClass( Writer_Class_Log_Admin::class ) )->newInstanceWithoutConstructor();
        $body  = $this->capture(
            static function () use ( $admin ) {
                $admin->render_update_db_notice();
            }
        );
        $this->assertStringContainsString( 'id="module-log-update-dissmised-logs-running" style="display:none;"', $body );
        if ( $allowed && 'running' === $status ) {
            $this->assertStringContainsString( 'mainwp_module_logs_start_update_dismissed_db();', $body );
        } else {
            $this->assertStringNotContainsString( 'mainwp_module_logs_start_update_dismissed_db', $body );
        }
        if ( $allowed && 'require_update' === $status ) {
            $this->assertStringContainsString( 'id="module-update-logs-db-requirement"', $body );
            $this->assertFalse( get_option( 'mainwp_module_logs_updates_dismissed_db_cancelled' ) );
        } else {
            $this->assertStringNotContainsString( 'module-update-logs-db-requirement', $body );
            $this->assertSame( '0', (string) get_option( 'mainwp_module_logs_updates_dismissed_db_cancelled' ) );
        }
    }

    /**
     * Only the nonexistent plugin slug can reach the operation fixtures.
     *
     * @param string $action Plugin operation.
     * @param bool   $allowed Effective grant.
     */
    private function plugin( string $action, bool $allowed ): void {
        $slug = 'mainwp-writer-nonexistent-fixture/plugin.php';
        $this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/' . $slug );
        Dashboard_Writer_Plugin_Fixture::$file = $this->root . '/plugin.php';
        file_put_contents( Dashboard_Writer_Plugin_Fixture::$file, 'fixture-plugin' );
        $original = get_option( 'active_plugins', array() );
        $active   = array_merge( $original, 'disable' === $action ? array( $slug ) : array() );
        update_option( 'active_plugins', $active );
        update_option( 'mainwp_writer_plugin_calls', array() );
        $before = $this->snapshot();
        $body   = $this->ajax(
            'mainwp_extension_plugin_action',
            array(
                'slug' => $slug,
                'what' => $action,
            ),
            static function () {
                Writer_Class_Extension_Handler::instance()->ajax_extension_plugin_action();
            }
        );
        if ( $allowed ) {
            $this->assertSame( array( $action, $slug ), get_option( 'mainwp_writer_plugin_calls' ) );
            $this->assertSame( 'active' === $action ? array_merge( $original, array( $slug ) ) : $original, get_option( 'active_plugins' ) );
            $this->assertSame( array( 'result' => 'SUCCESS' ), json_decode( $body, true ) );
        } else {
            $this->assertSame( $before, $this->snapshot() );
            $this->assertSame( array( 'error' => 'You are not allowed to manage Add-ons.' ), json_decode( $body, true ) );
        }
        $this->assertSame( ! ( $allowed && 'remove' === $action ), file_exists( Dashboard_Writer_Plugin_Fixture::$file ) );
    }

    /**
     * Direct writers cover both Settings and Manage Sites callers.
     *
     * @param string $action Template operation.
     * @param bool   $allowed Settings grant.
     */
    private function template( string $action, bool $allowed ): void {
        $template = MainWP_Notification_Template::instance();
        foreach ( array( 'template_path', 'template_custom_path' ) as $name ) {
            $property = new \ReflectionProperty( MainWP_Notification_Template::class, $name );
            $property->setAccessible( true );
            $this->template_paths[ $name ] = $property->getValue( $template );
            $property->setValue( $template, $this->root . '/' );
        }
        $this->filter(
            'mainwp_get_notification_template_name_by_type',
            static function () {
                return 'fixture.php';
            }
        );
        $file = $this->root . '/fixture.php';
        file_put_contents( $file, 'original-template' );
        file_put_contents( $this->root . '/source.php', 'replacement-template' );
        if ( 'copy' === $action ) {
            $this->filter(
                'mainwp_get_notification_template_name_by_type',
                static function () {
                    return 'source.php';
                }
            );
            // Distinct source and destination paths avoid a successful no-op copy.
            $property = new \ReflectionProperty( MainWP_Notification_Template::class, 'template_custom_path' );
            $property->setAccessible( true );
            $property->setValue( $template, $this->root . '/custom-' );
            $file = $this->root . '/custom-source.php';
        }
        $_GET = array( 'edit-email' => 'site_health' );
        if ( in_array( $action, array( 'copy', 'delete' ), true ) ) {
            $_GET['_wpnonce'] = wp_create_nonce( $action . '-email-template' );
        } else {
            $_POST = array(
                'wp_nonce'              => wp_create_nonce( 'save-email-template' ),
                'edit_site_health_code' => 'replacement-template',
            );
        }
        $this->assertTrue( current_user_can( 'edit_themes' ) );
        $before = $this->snapshot();
        $result = 'direct' === $action ? $template->save_template( 'replacement-template', 'fixture.php' ) : $template->handle_template_file_action();
        if ( ! $allowed ) {
            $this->assertSame( 'copy' === $action ? false : 'original-template', file_exists( $file ) ? file_get_contents( $file ) : false );
            $this->assertSame( 35, $result );
            $this->assertSame( $before, $this->snapshot() );
        } elseif ( 'delete' === $action ) {
            $this->assertFileDoesNotExist( $file );
            $this->assertSame( 1, $result );
        } else {
            $this->assertSame( 'replacement-template', file_get_contents( $file ) );
            $this->assertSame( 'direct' === $action ? true : ( 'copy' === $action ? 2 : 3 ), $result );
        }
    }

    /**
     * All auto-update fields change independently under a grant.
     *
     * @param bool $allowed Settings grant.
     */
    private function updates( bool $allowed ): void {
        $before = array();
        $after  = array();
        $_POST  = array( 'wp_nonce' => wp_create_nonce( 'UpdatesScrOptions' ) );
        foreach ( array( 'pluginAutomaticDailyUpdate', 'themeAutomaticDailyUpdate', 'transAutomaticDailyUpdate', 'automaticDailyUpdate', 'delay_autoupdate', 'show_language_updates', 'disable_update_confirmations' ) as $name ) {
            $before[ 'mainwp_' . $name ] = 0;
            $after[ 'mainwp_' . $name ]  = 1;
            $_POST[ 'mainwp_' . $name ]  = '1';
        }
        $this->options(
            $allowed,
            $before,
            $after,
            static function () {
                MainWP_System_Handler::instance()->handle_updates_screen_settings();
            }
        );
    }

    /**
     * Wizard saves must authorize before global defaults can be normalized.
     *
     * @param string $step Wizard step.
     * @param bool   $allowed Settings grant.
     */
	private function wizard( string $step, bool $allowed ): void {
		$wizard = ( new \ReflectionClass( Writer_Class_Setup_Wizard::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'step'  => 'first',
			'steps' => array(
				'first'  => array(),
				'second' => array(),
			),
		) as $name => $value ) {
			$property = new \ReflectionProperty( $wizard, $name );
			$property->setAccessible( true );
			$property->setValue( $wizard, $value );
		}
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'mwp-setup' ) );
		if ( 'system' === $step ) {
			$_POST  = array( 'mwp_setup_openssl_lib_location' => '/fixture/openssl' );
			$before = array( 'mainwp_opensslLibLocation' => '/original/openssl' );
			$after  = array( 'mainwp_opensslLibLocation' => '/fixture/openssl' );
		} else {
			$_POST = array(
				'mainwp-guided-tours-option'          => '1',
				'mainwp_setup_enableUptimeMonitoring' => '1',
			);
			// The summary saves only the tour choice; a stale monitoring field must not reach the setting.
			$settings = array(
				'active'           => 0,
				'interval'         => 120,
				'retention_limits' => 180,
				'bypass_cache'     => 0,
			);
			$before   = array(
				'mainwp_enable_guided_tours'               => 0,
				'mainwp_global_uptime_monitoring_settings' => $settings,
			);
			$after    = array(
				'mainwp_enable_guided_tours'               => 1,
				'mainwp_global_uptime_monitoring_settings' => $settings,
			);
		}
		$method = 'system' === $step ? 'mwp_setup_system_requirements_save' : 'mwp_setup_ready_save';
		$this->options(
			$allowed,
			$before,
			$after,
			static function () use ( $wizard, $method ) {
				$wizard->$method();
			}
		);
	}

    /**
     * Refused archive runs preserve the source logs and metadata too.
     *
     * @param bool $allowed Settings grant.
     */
    private function archive( bool $allowed ): void {
        $this->archive_tables( false );
        $tables = $this->table_snapshot();
        $this->options(
            $allowed,
            array(
                'mainwp_module_logs_updates_dismissed_db_cancelled'      => 0,
                'mainwp_module_logs_updates_dismissed_db_process_status' => 'original',
            ),
            array(
                'mainwp_module_logs_updates_dismissed_db_cancelled'      => 0,
                'mainwp_module_logs_updates_dismissed_db_process_status' => 'finished',
            ),
            function () {
                $this->ajax(
                    'mainwp_module_log_update_dismissed_db',
                    array(),
                    static function () {
                        Writer_Class_Insights_Page::ajax_archive_dismissed_db();
                    }
                );
            }
        );
        $after = $this->table_snapshot();
        if ( $allowed ) {
            $this->assertSame( array(), $after[0] );
            $this->assertSame( array(), $after[1] );
            $this->assertSame( '901', $after[2][0]['log_id'] );
            $this->assertSame( $tables[1], $after[3] );
        } else {
            $this->assertSame( $tables, $after );
        }
    }

    /**
     * Cancel status cannot be changed with the nonce alone.
     *
     * @param bool $allowed Settings grant.
     */
    private function cancel( bool $allowed ): void {
        $this->options(
            $allowed,
            array( 'mainwp_module_logs_updates_dismissed_db_cancelled' => 0 ),
            array( 'mainwp_module_logs_updates_dismissed_db_cancelled' => 1 ),
            function () {
                $this->ajax(
                    'mainwp_module_log_cancel_update_dismissed_db',
                    array(),
                    static function () {
                        Writer_Class_Insights_Page::ajax_cancel_update_dismissed_db();
                    }
                );
            }
        );
    }

    /**
     * Page Settings posts the Site Health box only when it is checked.
     *
     * @param bool $allowed Settings grant.
     */
    private function monitoring_checked( bool $allowed ): void {
        $this->monitoring( $allowed, true );
    }

    /**
     * An unchecked Site Health box must not disable monitoring for a refused user.
     *
     * @param bool $allowed Settings grant.
     */
    private function monitoring_cleared( bool $allowed ): void {
        $this->monitoring( $allowed, false );
    }

    /**
     * Page Settings includes the Site Health fields; only the column choice is per user.
     *
     * @param bool $allowed Settings grant.
     * @param bool $checked Whether the Site Health box is posted.
     */
    private function monitoring( bool $allowed, bool $checked ): void {
        $_POST = array(
            'wp_nonce'                                 => wp_create_nonce( 'MonitoringSitesScrOptions' ),
            'mainwp_default_monitoring_sites_per_page' => '50',
            'mainwp_site_healthThreshold'              => $checked ? '100' : '80',
            'mainwp_show_column_name'                  => '1',
        );
        if ( $checked ) {
            $_POST['mainwp_disable_sitesHealthMonitoring'] = '1';
        }
        $this->options(
            $allowed,
            array(
                'mainwp_disableSitesHealthMonitoring'      => $checked ? 1 : 0,
                'mainwp_sitehealthThreshold'               => $checked ? 80 : 100,
                'mainwp_default_monitoring_sites_per_page' => 25,
            ),
            array(
                'mainwp_disableSitesHealthMonitoring'      => $checked ? 0 : 1,
                'mainwp_sitehealthThreshold'               => $checked ? 100 : 80,
                'mainwp_default_monitoring_sites_per_page' => 50,
            ),
            static function () {
                MainWP_System_Handler::instance()->handle_monitoring_sites_screen_settings();
            }
        );
        $this->assertSame( array( 'name' => 1 ), get_user_option( 'mainwp_settings_show_monitoring_sites_columns' ) );
    }

    /** A Page Settings post without its nonce writes nothing, not even the column choice. */
    public function test_monitoring_page_settings_needs_nonce(): void {
        $this->right( 'manage_dashboard_settings', true );
        $_POST = array(
            'wp_nonce'                                 => 'invalid',
            'mainwp_default_monitoring_sites_per_page' => '50',
            'mainwp_site_healthThreshold'              => '100',
            'mainwp_show_column_name'                  => '1',
        );
        update_option( 'mainwp_disableSitesHealthMonitoring', 1 );
        $snapshot = $this->snapshot();
        MainWP_System_Handler::instance()->handle_monitoring_sites_screen_settings();
        $this->assertSame( $snapshot, $this->snapshot() );
        $this->assertFalse( get_user_option( 'mainwp_settings_show_monitoring_sites_columns' ) );
    }

    /**
     * The absent optimize field and forced favicon write are both global.
     *
     * @param bool $allowed Settings grant.
     */
    private function manage_sites( bool $allowed ): void {
        $_POST = array(
            'wp_nonce'                      => wp_create_nonce( 'ManageSitesScrOptions' ),
            'mainwp_default_sites_per_page' => '50',
            'mainwp_show_column_name'       => '1',
            'mainwp_sitesviewmode'          => 'table',
        );
        $this->options(
            $allowed,
            array(
                'mainwp_optimize'               => 1,
                'mainwp_use_favicon'            => 0,
                'mainwp_default_sites_per_page' => 25,
            ),
            array(
                'mainwp_optimize'               => 0,
                'mainwp_use_favicon'            => 1,
                'mainwp_default_sites_per_page' => 50,
            ),
            static function () {
                MainWP_System_Handler::instance()->handle_manage_sites_screen_settings();
            }
        );
        $this->assertSame( array( 'name' => 1 ), get_user_option( 'mainwp_settings_show_manage_sites_columns' ) );
        $this->assertSame( 'table', get_user_option( 'mainwp_sitesviewmode' ) );
    }

    /**
     * Recipients, subject and disable state belong to shared email settings.
     *
     * @param bool $allowed Settings grant.
     */
    private function email( bool $allowed ): void {
        $_POST  = array(
            'submit'                     => '1',
            'wp_nonce'                   => wp_create_nonce( 'SettingsEmail' ),
            'mainwp_setting_emails_type' => 'daily_digest',
            'mainwp_settingEmails'       => array(
                'daily_digest' => array(
                    'recipients' => 'fixture@example.org',
                    'subject'    => 'Replacement',
                    'disable'    => '1',
                ),
            ),
        );
        $result = null;
        $this->options(
            $allowed,
            array( 'mainwp_settings_notification_emails' => array( 'daily_digest' => array( 'recipients' => 'original@example.org' ) ) ),
            array(
                'mainwp_settings_notification_emails' => array(
                    'daily_digest' => array(
                        'recipients' => 'fixture@example.org',
                        'subject'    => 'Replacement',
                        'disable'    => 0,
                    ),
                ),
            ),
            static function () use ( &$result ) {
                $result = MainWP_Notification_Settings::emails_general_settings_handle();
            }
        );
        $this->assertSame( $allowed, $result );
    }

    /**
     * Tools toggles must not be reset by an absent checkbox without the right.
     *
     * @param bool $allowed Settings grant.
     */
    private function tools( bool $allowed ): void {
        $_GET  = array( 'page' => 'MainWPTools' );
        $_POST = array( 'wp_nonce' => wp_create_nonce( 'MainWPTools' ) );
        $this->options(
            $allowed,
            array(
                'mainwp_enable_guided_tours'    => 1,
                'mainwp_enable_guided_chatbase' => 1,
                'mainwp_enable_guided_video'    => 1,
            ),
            array(
                'mainwp_enable_guided_tours'    => 0,
                'mainwp_enable_guided_chatbase' => 0,
                'mainwp_enable_guided_video'    => 0,
            ),
            static function () {
                MainWP_System_Handler::instance()->handle_mainwp_tools_settings();
            }
        );
    }

    /**
     * Activation reset must retain all shared activation data on refusal.
     *
     * @param bool $allowed Settings grant.
     */
    private function activation( bool $allowed ): void {
        $_GET   = array(
            'clearActivationData' => '1',
            '_wpnonce'            => wp_create_nonce( 'clear_activation_data' ),
        );
        $before = array(
            'mainwp_extensions_api_username'          => 'fixture-user',
            'mainwp_extensions_api_password'          => 'synthetic-fixture',
            'mainwp_extensions_api_save_login'        => 1,
            'mainwp_extensions_plan_info'             => array( 'fixture' => 1 ),
            'mainwp_extensions_master_api_key'        => 'synthetic-fixture',
            'mainwp_extensions_all_activation_cached' => 'original',
            'mainwp_extensions'                       => array( array( 'slug' => 'fixture.php' ) ),
        );
        $after  = array(
            'mainwp_extensions_api_username'          => false,
            'mainwp_extensions_api_password'          => false,
            'mainwp_extensions_api_save_login'        => false,
            'mainwp_extensions_plan_info'             => false,
            'mainwp_extensions_master_api_key'        => false,
            'mainwp_extensions_all_activation_cached' => '',
            'mainwp_extensions'                       => array( array( 'slug' => 'fixture.php' ) ),
        );
        $this->options(
            $allowed,
            $before,
            $after,
            static function () {
                Writer_Class_Settings::admin_init();
            }
        );
    }

    /**
     * The real truncate statements operate only on temporary archive tables.
     *
     * @param bool $allowed Settings grant.
     */
    private function truncate( bool $allowed ): void {
        $this->archive_tables( true );
        $before = $this->table_snapshot();
        $_GET   = array(
            'clearArchivedSitesChangesData' => '1',
            '_wpnonce'                      => wp_create_nonce( 'clear_archived_sites_changes' ),
        );
        $admin  = ( new \ReflectionClass( Writer_Class_Log_Admin::class ) )->newInstanceWithoutConstructor();
        $this->capture(
            static function () use ( $admin ) {
                $admin->handle_post_archive_data();
            }
        );
        $after = $this->table_snapshot();
        $this->assertSame( $allowed ? array() : $before[2], $after[2] );
        $this->assertSame( $allowed ? array() : $before[3], $after[3] );
    }

    /**
     * Logging settings and selected events must both stay unchanged on refusal.
     *
     * @param bool $allowed Settings grant.
     */
    private function logs_settings( bool $allowed ): void {
        $settings          = Log_Manager::instance()->settings;
        $settings->options = array(
            'enabled'          => 1,
            'records_logs_ttl' => 100,
            'child_logs_ttl'   => 7,
            'auto_archive'     => 0,
        );
        $_POST             = array(
            'mainwp_module_log_settings_nonce'       => wp_create_nonce( 'logs_settings_nonce' ),
            'mainwp_module_log_records_ttl'          => '200',
            'mainwp_module_log_child_activities_ttl' => '14',
        );
        $this->options(
            $allowed,
            array(
                'mainwp_module_log_settings' => $settings->options,
                'mainwp_module_log_settings_logs_selection_data' => 'original',
            ),
            array(
                'mainwp_module_log_settings' => array(
                    'enabled'          => 0,
                    'records_logs_ttl' => 200,
                    'child_logs_ttl'   => 14,
                    'auto_archive'     => 0,
                ),
                'mainwp_module_log_settings_logs_selection_data' => '{"dashboard":[],"nonmainwpchanges":[],"changeslogs":[]}',
            ),
            static function () use ( $settings ) {
                $settings->admin_init();
            }
        );
    }

    /**
     * Assert all options on refusal and literal expected values on grants.
     *
     * @param bool     $allowed Effective grant.
     * @param array    $before Initial options.
     * @param array    $after Expected saved options.
     * @param callable $callback Writer invocation.
     */
    private function options( bool $allowed, array $before, array $after, callable $callback ): void {
        foreach ( $before as $name => $value ) {
            update_option( $name, $value );
        }
        $snapshot = $this->snapshot();
        $this->capture( $callback );
        if ( ! $allowed ) {
            $this->assertSame( $snapshot, $this->snapshot(), 'Refusal changed stored options.' );
        }
        foreach ( $allowed ? $after : $before as $name => $expected ) {
            $this->assertSame( $expected, get_option( $name ), $name );
        }
    }

    /**
     * Temporary tables preserve both real SQL behavior and existing test data.
     *
     * @param bool $archived Whether the fixture starts archived.
     */
    private function archive_tables( bool $archived ): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'writer_class_';
        foreach ( array( 'wp_logs', 'wp_logs_meta', 'wp_logs_archive', 'wp_logs_meta_archive' ) as $suffix ) {
            $source = MainWP_DB::instance()->get_table_name( $suffix );
            $table  = $prefix . $suffix;
            $this->assertNotFalse( $wpdb->query( "CREATE TEMPORARY TABLE $table LIKE $source" ), $wpdb->last_error );
            $this->tables[] = $table;
        }
        foreach ( array( Log_DB_Archive::class, Log_DB_Helper::class ) as $class ) {
            $property = new \ReflectionProperty( $class, 'table_prefix' );
            $property->setAccessible( true );
            $this->db_prefixes[ $class ] = $property->getValue( $class::instance() );
            $property->setValue( $class::instance(), $prefix );
        }
        $log = array(
            'log_id'     => 901,
            'user_login' => 'fixture',
            'action'     => 'updated',
            'context'    => 'plugins',
            'connector'  => 'plugins',
            'created'    => 1,
            'dismiss'    => 1,
        );
        if ( $archived ) {
            $log['archived_at'] = 1;
        }
        $this->assertNotFalse( $wpdb->insert( $this->tables[ $archived ? 2 : 0 ], $log ), $wpdb->last_error );
        $this->assertNotFalse(
            $wpdb->insert(
                $this->tables[ $archived ? 3 : 1 ],
                array(
                    'meta_id'     => 902,
                    'meta_log_id' => 901,
                    'meta_key'    => 'fixture',
                    'meta_value'  => 'preserve',
                )
            ),
            $wpdb->last_error
        );
    }

    /**
     * Read stored rows independently of the archive writer.
     *
     * @return array Table rows.
     */
    private function table_snapshot(): array {
        global $wpdb;
        $rows = array();
        foreach ( $this->tables as $table ) {
            $rows[] = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A );
        }
        return $rows;
    }

    /**
     * REST's process-wide bypass would invalidate every refusal assertion.
     *
     * @param string $cap MainWP right.
     * @param bool   $allowed Grant.
     */
    private function right( string $cap, bool $allowed ): void {
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'Run this class before Test_REST_*.' );
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $requested ) use ( $cap, $allowed ) {
                return 'dashboard' === $type && $cap === $requested ? $allowed : true;
            }
        );
        $this->assertSame( $allowed, \mainwp_current_user_can( 'dashboard', $cap ) );
    }

    /**
     * Replace only callbacks owned by this test.
     *
     * @param string   $hook Filter name.
     * @param callable $callback Filter callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }

    /**
     * Read complete option state so unexpected writes are visible.
     *
     * @return array Stored options.
     */
    private function snapshot(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );
    }

    /**
     * Invoke an AJAX writer with its valid nonce.
     *
     * @param string   $action AJAX action.
     * @param array    $post Form inputs.
     * @param callable $callback Writer invocation.
     * @return string Captured response.
     */
    private function ajax( string $action, array $post, callable $callback ): string {
        $_POST    = array_merge(
            $post,
            array(
                'security' => wp_create_nonce( $action ),
                'dts'      => wp_generate_uuid4(),
            )
        );
        $_REQUEST = $_POST;
        return $this->capture( $callback );
    }

    /**
     * Capture exits and redirects without bypassing the source writers.
     *
     * @param callable $callback Writer invocation.
     * @return string Captured response.
     */
    private function capture( callable $callback ): string {
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        $this->filter(
            'wp_redirect',
            static function () {
                throw new Dashboard_Writer_Response_Stop();
            }
        );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( Dashboard_Writer_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        return $buffer . $body;
    }

    /**
     * A captured die must escape legacy Exception handlers.
     *
     * @return callable AJAX die callback.
     */
    public function ajax_die_handler(): callable {
        return static function ( $message ) {
            throw new Dashboard_Writer_Response_Stop( (string) $message );
        };
    }
}
