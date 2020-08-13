<?php
/**
 * Updates Table Helper.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Updates Table Helper.
 */
class MainWP_Updates_Table_Helper {

	/**
	 * Protected variable to hold columns info.
	 *
	 * @var array
	 */
	protected $columns_info;

	/**
	 * Protected variable to type
	 *
	 * @var string
	 */
	public $type = 'plugin';

	/**
	 * Protected variable to view per
	 *
	 * @var string
	 */
	public $view_per;

	/**
	 * Method __construct()
	 *
	 * @param string $view_per View per value.
	 * @param string $type Type of plugin or theme, option, default: 'plugin'.
	 */
	public function __construct( $view_per, $type = 'plugin' ) {
		$this->type     = $type;
		$this->view_per = $view_per;
	}

	/**
	 * Method get_columns()
	 *
	 * Combine all columns.
	 *
	 * @return array $columns Array of column names.
	 */
	public function get_columns() {

		$title = ( MAINWP_VIEW_PER_PLUGIN_THEME == $this->view_per || MAINWP_VIEW_PER_GROUP == $this->view_per ) ? __( 'Website', 'mainwp' ) : '';
		if ( MAINWP_VIEW_PER_SITE == $this->view_per ) {
			$title = ( 'plugin' == $this->type ) ? __( 'Plugin', 'mainwp' ) : __( 'Theme', 'mainwp' );
		}

		$columns = array(
			'title'   => $title,
			'version' => __( 'Version', 'mainwp' ),
			'latest'  => __( 'Latest', 'mainwp' ),
			'trusted' => __( 'Trusted', 'mainwp' ),
			'status'  => __( 'Status', 'mainwp' ),
			'action'  => '',
		);
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
		$this->columns_info = apply_filters( 'mainwp_updates_table_columns_header', array( $columns, $sortable ), $this->type, $this->view_per, $top );
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
			echo "<th $class>$column_display_name</th>";
		}
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
				echo '<td>' . $row_columns[ $col ] . '</td>';
			}
		}
		return $row_columns;
	}

}
