<?php
/**
 * MainWP compatible legacy functions.
 *
 * @package MainWP/Dashboard
 */

// phpcs:disable -- legacy functions for backwards compatibility. Required.

if ( ! class_exists( 'MainWP_DB' ) ) {

    /**
     * MainWP Database Compatible class.
     */
    class MainWP_DB { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

        /**
         * Private static variable to hold the single instance of the class.
         *
         * @var mixed Default null
         */
        private static $instance = null;

        /**
         * Create warning friendly placeholder when MainWP core is not ready.
         *
         * @param string $method Method that attempted to run.
         */
        private static function not_ready( $method ) {
            $message = sprintf(
                /* translators: %s: method name. */
                'MainWP_DB::%s() called before MainWP Dashboard finished initializing. Please wait for the "mainwp_ready" hook and try again.',
                $method
            );

            if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
                \WP_CLI::error( $message );
            }

            if ( function_exists( 'wp_die' ) ) {
                if ( function_exists( 'esc_html' ) ) {
                    wp_die( esc_html( $message ) );
                }
                wp_die( $message );
            }

            throw new \RuntimeException( $message ); // phpcs:ignore -- NOSONAR.
        }

        /**
         * Proxy calls to the real MainWP DB singleton when available.
         *
         * @param string $method Method name.
         * @param array  $args   Arguments list.
         *
         * @return mixed
         */
        private function proxy_mainwp_db( $method, $args = array() ) { // phpcs:ignore -- NOSONAR.
            if ( class_exists( '\MainWP\Dashboard\MainWP_DB' ) ) {
                return call_user_func_array(
                    array( \MainWP\Dashboard\MainWP_DB::instance(), $method ),
                    $args
                );
            }

            self::not_ready( $method );
        }

        /**
         * Create public static instance.
         *
         * @return MainWP_DB
         */
        public static function instance() {
            if ( class_exists( '\MainWP\Dashboard\MainWP_DB' ) ) {
                return \MainWP\Dashboard\MainWP_DB::instance();
            }

            if ( null === static::$instance ) {
                static::$instance = new self();
            }

            return static::$instance;
        }

        /**
         * Magic handler for undefined instance methods.
         *
         * @param string $method Method name.
         * @param array  $args   Arguments.
         */
        public function __call( $method, $args ) { // phpcs:ignore -- NOSONAR.
            self::not_ready( $method );
        }

        /**
         * Magic handler for undefined static methods.
         *
         * @param string $method Method name.
         * @param array  $args   Arguments.
         */
        public static function __callStatic( $method, $args ) { // phpcs:ignore -- NOSONAR.
            self::not_ready( $method );
        }

        /**
         * Get child site by ID.
         *
         * @param int   $id           Child site ID.
         * @param array $selectGroups Select groups.
         *
         * @return object|null Database query results or null on failure.
         *
         * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_by_id()
         */
        public function getWebsiteById( $id, $selectGroups = false ) {
            return $this->proxy_mainwp_db( 'get_website_by_id', array( $id, $selectGroups ) );
        }

        /**
         * Get child sites by child site IDs.
         *
         * @param array $ids Child site IDs.
         * @param int   $userId User ID.
         *
         * @return object|null Database query result or null on failure.
         *
         * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_websites_by_ids()
         */
        public function getWebsitesByIds( $ids, $userId = null ) {
            return $this->proxy_mainwp_db( 'get_websites_by_ids', array( $ids, $userId ) );
        }

        /**
         * Get child sites by groups IDs.
         *
         * @param array $ids    Groups IDs.
         * @param int   $userId User ID.
         *
         * @return object|null Database query result or null on failure.
         *
         * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_websites_by_group_ids()
         */
        public function getWebsitesByGroupIds( $ids, $userId = null ) {
            return $this->proxy_mainwp_db( 'get_websites_by_group_ids', array( $ids, $userId ) );
        }

        /**
         * Get sites by user ID.
         *
         * @param int    $userid       User ID.
         * @param bool   $selectgroups Selected groups.
         * @param null   $search_site  Site search field value.
         * @param string $orderBy      Order list by. Default: URL.
         *
         * @return object|null Database query results or null on failure.
         *
         * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_websites_by_user_id()
         */
        public function getWebsitesByUserId( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url' ) {
            return $this->proxy_mainwp_db(
                'get_websites_by_user_id',
                array( $userid, $selectgroups, $search_site, $orderBy )
            );
        }

        /**
         * Get Child site wp_options database table.
         *
         * @param array $website Child Site array.
         * @param mixed $option  Child Site wp_options table name.
         *
         * @return string|null Database query result (as string), or null on failure.
         *
         * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_option()
         */
        public function getWebsiteOption( $website, $option ) {
            return $this->proxy_mainwp_db( 'get_website_option', array( $website, $option ) );
        }

        /**
         * Update child site options.
         *
         * @param object $website Child site object.
         * @param mixed  $option  Option to update.
         * @param mixed  $value   Value to update with.
         *
         * @uses \MainWP\Dashboard\MainWP_DB::instance()::update_website_option()
         */
        public function updateWebsiteOption( $website, $option, $value ) {
            return $this->proxy_mainwp_db( 'update_website_option', array( $website, $option, $value ) );
        }
    }
}

if ( ! class_exists( 'MainWP_System' ) ) {

    /**
     * MainWP System Compatible class
     *
     * @internal
     */
    class MainWP_System { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

        /**
         * Public static variable to hold the current plugin version.
         *
         * @var string Current plugin version.
         */
        public static $version = '4.0.7.2'; // NOSONAR - not IP.

        /**
         * Create public static instance.
         *
         * @return MainWP_System
         */
        public static function Instance() {
            return MainWP\Dashboard\Instance();
        }
    }
}

if ( ! class_exists( 'MainWP_Extensions_View' ) ) {

    /**
     * MainWP Extensions View Compatible class.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
     */
    class MainWP_Extensions_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

        /**
         * Get all available extensions.
         *
         * @return array Available extensions.
         *
         * @devtodo Move to MainWP Server via an XML file.
         */
        public static function getAvailableExtensions() {
            return MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions();
        }
    }
}


/**
 * Global registry for deferred callbacks waiting for mainwp_ready.
 *
 * @global array $mainwp_deferred_callbacks
 */
$GLOBALS['mainwp_deferred_callbacks'] = isset( $GLOBALS['mainwp_deferred_callbacks'] ) ? $GLOBALS['mainwp_deferred_callbacks'] : array();

/**
 * Defer a callback until MainWP is ready.
 *
 * This function allows extensions to safely defer initialization code
 * until MainWP has finished bootstrapping, without requiring the extension
 * to be modified or to hook into mainwp_ready.
 *
 * @param callable $callback The callback to execute when MainWP is ready.
 * @param array    $args     Optional. Arguments to pass to the callback.
 *
 * @return void
 */
if ( ! function_exists( 'mainwp_defer_until_ready' ) ) {
    function mainwp_defer_until_ready( $callback, $args = array() ) {
        // If MainWP is already ready, execute immediately.
        if ( did_action( 'mainwp_ready' ) ) {
            $callback_name = is_array( $callback ) && isset( $callback[0], $callback[1] ) ? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1] : ( is_string( $callback ) ? $callback : 'anonymous' );
            try {
                call_user_func_array( $callback, $args );
            } catch ( \Throwable $e ) {
                if ( class_exists( '\MainWP\Dashboard\MainWP_Logger' ) ) {
                    \MainWP\Dashboard\MainWP_Logger::instance()->log_action(
                        'MainWP deferred callback error in ' . $callback_name . ': ' . $e->getMessage(),
                        \MainWP\Dashboard\MainWP_Logger::EXECUTION_TIME_LOG_PRIORITY
                    );
                }
            }
            return;
        }

        // Otherwise, register for later execution.
        $GLOBALS['mainwp_deferred_callbacks'][] = array(
            'callback' => $callback,
            'args'     => $args,
        );
    }
}

/**
 * To compatible with php version < 7.3.0.
 */
if ( ! function_exists( 'array_key_first' ) ) {
    function array_key_first( array $arr ) {
        foreach ( $arr as $key => $unused ) {
            return $key;
        }
        return null;
    }
}
