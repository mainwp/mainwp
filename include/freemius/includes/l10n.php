<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.2.1.6
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Retrieve the translation of $text.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 * 
	 * @return string
	 */
	function _fs_text( $text ) {
		return translate( $text, 'freemius' );
	}

	/**
	 * Retrieve the translation of $text and escapes it for safe use in an attribute.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 * 
	 * @return string
	 */
	function _fs_esc_attr( $text ) {
		return esc_attr( translate( $text, 'freemius' ) );
	}

	/**
	 * Retrieve the translation of $text and escapes it for safe use in HTML output.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 * 
	 * @return string
	 */
	function _fs_esc_html( $text ) {
		return esc_html( translate( $text, 'freemius' ) );
	}

	/**
	 * Display translated text.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text
	 */
	function _fs_echo( $text ) {
		echo translate( $text, 'freemius' );
	}

	/**
	 * Display translated text that has been escaped for safe use in an attribute.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 */
	function _fs_esc_attr_echo( $text ) {
		echo esc_attr( translate( $text, 'freemius' ) );
	}

	/**
	 * Display translated text that has been escaped for safe use in HTML output.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 */
	function _fs_esc_html_echo( $text ) {
		echo esc_html( translate( $text, 'freemius' ) );
	}

	/**
	 * Retrieve translated string with gettext context.
	 *
	 * Quite a few times, there will be collisions with similar translatable text
	 * found in more than two places, but with different translated context.
	 *
	 * By including the context in the pot file, translators can translate the two
	 * strings differently.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 * @param string $context 
	 * 
	 * @return string
	 */
	function _fs_x( $text, $context ) {
		return translate_with_gettext_context( $text, $context, 'freemius' );
	}

	/**
	 * Display translated string with gettext context.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 * @param string $context
	 */
	function _fs_ex( $text, $context ) {
		// Avoid misleading Theme Check warning.
		$fn = '_x';
		echo $fn( $text, $context, 'freemius' );
	}

	/**
	 * Translate string with gettext context, and escapes it for safe use in an attribute.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $text
	 * @param string $context
	 *
	 * @return string
	 */
	function _fs_esc_attr_x( $text, $context ) {
		return esc_attr( translate_with_gettext_context( $text, $context, 'freemius' ) );
	}

	/**
	 * Translate string with gettext context, and escapes it for safe use in HTML output.
	 *
	 * @since 2.9.0
	 *
	 * @param string $text
	 * @param string $context
	 * 
	 * @return string
	 */
	function _fs_esc_html_x( $text, $context ) {
		return esc_html( translate_with_gettext_context( $text, $context, 'freemius' ) );
	}

	/**
	 * Translates and retrieves the singular or plural form based on the supplied number.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $single
	 * @param string $plural
	 * @param int    $number
	 * 
	 * @return string
	 */
	function _fs_n( $single, $plural, $number ) {
		$translations = get_translations_for_domain( 'freemius' );
		$translation  = $translations->translate_plural( $single, $plural, $number );

		/**
		 * Filters the singular or plural form of a string.
		 *
		 * @since WP 2.2.0
		 *
		 * @param string $translation
		 * @param string $single
		 * @param string $plural
		 * @param string $number
		 * @param string $domain
		 */
		return apply_filters( 'ngettext', $translation, $single, $plural, $number, 'freemius' );
	}

	/**
	 * Translates and retrieves the singular or plural form based on the supplied number, with gettext context.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $single
	 * @param string $plural
	 * @param int    $number
	 * @param string $context
	 * 
	 * @return string
	 */
	function _fs_nx($single, $plural, $number, $context ) {
		$translations = get_translations_for_domain( 'freemius' );
		$translation  = $translations->translate_plural( $single, $plural, $number, $context );

		/**
		 * Filters the singular or plural form of a string with gettext context.
		 *
		 * @since WP 3.0
		 *
		 * @param string $translation
		 * @param string $single
		 * @param string $plural
		 * @param string $number
		 * @param string $context
		 * @param string $domain
		 */
		return apply_filters( 'ngettext_with_context', $translation, $single, $plural, $number, $context, 'freemius' );
	}

	/**
	 * Registers plural strings in POT file, but does not translate them.
	 *
	 * Used when you want to keep structures with translatable plural
	 * strings and use them later when the number is known.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $singular
	 * @param string $plural
	 * 
	 * @return array
	 */
	function _fs_n_noop( $singular, $plural ) {
		return array(
			'singular' => $singular,
			'plural'   => $plural,
			'context'  => null,
			'domain'   => 'freemius'
		);
	}

	/**
	 * Registers plural strings with gettext context in POT file, but does not translate them.
	 *
	 * Used when you want to keep structures with translatable plural
	 * strings and use them later when the number is known.
	 *
	 * @since 1.2.1.6
	 *
	 * @param string $singular
	 * @param string $plural
	 * @param string $context
	 * 
	 * @return array
	 */
	function _fs_nx_noop( $singular, $plural, $context ) {
		return array(
			'singular' => $singular,
			'plural'   => $plural,
			'context'  => $context,
			'domain'   => 'freemius'
		);
	}
