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

jQuery(function($) {
	const MSHOT_REFRESH_DELAY = 6000;
	const MSHOT_REFRESH_QUERY_ARG = 'mainwp_mshot_refresh';

	function mainwp_preview_append_cache_bust(url, token) {
		if (!url) {
			return url;
		}

		let separator = url.indexOf('?') === -1 ? '?' : '&';

		return url + separator + MSHOT_REFRESH_QUERY_ARG + '=' + encodeURIComponent(token + '-' + Date.now());
	}

	function mainwp_preview_get_button_mshot_url(element, requeue) {
		let attrName = requeue ? 'data-mainwp-mshot-requeue-src' : 'data-mainwp-mshot-src';
		let mshotUrl = $(element).attr(attrName);

		if (mshotUrl) {
			return mshotUrl;
		}

		let linkUrl = $(element).attr('preview-site-url');

		if (!linkUrl) {
			return '';
		}

		mshotUrl = '//s0.wp.com/mshots/v1/' + encodeURIComponent(linkUrl) + '?w=900';

		if (requeue) {
			mshotUrl += '&requeue=true';
		}

		return mshotUrl;
	}

	function mainwp_preview_clear_image_recovery(img) {
		if (img && img.mainwpMshotRecoveryTimer) {
			clearTimeout(img.mainwpMshotRecoveryTimer);
			img.mainwpMshotRecoveryTimer = null;
		}
	}

	function mainwp_preview_queue_image_recovery(img) {
		if (!img || img.dataset.mainwpMshotRecoveryRequested === '1') {
			return;
		}

		let primarySrc = img.getAttribute('data-mainwp-mshot-src') || img.getAttribute('data-src') || img.getAttribute('src');
		let requeueSrc = img.getAttribute('data-mainwp-mshot-requeue-src');

		if (!primarySrc || !requeueSrc) {
			return;
		}

		img.dataset.mainwpMshotRecoveryRequested = '1';
		mainwp_preview_clear_image_recovery(img);

		// Ask mShots to regenerate the thumbnail once, then retry the canonical URL.
		img.setAttribute('src', mainwp_preview_append_cache_bust(requeueSrc, 'requeue'));
		img.mainwpMshotRecoveryTimer = setTimeout(function () {
			img.setAttribute('src', mainwp_preview_append_cache_bust(primarySrc, 'reload'));
		}, MSHOT_REFRESH_DELAY);
	}

	if (!window.mainwpPreviewImageRecoveryBound) {
		document.addEventListener('error', function (event) {
			let target = event.target;

			if (target && target.tagName === 'IMG') {
				mainwp_preview_queue_image_recovery(target);
			}
		}, true);

		window.mainwpPreviewImageRecoveryBound = true;
	}

	/**
	 * Init site preview function.
	 */
	window.mainwp_preview_init_event = function () {
		let mshotRemovalTimer = null;
		let mshotSecondTryTimer = null;
		let mshotThirdTryTimer = null;
		let previewTableSelector = '.mainwp-with-preview-table';
		let mshotEnabledLinkSelector = 'td span.mainwp-preview-item';

		// Show a preview image of the hovered URL.
		$(previewTableSelector).off('.mainwpPreview').on('click.mainwpPreview', mshotEnabledLinkSelector, function () {
			clearTimeout(mshotRemovalTimer);

			if ($('.mainwp-preview-mshot').length > 0) {
				if ($('.mainwp-preview-mshot:first').data('link') == this) {
					// The preview is already showing for this link.
					return;
				}
				else {
					// A new link is being hovered, so remove the old preview.
					let existingImage = $('.mainwp-preview-mshot .mshot-image').get(0);
					mainwp_preview_clear_image_recovery(existingImage);
					$('.mainwp-preview-mshot').remove();
				}
			}

			clearTimeout(mshotSecondTryTimer);
			clearTimeout(mshotThirdTryTimer);

			let primarySrc = mainwp_preview_get_button_mshot_url(this, false);
			let requeueSrc = mainwp_preview_get_button_mshot_url(this, true);
			let $image = $('<img />', {
				src: primarySrc,
				width: 450,
				height: 338,
				class: 'mshot-image'
			});
			let mShot = $('<div class="mainwp-preview-mshot mshot-container"><div class="mshot-arrow"></div></div>');

			$image.attr('data-mainwp-mshot-src', primarySrc);
			$image.attr('data-mainwp-mshot-requeue-src', requeueSrc);
			mShot.append($image);
			mShot.data('link', this);

			let offset = $(this).offset();

			mShot.offset({
				left: Math.min($(window).width() - 475, offset.left + $(this).width() + 10), // Keep it on the screen if the link is near the edge of the window.
				top: offset.top + ($(this).height() / 2) - 101 // 101 = top offset of the arrow plus the top border thickness
			});

			// These retries appear to be superfluous if .mshot-image has already loaded, but it's because mShots
			// can return a "Generating thumbnail..." image if it doesn't have a thumbnail ready, so we need
			// to retry to see if we can get the newly generated thumbnail.
			mshotSecondTryTimer = setTimeout(function () {
				mShot.find('.mshot-image').attr('src', mainwp_preview_append_cache_bust(primarySrc, 'retry-2'));
			}, MSHOT_REFRESH_DELAY);

			mshotThirdTryTimer = setTimeout(function () {
				mShot.find('.mshot-image').attr('src', mainwp_preview_append_cache_bust(primarySrc, 'retry-3'));
			}, MSHOT_REFRESH_DELAY * 2);

			$('body').append(mShot);
		}).on('mouseover.mainwpPreview', 'tr', function () {
			// When the mouse hovers over a row, begin preloading mshots for links.
			let linksToPreloadMshotsFor = $(this).find(mshotEnabledLinkSelector);

			linksToPreloadMshotsFor.each(function () {
				// Don't attempt to preload an mshot for a single link twice. Browser caching should cover this, but in case of
				// race conditions, save a flag locally when we've begun trying to preload one.
				if (!$(this).data('mainwp-preview-mshot-preloaded')) {
					mainwp_preview_preload_mshot(this);
					$(this).data('mainwp-preview-mshot-preloaded', true);
				}
			});
		});

		$(document).off('mouseup.mainwpPreview').on('mouseup.mainwpPreview', function () {
			if ($('.mainwp-preview-mshot').length > 0) {
				mshotRemovalTimer = setTimeout(function () {
					clearTimeout(mshotSecondTryTimer);
					clearTimeout(mshotThirdTryTimer);
					let previewImage = $('.mainwp-preview-mshot .mshot-image').get(0);

					mainwp_preview_clear_image_recovery(previewImage);
					$('.mainwp-preview-mshot').remove();
				}, 100);
			}
		});
	};

	// init preview.
	mainwp_preview_init_event();

	/**
	 * Begin loading an mShot preview of a link.
	 *
	 * @param object linkElement Preview trigger element.
	 */
	function mainwp_preview_preload_mshot(linkElement) {
		let img = new Image();
		let primarySrc = mainwp_preview_get_button_mshot_url(linkElement, false);

		if (primarySrc) {
			img.src = primarySrc;
		}
	}
});
