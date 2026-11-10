<?php
/**
 * Module Compact connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_User
 */
class Connector_Compact extends Log_Connector {
        /**
         * Connector slug
         *
         * @var string
         */
        public $name = 'compact';


        /**
         * Return translated connector label
         *
         * @return string Translated connector label
         */
    public function get_label() {
        return esc_html__( 'Compact', 'mainwp' );
    }

        /**
         * Return translated action term labels
         *
         * @return array Action terms label translation
         */
    public function get_action_labels() {
        return array(
            'saved' => esc_html__( 'Saved', 'mainwp' ),
        );
    }

        /**
         * Return translated context labels
         *
         * @return array Context label translations
         */
    public function get_context_labels() {
        return array(
            'compact' => esc_html__( 'Compact', 'mainwp' ),
        );
    }
}
