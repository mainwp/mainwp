<?php
/**
 * MainWP Client Overview Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Overview
 *
 * @package MainWP\Dashboard
 */
class MainWP_Client_Overview {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * The single instance of the class
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Enabled widgets
	 *
	 * @var array $enable_widgets
	 */
	private static $enable_widgets = array(
		'overview'    => true,
		'note'        => true,
		'fields_info' => true,
		'websites'    => true,
	);

	/**
	 * Current page.
	 *
	 * @static
	 * @var string $page Current page.
	 */
	public static $page;

	/**
	 * Check if there is a session,
	 * if there isn't one create it.
	 *
	 *  @return self::singlton Overview Page Session.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Overview
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Overview constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
		add_filter( 'screen_layout_columns', array( &$this, 'on_screen_layout_columns' ), 10, 2 );
		add_action( 'mainwp_help_sidebar_content', array( &$this, 'mainwp_help_content' ) );
	}

	/**
	 * Set the number of page coumns.
	 *
	 * @param mixed $columns Number of Columns.
	 * @param mixed $screen Screen size.
	 *
	 * @return int $columns Number of desired page columns.
	 */
	public function on_screen_layout_columns( $columns, $screen ) {
		if ( $screen == self::$page ) {
			$columns[ self::$page ] = 3;
		}

		return $columns;
	}


	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 *
	 * @param mixed $page Page name.
	 */
	public static function on_load_page( $page ) {

		self::$page = $page;

		$val = get_user_option( 'screen_layout_' . $page );
		if ( ! $val ) {
			global $current_user;
			update_user_option( $current_user->ID, 'screen_layout_' . $page, 2, true );
		}

		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'widgets' );

		self::add_meta_boxes( $page );
		add_filter( 'mainwp_header_actions_right', array( self::get_class_name(), 'screen_options' ), 10, 2 );
	}

	/**
	 * Method screen_options()
	 *
	 * Create Screen Options button.
	 *
	 * @param mixed $input Screen options button HTML.
	 *
	 * @return mixed Screen sptions button.
	 */
	public static function screen_options( $input ) {
		return $input .
				'<a class="ui button basic icon" onclick="mainwp_clients_overview_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Screen Options', 'mainwp' ) . '">
					<i class="cog icon"></i>
				</a>';
	}

	/**
	 * Method add_meta_boxes()
	 *
	 * Add MainWP Overview Page Widgets.
	 *
	 * @param array $page Current page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Handler::apply_filters()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_UI::add_widget_box()
	 * @uses \MainWP\Dashboard\MainWP_Connection_Status::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Recent_Pages::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Security_Issues_Widget::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Overview::get_class_name()
	 */
	public static function add_meta_boxes( $page ) { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Get getmetaboxes
		 *
		 * Adds metaboxes (widgets) to the Overview page.
		 *
		 * @since 4.3
		 */
		$extMetaBoxs = array();
		$extMetaBoxs = apply_filters( 'mainwp_clients_getmetaboxes', $extMetaBoxs );
		foreach ( $extMetaBoxs as $box ) {
			if ( isset( $box['plugin'] ) ) {
				$name                          = basename( $box['plugin'], '.php' );
				self::$enable_widgets[ $name ] = true;
			}
		}

		$client_contacts = array();
		if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) {
			$client_contacts = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'client_id', $_GET['client_id'], ARRAY_A );
		}

		if ( is_array( $client_contacts ) ) {
			foreach ( $client_contacts as $contact ) {
				self::$enable_widgets[ 'contact_' . $contact['contact_id'] ] = true;
			}
		}
		$values = self::$enable_widgets;

		/**
		 * Unset unwanted Widgets
		 *
		 * Contains the list of enabled widgets and allows user to unset unwanted widgets.
		 *
		 * @param array $values           Array containing enabled widgets.
		 * @param int   $dashboard_siteid Child site (Overview) ID.
		 *
		 * @since 4.3
		 */
		$values               = apply_filters( 'mainwp_clients_overview_enabled_widgets', $values, null );
		self::$enable_widgets = array_merge( self::$enable_widgets, $values );

		// Load the Updates Overview widget.
		if ( self::$enable_widgets['overview'] ) {
			MainWP_UI::add_widget_box( 'overview', array( MainWP_Client_Overview_Info::get_class_name(), 'render' ), $page, 'left', __( 'Overview', 'mainwp' ) );
		}

		// Load the Updates Overview widget.
		if ( self::$enable_widgets['note'] ) {
			MainWP_UI::add_widget_box( 'note', array( MainWP_Client_Overview_Note::get_class_name(), 'render' ), $page, 'left', __( 'Notes', 'mainwp' ) );
		}

		// Load the Updates Overview widget.
		if ( self::$enable_widgets['fields_info'] ) {
			MainWP_UI::add_widget_box( 'fields_info', array( MainWP_Client_Overview_Custom_Info::get_class_name(), 'render' ), $page, 'right', __( 'Addition Info', 'mainwp' ) );
		}

		// Load the Updates Overview widget.
		if ( self::$enable_widgets['websites'] ) {
			MainWP_UI::add_widget_box( 'websites', array( MainWP_Client_Overview_Sites::get_class_name(), 'render' ), $page, 'right', __( 'Websites', 'mainwp' ) );
		}

		if ( is_array( $client_contacts ) ) {
			foreach ( $client_contacts as $contact ) {
				if ( isset( self::$enable_widgets[ 'contact_' . $contact['contact_id'] ] ) && self::$enable_widgets[ 'contact_' . $contact['contact_id'] ] ) {
					$contact_widget          = new MainWP_Client_Overview_Contacts();
					$contact_widget->contact = $contact;
					MainWP_UI::add_widget_box( 'contact_' . $contact['contact_id'], array( $contact_widget, 'render' ), $page, 'left', __( 'Contacts', 'mainwp' ) );
				}
			}
		}

		$i = 1;
		foreach ( $extMetaBoxs as $metaBox ) {
			$enabled = true;
			if ( isset( $metaBox['plugin'] ) ) {
				$name = basename( $metaBox['plugin'], '.php' );
				if ( isset( self::$enable_widgets[ $name ] ) && ! self::$enable_widgets[ $name ] ) {
					$enabled = false;
				}
			}

			$id = isset( $metaBox['id'] ) ? $metaBox['id'] : $i++;
			$id = 'advanced-' . $id;

			if ( $enabled ) {
				MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, 'right', $metaBox['metabox_title'] );
			}
		}
	}

	/**
	 * Method on_show_page()
	 *
	 * When the page loads render the body content.
	 */
	public function on_show_page() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'access_client_dashboard' ) ) {
			mainwp_do_not_have_permissions( __( 'client dashboard', 'mainwp' ) );
			return;
		}

		/**
		 * Screen layout columns array.
		 *
		 * @global object
		 */
		global $screen_layout_columns;

		MainWP_Client::render_header( 'overview' );

		self::render_dashboard_body();
		?>
		</div>
		<?php
	}

	/**
	 * Method render_dashboard_body()
	 *
	 * Render the Dashboard Body content.
	 */
	public static function render_dashboard_body() {
		$screen   = get_current_screen();
		$clientid = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0;
		?>
		<div class="mainwp-primary-content-wrap">
		<div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'client-widgets' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="client-widgets"></i>
					<?php echo sprintf( __( 'To hide or show a widget, click the Cog (<i class="cog icon"></i>) icon or go to the %1$sMainWP Settings%2$s page and select options from "Show widgets"', 'mainwp' ), '<a href="admin.php?page=Settings">', '</a>' ); ?>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Action: mainwp_before_overview_widgets
			 *
			 * Fires at the top of the Overview page (before first widget).
			 *
			 * @since 4.3
			 */
			do_action( 'mainwp_before_overview_widgets' );

			$overviewColumns = get_option( 'mainwp_number_clients_overview_columns', 2 );

			$cls_grid = 'two';
			if ( 3 == $overviewColumns ) {
				$cls_grid = 'three';
			}
			?>
			<div class="ui <?php echo $cls_grid; ?> column tablet stackable grid mainwp-grid-wrapper">
		<div class="column grid-item" id="mainwp-grid-left" widget-context="left">
						<?php
						/**
						 * Action: mainwp_clients_overview_before_left_column
						 *
						 * Fires at the top of the left column on the Overview page.
						 *
						 * @since 4.3
						 */
						do_action( 'mainwp_clients_overview_before_left_column' );
						MainWP_UI::do_widget_boxes( $screen->id, 'left' );
						/**
						 * Action: mainwp_clients_overview_after_left_column
						 *
						 * Fires at the bottom of the left column on the Overview page.
						 *
						 * @since 4.3
						 */
						do_action( 'mainwp_clients_overview_after_left_column' );
						?>
		</div>
		<?php if ( 3 == $overviewColumns ) : ?>
		<div class="column grid-item" id="mainwp-grid-middle" widget-context="middle">
						<?php
						/**
						 * Action: mainwp_clients_overview_before_middle_column
						 *
						 * Fires at the top of the middle column on the Overview page.
						 *
						 * @since 4.3
						 */
						do_action( 'mainwp_clients_overview_before_middle_column' );
						MainWP_UI::do_widget_boxes( $screen->id, 'middle' );
						/**
						 * Action: mainwp_clients_overview_after_middle_column
						 *
						 * Fires at the bottom of the middle column on the Overview page.
						 *
						 * @since 4.3
						 */
						do_action( 'mainwp_clients_overview_after_middle_column' );
						?>
		</div>
		<?php endif; ?>
		<div class="column grid-item" id="mainwp-grid-right" widget-context="right">
						<?php
						/**
						 * Action: mainwp_clients_overview_before_right_column
						 *
						 * Fires at the top of the right column on the Overview page.
						 *
						 * @since 4.3
						 */
						do_action( 'mainwp_clients_overview_before_right_column' );
						MainWP_UI::do_widget_boxes( $screen->id, 'right' );
						/**
						 * Action: mainwp_clients_overview_after_right_column
						 *
						 * Fires at the bottom of the right column on the Overview page.
						 *
						 * @since 4.3
						 */
						do_action( 'mainwp_clients_overview_after_right_column' );
						?>
		</div>
			</div>
			<?php
			/**
			 * Action: 'mainwp_after_overview_widgets'
			 *
			 * Fires at the bottom of the Overview page (after the last widget).
			 *
			 * @since 4.3
			 */
			do_action( 'mainwp_after_overview_widgets' );
			?>
	<script type="text/javascript">
		var page_sortablewidgets = '<?php echo esc_js( MainWP_System_Utility::get_page_id( $screen->id ) ); ?>';
		jQuery( document ).ready( function( $ ) {

			var $mainwp_drake = dragula( [document.getElementById( 'mainwp-grid-left' ),
			<?php
			if ( 3 == $overviewColumns ) {
				?>
				document.getElementById( 'mainwp-grid-middle' ), 
			<?php }; ?> 
				document.getElementById( 'mainwp-grid-right' )], {
				moves: function ( el, container, handle ) {
					return handle.classList.contains( 'handle-drag' );
				}
			} );

			$mainwp_drake.on( 'drop', function ( el, target, source, sibling ) {
				var conts = $mainwp_drake.containers;
				var order = new Array();
				for ( var i = 0; i < conts.length; i++ ) {
					var context = jQuery( conts[i] ).attr( 'widget-context' );
					if ( undefined === context || '' == context )
						continue;
					var searchEles = conts[i].children;
					for( var idx = 0; idx < searchEles.length; idx++ ) {
						var itemElem = searchEles[idx];
						var wid = $( itemElem ).attr( 'id' );
						wid = wid.replace( "widget-", "" );
						order.push( context + ":" + wid );
					}
				}

				var postVars = {
					action:'mainwp_widgets_order',
					page: page_sortablewidgets,
					item_id: <?php echo intval( $clientid ); ?>
				};
				postVars['order'] = order.join( ',' );
				jQuery.post( ajaxurl, mainwp_secure_data( postVars ), function ( res ) {
				} );

			} );
			jQuery( '.mainwp-widget .mainwp-dropdown-tab .item' ).tab();

			mainwp_clients_overview_screen_options = function () {
				jQuery( '#mainwp-clients-overview-screen-options-modal' ).modal( {
					allowMultiple: true,
					onHide: function () {
					}
				} ).modal( 'show' );
				return false;
			};
			jQuery('#reset-clients-overview-settings').on('click', function () {
				mainwp_confirm(__('Are you sure.'), function(){
					jQuery('input[name=number_overview_columns][value=2]').prop('checked', true);
					jQuery('.mainwp_hide_wpmenu_checkboxes input[name="mainwp_show_widgets[]"]').prop('checked', true);
					jQuery('input[name=reset_client_overview_settings]').attr('value', 1);
					jQuery('#submit-client-overview-settings').click();
				}, false, false, true);
				return false;
			});
		} );
	</script>
	<div class="ui modal" id="mainwp-clients-overview-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Screen Options', 'mainwp' ); ?></div>
			<div class="content ui form">
				<?php
				/**
				 * Action: mainwp_clients_overview_screen_options_top
				 *
				 * Fires at the top of the Sceen Options modal on the Overview page.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_clients_overview_screen_options_top' );
				?>
				<form method="POST" action="" name="mainwp_clients_overview_screen_options_form" id="mainwp-clients-overview-screen-options-form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_scr_options_nonce" value="<?php echo wp_create_nonce( 'MainWPClientsScrOptions' ); ?>" />
					<?php echo self::render_screen_options( false ); ?>
					<?php
					/**
					 * Action: mainwp_clients_overview_screen_options_bottom
					 *
					 * Fires at the bottom of the Sceen Options modal on the Overview page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_clients_overview_screen_options_bottom' );
					?>
			</div>
			<div class="actions">
				<div class="ui two columns grid">
					<div class="left aligned column">
						<span data-tooltip="<?php esc_attr_e( 'Returns this page to the state it was in when installed. The feature also restores any widgets you have moved through the drag and drop feature on the page.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><input type="button" class="ui button" name="reset" id="reset-clients-overview-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
					</div>
					<div class="ui right aligned column">
						<input type="submit" class="ui green button" id="submit-client-overview-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
						<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
					</div>
				</div>
			</div>

			<input type="hidden" name="reset_client_overview_settings" value="" />
			</form>
		</div>

		<?php
	}

	/**
	 * Method render_screen_options()
	 *
	 * Render Screen Options.
	 *
	 * @return void  Render Screen Options html.
	 */
	public static function render_screen_options() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$default_widgets = array(
			'overview'    => __( 'Overview', 'mainwp' ),
			'note'        => __( 'Notes', 'mainwp' ),
			'fields_info' => __( 'Addition Info', 'mainwp' ),
			'websites'    => __( 'Websites', 'mainwp' ),
		);

		if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) {
			$client_contacts = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'client_id', $_GET['client_id'], ARRAY_A );
			if ( $client_contacts ) {
				foreach ( $client_contacts as $contact ) {
					$default_widgets[ 'contact_' . $contact['contact_id'] ] = esc_html( $contact['contact_name'] );

				}
			}
		}

		$custom_opts = array();
		/**
		 * Filter: mainwp_clients_widgets_screen_options
		 *
		 * Filters available widgets on the Overview page allowing users to unsent unwanted widgets.
		 *
		 * @since 4.0
		 */
		$custom_opts = apply_filters( 'mainwp_clients_widgets_screen_options', $custom_opts );

		if ( is_array( $custom_opts ) && 0 < count( $custom_opts ) ) {
			$default_widgets = array_merge( $default_widgets, $custom_opts );
		}

		$show_widgets = get_user_option( 'mainwp_clients_show_widgets' );
		if ( ! is_array( $show_widgets ) ) {
			$show_widgets = array();
		}

		/**
		 * Action: mainwp_screen_options_modal_top
		 *
		 * Fires at the top of the Screen Options modal element.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_screen_options_modal_top' );
		$which_settings = 'overview_settings';
		?>
		<?php if ( isset( $_GET['page'] ) ) : ?>
			<?php
			$overviewColumns = get_option( 'mainwp_number_clients_overview_columns', 2 );
			if ( 2 != $overviewColumns && 3 != $overviewColumns ) {
				$overviewColumns = 2;
			}

			?>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Widgets columns', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<div class="ui radio checkbox">
					<input type="radio" name="number_overview_columns" required="required" <?php echo ( 2 == $overviewColumns ? 'checked="true"' : '' ); ?> value="2">
					<label><?php esc_html_e( 'Show widgets in 2 columns', 'mainwp' ); ?></label>
				</div>
					<div class="ui fitted hidden divider"></div>
				<div class="ui radio checkbox">
					<input type="radio" name="number_overview_columns" required="required" <?php echo ( 3 == $overviewColumns ? 'checked="true"' : '' ); ?> value="3">
					<label><?php esc_html_e( 'Show widgets in 3 columns', 'mainwp' ); ?></label>
				</div>
			</div>
		</div>

		<div class="ui grid field">
			<label class="six wide column"><?php esc_html_e( 'Show widgets', 'mainwp' ); ?></label>
			<div class="ten wide column" <?php echo 'data-tooltip="' . esc_attr_e( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ); ?> data-inverted="" data-position="top left">
				<ul class="mainwp_hide_wpmenu_checkboxes">
				<?php
				foreach ( $default_widgets as $name => $title ) {
					$_selected = '';
					if ( ! isset( $show_widgets[ $name ] ) || 1 == $show_widgets[ $name ] ) {
						$_selected = 'checked';
					}
					?>
					<li>
						<div class="ui checkbox">
							<input type="checkbox" id="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" name="mainwp_show_widgets[]" <?php echo $_selected; ?> value="<?php echo esc_attr( $name ); ?>">
							<label for="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
						</div>
						<input type="hidden" name="mainwp_widgets_name[]" value="<?php echo esc_attr( $name ); ?>">
					</li>
					<?php
				}
				?>
				</ul>
			</div>
		</div>
		<?php endif; ?>
		<?php
		/**
		 * Action: mainwp_screen_options_modal_bottom
		 *
		 * Fires at the bottom of the Screen Options modal element.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_screen_options_modal_bottom' );
	}


	/**
	 * Method mainwp_help_content()
	 *
	 * Hook the section help content to the Help Sidebar element
	 *
	 * @return void
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'mainwp_tab' === $_GET['page'] ) {
			?>
			<p><?php esc_html_e( 'If you need help with your MainWP Dashboard, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://kb.mainwp.com/docs/understanding-mainwp-dashboard-user-interface/" target="_blank">Understanding MainWP Dashboard UI</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/mainwp-navigation/" target="_blank">MainWP Navigation</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/screen-options/" target="_blank">Screen Options</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/mainwp-dashboard/" target="_blank">MainWP Dashboard</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/mainwp-tables/" target="_blank">MainWP Tables</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/individual-child-site-mode/" target="_blank">Individual Child Site Mode</a></div>
				<?php
				/**
				 * Action: mainwp_clients_overview_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Overview page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.3
				 */
				do_action( 'mainwp_clients_overview_help_item' );
				?>
			</div>
			<?php
		}
	}

}
