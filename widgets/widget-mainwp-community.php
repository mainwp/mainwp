<?php
/**
 * MainWP Community Widget
 *
 * Build Latest topics from the MainWP Community Widget.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Community
 *
 * Build the Community Widget.
 */
class MainWP_Community {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return string CLASS Class Name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method get_mainwp_community_topics()
	 *
	 * Get MainWP community topics.
	 *
	 * @param bool $forced To forced load topics.
	 */
	public static function get_mainwp_community_topics( $forced = false ) {

		$api_url = 'https://meta.mainwp.com/latest.json';

		$cache_key = 'mainwp_community_lasttopics';
		$data      = get_transient( $cache_key );

		$maxitems = 5;

		if ( $forced || ! is_array( $data ) || ! isset( $data['topics'] ) ) {

			global $wp_version;
			// Get the WordPress current version to be polite in the API call.
			include ABSPATH . WPINC . '/version.php';

			$request_args               = array();
			$request_args['user-agent'] = 'WordPress/' . $wp_version . '; ' . home_url( '/' );

			$response      = wp_remote_get( $api_url, $request_args );
			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

			$response_error = null;

			if ( is_wp_error( $response ) ) {
				$response_error = $response->get_error_message();
			} elseif ( 200 !== $response_code ) {
				$response_error = __( 'Error loading topics.', 'mainwp' );
			} elseif ( is_array( $response_body ) && isset( $response_body['topic_list'] ) ) {
				if ( isset( $response_body['topic_list']['topics'] ) ) {
					$count = count( $response_body['topic_list']['topics'] );
					if ( $count < $maxitems ) {
						$maxitems = $count;
					}
					$data = array();
					for ( $i = 0; $i < $maxitems; $i ++ ) {
						$data['topic'][] = $response_body['topic_list']['topics'][ $i ];
					}
					set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
				}
			}
		}
		$topic_base_url = 'https://meta.mainwp.com/t/';

		$topic_items = is_array( $data ) && isset( $data['topic'] ) ? $data['topic'] : array();

		if ( empty( $response_error ) && 0 == count( $topic_items ) ) {
			$response_error = __( 'No items', 'mainwp' );
		}
		ob_start();
		?>
		<?php if ( ! empty( $response_error ) ) : ?>
				<div class="item">						
					<div class="middle aligned content">
						<?php echo esc_html( $response_error ); ?>
					</div>
				</div>
		<?php else : ?>
				<?php // Loop through each feed item and display each item as a hyperlink. ?>
				<?php foreach ( $topic_items as $item ) : ?>
					<div class="item">						
						<div class="middle aligned content">
							<a href="<?php echo esc_url( $topic_base_url . $item['slug'] ); ?>"
								target="_blank" title="<?php printf( __( 'Posted %s, by %s', 'mainwp' ), $item['last_posted_at'], $item['last_poster_username'] ); ?>">
								<?php echo esc_html( $item['title'] ); ?>
							</a>
						</div>
					</div>
				<?php endforeach; ?>
			<?php
		endif;

		$output = ob_get_clean();
		wp_die( $output );
	}

	/**
	 * Method render()
	 *
	 * Render the widget.
	 */
	public static function render() {
		?>
		<h3 class="ui header handle-drag">
			<?php esc_html_e( 'MainWP Community', 'mainwp' ); ?>
			<div class="sub header"><?php esc_html_e( 'Latest topics from the MainWP Community', 'mainwp' ); ?></div>
		</h3>
		<div class="ui section hidden divider"></div>
		<div class="ui divided selection list" id="mainwp-communitopics-content">
			<div class="ui active inverted dimmer">
			<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp' ); ?></div>
			</div>
		</div>		
		<script type="text/javascript">
			jQuery( document ).ready( function () {		
				mainwp_get_community_topics();				
			} );			
			mainwp_get_community_topics = function() {
				var data = mainwp_secure_data( {
					action: 'mainwp_get_community_topics'					
				} );
				jQuery.post( ajaxurl, data, function ( response ) {
					jQuery('#mainwp-communitopics-content').html( response );
				});
			}			
		</script>
		<?php
	}

}
