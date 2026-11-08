<?php
/**
 * Remote MCP Team Control check.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

use MainWP\Dashboard\MainWP_Abilities_Util;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one "no Team Control limits" check. Hands-off, adding a web app,
 * and seeing or revoking other users' connections all call this, never a test of their own.
 */
final class Remote_MCP_Team_Control {

    /**
     * Rights checked on top of every ability operation right: the per-type update rights the
     * update family derives from its input.
     *
     * @var array
     */
    const UPDATE_RIGHTS = array( 'update_wordpress', 'update_plugins', 'update_themes', 'update_translations' );

    /**
     * Whether a user has `manage_options`, every site, and every MainWP operation right.
     *
     * Team Control answers its filters for the current user, so the check runs with that user as
     * the current user and restores the previous one afterwards. With Team Control inactive both
     * filters return their defaults, so every `manage_options` user passes.
     *
     * @param int $user_id User id.
     * @return bool
     */
    public static function user_has_no_limits( int $user_id ): bool {
        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
            return false;
        }
        $previous = get_current_user_id();
        if ( $previous !== $user_id ) {
            wp_set_current_user( $user_id );
        }
        try {
            if ( 'all' !== MainWP_Abilities_Util::get_allowed_site_ids() ) {
                return false;
            }
            $rights = array_unique( array_merge( array_values( MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS ), self::UPDATE_RIGHTS ) );
            foreach ( $rights as $right ) {
                if ( ! MainWP_Abilities_Util::current_user_has_operation_right( $right ) ) {
                    return false;
                }
            }
            return true;
        } finally {
            if ( $previous !== $user_id ) {
                wp_set_current_user( $previous );
            }
        }
    }
}
