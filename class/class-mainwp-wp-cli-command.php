<?php

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

class MainWP_WP_CLI_Command extends WP_CLI_Command {

	public static function init() {
		WP_CLI::add_command( 'mainwp', 'MainWP_WP_CLI_Command' );
	}

	//mainwp sites: show sites options
	//mainwp sites add: add site
	//mainwp sites remove: remove site
	//mainwp sites list: list sites

	//mainwp sync: show sync options
	//mainwp sync all: sync all
	//mainwp sync <websiteid>: sync website
	public function sync( $args, $assoc_args ) {
		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		WP_CLI::line( 'Syncing ' . MainWP_DB::num_rows( $websites ) . ' sites' );
		$warnings = 0;
		$errors   = 0;
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			WP_CLI::line( '  -> ' . $website->name . ' (' . $website->url . ')' );
			try {
				if ( MainWP_Sync::syncSite( $website ) ) {
					WP_CLI::success( '  Sync succeeded' );
				} else {
					WP_CLI::warning( '  Sync failed' );
					$warnings++;
				}
			} catch ( Exception $e ) {
				WP_CLI::error( '  Sync failed' );
				$errors++;
			}
		}
		@MainWP_DB::free_result( $websites );
		if ( $errors > 0 ) {
			WP_CLI::error( 'Sync completed with errors' );
		} else if ( $warnings > 0 ) {
			WP_CLI::warning( 'Sync completed with warnings' );
		} else {
			WP_CLI::success( 'Sync completed' );
		}
	}

	//mainwp upgrade: show upgrade options
	//mainwp ugprade wp: show upgrade wp options
	//mainwp upgrade wp list: show list of wp upgrades
	//mainwp upgrade wp all: upgrade all wp
	//mainwp upgrade wp <websiteid>: upgrade wp website

	//mainwp upgrade plugin: show options
	//mainwp upgrade theme: show options
	/**
	 * @synopsis <wp|plugin|theme> <list|all|site_id>
	 */
	public function upgrade( $args, $assoc_args ) {
//		if ( 'list' === $args[0] ) {
//			upgrade_list($args, $assoc_args);
//		} else if ( 'all' === $args[0] ) {
//
//		} else if ( 'plugins' === $args[0] ) {
//
//		}
	}
	//upgrade everything
	//upgrade plugins
	//upgrade themes
}