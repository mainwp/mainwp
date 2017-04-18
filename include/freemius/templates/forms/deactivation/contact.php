<?php
    /**
     * @package     Freemius
     * @copyright   Copyright (c) 2015, Freemius, Inc.
     * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
     * @since       1.2.0
     */

    if ( ! defined( 'ABSPATH' ) ) {
        exit;
    }

	/**
	 * @var array $VARS
	 */
    $slug = $VARS['slug'];
    $fs   = freemius( $slug );

    echo __fs( 'contact-support-before-deactivation', $slug )
            . sprintf(" <a href='%s' class='button button-small button-primary'>%s</a>",
                $fs->contact_url( 'technical_support' ),
                __fs( 'contact-support', $slug )
            );
