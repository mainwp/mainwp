<?php
/**
 * Email template for the Auto Updates Notification.
 *
 * To overwrite this template, make a new template with the same filename and place it in the ../wp-content/uploads/mainwp/templates/email/ directory.
 *
 * @package     MainWP/Dashboard
 */

defined( 'ABSPATH' ) || exit;

$child_site_tokens = false;

if ( empty( $heading ) ) {
    $heading = 'Auto Updates Notification';
}

$auto_updated_items = isset( $auto_updated_items ) && is_array( $auto_updated_items ) ? $auto_updated_items : array();

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
             * Auto Updates Email Header
             *
             * Fires at the top of the auto updates email template.
             *
             * @since 6.3
             */
            do_action( 'mainwp_auto_updates_email_header' );
            // old html style for email clients display.
            ?>
            <table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" style="margin-top:30px;margin-bottom:30px;" aria-hidden="true">
                <tr>
                    <td align="center" valign="top">
                        <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color:#ffffff;border:1px solid #dedede;box-shadow: 0 1px 4px rgba(0,0,0,0.1);border-radius:3px;padding-bottom:30px;" aria-hidden="true">
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
                            <tr>
                                <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                    <strong><?php esc_html_e( 'Hi there', 'mainwp' ); ?>,</strong>
                                    <p><?php esc_html_e( 'Please take a few minutes to review the items that were automatically updated via your MainWP Dashboard.', 'mainwp' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td style="vertical-align:top;text-align:left;padding:30px 30px 0 30px;">
                                    <?php if ( ! empty( $auto_updated_items ) && is_array( $auto_updated_items ) ) : ?>
                                    <h3 style="color:#7fb100;"><?php esc_html_e( 'Auto Updated', 'mainwp' ); ?></h3>
                                    <p><?php esc_html_e( 'The following updates were automatically completed via your MainWP Dashboard.', 'mainwp' ); ?></p>
                                        <?php foreach ( $auto_updated_items as $wpid => $updated_items ) : ?>
                                        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $updated_items['site_id'] ) ); ?>" style="color:#7fb100;"><?php echo esc_html( $updated_items['site_name'] ); ?></a></p>
                                            <?php if ( ! empty( $updated_items['core'] ) && ! is_array( $updated_items['core'] ) ) : ?>
                                            <h4 style="color:#444;"><?php esc_html_e( 'WordPress Core', 'mainwp' ); ?></h4>
                                            <table style="border:0;width:100%;text-align:left;padding:0;border-spacing:0;font-size:11px; margin-bottom:30px;">
                                                <thead style="background: #eee">
                                                    <tr>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Old Version', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Latest Version', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    foreach ( $updated_items['core'] as $wpid => $item ) :
                                                        if ( empty( $item['success'] ) ) {
                                                            continue;
                                                        }
                                                        ?>
                                                    <tr>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><?php echo esc_html( $item['old_version'] ); ?></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><a href="#" style="color:#7fb100;"><?php echo esc_html( $item['version'] ); ?></a></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><?php echo $item['success'] ? '<span style="background:#7fb100;padding:3px 7px;color:#fff;">Success</span>' : '<span style="background:#444;padding:3px 7px;color:#fff;">Failed</span>'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                            <?php if ( is_array( $updated_items['plugins'] ) && ! empty( $updated_items['plugins'] ) ) : ?>
                                            <h4 style="color:#444;"><?php esc_html_e( 'Plugin Updates', 'mainwp' ); ?></h4>
                                            <table style="border:0;width:100%;text-align:left;padding:0;border-spacing:0;font-size:11px; margin-bottom:30px;">
                                                <thead style="background: #eee">
                                                    <tr>
                                                        <th style="padding:5px;text-align:left;"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Old Version', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Latest Version', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php
                                                foreach ( $updated_items['plugins'] as $wpid => $item ) :
                                                    if ( empty( $item['success'] ) ) {
                                                        continue;
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo esc_html( $item['name'] ); ?></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><?php echo esc_html( $item['old_version'] ); ?></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><a href="<?php echo ! empty( $item['change_log'] ) ? esc_url_raw( $item['change_log'] ) : ''; ?>" style="color:#7fb100;"><?php echo esc_html( $item['version'] ); ?></a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                            <?php if ( is_array( $updated_items['themes'] ) && ! empty( $updated_items['themes'] ) ) : ?>
                                            <h4 style="color:#444;"><?php esc_html_e( 'Theme Updates', 'mainwp' ); ?></h4>
                                            <table style="border:0;width:100%;text-align:left;padding:0;border-spacing:0;font-size:11px; margin-bottom:30px;">
                                                <thead style="background: #eee">
                                                    <tr>
                                                        <th style="padding:5px;text-align:left;"><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Old Version', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Latest Version', 'mainwp' ); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php
                                                foreach ( $updated_items['themes'] as $item ) :
                                                    if ( empty( $item['success'] ) ) {
                                                        continue;
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo esc_html( $item['name'] ); ?></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><?php echo esc_html( $item['old_version'] ); ?></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><a href="#" style="color:#7fb100;"><?php echo esc_html( $item['version'] ); ?></a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>

                                            <?php if ( is_array( $updated_items['translations'] ) && ! empty( $updated_items['translations'] ) ) : ?>
                                            <h4 style="color:#444;"><?php esc_html_e( 'Translation Updates', 'mainwp' ); ?></h4>
                                            <table style="border:0;width:100%;text-align:left;padding:0;border-spacing:0;font-size:11px; margin-bottom:30px;">
                                                <thead style="background: #eee">
                                                    <tr>
                                                        <th style="padding:5px;text-align:left;"><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                                                        <th style="padding:5px;"><?php esc_html_e( 'Latest Version', 'mainwp' ); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php
                                                foreach ( $updated_items['translations'] as $item ) :
                                                    if ( empty( $item['success'] ) ) {
                                                        continue;
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;"><?php echo esc_html( $item['name'] ); ?></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><?php echo esc_html( $item['type'] ); ?></td>
                                                        <td style="padding:10px;border-bottom: 1px solid #eee;text-align:center;"><a href="#" style="color:#7fb100;"><?php echo esc_html( $item['version'] ); ?></a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>

                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </td>
                            </tr>
                            <!-- End Body -->
                        </table>
                    </td>
                </tr>
            </table>
            <div style="text-align:center;font-size:11px;margin-bottom:30px;">
                <?php esc_html_e( 'Developed by ', 'mainwp' ); ?> <a href="https://mainwp.com/" style="color:#7fb100;"><?php esc_html_e( 'MainWP', 'mainwp' ); ?></a>. <?php esc_html_e( 'Sent from your Dashboard.', 'mainwp' ); // NOSONAR - noopener - open safe. ?>
            </div>
            <?php
            /**
             * Auto Updates Email Footer
             *
             * Fires at the bottom of the auto updates email template.
             *
             * @since 6.3
             */
            do_action( 'mainwp_auto_updates_email_footer' );
            ?>
        </div>
    </body>
</html>
<?php
