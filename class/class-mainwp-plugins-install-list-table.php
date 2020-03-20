<?php
/**
 * Plugin Installer List Table class.
 */

// Include class-wp-list-table.php.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Plugin Installer List Table class.
 *
 * @package WordPress
 * @subpackage List_Table
 * @since 3.1.0
 * @access private
 */
class MainWP_Plugins_Install_List_Table extends WP_List_Table {

	public $order   = 'ASC';
	public $orderby = null;
	public $groups  = array();
	private $error;

	/**
	 *
	 * @return bool
	 */
	public function ajax_user_can() {
		return current_user_can( 'install_plugins' );
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
		include ABSPATH . 'wp-admin/includes/plugin-install.php';

		global $tab; // , $paged, $type, $term;

		wp_reset_vars( array( 'tab' ) );

		$paged = $this->get_pagenum();

		$per_page = 40;

		// These are the tabs which are shown on the page
		$tabs = array();

		if ( 'search' == $tab ) {
			$tabs['search'] = __( 'Search Results', 'mainwp' );
		}
		$tabs['featured']    = _x( 'Featured', 'Plugin Installer' );
		$tabs['popular']     = _x( 'Popular', 'Plugin Installer' );
		$tabs['recommended'] = _x( 'Recommended', 'Plugin Installer' );
		// $tabs['favorites'] = _x( 'Favorites', 'Plugin Installer' );
		if ( $tab === 'beta' || false !== strpos( $GLOBALS['wp_version'], '-' ) ) {
			$tabs['beta'] = _x( 'Beta Testing', 'Plugin Installer' );
		}
		if ( current_user_can( 'upload_plugins' ) ) {
			// No longer a real tab. Here for filter compatibility.
			// Gets skipped in get_views().
			$tabs['upload'] = __( 'Upload Plugin', 'mainwp' );
		}

		$nonmenu_tabs = array( 'plugin-information' );
		// Valid actions to perform which do not have a Menu item.
		// $nonmenu_tabs = apply_filters( 'install_plugins_nonmenu_tabs', $nonmenu_tabs );
		// If a non-valid menu tab has been selected, And it's not a non-menu action.
		if ( empty( $tab ) || ( ! isset( $tabs[ $tab ] ) && ! in_array( $tab, (array) $nonmenu_tabs ) ) ) {
			$tab = key( $tabs );
		}

		$args = array(
			'page'               => $paged,
			'per_page'           => $per_page,
			'fields'             => array(
				'last_updated'       => true,
				'icons'              => true,
				'active_installs'    => true,
			),
			'locale'             => get_locale(),
			'installed_plugins'  => array(),
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
				$this->orderby           = 'group';
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
				if ( $user ) {
					$args['user'] = $user;
				} else {
					$args = false;
				}

				break;

			default:
				$args = false;
				break;
		}

		if ( ! $args ) {
			return;
		}

		$api = plugins_api( 'query_plugins', $args );

		if ( is_wp_error( $api ) ) {
			$this->error = $api;
			return;
		}

		$this->items = $api->plugins;

		if ( $this->orderby ) {
			uasort( $this->items, array( $this, 'order_callback' ) );
		}

		$this->set_pagination_args(
			array(
				'total_items'    => $api->info['results'],
				'per_page'       => $args['per_page'],
			)
		);

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
		echo '<div class="ui message yellow">' . $message . '</div>';
	}

	/**
	 * Override the parent display() so we can provide a different container.
	 */
	public function display() {
		?>
		<?php // $this->display_tablenav('top'); ?>
		<div id="mainwp-install-plugins-container" class="ui stackable four cards">
				<?php $this->display_rows_or_placeholder(); ?>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui column grid">
				<div class="column right aligned">
					<div class="inline field">
						<?php $this->display_tablenav('bottom'); ?>
					</div>
				</div>
			</div>
		<?php
	}

	protected function display_tablenav( $which ) {

		if ( $GLOBALS['tab'] === 'featured' ) {
			return;
		}

		if ( 'top' == $which ) {
			wp_referer_field();
			$this->pagination( $which );
		} else {
			$this->pagination( $which );
		}
	}

	protected function pagination( $which ) {
		if ( empty( $this->_pagination_args ) ) {
			return;
		}

		$total_items = $this->_pagination_args['total_items'];
		$total_pages = $this->_pagination_args['total_pages'];

		$perpage_paging = '<span>' . sprintf( _n( '%s item', '%s items', $total_items ), number_format_i18n( $total_items ) ) . '</span>';

		$current              = $this->get_pagenum();
		$removable_query_args = wp_removable_query_args();

		$current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

		$current_url = remove_query_arg( $removable_query_args, $current_url );

		$page_links = array();

		$disable_first = $disable_last = $disable_prev = $disable_next = false;

		if ( $current == 1 ) {
			$disable_first = true;
			$disable_prev  = true;
		}
		if ( $current == 2 ) {
			$disable_first = true;
		}
		if ( $current == $total_pages ) {
			$disable_last = true;
			$disable_next = true;
		}
		if ( $current == $total_pages - 1 ) {
			$disable_last = true;
		}

		if ( $disable_first ) {
			$page_links[] = '<a class="item disabled" aria-hidden="true"><i class="angle double left icon"></i></a>';
		} else {
			$page_links[] = sprintf( "<a class='item' href='%s' title='" . __( 'First page' ) . "' aria-hidden='true'>%s</a>",
				esc_url( remove_query_arg( 'paged', $current_url ) ),
				'<i class="angle double left icon"></i>'
			);
		}

		if ( $disable_prev ) {
			$page_links[] = '<a class="item disabled" aria-hidden="true"><i class="angle left icon"></i></a>';
		} else {
			$page_links[] = sprintf( "<a class='item' href='%s' title='" . __( 'Previous page' ) . "' aria-hidden='true'>%s</a>",
				esc_url( add_query_arg( 'paged', max( 1, $current - 1 ), $current_url ) ),
				'<i class="angle left icon"></i>'
			);
		}

		if ( $current - 1 > 0 ) {
			$page_links[] = sprintf( "<a class='item' href='%s'>%s</a>",
				esc_url( add_query_arg( 'paged', $current - 1, $current_url ) ),
				$current - 1
			);
		}

		$page_links[] = sprintf( "<a class='item active' href='%s'>%s</a>",
			esc_url( add_query_arg( 'paged', $current, $current_url ) ),
			$current
		);

		if ( $current + 1 <= $total_pages ) {
			$page_links[] = sprintf( "<a class='item' href='%s'>%s</a>",
				esc_url( add_query_arg( 'paged', $current + 1, $current_url ) ),
				$current + 1
			);
		}

		if ( $disable_next ) {
			$page_links[] = '<span class="item disabled " aria-hidden="true"><i class="right angle icon"></i></span>';
		} else {
			$page_links[] = sprintf( "<a class='item' href='%s' title='" . __( 'Next page' ) . "'>%s</a>",
				esc_url( add_query_arg( 'paged', min( $total_pages, $current + 1 ), $current_url ) ),
				'<i class="angle right icon"></i>'
			);
		}

		if ( $disable_last ) {
			$page_links[] = '<a class="item disabled" aria-hidden="true"><i class="right angle double icon"></i></a>';
		} else {
			$page_links[] = sprintf( "<a class='item' href='%s' title='" . __( 'Last page' ) . "' aria-hidden='true'>%s</a>",
				esc_url( add_query_arg( 'paged', $total_pages, $current_url ) ),
				'<i class="right angle double icon"></i>'
			);
		}

		if ( $total_pages > 1 ) {
			$perpage_paging = $perpage_paging . "&nbsp;&nbsp;<div class='ui pagination menu'>" . join( "\n", $page_links ) . '</div>';
		} else {
			$perpage_paging = $perpage_paging;
		}

		ob_start();

		echo $perpage_paging;

		$output = ob_get_clean();

		$this->_pagination = $output;

		echo $this->_pagination;
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
			'a'            => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
			),
			'abbr'         => array( 'title' => array() ),
			'acronym'      => array( 'title' => array() ),
			'code'         => array(),
			'pre'          => array(),
			'em'           => array(),
			'strong'       => array(),
			'ul'           => array(),
			'ol'           => array(),
			'li'           => array(),
			'p'            => array(),
			'br'           => array(),
		);

		$plugins_group_titles = array(
			'Performance'    => _x( 'Performance', 'Plugin installer group title' ),
			'Social'         => _x( 'Social', 'Plugin installer group title' ),
			'Tools'          => _x( 'Tools', 'Plugin installer group title' ),
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
			$description = wp_strip_all_tags( $plugin['short_description'] );
			$version     = wp_kses( $plugin['version'], $plugins_allowedtags );

			$name = wp_strip_all_tags( $title . ' ' . $version );

			$author = wp_kses( $plugin['author'], $plugins_allowedtags );
			if ( ! empty( $author ) ) {
				$author = ' <cite>' . sprintf( __( 'By %s', 'mainwp' ), $author ) . '</cite>';
			}

			$details_link = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $plugin['slug'] . '&url=' . ( isset( $plugin['PluginURI'] ) ? rawurlencode( $plugin['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin['name'] ) . '&TB_iframe=true&width=772&height=887' );

			if ( ! empty( $plugin['icons']['svg'] ) ) {
				$plugin_icon_url = $plugin['icons']['svg'];
			} elseif ( ! empty( $plugin['icons']['2x'] ) ) {
				$plugin_icon_url = $plugin['icons']['2x'];
			} elseif ( ! empty( $plugin['icons']['1x'] ) ) {
				$plugin_icon_url = $plugin['icons']['1x'];
			} else {
				$plugin_icon_url = $plugin['icons']['default'];
			}

			$last_updated_timestamp = strtotime( $plugin['last_updated'] );
			?>

			<div class="card plugin-card-<?php echo sanitize_html_class( $plugin['slug'] ); ?>">
				<?php do_action( 'mainwp_install_plugin_card_top' ); ?>
			<div class="content">
			  <a class="right floated mini ui image thickbox open-plugin-details-modal" href="<?php echo esc_url( $details_link ); ?>"><img src="<?php echo esc_attr( $plugin_icon_url ); ?>" /></a>
			  <div class="header">
				<a class="thickbox open-plugin-details-modal" href="<?php echo esc_url( $details_link ); ?>"><?php echo $title; ?></a>
					</div>
			  <div class="meta">
						<?php echo $author; // html content ?>
					</div>
			  <div class="description">
				<?php echo wp_strip_all_tags( $description ); // html content ?>
				</div>
					</div>
				<div class="extra content">
					<span class="right floated">
						<strong><?php esc_html_e( 'Last Updated: ', 'mainwp' ); ?></strong><?php printf( __( '%s ago', 'mainwp' ), human_time_diff( $last_updated_timestamp ) ); ?>
						</span>
					<?php
					wp_star_rating(
						array(
							'rating' => $plugin['rating'],
							'type'   => 'percent',
							'number' => $plugin['num_ratings'],
						)
					);
					?>
					</div>
					<div class="extra content">
						<a href="<?php echo esc_attr( $details_link ); ?>" class="ui mini button thickbox open-plugin-details-modal"><?php echo esc_html( 'Plugin Details' ); ?></a>
						<div class="ui radio checkbox right floated">
						  <input name="install-plugin" type="radio" id="install-plugin-<?php echo sanitize_html_class ( $plugin['slug'] ); ?>">
						  <label><?php echo esc_html( 'Install this Plugin', 'mainwp' ); ?></label>
						</div>
					</div>
				<?php do_action( 'mainwp_install_plugin_card_bottom', $plugin ); ?>
				</div>
			<?php
		}
		?>
		<script type="text/javascript">
			jQuery( document ).ready( function() {
				jQuery( '.card .ui.star.rating' ).rating();
			} );
		</script>
		<?php
		// Close off the group divs of the last one
		if ( ! empty( $group ) ) {
			echo '</div></div>';
		}
	}
}
