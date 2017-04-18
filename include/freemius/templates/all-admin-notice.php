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

	/**
	 * @var array $VARS
	 */
?>
<div class="<?php
	switch ($VARS['type']) {
		case 'error':
			echo 'error form-invalid';
			break;
		case 'update-nag':
			echo 'update-nag ';
			break;
		case 'update':
		case 'success':
		default:
			echo 'updated success';
			break;
	}
?> fs-notice">
	<?php if ('update-nag' !== $VARS['type']) : ?><p><?php endif ?>
		<?php if (!empty($VARS['title'])) : ?>
			<b><?php echo $VARS['title'] ?></b>
		<?php endif ?>
		<?php echo $VARS['message'] ?>
	<?php if ('update-nag' !== $VARS['type']) : ?></p><?php endif ?>
	<?php if ($VARS['sticky']) : ?><i class="dashicons dashicons-no"></i><?php endif ?>
</div>