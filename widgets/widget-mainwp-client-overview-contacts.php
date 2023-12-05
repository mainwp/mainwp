<?php
/**
 * MainWP Client Overview Contacts Widget
 *
 * Displays the Client Contacts.
 *
 * @package MainWP/MainWP_Client_Overview_Contacts
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Overview_Contacts
 *
 * Displays the Client Contacts.
 */
class MainWP_Client_Overview_Contacts {


	/**
	 * The contact variable.
	 *
	 * @var mixed Default null
	 */
	public $contact = null;

	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * @return mixed render_site_info()
	 */
	public function render() {
		if ( empty( $this->contact ) ) {
			return;
		}
		self::render_contact( $this->contact );
	}


	/**
	 * Render client Contacts Info.
	 *
	 * @param object $contact_info The Contact.
	 */
	public static function render_contact( $contact_info ) {

		if ( empty( $contact_info ) ) {
			return;
		}

		?>
		<div class="mainwp-widget-header">
			<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_clients_overview_contact_widget_title
			 *
			 * Filters the Site info widget title text.
			 *
			 * @param object $contact_info Object containing the child site info.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_clients_overview_contact_widget_title', $contact_info['contact_name'], $contact_info ) );
			?>
				<div class="sub header">
					<?php echo esc_html( apply_filters( 'mainwp_clients_overview_contact_widget_sutbitle', $contact_info['contact_role'], $contact_info ) ); ?>
				</div>
			</h3>
		</div>
			<div class="mainwp-widget-client-card mainwp-scrolly-overflow">
				<?php
				/**
				 * Actoin: mainwp_clients_overview_contact_widget_top
				 *
				 * Fires at the top of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $contact_info Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_clients_overview_contact_widget_top', $contact_info );
				?>
				<?php if ( $contact_info ) { ?>
						<div class="ui stackable grid">
								<div class="four wide column">
								<?php if ( empty( $contact_info['contact_image'] ) ) { ?>
									<i class="user icon massive"></i>
									<?php
								} else {
									$image_url = MainWP_Client_Handler::get_client_image_url( $contact_info['contact_image'] );
									?>
								<img class="ui circular medium image" src="<?php echo esc_attr( $image_url ); ?>">
									<?php } ?>
								</div>
								<div class="twelve wide middle aligned column">
							<div class="ui relaxed list">
								<?php if ( isset( $contact_info['contact_email'] ) && '' !== $contact_info['contact_email'] ) : ?>
								<div class="item">
									<i class="envelope grey icon"></i>
									<div class="content"><a href="mailto:<?php echo esc_url( $contact_info['contact_email'] ); ?>" target="_blank"><?php echo esc_html( $contact_info['contact_email'] ); ?></a> <i data-clipboard-text="<?php echo esc_html( $contact_info['contact_email'] ); ?>" style="cursor:pointer" class="copy green icon copy-to-clipboard"></i></div>
											</div>
								<?php endif; ?>
								<?php if ( isset( $contact_info['contact_phone'] ) && '' !== $contact_info['contact_phone'] ) : ?>
								<div class="item">
									<i class="phone grey rotated icon"></i>
									<div class="content"><a href="tel:<?php echo esc_url( $contact_info['contact_phone'] ); ?>" target="_blank"><?php echo esc_html( $contact_info['contact_phone'] ); ?></a></div>
											</div>
								<?php endif; ?>
								<?php if ( isset( $contact_info['facebook'] ) && '' !== $contact_info['facebook'] ) : ?>
											<div class="item">
												<i class="facebook grey icon"></i>
									<div class="content"><a href="<?php echo esc_url( $contact_info['facebook'] ); ?>" target="_blank"><?php echo esc_html( $contact_info['facebook'] ); ?></a></div>
											</div>
								<?php endif; ?>
								<?php if ( isset( $contact_info['twitter'] ) && '' !== $contact_info['twitter'] ) : ?>
											<div class="item">
												<i class="twitter grey icon"></i>
									<div class="content"><a href="<?php echo esc_url( $contact_info['twitter'] ); ?>" target="_blank"><?php echo esc_html( $contact_info['twitter'] ); ?></a></div>
											</div>
								<?php endif; ?>
								<?php if ( isset( $contact_info['instagram'] ) && '' !== $contact_info['instagram'] ) : ?>
											<div class="item">
												<i class="instagram grey icon"></i>
									<div class="content"><a href="<?php echo esc_url( $contact_info['instagram'] ); ?>" target="_blank"><?php echo esc_html( $contact_info['instagram'] ); ?></a></div>
											</div>
								<?php endif; ?>
								<?php if ( isset( $contact_info['linkedin'] ) && '' !== $contact_info['linkedin'] ) : ?>
											<div class="item">
												<i class="linkedin grey icon"></i>
									<div class="content"><a href="<?php echo esc_url( $contact_info['linkedin'] ); ?>" target="_blank"><?php echo esc_html( $contact_info['linkedin'] ); ?></a></div>
										</div>
								<?php endif; ?>
									</div>
								</div>
						</div>
					<div class="ui hidden divider"></div>
						<script type="text/javascript">
								jQuery( document ).ready( function ($) {
									new ClipboardJS('.copy-to-clipboard');
								});
						</script>					
					<?php } ?>
				<?php
				/**
				 * Action: mainwp_clients_overview_contact_widget_bottom
				 *
				 * Fires at the bottom of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $contact_info Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_clients_overview_contact_widget_bottom', $contact_info );
				?>
			</div>
			<?php
	}
}
