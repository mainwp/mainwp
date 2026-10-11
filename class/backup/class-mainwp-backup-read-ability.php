<?php
/**
 * Exact identities must survive WordPress input validation and REST coercion.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

/** WordPress schemas permit numeric coercion that can change an id's meaning. */
class MainWP_Backup_Read_Ability extends \WP_Ability {
    /**
     * The REST controller consults this check before coercing schema-valid input.
     *
     * @param mixed $input Raw input.
     * @return true|\WP_Error
     */
    public function validate_input( $input = null ) {
        $valid = MainWP_Abilities_Backups::validate_id_input( $input );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        $valid = parent::validate_input( $input );
        if ( is_wp_error( $valid ) ) {
            // Older WordPress versions leave schema refusals without an HTTP status.
            $valid->add_data( array( 'status' => 400 ) );
        }
        return $valid;
    }
}
