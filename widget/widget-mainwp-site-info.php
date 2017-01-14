<?php

class MainWP_Site_Info {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function init() {

	}

	public static function getName() {
		return '<i class="fa fa-info" aria-hidden="true"></i> ' . __( 'Site info', 'mainwp' );
	}

	public static function render() {		
        MainWP_Site_Info::renderSiteInfo();
	}

	public static function renderSiteInfo() {
		$current_wpid = MainWP_Utility::get_current_wpid();
        if ( empty( $current_wpid ) ) return;

        $sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
                
		$websites = MainWP_DB::Instance()->query( $sql );
		if ( empty($websites) ) return;
                
        $website = @MainWP_DB::fetch_object( $websites );

        $website_info = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'site_info' ), true );

        $infor_items = array(
            'wpversion' => __('WordPress Version', 'mainwp'),
            'phpversion' => __('PHP Version', 'mainwp'),
            'child_version' => __('MainWP Child Version', 'mainwp'),
            'memory_limit' => __('PHP Memory Limit', 'mainwp'),
            'mysql_version' => __('MySQL Version', 'mainwp'),
            'ip' => __('Server IP', 'mainwp'),
        );
                
		?>
		<div class="mainwp-widget-content widget-site-info">			
            <?php
            if ( !is_array( $website_info ) || !isset( $website_info['wpversion'] ) ) {
                echo __( 'Site info empty', 'mainwp' );
            } else {

                ?>
                <table style="width:100%">
                    <tbody>
                    <?php
                    foreach ( $infor_items as $index => $title ) {
                        ?>
                        <tr><td style="width: 40%"><?php echo $title; ?>:</td><td><strong><?php echo isset($website_info[$index]) ? $website_info[$index] : '' ?></strong></td></tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
                <?php
            }
            ?>
        </div>
		<?php
		@MainWP_DB::free_result( $websites );
	}
}
