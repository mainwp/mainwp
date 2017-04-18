<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.0.3
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}
?>
<div<?php if ( ! empty( $VARS['id'] ) ) : ?> data-id="<?php echo $VARS['id'] ?>"<?php endif ?><?php if ( ! empty( $VARS['slug'] ) ) : ?> data-slug="<?php echo $VARS['slug'] ?>"<?php endif ?>
	class="<?php
		switch ( $VARS['type'] ) {
			case 'error':
				echo 'error form-invalid';
				break;
			case 'promotion':
				echo 'updated promotion';
				break;
			case 'update':
//			echo 'update-nag update';
//			break;
			case 'success':
			default:
				echo 'updated success';
				break;
		}
	?> fs-notice<?php if ( ! empty( $VARS['sticky'] ) ) {
		echo ' fs-sticky';
	} ?><?php if ( ! empty( $VARS['plugin'] ) ) {
		echo ' fs-has-title';
	} ?>"><?php if ( ! empty( $VARS['plugin'] ) ) : ?>
		<label class="fs-plugin-title"><?php echo $VARS['plugin'] ?></label>
	<?php endif ?>
	<?php if ( ! empty( $VARS['sticky'] ) ) : ?>
		<div class="fs-close"><i class="dashicons dashicons-no"
		                         title="<?php _efs( 'dismiss' ) ?>"></i> <span><?php _efs( 'dismiss' ) ?></span>
		</div>
	<?php endif ?>
	<div class="fs-notice-body">
		<?php if ( ! empty( $VARS['title'] ) ) : ?><b><?php echo $VARS['title'] ?></b> <?php endif ?>
		<?php echo $VARS['message'] ?>
	</div>
</div>
