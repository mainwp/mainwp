<?php
/**
 * MainWP UI Select Sites.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_UI_Select_Sites
 *
 * @package MainWP\Dashboard
 */
class MainWP_UI_Select_Sites {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method select_sites_box()
	 *
	 * Select sites box.
	 *
	 * @param array $params {
	 *  An array of params for sites selection. Default empty array.
	 *  @type string  $type Input type, radio.
	 *  @type bool    $show_group Whether or not to show group, Default: true.
	 *  @type bool    $show_select_all Whether to show select all.
	 *  @type string  $class Default = ''.
	 *  @type string  $style Default = ''.
	 *  @type array   $selected_sites Selected Child Sites.
	 *  @type array   $selected_groups Selected Groups.
	 *  @type bool    $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
	 *  @type integer $postId Post Meta ID.
	 *  @type bool    $show_client (bool) True, if show clients. False if not.
	 *  @type bool    $enable_suspended_clients (bool) True, if enable suspended clients. False if not.
	 *  @type array   $selected_clients Selected Clients.
	 * }
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::maybe_unserialyze()
	 */
	public static function select_sites_box( $params = array() ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$type                   = isset( $params['type'] ) ? $params['type'] : 'checkbox';
		$show_group             = isset( $params['show_group'] ) ? $params['show_group'] : true;
		$show_select_all        = isset( $params['show_select_all'] ) ? $params['show_select_all'] : true;
		$show_selectall_disc    = isset( $params['show_select_all_disconnect'] ) ? $params['show_select_all_disconnect'] : false;
		$show_new_tag           = isset( $params['show_create_tag'] ) ? $params['show_create_tag'] : true;
		$class                  = isset( $params['class'] ) ? $params['class'] : '';
		$style                  = isset( $params['style'] ) ? $params['style'] : '';
		$selected_sites         = isset( $params['selected_sites'] ) ? $params['selected_sites'] : array();
		$selected_groups        = isset( $params['selected_groups'] ) ? $params['selected_groups'] : array();
		$enableOfflineSites     = isset( $params['enable_offline_sites'] ) ? $params['enable_offline_sites'] : false;
		$postId                 = isset( $params['post_id'] ) ? $params['post_id'] : 0;
		$show_client            = isset( $params['show_client'] ) ? $params['show_client'] : false;
		$enableSuspendedClients = isset( $params['enable_suspended_clients'] ) ? $params['enable_suspended_clients'] : false;
		$selected_clients       = isset( $params['selected_clients'] ) ? $params['selected_clients'] : array();
		$selected_clients       = isset( $params['selected_clients'] ) ? $params['selected_clients'] : array();
		$add_edit_client_id     = isset( $params['add_edit_client_id'] ) ? $params['add_edit_client_id'] : false;

		if ( $postId ) {

			$sites_val      = get_post_meta( $postId, '_selected_sites', true );
			$selected_sites = MainWP_System_Utility::maybe_unserialyze( $sites_val );

			if ( empty( $selected_sites ) ) {
				$selected_sites = array();
			}

			$groups_val      = get_post_meta( $postId, '_selected_groups', true );
			$selected_groups = MainWP_System_Utility::maybe_unserialyze( $groups_val );

			if ( empty( $selected_groups ) ) {
				$selected_groups = array();
			}

			$selected_clients = get_post_meta( $postId, '_selected_clients', true );

			if ( empty( $selected_clients ) ) {
				$selected_clients = array();
			}
		}

		if ( empty( $selected_sites ) && isset( $_GET['selected_sites'] ) && ! empty( $_GET['selected_sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$selected_sites = explode( '-', sanitize_text_field( wp_unslash( $_GET['selected_sites'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize ok.
			$selected_sites = array_map( 'intval', $selected_sites );
			$selected_sites = array_filter( $selected_sites );
		}

		/**
		 * Action: mainwp_before_seclect_sites
		 *
		 * Fires before the Select Sites box.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_seclect_sites' );
		$params = array(
			'type'                       => $type,
			'show_group'                 => $show_group,
			'show_select_all'            => $show_select_all,
			'show_select_all_disconnect' => $show_selectall_disc,
			'show_create_tag'            => $show_new_tag,
			'selected_sites'             => $selected_sites,
			'selected_groups'            => $selected_groups,
			'enable_offline_sites'       => $enableOfflineSites,
			'post_id'                    => $postId,
			'show_client'                => $show_client,
			'enable_suspended_clients'   => $enableSuspendedClients,
			'selected_clients'           => $selected_clients,
			'add_edit_client_id'         => $add_edit_client_id,
		);
		?>
		<div id="mainwp-select-sites" class="mainwp_select_sites_wrapper">
		<?php self::select_sites_box_body( $params ); ?>
	</div>
		<?php
		/**
		 * Action: mainwp_after_seclect_sites
		 *
		 * Fires after the Select Sites box.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_seclect_sites' );
	}

	/**
	 * Method select_sites_box_body()
	 *
	 * Select sites box Body.
	 *
	 *  @param array $params {
	 *
	 *  An array of params for sites selection. Default empty array.
	 *
	 *  @type string  $type Input type, radio.
	 *  @type bool    $show_group Whether or not to show group, Default: true.
	 *  @type bool    $show_select_all Whether to show select all.
	 *  @type array   $selected_sites Selected Child Sites.
	 *  @type array   $selected_groups Selected Groups.
	 *  @type bool    $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
	 *  @type integer $postId Post Meta ID.
	 *  @type bool    $show_client (bool) True, if show clients. False if not.
	 *  @type array  $selected_clients Selected clients.
	 *  @type bool   $enable_suspended_clients (bool) True, if suspended clients is enabled. False if not.
	 * }
	 */
	public static function select_sites_box_body( $params = array() ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$type                   = isset( $params['type'] ) ? $params['type'] : 'checkbox';
		$show_group             = isset( $params['show_group'] ) ? $params['show_group'] : true;
		$show_select_all        = isset( $params['show_select_all'] ) ? $params['show_select_all'] : true;
		$show_selectall_disc    = isset( $params['show_select_all_disconnect'] ) ? $params['show_select_all_disconnect'] : false;
		$show_new_tag           = isset( $params['show_create_tag'] ) ? $params['show_create_tag'] : true;
		$selected_sites         = isset( $params['selected_sites'] ) ? $params['selected_sites'] : array();
		$selected_groups        = isset( $params['selected_groups'] ) ? $params['selected_groups'] : array();
		$enableOfflineSites     = isset( $params['enable_offline_sites'] ) ? $params['enable_offline_sites'] : false;
		$postId                 = isset( $params['post_id'] ) ? $params['post_id'] : 0;
		$show_client            = isset( $params['show_client'] ) ? $params['show_client'] : false;
		$enableSuspendedClients = isset( $params['enable_suspended_clients'] ) ? $params['enable_suspended_clients'] : false;
		$selected_clients       = isset( $params['selected_clients'] ) ? $params['selected_clients'] : array();
		$add_edit_client_id     = isset( $params['add_edit_client_id'] ) ? $params['add_edit_client_id'] : false;

		if ( 'all' !== $selected_sites && ! is_array( $selected_sites ) ) {
			$selected_sites = array();
		}

		if ( ! is_array( $selected_groups ) ) {
			$selected_groups = array();
		}

		$selectedby = 'site';
		if ( ! empty( $selected_groups ) ) {
			$selectedby = 'group';
		} elseif ( ! empty( $selected_clients ) ) {
			$selectedby = 'client';
		}

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		$groups   = MainWP_DB_Common::instance()->get_not_empty_groups( null, $enableOfflineSites );

		// support staging extension.
		$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );

		$edit_site_id = false;
		if ( $postId ) {
			$edit_site_id = get_post_meta( $postId, '_mainwp_edit_post_site_id', true );
			$edit_site_id = intval( $edit_site_id );
		}

		if ( $edit_site_id ) {
			$show_group = false;
		}
		// to fix layout with multi sites selector.
		$tab_id = wp_rand();

		MainWP_UI::render_select_sites_header( $tab_id, $staging_enabled, $selectedby, $show_group, $show_client );

		$select_all_disconnected = '';

		if ( $show_selectall_disc ) {
			$select_all_disconnected  = '<div onClick="return mainwp_ss_select_disconnected( this, true )" class="mainwp-ss-select-disconnected"><i class="square outline icon"></i> ' . esc_attr__( 'Select All Disconnected', 'mainwp' ) . '</div>';
			$select_all_disconnected .= '<div onClick="return mainwp_ss_select_disconnected( this, false )" class="mainwp-ss-deselect-disconnected" style="display:none;padding-top:0;"><i class="check square outline icon"></i> ' . esc_attr__( 'Deselect All Disconnected', 'mainwp' ) . '</div>';
		}

		if ( $show_select_all || $show_selectall_disc || $show_new_tag ) :
			?>
			<div id="mainwp-select-sites-select-all-actions" class="ui two columns grid">
				<div class="ui middle aligned column">
				<?php if ( $show_select_all ) : ?>	
				<div onClick="return mainwp_ss_select( this, true )" class="mainwp-ss-select"><i class="square outline icon"></i> <?php esc_attr_e( 'Select All', 'mainwp' ); ?></div>
				<div onClick="return mainwp_ss_select( this, false )" class="mainwp-ss-deselect" style="display:none;padding-top:0;"><i class="check square outline icon"></i> <?php esc_attr_e( 'Deselect All', 'mainwp' ); ?></div>
				<?php endif; ?>
				<?php echo $select_all_disconnected; //phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
				<div class="ui right aligned middle aligned column">
					<?php if ( $show_new_tag ) { ?>
					<a class="ui mini basic icon button" href="javascript:void(0)" id="mainwp-create-new-tag-button" data-tooltip="<?php esc_attr_e( 'Create a tag with selected sites.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="tag icon"></i></a>
					<?php } ?>
				</div>
			</div>
			<div class="ui hidden divider"></div>
			<?php
			endif;
		?>
		<div class="ui tab <?php echo 'site' === $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-sites-<?php echo esc_attr( $tab_id ); ?>" id="mainwp-select-sites">
		<?php
			MainWP_UI::render_select_sites( $websites, $type, $selected_sites, $enableOfflineSites, $edit_site_id, $show_select_all, $add_edit_client_id, $show_selectall_disc );
		?>
		</div>
		<?php if ( $staging_enabled ) { ?>
			<div class="ui tab <?php echo 'staging' === $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-staging-sites-<?php echo esc_attr( $tab_id ); ?>">
				<?php
					MainWP_UI::render_select_sites_staging( $selected_sites, $edit_site_id, $type );
				?>
			</div>
			<?php
		}

		if ( $show_group ) {
			?>
			<div class="ui tab <?php echo 'group' === $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-groups-<?php echo esc_attr( $tab_id ); ?>" id="mainwp-select-groups">
			<?php
				MainWP_UI::render_select_sites_group( $groups, $selected_groups, $type );
			?>
			</div>
			<?php
		}

		if ( $show_client ) {
			$clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
			$params  = array(
				'clients'                  => $clients,
				'type'                     => $type,
				'selected_clients'         => $selected_clients,
				'enable_suspended_clients' => $enableSuspendedClients,
			);
			?>
			<div class="ui tab <?php echo 'client' === $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-clients-<?php echo esc_attr( $tab_id ); ?>" id="mainwp-select-clients">
			<?php
			self::render_select_clients( $params );
			?>
			</div>
			<?php
		}

		if ( $show_new_tag ) {
			self::render_create_tag_modal();
		}
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function () {
			jQuery('#mainwp-select-sites-header .ui.menu .item').tab( {'onVisible': function() { mainwp_sites_selection_onvisible_callback( this ); } } );
			jQuery( '#mainwp-create-new-tag-button' ).on( 'click', function() {
				jQuery( '#mainwp-create-group-sites-modal' ).modal( {
					onHide: function () {
						window.location.href = location.href;
						return false;
					},
				} ).modal( 'show' );
			} );
			// Create a new group (Select Sites UI)
			jQuery( document ).on( 'click', '#mainwp-save-new-tag-button', function () {
				var newName = jQuery( '#mainwp-create-group-sites-modal' ).find( '#mainwp-group-name' ).val().trim();
				var newColor = jQuery( '#mainwp-create-group-sites-modal' ).find( '#mainwp-group-color' ).val();
				if('' == newName ){
					return false;
				}
				jQuery(this).attr('disabled', 'disabled');
				var selected_sites = [ ];
				jQuery( "input[name='selected_sites[]']:checked" ).each( function () {
					selected_sites.push( jQuery( this ).val() );
				} );
				var data = mainwp_secure_data( {
					action: 'mainwp_group_sites_add',
					selected_sites: selected_sites,
					newName: newName,
					newColor: newColor
				} );
				jQuery.post( ajaxurl, data, function ( response ) {
					try {
						if ( response.error != undefined ){
							jQuery('#mainwp-message-zone-tag').show().find('.ui.message').html(response.error);
							return;
						}
					} catch ( err ) {
						// to fix js error.
					}
					jQuery( '#mainwp-create-group-sites-modal' ).modal( 'hide' );
				}, 'json' );
				return false;
			} );
		} );
		</script>
			<?php
	}

		/**
		 * Method render_select_clients()
		 *
		 *  @param array $params {
		 *
		 *  An array of params for clients selection. Default empty array.
		 *
		 * @type object $clients Object containing Clients info.
		 * @type string $type Selector type.
		 * @type array  $selected_clients Selected clients.
		 * @type bool   $enable_suspended_clients (bool) True, if suspended clients is enabled. False if not.
		 * @type mixed  $tab_id Datatab ID.
		 * }
		 *
		 * @return void Render Select Clients html.
		 */
	public static function render_select_clients( $params = array() ) {

		$clients                = isset( $params['clients'] ) ? $params['clients'] : array();
		$type                   = isset( $params['type'] ) ? $params['type'] : 'checkbox';
		$selected_clients       = isset( $params['selected_clients'] ) ? $params['selected_clients'] : array();
		$enableSuspendedClients = isset( $params['enable_suspended_clients'] ) ? $params['enable_suspended_clients'] : false;

		if ( ! is_array( $clients ) ) {
			$clients = array();
		}

		if ( ! is_array( $selected_clients ) && ( 'all' !== $selected_clients ) ) {
			$selected_clients = array();
		}

		/**
		 * Action: mainwp_before_select_clients_list
		 *
		 * Fires before the Select Clients list.
		 *
		 * @param object $clients Object containing Clients info.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_select_clients_list', $clients );
		?>
			<div id="mainwp-select-sites-body">
				<div class="ui relaxed divided list" id="mainwp-select-clients-list">
				<?php if ( ! $clients ) : ?>
						<h2 class="ui icon header">
							<i class="folder open outline icon"></i>
							<div class="content"><?php esc_html_e( 'No Clients!', 'mainwp' ); ?></div>
							<div class="ui divider hidden"></div>
							<a href="admin.php?page=ClientAddNew" class="ui green button basic"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></a>
						</h2>
						<?php
						else :
							foreach ( $clients as $client ) {
								$selected = false;
								if ( 0 === (int) $client->suspended || $enableSuspendedClients ) {
									$selected = ( 'all' === $selected_clients || in_array( $client->client_id, $selected_clients ) );
									?>
									<div title="<?php echo esc_html( $client->name ); ?>" class="mainwp_selected_clients_item ui <?php echo esc_html( $type ); ?> item <?php echo ( $selected ? 'selected_clients_item_checked' : '' ); ?>">
										<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo ( 'radio' === $type ? 'selected_clients' : 'selected_clients[]' ); ?>" siteid="<?php echo intval( $client->client_id ); ?>" value="<?php echo intval( $client->client_id ); ?>" id="selected_clients_<?php echo intval( $client->client_id ); ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
										<label for="selected_clients_<?php echo intval( $client->client_id ); ?>">
											<span class="client-contact-name"><?php echo esc_html( $client->name ); ?></span>
										</label>
									</div>
									<?php
								} else {
									?>
								<div title="<?php echo esc_html( $client->name ); ?>" class="mainwp_selected_clients_item item ui <?php echo esc_html( $type ); ?> <?php echo ( $selected ? 'selected_clients_item_checked' : '' ); ?>">
									<input type="<?php echo esc_html( $type ); ?>" disabled="disabled"/>
									<label for="selected_clients_<?php echo intval( $client->client_id ); ?>">
										<span class="client-contact-name"><?php echo esc_html( stripslashes( $client->name ) ); ?></span>
									</label>
								</div>
									<?php
								}
							}
						endif;
						?>
				</div>
			</div>
			<?php
			/**
			 * Action: mainwp_after_select_clients_list
			 *
			 * Fires after the Select Clients list.
			 *
			 * @param object $clients Object containing Clients info.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_select_clients_list', $clients );
	}

	/**
	 * Method render_create_tag_modal()
	 *
	 * Renders the Create Tag modal.
	 */
	public static function render_create_tag_modal() {
		?>
		<div class="ui mini modal" id="mainwp-create-group-sites-modal">
		<i class="close icon"></i>
			<div class="header"><?php echo esc_html__( 'Create Tag', 'mainwp' ); ?></div>
				<div class="content">
					<div id="mainwp-message-zone-tag" style="display: none;">
						<div class="ui message red"></div>
					</div>					
					<div class="ui form">
						<div class="field">
							<label><?php esc_html_e( 'Enter tag name', 'mainwp' ); ?></label>
							<input type="text" value="" name="mainwp-group-name" id="mainwp-group-name">
						</div>
						<div class="field">
							<label><?php esc_html_e( 'Select tag color', 'mainwp' ); ?></label>
							<input type="text" name="mainwp-group-color" class="mainwp-tag-color-picker" id="mainwp-group-color"  value="" />
						</div>
					</div>
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							
						</div>
						<div class="right aligned column">
						<a class="ui green button" id="mainwp-save-new-tag-button" href="javascript:void(0);"><?php echo esc_html__( 'Create Tag', 'mainwp' ); ?></a>
						</div>
					</div>
				</div>
				<style>
					.mainwp-ui .ui.modal .wp-picker-clear {
						display:none;
					}
					.mainwp-ui .ui.modal #mainwp-group-color {
						height: 28px;
						margin-left: 5px;
					}
				</style>
				<script type="text/javascript">
					jQuery( document ).ready( function() {
						jQuery('.mainwp-tag-color-picker').wpColorPicker({
							hide: true,
							clear: false,
							palettes: [ '#18a4e0','#0253b3','#7fb100','#446200','#ad0000','#ffd300','#2d3b44','#6435c9','#e03997','#00b5ad' ],
						});
					} );
				</script>
			</div>
		<?php
	}
}
