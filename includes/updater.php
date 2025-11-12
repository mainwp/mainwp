<?php
/**
 * Universal Updater Drop-In (UUPD) for Plugins & Themes
 * --------------------------------------------------------
 * Supports:
 *  - Private update servers (via JSON metadata)
 *  - GitHub-based updates (auto-detected via `server` URL)
 *  - Manual update triggers
 *  - Caching via WordPress transients
 *  - Optional GitHub authentication (for private repos or rate-limiting)
 *
 * Safe to include multiple times. Class is namespaced and encapsulated.
 *
 * ╭───────────────────────────── GitHub Token Filters ─────────────────────────────╮
 *
 * ➤ Override GitHub tokens globally or per plugin slug:
 *
 *   // A. Apply a single fallback token for all GitHub plugins:
 *   add_filter( 'uupd/github_token_override', function( $token, $slug ) {
 *       return 'ghp_yourGlobalFallbackToken';
 *   }, 10, 2 );
 *
 *   // B. Apply per-slug tokens only when needed:
 *   add_filter( 'uupd/github_token_override', function( $token, $slug ) {
 *       $tokens = [
 *           'plugin-slug-1' => 'ghp_tokenForPlugin1',
 *           'plugin-slug-2' => 'ghp_tokenForPlugin2',
 *       ];
 *       return $tokens[ $slug ] ?? $token;
 *   }, 10, 2 );
 *
 * ╰────────────────────────────────────────────────────────────────────────────────╯
 *
 * ╭──────────────────────────── Plugin Integration ─────────────────────────────╮
 *
 * 1. Save this file to: `includes/updater.php` inside your plugin.
 *
 * 2. In your main plugin file (e.g. `my-plugin.php`), add:
 *
 *    add_action( 'plugins_loaded', function() {
 *        require_once __DIR__ . '/includes/updater.php';
 *
 *        $updater_config = [
 *            'plugin_file'   => plugin_basename( __FILE__ ),     // e.g. "my-plugin/my-plugin.php"
 *            'slug'          => 'my-plugin-slug',                // must match your update slug
 *            'name'          => 'My Plugin Name',                // shown in the update UI
 *            'version'       => MY_PLUGIN_VERSION,               // define as constant
 *            'key'           => 'YourSecretKeyHere',             // optional if using GitHub
 *            'server'        => 'https://github.com/user/repo',  // GitHub or private server
 *            'github_token'  => 'ghp_YourTokenHere',             // optional
 *            // 'textdomain' => 'my-plugin-textdomain',         // optional, defaults to 'slug'
 *            // 'allow_prerelease'=> false, // Optional — default is false. Set to true to allow beta/RC updates.
 *        ];
 *
 *        \UUPD\V1\UUPD_Updater_V1::register( $updater_config );
 *    }, 1 );
 *
 * ╰─────────────────────────────────────────────────────────────────────────────╯
 *
 * ╭──────────────────────────── Theme Integration ──────────────────────────────╮
 *
 * 1. Save this file to: `includes/updater.php` inside your theme.
 *
 * 2. In your `functions.php`, add:
 *
 *    add_action( 'after_setup_theme', function() {
 *        require_once get_stylesheet_directory() . '/includes/updater.php';
 *
 *        $updater_config = [
 *            'slug'         => 'my-theme-folder',                // must match theme folder
 *            'name'         => 'My Theme Name',
 *            'version'      => '1.0.0',                           // match style.css Version
 *            'key'          => 'YourSecretKeyHere',              // optional if using GitHub
 *            'server'       => 'https://github.com/user/repo',   // GitHub or private
 *            'github_token' => 'ghp_YourTokenHere',              // optional
 *            // 'textdomain' => 'my-theme-textdomain',
 *            // 'allow_prerelease'=> false, // <--- NEW: optional, defaults to false
 *        ];
 *
 *        add_action( 'admin_init', function() use ( $updater_config ) {
 *            \UUPD\V1\UUPD_Updater_V1::register( $updater_config );
 *        } );
 *    } );
 *
 * ╰─────────────────────────────────────────────────────────────────────────────╯
 *
 *  * ╭────────────────────────────── Cache Duration Filters ───────────────────────────────╮
 *
 * ➤ Customize how long update data is cached (success or failure) per slug or globally:
 *
 *   // A. Change success cache duration (default: 6 hours):
 *   add_filter( 'uupd_success_cache_ttl', function( $ttl, $slug ) {
 *       if ( $slug === 'my-plugin-slug' ) {
 *           return 1 * HOUR_IN_SECONDS; // Cache successful metadata for 1 hour
 *       }
 *       return $ttl;
 *   }, 10, 2 );
 *
 *   // B. Change error cache duration (e.g., if remote server is unreachable):
 *   add_filter( 'uupd_fetch_remote_error_ttl', function( $ttl, $slug ) {
 *       return 15 * MINUTE_IN_SECONDS; // Retry failed fetches after 15 minutes
 *   }, 10, 2 );
 *
 * ➤ These filters help balance performance and responsiveness for different update sources.
 *
 * ╰──────────────────────────────────────────────────────────────────────────────────────╯
 *
 *
 *
 * 🔧 Optional Debugging:
 *     Add this anywhere in your code:
 *         add_filter( 'updater_enable_debug', fn( $e ) => true );
 *
 *     Also enable in wp-config.php:
 *         define( 'WP_DEBUG', true );
 *         define( 'WP_DEBUG_LOG', true ); *
 *
 * What This Does:
 *  - Detects updates from GitHub or private JSON endpoints
 *  - Auto-selects GitHub logic if `server` contains "github.com"
 *  - Caches metadata in `upd_{slug}` for 6 hours
 *  - Injects WordPress update data via native transients
 *  - Adds “View details” + “Check for updates” under plugin/theme row
 *  - Works seamlessly with `wp_update_plugins()` or `wp_update_themes()`
 *
 *
 * Scoped Filters:
 *   All filters like `uupd/server_url`, `uupd/remote_url`, etc., also support per-slug filters.
 *   Example:
 *      add_filter( 'uupd/server_url/my-plugin-slug', function( $url ) {
 *          return 'https://mydomain.com/my-endpoint';
 *      });
 *
 *
 *
 */

namespace MainWP\Dashboard\UUPD\V1;

if ( ! class_exists( __NAMESPACE__ . '\UUPD_Updater_V1' ) ) {

    class UUPD_Updater_V1 {

        const VERSION = '1.3.1'; // Change as needed


        private static function apply_filters_per_slug( $filter_base, $default, $slug ) {
            $slug = sanitize_key( $slug );
            $scoped = apply_filters( "{$filter_base}/{$slug}", $default, $slug );
            return apply_filters( $filter_base, $scoped, $slug );
        }


        /** @var array Configuration settings */
        private $config;

         /** @var bool Fetch success */
        private $fetch_success = false;

        /**
         * Constructor.
         *
         * @param array $config {
         *   @type string 'slug'        Plugin or theme slug.
         *   @type string 'name'        Human-readable name.
         *   @type string 'version'     Current version.
         *   @type string 'key'         Your secret key.
         *   @type string 'server'      Base URL of your updater endpoint.
         *   @type string 'plugin_file' (optional) plugin_basename(__FILE__) for plugins.
         *   @type bool 'allow_prerelease' (optional) Whether to allow updates to prerelease versions (e.g. -beta, -rc).
         *
         * }
         */
        public function __construct( array $config ) {
             // Allow plugins to override full config dynamically
            $config = self::apply_filters_per_slug( 'uupd/filter_config', $config, $config['slug'] ?? '' );

            // Allow override of prerelease flag (per-slug logic)
            $config['allow_prerelease'] = self::apply_filters_per_slug(
                'uupd/allow_prerelease',
                $config['allow_prerelease'] ?? false,
                $config['slug'] ?? ''
            );


            // Allow overriding the server URL
            $config['server'] = self::apply_filters_per_slug(
                'uupd/server_url',
                $config['server'] ?? '',
                $config['slug'] ?? ''
            );



            $this->config = $config;
            $this->log( "✓ Using UUPD_Updater_V1 version " . self::VERSION );
            $this->register_hooks();
        }

        /** Attach update and info filters for plugin or theme. */
        private function register_hooks() {
            if ( ! empty( $this->config['plugin_file'] ) ) {
                add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'plugin_update' ] );
                add_filter( 'site_transient_update_plugins',     [ $this, 'plugin_update' ] ); // WP 6.8
                add_filter( 'plugins_api',                       [ $this, 'plugin_info' ], 10, 3 );
            } else {
                add_filter( 'pre_set_site_transient_update_themes', [ $this, 'theme_update' ] );
                add_filter( 'site_transient_update_themes',          [ $this, 'theme_update' ] ); // WP 6.8
                add_filter( 'themes_api',                            [ $this, 'theme_info' ], 10, 3 );
            }
        }

        /** Fetch metadata JSON from remote server and cache it. */
        private function fetch_remote() {
            $c          = $this->config;
            $slug_plain = $c['slug'] ?? '';

            if ( empty( $c['server'] ) ) {
                $this->log( 'No server URL configured — skipping fetch and caching an error state.' );
                $ttl = self::apply_filters_per_slug( 'uupd_fetch_remote_error_ttl', 6 * HOUR_IN_SECONDS, $slug_plain );
                set_transient( 'upd_' . $slug_plain . '_error', time(), $ttl );
                do_action( 'uupd_metadata_fetch_failed', [ 'slug' => $slug_plain, 'server' => '', 'message' => 'No server configured' ] );
                do_action( "uupd_metadata_fetch_failed/{$slug_plain}", [ 'slug' => $slug_plain, 'server' => '', 'message' => 'No server configured' ] );
                return;
            }
            $slug_qs    = rawurlencode( $slug_plain ); // only for the URL query
            $key_qs     = rawurlencode( isset( $c['key'] ) ? $c['key'] : '' );
            $host_qs    = rawurlencode( wp_parse_url( untrailingslashit( home_url() ), PHP_URL_HOST ) );

            $separator  = strpos( $c['server'], '?' ) === false ? '?' : '&';
            $url = ( self::ends_with( $c['server'], '.json' ) ? $c['server'] : untrailingslashit( $c['server'] ) )
                 . $separator . "action=get_metadata&slug={$slug_qs}&key={$key_qs}&domain={$host_qs}";



            // Allow full override of constructed URL
            $url = self::apply_filters_per_slug( 'uupd/remote_url', $url, $slug_plain );

            $failure_cache_key = 'upd_' . $slug_plain . '_error';

            $this->log( " Fetching metadata: {$url}" );
            do_action( 'uupd/before_fetch_remote', $slug_plain, $c );
            $this->log( "→ Triggered action: uupd/before_fetch_remote for '{$slug_plain}'" );

            $resp = wp_remote_get( $url, [
                'timeout' => 15,
                'headers' => [ 'Accept' => 'application/json' ],
            ] );

            if ( is_wp_error( $resp ) ) {
                $msg = $resp->get_error_message();
                $this->log( " WP_Error: $msg — caching failure for 6 hours" );
                $ttl = self::apply_filters_per_slug( 'uupd_fetch_remote_error_ttl', 6 * HOUR_IN_SECONDS, $slug_plain );
                set_transient( $failure_cache_key, time(), $ttl );
                do_action( 'uupd_metadata_fetch_failed', [ 'slug' => $slug_plain, 'server' => $c['server'], 'message' => $msg ] );
                do_action( "uupd_metadata_fetch_failed/{$slug_plain}", [ 'slug' => $slug_plain, 'server' => $c['server'], 'message' => $msg ] );
                return;
            }

            $code = wp_remote_retrieve_response_code( $resp );
            $body = wp_remote_retrieve_body( $resp );

            $this->log( "← HTTP {$code}: " . trim( $body ) );

            if ( 200 !== (int) $code ) {
                $this->log( "Unexpected HTTP {$code} — update fetch will pause until next cycle" );
                $ttl = self::apply_filters_per_slug( 'uupd_fetch_remote_error_ttl', 6 * HOUR_IN_SECONDS, $slug_plain );
                set_transient( $failure_cache_key, time(), $ttl );
                do_action( 'uupd_metadata_fetch_failed', [ 'slug' => $slug_plain, 'server' => $c['server'], 'code' => $code ] );
                do_action( "uupd_metadata_fetch_failed/{$slug_plain}", [ 'slug' => $slug_plain, 'server' => $c['server'], 'code' => $code ] );
                return;
            }

            $meta = json_decode( $body );
            if ( ! $meta ) {
                $this->log( ' JSON decode failed — caching error state' );
                $ttl = self::apply_filters_per_slug( 'uupd_fetch_remote_error_ttl', 6 * HOUR_IN_SECONDS, $slug_plain );
                set_transient( $failure_cache_key, time(), $ttl );
                do_action( 'uupd_metadata_fetch_failed', [ 'slug' => $slug_plain, 'server' => $c['server'], 'code' => 200, 'message' => 'Invalid JSON' ] );
                do_action( "uupd_metadata_fetch_failed/{$slug_plain}", [ 'slug' => $slug_plain, 'server' => $c['server'], 'code' => 200, 'message' => 'Invalid JSON' ] );
                return;
            }

            // Allow developers to manipulate raw metadata before use
            $meta = self::apply_filters_per_slug( 'uupd/metadata_result', $meta, $slug_plain );

            set_transient( 'upd_' . $slug_plain, $meta, self::apply_filters_per_slug( 'uupd_success_cache_ttl', 6 * HOUR_IN_SECONDS, $slug_plain ) );
            delete_transient( $failure_cache_key );
            $this->log( " Cached metadata '{$slug_plain}' → v" . ( $meta->version ?? 'unknown' ) );
        }



        private function normalize_version( $v ) {
            $v = trim((string) $v);

            // Strip build metadata (SemVer: everything after '+')
            $v = preg_replace('/\+.*$/', '', $v);

            // Drop a leading 'v' (e.g. v1.3.0)
            $v = ltrim($v, "vV");

            // Normalize separators
            $v = str_replace('_', '-', $v);

            // Ensure we have three numeric components (x.y.z)
            if (preg_match('/^\d+\.\d+$/', $v)) {
                $v .= '.0';
            } elseif (preg_match('/^\d+$/', $v)) {
                $v .= '.0.0';
            }

            // Insert a hyphen before pre-release if someone wrote 1.3.0alpha2 / 1.3.0rc
            // Also capture shorthands and synonyms: a,b,pre,preview
            // Capture optional numeric like alpha2 / alpha-2 / alpha.2
            if (preg_match('/^(\d+\.\d+\.\d+)[\.\-]?((?:alpha|a|beta|b|rc|dev|pre|preview))(?:(?:[\.\-]?)(\d+))?$/i', $v, $m)) {
                $core = $m[1];
                $tag  = strtolower($m[2]);
                $num  = isset($m[3]) && $m[3] !== '' ? $m[3] : '0';

                // Map shorthands & synonyms to PHP-recognized tokens
                switch ($tag) {
                    case 'a':       $tag = 'alpha'; break;
                    case 'b':       $tag = 'beta';  break;
                    case 'pre':     // treat "pre/preview" as earlier than RC, closer to beta
                    case 'preview': $tag = 'beta';  break;
                    case 'rc':      $tag = 'rc';    break; // PHP is case-insensitive
                    case 'dev':     $tag = 'dev';   break;
                    case 'er':      $tag = 'er';    break;
                    // alpha/beta already work correctly
                }

                $v = "{$core}-{$tag}.{$num}";
            }

            // If someone wrote "1.3.0-alpha" (no number), pad with .0
            $v = preg_replace('/^(\d+\.\d+\.\d+)-(alpha|beta|rc|dev)(?=$)/i', '$1-$2.0', $v);

            return $v;
        }



        /** Handle plugin update injection. */
        public function plugin_update( $trans ) {
            if ( ! is_object( $trans ) || ! isset( $trans->checked ) || ! is_array( $trans->checked ) ) {
                return $trans;
            }

            $c         = $this->config;
            $file      = $c['plugin_file'];
            $slug      = $c['slug'];
            $cache_id  = 'upd_' . $slug;
            $error_key = $cache_id . '_error';

            $this->log( "Plugin-update hook for '{$slug}'" );

            $current = $trans->checked[ $file ] ?? $c['version'];
            $meta    = get_transient( $cache_id );


            /**
             * Hook for testing.
             *
             * @since 6.0             *
             */
            $testing_fetch = apply_filters( 'mainwp_uupd_testing_fetch_release', false, $slug );

            // Skip if last fetch failed
            if ( ! $testing_fetch && ( false === $meta && get_transient( $error_key ) ) ) {
                $this->log( " Skipping plugin update check for '{$slug}' — previous error cached" );
                //return $trans;
            }

            // Fetch metadata if missing
            if ( $testing_fetch || false === $meta ) {
                if ( isset( $c['server'] ) && strpos( $c['server'], 'github.com' ) !== false ) {
                    $repo_url  = rtrim( $c['server'], '/' );
                    $cache_key = 'uupd_github_release_' . md5( $repo_url );
                    $release   = get_transient( $cache_key );

                    if ( $testing_fetch || false === $release ) {

                        if ( $this->fetch_success ){
                            return $trans;
                        }

                        // $api_url = str_replace( 'github.com', 'api.github.com/repos', $repo_url ) . '/releases/latest';
                        // $token   = self::apply_filters_per_slug( 'uupd/github_token_override', $c['github_token'] ?? '', $slug );

                        // $headers = [ 'Accept' => 'application/vnd.github.v3+json' ];
                        // if ( $token ) {
                        //     $headers['Authorization'] = 'token ' . $token;
                        // }

                        // $this->log( " GitHub fetch: $api_url" );
                        // $response = wp_remote_get( $api_url, [ 'headers' => $headers ] );

                        $release = $this->fetch_github_release( $repo_url, $slug );

                        if ( false === $release ) {
                            $msg = 'GitHub fetch failed or no releases/tags found';
                            $this->log( "✗ GitHub API failed — $msg — caching error state" );
                            set_transient(
                                $error_key,
                                time(),
                                self::apply_filters_per_slug( 'uupd_fetch_remote_error_ttl', 6 * HOUR_IN_SECONDS, $slug )
                            );
                            do_action( 'uupd_metadata_fetch_failed', [ 'slug' => $slug, 'server' => $repo_url, 'message' => $msg ] );
                            do_action( "uupd_metadata_fetch_failed/{$slug}", [ 'slug' => $slug, 'server' => $repo_url, 'message' => $msg ] );
                            return $trans; // or continue depending on surrounding code
                        } else {
                            $ttl = self::apply_filters_per_slug( 'uupd_success_cache_ttl', 6 * HOUR_IN_SECONDS, $slug );
                            set_transient( $cache_key, $release, $ttl );
                            $this->fetch_success = true;
                        }


                        // if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                        //     $release = json_decode( wp_remote_retrieve_body( $response ) );
                        //     $ttl = self::apply_filters_per_slug( 'uupd_success_cache_ttl', 6 * HOUR_IN_SECONDS, $slug );
                        //     set_transient( $cache_key, $release, $ttl );
                        // } else {
                        //     $msg = is_wp_error( $response ) ? $response->get_error_message() : 'Invalid HTTP response';
                        //     $this->log( "✗ GitHub API failed — $msg — caching error state" );
                        //     set_transient(
                        //         $error_key,
                        //         time(),
                        //         self::apply_filters_per_slug( 'uupd_fetch_remote_error_ttl', 6 * HOUR_IN_SECONDS, $slug )
                        //     );
                        //     do_action( 'uupd_metadata_fetch_failed', [ 'slug' => $slug, 'server' => $repo_url, 'message' => $msg ] );
                        //     do_action( "uupd_metadata_fetch_failed/{$slug}", [ 'slug' => $slug, 'server' => $repo_url, 'message' => $msg ] );
                        //     return $trans;
                        // }
                    }

                    if ( isset( $release->tag_name ) ) {
                        $zip_url = $release->zipball_url;

                        // Prefer an uploaded .zip asset if one exists
                        foreach ( $release->assets ?? [] as $asset ) {
                            if ( isset( $asset->name, $asset->browser_download_url ) && self::ends_with( $asset->name, '.zip' ) ) {
                                $zip_url = $asset->browser_download_url;
                                break;
                            }
                        }

                        $meta = (object) [
                            'version'      => ltrim( $release->tag_name, 'v' ),
                            'download_url' => $zip_url,
                            'homepage'     => $release->html_url ?? $repo_url,
                            'sections'     => [ 'changelog' => $release->body ?? '' ],
                        ];
                    } else {
                        $meta = (object) [
                            'version'      => $c['version'],
                            'download_url' => '',
                            'homepage'     => $repo_url,
                            'sections'     => [ 'changelog' => '' ],
                        ];
                    }

                    // Success: clear the error flag for this slug (if any)
                    delete_transient( $error_key );



                } else {
                    $this->fetch_remote(); // Handles error logging + failure cache internally
                    $meta = get_transient( $cache_id );
                }
            }

            // If still no metadata, bail
            if ( ! $meta ) {
                $this->log("No metadata found, skipping update logic.");
                return $trans;
            }

            // Compare versions
            $remote_version     = $meta->version ?? '0.0.0';
            $allow_prerelease   = $this->config['allow_prerelease'] ?? false;

            $current_normalized = $this->normalize_version( $current );
            $remote_normalized  = $this->normalize_version( $remote_version );

            $this->log("Original versions: installed={$current}, remote={$remote_version}");
            $this->log("Normalized versions: installed={$current_normalized}, remote={$remote_normalized}");
            $this->log("Comparing (normalized): installed={$current_normalized} vs remote={$remote_normalized}");

            if (
                ( ! $allow_prerelease && preg_match('/^\d+\.\d+\.\d+-(alpha|beta|rc|dev|preview)(?:[.\-]\d+)?$/i', $remote_normalized) )
                ||
                version_compare( $current_normalized, $remote_normalized, '>=' )
            ) {
                $this->log("Plugin '{$slug}' is up to date (v{$current})");
                $trans->no_update[ $file ] = (object) [
                    'id'           => $file,
                    'slug'         => $slug,
                    'plugin'       => $file,
                    'new_version'  => $current,
                    'url'          => $meta->homepage ?? '',
                    'package'      => '',
                    'icons'        => (array) ( $meta->icons ?? [] ),
                    'banners'      => (array) ( $meta->banners ?? [] ),
                    'tested'       => $meta->tested ?? '',
                    'requires'     => $meta->requires ?? $meta->min_wp_version ?? '',
                    'requires_php' => $meta->requires_php ?? '',
                    'compatibility'=> new \stdClass(),
                ];
                return $trans;
            }

            // Inject update
            $this->log( "Injecting plugin update '{$slug}' → v{$meta->version}" );
            $trans->response[ $file ] = (object) [
                'id'           => $file,
                'name'         => $c['name'],
                'slug'         => $slug,
                'plugin'       => $file,
                'new_version'  => $meta->version ?? $c['version'],
                'package'      => $meta->download_url ?? '',
                'url'          => $meta->homepage ?? '',
                'tested'       => $meta->tested ?? '',
                'requires'     => $meta->requires ?? $meta->min_wp_version ?? '',
                'requires_php' => $meta->requires_php ?? '',
                'sections'     => (array) ( $meta->sections ?? [] ),
                'icons'        => (array) ( $meta->icons ?? [] ),
                'banners'      => (array) ( $meta->banners ?? [] ),
                'compatibility'=> new \stdClass(),
            ];

            unset( $trans->no_update[ $file ] );
            return $trans;
        }


        /**
     * Fetch a GitHub "release" object with fallback logic:
     *  - Try /releases/latest
     *  - If 404 or empty and $allow_prerelease allows, try /releases (list)
     *  - If still empty, fallback to /tags and synthesize a simple release object
     *
     * Returns release object on success, or false on failure.
     */
    private function fetch_github_release( $repo_url, $slug ) {
        $repo_url = rtrim( $repo_url, '/' );
        $api_base = str_replace( 'github.com', 'api.github.com/repos', $repo_url );
        $token    = self::apply_filters_per_slug( 'uupd/github_token_override', $this->config['github_token'] ?? '', $slug );
        $headers  = [ 'Accept' => 'application/vnd.github.v3+json' ];

        $headers['User-Agent'] = 'MainWP/' . \MainWP\Dashboard\MainWP_System::$version;

        if ( $token ) {
            $headers['Authorization'] = 'token ' . $token;
        }

        // 1) Try /releases/latest
        $this->log( " GitHub fetch (latest): {$api_base}/releases/latest" );
        $resp = wp_remote_get( $api_base . '/releases/latest', [ 'headers' => $headers, 'timeout' => 15 ] );

        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $release = json_decode( wp_remote_retrieve_body( $resp ) );
            if ( $release ) {
                return $release;
            }
        }

        // If /releases/latest returned 404 or otherwise, try listing releases.
        // This will include prereleases. We'll respect allow_prerelease flag below.
        $this->log( " GitHub fetch (list): {$api_base}/releases?per_page=10" );
        $resp = wp_remote_get( $api_base . '/releases?per_page=10', [ 'headers' => $headers, 'timeout' => 15 ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $releases = json_decode( wp_remote_retrieve_body( $resp ) );
            if ( is_array( $releases ) && count( $releases ) ) {
                // If allow_prerelease is false, prefer first non-prerelease. Otherwise take the first release.
                $allow_prerelease = $this->config['allow_prerelease'] ?? false;
                if ( $allow_prerelease ) {
                    return $releases[0];
                } else {
                    foreach ( $releases as $r ) {
                        if ( empty( $r->prerelease ) ) {
                            return $r;
                        }
                    }
                    // None non-prerelease found — fall through to next fallback.
                }
            }
        }

        // 3) If there are no releases at all, try tags and synthesize a minimal "release"
        $this->log( " GitHub fetch (tags): {$api_base}/tags?per_page=5" );
        $resp = wp_remote_get( $api_base . '/tags?per_page=5', [ 'headers' => $headers, 'timeout' => 15 ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $tags = json_decode( wp_remote_retrieve_body( $resp ) );
            if ( is_array( $tags ) && ! empty( $tags[0]->name ) ) {
                $tag = $tags[0]->name;
                // Build a minimal release-like object with tag_name and zipball_url
                $release = new \stdClass();
                $release->tag_name      = $tag;
                $release->zipball_url   = $api_base . '/zipball/' . rawurlencode( $tag );
                $release->html_url      = $repo_url;
                $release->body          = '';
                $release->assets        = [];
                $release->prerelease    = preg_match( '/(alpha|beta|rc|preview|pre|dev)/i', $tag ) ? true : false;
                return $release;
            }
        }

        // Failure: return false
        return false;
    }


    public function theme_update( $trans ) {
        if ( ! is_object( $trans ) || ! isset( $trans->checked ) || ! is_array( $trans->checked ) ) {
            return $trans;
        }

        $c         = $this->config;
        $slug      = $c['real_slug'] ?? $c['slug'];      // WP expects real theme folder slug
        $cache_id  = 'upd_' . $c['slug'];               // Transient key for metadata
        $error_key = $cache_id . '_error';              // Transient key for error flag

        $this->log( "Theme-update hook for '{$c['slug']}'" );

        $current = $trans->checked[ $slug ] ?? wp_get_theme( $slug )->get( 'Version' );
        $meta    = get_transient( $cache_id );

        // Skip if last fetch failed
        if ( false === $meta && get_transient( $error_key ) ) {
            $this->log( "Skipping theme update check for '{$c['slug']}' — previous error cached" );
            return $trans;
        }

        // If metadata is missing, try to fetch it (GitHub or private server)
        if ( false === $meta ) {
            if ( isset( $c['server'] ) && strpos( $c['server'], 'github.com' ) !== false ) {
                $repo_url  = rtrim( $c['server'], '/' );
                $cache_key = 'uupd_github_release_' . md5( $repo_url );
                $release   = get_transient( $cache_key );

                if ( false === $release ) {
                    $api_url = str_replace( 'github.com', 'api.github.com/repos', $repo_url ) . '/releases/latest';
                    $token = self::apply_filters_per_slug( 'uupd/github_token_override', $c['github_token'] ?? '', $slug );

                    $headers = [ 'Accept' => 'application/vnd.github.v3+json' ];
                    if ( $token ) {
                        $headers['Authorization'] = 'token ' . $token;
                    }

                    $this->log( " GitHub fetch: $api_url" );
                    $response = wp_remote_get( $api_url, [ 'headers' => $headers ] );

                    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                        $release = json_decode( wp_remote_retrieve_body( $response ) );
                        $ttl = self::apply_filters_per_slug( 'uupd_success_cache_ttl', 6 * HOUR_IN_SECONDS, $slug );
                        set_transient( $cache_key, $release, $ttl );
                    } else {
                        $this->log( 'GitHub API fetch failed — caching error state' );
                        set_transient(
                            $error_key,
                            time(),
                            self::apply_filters_per_slug( 'uupd_fetch_remote_error_ttl', 6 * HOUR_IN_SECONDS, $slug )
                        );
                        do_action( 'uupd_metadata_fetch_failed', [ 'slug' => $c['slug'], 'server' => $repo_url, 'message' => 'GitHub fetch failed' ] );
                        do_action( "uupd_metadata_fetch_failed/{$c['slug']}", [ 'slug' => $c['slug'], 'server' => $repo_url, 'message' => 'GitHub fetch failed' ] );
                        return $trans;
                    }
                }

                if ( isset( $release->tag_name ) ) {
                    $zip_url = $release->zipball_url;

                    // Prefer an uploaded .zip asset if one exists
                    foreach ( $release->assets ?? [] as $asset ) {
                        if ( isset( $asset->name, $asset->browser_download_url ) && self::ends_with( $asset->name, '.zip' ) ) {
                            $zip_url = $asset->browser_download_url;
                            break;
                        }
                    }

                    $meta = (object) [
                        'version'      => ltrim( $release->tag_name, 'v' ),
                        'download_url' => $zip_url,
                        'homepage'     => $release->html_url ?? $repo_url,
                        'sections'     => [ 'changelog' => $release->body ?? '' ],
                    ];
                } else {
                    $meta = (object) [
                        'version'      => $c['version'],
                        'download_url' => '',
                        'homepage'     => $repo_url,
                        'sections'     => [ 'changelog' => '' ],
                    ];
                }

                // Success: clear the error flag for this slug (if any)
                delete_transient( $error_key );
            } else {
                $this->fetch_remote(); // will handle error caching internally
                $meta = get_transient( $cache_id );
            }
        }

        // If still no metadata, bail before touching $meta->...
        if ( ! $meta ) {
            $this->log("No metadata found, skipping update logic.");
            return $trans;
        }

        // Build base info used for both "no update" and "update available"
        $base_info = [
            'theme'        => $slug,
            'url'          => $meta->homepage ?? '',
            'requires'     => $meta->requires ?? $meta->min_wp_version ?? '',
            'requires_php' => $meta->requires_php ?? '',
            'screenshot'   => $meta->screenshot ?? '',
            'tested'       => $meta->tested ?? '',
        ];


        // Compare versions
        $remote_version     = $meta->version ?? '0.0.0';
        $allow_prerelease   = $this->config['allow_prerelease'] ?? false;

        $current_normalized = $this->normalize_version( $current );
        $remote_normalized  = $this->normalize_version( $remote_version );

        $this->log( "Original versions: installed={$current}, remote={$remote_version}" );
        $this->log( "Normalized versions: installed={$current_normalized}, remote={$remote_normalized}" );
        $this->log( "Comparing (normalized): installed={$current_normalized} vs remote={$remote_normalized}" );

        if (
            ( ! $allow_prerelease && preg_match('/^\d+\.\d+\.\d+-(alpha|beta|rc|dev|preview)(?:[.\-]\d+)?$/i', $remote_normalized) )
            || version_compare( $current_normalized, $remote_normalized, '>=' )
        ) {
            $this->log( " Theme '{$c['slug']}' is up to date (v{$current})" );
            $trans->no_update[ $slug ] = (object) array_merge( $base_info, [
                'new_version' => $current,
                'package'     => '',
            ] );
            return $trans;
        }

        $this->log( " Injecting theme update '{$c['slug']}' → v{$meta->version}" );
        $trans->response[ $slug ] = array_merge( $base_info, [
            'new_version' => $meta->version ?? $current,
            'package'     => $meta->download_url ?? ''
        ] );

        unset( $trans->no_update[ $slug ] );
        return $trans;
    }






            /** Provide plugin information for the details popup. */
        public function plugin_info( $res, $action, $args ) {
            $c = $this->config;
            if ( 'plugin_information' !== $action || $args->slug !== $c['slug'] ) {
                return $res;
            }

            $meta = get_transient( 'upd_' . $c['slug'] );
            if ( ! $meta ) {
                return $res;
            }

            // Build sections array (description, installation, faq, screenshots, changelog…)
            $sections = [];
            if ( isset( $meta->sections ) ) {
                foreach ( (array) $meta->sections as $key => $content ) {
                    $sections[ $key ] = $content;
                }
            }

            return (object) [
                'name'            => $c['name'],
                'title'           => $c['name'],               // Popup title
                'slug'            => $c['slug'],
                'version'         => $meta->version        ?? '',
                'author'          => $meta->author         ?? '',
                'author_homepage' => $meta->author_homepage ?? '',
                'requires'        => $meta->requires       ?? $meta->min_wp_version ?? '',
                'tested'          => $meta->tested         ?? '',
                'requires_php'    => $meta->requires_php   ?? '',   // “Requires PHP: x.x or higher”
                'last_updated'    => $meta->last_updated   ?? '',
                'download_link'   => $meta->download_url   ?? '',
                'homepage'        => $meta->homepage       ?? '',
                'sections'        => $sections,
                'icons'           => isset( $meta->icons )   ? (array) $meta->icons   : [],
                'banners'         => isset( $meta->banners ) ? (array) $meta->banners : [],
                'screenshots'     => isset( $meta->screenshots )
                                       ? (array) $meta->screenshots
                                       : [],
            ];
        }

            /** Provide theme information for the details popup. */
            public function theme_info( $res, $action, $args ) {
                $c = $this->config;
                $slug = $c['real_slug'] ?? $c['slug'];

                if ( 'theme_information' !== $action || $args->slug !== $slug ) {
                    return $res;
                }

                $meta = get_transient( 'upd_' . $c['slug'] );
                if ( ! $meta ) {
                    return $res;
                }
                // Safely extract changelog HTML
                if ( isset( $meta->changelog_html ) ) {
                    $changelog = $meta->changelog_html;
                } elseif ( isset( $meta->sections ) ) {
                    if ( is_array( $meta->sections ) ) {
                        $changelog = $meta->sections['changelog'] ?? '';
                    } elseif ( is_object( $meta->sections ) ) {
                        $changelog = $meta->sections->changelog ?? '';
                    } else {
                        $changelog = '';
                    }
                } else {
                    $changelog = '';
                }

                return (object) [
                    'name'          => $c['name'],
                    'slug'          => $c['real_slug'] ?? $c['slug'],
                    'version'       => $meta->version ?? '',
                    'tested'        => $meta->tested ?? '',
                    'requires'      => $meta->min_wp_version ?? '',
                    'sections'      => [ 'changelog' => $changelog ],
                    'download_link' => $meta->download_url ?? '',
                    'icons'         => isset( $meta->icons )   ? (array) $meta->icons   : [],
                    'banners'       => isset( $meta->banners ) ? (array) $meta->banners : [],
                ];
            }

        /** Optional debug logger. */
        private function log( $msg ) {
            if ( apply_filters( 'updater_enable_debug', false ) ) {
                error_log( "[Updater] {$msg}" );
                do_action( 'uupd/log', $msg, $this->config['slug'] ?? '' );
            }
        }


        private static function ends_with( $haystack, $needle ) {
            if ( function_exists( 'str_ends_with' ) ) {
                return \str_ends_with( (string) $haystack, (string) $needle );
            }
            $haystack = (string) $haystack;
            $needle   = (string) $needle;
            if ( $needle === '' ) return true;
            if ( strlen( $needle ) > strlen( $haystack ) ) return false;
            return substr( $haystack, -strlen( $needle ) ) === $needle;
        }




        /**
         * NEW STATIC HELPER: register everything (was the global function before).
         *
         * @param array $config  Same structure you passed to the old uupd_register_updater_and_manual_check().
         */
        public static function register( array $config ) {
            // 1) Instantiate the updater class:
            new self( $config );

            // 2) Add the “Check for updates” link under the plugin row:
            $our_file   = $config['plugin_file'] ?? null;
            $slug       = $config['slug'];
            $textdomain = ! empty( $config['textdomain'] ) ? $config['textdomain'] : $slug;
            // Only register plugin row meta for plugins, not themes
            if ( $our_file ) {
                add_filter(
                    'plugin_row_meta',
                    function( array $links, string $file, array $plugin_data ) use ( $our_file, $slug, $textdomain ) {
                        if ( $file === $our_file ) {
                            $nonce     = wp_create_nonce( 'uupd_manual_check_' . $slug );
                            $check_url = admin_url( sprintf(
                                'admin.php?action=uupd_manual_check&slug=%s&_wpnonce=%s',
                                rawurlencode( $slug ),
                                $nonce
                            ) );

                            $links[] = sprintf(
                                '<a href="%s">%s</a>',
                                esc_url( $check_url ),
                                esc_html__( 'Check for updates', $textdomain )
                            );
                        }
                        return $links;
                    },
                    10,
                    3
                );
            }


            // 3) Hook up the manual‐check listener:
            add_action( 'admin_action_uupd_manual_check', function() use ( $slug, $config ) {
            // 1) Grab the requested slug and normalize it.
            $request_slug = isset( $_REQUEST['slug'] ) ? sanitize_key( wp_unslash( $_REQUEST['slug'] ) ) : '';

            // 2) If the incoming 'slug' doesn’t match this plugin’s slug, bail out early:
            if ( $request_slug !== $slug ) {
                return;
            }

            // 3) Only users who can update plugins/themes should proceed.
            if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) ) {
                wp_die( __( 'Cheatin’ uh?' ) );
            }

            // 4) Verify the nonce for this slug.
            $nonce     = isset( $_REQUEST['_wpnonce'] ) ? wp_unslash( $_REQUEST['_wpnonce'] ) : '';
            $checkname = 'uupd_manual_check_' . $slug;
            if ( ! wp_verify_nonce( $nonce, $checkname ) ) {
                wp_die( __( 'Security check failed.' ) );
            }

            // 5) It’s our plugin’s “manual check,” so clear the transient and force WP to fetch again.
            delete_transient( 'upd_' . $slug );

            //ALSO clear GitHub release cache if using GitHub
            if ( isset( $config['server'] ) && strpos( $config['server'], 'github.com' ) !== false ) {
                $repo_url  = rtrim( $config['server'], '/' );
                $gh_key    = 'uupd_github_release_' . md5( $repo_url );
                delete_transient( $gh_key );
            }

            if ( ! empty( $config['plugin_file'] ) ) {
                wp_update_plugins();
                $redirect = wp_get_referer() ?: admin_url( 'plugins.php' );
            } else {
                wp_update_themes();
                $redirect = wp_get_referer() ?: admin_url( 'themes.php' );
            }

            $redirect = self::apply_filters_per_slug( 'uupd/manual_check_redirect', $redirect, $slug );
            wp_safe_redirect( $redirect );
            exit;
        } );

        }
    }
}
