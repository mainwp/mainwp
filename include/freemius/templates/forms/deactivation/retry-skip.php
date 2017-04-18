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

    $skip_url  = fs_nonce_url( $fs->_get_admin_page_url( '', array( 'fs_action' => $slug . '_skip_activation' ) ), $slug . '_skip_activation' );
    $skip_text = strtolower( __fs( 'skip', $slug ) );
    $use_plugin_anonymously_text = __fs( 'click-here-to-use-plugin-anonymously', $slug );

    echo sprintf( __fs( 'dont-have-to-share-any-data', $slug ), "<a href='{$skip_url}'>{$skip_text}</a>" )
            . " <a href='{$skip_url}' class='button button-small button-secondary'>{$use_plugin_anonymously_text}</a>";
