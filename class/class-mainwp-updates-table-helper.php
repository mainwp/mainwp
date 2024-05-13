<?php
/**
 * Updates Table Helper.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Table_Helper
 *
 * @package MainWP\Dashboard
 */
class MainWP_Updates_Table_Helper {

	/**
	 * Protected variable to hold columns info.
	 *
	 * @var array
	 */
	protected $columns_info;

	/**
	 * Public variable to type
	 *
	 * @var string
	 */
	public $type = 'plugin';

	/**
	 * Public variable to show select box on rows.
	 *
	 * @var string
	 */
	public $show_select = false;


	/**
	 * Protected variable to view per
	 *
	 * @var string
	 */
	public $view_per;

	/**
	 * MainWP_Updates_Table_Helper constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @param string $view_per View per value.
	 * @param string $type Type of plugin or theme, option, default: 'plugin'.
	 * @param array  $others others parameters.
	 */
	public function __construct( $view_per, $type = 'plugin', $others = array() ) {
		$this->type        = $type;
		$this->view_per    = (int) $view_per;
		$this->show_select = is_array( $others ) && isset( $others['show_select'] ) && $others['show_select'] ? true : false;
	}

	/**
	 * Method get_columns()
	 *
	 * Combine all columns.
	 *
	 * @return array $columns Array of column names.
	 */
	public function get_columns() {

		$title = ( MAINWP_VIEW_PER_PLUGIN_THEME === $this->view_per || MAINWP_VIEW_PER_GROUP === $this->view_per ) ? esc_html__( 'Website', 'mainwp' ) : '';
		if ( MAINWP_VIEW_PER_SITE === $this->view_per ) {
			$title = ( 'plugin' === $this->type ) ? esc_html__( 'Plugin', 'mainwp' ) : esc_html__( 'Theme', 'mainwp' );
		}

		$columns = array(
			'title'   => $title,
			'login'   => '<i class="sign in alternate icon"></i>',
			'version' => esc_html__( 'Version', 'mainwp' ),
			'latest'  => esc_html__( 'Latest', 'mainwp' ),
			'trusted' => esc_html__( 'Trusted', 'mainwp' ),
			'status'  => esc_html__( 'Status', 'mainwp' ),
			'client'  => esc_html__( 'Client', 'mainwp' ),
			'action'  => '',
		);

		if ( MAINWP_VIEW_PER_PLUGIN_THEME !== $this->view_per ) {
			unset( $columns['login'] );
			unset( $columns['client'] );
		}
		if ( MAINWP_VIEW_PER_PLUGIN_THEME === $this->view_per ) {
			unset( $columns['trusted'] );
		}
		return $columns;
	}

	/**
	 * Get column info.
	 */
	protected function get_column_info() {
		if ( isset( $this->columns_info ) ) {
			return $this->columns_info;
		}
		$columns            = $this->get_columns();
		$sortable           = $this->get_sortable_columns();
		$this->columns_info = apply_filters( 'mainwp_updates_table_columns_header', array( $columns, $sortable ), $this->type, $this->view_per );
		return $this->columns_info;
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array $sortable_columns Array of sortable column names.
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'title'   => true,
			'version' => true,
			'trusted' => true,
			'status'  => true,
		);
		return $sortable_columns;
	}

	/**
	 * Echo the column headers.
	 *
	 * @param bool $top true|false.
	 */
	public function print_column_headers( $top = true ) {

		list( $columns_header, $sortable ) = $this->get_column_info();

		foreach ( $columns_header as $column_key => $column_display_name ) {
			$class = array();
			if ( ! isset( $sortable[ $column_key ] ) ) {
				$class[] = 'no-sort';
			}

			if ( ! empty( $class ) ) {
				$class = "class='" . join( ' ', $class ) . "'";
			} else {
				$class = '';
			}
			$column_display_name = apply_filters( 'mainwp_updates_table_header_content', $column_display_name, $column_key, $top, $this );
			echo "<th $class>$column_display_name</th>"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}


	/**
	 * Trusted column.
	 *
	 * @param mixed $value Value of column.
	 */
	public function column_trusted( $value ) {
		if ( $value ) {
			$label = '<span class="ui tiny basic green label mainwp-768-fluid">Trusted</span>';
		} else {
			$label = '<span class="ui tiny basic grey label mainwp-768-fluid">Not Trusted</span>';
		}
		return '<td class="mainwp-768-half-width-cell">' . $label . '</td>';
	}

	/**
	 * Status column.
	 *
	 * @param mixed $value Value of column.
	 */
	public function column_status( $value ) {
		if ( $value ) {
			$label = '<span class="ui tiny basic green label mainwp-768-fluid">Active</span>';
		} else {
			$label = '<span class="ui tiny basic grey label mainwp-768-fluid">Inactive</span>';
		}
		return '<td class="mainwp-768-half-width-cell">' . $label . '</td>';
	}

	/**
	 * Default column.
	 *
	 * @param mixed $value Value of column.
	 * @param mixed $column_name Name of column.
	 */
	public function column_default( $value, $column_name ) {
		$current_wpid = MainWP_System_Utility::get_current_wpid();
		$class        = '';
		if ( 'version' === $column_name || 'latest' === $column_name ) {
			$class = 'mainwp-768-half-width-cell';
		}

		$column_content = '';
		if ( 'title' === $column_name && ( empty( $current_wpid ) || $this->show_select ) ) {
			$column_content .= '<div class="ui child checkbox">
			<input type="checkbox" name="">
		  </div>';
		}
		$column_content .= $value;

		$col = '<td class="' . $class . '">' . $column_content . '</td>';
		return $col;
	}

	/**
	 *  Echo columns.
	 *
	 * @param array  $columns Array of columns.
	 * @param object $website The website.
	 *
	 * @return array Row columns.
	 */
	public function render_columns( $columns, $website ) {
		$row_columns            = apply_filters( 'mainwp_updates_table_row_columns', $columns, $website, $this->type, $this->view_per );
		list( $columns_header ) = $this->get_column_info();
		foreach ( $columns_header as $col => $title ) {
			if ( isset( $row_columns[ $col ] ) ) {
				$value = $row_columns[ $col ];
				if ( method_exists( $this, 'column_' . $col ) ) {
					echo call_user_func( array( &$this, 'column_' . $col ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					echo $this->column_default( $value, $col ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
		}
		return $row_columns;
	}
}
