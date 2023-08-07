<?php
/**
 * Email template for the Daily Digest Notification.
 *
 * To overwrite this template, make a new template with the same filename and place it in the ../wp-content/uploads/mainwp/templates/email/ directory.
 *
 * @package     MainWP/Dashboard
 */

defined( 'ABSPATH' ) || exit;

$child_site_tokens = false;

if ( empty( $heading ) ) {
	$heading = 'Daily Digest';
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
			 * Daily Digest Email Header
			 *
			 * Fires at the top of the daily digest email template.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_daily_digest_email_header' );
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
									<strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
									<p><?php esc_html_e( 'Please take a few minutes to review the latest updates from your MainWP Dashboard.', 'mainwp' ); ?></p>
								</td>
							</tr>
							<tr>
								<td align="left" valign="top" style="padding:30px 30px 0 30px;">
									<?php if ( $available_updates ) : ?>
									<h3 style="color:#7fb100;"><?php esc_html_e( 'Available Updates', 'mainwp' ); ?></h3>
									<p><?php esc_html_e( 'The following updates are available on your MainWP Dashboard.', 'mainwp' ); ?></p>
										<?php if ( is_array( $wp_updates ) && 0 < count( $wp_updates ) ) : ?>
											<h4 style="color:#444;"><?php esc_html_e( 'WordPress Core Updates', 'mainwp' ); ?></h4>
											<table border="0" cellpadding="0" cellspacing="0" align="left" width="100%" style="font-size:11px; margin-bottom:30px;">
												<thead style="background: #eee">
													<tr>
														<th style="padding:5px;" align="left"><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Detected', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php foreach ( $wp_updates as $item ) : ?>
													<tr>
														<td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo $item['new'] ? '<span style="background:#7fb100;padding:3px 6px;color:#fff;font-size:0.6em">NEW</span>' : ''; ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $item['id'] ) ); ?>" style="color:#7fb100;"><?php echo esc_html( $item['name'] ); ?></a></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><?php echo esc_html( $item['current'] ); ?></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><a href="#" style="color:#7fb100;"><?php echo esc_html( $item['new_version'] ); ?></a></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><?php echo $item['trusted'] ? '<span style="background:#7fb100;padding:3px 7px;color:#fff;">Yes</span>' : '<span style="background:#444;padding:3px 7px;color:#fff;">No</span>'; ?></td>
													</tr>
												<?php endforeach; ?>
												</tbody>
											</table>
										<?php endif; ?>
										<?php if ( is_array( $plugin_updates ) && 0 < count( $plugin_updates ) ) : ?>
											<h4 style="color:#444;"><?php esc_html_e( 'Plugins Updates', 'mainwp' ); ?></h4>
											<table border="0" cellpadding="0" cellspacing="0" align="left" width="100%" style="font-size:11px; margin-bottom:30px;">
												<thead style="background: #eee">
													<tr>
														<th style="padding:5px;" align="left"><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
														<th style="padding:5px;" align="left"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Detected', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
													</tr>
												</thead>
												<tbody>
												<?php foreach ( $plugin_updates as $item ) : ?>
													<tr>
														<td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo $item['new'] ? '<span style="background:#7fb100;padding:3px 6px;color:#fff;font-size:0.6em">NEW</span>' : ''; ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $item['id'] ) ); ?>" style="color:#7fb100;"><?php echo esc_html( $item['name'] ); ?></a></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo esc_html( $item['plugin'] ); ?></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><?php echo esc_html( $item['current'] ); ?></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><a href="<?php echo ! empty( $item['change_log'] ) ? esc_url_raw( $item['change_log'] ) : ''; ?>" style="color:#7fb100;"><?php echo esc_html( $item['new_version'] ); ?></a></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><?php echo $item['trusted'] ? '<span style="background:#7fb100;padding:3px 7px;color:#fff;">Yes</span>' : '<span style="background:#444;padding:3px 7px;color:#fff;">No</span>'; ?></td>
													</tr>
												<?php endforeach; ?>
												</tbody>
											</table>
										<?php endif; ?>
										<?php if ( is_array( $theme_updates ) && 0 < count( $theme_updates ) ) : ?>
											<h4 style="color:#444;"><?php esc_html_e( 'Themes Updates', 'mainwp' ); ?></h4>
											<table border="0" cellpadding="0" cellspacing="0" align="left" width="100%" style="font-size:11px; margin-bottom:30px;">
												<thead style="background: #eee">
													<tr>
														<th style="padding:5px;" align="left"><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
														<th style="padding:5px;" align="left"><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Detected', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
														<th style="padding:5px;"><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
													</tr>
												</thead>
												<tbody>
												<?php foreach ( $theme_updates as $item ) : ?>
													<tr>
														<td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo $item['new'] ? '<span style="background:#7fb100;padding:3px 6px;color:#fff;font-size:0.6em">NEW</span>' : ''; ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $item['id'] ) ); ?>" style="color:#7fb100;"><?php echo esc_html( $item['name'] ); ?></a></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo esc_html( $item['theme'] ); ?></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><?php echo esc_html( $item['current'] ); ?></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><a href="#" style="color:#7fb100;"><?php echo esc_html( $item['new_version'] ); ?></a></td>
														<td style="padding:10px;border-bottom: 1px solid #eee;" align="center"><?php echo $item['trusted'] ? '<span style="background:#7fb100;padding:3px 7px;color:#fff;">Yes</span>' : '<span style="background:#444;padding:3px 7px;color:#fff;">No</span>'; ?></td>
													</tr>
												<?php endforeach; ?>
												</tbody>
											</table>
										<?php endif; ?>
										<p><?php esc_html_e( 'If your MainWP is configured to use Auto Updates, trusted updates will be installed in the next 24 hours.', 'mainwp' ); ?></p>
									<?php else : ?>
									<p><?php esc_html_e( 'Congratulations! All your child sites are up to date!', 'mainwp' ); ?></p>
									<?php endif; ?>
									<?php if ( is_array( $sites_disconnected ) && 0 < count( $sites_disconnected ) ) : ?>
										<h3 style="color:#7fb100;"><?php esc_html_e( 'Disconnected Sites', 'mainwp' ); ?></h3>
										<p><?php esc_html_e( 'The following sites got disconnected from your MainWP Dashboard.', 'mainwp' ); ?></p>
										<table border="0" cellpadding="0" cellspacing="0" align="left" width="100%" style="font-size:11px; margin-bottom:30px;">
											<thead style="background: #eee">
												<tr>
													<th style="padding:5px;" align="left"><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
													<th style="padding:5px;"></th>
												</tr>
											</thead>
											<tbody>
											<?php foreach ( $sites_disconnected as $item ) : ?>
												<tr>
													<td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo esc_html( $item['name'] ); ?></td>
													<td style="padding:10px;border-bottom: 1px solid #eee;" align="right"><a href="<?php echo esc_url_raw( $item['url'] ); ?>" style="color:#7fb100;"><?php esc_html_e( 'Visit Site', 'mainwp' ); ?></a></td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
									<?php else : ?>
									<p><?php esc_html_e( 'Congratulations! All your child sites are properly connected!', 'mainwp' ); ?></p>
									<?php endif; ?>
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
			 * Daily Digest Email Footer
			 *
			 * Fires at the bottom of the daily digest email template.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_daily_digest_email_footer' );
			?>
		</div>
	</body>
</html>
<?php
