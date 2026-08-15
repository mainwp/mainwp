<?php
/**
 * MainWP Abilities API Bootstrap
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities
 *
 * Bootstraps the Abilities API integration for MainWP Dashboard.
 * Feature-gated: does nothing if Abilities API is not available.
 */
class MainWP_Abilities {

    /**
     * Maximum encoded GET input size.
     *
     * @var int
     */
    private const MAX_GET_INPUT_BYTES = 8192;

    /**
     * Maximum DELETE JSON body size.
     *
     * @var int
     */
    private const MAX_DELETE_INPUT_BYTES = 1048576;

    /**
     * Initialize the Abilities integration.
     *
     * Cron handlers are always initialized to process any previously queued jobs,
     * even if the Abilities API is later disabled. The cron handler constructor
     * is side-effect free beyond hooking actions, so this is safe.
     *
     * @return void
     */
    public static function init(): void {
        // Always initialize cron handlers for batch processing.
        // This ensures previously queued jobs are processed even if Abilities API
        // is disabled after jobs were created. The cron handler only hooks actions
        // and is safe to call without the Abilities API.
        MainWP_Abilities_Cron::instance();

        // Feature gate: If Abilities API is not available, skip ability registration.
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        if ( false === has_filter( 'rest_request_before_callbacks', array( static::class, 'normalize_rest_input_transport' ) ) ) {
            add_filter(
                'rest_request_before_callbacks',
                array( static::class, 'normalize_rest_input_transport' ),
                5,
                3
            );
        }

        // Hook into MainWP REST authentication to include Abilities API routes.
        // This allows consumer_key/consumer_secret authentication to work for abilities.
        add_filter(
            'mainwp_rest_is_request_to_rest_api',
            array( static::class, 'include_abilities_in_rest_auth' )
        );

        add_action(
            'wp_abilities_api_categories_init',
            array( static::class, 'register_categories' )
        );

        add_action(
            'wp_abilities_api_init',
            array( static::class, 'register_abilities' )
        );
    }

    /**
     * Include Abilities API routes in MainWP REST authentication.
     *
     * This filter allows MainWP abilities to be accessed via both:
     * 1. MainWP's consumer_key/consumer_secret API authentication
     * 2. WordPress Application Passwords (standard REST auth)
     *
     * For Application Passwords to work, we must NOT force MainWP auth
     * on Abilities API requests, as that would conflict with WP's native auth.
     *
     * @param bool $is_mainwp_api Whether the current request is to a MainWP REST API.
     * @return bool True only for actual MainWP REST API requests (not abilities).
     */
    public static function include_abilities_in_rest_auth( bool $is_mainwp_api ): bool {
        // Pass through the original detection - don't extend MainWP auth to Abilities API.
        // Abilities API has its own permission_callback that checks current_user_can()
        // which works with both MainWP API keys (via MainWP_REST_Authentication) and
        // WordPress Application Passwords (via native WP REST auth).
        return $is_mainwp_api;
    }

    /**
     * Restore typed JSON values before the Abilities API validates a request.
     *
     * WordPress reads GET and DELETE ability input only from query parameters.
     * Query parsing cannot distinguish JSON null from strings without a JSON
     * carrier, and credentials must not be placed in DELETE URLs. This adapter
     * is limited to MainWP ability run routes and leaves the legacy bracket-style
     * input query parameter unchanged when no JSON carrier is present.
     *
     * @param mixed            $response Result to send to the client, or null.
     * @param array            $handler  Matched REST route handler.
     * @param \WP_REST_Request $request  Current REST request.
     * @return mixed
     */
    public static function normalize_rest_input_transport( $response, array $handler, \WP_REST_Request $request ) {
        unset( $handler );

        if ( null !== $response ) {
            return $response;
        }

        if ( ! preg_match( '#^/wp-abilities/v1/abilities/mainwp/[a-z0-9-]+/run$#', $request->get_route() ) ) {
            return $response;
        }

        $method = strtoupper( $request->get_method() );
        if ( 'GET' === $method ) {
            return static::normalize_get_input_transport( $response, $request );
        }

        if ( 'DELETE' === $method ) {
            return static::normalize_delete_input_transport( $response, $request );
        }

        return $response;
    }

    /**
     * Normalize the GET input_json query carrier.
     *
     * @param mixed            $response Result to send to the client, or null.
     * @param \WP_REST_Request $request  Current REST request.
     * @return mixed
     */
    private static function normalize_get_input_transport( $response, \WP_REST_Request $request ) {
        $query = $request->get_query_params();
        if ( ! array_key_exists( 'input_json', $query ) ) {
            return $response;
        }

        if ( array_key_exists( 'input', $query ) ) {
            return static::invalid_input_transport();
        }

        $input = static::decode_input_object( $query['input_json'], self::MAX_GET_INPUT_BYTES );
        if ( is_wp_error( $input ) ) {
            return $input;
        }

        unset( $query['input_json'] );
        $query['input'] = $input;
        $request->set_query_params( $query );

        return $response;
    }

    /**
     * Normalize the DELETE application/json body carrier.
     *
     * @param mixed            $response Result to send to the client, or null.
     * @param \WP_REST_Request $request  Current REST request.
     * @return mixed
     */
    private static function normalize_delete_input_transport( $response, \WP_REST_Request $request ) {
        $body  = $request->get_body();
        $query = $request->get_query_params();

        if ( '' === trim( $body ) ) {
            if ( array_key_exists( 'input_json', $query ) ) {
                return static::invalid_input_transport();
            }
            return $response;
        }

        if ( array_key_exists( 'input', $query ) || array_key_exists( 'input_json', $query ) ) {
            return static::invalid_input_transport();
        }

        $content_type = $request->get_content_type();
        if ( ! is_array( $content_type ) || 'application/json' !== $content_type['value'] ) {
            return static::invalid_input_transport();
        }

        if ( strlen( $body ) > self::MAX_DELETE_INPUT_BYTES ) {
            return static::invalid_input_transport();
        }

        $shape   = json_decode( $body, false, 32 );
        $decoded = json_decode( $body, true, 32 );
        if (
            JSON_ERROR_NONE !== json_last_error()
            || ! $shape instanceof \stdClass
            || array( 'input' ) !== array_keys( get_object_vars( $shape ) )
            || ! $shape->input instanceof \stdClass
            || ! is_array( $decoded )
        ) {
            return static::invalid_input_transport();
        }

        $input = $decoded['input'];
        if ( ! is_array( $input ) ) {
            return static::invalid_input_transport();
        }

        $query['input'] = $input;
        $request->set_query_params( $query );

        return $response;
    }

    /**
     * Decode a bounded JSON object without coercing scalar values.
     *
     * @param mixed $raw       Raw JSON value.
     * @param int   $max_bytes Maximum encoded size.
     * @return array|\WP_Error
     */
    private static function decode_input_object( $raw, int $max_bytes ) {
        if ( ! is_string( $raw ) || strlen( $raw ) > $max_bytes ) {
            return static::invalid_input_transport();
        }

        $decoded = json_decode( $raw, true, 32 );
        if (
            JSON_ERROR_NONE !== json_last_error()
            || ! is_array( $decoded )
            || '{' !== substr( ltrim( $raw ), 0, 1 )
        ) {
            return static::invalid_input_transport();
        }

        return $decoded;
    }

    /**
     * Return the closed, non-reflective transport error.
     *
     * @return \WP_Error
     */
    private static function invalid_input_transport(): \WP_Error {
        return new \WP_Error(
            'mainwp_abilities_invalid_input_transport',
            __( 'The ability input transport is invalid.', 'mainwp' ),
            array( 'status' => 400 )
        );
    }

    /**
     * Register MainWP ability categories.
     *
     * @return void
     */
    public static function register_categories(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }

        // Sites category.
        wp_register_ability_category(
            'mainwp-sites',
            array(
                'label'       => __( 'MainWP Sites', 'mainwp' ),
                'description' => __( 'Abilities for managing MainWP child sites including listing, syncing, and monitoring.', 'mainwp' ),
            )
        );

        // Updates category.
        wp_register_ability_category(
            'mainwp-updates',
            array(
                'label'       => __( 'MainWP Updates', 'mainwp' ),
                'description' => __( 'Abilities for managing updates across MainWP child sites including core, plugins, and themes.', 'mainwp' ),
            )
        );

        // Clients category.
        wp_register_ability_category(
            'mainwp-clients',
            array(
                'label'       => __( 'MainWP Clients', 'mainwp' ),
                'description' => __( 'Abilities for managing MainWP clients including creation, updates, and client-site relationships.', 'mainwp' ),
            )
        );

        // Tags category.
        wp_register_ability_category(
            'mainwp-tags',
            array(
                'label'       => __( 'MainWP Tags', 'mainwp' ),
                'description' => __( 'Abilities for managing MainWP tags for organizing sites and clients.', 'mainwp' ),
            )
        );

        // Batch category.
        wp_register_ability_category(
            'mainwp-batch',
            array(
                'label'       => __( 'MainWP Batch Operations', 'mainwp' ),
                'description' => __( 'Abilities for monitoring queued batch operations including sync, update, and site management tasks.', 'mainwp' ),
            )
        );
    }

    /**
     * Register all MainWP abilities.
     *
     * @return void
     */
    public static function register_abilities(): void {
        // Site abilities.
        MainWP_Abilities_Sites::register();

        // Update abilities.
        MainWP_Abilities_Updates::register();

        // Client abilities.
        MainWP_Abilities_Clients::register();

        // Tag abilities.
        MainWP_Abilities_Tags::register();

        // Batch abilities.
        MainWP_Abilities_Batch::register();

        // NOTE: Extensions register their own abilities in their own codebases.
        // Total core abilities: 62 (30 sites + 13 updates + 11 clients + 7 tags + 1 batch).
    }
}
