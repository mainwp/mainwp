<?php
/**
 * MainWP Site URL Corrector.
 *
 * Detects and corrects stored child site URLs whose scheme (http/https) or
 * www prefix does not match the address the child site reports for itself
 * ( get_option( 'siteurl' ) delivered on every sync and stored in the
 * mainwp_wp.siteurl column ). A wrong scheme or www variant breaks the
 * "Jump to WP Admin" login flow while sync keeps working, so users get no
 * signal about the cause. See MWP-1662.
 *
 * Containment invariant: this class may only ever move a URL within
 * {http,https} x {www.,''} of the already-registered host. It never crosses
 * domains, ports or paths, no matter what the child reports.
 *
 * The corrector operates silently: no user notices are created. The audit
 * trail is the per-site url_correct_history website option, a MainWP_Logger
 * line and the public mainwp_site_url_corrected action.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Site_Url_Corrector
 *
 * @package MainWP\Dashboard
 */
class MainWP_Site_Url_Corrector { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Stored and reported URLs match on scheme + host + port (paths are ignored).
     */
    const CATEGORY_IDENTICAL = 'identical';

    /**
     * Same scheme, hosts differ only by a leading www prefix.
     */
    const CATEGORY_WWW_ONLY = 'www_only';

    /**
     * Same host, stored http while the child reports https.
     */
    const CATEGORY_SCHEME_UPGRADE = 'scheme_upgrade';

    /**
     * Same host, stored https while the child reports http.
     */
    const CATEGORY_SCHEME_DOWNGRADE = 'scheme_downgrade';

    /**
     * Both a www difference and an http -> https upgrade.
     */
    const CATEGORY_SCHEME_WWW_UPGRADE = 'scheme_www_upgrade';

    /**
     * Both a www difference and an https -> http downgrade.
     */
    const CATEGORY_SCHEME_WWW_DOWNGRADE = 'scheme_www_downgrade';

    /**
     * Anything else: different domain, different port, unparseable input.
     */
    const CATEGORY_OTHER = 'other';

    /**
     * Website option: user lock. When set, no layer touches or reroutes the URL.
     */
    const OPTION_LOCK = 'url_correct_lock';

    /**
     * Website option: queued correction awaiting verify + apply (JSON).
     */
    const OPTION_PENDING = 'url_correct_pending';

    /**
     * Website option: last recorded suggestion / skip reason (JSON).
     */
    const OPTION_SUGGEST = 'url_correct_suggest';

    /**
     * Website option: applied corrections history (JSON array).
     */
    const OPTION_HISTORY = 'url_correct_history';

    /**
     * Global option enabling automatic apply of safe categories.
     */
    const OPTION_AUTO_ENABLED = 'mainwp_auto_correct_site_url';

    /**
     * Global option: timestamp of the last daily detection sweep.
     */
    const OPTION_LAST_SWEEP = 'mainwp_url_correct_last_sweep';

    /**
     * Global option: set when the queue may contain entries, so the minutely
     * drain can skip its options-table query entirely in the steady state.
     */
    const OPTION_HAS_QUEUE = 'mainwp_url_correct_has_queue';

    /**
     * Freshness gate for the daily sweep: only rows synced within this many days.
     */
    const SWEEP_FRESH_DAYS = 30;

    /**
     * Flip-flop damper: at most one applied correction per site in this many days.
     */
    const DAMPER_DAYS = 14;

    /**
     * Do not re-verify a failed candidate more often than this many days.
     */
    const RETRY_DAYS = 30;

    /**
     * Maximum applied-corrections history entries kept per site.
     */
    const HISTORY_MAX_ENTRIES = 20;

    /**
     * Singleton instance.
     *
     * @var null|self
     */
    private static $instance = null;

    /**
     * Get instance.
     *
     * @return self
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Intentionally empty: the regular-sequence dispatcher instantiates the
     * callback class on every minutely tick ( see
     * MainWP_System_Cron_Jobs::is_process_callable() ), so registering hooks
     * here would stack duplicates. All wiring happens in
     * MainWP_System_Cron_Jobs::init_cron_jobs().
     */
    public function __construct() {
    }

    /**
     * Register the queue drain into the regular sequence process dispatcher.
     *
     * Hooked to the mainwp_register_regular_sequence_process filter.
     *
     * @param array $list_values Registered processes list.
     *
     * @return array Registered processes list.
     */
    public static function hook_regular_sequence_process( $list_values ) {
        if ( is_array( $list_values ) ) {
            $list_values['site_url_correct'] = array(
                'priority' => 10,
                'callback' => array( __CLASS__, 'run_scheduled_tasks' ), // must be array( class_name, method ).
            );
        }
        return $list_values;
    }

    /**
     * Entry point for the regular-sequence dispatcher. Runs under both WP
     * cron and server cron ( cron/generalschedules.php ): performs the daily
     * detection sweep when due, then drains the verify + apply queue.
     */
    public function run_scheduled_tasks() {
        $last_sweep = (int) get_option( self::OPTION_LAST_SWEEP, 0 );
        if ( time() - $last_sweep >= DAY_IN_SECONDS ) {
            MainWP_Utility::update_option( self::OPTION_LAST_SWEEP, time() );
            $this->cron_sweep();
        }
        $this->process_queue();
    }

    /**
     * Classify the difference between the stored URL and the child-reported URL.
     *
     * Compares scheme + host + port only; paths are deliberately ignored
     * because subdirectory installs legitimately report a siteurl with a path
     * while the dashboard stores the home URL.
     *
     * @param string $stored_url   Stored dashboard URL ( mainwp_wp.url ).
     * @param string $reported_url Child-reported URL ( mainwp_wp.siteurl ).
     *
     * @return string One of the CATEGORY_* constants.
     */
    public static function classify( $stored_url, $reported_url ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR - straightforward decision table.
        $stored   = static::parse_url_parts( $stored_url );
        $reported = static::parse_url_parts( $reported_url );

        if ( empty( $stored ) || empty( $reported ) ) {
            return self::CATEGORY_OTHER;
        }

        if ( $stored['port'] !== $reported['port'] ) {
            return self::CATEGORY_OTHER;
        }

        if ( $stored['host'] === $reported['host'] ) {
            if ( $stored['scheme'] === $reported['scheme'] ) {
                return self::CATEGORY_IDENTICAL;
            }
            return 'https' === $reported['scheme'] ? self::CATEGORY_SCHEME_UPGRADE : self::CATEGORY_SCHEME_DOWNGRADE;
        }

        $stored_no_www   = preg_replace( '/^www\./', '', $stored['host'] );
        $reported_no_www = preg_replace( '/^www\./', '', $reported['host'] );

        if ( '' !== $stored_no_www && $stored_no_www === $reported_no_www ) {
            if ( $stored['scheme'] === $reported['scheme'] ) {
                return self::CATEGORY_WWW_ONLY;
            }
            return 'https' === $reported['scheme'] ? self::CATEGORY_SCHEME_WWW_UPGRADE : self::CATEGORY_SCHEME_WWW_DOWNGRADE;
        }

        return self::CATEGORY_OTHER;
    }

    /**
     * Whether a category may be applied automatically.
     *
     * Safe categories only: www changes and http -> https upgrades. Scheme
     * downgrades and cross-domain differences are never auto-applied.
     *
     * @param string $category Category constant.
     *
     * @return bool True when the category is safe to auto-apply.
     */
    public static function is_auto_fixable_category( $category ) {
        return in_array( $category, array( self::CATEGORY_WWW_ONLY, self::CATEGORY_SCHEME_UPGRADE, self::CATEGORY_SCHEME_WWW_UPGRADE ), true );
    }

    /**
     * Whether a category is a scheme/www-class mismatch ( expressible via the
     * Edit screen protocol + www dropdowns ). Cross-domain differences are not.
     *
     * @param string $category Category constant.
     *
     * @return bool True for scheme/www-class categories.
     */
    public static function is_scheme_www_category( $category ) {
        return in_array(
            $category,
            array(
                self::CATEGORY_WWW_ONLY,
                self::CATEGORY_SCHEME_UPGRADE,
                self::CATEGORY_SCHEME_DOWNGRADE,
                self::CATEGORY_SCHEME_WWW_UPGRADE,
                self::CATEGORY_SCHEME_WWW_DOWNGRADE,
            ),
            true
        );
    }

    /**
     * Build the corrected candidate URL: reported scheme + host, stored path.
     *
     * The stored URL's path ( including its trailing slash ) is preserved;
     * only scheme and host are taken from the child-reported URL.
     *
     * @param string $stored_url   Stored dashboard URL.
     * @param string $reported_url Child-reported URL.
     *
     * @return string Candidate URL, or empty string when not buildable.
     */
    public static function detected_candidate_url( $stored_url, $reported_url ) {
        $reported = static::parse_url_parts( $reported_url );
        if ( empty( $reported ) ) {
            return '';
        }

        $port = ! empty( $reported['port'] ) ? ':' . $reported['port'] : '';
        $path = wp_parse_url( trim( (string) $stored_url ), PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';

        return $reported['scheme'] . '://' . $reported['host'] . $port . $path;
    }

    /**
     * Layer 1: the URL the browser-side Jump to WP Admin form should target.
     *
     * When the stored URL and the child-reported URL differ only within a safe
     * scheme/www category, returns the canonical scheme + host with the stored
     * path preserved. In every other case ( identical, locked, downgrade,
     * cross-domain, kill-switch filter, missing data ) returns the stored URL
     * exactly like the previous `$website->url ?: $website->siteurl` behavior.
     *
     * Never used for server-side traffic: backup downloads and premium-update
     * requests must keep using the stored URL.
     *
     * @param object $website Child site object ( needs url, siteurl, id ).
     *
     * @return string Base URL for the browser-bound form ( no trailing-slash changes ).
     */
    public static function browser_target_url( $website ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR - guard clauses.
        $stored = isset( $website->url ) && '' !== $website->url ? $website->url : ( isset( $website->siteurl ) ? $website->siteurl : '' );

        if ( '' === $stored || ! isset( $website->siteurl ) || '' === $website->siteurl ) {
            return $stored;
        }

        $category = static::classify( $stored, $website->siteurl );

        if ( ! static::is_auto_fixable_category( $category ) ) {
            return $stored;
        }

        /**
         * Filter: mainwp_open_site_use_reported_url
         *
         * Kill-switch for routing the Jump to WP Admin login form to the
         * child-reported canonical URL when the stored URL differs only by
         * scheme/www.
         *
         * @param bool   $enabled Default true.
         * @param object $website Child site object.
         *
         * @since 6.2
         */
        if ( ! apply_filters( 'mainwp_open_site_use_reported_url', true, $website ) ) {
            return $stored;
        }

        if ( static::is_locked( $website ) ) {
            return $stored;
        }

        $candidate = static::detected_candidate_url( $stored, $website->siteurl );
        if ( '' === $candidate ) {
            return $stored;
        }

        return esc_url_raw( $candidate );
    }

    /**
     * Layer 3: correct a user-entered URL against the child-reported URL at
     * registration time.
     *
     * Adopts the reported www variant and http -> https scheme upgrades. Never
     * downgrades https -> http ( the entered scheme is kept ) and never crosses
     * domains. Returns the entered URL unchanged in all other cases.
     *
     * @param string $entered_url          User-entered URL.
     * @param string $reported_url         Child-reported URL.
     * @param bool   $allow_scheme_upgrade Optional. Whether an http -> https
     *                                     upgrade may be adopted. Default true.
     *
     * @return string Corrected URL or the original entered URL.
     */
    public static function correct_entered_url( $entered_url, $reported_url, $allow_scheme_upgrade = true ) {
        if ( ! is_string( $entered_url ) || ! is_string( $reported_url ) ) {
            return $entered_url;
        }

        $entered_url  = trim( $entered_url );
        $reported_url = trim( $reported_url );

        $category = static::classify( $entered_url, $reported_url );

        if ( ! static::is_scheme_www_category( $category ) ) {
            return $entered_url;
        }

        $entered  = static::parse_url_parts( $entered_url );
        $reported = static::parse_url_parts( $reported_url );

        // Never downgrade the scheme: keep the entered scheme unless the child reports https.
        $scheme = $allow_scheme_upgrade && 'https' === $reported['scheme'] ? 'https' : $entered['scheme'];
        $port   = ! empty( $entered['port'] ) ? ':' . $entered['port'] : '';
        $path   = wp_parse_url( $entered_url, PHP_URL_PATH );
        $path   = is_string( $path ) ? $path : '';

        return $scheme . '://' . $reported['host'] . $port . $path;
    }

    /**
     * Whether automatic URL correction is locked for a site.
     *
     * @param object|int $website Child site object or ID.
     *
     * @return bool True when locked.
     */
    public static function is_locked( $website ) {
        $value = MainWP_DB::instance()->get_website_option( $website, self::OPTION_LOCK, 0 );
        return ! empty( $value );
    }

    /**
     * Set or unset the per-site URL lock. Locking also clears any queued correction.
     *
     * @param object|int $website Child site object or ID.
     * @param bool       $locked  Lock state.
     */
    public static function set_lock( $website, $locked ) {
        MainWP_DB::instance()->update_website_option( $website, self::OPTION_LOCK, $locked ? 1 : 0 );
        if ( $locked ) {
            MainWP_DB::instance()->update_website_option( $website, self::OPTION_PENDING, '' );
        }
    }

    /**
     * Record that a user ( UI, REST API, Abilities API, WP-CLI ) explicitly set
     * a site URL. When the chosen URL diverges from the child-reported
     * canonical, the per-site lock is set automatically so the corrector never
     * reverts a deliberate choice.
     *
     * @param object|int $website Child site object ( needs id + siteurl ) or site ID.
     * @param string     $new_url The URL the user set.
     */
    public static function after_user_set_url( $website, $new_url ) {
        if ( is_numeric( $website ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( intval( $website ) );
        }

        if ( empty( $website ) || ! is_object( $website ) || empty( $new_url ) || ! is_string( $new_url ) ) {
            return;
        }

        $reported = isset( $website->siteurl ) ? $website->siteurl : '';
        if ( '' === $reported ) {
            return;
        }

        $category = static::classify( $new_url, $reported );
        if ( self::CATEGORY_IDENTICAL === $category ) {
            return;
        }

        static::set_lock( $website, true );
        MainWP_Logger::instance()->info_for_website( $website, 'URL AUTO-CORRECT', 'Lock enabled: user-set URL [' . $new_url . '] differs from the child-reported address [' . $reported . '].' );
    }

    /**
     * Daily sweep ( Layer 2 detection ). Invoked via run_scheduled_tasks()
     * once per day.
     *
     * One candidate SQL query fleet-wide; classification in PHP. Actionable
     * safe-category mismatches are queued for the throttled verify + apply
     * drain when automatic correction is enabled; otherwise the finding is
     * journaled to the per-site suggest option ( silent, support-facing ).
     * The first run doubles as the backfill for the existing install base.
     */
    public function cron_sweep() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR - guard clauses.
        $rows = MainWP_DB::instance()->get_websites_with_url_siteurl_mismatch( self::SWEEP_FRESH_DAYS );
        if ( empty( $rows ) ) {
            return;
        }

        $auto_enabled = (int) get_option( self::OPTION_AUTO_ENABLED, 1 );

        foreach ( $rows as $row ) {
            $category = static::classify( $row->url, $row->siteurl );

            if ( ! static::is_scheme_www_category( $category ) ) {
                continue; // identical ( slash/case only ) or cross-domain: nothing to do here.
            }

            if ( static::is_locked( $row->id ) ) {
                continue;
            }

            $candidate = static::detected_candidate_url( $row->url, $row->siteurl );
            if ( '' === $candidate ) {
                continue;
            }

            if ( '/' !== substr( $candidate, -1 ) ) {
                $candidate .= '/'; // canonical form: stored URLs always carry a trailing slash.
            }

            if ( ! $auto_enabled || ! static::is_auto_fixable_category( $category ) ) {
                static::journal_suggest( $row->id, $candidate, $category, $auto_enabled ? 'policy' : 'suggest_only_mode' );
                continue;
            }

            // Re-verify damper: skip candidates that recently failed verification.
            $suggest = MainWP_DB::instance()->get_website_option( $row->id, self::OPTION_SUGGEST, null, true );
            if ( is_array( $suggest ) && isset( $suggest['reason'], $suggest['candidate'], $suggest['ts'] ) && 'verify_failed' === $suggest['reason'] && $suggest['candidate'] === $candidate && ( time() - intval( $suggest['ts'] ) ) < self::RETRY_DAYS * DAY_IN_SECONDS ) {
                continue;
            }

            $pending = MainWP_DB::instance()->get_website_option( $row->id, self::OPTION_PENDING, null, true );
            if ( is_array( $pending ) && isset( $pending['candidate'] ) && $pending['candidate'] === $candidate ) {
                continue; // already queued.
            }

            MainWP_DB::instance()->update_website_option(
                $row->id,
                self::OPTION_PENDING,
                wp_json_encode(
                    array(
                        'candidate' => $candidate,
                        'category'  => $category,
                        'ts'        => time(),
                    )
                )
            );
            MainWP_Utility::update_option( self::OPTION_HAS_QUEUE, 1 );
        }
    }

    /**
     * Throttled verify + apply drain ( Layer 2 apply ).
     *
     * Registered into the minutely regular-sequence dispatcher; each
     * invocation processes a small batch because every verification is a full
     * authed HTTP round-trip to the child site.
     */
    public function process_queue() {
        if ( ! (int) get_option( self::OPTION_AUTO_ENABLED, 1 ) ) {
            return;
        }

        if ( ! get_option( self::OPTION_HAS_QUEUE ) ) {
            return; // steady state: nothing queued, skip the options-table query entirely.
        }

        /**
         * Filter: mainwp_url_correct_batch_size
         *
         * Number of queued URL corrections verified per drain invocation.
         *
         * @param int $batch_size Default 3.
         *
         * @since 6.2
         */
        $batch = (int) apply_filters( 'mainwp_url_correct_batch_size', 3 );
        $ids   = MainWP_DB::instance()->get_website_ids_with_nonempty_option( self::OPTION_PENDING, max( 1, $batch ) );

        if ( empty( $ids ) ) {
            MainWP_Utility::update_option( self::OPTION_HAS_QUEUE, 0 );
            return;
        }

        foreach ( $ids as $site_id ) {
            $this->process_queued_site( intval( $site_id ) );
        }
    }

    /**
     * Verify and apply one queued correction.
     *
     * @param int $site_id Child site ID.
     */
    private function process_queued_site( $site_id ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR - sequential guard pipeline.
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );

        if ( empty( $website ) ) {
            MainWP_DB::instance()->update_website_option( $site_id, self::OPTION_PENDING, '' );
            return;
        }

        // Re-derive everything from the current columns; the queue entry may be stale.
        $category = static::classify( $website->url, isset( $website->siteurl ) ? $website->siteurl : '' );

        if ( ! static::is_auto_fixable_category( $category ) || static::is_locked( $website ) ) {
            MainWP_DB::instance()->update_website_option( $website, self::OPTION_PENDING, '' );
            return;
        }

        $candidate = static::detected_candidate_url( $website->url, $website->siteurl );
        if ( '' === $candidate ) {
            MainWP_DB::instance()->update_website_option( $website, self::OPTION_PENDING, '' );
            return;
        }

        if ( '/' !== substr( $candidate, -1 ) ) {
            $candidate .= '/'; // stored URLs always carry a trailing slash.
        }

        // Flip-flop damper: at most one applied correction per DAMPER_DAYS.
        $history = MainWP_DB::instance()->get_website_option( $website, self::OPTION_HISTORY, null, true );
        $history = is_array( $history ) ? $history : array();
        foreach ( $history as $entry ) {
            if ( isset( $entry['ts'] ) && ( time() - intval( $entry['ts'] ) ) < self::DAMPER_DAYS * DAY_IN_SECONDS ) {
                static::journal_suggest( $website->id, $candidate, $category, 'damper' );
                MainWP_DB::instance()->update_website_option( $website, self::OPTION_PENDING, '' );
                return;
            }
        }

        // Duplicate guard: never collapse two site rows onto the same URL.
        if ( MainWP_DB::instance()->get_website_by_exact_url_excluding_id( $candidate, $website->id ) ) {
            static::journal_suggest( $website->id, $candidate, $category, 'duplicate_site' );
            MainWP_DB::instance()->update_website_option( $website, self::OPTION_PENDING, '' );
            return;
        }

        // Verify-before-apply: one authed request against the candidate URL.
        if ( ! static::verify_candidate_url( $website, $candidate ) ) {
            static::journal_suggest( $website->id, $candidate, $category, 'verify_failed' );
            MainWP_DB::instance()->update_website_option( $website, self::OPTION_PENDING, '' );
            return;
        }

        $old_url = $website->url;

        MainWP_DB::instance()->update_website_values( $website->id, array( 'url' => $candidate ) );

        $history[] = array(
            'old'      => $old_url,
            'new'      => $candidate,
            'category' => $category,
            'source'   => 'auto',
            'ts'       => time(),
        );
        if ( count( $history ) > self::HISTORY_MAX_ENTRIES ) {
            $history = array_slice( $history, - self::HISTORY_MAX_ENTRIES );
        }
        MainWP_DB::instance()->update_website_option( $website, self::OPTION_HISTORY, wp_json_encode( $history ) );
        MainWP_DB::instance()->update_website_option( $website, self::OPTION_PENDING, '' );
        MainWP_DB::instance()->update_website_option( $website, self::OPTION_SUGGEST, '' );

        MainWP_Manage_Sites_List_Table::invalidate_manage_sites_cache();

        MainWP_Logger::instance()->info_for_website( $website, 'URL AUTO-CORRECT', 'Stored URL corrected from [' . $old_url . '] to [' . $candidate . '] (the child site reports this address).' );

        /**
         * Action: mainwp_site_url_corrected
         *
         * Fires after the stored URL of a child site was corrected to the
         * child-reported canonical address.
         *
         * @param int    $website_id Child site ID.
         * @param string $old_url    Previous stored URL.
         * @param string $new_url    New stored URL.
         * @param string $source     Correction source ( 'auto' ).
         *
         * @since 6.2
         */
        do_action( 'mainwp_site_url_corrected', (int) $website->id, $old_url, $candidate, 'auto' );
    }

    /**
     * Verify a candidate URL with one authed request ( 'stats' ) against a
     * clone of the website object. The clone keeps every connection property
     * ( keys, admin name, SSL settings, HTTP auth, IPv4 flag ); only the URL
     * is swapped.
     *
     * @param object $website   Child site object.
     * @param string $candidate Candidate URL ( trailing-slashed ).
     *
     * @return bool True when the child answered the authed request on the candidate URL.
     */
    private static function verify_candidate_url( $website, $candidate ) {
        $probe      = clone $website;
        $probe->url = $candidate;

        try {
            $information = MainWP_Connect::fetch_url_authed( $probe, 'stats' );
        } catch ( MainWP_Exception $e ) {
            return false;
        } catch ( \Exception $e ) {
            return false;
        }

        return is_array( $information ) && ! isset( $information['error'] );
    }

    /**
     * Journal a suggestion / skip reason to the per-site suggest option.
     * Silent by design: only support ( and the Edit screen hint logic ) read it.
     *
     * @param int    $site_id   Child site ID.
     * @param string $candidate Candidate URL.
     * @param string $category  Category constant.
     * @param string $reason    Reason slug ( suggest_only_mode|policy|damper|duplicate_site|verify_failed ).
     */
    private static function journal_suggest( $site_id, $candidate, $category, $reason ) {
        $current = MainWP_DB::instance()->get_website_option( $site_id, self::OPTION_SUGGEST, null, true );
        if ( is_array( $current ) && isset( $current['candidate'], $current['reason'] ) && $current['candidate'] === $candidate && $current['reason'] === $reason && 'verify_failed' !== $reason ) {
            return; // unchanged; avoid daily rewrites. verify_failed always rewrites so its retry timestamp stays fresh.
        }
        MainWP_DB::instance()->update_website_option(
            $site_id,
            self::OPTION_SUGGEST,
            wp_json_encode(
                array(
                    'candidate' => $candidate,
                    'category'  => $category,
                    'reason'    => $reason,
                    'ts'        => time(),
                )
            )
        );
    }

    /**
     * Parse a URL into normalized scheme / host / port parts.
     *
     * @param string $url URL to parse.
     *
     * @return array Empty array when invalid; otherwise scheme ( http|https,
     *               lowercase ), host ( lowercase ) and port ( int, 0 = none ).
     */
    private static function parse_url_parts( $url ) {
        if ( ! is_string( $url ) || '' === trim( $url ) ) {
            return array();
        }

        $parts = wp_parse_url( trim( $url ) );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return array();
        }

        $scheme = strtolower( $parts['scheme'] );
        if ( 'http' !== $scheme && 'https' !== $scheme ) {
            return array();
        }

        return array(
            'scheme' => $scheme,
            'host'   => strtolower( $parts['host'] ),
            'port'   => isset( $parts['port'] ) ? intval( $parts['port'] ) : 0,
        );
    }
}
