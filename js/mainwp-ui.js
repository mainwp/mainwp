
function reload_init()
{
	read_current_url();
	if(typeof wpOnload=='function')wpOnload();
    managebackups_init();
    managesites_init();
    // Update form action URL, workaround for browser without History API
    jQuery('#wpbody-content form').each(function(){
    	if ( jQuery(this).attr('action') == '' )
	    	jQuery(this).attr('action', mainwp_current_url);
    });
    stick_element_init();
}

/** AJAX page load **/
var mainwp_current_url = '';
function read_current_url()
{
	mainwp_current_url = document.location.href.replace(/^.*?\/([^\/]*?)\/?$/i, '$1');
	return mainwp_current_url;
}
function load_url( href, obj, e )
{
	var page = href.match(/page=/i) ? href.replace(/^.*?page=([^&]+).*?$/i, '$1') : '';
	if ( page || href == 'index.php' )
	{
		if ( typeof e !== 'undefined' )
			e.preventDefault();
		jQuery('#wpbody-content').html('<div class="mainwp-loading"><img src="images/loading.gif" /> '+__('Please wait...')+'</div>');
		if ( jQuery(obj).hasClass('menu-top') )
		{
			var top = jQuery(obj).closest('li.menu-top');
			jQuery('#adminmenu .current').removeClass('current').addClass('wp-not-current-submenu');
			jQuery('.wp-has-current-submenu').removeClass('wp-has-current-submenu').addClass('wp-not-current-submenu');
			if ( top.hasClass('wp-has-submenu') )
			{
				top.removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
				jQuery(obj).removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
			}
			else
			{
				top.removeClass('wp-not-current-submenu').addClass('current');
				jQuery(obj).removeClass('wp-not-current-submenu').addClass('current');
			}
			top.find('li.wp-first-item').addClass('current');
		}
		else
		{
			jQuery('#adminmenu .current').removeClass('current');
			jQuery(obj).closest('li').addClass('current');
			var top = jQuery(obj).closest('li.menu-top');
			if ( top.hasClass('wp-not-current-submenu') )
			{
				jQuery('.wp-has-current-submenu').removeClass('wp-has-current-submenu').addClass('wp-not-current-submenu');
				top.removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
			}
			top.find('a.menu-top').removeClass('wp-not-current-submenu').addClass('wp-has-current-submenu');
		}
		if ( page )
		{
			jQuery.get(ajaxurl, {
				action: 'mainwp_load_title',
				page: page,
				nonce: mainwp_ajax_nonce
			}, function(data){
				document.title = data;
			});
			jQuery.get(ajaxurl, {
				action: 'mainwp_load',
				page: page,
				nonce: mainwp_ajax_nonce
			}, function(data){
				pagenow = page;
				data += '<div class="clear"></div>';
				jQuery('#wpbody-content').html(data);
				reload_init();
			});
		}
		else
		{
			jQuery.get(ajaxurl, {
				action: 'mainwp_load_dashboard_title',
				nonce: mainwp_ajax_nonce
			}, function(data){
				document.title = data;
			});
			jQuery.get(ajaxurl, {
				action: 'mainwp_load_dashboard',
				nonce: mainwp_ajax_nonce
			}, function(data){
				pagenow = 'dashboard';
				data += '<div class="clear"></div>';
				jQuery('#wpbody-content').html(data);
				reload_init();
				postboxes.init(pagenow);
				mainwp_ga_getstats();
			});
		}

	}
}
function getSelector( obj )
{
	var elements = jQuery(obj).parentsUntil('body').map(mapGetSelector).get().reverse();
	elements.push(jQuery(obj).map(mapGetSelector).get());
	jQuery(obj).parentsUntil('body').each(function(i){
		var current_selector = elements.slice(0, elements.length-(i+1)).join(" > ");
		var element_index = jQuery(this).index(current_selector);
		if ( element_index > -1 )
			elements[elements.length-(i+2)] += ':eq('+element_index+')';
	});
	return elements.join(" > ");
}
function mapGetSelector()
{
	var el_class = jQuery(this).attr('class');
	var el_id = jQuery(this).attr('id');
	return el_selector = this.tagName + ( el_id ? '#'+el_id : '' ) + 
		( ! el_id && el_class ? '.'+el_class.match(/^\S+/) : '' );
}
window.onpopstate = function(e){
	read_current_url();
	if ( e.state )
		load_url(mainwp_current_url, e.state.anchor);
	//alert(mainwp_current_url);
}
jQuery(document).ready(function()
{
//	read_current_url();
//	jQuery('#adminmenu li a').click(function(e){
//		var href = jQuery(this).attr('href');
//		var data_obj = {
//			'anchor': getSelector(this)
//		};
//		load_url(href, data_obj.anchor, e);
//		if (typeof history.pushState !== 'undefined') {
//			var title = jQuery(this).text();
//			history.pushState(data_obj, title, href);
//		}
//	});
});



function scroll_element( e )
{
	var top = jQuery(this).scrollTop();
	var start = 20;
	jQuery('.stick-to-window').each(function(){
		var init = jQuery(this).data('init-position');
		if ( top > init.top )
			jQuery(this).stop().animate({top: (top-init.top)+init.top+start}, 1000);
		else
			jQuery(this).stop().css({top: init.top});
	});
}
function stick_element_init()
{
	jQuery('.stick-to-window').each(function(){
		var pos = jQuery(this).position();
		jQuery(this).css({
			position: 'absolute',
			top: pos.top,
			left: pos.left
		});
		jQuery(this).data('init-position', pos);
	});
}
function stick_element_reset()
{
	jQuery('.stick-to-window').each(function(){
		jQuery(this).css({
			position: 'static',
			top: 0,
			left: 0
		});
	});
	stick_element_init();
	scroll_element();
}
jQuery(document).ready(function(){
	jQuery(window).scroll(scroll_element).resize(stick_element_reset);
	stick_element_init();
});



function shake_element( select )
{
	var pos = jQuery(select).position();
	var type = jQuery(select).css('position');
	if ( type == 'static' )
	{
		jQuery(select).css({
			position: 'relative'
		});
	}
	if ( type == 'static' || type == 'relative' )
	{
		pos.top = 0;
		pos.left = 0;
	}
	jQuery(select).data('init-type', type);
	var shake = [ [0,5,60], [0,0,60], [0,-5,60], [0,0,60], [0,2,30], [0,0,30], [0,-2,30], [0,0,30] ];
	for ( s = 0; s < shake.length; s++ )
	{
		jQuery(select).animate({
			top: pos.top+shake[s][0],
			left: pos.left+shake[s][1]
		}, shake[s][2], 'linear');
	}
}




