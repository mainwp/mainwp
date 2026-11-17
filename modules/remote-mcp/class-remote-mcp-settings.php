<?php
/**
 * Remote MCP settings.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Option names and the stored token secret.
 *
 * Option names are repeated in the self-contained uninstall.php, which can't load this class.
 */
class Remote_MCP_Settings {

    /**
     * Module schema version, separate from mainwp_db_version so retiring the module touches nothing else.
     *
     * @var string
     */
    const OPTION_DB_VERSION = 'mainwp_remote_mcp_db_version';

    /**
     * Token secret: array with `version` and base64 `key`.
     *
     * @var string
     */
    const OPTION_SECRET = 'mainwp_remote_mcp_secret';

    /**
     * Connector on/off, off by default.
     *
     * @var string
     */
    const OPTION_ENABLED = 'mainwp_remote_mcp_enabled';

    /**
     * The canonical home the connector was enabled under, kept to notice a domain change.
     *
     * @var string
     */
    const OPTION_BOUND_HOME = 'mainwp_remote_mcp_bound_home';

    /**
     * Cached result of the competing well-known rewrite rule check: array with bool
     * `openid` and `authorization_server`. Computed on enable and when the rewrite table changes,
     * never per request.
     *
     * @var string
     */
    const OPTION_COMPETING_RULES = 'mainwp_remote_mcp_competing_rules';

    /**
     * Kill switch constant, set in wp-config.php. A subclass in the tests names a
     * test-only constant, so the real read path runs without defining this one for the process.
     *
     * @var string
     */
    const KILL_SWITCH_CONSTANT = 'MAINWP_DISABLE_REMOTE_MCP';

    /**
     * Kill switch filter. It can only add "off".
     *
     * @var string
     */
    const FORCE_DISABLED_FILTER = 'mainwp_remote_mcp_force_disabled';

    /**
     * Daily purge cron event.
     *
     * @var string
     */
    const PURGE_HOOK = 'mainwp_remote_mcp_purge';

    /**
     * Logs module constant (`mainwp.php`). A test subclass names a test-only constant.
     *
     * @var string
     */
    const LOG_MODULE_CONSTANT = 'MAINWP_MODULE_LOG_ENABLED';

    /**
     * Logs module settings option, with its `enabled` key.
     *
     * @var string
     */
    const OPTION_LOG_SETTINGS = 'mainwp_module_log_settings';

    /**
     * Panel switches. uninstall.php repeats these names.
     */
    const OPTION_REGISTRATION_ENABLED    = 'mainwp_remote_mcp_registration_enabled';
    const OPTION_STATIC_CLIENTS          = 'mainwp_remote_mcp_static_clients';
    const OPTION_TOOL_SWITCHES           = 'mainwp_remote_mcp_tool_switches';
    const OPTION_UNAUDITED_TOOLS_ENABLED = 'mainwp_remote_mcp_unaudited_tools_enabled';

    /**
     * Defaults when nothing is stored: all on. Static clients are a fallback
     * once registration exists, and any program that can use one could also register, so
     * switching them off by default would stop no realistic attack.
     */
    const DEFAULT_REGISTRATION_ENABLED    = true;
    const DEFAULT_STATIC_CLIENT_ENABLED   = true;
    const DEFAULT_TOOL_ENABLED            = true;
    const DEFAULT_UNAUDITED_TOOLS_ENABLED = true;

    /**
     * Whether the activity log can record remote MCP events: the logs module is loaded and
     * its `enabled` setting is on. External log connectors only load in that state
     * (`class-log-connectors.php`), so anything less means calls would go unaudited. A missing or
     * malformed setting reads as off. Read per call, never cached.
     *
     * @return bool
     */
    public static function activity_log_available(): bool {
        if ( ! defined( static::LOG_MODULE_CONSTANT ) || ! constant( static::LOG_MODULE_CONSTANT ) ) {
            return false;
        }
        $settings = get_option( self::OPTION_LOG_SETTINGS );
        return is_array( $settings ) && ! empty( $settings['enabled'] );
    }

    /**
     * 24-hour diagnostic counters the self-test shows. Per name: `count` and the
     * `since` time its window opened.
     *
     * @var string
     */
    const OPTION_COUNTERS = 'mainwp_remote_mcp_counters';

    /**
     * Counter names; anything else is ignored.
     *
     * @var array
     */
    const COUNTERS = array( 'auth_header_missing', 'auth_header_received', 'auth_token_rejected', 'unknown_client' );

    /**
     * Count one event. Each name has its own 24-hour window: a bump after the window closed, or
     * over a stored value of the wrong shape, starts that counter over at 1. Two requests bumping
     * at once can lose a count; these are diagnostics, not limits.
     *
     * @param string $name One of COUNTERS.
     */
    public static function bump_counter( string $name ): void {
        if ( ! in_array( $name, self::COUNTERS, true ) ) {
            return;
        }
        $now    = time();
        $stored = get_option( self::OPTION_COUNTERS );
        $stored = is_array( $stored ) ? array_intersect_key( $stored, array_flip( self::COUNTERS ) ) : array();
        $count  = self::counter_value( $stored[ $name ] ?? null, $now );
        $since  = $count > 0 ? (int) $stored[ $name ]['since'] : $now;

        $stored[ $name ] = array(
            'count' => $count + 1,
            'since' => $since,
        );
        update_option( self::OPTION_COUNTERS, $stored, false );
    }

    /**
     * Note that a request reached WordPress with its Authorization header. Every OAuth client's
     * first call has no token, so a missing header only points at a stripping server or proxy
     * when none arrive. Called on every authenticated request, so it writes once per window.
     */
    public static function note_header_received(): void {
        if ( 0 === self::counters()['auth_header_received'] ) {
            self::bump_counter( 'auth_header_received' );
        }
    }

    /**
     * Current counts within each name's 24-hour window.
     *
     * @return array Name => count, every name in COUNTERS present.
     */
    public static function counters(): array {
        $now    = time();
        $stored = get_option( self::OPTION_COUNTERS );
        $counts = array();
        foreach ( self::COUNTERS as $name ) {
            $counts[ $name ] = self::counter_value( is_array( $stored ) ? ( $stored[ $name ] ?? null ) : null, $now );
        }
        return $counts;
    }

    /**
     * Count of one stored counter, 0 when its window has closed or it has the wrong shape.
     *
     * @param mixed $entry Stored entry.
     * @param int   $now   Current time.
     * @return int
     */
    private static function counter_value( $entry, int $now ): int {
        if ( ! is_array( $entry ) || ! is_int( $entry['count'] ?? null ) || ! is_int( $entry['since'] ?? null ) || $entry['count'] < 0 ) {
            return 0;
        }
        return $entry['since'] > $now - DAY_IN_SECONDS ? $entry['count'] : 0;
    }

    /**
     * Sites per job entry: the `mainwp_remote_mcp_chunk_size` filter, default
     * 10, kept between 1 and the core `mainwp_abilities_batch_threshold` minus one, so a chunked
     * call never enters the core batch queue. The one accessor the gate and job runner read.
     *
     * @return int
     */
    public static function chunk_size(): int {
        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', 200 );
        // Core queues when `count > $threshold`, a loose comparison, so a numeric string or float
        // threshold from a filter or stored option still queues and must lower the cap too.
        $cap  = is_int( $threshold ) || is_float( $threshold ) || ( is_string( $threshold ) && is_numeric( $threshold ) ) ? (int) ceil( (float) $threshold ) - 1 : 199;
        $size = apply_filters( 'mainwp_remote_mcp_chunk_size', 10 );
        if ( ! is_int( $size ) ) {
            $size = 10;
        }
        return max( 1, min( $size, $cap ) );
    }

    /**
     * Whether apps on the user's computer may register.
     *
     * @return bool
     */
    public static function registration_enabled(): bool {
        return self::read_flag( self::OPTION_REGISTRATION_ENABLED, self::DEFAULT_REGISTRATION_ENABLED );
    }

    /**
     * Set the registration switch.
     *
     * @param bool $enabled On or off.
     * @return bool Whether the stored value now matches.
     */
    public static function set_registration_enabled( bool $enabled ): bool {
        return self::write_flag( self::OPTION_REGISTRATION_ENABLED, $enabled );
    }

    /**
     * Whether one static client may connect, by its exact client id.
     *
     * @param string $client_id Static client id.
     * @return bool
     */
    public static function static_client_enabled( string $client_id ): bool {
        return self::read_map( self::OPTION_STATIC_CLIENTS, null, $client_id, self::DEFAULT_STATIC_CLIENT_ENABLED );
    }

    /**
     * Set one static client's switch.
     *
     * @param string $client_id Static client id.
     * @param bool   $enabled   On or off.
     * @return bool
     */
    public static function set_static_client_enabled( string $client_id, bool $enabled ): bool {
        return self::write_map( self::OPTION_STATIC_CLIENTS, null, $client_id, $enabled );
    }

    /**
     * Whether one tool is switched on. The tool's category switch is a separate check.
     *
     * @param string $tool_name Tool name.
     * @return bool
     */
    public static function tool_enabled( string $tool_name ): bool {
        return self::read_map( self::OPTION_TOOL_SWITCHES, 'tools', $tool_name, self::DEFAULT_TOOL_ENABLED );
    }

    /**
     * Set one tool's switch.
     *
     * @param string $tool_name Tool name.
     * @param bool   $enabled   On or off.
     * @return bool
     */
    public static function set_tool_enabled( string $tool_name, bool $enabled ): bool {
        return self::write_map( self::OPTION_TOOL_SWITCHES, 'tools', $tool_name, $enabled );
    }

    /**
     * Whether one ability category is switched on.
     *
     * @param string $category Category slug.
     * @return bool
     */
    public static function category_enabled( string $category ): bool {
        return self::read_map( self::OPTION_TOOL_SWITCHES, 'categories', $category, self::DEFAULT_TOOL_ENABLED );
    }

    /**
     * Set one category's switch.
     *
     * @param string $category Category slug.
     * @param bool   $enabled  On or off.
     * @return bool
     */
    public static function set_category_enabled( string $category, bool $enabled ): bool {
        return self::write_map( self::OPTION_TOOL_SWITCHES, 'categories', $category, $enabled );
    }

    /**
     * Save a validated set of tool switches, preserving settings for absent extensions.
     *
     * @param array     $tools      Tool names mapped to booleans.
     * @param array     $categories Category names mapped to booleans.
     * @param bool|null $unaudited  Unreviewed tools switch, or null when not displayed.
     * @return bool Whether the settings were stored.
     */
    public static function set_tool_settings( array $tools, array $categories, ?bool $unaudited = null ): bool {
        $stored = get_option( self::OPTION_TOOL_SWITCHES, null );
        $value  = array();
        foreach ( array(
            'tools'      => $tools,
            'categories' => $categories,
        ) as $section => $switches ) {
            foreach ( $switches as $enabled ) {
                if ( ! is_bool( $enabled ) ) {
                    return false;
                }
            }
            $existing          = null === $stored ? array() : self::map_section( $stored, $section );
            $value[ $section ] = array_replace( $existing ?? array(), $switches );
        }
        update_option( self::OPTION_TOOL_SWITCHES, $value, false );
        $saved = get_option( self::OPTION_TOOL_SWITCHES ) === $value;
        if ( null !== $unaudited ) {
            $unaudited_saved = self::set_unaudited_extension_tools_enabled( $unaudited );
            $saved           = $saved && $unaudited_saved;
        }
        return $saved;
    }

    /**
     * Whether the "Not reviewed by MainWP" section is on. Each tool in it also
     * has its own tool switch.
     *
     * @return bool
     */
    public static function unaudited_extension_tools_enabled(): bool {
        return self::read_flag( self::OPTION_UNAUDITED_TOOLS_ENABLED, self::DEFAULT_UNAUDITED_TOOLS_ENABLED );
    }

    /**
     * Set the "Not reviewed by MainWP" section switch.
     *
     * @param bool $enabled On or off.
     * @return bool
     */
    public static function set_unaudited_extension_tools_enabled( bool $enabled ): bool {
        return self::write_flag( self::OPTION_UNAUDITED_TOOLS_ENABLED, $enabled );
    }

    /**
     * A stored on/off switch: missing reads as the default, '1' and '0' as on and off, and any
     * other stored value as off.
     *
     * @param string $option  Option name.
     * @param bool   $fallback Value when nothing is stored.
     * @return bool
     */
    private static function read_flag( string $option, bool $fallback ): bool {
        $stored = get_option( $option, null );
        if ( null === $stored ) {
            return $fallback;
        }
        return '1' === $stored;
    }

    /**
     * Store an on/off switch as '1' or '0'.
     *
     * @param string $option  Option name.
     * @param bool   $enabled On or off.
     * @return bool Whether the stored value now matches.
     */
    private static function write_flag( string $option, bool $enabled ): bool {
        update_option( $option, $enabled ? '1' : '0', false );
        return ( $enabled ? '1' : '0' ) === get_option( $option );
    }

    /**
     * One entry of a stored map of switches. A missing option or entry reads as the default; an
     * option of the wrong shape, or an entry that isn't a real boolean, reads as off.
     *
     * @param string      $option  Option name.
     * @param string|null $section Top-level key, or null for a flat map.
     * @param string      $key     Entry key, compared byte for byte.
     * @param bool        $fallback Value when nothing is stored.
     * @return bool
     */
    private static function read_map( string $option, ?string $section, string $key, bool $fallback ): bool {
        $stored = get_option( $option, null );
        if ( null === $stored ) {
            return $fallback;
        }
        $map = self::map_section( $stored, $section );
        if ( null === $map ) {
            return false;
        }
        if ( ! array_key_exists( $key, $map ) ) {
            return $fallback;
        }
        return true === $map[ $key ];
    }

    /**
     * Set one entry of a stored map. A malformed option is started over, so a setter never keeps
     * the damaged value around.
     *
     * @param string      $option  Option name.
     * @param string|null $section Top-level key, or null for a flat map.
     * @param string      $key     Entry key.
     * @param bool        $enabled On or off.
     * @return bool Whether the stored entry now matches.
     */
    private static function write_map( string $option, ?string $section, string $key, bool $enabled ): bool {
        $stored = get_option( $option, null );
        if ( null === $section ) {
            $value         = is_array( $stored ) && null !== self::map_section( $stored, null ) ? $stored : array();
            $value[ $key ] = $enabled;
        } else {
            $value = array(
                'tools'      => array(),
                'categories' => array(),
            );
            foreach ( array_keys( $value ) as $name ) {
                $existing = null === $stored ? array() : self::map_section( $stored, $name );
                if ( null !== $existing ) {
                    $value[ $name ] = $existing;
                }
            }
            $value[ $section ][ $key ] = $enabled;
        }
        update_option( $option, $value, false );
        return self::read_map( $option, $section, $key, ! $enabled ) === $enabled;
    }

    /**
     * The switch map in a stored value, or null when the value has the wrong shape.
     *
     * @param mixed       $stored  Stored option value.
     * @param string|null $section Top-level key, or null for a flat map.
     * @return array|null
     */
    private static function map_section( $stored, ?string $section ): ?array {
        if ( ! is_array( $stored ) ) {
            return null;
        }
        if ( null !== $section ) {
            if ( ! isset( $stored[ $section ] ) || ! is_array( $stored[ $section ] ) ) {
                return null;
            }
            $stored = $stored[ $section ];
        }
        foreach ( $stored as $value ) {
            if ( ! is_bool( $value ) ) {
                return null;
            }
        }
        return $stored;
    }

    /**
     * Whether the stored toggle is on. Runtime checks use is_active(), never this alone.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return '1' === (string) get_option( self::OPTION_ENABLED, '' );
    }

    /**
     * The one predicate for "the connector is on": the toggle, unless the kill switch forces it
     * off. Read on every call, never cached, so a filter an mu-plugin or a later
     * plugin adds still takes effect on the next request.
     *
     * @return bool
     */
    public static function is_active(): bool {
        return static::enabled() && null === static::forced_off();
    }

    /**
     * Why the kill switch is on, or null.
     *
     * @return string|null `constant`, `filter` or null.
     */
    public static function forced_off(): ?string {
        $constant = defined( static::KILL_SWITCH_CONSTANT ) && (bool) constant( static::KILL_SWITCH_CONSTANT );
        // The constant wins: with it set the filter is never asked, so no plugin can turn a
        // constant-disabled connector back on.
        if ( $constant ) {
            return static::forced_off_reason( true, false );
        }
        // Anything but an explicit false counts as off, so a filter that returns a stray value
        // fails closed.
        $filtered = false !== apply_filters( static::FORCE_DISABLED_FILTER, false );
        return static::forced_off_reason( false, $filtered );
    }

    /**
     * Kill switch decision as a pure function of its two inputs.
     *
     * @param bool $constant Whether the constant is truthy.
     * @param bool $filtered Whether the filter forced it off.
     * @return string|null `constant`, `filter` or null.
     */
    public static function forced_off_reason( bool $constant, bool $filtered ): ?string {
        if ( $constant ) {
            return 'constant';
        }
        return $filtered ? 'filter' : null;
    }

    /**
     * Cached competing rewrite rules.
     *
     * @return array Bool `openid` and `authorization_server`.
     */
    public static function competing_rules(): array {
        $stored = get_option( self::OPTION_COMPETING_RULES );
        return array(
            'openid'               => is_array( $stored ) && ! empty( $stored['openid'] ),
            'authorization_server' => is_array( $stored ) && ! empty( $stored['authorization_server'] ),
        );
    }

    /**
     * Current token secret.
     *
     * @return array|null Array with int `version` and 32 raw bytes in `key`, or null when none exists.
     */
    public static function get_secret(): ?array {
        $stored = get_option( self::OPTION_SECRET );
        if ( ! is_array( $stored ) || empty( $stored['version'] ) || ! is_string( $stored['key'] ?? null ) ) {
            return null;
        }
        $key = base64_decode( $stored['key'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the raw secret is stored base64 so the option survives charset conversion.
        if ( false === $key || 32 !== strlen( $key ) ) {
            return null;
        }
        return array(
            'version' => (int) $stored['version'],
            'key'     => $key,
        );
    }

    /**
     * Store the first secret; the first writer wins.
     *
     * Not add_option(): it only pre-checks the option cache and then runs INSERT ... ON DUPLICATE KEY
     * UPDATE, so a concurrent first use would overwrite a secret another request already hashed with.
     *
     * @param int    $version Secret version.
     * @param string $key     32 raw bytes.
     * @return bool Whether this call stored its secret.
     */
    public static function add_secret( int $version, string $key ): bool {
        global $wpdb;
        // 'no' means "not autoloaded" on the WordPress 6.2 floor and still does on 6.6+, which writes
        // 'off' itself but excludes every value outside its autoload list.
        $inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- first-writer-wins insert the options API can't express.
            $wpdb->prepare(
                "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $wpdb->options,
                self::OPTION_SECRET,
                maybe_serialize( self::secret_value( $version, $key ) )
            )
        );
        // The insert bypassed the options API, so drop whatever a miss before it cached.
        wp_cache_delete( self::OPTION_SECRET, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        return 1 === $inserted;
    }

    /**
     * Replace the secret.
     *
     * @param int    $version Secret version.
     * @param string $key     32 raw bytes.
     * @return bool
     */
    public static function replace_secret( int $version, string $key ): bool {
        return update_option( self::OPTION_SECRET, self::secret_value( $version, $key ), false );
    }

    /**
     * Stored shape of the secret.
     *
     * @param int    $version Secret version.
     * @param string $key     32 raw bytes.
     * @return array
     */
    private static function secret_value( int $version, string $key ): array {
        return array(
            'version' => $version,
            'key'     => base64_encode( $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- see get_secret().
        );
    }
}
