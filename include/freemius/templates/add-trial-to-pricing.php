<?php
	/**
	 * Add "&trial=true" to pricing menu item href when running in trial
	 * promotion context.
	 *
	 * @package     Freemius
	 * @copyright   Copyright (c) 2016, Freemius, Inc.
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
?>
<script type="text/javascript">
	(function ($) {
		$(document).ready(function () {
			var $pricingMenu = $('.fs-submenu-item.<?php echo $slug ?>.pricing'),
				$pricingMenuLink = $pricingMenu.parents('a');

			// Add trial querystring param.
			$pricingMenuLink.attr('href', $pricingMenuLink.attr('href') + '&trial=true');
		});
	})(jQuery);
</script>