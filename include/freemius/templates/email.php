<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.1.1
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * @var array $VARS
	 */
	$sections = $VARS['sections'];
?>
<table>
	<?php
	foreach ( $sections as $section_id => $section ) {
		?>
		<thead>
			<tr><th colspan="2" style="text-align: left; background: #333; color: #fff; padding: 5px;"><?php echo esc_html($section['title']) ?></th></tr>
		</thead>
		<tbody>
		<?php
		foreach ( $section['rows'] as $row_id => $row ) {
			$col_count = count( $row );
			?>
			<tr>
				<?php
				if ( 1 === $col_count ) { ?>
					<td style="vertical-align: top;" colspan="2"><?php echo $row[0] ?></td>
					<?php
				} else { ?>
					<td style="vertical-align: top;"><b><?php echo esc_html($row[0]) ?>:</b></td>
					<td><?php echo $row[1]; ?></td>
					<?php
				}
				?>
			</tr>
			<?php
		}
		?>
		</tbody>
		<?php
	}
	?>
</table>