<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.2.1.5
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * @var array $VARS
	 */
	$slug = $VARS['slug'];
	$fs   = freemius( $slug );

	$action = $fs->is_tracking_allowed() ?
		'stop_tracking' :
		'allow_tracking';

	$plugin_title                     = "<strong>{$fs->get_plugin()->title}</strong>";
	$opt_out_button_text              = __fs( 'opt-out', $slug );
	// @todo Change 'plugin' with module type when migrating with 1.2.2 (themes version).
    $opt_out_message_appreciation     = sprintf( __fs( 'opt-out-message-appreciation', $slug ), 'plugin' );
    $opt_out_message_usage_tracking   = sprintf( __fs( 'opt-out-message-usage-tracking', $slug ), $plugin_title );
    $opt_out_message_clicking_opt_out = sprintf(
    	__fs( 'opt-out-message-clicking-opt-out', $slug ),
	    $plugin_title,
	    sprintf(
		    '<a href="%s" target="_blank">%s</a>',
		    'https://freemius.com',
		    'freemius.com'
	    )
    );

	$admin_notice_params = array(
		'id'      => '',
		'slug'    => $fs->get_id(),
		'type'    => 'success',
		'sticky'  => false,
		'plugin'  => $fs->get_plugin()->title,
		'message' => $opt_out_message_appreciation
	);

	$admin_notice_html = fs_get_template( 'admin-notice.php', $admin_notice_params );

	$modal_content_html = <<< HTML
		<h2>{$opt_out_message_appreciation}</h2>
		<div class="notice notice-error inline opt-out-error-message"><p></p></div>
		<p>{$opt_out_message_usage_tracking}</p>
		<p>{$opt_out_message_clicking_opt_out}</p>
HTML;

	fs_enqueue_local_style( 'dialog-boxes', '/admin/dialog-boxes.css' );
	fs_enqueue_local_style( 'fs_common', '/admin/common.css' );
?>
<script type="text/javascript">
(function( $ ) {
	$( document ).ready(function() {
		var modalContentHtml = <?php echo json_encode( $modal_content_html ) ?>,
			modalHtml =
				'<div class="fs-modal fs-modal-opt-out">'
				+ '	<div class="fs-modal-dialog">'
				+ '		<div class="fs-modal-header">'
				+ '		    <h4><?php echo esc_js($opt_out_button_text) ?></h4>'
				+ '		</div>'
				+ '		<div class="fs-modal-body">'
				+ '			<div class="fs-modal-panel active">' + modalContentHtml + '</div>'
				+ '		</div>'
				+ '		<div class="fs-modal-footer">'
				+ '			<button class="button button-secondary button-opt-out" tabindex="1"><?php echo esc_js($opt_out_button_text) ?></button>'
				+ '			<button class="button button-primary button-close" tabindex="2"><?php echo esc_js( __fs( 'opt-out-cancel', $slug ) ) ?></button>'
				+ '		</div>'
				+ '	</div>'
				+ '</div>',
			$modal               = $( modalHtml ),
			$adminNotice         = $( <?php echo json_encode( $admin_notice_html ) ?> ),
			action               = '<?php echo $action ?>',
			optOutActionTag      = '<?php echo $fs->get_action_tag( 'stop_tracking' ) ?>',
			optInActionTag       = '<?php echo $fs->get_action_tag( 'allow_tracking' ) ?>',
			$actionLink          = $( 'span.opt-in-or-opt-out.<?php echo $VARS['slug'] ?> a' ),
			$optOutButton        = $modal.find( '.button-opt-out' ),
			$optOutErrorMessage  = $modal.find( '.opt-out-error-message' ),
			pluginSlug           = '<?php echo $slug ?>';

		$actionLink.attr( 'data-action', action );
		$modal.appendTo( $( 'body' ) );

		function registerEventHandlers() {
			$actionLink.click(function( evt ) {
				evt.preventDefault();

				if ( 'stop_tracking' == $actionLink.attr( 'data-action' ) ) {
					showModal();
				} else {
					optIn();
				}
			});

			$modal.on( 'click', '.button-opt-out', function( evt ) {
				evt.preventDefault();

				if ( $( this ).hasClass( 'disabled' ) ) {
					return;
				}

				disableOptOutButton();
				optOut();
			});

			// If the user has clicked outside the window, close the modal.
			$modal.on( 'click', '.fs-close, .button-close', function() {
				closeModal();
				return false;
			});
		}

		registerEventHandlers();

		function showModal() {
			resetModal();

			// Display the dialog box.
			$modal.addClass( 'active' );
			$( 'body' ).addClass( 'has-fs-modal' );
		}

		function closeModal() {
			$modal.removeClass( 'active' );
			$( 'body' ).removeClass( 'has-fs-modal' );
		}

		function resetOptOutButton() {
			enableOptOutButton();
			$optOutButton.text( <?php echo json_encode($opt_out_button_text) ?> );
		}

		function resetModal() {
			hideError();
			resetOptOutButton();
		}

		function optIn() {
			sendRequest();
		}

		function optOut() {
			sendRequest();
		}

		function sendRequest() {
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: ( 'stop_tracking' == action ? optOutActionTag : optInActionTag ),
					slug  : pluginSlug
				},
				beforeSend: function() {
					if ( 'opt-in' == action ) {
						$actionLink.text( <?php fs_json_encode_echo( 'opting-in', $slug ) ?> )
					} else {
						$optOutButton.text( <?php fs_json_encode_echo( 'opting-out', $slug ) ?> );
					}
				},
				success: function( resultObj ) {
					if ( resultObj.success ) {
						if ( 'allow_tracking' == action ) {
							action = 'stop_tracking';
							$actionLink.text( <?php fs_json_encode_echo( 'opt-out', $slug ) ?> );
							showOptInAppreciationMessageAndScrollToTop();
						} else {
							action = 'allow_tracking';
							$actionLink.text( <?php fs_json_encode_echo( 'opt-in', $slug ) ?> );
							closeModal();

							if ( $adminNotice.length > 0 ) {
								$adminNotice.remove();
							}
						}

						$actionLink.attr( 'data-action', action );
					} else {
						showError( resultObj.error );
						resetOptOutButton();
					}
				}
			});
		}

		function enableOptOutButton() {
			$optOutButton.removeClass( 'disabled' );
		}

		function disableOptOutButton() {
			$optOutButton.addClass( 'disabled' );
		}

		function hideError() {
			$optOutErrorMessage.hide();
		}

		function showOptInAppreciationMessageAndScrollToTop() {
			$adminNotice.insertAfter( $( '#wpbody-content' ).find( ' > .wrap > h1' ) );
			window.scrollTo(0, 0);
		}

		function showError( msg ) {
			$optOutErrorMessage.find( ' > p' ).html( msg );
			$optOutErrorMessage.show();
		}
	});
})( jQuery );
</script>
