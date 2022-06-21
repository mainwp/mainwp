<?php
/**
 * MainWP Manage Screenshots.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Screenshots
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Screenshots {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * MainWP_Manage_Screenshots constructor.
	 *
	 * Run each time the class is called.
	 * Add action to generate tabletop.
	 */
	public function __construct() {
		add_action( 'mainwp_managesites_tabletop', array( &$this, 'generate_tabletop' ) );
	}

	/**
	 * Method generate_tabletop()
	 *
	 * Run the render_manage_sites_table_top menthod.
	 */
	public function generate_tabletop() {
		$this->render_manage_sites_table_top();
	}

	/**
	 * Render manage sites table top.
	 */
	public function render_manage_sites_table_top() {
		$selected_group = isset( $_REQUEST['g'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) : '';
		$is_not         = isset( $_REQUEST['isnot'] ) && ( 'yes' == $_REQUEST['isnot'] ) ? true : false;

		if ( ! isset( $_REQUEST['g'] ) ) {
			$selected_group = get_user_option( 'mainwp_screenshots_filter_group' );
			$is_not         = get_user_option( 'mainwp_screenshots_filter_is_not' );
		}

		?>
		<div class="ui grid">
			<div class="equal width row ui mini form">
			<div class="middle aligned column">
					<input type="text" id="mainwp-screenshots-sites-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>">
				</div>
				<div class="right aligned middle aligned column">
				<?php esc_html_e( 'Filter sites: ', 'mainwp' ); ?>
					<div class="ui selection dropdown" id="mainwp_is_not_site">
							<input type="hidden" value="<?php echo $is_not ? 'yes' : ''; ?>">
							<i class="dropdown icon"></i>
							<div class="default text"><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
							<div class="menu">
								<div class="item" data-value=""><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
								<div class="item" data-value="yes"><?php esc_html_e( 'Is not', 'mainwp' ); ?></div>
							</div>
						</div>
						<div id="mainwp-filter-sites-group" class="ui multiple selection dropdown">
							<input type="hidden" value="<?php echo esc_html( $selected_group ); ?>">
							<i class="dropdown icon"></i>
							<div class="default text"><?php esc_html_e( 'All groups', 'mainwp' ); ?></div>
							<div class="menu">
								<?php
								$groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
								foreach ( $groups as $group ) {
									?>
									<div class="item" data-value="<?php echo $group->id; ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></div>
									<?php
								}
								?>
								<div class="item" data-value="nogroups"><?php esc_html_e( 'No Groups', 'mainwp' ); ?></div>
							</div>
						</div>
						<button onclick="mainwp_screenshots_sites_filter()" class="ui tiny basic button"><?php esc_html_e( 'Filter Sites', 'mainwp' ); ?></button>
				</div>
			</div>
		</div>
		<script type="text/javascript">
				mainwp_screenshots_sites_filter = function() {
					var group = jQuery( "#mainwp-filter-sites-group" ).dropdown( "get value" );
					var isNot = jQuery("#mainwp_is_not_site").dropdown("get value");
					var params = '';
					params += '&g=' + group;
					if ( 'yes' == isNot ){
						params += '&isnot=yes';
					}
					window.location = 'admin.php?page=managesites' + params;
					return false;
				};

				jQuery( document ).on( 'keyup', '#mainwp-screenshots-sites-filter', function () {
					var filter = jQuery(this).val().toLowerCase();
					var siteItems =  jQuery('#mainwp-sites-previews').find( '.card' );
					for ( var i = 0; i < siteItems.length; i++ ) {
						var currentElement = jQuery( siteItems[i] );
						var valueurl = jQuery(currentElement).attr('site-url').toLowerCase();
						var valuename = currentElement.find('.ui.header').text().toLowerCase();
						if ( valueurl.indexOf( filter ) > -1 || valuename.indexOf( filter ) > -1 ) {
							currentElement.show();
						} else {
							currentElement.hide();
						}
					}
				} );

				jQuery('#mainwp-sites-previews .image img').visibility({
					type       : 'image',
					transition : 'fade in',
					duration   : 1000
				});

		</script>
		<?php
	}

	/**
	 * Method render_all_sites()
	 *
	 * Render Screenshots.
	 */
	public static function render_all_sites() {
		/**
		 * Sites Page header
		 *
		 * Renders the tabs on the Sites screen.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_pageheader_sites', 'managesites' );

		$websites = self::prepare_items();

		MainWP_DB::data_seek( $websites, 0 );

		?>
		<div id="mainwp-screenshots-sites" class="ui segment">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-grid-view-mode-info-message' ) ) : ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-grid-view-mode-info-message"></i>
				<div><strong><?php echo __( 'Sites view mode is an experimental feature.', 'mainwp' ); ?></strong></div>
				<div><?php echo __( 'In the Grid mode, sites options are limited in comparison to the Table mode.', 'mainwp' ); ?></div>
				<div><?php echo __( 'Grid mode queries WordPress.com servers to capture a screenshot of your site the same way comments show you a preview of URLs.', 'mainwp' ); ?></div>
		</div>
			<?php endif; ?>
		<?php
		/**
		 * Filter: mainwp_cards_per_row
		 *
		 * Filters the number of cards per row in MainWP Screenshots page.
		 *
		 * @since 4.1.8
		 */
		$cards_per_row = apply_filters( 'mainwp_cards_per_row', 'five' );
		?>
		<div id="mainwp-sites-previews">
				<div class="ui <?php echo $cards_per_row; ?> cards" >
					<?php
					while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {

						?>
					<div class="card" site-url="<?php echo $website->url; ?>">
						<div class="image">
							<img data-src="//s0.wordpress.com/mshots/v1/<?php echo rawurlencode( $website->url ); ?>?w=900">
						</div>
						<div class="content">
						<h5 class="ui small header"><?php echo $website->name; ?></h5>
						<div class="meta">
						</div>
						<div class="description">
							<?php echo $website->note; ?>
						</div>
						</div>
						<div class="extra content">
							<span class="right floated" data-tooltip="<?php esc_attr_e( 'Last sync time.', 'mainwp' ); ?>">
							<?php echo 0 != $website->dtsSync ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website->dtsSync ) ) : ''; ?>
						</span>
						<span>
							<a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>"><i class="grid layout icon"></i></a>
							<a href="javascript:void(0)" class="mainwp-sync-this-site" site-id="<?php echo $website->id; ?>"><i class="sync alternate icon"></i></a>
							<a href="admin.php?page=managesites&id=<?php echo $website->id; ?>"><i class="edit icon"></i></a>
							<a href="admin.php?page=managesites&scanid=<?php echo $website->id; ?>"><i class="shield icon"></i></a>
							<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>&_opennonce=<?php echo wp_create_nonce( 'mainwp-admin-nonce' ); ?>" target="_blank"><i class="sign in icon"></i></a>
						</span>
						</div>
					</div>
						<?php
					}
					?>
				</div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery('#mainwp-sites-previews .image img').visibility({
				type       : 'image',
				transition : 'fade in',
				duration   : 1000
			});

			mainwp_manage_sites_screen_options = function () {
				jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( {
					allowMultiple: true,
					onHide: function () {
						//ok.
					}
				} ).modal( 'show' );

				jQuery( '#manage-sites-screen-options-form' ).submit( function() {
					jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( 'hide' );
				} );
				return false;
			};

		</script>
		<?php
		MainWP_DB::free_result( $websites );
		self::render_screen_options();
		/**
		 * Sites Page Footer
		 *
		 * Renders the footer on the Sites screen.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_pagefooter_sites', 'managesites' );
	}

	/**
	 * Method render_screen_options()
	 *
	 * Render Screen Options Modal.
	 */
	public static function render_screen_options() {

		$siteViewMode = get_user_option( 'mainwp_sitesviewmode' );
		if ( 'grid' !== $siteViewMode && 'table' !== $siteViewMode ) {
			$siteViewMode = 'table';
		}
		?>
		<div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Screen Options', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'SreenshotsSitesScrOptions' ); ?>" />
					<div class="ui grid field">
						<label class="top aligned six wide column" tabindex="0"><?php esc_html_e( 'Sites view mode', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Sites view mode.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
							<div class="ui info message">
								<div><strong><?php echo __( 'Sites view mode is an experimental feature.', 'mainwp' ); ?></strong></div>
								<div><?php echo __( 'In the Grid mode, sites options are limited in comparison to the Table mode.', 'mainwp' ); ?></div>
								<div><?php echo __( 'Grid mode queries WordPress.com servers to capture a screenshot of your site the same way comments show you a preview of URLs.', 'mainwp' ); ?></div>
							</div>
							<select name="mainwp_sitesviewmode" id="mainwp_sitesviewmode" class="ui dropdown">
								<option value="table" <?php echo ( 'table' == $siteViewMode ? 'selected' : '' ); ?>><?php esc_html_e( 'Table', 'mainwp' ); ?></option>
								<option value="grid" <?php echo ( 'grid' == $siteViewMode ? 'selected' : '' ); ?>><?php esc_html_e( 'Grid', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<div class="ui hidden divider"></div>
					<div class="ui hidden divider"></div>
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							<span data-tooltip="<?php esc_attr_e( 'Returns this page to the state it was in when installed. The feature also restores any column you have moved through the drag and drop feature on the page.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-managersites-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
						</div>
						<div class="ui right aligned column">
					<input type="submit" class="ui green button" name="btnSubmit" id="submit-managersites-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
					<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
				</div>
					</div>
				</div>
				<input type="hidden" name="reset_managersites_columns_order" value="0">
			</form>
		</div>
		<div class="ui small modal" id="mainwp-manage-sites-site-preview-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Screen Options', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<span><?php esc_html_e( 'Would you like to turn on home screen previews?  This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
			</div>
			<div class="actions">
				<div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
				<div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery('#reset-managersites-settings').on( 'click', function () {
					mainwp_confirm(__( 'Are you sure.' ), function(){
						jQuery('#mainwp_sitesviewmode').dropdown( 'set selected', 'table' );
						jQuery('#submit-managersites-settings').click();
					}, false, false, true );
					return false;
				});
			} );
		</script>
		<?php
	}

	/**
	 * Prepare the items to be listed.
	 */
	public static function prepare_items() {

		$orderby = 'wp.url';

		$req_orderby = null;
		$req_order   = null;

		$perPage   = 9999;
		$start     = 0;
		$group_ids = false;

		$get_saved_params = ! isset( $_REQUEST['g'] ) && ! isset( $_REQUEST['isnot'] ) ? true : false;

		if ( $get_saved_params ) {
			$is_not    = get_user_option( 'mainwp_screenshots_filter_is_not' );
			$group_ids = get_user_option( 'mainwp_screenshots_filter_group' );
		} else {
			$is_not = isset( $_REQUEST['isnot'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['isnot'] ) ) : '';
			MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_is_not', $is_not );

			$group_ids = isset( $_REQUEST['g'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) : ''; // may be multi groups.
			MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_group', sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) );
		}

		$is_not = 'yes' == $is_not ? true : false;

		$where = null;

		$params = array(
			'selectgroups' => true,
			'orderby'      => $orderby,
			'offset'       => $start,
			'rowcount'     => $perPage,
		);

		$params['isnot'] = $is_not;

		$qry_group_ids = array();
		if ( ! empty( $group_ids ) ) {
			$group_ids = explode( ',', $group_ids ); // convert to array.
			// to fix query deleted groups.
			$groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
			foreach ( $groups as $gr ) {
				if ( in_array( $gr->id, $group_ids ) ) {
					$qry_group_ids[] = $gr->id;
				}
			}
			// to fix.
			if ( in_array( 'nogroups', $group_ids ) ) {
				$qry_group_ids[] = 'nogroups';
			}
		}

		if ( ! empty( $qry_group_ids ) ) {
			$params['group_id'] = $qry_group_ids;
		}

		if ( ! empty( $where ) ) {
			$total_params['extra_where'] = $where;
			$params['extra_where']       = $where;
		}

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $params ) );
		return $websites;
	}

}
