<?php

class MainWP_SEO {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function getName() {
		return '<i class="fa fa-search"></i> ' . __( 'SEO', 'mainwp' );
	}

	public static function render() {
		global $wpdb;
		$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		$websites = MainWP_DB::Instance()->query( $sql );

		if ( count( $websites ) == 0 ) {
			echo __( 'No Websites', 'mainwp' );
		} else {
			?>
			<style type="text/css">
				table#mainwp-seo-list tr:nth-child(even) {
					background: #fafafa;
				}
			</style>
			<br/>
			<table id="mainwp-seo-list">
				<thead align="left">
				<th style="padding-bottom: 1em; cursor: pointer;" class="sortable"><?php _e( 'Child Site', 'mainwp' ); ?></th>
				<th style="padding-bottom: 1em; cursor: pointer;" class="sortable"><?php _e( 'Alexa Rank', 'mainwp' ); ?></th>
				<!--            			<th style="padding-bottom: 1em; cursor: pointer;" class="sortable"><?php _e( 'Google PR', 'mainwp' ); ?></th>-->
				<th style="padding-bottom: 1em; cursor: pointer;" class="sortable"><?php _e( 'Indexed', 'mainwp' ); ?></th>
				</thead>
				<tbody>
				<?php
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					?>
					<tr>
						<td style="padding-right: 2em">
							<abbr title="<?php echo $website->url; ?>"><a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>"><?php echo $website->url; ?></a></abbr>
						</td>
						<?php if ( $website->alexia < $website->alexia_old ) { ?>
							<td style="width: 150px"><span class="mainwp-green">
							<i class="fa fa-chevron-down"></i> <?php echo $website->alexia; ?>
							</span><?php echo( $website->alexia_old != '' ? ' <span style="color: #7B848B !important">(' . $website->alexia_old . ')</span>' : '' ); ?>
							</td><?php } else if ( $website->alexia == $website->alexia_old ) { ?>
							<td style="width: 150px">
							<span><i class="fa fa-chevron-right"></i> <?php echo $website->alexia; ?></span> <?php echo( $website->alexia_old != '' ? ' <span style="color: #7B848B !important">(' . $website->alexia_old . ')</span>' : '' ); ?>
							</td><?php } else { ?>
							<td style="width: 150px">
							<span class="mainwp-red"><i class="fa fa-chevron-up"></i> <?php echo $website->alexia; ?></span> <?php echo( $website->alexia_old != '' ? ' <span style="color: #7B848B !important">(' . $website->alexia_old . ')</span>' : '' ); ?>
							</td><?php }

						if ( $website->indexed > $website->indexed_old ) { ?>
							<td style="width: 100px">
							<span class="mainwp-green"><i class="fa fa-chevron-up"></i> <?php echo $website->indexed; ?></span> <?php echo( $website->indexed_old != '' ? ' <span style="color: #7B848B !important">(' . $website->indexed_old . ')</span>' : '' ); ?>
							</td><?php } else if ( $website->indexed == $website->indexed_old ) { ?>
							<td style="width: 100px">
							<span><i class="fa fa-chevron-right"></i> <?php echo $website->indexed; ?></span> <?php echo( $website->indexed_old != '' ? ' <span style="color: #7B848B !important">(' . $website->indexed_old . ')</span>' : '' ); ?>
							</td><?php } else { ?>
							<td style="width: 100px">
							<span class="mainwp-red"><i class="fa fa-chevron-down"></i> <?php echo $website->indexed; ?></span> <?php echo( $website->indexed_old != '' ? ' <span style="color: #7B848B !important">(' . $website->indexed_old . ')</span>' : '' ); ?>
							</td><?php } ?>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
			<script type="text/javascript">
				jQuery( document ).ready( function () {
					jQuery( '#mainwp-seo-list' ).tablesorter( {
						cssAsc: "desc",
						cssDesc: "asc",
						sortInitialOrder: 'desc',
						textExtraction: function ( node ) {
							if ( jQuery( node ).find( 'abbr' ).length == 0 ) {
								return node.innerHTML
							}
							else {
								return jQuery( node ).find( 'abbr' )[0].title;
							}
						}
					} );
				} );</script>
			<?php
			@MainWP_DB::free_result( $websites );
		}
	}
}
