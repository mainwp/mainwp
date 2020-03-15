<?php
/**
 * Display custom fields form fields.
 */
class MainWP_Bulk_Post {

	public static function getClassName() {
		return __CLASS__;
	}

	/**
	 * Display custom fields form fields.
	 *
	 * @since 2.6.0
	 *
	 * @param object $post
	 */
	public static function post_custom_meta_box( $post ) {
		?>
		<div class="field">
		<div id="postcustomstuff">
		<div id="ajax-response"></div>
		<?php
		$metadata = has_meta($post->ID);
		foreach ( $metadata as $key => $value ) {
			if ( is_protected_meta( $metadata[ $key ]['meta_key'], 'post' ) || ! current_user_can( 'edit_post_meta', $post->ID, $metadata[ $key ]['meta_key'] ) ) {
				unset( $metadata[ $key ] );
			}
		}
		list_meta( $metadata );
		meta_form( $post );
		?>
		</div>
		<p>
		<?php
			printf(
				/* translators: %s: Codex URL */
				__( 'Custom fields can be used to add extra metadata to a post that you can <a href="%s">use in your theme</a>.' ),
				__( 'https://codex.wordpress.org/Using_Custom_Fields' )
			);
		?>
		</p>

		</div>
		<?php
	}

}
