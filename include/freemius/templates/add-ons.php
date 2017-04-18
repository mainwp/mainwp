<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.0.3
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * @var array $VARS
	 */
	$slug = $VARS['slug'];
	/**
	 * @var Freemius
	 */
	$fs = freemius( $slug );

	$open_addon_slug = fs_request_get( 'slug' );

	$open_addon = false;

	/**
	 * @var FS_Plugin[]
	 */
	$addons = $fs->get_addons();

	$has_addons = ( is_array( $addons ) && 0 < count( $addons ) );
?>
	<div id="fs_addons" class="wrap">
		<h2><?php printf( __fs( 'add-ons-for-x', $slug ), $fs->get_plugin_name() ) ?></h2>

		<div id="poststuff">
			<?php if ( ! $has_addons ) : ?>
				<h3><?php printf(
						'%s... %s',
						__fs( 'oops', $slug ),
						__fs( 'add-ons-missing', $slug )
					) ?></h3>
			<?php endif ?>
			<ul class="fs-cards-list">
				<?php if ( $has_addons ) : ?>
					<?php foreach ( $addons as $addon ) : ?>
						<?php
						$open_addon = ( $open_addon || ( $open_addon_slug === $addon->slug ) );

						$price        = 0;
						$plan         = null;
						$plans_result = $fs->get_api_site_or_plugin_scope()->get( "/addons/{$addon->id}/plans.json" );
						if ( ! isset( $plans_result->error ) ) {
							$plans = $plans_result->plans;
							if ( is_array( $plans ) && 0 < count( $plans ) ) {
								$plan           = new FS_Plugin_Plan( $plans[0] );
								$pricing_result = $fs->get_api_site_or_plugin_scope()->get( "/addons/{$addon->id}/plans/{$plan->id}/pricing.json" );
								if ( ! isset( $pricing_result->error ) ) {
									// Update plan's pricing.
									$plan->pricing = $pricing_result->pricing;

									if ( is_array( $plan->pricing ) && 0 < count( $plan->pricing ) ) {
										$min_price = 999999;
										foreach ( $plan->pricing as $pricing ) {
											if ( ! is_null( $pricing->annual_price ) && $pricing->annual_price > 0 ) {
												$min_price = min( $min_price, $pricing->annual_price );
											} else if ( ! is_null( $pricing->monthly_price ) && $pricing->monthly_price > 0 ) {
												$min_price = min( $min_price, 12 * $pricing->monthly_price );
											}
										}

										if ( $min_price < 999999 ) {
											$price = $min_price;
										}
									}
								}
							}
						}
						?>
						<li class="fs-card fs-addon" data-slug="<?php echo $addon->slug ?>">
							<?php
								echo sprintf( '<a href="%s" class="thickbox fs-overlay" aria-label="%s" data-title="%s"></a>',
									esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&parent_plugin_id=' . $fs->get_id() . '&plugin=' . $addon->slug .
									                            '&TB_iframe=true&width=600&height=550' ) ),
									esc_attr( sprintf( __fs( 'more-information-about-x', $slug ), $addon->title ) ),
									esc_attr( $addon->title )
								);
							?>
							<?php
								if ( is_null( $addon->info ) ) {
									$addon->info = new stdClass();
								}
								if ( ! isset( $addon->info->card_banner_url ) ) {
									$addon->info->card_banner_url = '//dashboard.freemius.com/assets/img/marketing/blueprint-300x100.jpg';
								}
								if ( ! isset( $addon->info->short_description ) ) {
									$addon->info->short_description = 'What\'s the one thing your add-on does really, really well?';
								}
							?>
							<div class="fs-inner">
								<ul>
									<li class="fs-card-banner"
									    style="background-image: url('<?php echo $addon->info->card_banner_url ?>');"></li>
<!--									<li class="fs-tag"></li>-->
									<li class="fs-title"><?php echo $addon->title ?></li>
									<li class="fs-offer">
									<span
										class="fs-price"><?php echo ( 0 == $price ) ? __fs( 'free', $slug ) : ('$' . number_format( $price, 2 ) . ($plan->has_trial() ? ' - ' . __fs('trial', $slug) : '')) ?></span>
									</li>
									<li class="fs-description"><?php echo ! empty( $addon->info->short_description ) ? $addon->info->short_description : 'SHORT DESCRIPTION' ?></li>
									<li class="fs-cta"><a class="button"><?php _efs( 'view-details', $slug ) ?></a></li>
								</ul>
							</div>
						</li>
					<?php endforeach ?>
				<?php endif ?>
			</ul>
		</div>
	</div>
	<script type="text/javascript">
		(function ($) {
			<?php if ( $open_addon ) : ?>

			var interval = setInterval(function () {
				// Open add-on information page.
				<?php
				/**
				 * @author Vova Feldman
				 *
				 * This code does NOT expose an XSS vulnerability because:
				 *  1. This page only renders for admins, so if an attacker manage to get
				 *     admin access, they can do more harm.
				 *  2. This code won't be rendered unless $open_addon_slug matches any of
				 *     the plugin's add-ons slugs.
				 */
				?>
				$('.fs-card[data-slug=<?php echo $open_addon_slug ?>] a').click();
				if ($('#TB_iframeContent').length > 0) {
					clearInterval(interval);
					interval = null;
				}
			}, 200);

			<?php else : ?>


			$('.fs-card.fs-addon')
				.mouseover(function () {
					$(this).find('.fs-cta .button').addClass('button-primary');
				}).mouseout(function () {
					$(this).find('.fs-cta .button').removeClass('button-primary');
				});

			<?php endif ?>
		})(jQuery);
	</script>
<?php
	$params = array(
		'page'           => 'addons',
		'module_id'      => $fs->get_id(),
		'module_slug'    => $slug,
		'module_version' => $fs->get_plugin_version(),
	);
	fs_require_template( 'powered-by.php', $params );
?>