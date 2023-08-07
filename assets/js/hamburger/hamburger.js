jQuery( document ).ready( function() {
	jQuery( '.hamburger' ).on('click', function(e) {
		$menu = jQuery( this ).parent();
		if( !jQuery( this ).hasClass( 'active' ) ) {
			jQuery( this ).addClass( 'active' );
			$menu.addClass( 'open' );
		} else {
			jQuery(this).removeClass( 'active' );
			$menu.removeClass( 'open' );
		}
		e.preventDefault();
	});
})
