<?php
/**
 * MainWP Unhook Helper
 *
 * Utilities to remove 3rd-party actions/filters by name, class, namespace, or file path.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Unhooks_Helper
 *
 * @package MainWP\Dashboard
 */
class MainWP_Unhooks_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private $active_plugins = null;

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method instance()
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
     * Run each time the class is called.
     */
    public function __construct() {
        add_action(
            'plugins_loaded',
            array( $this, 'hook_remove_unwanted_hooks' ),
            0
        );

        add_action(
            'mainwp_shutdown',
            array( $this, 'hook_unhook_shutdown' ),
            10
        );
    }

    /**
     * Remove unwanted hooks.
     */
    public function hook_remove_unwanted_hooks() {

        if ( ! static::is_request_unhooks() ) {
            add_action(
                'current_screen',
                array( $this, 'hook_remove_unwanted_hooks_default_pages' ),
            );
            return;
        }

        /**
         * Hook remove unwanted hooks.
         *
         * @since 5.5.
         */
        $remove = apply_filters( 'mainwp_unhooks_remove_unwanted_hooks', true, 'unwanted_hooks' ); //phpcs:ignore -- ok.

        if ( ! $remove ) {
            return;
        }

        MainWP_Logger::instance()->log_events( 'unhooks', '[hook_remove_unwanted_hooks] :: [page=' . ( isset( $_GET['page'] ) ? esc_html( wp_unslash( $_GET['page'] ) ) : '' ) . ']' );  //phpcs:ignore -- ok.
        $this->remove_slow_hooks();
    }

    /**
     * Default mainwp pages to remove hooks.
     */
    public function hook_remove_unwanted_hooks_default_pages() {

        if ( ! MainWP_System::is_mainwp_pages() ) {
            return;
        }

        if ( static::is_request_unhooks() ) { // requested unhooks.
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore -- ok.

        if ( $this->is_excluded_pages( $page ) ) {
            return;
        }

        /**
         * Hook default mainwp pages to remove unwanted hooks.
         *
         * @since 5.5.
         */
        $remove = apply_filters( 'mainwp_unhooks_remove_unwanted_hooks', true, 'default_pages' ); //phpcs:ignore -- ok.

        if ( $remove ) {
            MainWP_Logger::instance()->log_events( 'unhooks', '[hook_remove_unwanted_hooks_default_pages] :: [page=' . $page . ']' );  //phpcs:ignore -- ok.
            $this->remove_slow_hooks();
        }
    }


    /**
     * Method is_excluded_pages().
     *
     * @param string $page Excluded page check.
     *
     * @return bool Excluded page or not
     */
    public function is_excluded_pages( $page = '' ) {
        if ( empty( $page ) || in_array( $page, (array) $this->get_default_excluded_pages() ) ) {
            return true;
        }
        return false;
    }

    /**
     * Hook shutdown.
     */
    public function hook_unhook_shutdown() {
        if ( ! $this->is_excluded_pages() || static::is_request_unhooks() ) {
            $rt = MainWP_Execution_Helper::get_run_time();
            MainWP_Logger::instance()->log_events( 'unhooks', '[Execution time=' . $rt . '] (seconds)', MainWP_Logger::INFO ); // phpcs:ignore -- ok.
            MainWP_Logger::instance()->log_events( 'execution-time', '[Unhooks] :: [runtime=' . $rt . '] (seconds)', MainWP_Logger::INFO ); // phpcs:ignore -- ok.
        }
    }

    /**
     * Verify unhooks request.
     *
     * @return bool Unhooks or not.
     */
    public static function is_request_unhooks() {
        if ( ! isset( $_GET['_uhnonce'] ) || ! wp_verify_nonce( (string) $_GET['_uhnonce'] , 'unhooks_nonce' ) ) { // phpcs:ignore -- ok.
            return false;
        }
        return true;
    }

    /**
     * Method get_unhooks_active_plugins().
     *
     * @return array Active non mainwp plugins.
     */
    public function get_unhooks_active_plugins() {

        if ( null === $this->active_plugins ) {
            $this->active_plugins = array();
            $mainwp_str           = 'mainwp';
            $plugins_files        = get_option( 'active_plugins', array() );
            if ( is_array( $plugins_files ) ) {
                foreach ( $plugins_files as $indx => $plugin_file ) {
                    if ( false === stripos( $plugin_file, $mainwp_str ) ) {
                        $this->active_plugins[ $indx ] = $plugin_file;
                    }
                }
            }
            /**
             * Get unhooks active plugins files.
             *
             * @since 5.5.
             */
            $this->active_plugins = apply_filters( 'mainwp_unhooks_active_plugins_files', $this->active_plugins );
        }

        return $this->active_plugins;
    }



    /**
     * Get excluded pages.
     */
    public function get_default_excluded_pages() {

        /**
         * Hook get default excluded pages.
         *
         * @since 5.5.
         */
        return apply_filters(
            'mainwp_unhooks_default_excluded_pages',
            array(
                'ActionLogs',
            )
        );
    }


    /**
     * Remove slow hooks.
     */
    public function remove_slow_hooks() {

        $remove_desired_hooks = $this->unhooks_list();

        $unhooks_types = $this->get_unhooks_types();

        if ( is_array( $unhooks_types ) ) {
            foreach ( $unhooks_types as $type => $values ) {
                switch ( $type ) {
                    case 'all_exclude_mainwp':
                    case 'files_names':
                        $files = array();
                        if ( 'files_names' === $type ) {
                            $files = $values;
                        } elseif ( 'all_exclude_mainwp' === $type ) {
                            if ( true === $values ) {
                                $files = $this->get_unhooks_active_plugins();
                            }
                        }
                        if ( is_array( $files ) && ! empty( $files ) ) {
                            foreach ( $remove_desired_hooks as $hook ) {
                                $this->remove_by_file( $hook, $files );
                            }
                        }
                        break;
                    case 'plugin_names':
                        $names = $values;
                        if ( is_array( $names ) && ! empty( $names ) ) {
                            foreach ( $remove_desired_hooks as $hook ) {
                                $this->remove_by_name( $hook, $names );
                            }
                        }
                        break;
                    case 'class_names':
                        $cls_names = $values;
                        if ( is_array( $cls_names ) && ! empty( $cls_names ) ) {
                            foreach ( $remove_desired_hooks as $hook ) {
                                $this->remove_by_class( $hook, $cls_names );
                            }
                        }
                        break;
                    case 'all_specific':
                        $hooks = $values;
                        if ( is_array( $hooks ) ) {
                            foreach ( $hooks as $hook ) {
                                $this->remove_all( $hook );
                            }
                        }
                        break;
                    default:
                        // Nothing.
                        break;
                }
            }
        }
    }

    /**
     * Get un-hooks list.
     *
     * @param string $remove Which hooks list.
     *
     * @return array
     */
    public function unhooks_list( $remove = 'main' ) {

        $main_hooks = array(
            'admin_init',
            'init',
            'plugins_loaded',
        );

        $other_hooks = array(
            'admin_footer',
            'admin_head',
            'admin_menu',
            'plugin_loaded',
            'wp_head',
            'wp_loaded',
            'wp_footer',
            'admin_bar_init',
            'admin_bar_menu',
            'admin_enqueue_scripts',
            'admin_notices',
            'admin_print_footer_scripts',
            'admin_print_scripts',
            'admin_print_styles',
            'after_setup_theme',
            'all_admin_notices',
            'current_screen',
            'get_header',
            'load_textdomain',
            'muplugins_loaded',
            'pre_get_posts',
            'setup_theme',
            'wp',
            'wp_default_scripts',
            'wp_default_styles',
            'wp_enqueue_scripts',
            'wp_print_footer_scripts',
            'wp_print_scripts',
            'wp_print_styles',
            'wp_print_scripts',
        );

        /**
         * Hook remove hooks list.
         *
         * @since 5.5.
         */
        return apply_filters(
            'mainwp_unhooks_list',
            'main' === $remove ? $main_hooks : array_unique( array_merge( $main_hooks, $other_hooks ) )
        );
    }

    /**
     * Get un-hooks types.
     *
     * @return array
     */
    public function get_unhooks_types() {

        /**
         * Hook get unhooks types.
         *
         * @since 5.5.
         */
        return apply_filters(
            'mainwp_unhooks_get_types',
            array(
                'all_exclude_mainwp' => true,
                'files_names'        => array(),
                'plugin_names'       => array(),
                'class_names'        => array(),
                'all_specific'       => false,
            )
        );
    }


    /**
     * Add url params to unhook.
     *
     * @param string $url Url to enable un-hooks.
     *
     * @return string Url with unhook nonce.
     */
    public static function add_unhooks_params( $url ) {
        $url_unhook = $url . '&_uhnonce=' . wp_create_nonce( 'unhooks_nonce' );
        /**
         * Hook add unhooks url params.
         *
         * @since 5.5.
         */
        return apply_filters( 'mainwp_unhooks_url_params', $url_unhook, $url );
    }

    /**
     * Remove callbacks on a hook where the callable's function/method name matches a pattern
     *
     * @param  mixed $hook The hook.
     * @param  mixed $names Array of classes names.
     * @return int
     */
    private function remove_by_name( $hook, $cls_names = array() ) {
        global $wp_filter;
        $removed = 0;
        $failed  = 0;

        if ( empty( $wp_filter[ $hook ] ) ) {
            return 0;
        }

        $hook_obj  = $wp_filter[ $hook ];
        $callbacks = $hook_obj->callbacks ?? array();

        foreach ( $callbacks as $priority => $list ) {
            foreach ( $list as $idx => $entry ) {
                $callable = $entry['function'] ?? null;
                $name     = $this->callable_label( $callable );
                if ( ! empty( $name ) ) {
                    $found = false;
                    foreach ( $cls_names as $find_name ) {
                        if ( false !== stripos( $name, $find_name ) ) {
                            $found = true;
                            break;
                        }
                    }

                    if ( $found ) {
                        $success = remove_action( $hook, $callable, (int) $priority );
                        if ( $success ) {
                            ++$removed;
                        } else {
                            ++$failed;
                        }
                    }
                }
            }
        }
        return $removed;
    }

    /**
     * Remove callbacks whose underlying file path matches a regex (good for targeting a plugin).
     *
     * Note: This is HEAVY function.
     *
     * @param  mixed $hook The hook.
     * @param  mixed $path_files The files path to check hooks.
     * @return int
     */
    private function remove_by_file( $hook, $path_files ) {

        global $wp_filter;

        if ( empty( $wp_filter[ $hook ] ) ) {
            return 0;
        }

        $removed = 0;
        $failed  = 0;

        $hook_obj  = $wp_filter[ $hook ];
        $callbacks = $hook_obj->callbacks ?? array();

        $plugins_dir = 'wp-content/plugins';

        if ( defined( 'WP_PLUGIN_DIR' ) ) {
            $plugins_dir = str_replace( ABSPATH, '', WP_PLUGIN_DIR );
        }

        $lists          = array();
        $lists[ $hook ] = array();
        foreach ( $callbacks as $priority => $list ) {
            foreach ( $list as $idx => $entry ) {
                $callable = $entry['function'] ?? null;
                $file     = $this->callable_file( $callable );
                if ( ! empty( $file ) ) {
                    $found = false;
                    foreach ( $path_files as $dir_file ) {
                        $path = '/' . $plugins_dir . '/' . MainWP_Utility::get_dir_slug( $dir_file ) . '/';
                        if ( false !== stripos( $file, $path ) ) {
                            $found = true;
                            break;
                        }
                    }
                    if ( $found ) {
                        $success = remove_action( $hook, $callable, (int) $priority );
                        if ( $success ) {
                            ++$removed;
                            $lists[ $hook ][] = $file;
                        } else {
                            ++$failed;
                        }
                    }
                }
                unset( $callable );
            }
        }

        if ( $removed || $failed ) {
            MainWP_Logger::instance()->log_events( 'unhooks', '[Unhooks by files] :: [hook=' . $hook . '] :: [removed=' . $removed . '] :: [failed=' . $failed . ']' ); //phpcs:ignore -- ok.
            MainWP_Logger::instance()->log_events( 'unhooks', '[Unhooks by files] :: [lists=' . print_r($lists, true ) . ']' ); //phpcs:ignore -- ok.
        }

        return $removed;
    }

    /**
     * Remove callbacks where the callable belongs to a class/namespace regex.
     *
     * @param  mixed $hook The hook.
     * @param  mixed $classes_names The classes names.
     * @return int
     */
    private function remove_by_class( $hook, $classes_names ) {
        global $wp_filter;
        $removed = 0;
        $failed  = 0;

        if ( empty( $wp_filter[ $hook ] ) ) {
            return 0;
        }

        $hook_obj  = $wp_filter[ $hook ];
        $callbacks = $hook_obj->callbacks ?? array();

        foreach ( $callbacks as $priority => $list ) {
            foreach ( $list as $idx => $entry ) {
                $callable = $entry['function'] ?? null;

                if ( is_array( $callable ) ) {
                    $obj_or_class = $callable[0];
                    $class        = is_object( $obj_or_class ) ? get_class( $obj_or_class ) : $obj_or_class;
                    if ( ! empty( $class ) ) {
                        $found = false;

                        foreach ( $classes_names as $find_class ) {
                            if ( false !== stripos( $class, $find_class ) ) {
                                $found = true;
                                break;
                            }
                        }

                        if ( $found ) {
                            $success = remove_action( $hook, $callable, (int) $priority );
                            if ( $success ) {
                                ++$removed;
                            } else {
                                ++$failed;
                            }
                        }
                    }
                }
            }
        }
        if ( $removed || $failed ) {
            MainWP_Logger::instance()->log_events( 'unhooks', '[Unhooks by class :: [hook=' . $hook . '] :: [removed=' . $removed . '] :: [failed=' . $failed . ']' ); //phpcs:ignore -- ok.
        }

        return $removed;
    }

    /**
     * Remove everything on a hook (optionally only at a given priority).
     * Works for actions & filters.
     *
     * @param  mixed $hook The hook.
     * @param  mixed $priority Hook priority.
     * @return void
     */
    private function remove_all( $hook, $priority = false ) {
        if ( false === $priority ) {
            remove_all_actions( $hook ); // wrapper for remove_all_filters.
        } else {
            remove_all_filters( $hook, (int) $priority );
        }
        MainWP_Logger::instance()->log_events( 'unhooks', '[Unhooks all :: [hook=' . $hook . '] :: [priority=' . (false !== $priority ? (int)$priority : 'false') . ']' ); //phpcs:ignore -- ok.
    }

    /**
     * Human label for any callable.
     *
     * @param  mixed $callback The callable.
     * @return string
     */
    private function callable_label( $callback ) {
        if ( is_string( $callback ) ) {
            return $callback;
        }
        if ( is_array( $callback ) ) {
            $obj_or_class = $callback[0];
            $meth         = $callback[1] ?? '';
            $class        = is_object( $obj_or_class ) ? get_class( $obj_or_class ) : (string) $obj_or_class;
            return $class ? "{$class}::{$meth}" : $meth;
        }
        if ( $callback instanceof \Closure ) {
            return 'Closure';
        }
        return null;
    }

    /**
     * Returns the source filename for a callable, or null if unknown.
     *
     * @param callable $callback
     * @return string|null
     */
    private function callable_file( $callback ) {

        static $cache = array();

        // Build a stable cache key for all callable shapes.
        if ( $callback instanceof \Closure ) {
            $key = 'closure:' . spl_object_id( $callback );
        } elseif ( is_string( $callback ) ) {
            $key = 'string:' . $callback; // "function" or "Class::method".
        } elseif ( is_array( $callback ) ) {
            $key = 'array:' . ( is_object( $callback[0] ) ? get_class( $callback[0] ) . '#' . spl_object_id( $callback[0] ) : $callback[0] ) . '::' . ( $callback[1] ?? '' );
        } elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
            $key = 'invokable:' . get_class( $callback ) . '#' . spl_object_id( $callback );
        } else {
            return null;
        }

        if ( array_key_exists( $key, $cache ) ) {
            return $cache[ $key ];
        }

        try {
            if ( $callback instanceof \Closure ) {
                $ref           = new \ReflectionFunction( $callback );
                $cache[ $key ] = $ref->getFileName();
                $cache[ $key ] = $cache[ $key ] ? $cache[ $key ] : null;
                return $cache[ $key ];
            }

            if ( is_string( $callback ) ) {
                // function or "Class::method".
                if ( strpos( $callback, '::' ) !== false ) {
                    [$class, $method] = explode( '::', $callback, 2 );
                    $ref              = new \ReflectionMethod( $class, $method );
                    $cache[ $key ]    = $ref->getFileName();
                    $cache[ $key ]    = $cache[ $key ] ? $cache[ $key ] : null;
                    return $cache[ $key ];
                } else {
                    // userland function.
                    if ( ! function_exists( $callback ) ) {
                        $cache[ $key ] = null;
                        return $cache[ $key ];
                    }
                    $ref           = new \ReflectionFunction( $callback );
                    $cache[ $key ] = $ref->getFileName();
                    $cache[ $key ] = $cache[ $key ] ? $cache[ $key ] : null;
                    return $cache[ $key ];
                }
            }

            if ( is_array( $callback ) ) {
                [$objOrClass, $method] = array( $callback[0], $callback[1] ?? '' );
                $ref                   = new \ReflectionMethod( $objOrClass, $method );
                $cache[ $key ]         = $ref->getFileName();
                $cache[ $key ]         = $cache[ $key ] ?: null;
                return $cache[ $key ];
            }

            if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
                $ref           = new \ReflectionMethod( $callback, '__invoke' );
                $cache[ $key ] = $ref->getFileName();
                $cache[ $key ] = $cache[ $key ] ? $cache[ $key ] : null;
                return $cache[ $key ];
            }
        } catch ( \Throwable $e ) {
            // swallow and fall through.
        }
        $cache[ $key ] = null;
        return $cache[ $key ];
    }
}
