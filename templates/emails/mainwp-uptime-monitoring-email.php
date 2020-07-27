<?php

defined( 'ABSPATH' ) || exit;

if ( empty( $heading ) ) {
	$heading = 'Uptime Monitoring';
}

?>
<!DOCTYPE html>
<html>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<title><?php echo get_bloginfo( 'name', 'display' ); ?></title>
	</head>
	<body marginwidth="0" topmargin="0" marginheight="0" offset="0" style="background-color:#f7f7f7;font-family:'Lato',sans-serif;">
		<div id="mainwp-email-wrapper" style="padding: 30px 0;">
			<?php do_action( 'mainwp_uptime_monitoring_email_header' ); ?>
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
								$site        = $current_email_site;
								$site_name   = $site->name;
								$site_url    = $site->url;
								$code        = $site->http_response_code;
								$code_string = MainWP\Dashboard\MainWP_Utility::get_http_codes( $code );
								if ( ! empty( $code_string ) ) {
									$code .= ' - ' . $code_string;
								}
								?>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
									<p><?php esc_html_e( 'Based on the HTTP response from your monitor, it appears that your child site is DOWN.', 'mainwp' ); ?></p>
									<p><strong><?php esc_html_e( 'Monitor', 'mainwp' ); ?>:</strong> <?php echo $site_name; ?></p>
									<p><strong><?php esc_html_e( 'Site URL', 'mainwp' ); ?>:</strong> <a href="<?php echo $site_url; ?>" target="_blank"><?php echo $site_url; ?></a></p>
									<p><strong><?php esc_html_e( 'Status Code', 'mainwp' ); ?>:</strong> <?php echo $code; ?></p>
								</td>
							</tr>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<strong><?php esc_html_e( 'Event timestamp: ', 'mainwp' ); ?></strong><?php echo MainWP\Dashboard\MainWP_Utility::format_timestamp( $site->offline_checks_last ); ?>
								</td>
							</tr>
								<?php
							} elseif ( ! empty( $sites ) ) {
								foreach ( $sites as $site ) {
									$site_name   = $site->name;
									$site_url    = $site->url;
									$code        = $site->http_response_code;
									$code_string = MainWP\Dashboard\MainWP_Utility::get_http_codes( $code );
									if ( ! empty( $code_string ) ) {
										$code .= ' - ' . $code_string;
									}
									?>
										<tr>
											<td align="left" valign="top" style="padding:30px 30px 0 30px;">
												<strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
												<p><?php esc_html_e( 'Based on the HTTP response from your monitor, it appears that your child site is DOWN.', 'mainwp' ); ?></p>
												<p><strong><?php esc_html_e( 'Monitor', 'mainwp' ); ?>:</strong> <?php echo $site_name; ?></p>
												<p><strong><?php esc_html_e( 'Site URL', 'mainwp' ); ?>:</strong> <a href="<?php echo $site_url; ?>" target="_blank"><?php echo $site_url; ?></a></p>
												<p><strong><?php esc_html_e( 'Status Code', 'mainwp' ); ?>:</strong> <?php echo $code; ?></p>
											</td>
										</tr>
										<tr>
											<td align="left" valign="top" style="padding:30px 30px 0 30px;">
												<strong><?php esc_html_e( 'Event timestamp: ', 'mainwp' ); ?></strong><?php echo MainWP\Dashboard\MainWP_Utility::format_timestamp( $site->offline_checks_last ); ?>
											</td>
										</tr>
								<?php } ?>
							<?php } ?>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<a href="<?php echo admin_url( 'admin.php?page=MonitoringSites' ); ?>" style="color:#7fb100;text-decoration:none;"><?php echo __( 'Click here', 'mainwp' ); ?></a> <?php echo __( 'to check your site status.', 'mainwp' ); ?>
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
			<?php do_action( 'mainwp_uptime_monitoring_email_footer' ); ?>
		</div>
	</body>
</html>
<?php
