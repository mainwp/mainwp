<?php
/**
 * Email template for the Uptime Monitoring Notification.
 *
 * To overwrite this template, make a new template with the same filename and place it in the ../wp-content/uploads/mainwp/templates/email/ directory.
 *
 * @package     MainWP/Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Utility::get_http_codes()
 * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
 */

defined( 'ABSPATH' ) || exit;


$child_site_tokens = false;

if ( empty( $heading ) ) {
    $heading = 'Uptime Monitoring';
}

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
        <title><?php echo esc_html( get_bloginfo( 'name', 'display' ) ); ?></title>
    </head>
    <body offset="0" style="margin:0;background-color:#f7f7f7;font-family:'Lato',sans-serif;">
        <div id="mainwp-email-wrapper" style="padding: 30px 0;">
            <?php
            /**
             * Uptime Monitoring Email Header
             *
             * Fires at the top of the uptime monitoring email template.
             *
             * @since 4.1
             */
            do_action( 'mainwp_uptime_monitoring_email_header' );
            ?>
            <table style="border:0;height:100%;padding:0;border-spacing:0;margin-top:30px;margin-bottom:30px;">
                <tr>
                    <td style="vertical-align:top;text-align:center;">
                        <table style="border:0;padding:0;border-spacing:0;width:600px;background-color:#ffffff;border:1px solid #dedede;box-shadow: 0 1px 4px rgba(0,0,0,0.1);border-radius:3px;padding-bottom:30px;">
                            <!-- Header -->
                            <tr>
                                <td style="vertical-align:top;text-align:center;">
                                    <table style="border:0;width:600px;padding:0;border-spacing:0;">
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
                                $mo_url      = empty( $site->issub ) ? $site_url : $site_url . $site->suburl;
                                $code        = $site->hb_http_code;
                                $code_string = MainWP\Dashboard\MainWP_Utility::get_http_codes( $code );
                                if ( ! empty( $code_string ) ) {
                                    $code .= ' - ' . $code_string;
                                }
                                ?>
                            <tr>
                                <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                    <strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
                                    <?php if ( empty( $site->status ) ) { ?>
                                    <p><?php esc_html_e( 'Based on the HTTP response from your monitor, it appears that your child site is DOWN.', 'mainwp' ); ?></p>
                                    <?php } else { ?>
                                    <p><?php esc_html_e( 'Based on the HTTP response from your monitor, it appears that your child site is UP.', 'mainwp' ); ?></p>
                                    <?php } ?>
                                    <p><strong><?php esc_html_e( 'Monitor', 'mainwp' ); ?>:</strong> <?php echo esc_html( $site_name ); ?></p>
                                    <p><strong><?php esc_html_e( 'Monitor URL', 'mainwp' ); ?>:</strong> <a href="<?php echo esc_url( $mo_url ); ?>" target="_blank"><?php echo esc_html( $mo_url ); ?></a></p>
                                    <p><strong><?php esc_html_e( 'Status Code', 'mainwp' ); ?>:</strong> <?php echo esc_html( $code ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                    <strong><?php esc_html_e( 'Event timestamp: ', 'mainwp' ); ?></strong><?php echo MainWP\Dashboard\MainWP_Utility::format_timestamp( $site->hb_time_check ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                </td>
                            </tr>
                                <?php
                            } elseif ( ! empty( $sites ) ) {
                                foreach ( $sites as $site ) {
                                    $site_name   = $site->name;
                                    $site_url    = $site->url;
                                    $mo_url      = empty( $site->issub ) ? $site_url : $site_url . $site->suburl;
                                    $code        = $site->hb_http_code;
                                    $code_string = MainWP\Dashboard\MainWP_Utility::get_http_codes( $code );
                                    if ( ! empty( $code_string ) ) {
                                        $code .= ' - ' . $code_string;
                                    }
                                    ?>
                                        <tr>
                                            <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                                <strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
                                                <?php if ( empty( $site->status ) ) { ?>
                                                <p><?php esc_html_e( 'Based on the HTTP response from your monitor, it appears that your child site is DOWN.', 'mainwp' ); ?></p>
                                                <?php } else { ?>
                                                <p><?php esc_html_e( 'Based on the HTTP response from your monitor, it appears that your child site is UP.', 'mainwp' ); ?></p>
                                                <?php } ?>
                                                <p><strong><?php esc_html_e( 'Monitor', 'mainwp' ); ?>:</strong> <?php echo esc_html( $site_name ); ?></p>
                                                <p><strong><?php esc_html_e( 'Monitor URL', 'mainwp' ); ?>:</strong> <a href="<?php echo esc_url( $mo_url ); ?>" target="_blank"><?php echo esc_html( $mo_url ); ?></a></p>
                                                <p><strong><?php esc_html_e( 'Status Code', 'mainwp' ); ?>:</strong> <?php echo esc_html( $code ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                                <strong><?php esc_html_e( 'Event timestamp: ', 'mainwp' ); ?></strong>
                                                <?php
                                                $last_time = $site->offline_checks_last;
                                                if ( ! empty( $site->hb_time_check ) ) {
                                                    $last_time = $site->hb_time_check;
                                                }
                                                echo MainWP\Dashboard\MainWP_Utility::format_timestamp( $last_time ); // phpcs:ignore WordPress.Security.EscapeOutput
                                                ?>
                                            </td>
                                        </tr>
                                <?php } ?>
                            <?php } ?>
                            <tr>
                                <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=MonitoringSites' ) ); ?>" style="color:#7fb100;text-decoration:none;"><?php echo esc_html__( 'Click here', 'mainwp' ); ?></a> <?php echo esc_html__( 'to check your site status.', 'mainwp' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="vertical-align:top;text-align:center;padding:30px 30px 0 30px;">
                                    <?php esc_html_e( 'Powered by ', 'mainwp' ); ?> <a href="https://mainwp.com/" style="color:#7fb100;"><?php esc_html_e( 'MainWP', 'mainwp' ); // NOSONAR - noopener - open safe. ?></a>.
                                </td>
                            </tr>
                            <!-- End Body -->
                        </table>
                    </td>
                </tr>
            </table>
            <?php
            /**
             * Uptime Monitoring Email Footer
             *
             * Fires at the bottom of the uptime monitoring email template.
             *
             * @since 4.1
             */
            do_action( 'mainwp_uptime_monitoring_email_footer' );
            ?>
        </div>
    </body>
</html>
<?php
