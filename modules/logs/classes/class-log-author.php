<?php
/**
 * Module Logs Author class.
 *
 * @package MainWP\Dashboard\Module\Log
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

use MainWP\Dashboard\MainWP_Exception;

/**
 * Class Log_Author.
 *
 * @package MainWP\Dashboard.
 */
class Log_Author {

    /**
     * Holds User ID.
     *
     * @var int
     */
    public $id;

    /**
     * Holds User meta data.
     *
     * @var array
     */
    public $meta = array();

    /**
     * Holds WP user object connected to "$this" instance.
     *
     * @var \WP_User
     */
    protected $user;

    /**
     * Class constructor.
     *
     * @param int   $user_id   The user ID.
     * @param array $user_meta The user meta array.
     */
    public function __construct( $user_id, $user_meta = array() ) {

        if ( ! is_array( $user_meta ) ) {
            $user_meta = array();
        }

        $this->id   = absint( $user_id );
        $this->meta = $user_meta;

        if ( $this->id && ( ! isset( $user_meta['wp_user_id'] ) && ! isset( $user_meta['user_id'] ) ) ) {
            $this->user = new \WP_User( $this->id ); // if is not child user id, load the dashboard user.
        }
    }

    /**
     * Get various user meta data
     *
     * @devtodo Make sure this is being covered in the unit tests.
     *
     * @param string $name User meta key.
     *
     * @throws \MainWP_Exception Meta not found | User not found.
     *
     * @return string
     */
    public function __get( $name ) {
        switch ( $name ) {
            case 'display_name':
            case 'avatar_img':
            case 'avatar_src':
            case 'role':
            case 'agent':
                $getter = "get_{$name}";
                return $this->$getter();
            default:
                if ( ! empty( $this->user ) && 0 !== $this->user->ID ) {
                    if ( is_null( $this->user->$name ) ) {
                        throw new MainWP_Exception( "Unrecognized magic '$name'" ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }
                    return $this->user->$name;
                }

                throw new MainWP_Exception( 'User not found.' );
        }
    }

    /**
     * Returns string representation of this object
     *
     * @return string
     */
    public function __toString() {
        return $this->get_display_name();
    }

    /**
     * Get the display name of the user
     *
     * @return string
     */
    public function get_display_name() { //phpcs:ignore -- NOSONAR - complex.

        // child users.
        if ( isset( $this->meta['wp_user_id'] ) || isset( $this->meta['user_id'] ) ) { // 'user_id' to compare with old child wp.
            if ( ! empty( $this->meta['action_user'] ) ) {
                return esc_html( $this->meta['action_user'] );
            } elseif ( ! empty( $this->meta['system_user_name'] ) ) {
                return esc_html( $this->meta['system_user_name'] );
            }
        } elseif ( empty( $this->id ) ) { // dashboard user.
            if ( isset( $this->meta['system_user_name'] ) ) {
                return esc_html( $this->meta['system_user_name'] );
            } elseif ( 'wp_cli' === $this->get_current_agent() ) {
                return 'WP-CLI'; // No translation needed.
            }
        } elseif ( $this->is_deleted() ) {  // dashboard user.
            if ( ! empty( $this->meta['display_name'] ) ) {
                return $this->meta['display_name'];
            } elseif ( ! empty( $this->meta['user_login'] ) ) {
                return $this->meta['user_login'];
            }
        } elseif ( ! empty( $this->user ) ) {  // dashboard user.
            if ( ! empty( $this->user->display_name ) ) {
                return $this->user->display_name;
            } elseif ( ! empty( $this->user_login ) ) {
                return $this->user->user_login;
            }
        }
        return '';
    }

    /**
     * Get the agent of the user
     *
     * @return string
     */
    public function get_agent() {
        $agent = '';

        if ( ! empty( $this->meta['agent'] ) ) {
            $agent = $this->meta['agent'];
        }

        return $agent;
    }

    /**
     * Tries to find a label for the record's user_role.
     *
     * If the user_role exists, use the label associated with it.
     *
     * Otherwise, if there is a user role label stored as Log meta then use that.
     * Otherwise, if the user exists, use the label associated with their current role.
     * Otherwise, use the role slug as the label.
     *
     * @return string
     */
    public function get_role() {
        global $wp_roles;

        $user_role = '';

        if ( ! empty( $this->meta['user_role'] ) && isset( $wp_roles->role_names[ $this->meta['user_role'] ] ) ) {
            $user_role = $wp_roles->role_names[ $this->meta['user_role'] ];
        } elseif ( ! empty( $this->meta['user_role_label'] ) ) {
            $user_role = $this->meta['user_role_label'];
        } elseif ( ! empty( $this->user->roles ) ) {
            $roles = array_map(
                function ( $role ) use ( $wp_roles ) {
                    return $wp_roles->role_names[ $role ];
                },
                $this->user->roles
            );

            $separator = apply_filters( 'mainwp_module_log_get_role_list_separator', ' - ' );
            $user_role = implode( $separator, $roles );
        } elseif ( is_multisite() && is_super_admin( $this->id ) ) {
            $user_role = $wp_roles->role_names['administrator'];
        }

        return $user_role;
    }

    /**
     * True if user no longer exists, otherwise false
     *
     * @return bool
     */
    public function is_deleted() {
        return 0 !== $this->id && ( empty( $this->user ) || empty( $this->user->ID ) );
    }

    /**
     * True if user is WP-CLI, otherwise false
     *
     * @return bool
     */
    public function is_wp_cli() {
        return 'wp_cli' === $this->get_agent();
    }

    /**
     * Check if the current request is part of a WP cron task.
     *
     * Note: This will return true for all manual or custom
     * cron runs even if the default front-end cron is disabled.
     *
     * We're not using `wp_doing_cron()` since it was introduced
     * only in WordPress 4.8.0.
     *
     * @return bool
     */
    public function is_doing_wp_cron() {
        return defined( 'DOING_CRON' ) && DOING_CRON;
    }

    /**
     * Look at the environment to detect if an agent is being used
     *
     * @return string
     */
    public function get_current_agent() {
        $agent = '';

        if ( defined( '\WP_CLI' ) && \WP_CLI ) {
            $agent = 'wp_cli';
        } elseif ( $this->is_doing_wp_cron() ) {
            $agent = 'wp_cron';
        } elseif ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) {
            $agent = 'wp_rest_api';
        }

        /**
         * Filter the current agent string
         *
         * @return string
         */
        $agent = apply_filters( 'mainwp_module_log_current_agent', $agent );

        return $agent;
    }

    /**
     * Get the agent label
     *
     * @param string $agent Key representing agent.
     *
     * @return string
     */
    public function get_agent_label( $agent ) {
        if ( 'wp_cli' === $agent ) {
            $label = esc_html__( 'via WP-CLI', 'mainwp' );
        } elseif ( 'wp_cron' === $agent ) {
            $label = esc_html__( 'during WP Cron', 'mainwp' );
        } elseif ( 'wp_rest_api' === $agent ) {
            $label = esc_html__( 'during WP REST API', 'mainwp' );
        } else {
            $label = '';
        }

        /**
         * Filter agent labels
         *
         * @param string $agent Key representing agent.
         *
         * @return string
         */
        $label = apply_filters( 'mainwp_module_log_agent_label', $label, $agent );

        return $label;
    }
}
