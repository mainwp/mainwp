/**
 *
 * Credits
 *
 * Plugin-Name: Akismet Anti-Spam
 * Plugin-URI: https://akismet.com/
 * Author: Automattic
 * Author URI: https://automattic.com/wordpress-plugins/
 * License: GPLv2 or later
 * Text Domain: akismet
 *
 */

 jQuery(document).ready(function ($) {

	/**
	 * Init site preview function.
	 */
	mainwp_preview_init_event = function() {
		var mshotRemovalTimer = null;
		var mshotSecondTryTimer = null
		var mshotThirdTryTimer = null

		var mshotEnabledLinkSelector = 'td span.mainwp-preview-item';

		// Show a preview image of the hovered URL.
		$( '.mainwp-with-preview-table' ).on( 'click', mshotEnabledLinkSelector, function () {
			clearTimeout( mshotRemovalTimer );

			if ( $( '.mainwp-preview-mshot' ).length > 0 ) {
				if ( $( '.mainwp-preview-mshot:first' ).data( 'link' ) == this ) {
					// The preview is already showing for this link.
					return;
				}
				else {
					// A new link is being hovered, so remove the old preview.
					$( '.mainwp-preview-mshot' ).remove();
				}
			}

			clearTimeout( mshotSecondTryTimer );
			clearTimeout( mshotThirdTryTimer );

			var thisHref = $( this ).attr( 'preview-site-url' );

			var mShot = $( '<div class="mainwp-preview-mshot mshot-container"><div class="mshot-arrow"></div><img src="' + mainwp_preview_mshot_url( thisHref ) + '" width="450" height="338" class="mshot-image" /></div>' );
			mShot.data( 'link', this );

			var offset = $( this ).offset();

			mShot.offset( {
				left : Math.min( $( window ).width() - 475, offset.left + $( this ).width() + 10 ), // Keep it on the screen if the link is near the edge of the window.
				top: offset.top + ( $( this ).height() / 2 ) - 101 // 101 = top offset of the arrow plus the top border thickness
			} );

			// These retries appear to be superfluous if .mshot-image has already loaded, but it's because mShots
			// can return a "Generating thumbnail..." image if it doesn't have a thumbnail ready, so we need
			// to retry to see if we can get the newly generated thumbnail.
			mshotSecondTryTimer = setTimeout( function () {
				mShot.find( '.mshot-image' ).attr( 'src', mainwp_preview_mshot_url( thisHref, 2 ) );
			}, 6000 );

			mshotThirdTryTimer = setTimeout( function () {
				mShot.find( '.mshot-image' ).attr( 'src', mainwp_preview_mshot_url( thisHref, 3 ) );
			}, 12000 );

			$( 'body' ).append( mShot );
		} ).on( 'mouseover', 'tr', function () {
			// When the mouse hovers over a row, begin preloading mshots for links.
			var linksToPreloadMshotsFor = $( this ).find( mshotEnabledLinkSelector );

			linksToPreloadMshotsFor.each( function () {
				// Don't attempt to preload an mshot for a single link twice. Browser caching should cover this, but in case of
				// race conditions, save a flag locally when we've begun trying to preload one.
				if ( ! $( this ).data( 'mainwp-preview-mshot-preloaded' ) ) {
					mainwp_preview_preload_mshot( $( this ).attr( 'preview-site-url' ) );
					$( this ).data( 'mainwp-preview-mshot-preloaded', true );
				}
			} );
		} );

    $( document ).on( 'mouseup', function () {
      if ( $( '.mainwp-preview-mshot' ).length > 0 ) {
        mshotRemovalTimer = setTimeout( function () {
           clearTimeout( mshotSecondTryTimer );
           clearTimeout( mshotThirdTryTimer );
           $( '.mainwp-preview-mshot' ).remove();
        }, 100 );
     }
    } );
	};

	// init preview.
	mainwp_preview_init_event();

	/**
	 * Generate an mShot URL if given a link URL.
	 *
	 * @param string linkUrl
	 * @param int retry If retrying a request, the number of the retry.
	 * @return string The mShot URL;
	 */
	function mainwp_preview_mshot_url( linkUrl, retry ) {
		var mshotUrl = '//s0.wordpress.com/mshots/v1/' + encodeURIComponent( linkUrl ) + '?w=900';

		if ( retry ) {
			mshotUrl += '&r=' + encodeURIComponent( retry );
		}

		return mshotUrl;
	}

	/**
	 * Begin loading an mShot preview of a link.
	 *
	 * @param string linkUrl
	 */
	function mainwp_preview_preload_mshot( linkUrl ) {
		var img = new Image();
		img.src = mainwp_preview_mshot_url( linkUrl );
	}
});
