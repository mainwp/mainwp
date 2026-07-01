<?php
/**
 * MainWP Database Health Check Tool.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Database_Schema_Checker
 *
 * @package MainWP\Dashboard
 */
class MainWP_Database_Schema_Checker { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;


    /**
     * Cache the detected issues.
     *
     * @static
     * @var array issues.
     */
    public static $cache_detected_issues;


    /**
     * Cache the extensions db tables info.
     *
     * @static
     * @var array issues.
     */
    public static $cache_extensions_loaded_info;

    /**
     * Cache the extensions db tables detected issues.
     *
     * @static
     * @var array issues.
     */
    public static $cache_extensions_detected_issues;


    /**
     * MainWP_Database_Schema_Checker constructor.
     *
     * Run each time the class is called.
     * Add action to generate tabletop.
     */
    public function __construct() {
        // Constructor method.
    }

    /**
     * Create public static instance.
     *
     * @static
     *
     * @return instance.
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Initialize data before running database checks.
     *
     * @return void.
     */
    private static function init_data() {

        if ( ! is_array( static::$cache_extensions_detected_issues ) ) {
            static::$cache_extensions_detected_issues = array();
        }

        /**
         *  Filter to load extension information.
         *
         * @since 6.1.3.
         */
        static::$cache_extensions_loaded_info = apply_filters(
            'mainwp_extensions_loaded_info',
            array()
        );

        if ( ! empty( static::$cache_extensions_loaded_info ) && is_array( static::$cache_extensions_loaded_info ) ) {
            foreach ( static::$cache_extensions_loaded_info as $ext_slug => $ext_info ) {
                if ( ! empty( $ext_info['tables'] ) && is_array( $ext_info['tables'] ) ) {
                    static::$cache_extensions_detected_issues[ $ext_slug ]['tables_db_info'] = array_fill_keys( $ext_info['tables'], false );
                }
            }
        }
    }

    /**
     * Get array of database information.
     *
     * @return array
     */
    public static function get_database_info() { // phpcs:ignore -- NOSONAR - complex function.
        global $wpdb;

        static::init_data();

        $tables        = array();
        $database_size = array();

        if ( defined( 'DB_NAME' ) ) {
            $database_table_information = $wpdb->get_results( // phpcs:ignore -- NOSONAR - custom query.
                $wpdb->prepare(
                    "SELECT
					    table_name AS 'name',
						engine AS 'engine',
					    round( ( data_length / 1024 / 1024 ), 2 ) 'data',
					    round( ( index_length / 1024 / 1024 ), 2 ) 'index'
					FROM information_schema.TABLES
					WHERE table_schema = %s
					ORDER BY name ASC;",
                    DB_NAME
                )
            );

            // WC Core tables to check existence of.
            $core_tables = MainWP_DB::instance()->get_core_tables();

            $tables = array(
                'core'  => array_fill_keys( $core_tables, false ),
                'other' => array(),
            );

            $database_size = array(
                'data'  => 0,
                'index' => 0,
            );

            $site_tables_prefix = $wpdb->get_blog_prefix( get_current_blog_id() );

            $mainwp_tables_prefix = $site_tables_prefix . 'mainwp';

            foreach ( $database_table_information as $table ) {

                // to filter mainwp tables.
                if ( 0 !== strpos( $table->name, $mainwp_tables_prefix ) ) {
                    continue;
                }

                $table_type = in_array( $table->name, $core_tables, true ) ? 'core' : 'other';

                $tables[ $table_type ][ $table->name ] = array(
                    'data'   => $table->data,
                    'index'  => $table->index,
                    'engine' => $table->engine,
                );

                $database_size['data']  += $table->data;
                $database_size['index'] += $table->index;
            }
        }

        // Return database info.
        return array(
            'mainwp_database_version' => get_site_option( 'mainwp_db_version' ),
            'database_prefix'         => $wpdb->prefix,
            'database_size'           => $database_size,
            'database_tables'         => $tables,
        );
    }


    /**
     * Get array of database privileges.
     *
     * @return array
     */
    public static function get_database_privileges() {
        global $wpdb;

        $database_name      = $wpdb->dbname;
        $required_privs     = array( 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX' );
        $grants             = $wpdb->get_col( 'SHOW GRANTS' ); // phpcs:ignore -- NOSONAR - requires custom query.
        $granted_privileges = array();

        foreach ( $grants as $grant ) {

            if (
                ! preg_match(
                    '/GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/i',
                    $grant,
                    $matches
                )
            ) {
                continue;
            }

            $privileges = strtoupper( trim( $matches[1] ) );
            $scope      = trim( $matches[2] );

            // Global privileges.
            if ( '*.*' === $scope ) {
                if ( false !== strpos( $privileges, 'ALL PRIVILEGES' ) ) {
                    $granted_privileges = $required_privs;
                    break;
                }

                $granted_privileges = array_merge(
                    $granted_privileges,
                    array_map( 'trim', explode( ',', $privileges ) )
                );

                continue;
            }

            // Database-specific privileges.
            if ( preg_match( '/^`([^`]+)`\.\*$/', $scope, $db_matches ) ) {

                $grant_db = strtr(
                    $db_matches[1],
                    array(
                        '\_' => '_',
                        '\%' => '%',
                    )
                );

                if ( $grant_db !== $database_name ) {
                    continue;
                }

                if ( false !== strpos( $privileges, 'ALL PRIVILEGES' ) ) {
                    $granted_privileges = $required_privs;
                    break;
                }

                $granted_privileges = array_merge(
                    $granted_privileges,
                    array_map( 'trim', explode( ',', $privileges ) )
                );
            }
        }

        $granted_privileges = array_unique( $granted_privileges );

        return array(
            'database_grants'             => $granted_privileges,
            'database_missing_privileges' => array_diff(
                $required_privs,
                $granted_privileges
            ),
        );
    }

    /**
     * Detect missing tables.
     *
     * @param string $table_name Table name.
     *
     * @return array Missing tables.
     */
    public static function detect_missing_tables( $tables ) { // phpcs:ignore -- NOSONAR - complex function.

        if ( ! is_array( $tables ) ) {
            $tables = array();
        }

        $missing_tables = array(
            'core'       => array(),
            'extensions' => array(),
        );
        // check mainwp tables conditional with other tables.
        if ( isset( $tables['core'] ) && is_array( $tables['core'] ) ) {

            foreach ( $tables['core'] as $tbl_name => $info ) {
                if ( empty( $info ) ) {
                    $missing_tables['core'][] = esc_html( $tbl_name );
                }
            }

            if ( ! empty( $tables['other'] ) && is_array( $tables['other'] ) ) {
                static::detect_missing_other_tables( $tables['other'] );
            }

            if ( ! empty( static::$cache_extensions_detected_issues ) && is_array( static::$cache_extensions_detected_issues ) ) {
                foreach ( static::$cache_extensions_detected_issues as $ext_slug => $tables_info ) {
                    if ( ! empty( $tables_info['tables_db_info'] ) && is_array( $tables_info['tables_db_info'] ) ) {
                        foreach ( $tables_info['tables_db_info'] as $tbl_name => $info ) {
                            if ( empty( $info ) ) {

                                if ( ! isset( $missing_tables['extensions'][ $ext_slug ] ) ) {
                                    $missing_tables['extensions'][ $ext_slug ] = array();
                                }

                                $missing_tables['extensions'][ $ext_slug ][] = esc_html( $tbl_name );
                            }
                        }
                    }
                }
            }
        }

        return $missing_tables;
    }

    /**
     * Checking other tables.
     *
     * @param string $table_name Table name.
     *
     * @return void
     */
    protected static function detect_missing_other_tables( $tables ) { // phpcs:ignore -- NOSONAR - complex function.

        if ( ! is_array( static::$cache_extensions_loaded_info ) ) {
            static::$cache_extensions_loaded_info = array();
        }

        foreach ( $tables as $table_name => $info ) {
            static::detect_missing_extensions_tables( $table_name, $info );
        }
    }

    /**
     * Checking missing extensions tables.
     *
     * @param string $table_name Table name.
     * @param array  $info Table info.
     *
     * @return void
     */
    protected static function detect_missing_extensions_tables( $table_name, $info ) { // phpcs:ignore -- NOSONAR - complex function.

        if ( false === strpos( $table_name, '_mainwp_' ) ) {
            return;
        }

        if ( is_array( static::$cache_extensions_detected_issues ) && ! empty( static::$cache_extensions_detected_issues ) ) {
            foreach ( static::$cache_extensions_detected_issues  as $ext_slug => $ext_info ) {
                if ( is_array( $ext_info ) && isset( $ext_info['tables_db_info'][ $table_name ] ) ) {
                    static::$cache_extensions_detected_issues[ $ext_slug ]['tables_db_info'][ $table_name ] = $info;
                }
            }
        }
    }


    /**
     * Method prepare_db_and_tables_issues().
     *
     * @param array $missing_tables Detected Missing tables.
     *
     * @return void
     */
    public static function prepare_db_and_tables_issues( $missing_tables ) {

        if ( ! is_array( $missing_tables ) ) {
            $missing_tables = array();
        }

        $db_issues_details = '';

        if ( ! empty( $missing_tables['core'] ) ) {
            $db_issues_details = sprintf(
                '<strong>' . esc_html__( 'Detected missing core tables', 'mainwp' ) . '</strong>: %1$s.',
                esc_html( implode( ', ', $missing_tables['core'] ) )
            );
        }

        if ( ! empty( $missing_tables['extensions'] ) ) {
            foreach ( $missing_tables['extensions'] as $ext_slug => $tbls ) {
                $title = is_array( static::$cache_extensions_loaded_info ) && isset( static::$cache_extensions_loaded_info[ $ext_slug ]['title'] ) ? static::$cache_extensions_loaded_info[ $ext_slug ]['title'] : esc_html__( 'other', 'mainwp' );
                $title = MainWP_Extensions_Handler::polish_string_name( $title );

                $db_issues_details .= sprintf(
                    ' <strong>%1$s</strong>: %2$s.',
                    esc_html( $title ),
                    esc_html( implode( ', ', $tbls ) )
                );
            }
        }

        if ( is_array( static::$cache_extensions_detected_issues ) ) {
            if ( ! empty( static::$cache_extensions_detected_issues['found_missing_columns'] ) ) {
                $found_count = array_sum(
                    array_map( 'count', static::$cache_extensions_detected_issues['found_missing_columns'] )
                );

                $db_issues_details .= sprintf(
                    ' <strong>' . esc_html__( 'Found %1$s missing columns in tables.', 'mainwp' ) . '</strong>',
                    $found_count
                );
            }

            if ( ! empty( static::$cache_extensions_detected_issues['found_invalid_columns'] ) && is_array( static::$cache_extensions_detected_issues['found_invalid_columns'] ) ) {

                $invalid_count = 0;

                foreach ( static::$cache_extensions_detected_issues['found_invalid_columns'] as $invalid_cols ) {
                    $invalid_count += count( $invalid_cols );
                }

                $db_issues_details .= sprintf(
                    ' <strong>' . esc_html__( 'Found %1$s invalid columns definitions in tables.', 'mainwp' ) . '</strong>',
                    $invalid_count
                );
            }
        }

        if ( ! empty( $db_issues_details ) ) {
            static::$cache_detected_issues[] = array(
                'severity'    => 'error',
                'title'       => esc_html__( 'MainWP datatable Issues', 'mainwp' ),
                'detail_html' => $db_issues_details,
                'anchor'      => 'mainwp-system-report-mainwp-database-table',
                'kb_url'      => 'https://docs.mainwp.com/troubleshooting/resolve-system-requirement-issues/',
            );
        }
    }

    /**
     * Get detected database issues.
     *
     * Used by get_primary_issues().
     *
     * @return array Detected database issues.
     */
    public static function get_detected_db_issues() {
        return static::$cache_detected_issues;
    }


    /**
     * Method detect_table_columns_issues().
     *
     * @param string $table_name Table name.
     * @param string $type Type Table: core or other.
     *
     * @return array Missing and Invalid columns.
     */
    public static function detect_table_columns_issues( $table_name, $type ) { //phpcs:ignore -- NOSONAR - complex function.

        global $wpdb;

        if ( ! is_string( $table_name ) || empty( $table_name ) ) {
            return array();
        }

        $table_name = esc_sql( $table_name );

        $actual_columns = $wpdb->get_col( // phpcs:ignore -- NOSONAR - custom query.
            "SHOW COLUMNS FROM `{$table_name}`", // phpcs:ignore -- NOSONAR - escaped table name.
            0
        );

        $missing          = array();
        $invalid_cols     = array();
        $expected_cols    = array();
        $expected_schemas = array();

        if ( 'core' === $type ) {
            $core_tbls_schema = static::get_core_db_schema_info();
            if ( is_array( $core_tbls_schema ) && isset( $core_tbls_schema[ $table_name ] ) && is_array( $core_tbls_schema[ $table_name ] ) && isset( $core_tbls_schema[ $table_name ]['columns'] ) && is_array( $core_tbls_schema[ $table_name ]['columns'] ) ) {
                $expected_cols    = array_keys( $core_tbls_schema[ $table_name ]['columns'] );
                $expected_schemas = $core_tbls_schema[ $table_name ]['columns'];
            }
        } elseif ( ! empty( $actual_columns ) && ! empty( static::$cache_extensions_loaded_info ) && is_array( static::$cache_extensions_loaded_info ) ) {
            foreach ( static::$cache_extensions_loaded_info  as $ext_info ) {
                if ( ! empty( $ext_info['schema'] ) && is_array( $ext_info['schema'] ) && isset( $ext_info['schema'][ $table_name ] ) && is_array( $ext_info['schema'][ $table_name ] ) && isset( $ext_info['schema'][ $table_name ]['columns'] ) && is_array( $ext_info['schema'][ $table_name ]['columns'] ) ) {
                    $expected_cols    = array_keys( $ext_info['schema'][ $table_name ]['columns'] );
                    $expected_schemas = $ext_info['schema'][ $table_name ]['columns'];
                    break;
                }
            }
        }

        if ( is_array( $expected_cols ) && ! empty( $expected_cols ) ) {
            $missing = array_diff(
                $expected_cols,
                $actual_columns
            );
        }

        if ( ! empty( $missing ) ) {
            if ( ! isset( static::$cache_extensions_detected_issues['found_missing_columns'] ) ) {
                static::$cache_extensions_detected_issues['found_missing_columns'] = array();
            }
            if ( ! isset( static::$cache_extensions_detected_issues['found_missing_columns'][ $table_name ] ) ) {
                static::$cache_extensions_detected_issues['found_missing_columns'][ $table_name ] = array();
            }
            static::$cache_extensions_detected_issues['found_missing_columns'][ $table_name ] = $missing;
        }

        if ( is_array( $expected_schemas ) && ! empty( $expected_schemas ) ) {

            $actual_schemas = $wpdb->get_results(
                "SHOW FULL COLUMNS FROM `{$table_name}`", // phpcs:ignore -- NOSONAR - escaped table name.
                ARRAY_A
            );

            $schemas_by_column = array_column(
                $actual_schemas,
                null,
                'Field'
            );

            $invalid_cols = static::detect_invalid_column_definitions( $table_name, $expected_schemas, $schemas_by_column );

            if ( ! empty( $invalid_cols ) ) {
                if ( ! isset( static::$cache_extensions_detected_issues['found_invalid_columns'] ) ) {
                    static::$cache_extensions_detected_issues['found_invalid_columns'] = array();
                }
                if ( ! isset( static::$cache_extensions_detected_issues['found_invalid_columns'][ $table_name ] ) ) {
                    static::$cache_extensions_detected_issues['found_invalid_columns'][ $table_name ] = array();
                }
                static::$cache_extensions_detected_issues['found_invalid_columns'][ $table_name ] = $invalid_cols;
            }
        }

        return array(
            'missing_columns' => $missing,
            'invalid_columns' => $invalid_cols,

        );
    }


    /**
     * Check for incorrect column definitions in an extension table.
     *
     * @param string $table_name       Table name information.
     * @param array  $schema       Table schema information.
     * @param array  $actual_cols_schemas Actual columns information.
     *
     * @return array $issues Column definitions  issues.
     */
    protected static function detect_invalid_column_definitions( $table_name, $columns, $actual_cols_schemas ) { // phpcs:ignore -- NOSONAR - complex function.

        $issues = array();
        foreach ( $columns as $column_name => $expected_definition ) {

            if ( ! isset( $actual_cols_schemas[ $column_name ] ) ) {
                continue; // already handled by missing column check.
            }

            $actual = static::get_column_definition( $actual_cols_schemas[ $column_name ] );

            $expected = static::normalize_definition( $expected_definition );

            $actual = static::normalize_definition( $actual );

            if ( $expected !== $actual ) {

                $issues[] = array(
                    'table_name' => $table_name,
                    'column'     => $column_name,
                    'expected'   => $expected,
                    'actual'     => $actual,
                );
            }
        }
        return $issues;
    }


    /**
     * Normalize a column definition for comparison.
     *
     * @param string $definition Column definition.
     *
     * @return string
     */
    protected static function normalize_definition( $definition ) {

        $definition = strtoupper( trim( $definition ) );

        // Normalize whitespace.
        $definition = preg_replace( '/\s+/', ' ', $definition );

        // Normalize spaces inside type declarations.
        $definition = preg_replace(
            '/\(\s*(\d+)\s*,\s*(\d+)\s*\)/',
            '($1,$2)',
            $definition
        );

        // Normalize repeated quotes.
        $definition = str_replace( "''", '""', $definition );
        $definition = preg_replace(
            '/DEFAULT\s+["\']{2,}/',
            'DEFAULT ""',
            $definition
        );

        // Remove integer display widths.
        $definition = preg_replace(
            '/\b(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)\(\d+\)/',
            '$1',
            $definition
        );

        // Normalize NULL handling.
        $definition = preg_replace(
            '/(?<!NOT)\s+NULL\s+DEFAULT\s+NULL\b/',
            '',
            $definition
        );

        $definition = preg_replace(
            '/(?<!NOT)\s+NULL\b/',
            '',
            $definition
        );

        // Normalize quoted numeric defaults.
        $definition = preg_replace(
            "/DEFAULT\s+'(-?\d+(?:\.\d+)?)'/",
            'DEFAULT $1',
            $definition
        );

        // Normalize decimal/float zero defaults.
        $definition = preg_replace(
            '/DEFAULT\s+0+\.0+\b/',
            'DEFAULT 0',
            $definition
        );

        if ( preg_match( '/^(TEXT|MEDIUMTEXT|LONGTEXT)\b/', $definition ) ) {
            $definition = preg_replace(
                '/\s+DEFAULT\s+(""|\'\')$/',
                '',
                $definition
            );
        }

        return trim( $definition );
    }


    /**
     * Normalize a column default value.
     *
     * @param mixed $default_definition Default value.
     *
     * @return string|null
     */
    private static function normalize_default( $default_definition ) {

        if ( null === $default_definition ) {
            return null;
        }

        if ( self::is_sql_default_expression( $default_definition ) ) {
            return strtoupper( trim( $default_definition ) );
        }

        if ( is_numeric( $default_definition ) ) {
            return $default_definition;
        }

        return "'" . $default_definition . "'";
    }

    /**
     * Check whether the default value is an SQL expression.
     *
     * @param string $default_definition Default value.
     *
     * @return bool
     */
    private static function is_sql_default_expression( $default_definition ) {

        return in_array(
            strtoupper( trim( $default_definition ) ),
            array(
                'CURRENT_TIMESTAMP',
                'CURRENT_TIMESTAMP()',
                'NOW()',
            ),
            true
        );
    }


    /**
     * Method get_column_definition().
     *
     * @param array $column DB column values.
     *
     * @return string definition.
     */
    protected static function get_column_definition( $column ) {
        $definition = strtoupper( $column['Type'] );

        if ( 'NO' === $column['Null'] ) {
            $definition .= ' NOT NULL';
        }

        $default = self::normalize_default( $column['Default'] );

        if ( null !== $default ) {
            $definition .= ' DEFAULT ' . $default;
        }


        if ( false !== stripos( $column['Extra'], 'auto_increment' ) ) {
            $definition .= ' AUTO_INCREMENT';
        }

        return $definition;
    }


    /**
     * Method get_core_db_schema_info().
     *
     * @return array Core schema info.
     */
    public static function get_core_db_schema_info() {

        static $core_schema = null;

        if ( null !== $core_schema ) {
            return $core_schema;
        }

        $core_schema = include MAINWP_PLUGIN_DIR . 'includes/core-db-schema-info.php'; //phpcs:ignore -- NOSONAR - custom requires.

        if ( ! is_array( $core_schema ) ) {
            $core_schema = array();
        }

        $core_schema = apply_filters( 'mainwp_database_core_tables_schema', $core_schema );

        if ( is_array( $core_schema ) ) {
            global $wpdb;

            $prefix = $wpdb->prefix . 'mainwp_';

            $result = array();

            foreach ( $core_schema as $key => $value ) {
                $result[ $prefix . $key ] = $value;
            }
            $core_schema = $result;
        }

        if ( ! is_array( $core_schema ) ) {
            $core_schema = array();
        }

        return $core_schema;
    }

    /**
     * Method get_loaded_extension_title().
     *
     * @param string $ext_slug Extension slug.
     *
     * @return string Extension info title.
     */
    public static function get_loaded_extension_title( $ext_slug ) {
        $title = is_array( static::$cache_extensions_loaded_info ) && isset( static::$cache_extensions_loaded_info[ $ext_slug ]['title'] ) ? static::$cache_extensions_loaded_info[ $ext_slug ]['title'] : esc_html__( 'other', 'mainwp' );
        return MainWP_Extensions_Handler::polish_string_name( $title );
    }
}
