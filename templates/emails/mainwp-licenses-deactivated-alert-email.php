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
	$heading = 'Extension License Deactivation Notification';
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
			do_action( 'mainwp_licenses_deactivated_alert_email_header' );
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
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<p><?php esc_html_e( 'Your MainWP extension licenses for the following have been deactivated:', 'mainwp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
								</td>
							</tr>
							<?php
							if ( ! empty( $deactivated_licenses ) && is_array( $deactivated_licenses ) ) {
								foreach ( $deactivated_licenses as $info ) {
									?>
									<tr>
										<td align="left" valign="top" style="padding:30px 30px 0 30px;">
											<p><?php echo esc_html( $info['name'] ); ?></p>
										</td>
									</tr>
									<?php
								}
								?>
							<?php } ?>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<p><?php esc_html_e( 'If this deactivation was unintentional, please log into your MainWP Dashboard to reactivate the licenses.', 'mainwp' ); ?></p>
									<p><?php esc_html_e( 'If you no longer need these extensions, please ensure to disable and delete them from your MainWP Dashboard to maintain optimal performance and security.', 'mainwp' ); ?></p>
									<p><?php esc_html_e( 'To adjust email settings visit the MainWP > Settings > Email settings page.', 'mainwp' ); ?></p>
								</td>
							</tr>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=Extensions' ) ); ?>" style="color:#7fb100;text-decoration:none;"><?php echo esc_html__( 'Click here', 'mainwp' ); ?></a> <?php echo esc_html__( 'to check your Extensions.', 'mainwp' ); ?>
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
			do_action( 'mainwp_licenses_deactivated_alert_email_footer' );
			?>
		</div>
	</body>
</html>
<?php
