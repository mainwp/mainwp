<?php

class MainWP_Blogroll_Widget {

    public static function getClassName() {
        return __CLASS__;
    }

    public static function getName() {
        return '<i class="fa fa-rss-square" aria-hidden="true"></i> ' . __( 'MainWP blogroll', 'mainwp' );
    }

    public static function get_mainwp_blogroll() {
        $cache_key = 'mainwp_blogroll_feed_content';
        $reload = isset($_POST['reload']) && $_POST['reload'];
        if ( !$reload && ( false !== ( $output = get_transient( $cache_key ) ) ) ) {
            echo $output;
            return true;
        }

        include_once( ABSPATH . WPINC . '/feed.php' );

        $maxitems = 0;
        // Get a SimplePie feed object from the specified feed source.
        $rss = fetch_feed( 'https://mainwp.com/feed/' );
        if ( ! is_wp_error( $rss ) ) : // Checks that the object is created correctly
            // Figure out how many total items there are, but limit it to 5.
            $maxitems = $rss->get_item_quantity( 5 );
            // Build an array of all the items, starting with element 0 (first element).
            $rss_items = $rss->get_items( 0, $maxitems );
        endif;
        ob_start();
        ?>
        <ul>
            <?php if ( $maxitems == 0 ) : ?>
                <li><?php _e( 'No items', 'mainwp' ); ?></li>
            <?php else : ?>
                <?php // Loop through each feed item and display each item as a hyperlink. ?>
                <?php foreach ( $rss_items as $item ) : ?>
                    <li>
                        <a href="<?php echo esc_url( $item->get_permalink() ) . '?utm_source=dashboard&utm_campaign=blog-widget&utm_medium=plugin'; ?>"
                           target="_blank" title="<?php printf( __( 'Posted %s', 'mainwp' ), $item->get_date('j F Y | g:i a') ); ?>">
                            <?php echo esc_html( $item->get_title() ); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
        <?php

        $output = ob_get_clean();
        set_transient( $cache_key, $output, 12 * HOUR_IN_SECONDS );

        echo $output;
        return;
    }

    public static function render() {
        $current_options = get_option( 'mainwp_opts_saving_status' );
        $blogroll_enabled = (is_array($current_options) && isset($current_options['mainwp_blogroll_enabled']) && !empty($current_options['mainwp_blogroll_enabled'])) ? true : false;
        ?>
        <div class="mainwp-postbox-actions-top">
            <div class="mainwp-left" style="width: 75%;"><?php _e( 'Would you like to receive notice of the latest posts from the MainWP blog directly in this widget?', 'mainwp' ); ?></div>
            <div class="mainwp-cols-4 mainwp-right mainwp-t-align-right">
                            <span class="mainwp-checkbox">
                                    <input type="checkbox" name="enable_mainwp_blogroll" id="enable_mainwp_blogroll" <?php echo $blogroll_enabled ? 'checked="checked"' : ''; ?> />
                                    <label for="enable_mainwp_blogroll"></label>
                            </span>
            </div>
            <div style="clear:both;"></div>
        </div>
        <div class="inside">
            <div id="mainwp_blogroll_open_wrap" <?php echo $blogroll_enabled ? 'style="display: none"' : ''; ?>>
                <a href="https://mainwp.com/mainwp-blog" target="_blank"><?php _e( 'Check the MainWP Blog', 'mainwp' ); ?></a>
            </div>
            <div id="mainwp_blogroll_content"></div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function () {
                <?php
				   if ($blogroll_enabled) {
					?>
                mainwp_get_blogroll();
                <?php
			   }
			?>
                jQuery('#enable_mainwp_blogroll').change(function() {
                    if (jQuery(this).is(':checked')) {
                        mainwp_get_blogroll(true);
                        jQuery('#mainwp_blogroll_open_wrap').fadeOut(0);
                    } else {
                        jQuery('#mainwp_blogroll_content').hide();
                        jQuery('#mainwp_blogroll_open_wrap').fadeIn(1000);
                    }
                    var data = {
                        action:'mainwp_saving_status',
                        saving_status: 'mainwp_blogroll_enabled',
                        value: jQuery(this).is(':checked') ? 1 : 0,
                        nonce: mainwp_ajax_nonce
                    };
                    jQuery.post(ajaxurl, data, function (res) {
                    });
                });
            })

        </script>
        <?php
    }
}
