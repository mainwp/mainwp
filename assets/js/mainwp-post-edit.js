/**
 * Contains all dynamic functionality needed on post and term pages.
 *
 * @summary Control page and term functionality.
 */


// Make sure the wp object exists.
window.wp = window.wp || {};

mainwp_post_newmeta_submit = function (action, me) {

	if (action != 'add' && action != 'delete' && action != 'update')
		return false;

	var data;

	if (action == 'add') {

		var metakey = jQuery('#metakeyselect').val(), newkey = false;
		if (jQuery('#metakeyinput').is(':visible')) {
			metakey = '#NONE#';
			newkey = true;
		}

		data = mainwp_secure_data({
			action: 'mainwp_post_addmeta',
			post_id: jQuery('#post_ID').val(),
			metavalue: jQuery('#metavalue').val(),
			metakeyinput: jQuery('#metakeyinput').val(),
			metakeyselect: metakey
		});

	} else {

		var row = jQuery(me).closest('.row');
		var metaid = row.attr('meta-id');

		if ((row.length == 0) || !metaid)
			return false;

		data = mainwp_secure_data({
			action: 'mainwp_post_addmeta',
			post_id: jQuery('#post_ID').val()
		});

		if (action == 'update') {
			row.addClass('yellow');
			data['meta'] = {};
			data['meta'][metaid] = {
				'key': row.find('#meta-' + metaid + '-key').val(),
				'value': row.find('#meta-' + metaid + '-value').val()
			};
		} else {
			row.addClass('red');
			data['delete_meta'] = 'yes';
			data['meta_nonce'] = jQuery(me).attr('_ajax_nonce');
			data['id'] = metaid;
		}
	}

	jQuery.post(ajaxurl, data, function (response) {
		if (response) {
			if (response.error) {
				feedback('mainwp-message-zone', response.error, 'red');
				return;
			}
			if (action == 'update' && response.result) {
				row.replaceWith(response.result);
			} else if (action == 'add' && response.result) {
				jQuery(response.result).insertBefore('#mainwp-metaform-row');

				if (newkey) {
					jQuery('#metakeyinput').val('');
				} else {
					jQuery('#metakeyselect').val('#NONE#');
				}

			} else if (action == 'delete' && response.ok) {
				row.remove();
			}
		}
	}, 'json');
};

/**
 * All post and postbox controls and functionality.
 */
jQuery(document).ready(function ($) {
	var updateText,
		$textarea = $('#content'),
		$document = $(document),
		$timestampdiv = $('#timestampdiv');

	/*
	 * Clear the window name. Otherwise if this is a former preview window where the user navigated to edit another post,
	 * and the first post is still being edited, clicking Preview there will use this window to show the preview.
	 */
	window.name = '';

	//Show/Hide visibility additional fields
	jQuery('input[name="visibility"').on('change', function () {
		if (jQuery(this).val() == 'public') {
			jQuery('#sticky-field').show();
			jQuery('#post_password-field').hide();
		} else if (jQuery(this).val() == 'password') {
			jQuery('#sticky-field').hide();
			jQuery('#post_password-field').show();
		} else {
			jQuery('#sticky-field').hide();
			jQuery('#post_password-field').hide();
		}
	});

	//Show timestamp field
	jQuery('#post_timestamp').on('change', function () {
		if (jQuery(this).val() == 'schedule') {
			jQuery('#post_timestamp_value-field').show();
		} else {
			jQuery('#post_timestamp_value-field').hide();
			// cancel edit date time.
			$('#mm').val($('#hidden_mm').val());
			$('#jj').val($('#hidden_jj').val());
			$('#aa').val($('#hidden_aa').val());
			$('#hh').val($('#hidden_hh').val());
			$('#mn').val($('#hidden_mn').val());
		}
	});

	/**
	 * Make sure all labels represent the current settings.
	 *
	 * @returns {boolean} False when an invalid timestamp has been selected, otherwise True.
	 */
	updateText = function () {

		if (!$timestampdiv.length)
			return true;

		var attemptedDate,
			aa = $('#aa').val(),
			mm = $('#mm').val(), jj = $('#jj').val(), hh = $('#hh').val(), mn = $('#mn').val();

		attemptedDate = new Date(aa, mm - 1, jj, hh, mn);

		// Catch unexpected date problems.
		if (attemptedDate.getFullYear() != aa || (1 + attemptedDate.getMonth()) != mm || attemptedDate.getDate() != jj || attemptedDate.getMinutes() != mn) {
			$timestampdiv.find('.timestamp-wrap').addClass('form-invalid');
			return false;
		} else {
			$timestampdiv.find('.timestamp-wrap').removeClass('form-invalid');
		}
		return true;
	};

	// init post datetime calendar
	jQuery('#schedule_post_datetime').calendar({
		type: 'datetime',
		initialDate: function () {
			var aa = $('#aa').val(), mm = $('#mm').val(), jj = $('#jj').val(), hh = $('#hh').val(), mn = $('#mn').val();
			var ind = new Date(aa, mm - 1, jj, hh, mn);
			console.log(ind);
			return ind;
		}(),
		monthFirst: false,
		formatter: {
			date: function (date) {
				if (!date) return '';
				var jj = date.getDate();
				var mm = date.getMonth() + 1;
				var aa = date.getFullYear();
				return aa + '-' + mm + '-' + jj;
			}
		},
		onChange: function (attemptedDate, textDate) {
			console.log('onChange:' + textDate);
			var aa = attemptedDate.getFullYear(), mm = attemptedDate.getMonth() + 1, jj = attemptedDate.getDate(), mn = attemptedDate.getMinutes(), hh = attemptedDate.getHours();
			mm = ('0' + mm).slice(-2); // to format 01,02,03, ... 11,12
			$('#aa').val(aa);
			$('#mm').val(mm).trigger("change"); // selector element
			$('#jj').val(jj);
			$('#hh').val(hh);
			$('#mn').val(mn);
			return updateText(); // not set if invalid date
		},
		onSelect: function (attemptedDate, mode) {
			console.log('onSelect mode:' + mode);
			var aa = attemptedDate.getFullYear(), mm = attemptedDate.getMonth() + 1, jj = attemptedDate.getDate(), mn = attemptedDate.getMinutes(), hh = attemptedDate.getHours();
			mm = ('0' + mm).slice(-2); // to format 01,02,03, ... 11,12
			$('#aa').val(aa);
			$('#mm').val(mm).trigger("change"); // selector element
			$('#jj').val(jj);
			$('#hh').val(hh);
			$('#mn').val(mn);
			console.log(aa + ' ' + mm + ' ' + jj + ' ' + hh + ' ' + mn);

			return updateText(); // not set if invalid date
		},
	});


	// Post locks: contain focus inside the dialog. If the dialog is shown, focus the first item.
	$('#post-lock-dialog .notification-dialog').on('keydown', function (e) {
		// Don't do anything when [tab] is pressed.
		if (e.which != 9)
			return;

		var target = $(e.target);

		// [shift] + [tab] on first tab cycles back to last tab.
		if (target.hasClass('wp-tab-first') && e.shiftKey) {
			$(this).find('.wp-tab-last').trigger('focus');
			e.preventDefault();
			// [tab] on last tab cycles back to first tab.
		} else if (target.hasClass('wp-tab-last') && !e.shiftKey) {
			$(this).find('.wp-tab-first').trigger('focus');
			e.preventDefault();
		}
	}).filter(':visible').find('.wp-tab-first').trigger('focus');

	// This code is meant to allow tabbing from Title to Post content.
	$('#title').on('keydown.editor-focus', function (event) {
		var editor;

		if (event.keyCode === 9 && !event.ctrlKey && !event.altKey && !event.shiftKey) {
			editor = typeof tinymce != 'undefined' && tinymce.get('content');

			if (editor && !editor.isHidden()) {
				editor.focus();
			} else if ($textarea.length) {
				$textarea.trigger('focus');
			} else {
				return;
			}

			event.preventDefault();
		}
	});




	// Resize the WYSIWYG and plain text editors.
	(function () {
		var editor, offset, mce,
			$handle = $('#post-status-info'),
			$postdivrich = $('#postdivrich');

		// If there are no textareas or we are on a touch device, we can't do anything.
		if (!$textarea.length || 'ontouchstart' in window) {
			// Hide the resize handle.
			$('#content-resize-handle').hide();
			return;
		}

		/**
		 * Handle drag event.
		 *
		 * @param {Object} event Event containing details about the drag.
		 */
		function dragging(event) {
			if ($postdivrich.hasClass('wp-editor-expand')) {
				return;
			}

			if (mce) {
				editor.theme.resizeTo(null, offset + event.pageY);
			} else {
				$textarea.height(Math.max(50, offset + event.pageY));
			}

			event.preventDefault();
		}

		/**
		 * When the dragging stopped make sure we return focus and do a sanity check on the height.
		 */
		function endDrag() {
			var height, toolbarHeight;

			if ($postdivrich.hasClass('wp-editor-expand')) {
				return;
			}

			if (mce) {
				editor.focus();
				toolbarHeight = parseInt($('#wp-content-editor-container .mce-toolbar-grp').height(), 10);

				if (toolbarHeight < 10 || toolbarHeight > 200) {
					toolbarHeight = 30;
				}

				height = parseInt($('#content_ifr').css('height'), 10) + toolbarHeight - 28;
			} else {
				$textarea.trigger('focus');
				height = parseInt($textarea.css('height'), 10);
			}

			$document.off('.wp-editor-resize');

			// Sanity check: normalize height to stay within acceptable ranges.
			if (height && height > 50 && height < 5000) {
				setUserSetting('ed_size', height);
			}
		}

		$handle.on('mousedown.wp-editor-resize', function (event) {
			if (typeof tinymce !== 'undefined') {
				editor = tinymce.get('content');
			}

			if (editor && !editor.isHidden()) {
				mce = true;
				offset = $('#content_ifr').height() - event.pageY;
			} else {
				mce = false;
				offset = $textarea.height() - event.pageY;
				$textarea.blur();
			}

			$document.on('mousemove.wp-editor-resize', dragging)
				.on('mouseup.wp-editor-resize mouseleave.wp-editor-resize', endDrag);

			event.preventDefault();
		}).on('mouseup.wp-editor-resize', endDrag);
	})();

	// TinyMCE specific handling of Post Format changes to reflect in the editor.
	if (typeof tinymce !== 'undefined') {
		// When changing post formats, change the editor body class.
		$('#post-formats-select input.post-format').on('change.set-editor-class', function () {
			var editor, body, format = this.id;

			if (format && $(this).prop('checked') && (editor = tinymce.get('content'))) {
				body = editor.getBody();
				body.className = body.className.replace(/\bpost-format-[^ ]+/, '');
				editor.dom.addClass(body, format == 'post-format-0' ? 'post-format-standard' : format);
				$(document).trigger('editor-classchange');
			}
		});

		// When changing page template, change the editor body class
		$('#page_template').on('change.set-editor-class', function () {
			var editor, body, pageTemplate = $(this).val() || '';

			pageTemplate = pageTemplate.substr(pageTemplate.lastIndexOf('/') + 1, pageTemplate.length)
				.replace(/\.php$/, '')
				.replace(/\./g, '-');

			if (pageTemplate && (editor = tinymce.get('content'))) {
				body = editor.getBody();
				body.className = body.className.replace(/\bpage-template-[^ ]+/, '');
				editor.dom.addClass(body, 'page-template-' + pageTemplate);
				$(document).trigger('editor-classchange');
			}
		});

	}

});

/**
 * TinyMCE word count display
 */
(function ($, counter) {
	$(function () {
		var $content = $('#content'),
			$count = $('#wp-word-count').find('.word-count'),
			prevCount = 0,
			contentEditor;

		/**
		 * Get the word count from TinyMCE and display it
		 */
		function update() {
			var text, count;

			if (!contentEditor || contentEditor.isHidden()) {
				text = $content.val();
			} else {
				text = contentEditor.getContent({ format: 'raw' });
			}

			count = counter.count(text);

			if (count !== prevCount) {
				$count.text(count);
			}

			prevCount = count;
		}

		/**
		 * Bind the word count update triggers.
		 *
		 * When a node change in the main TinyMCE editor has been triggered.
		 * When a key has been released in the plain text content editor.
		 */
		$(document).on('tinymce-editor-init', function (event, editor) {
			if (editor.id !== 'content') {
				return;
			}

			contentEditor = editor;

			editor.on('nodechange keyup', _.debounce(update, 1000));
		});

		$content.on('input keyup', _.debounce(update, 1000));

		update();
	});

})(jQuery, new wp.utils.WordCounter());

