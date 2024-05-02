<?php
/**
 * Email template for the the Cost Tracking Notification Emails.
 *
 * To overwrite this template, make a new template with the same filename and place it in the ../wp-content/uploads/mainwp/templates/email/ directory.
 *
 * @package MainWP/Extensions
 */

defined( 'ABSPATH' ) || exit;

$child_site_tokens = false;

if ( empty( $heading ) ) {
    $heading = 'MainWP Cost Tracker Notification';
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
             * HTTP Check Email Header
             *
             * Fires at the top of the HTTP check (after update checks) email template.
             *
             * @since 4.0
             */
            do_action( 'mainwp_module_cost_tracker_email_header' );
            ?>
            <table style="border:0;height:100%;padding:0;border-spacing:0;margin-top:30px;margin-bottom:30px;">
                <tr>
                    <td style="text-align:center;vertical-align:top;">
                        <table style="border:0;padding:0;border-spacing:0;width:600px;background-color:#ffffff;border:1px solid #dedede;box-shadow: 0 1px 4px rgba(0,0,0,0.1);border-radius:3px;padding-bottom:30px;">
                            <!-- Header -->
                            <tr>
                                <td style="text-align:center;vertical-align:top;">
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
                            <tr>
                                <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                    <br /><br />
                                    <?php echo $content_text; //phpcs:ignore -- ok. ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                </td>
                            </tr>
                            <!-- End Body -->
                        </table>
                    </td>
                </tr>
            </table>
            <div style="text-align:center;font-size:11px;margin-bottom:30px;">
                <?php esc_html_e( 'Powered by', 'mainwp' ); ?> <a href="https://mainwp.com/" style="color:#7fb100;"><?php esc_html_e( 'MainWP', 'mainwp' ); // NOSONAR - noopener - open safe. ?></a>.
            </div>
            <?php
            /**
             * HTTP Check Email Footer
             *
             * Fires at the bottom of the HTTP check (after update checks) email template.
             *
             * @since 4.0
             */
            do_action( 'mainwp_module_cost_tracker_email_footer' );
            ?>
        </div>
    </body>
</html>
<?php
