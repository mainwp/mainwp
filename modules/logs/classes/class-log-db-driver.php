<?php
/**
 * Interface for a Database Driver.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

/**
 * Interface - Log_DB_Driver
 */
interface Log_DB_Driver {
	/**
	 * Insert a record
	 *
	 * @param array $data Data to be insert into the database.
	 *
	 * @return int
	 */
	public function insert_record( $data );

	/**
	 * Retrieve records
	 *
	 * @param array $args Argument to filter the result by.
	 *
	 * @return array
	 */
	public function get_records( $args );

	/**
	 * Returns array of existing values for requested column.
	 * Used to fill search filters with only used items, instead of all items.
	 *
	 * @param string $column Column to pull data from.
	 *
	 * @return array
	 */
	public function get_column_values( $column );

	/**
	 * Public getter to return the names of the tables this driver manages.
	 *
	 * @return array
	 */
	public function get_table_names();



	/**
	 * Purge storage.
	 *
	 * @param \MainWP\Dashboard\Module\Log\Log_Manager $manager Instance of the manager.
	 */
	public function purge_storage( $manager );
}
