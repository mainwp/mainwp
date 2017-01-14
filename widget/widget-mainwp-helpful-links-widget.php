<?php

class MainWP_Helpful_Links_Widget {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function getName() {
		return '<i class="fa fa-link" aria-hidden="true"></i> ' . __( 'Helpful links', 'mainwp' );
	}

	public static function render() {
		$support = 'https://mainwp.com/support/';
		$documentation = 'https://mainwp.com/help/';
		$facebook = 'https://facebook.com/mainwp/';
		$facebook_group = 'https://www.facebook.com/groups/MainWPUsers/';
		$twitter = 'https://twitter.com/mymainwp';
		$trello = 'https://mainwp.com/mainwp-roadmaps/';
		?>
		<div>
			<div class="mainwp-row-top">
				<h4><a href="<?php echo $documentation ; ?>" target="_blank"><i class="fa fa-book" aria-hidden="true"></i> <?php _e( 'MainWP Documentation', 'mainwp' ); ?></a></h4>
				<em><?php _e( 'Review the MainWP docuumentation if you need help with getting started with the MainWP products.', 'mainwp' ); ?></em>
			</div>
			<div class="mainwp-row">
				<h4><a href="<?php echo $support ; ?>" target="_blank"><i class="fa fa-life-ring" aria-hidden="true"></i> <?php _e( 'MainWP Support', 'mainwp' ); ?></a></h4>
				<em><?php _e( 'If you need any help with the MainWP products, do not hesitate to reach us through our support portal.', 'mainwp' ); ?></em>
			</div>
			<div class="mainwp-row">
				<h4><a href="<?php echo $facebook ; ?>" target="_blank"><i class="fa fa-facebook-square" aria-hidden="true"></i> <?php _e( 'MainWP Facebook Page', 'mainwp' ); ?></a></h4>
				<em><?php _e( 'Follow us on our Facebook page and get all important updates about the MainWP plugins.', 'mainwp' ); ?></em>
			</div>
			<div class="mainwp-row">
				<h4><a href="<?php echo $facebook_group ; ?>" target="_blank"><i class="fa fa-facebook-square" aria-hidden="true"></i> <?php _e( 'MainWP Users Facebook Group', 'mainwp' ); ?></a></h4>
				<em><?php _e( 'Join the MainWP Users Facebook group and join discussions about the MainWP products with other MainWP users. ', 'mainwp' ); ?></em>
			</div>
			<div class="mainwp-row">
				<h4><a href="<?php echo $twitter ; ?>" target="_blank"><i class="fa fa-twitter" aria-hidden="true"></i> <?php _e( 'MainWP Twitter', 'mainwp' ); ?></a></h4>
				<em><?php _e( 'Follow us on our Twitter page and get all important updates about the MainWP plugins.', 'mainwp' ); ?></em>
			</div>
			<div class="mainwp-row">
				<h4><a href="<?php echo $trello ; ?>" target="_blank"><i class="fa fa-trello" aria-hidden="true"></i> <?php _e( 'MainWP Roadmaps', 'mainwp' ); ?></a></h4>
				<em><?php _e( 'If you have any suggestion or feature request for the MainWP products, please check our roadmaps.', 'mainwp' ); ?></em>
			</div>
		</div>
		<?php
	}
}