<?php
/**
 * Plugin Installer List Table class.
 *
 * @package WordPress
 * @subpackage List_Table
 * @since 3.1.0
 * @access private
 */
if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class MainWP_Plugins_Install_List_Table extends WP_List_Table {

	public $order = 'ASC';
	public $orderby = null;
	public $groups = array();

	private $error;

	/**
	 *
	 * @return bool
	 */
	public function ajax_user_can() {
		return current_user_can('install_plugins');
	}


	/**
	 *
	 * @global array  $tabs
	 * @global string $tab
	 * @global int    $paged
	 * @global string $type
	 * @global string $term
	 * @global string $wp_version
	 */
	public function prepare_items() {
		include( ABSPATH . 'wp-admin/includes/plugin-install.php' );

		global $tabs, $tab, $paged, $type, $term;

		wp_reset_vars( array( 'tab' ) );

		$paged = $this->get_pagenum();

		$per_page = 30;

		// These are the tabs which are shown on the page
		$tabs = array();

		if ( 'search' == $tab )
		$tabs['search']	= __( 'Search Results', 'mainwp' );
		$tabs['featured']  = _x( 'Featured', 'Plugin Installer' );
		$tabs['popular']   = _x( 'Popular', 'Plugin Installer' );
		$tabs['recommended']   = _x( 'Recommended', 'Plugin Installer' );
		//$tabs['favorites'] = _x( 'Favorites', 'Plugin Installer' );
		if ( $tab === 'beta' || false !== strpos( $GLOBALS['wp_version'], '-' ) ) {
			$tabs['beta']      = _x( 'Beta Testing', 'Plugin Installer' );
		}
		if ( current_user_can( 'upload_plugins' ) ) {
			// No longer a real tab. Here for filter compatibility.
			// Gets skipped in get_views().
			$tabs['upload'] = __( __( 'Upload Plugin', 'mainwp' ) );
		}

		$nonmenu_tabs = array( 'plugin-information' ); // Valid actions to perform which do not have a Menu item.

		//$nonmenu_tabs = apply_filters( 'install_plugins_nonmenu_tabs', $nonmenu_tabs );

		// If a non-valid menu tab has been selected, And it's not a non-menu action.
		if ( empty( $tab ) || ( !isset( $tabs[ $tab ] ) && !in_array( $tab, (array) $nonmenu_tabs ) ) )
			$tab = key( $tabs );

		$args = array(
			'page' => $paged,
			'per_page' => $per_page,
			'fields' => array(
				'last_updated' => true,
				'icons' => true,
				'active_installs' => true
			),
			// Send the locale and installed plugin slugs to the API so it can provide context-sensitive results.
			'locale' => get_locale(),
			'installed_plugins' => array(),
		);

		switch ( $tab ) {
			case 'search':
				$type = isset( $_REQUEST['type'] ) ? wp_unslash( $_REQUEST['type'] ) : 'term';
				$term = isset( $_REQUEST['s'] ) ? wp_unslash( $_REQUEST['s'] ) : '';

				switch ( $type ) {
					case 'tag':
						$args['tag'] = sanitize_title_with_dashes( $term );
						break;
					case 'term':
						$args['search'] = $term;
						break;
					case 'author':
						$args['author'] = $term;
						break;
				}

				break;

			case 'featured':
				$args['fields']['group'] = true;
				$this->orderby = 'group';
				// No break!
			case 'popular':
			case 'new':
			case 'beta':
			case 'recommended':
				$args['browse'] = $tab;
				break;

			case 'favorites':
				$user = isset( $_GET['user'] ) ? wp_unslash( $_GET['user'] ) : get_user_option( 'wporg_favorites' );
				update_user_meta( get_current_user_id(), 'wporg_favorites', $user );
				if ( $user )
					$args['user'] = $user;
				else
					$args = false;

				break;

			default:
				$args = false;
				break;
		}

		if ( !$args )
			return;

		$api = plugins_api( 'query_plugins', $args );

		if ( is_wp_error( $api ) ) {
			$this->error = $api;
			return;
		}

		$this->items = $api->plugins;

		if ( $this->orderby ) {
			uasort( $this->items, array( $this, 'order_callback' ) );
		}

		$this->set_pagination_args( array(
			'total_items' => $api->info['results'],
			'per_page' => $args['per_page'],
		) );

		if ( isset( $api->info['groups'] ) ) {
			$this->groups = $api->info['groups'];
		}
	}

	/**
	 * @access public
	 */
	public function no_items() {
		if ( isset( $this->error ) ) {
			$message = $this->error->get_error_message() . '<p class="hide-if-no-js"><a href="#" class="button" onclick="document.location.reload(); return false;">' . __( 'Try again', 'mainwp' ) . '</a></p>';
		} else {
			$message = __( 'No plugins match your request.', 'mainwp' );
		}
		echo '<div class="no-plugin-results">' . $message . '</div>';
	}

	protected function get_views() {
		global $tabs, $tab;

		$display_tabs = array();
		foreach ( (array) $tabs as $action => $text ) {
			$class = ( $action == $tab ) ? ' current' : '';
			$href = self_admin_url('admin.php?page=PluginsInstall&tab=' . $action);
			$display_tabs['plugin-install-'.$action] = "<a href='$href' class='$class'>$text</a>";
		}
		// No longer a real tab.
		unset( $display_tabs['plugin-install-upload'] );

		return $display_tabs;
	}

	
	public function views() {
		$views = $this->get_views();		

?>
<div class="wp-filter">
<h3 class="mainwp_box_title"><?php _e( 'Step 1: Select a plugin', 'mainwp' ); ?></h3>
	<ul class="filter-links">
		<?php
		if ( ! empty( $views ) ) {
			foreach ( $views as $class => $view ) {
				$views[ $class ] = "\t<li class='$class'>$view";
			}
			echo implode( " </li>\n", $views ) . "</li>\n";
		}
		?>
	</ul>

	<?php MainWP_Plugins::install_search_form( isset( $views['plugin-install-search'] ) ); ?>
</div>
<?php
	}

	/**
	 * Override the parent display() so we can provide a different container.
	 */
	public function display() {
		$singular = $this->_args['singular'];

		$data_attr = '';

		if ( $singular ) {
			$data_attr = " data-wp-lists='list:$singular'";
		}

		$this->display_tablenav( 'top' );

?>
<div class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">

	<div id="the-list"<?php echo $data_attr; ?>>
		<?php $this->display_rows_or_placeholder(); ?>
	</div>
</div>
<?php
		$this->display_tablenav( 'bottom' );
	}

	protected function display_tablenav( $which ) {
		if ( $GLOBALS['tab'] === 'featured' ) {
			return;
		}

		if ( 'top' ==  $which ) {
			wp_referer_field();
		?>
			<div class="tablenav top">
				<div class="alignleft actions">
					<?php
					/**
					 * Fires before the Plugin Install table header pagination is displayed.
					 *
					 * @since 2.7.0
					 */
					do_action( 'install_plugins_table_header' ); ?>
				</div>
				<?php $this->pagination( $which ); ?>
				<br class="clear" />
			</div>
		<?php } else { ?>
			<div class="tablenav bottom">
				<?php $this->pagination( $which ); ?>
				<br class="clear" />
			</div>
		<?php
		}
	}

	/**
	 * @return array
	 */
	protected function get_table_classes() {
		return array( 'widefat', $this->_args['plural'] );
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		return array();
	}

	private function order_callback( $plugin_a, $plugin_b ) {
		$orderby = $this->orderby;
		if ( ! isset( $plugin_a->$orderby, $plugin_b->$orderby ) ) {
			return 0;
		}

		$a = $plugin_a->$orderby;
		$b = $plugin_b->$orderby;

		if ( $a == $b ) {
			return 0;
		}

		if ( 'DESC' == $this->order ) {
			return ( $a < $b ) ? 1 : -1;
		} else {
			return ( $a < $b ) ? -1 : 1;
		}
	}

	/**
	 * @global string $wp_version
	 */
	public function display_rows() {
		$plugins_allowedtags = array(
			'a' => array( 'href' => array(),'title' => array(), 'target' => array() ),
			'abbr' => array( 'title' => array() ),'acronym' => array( 'title' => array() ),
			'code' => array(), 'pre' => array(), 'em' => array(),'strong' => array(),
			'ul' => array(), 'ol' => array(), 'li' => array(), 'p' => array(), 'br' => array()
		);

		$plugins_group_titles = array(
			'Performance' => _x( 'Performance', 'Plugin installer group title' ),
			'Social'      => _x( 'Social',      'Plugin installer group title' ),
			'Tools'       => _x( 'Tools',       'Plugin installer group title' ),
		);

		$group = null;

		foreach ( (array) $this->items as $plugin ) {
			if ( is_object( $plugin ) ) {
				$plugin = (array) $plugin;
			}

			// Display the group heading if there is one
			if ( isset( $plugin['group'] ) && $plugin['group'] != $group ) {
				if ( isset( $this->groups[ $plugin['group'] ] ) ) {
					$group_name = $this->groups[ $plugin['group'] ];
					if ( isset( $plugins_group_titles[ $group_name ] ) ) {
						$group_name = $plugins_group_titles[ $group_name ];
					}
				} else {
					$group_name = $plugin['group'];
				}

				// Starting a new group, close off the divs of the last one
				if ( ! empty( $group ) ) {
					echo '</div></div>';
				}

				echo '<div class="plugin-group"><h3>' . esc_html( $group_name ) . '</h3>';
				// needs an extra wrapping div for nth-child selectors to work
				echo '<div class="plugin-items">';

				$group = $plugin['group'];
			}
			$title = wp_kses( $plugin['name'], $plugins_allowedtags );

			// Remove any HTML from the description.
			$description = strip_tags( $plugin['short_description'] );
			$version = wp_kses( $plugin['version'], $plugins_allowedtags );

			$name = strip_tags( $title . ' ' . $version );

			$author = wp_kses( $plugin['author'], $plugins_allowedtags );
			if ( ! empty( $author ) ) {
				$author = ' <cite>' . sprintf( __( 'By %s', 'mainwp' ), $author ) . '</cite>';
			}

			$action_links = array();

			if ( current_user_can( 'install_plugins' ) || current_user_can( 'update_plugins' ) ) {
							/* translators: 1: Plugin name and version. */							
//							$action_links[] = '<a class="install-now button" href="#" id="install-plugin-' . $plugin['slug'] . '"
//                               title="Install ' . $plugin['name'] . '  ' . $plugin['version'] . '">' . __('Install Now','mainwp') . '</a>';
							$action_links[] = '<label style="font-size: 16px;"><input name="install-plugin" type="radio" id="install-plugin-' . $plugin['slug'] . '"
                               title="Install ' . $plugin['name'] . '  ' . $plugin['version'] . '">' . __('Install this Plugin','mainwp') . '</label>';			
			}

			$details_link   = self_admin_url( 'plugin-install.php?tab=plugin-information&amp;plugin=' . $plugin['slug'] .
								'&amp;TB_iframe=true&amp;width=600&amp;height=550' );

			/* translators: 1: Plugin name and version. */
			$action_links[] = '<a href="' . esc_url( $details_link ) . '" class="thickbox" aria-label="' . esc_attr( sprintf( __( 'More information about %s' ), $name ) ) . '" data-title="' . esc_attr( $name ) . '">' . __( 'More Details' ) . '</a>';

			if ( !empty( $plugin['icons']['svg'] ) ) {
				$plugin_icon_url = $plugin['icons']['svg'];
			} elseif ( !empty( $plugin['icons']['2x'] ) ) {
				$plugin_icon_url = $plugin['icons']['2x'];
			} elseif ( !empty( $plugin['icons']['1x'] ) ) {
				$plugin_icon_url = $plugin['icons']['1x'];
			} else {
				$plugin_icon_url = $plugin['icons']['default'];
			}

			$action_links = apply_filters( 'mainwp_plugin_install_action_links', $action_links, $plugin );

			$date_format = __( 'M j, Y @ H:i' );
			$last_updated_timestamp = strtotime( $plugin['last_updated'] );
		?>
		<div class="plugin-card plugin-card-<?php echo sanitize_html_class( $plugin['slug'] ); ?>">
			<div class="plugin-card-top">
				<a href="<?php echo esc_url( $details_link ); ?>" class="thickbox"><img src="<?php echo esc_attr( $plugin_icon_url ); ?>" class="plugin-icon" /></a>
				<div class="name column-name" style="margin-right: 0!important;">
					<h3><a href="<?php echo esc_url( $details_link ); ?>" class="thickbox"><?php echo $title; ?></a></h3>
				</div>
				<div class="desc column-description" style="margin-right: 0!important;">
					<p><?php echo $description; ?></p>
					<p class="authors"><?php echo $author; ?></p>
				</div>
			</div>
			<div class="plugin-card-bottom">
				<div class="vers column-rating">
					<?php wp_star_rating( array( 'rating' => $plugin['rating'], 'type' => 'percent', 'number' => $plugin['num_ratings'] ) ); ?>
					<span class="num-ratings">(<?php echo number_format_i18n( $plugin['num_ratings'] ); ?>)</span>
				</div>
				<div class="column-updated">
					<strong><?php _e( 'Last Updated:', 'mainwp' ); ?></strong> <span title="<?php echo esc_attr( date_i18n( $date_format, $last_updated_timestamp ) ); ?>">
						<?php printf( __( '%s ago', 'mainwp' ), human_time_diff( $last_updated_timestamp ) ); ?>
					</span>
				</div>
				<div class="column-downloaded">
					<?php
					if ( $plugin['active_installs'] >= 1000000 ) {
						$active_installs_text = _x( '1+ Million', 'Active plugin installs' );
					} else {
						$active_installs_text = number_format_i18n( $plugin['active_installs'] ) . '+';
					}
					printf( __( '%s Active Installs', 'mainwp' ), $active_installs_text );
					?>
				</div>
				<div class="column-compatibility">
					<?php
					if ( ! empty( $plugin['tested'] ) && version_compare( substr( $GLOBALS['wp_version'], 0, strlen( $plugin['tested'] ) ), $plugin['tested'], '>' ) ) {
						echo '<span class="compatibility-untested">' . __( 'Untested with your version of WordPress', 'mainwp' ) . '</span>';
					} elseif ( ! empty( $plugin['requires'] ) && version_compare( substr( $GLOBALS['wp_version'], 0, strlen( $plugin['requires'] ) ), $plugin['requires'], '<' ) ) {
						echo '<span class="compatibility-incompatible">' . __( '<strong>Incompatible</strong> with your version of WordPress', 'mainwp' ) . '</span>';
					} else {
						echo '<span class="compatibility-compatible">' . __( '<strong>Compatible</strong> with your version of WordPress', 'mainwp' ) . '</span>';
					}
					?>
				</div>
			</div>
			<div class="mainwp-action-lnks">
				<?php
//					echo '<span class="mainwp-more-details">' . $details_link . '</span>';
					echo '<span class="mainwp-more-details"></span>';
					if ( $action_links ) {
						echo '<ul class="plugin-action-buttons"><li>' . implode( '</li><li>', $action_links ) . '</li></ul>';
					}
				?>
				<div style="clear:both;"></div>
			</div>
		</div>
		<?php
		}

		// Close off the group divs of the last one
		if ( ! empty( $group ) ) {
			echo '</div></div>';
		}
	}
}
