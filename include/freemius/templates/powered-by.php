<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.0.5
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	$VARS = isset($VARS) ? $VARS : array();

	wp_enqueue_script( 'jquery' );
	wp_enqueue_script( 'json2' );
	fs_enqueue_local_script( 'postmessage', 'nojquery.ba-postmessage.min.js' );
	fs_enqueue_local_script( 'fs-postmessage', 'postmessage.js' );
?>

<div id="piframe"></div>
<script type="text/javascript">
	(function ($) {
		$(function () {
			var
				base_url = '<?php echo WP_FS__ADDRESS ?>',
				piframe = $('<iframe id="fs_promo_tab" src="' + base_url + '/promotional-tab/?<?php echo http_build_query($VARS) ?>#' + encodeURIComponent(document.location.href) + '" height="350" width="60" frameborder="0" style="  background: transparent; position: fixed; top: 20%; right: 0;" scrolling="no"></iframe>')
					.appendTo('#piframe');

			FS.PostMessage.init(base_url);
			FS.PostMessage.receive('state', function (state) {
				if ('closed' === state)
					$('#fs_promo_tab').css('width', '60px');
				else
					$('#fs_promo_tab').css('width', '345px');
			});
		});
	})(jQuery);
</script>