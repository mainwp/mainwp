<?php
/**
 * Email template for the Site Health Notification.
 *
 * To overwrite this template, make a new template with the same filename and place it in the ../wp-content/uploads/mainwp/templates/email/ directory.
 *
 * @package     MainWP/Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
 */

defined( 'ABSPATH' ) || exit;

$child_site_tokens = false;

if ( empty( $heading ) ) {
	$heading = 'Site Health Monitoring';
}

?>

<!DOCTYPE html>
<html>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<title><?php echo esc_html( get_bloginfo( 'name', 'display' ) ); ?></title>
	</head>
	<body marginwidth="0" topmargin="0" marginheight="0" offset="0" style="background-color:#f7f7f7;font-family:'Lato',sans-serif;">
		<div id="mainwp-email-wrapper" style="padding: 30px 0;">
			<?php
			/**
			 * Site Health Monitoring Email Header
			 *
			 * Fires at the top of the site health monitoring email template.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_site_health_monitoring_email_header' );
			?>
			<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" style="margin-top:30px;margin-bottom:30px;">
				<tr>
					<td align="center" valign="top">
						<table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color:#ffffff;border:1px solid #dedede;box-shadow: 0 1px 4px rgba(0,0,0,0.1);border-radius:3px;padding-bottom:30px;">
							<!-- Header -->
							<tr>
								<td align="center" valign="top">
									<table border="0" cellpadding="0" cellspacing="0" width="600">
										<tr>
											<td id="header_wrapper" style="padding: 36px 48px; display: block; background: #1c1d1b;">
												<h1 style="text-align:center;color:#fff;"><?php echo esc_html( $heading ); ?></h1>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<!-- End Header -->
							<!-- Body -->
							<?php
							if ( ! empty( $current_email_site ) ) {
								$site      = $current_email_site;
								$site_name = $site->name;
								$site_url  = $site->url;
								?>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
									<?php if ( 80 <= $site->health_value ) : ?>
									<p><?php printf( esc_html__( 'The site health check shows that your site %1$s (%2$s) health is ', 'mainwp' ), $site_name, $site_url ); // phpcs:ignore WordPress.Security.EscapeOutput ?><strong style="color:#7fb100;"><?php esc_html_e( 'Good', 'mainwp' ); ?>.</strong></p>
									<?php else : ?>
									<p><?php printf( esc_html__( 'The site health check shows that your site %1$s (%2$s) health ', 'mainwp' ), $site_name, $site_url ); // phpcs:ignore WordPress.Security.EscapeOutput ?><strong style="color:#f2711c;"><?php esc_html_e( 'Should be improved ', 'mainwp' ); ?></strong><?php esc_html_e( 'as soon as possible to improve its performance and security.', 'mainwp' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<strong><?php esc_html_e( 'Event timestamp: ', 'mainwp' ); ?></strong><?php echo MainWP\Dashboard\MainWP_Utility::format_timestamp( time() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</td>
							</tr>
								<?php
							} elseif ( ! empty( $sites ) ) {
								foreach ( $sites as $site ) {
									$site_name = $site->name;
									$site_url  = $site->url;
									?>
									<tr>
										<td align="left" valign="top" style="padding:30px 30px 0 30px;">
											<strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
											<?php if ( 80 <= $site->health_value ) : ?>
											<p><?php printf( esc_html__( 'The site health check shows that your site %1$s (%2$s) health is ', 'mainwp' ), $site_name, $site_url ); // phpcs:ignore WordPress.Security.EscapeOutput ?><strong style="color:#7fb100;"><?php esc_html_e( 'Good', 'mainwp' ); ?>.</strong></p>
											<?php else : ?>
											<p><?php printf( esc_html__( 'The site health check shows that your site %1$s (%2$s) health ', 'mainwp' ), $site_name, $site_url ); // phpcs:ignore WordPress.Security.EscapeOutput ?><strong style="color:#f2711c;"><?php esc_html_e( 'Should be improved ', 'mainwp' ); ?></strong><?php esc_html_e( 'as soon as possible to improve its performance and security.', 'mainwp' ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<td align="left" valign="top" style="padding:30px 30px 0 30px;">
											<strong><?php esc_html_e( 'Event timestamp: ', 'mainwp' ); ?></strong><?php echo MainWP\Dashboard\MainWP_Utility::format_timestamp( time() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										</td>
									</tr>
									<?php
								}
								?>
							<?php } ?>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=MonitoringSites' ) ); ?>" style="color:#7fb100;text-decoration:none;"><?php echo esc_html__( 'Click here', 'mainwp' ); ?></a> <?php echo esc_html__( 'to check your site status.', 'mainwp' ); ?>
								</td>
							</tr>
							<!-- End Body -->
						</table>
					</td>
				</tr>
			</table>
			<div style="text-align:center;font-size:11px;margin-bottom:30px;">
				<?php esc_html_e( 'Powered by ', 'mainwp' ); ?> <a href="https://mainwp.com/" style="color:#7fb100;"><?php esc_html_e( 'MainWP', 'mainwp' ); ?></a>.
			</div>
			<?php
			/**
			 * Site Health Monitoring Email Footer
			 *
			 * Fires at the bottom of the site health monitoring email template.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_site_health_monitoring_email_footer' );
			?>
		</div>
	</body>
</html>
<?php
