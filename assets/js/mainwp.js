/* eslint-disable complexity */
// current complexity is the only way to achieve desired results, pull request solutions appreciated.

window.mainwpVars = window.mainwpVars || {};

jQuery(function ($) {

  if (jQuery('.mainwp-ui-page').length) {
    jQuery('.mainwp-popup-tooltip').popup()
  }

  jQuery(document).on('click', '#mainwp-help-menu-item', function () {
    jQuery('#mainwp-help-modal').modal({
      inverted: true,
      blurring: false,
      closable: false,
      onShow: function() {
          jQuery('#mainwp-help-modal').parent('.ui.dimmer').removeClass('dimmer');
          jQuery('#mainwp-help-modal').css({
            'top':(jQuery(window).height() - jQuery('#mainwp-help-modal').outerHeight()) / 2 + 'px',
            'left':(jQuery(window).width() - jQuery('#mainwp-help-modal').outerWidth()) / 2 + 'px'
          });
      },

    }).modal('show').draggable().resizable({
      handles: "n, e, s, w, ne, nw, se, sw", // Allows resizing from all edges
      minWidth: 300, // Set minimum width
      minHeight: 640, // Set minimum height
    });
    return false;
  });

  // review for new UI update
  jQuery(document).on('click', '#mainwp-category-add-submit', function () {
    let newCat = jQuery('#newcategory').val();
    if (jQuery('#categorychecklist .menu').find('.item[data-value="' + encodeURIComponent(newCat) + '"]').length > 0) {
      console.log('Existed: ' + encodeURIComponent(newCat));
      jQuery('#newcategory').val('');
      return;
    }
    let selected_categories = jQuery('#categorychecklist').dropdown('get value');
    jQuery('#categorychecklist .menu').append('<div class="item" data-value="' + encodeURIComponent(newCat) + '">' + newCat + '</div>');
    jQuery('#categorychecklist .menu').dropdown('set selected', selected_categories); // to refresh.
    jQuery('#newcategory').val('');
  });

  // Show/Hide new category field and button
  jQuery(document).on('click', '#category-add-toggle', function () {
    jQuery('#newcategory-field').toggle();
    jQuery('#mainwp-category-add-submit-field').toggle();
    return false;
  });

  // Manage Child options
  $('.mainwp-parent-toggle input[type="checkbox"]').on('change', function () {
    if (this.checked) {
      $(this).closest('.mainwp-parent-toggle').next('.mainwp-child-field').fadeIn();
    } else {
      $(this).closest('.mainwp-parent-toggle').next('.mainwp-child-field').fadeOut();
    }
  });

  //Toggle Add Site form
  jQuery('#mainwp_managesites_verify_installed_child').on('change', function () {
    if (jQuery(this).is(':checked')) {
      jQuery('#mainwp-add-site-hidden-form').fadeIn(500);
    } else {
      jQuery('#mainwp-add-site-hidden-form').fadeOut(500);
    }
  });

  // Toggle Add Site / Optional Settings section
  jQuery('#mainwp-add-site-advanced-options-toggle').on('click', function () {
    jQuery('#mainwp-add-site-advanced-options').toggle(500);
    return false;
  });

  jQuery('.mainwp-remove-site-button').on('click', function () {
    let side_id = jQuery(this).attr('site-id');
    let confirmation = "Are you sure you want to remove this site from your MainWP Dashboard?";

    let _confirm_callback = mainwp_get_remove_calback(side_id);

    mainwp_confirm(confirmation, _confirm_callback, false, false, false, 'REMOVE');

    return false;
  });

});

let mainwp_get_remove_calback = function (side_id) {
  return function () {
    feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Removing the site. Please wait...', 'mainwp'), '');
    let data = mainwp_secure_data({
      action: 'mainwp_removesite',
      id: side_id
    });

    jQuery.post(ajaxurl, data, function (response) {

      let error = false;

      if (response.error != undefined) {
        error = response.error;
      } else if (response.result == 'SUCCESS') {
        feedback('mainwp-message-zone', __('The site has been removed and the MainWP Child plugin has been disabled. You will be redirected to the Sites page right away.', 'mainwp'), 'green');
      } else if (response.result == 'NOSITE') {
        feedback('mainwp-message-zone', __('Site could not be removed. Please reload the page and try again.', 'mainwp'), 'red');
        error = true;
      } else {
        feedback('mainwp-message-zone', __('The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly. You will be redirected to the Sites page right away.', 'mainwp'), 'green');
      }

      if (!error) {
        setTimeout(function () {
          window.location = 'admin.php?page=managesites';
        }, 3000);
      }

    }, 'json');
  }

}

window.mainwp_set_message_zone = window.mainwp_set_message_zone || function (zone_selector, msg_html, colors, show) {
  if (msg_html) {
    jQuery(zone_selector).html(msg_html);
  } else if (msg_html === '' || msg_html === undefined) {
    jQuery(zone_selector).html('');
  }

  if (typeof colors !== "undefined" && colors != '') {
    jQuery(zone_selector).removeClass('green yellow red');
    jQuery(zone_selector).addClass(colors);
  } else if (colors === '' || colors === undefined) {
    jQuery(zone_selector).removeClass('green yellow red');
  }

  if (true === show || (false !== show && msg_html)) {
    jQuery(zone_selector).show();
  } else {
    jQuery(zone_selector).hide();
  }
};

let bulkInstallMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
let bulkInstallCurrentThreads = 0;
let bulkInstallDone = 0;

/**
 * Global
 */
jQuery(function () {
  jQuery('.mainwp-row').on({
    mouseenter: function () {
      rowMouseEnter(this);
    },
    mouseleave: function () {
      rowMouseLeave(this);
    }
  });
});
let rowMouseEnter = function (elem) {
  if (!jQuery(elem).children('.mainwp-row-actions-working').is(":visible"))
    jQuery(elem).children('.mainwp-row-actions').show();
};
let rowMouseLeave = function (elem) {
  if (jQuery(elem).children('.mainwp-row-actions').is(":visible"))
    jQuery(elem).children('.mainwp-row-actions').hide();
};

/**
 * Recent posts
 */
jQuery(function () {
  jQuery(document).on('click', '.mainwp-post-unpublish', function () {
    postAction(jQuery(this), 'unpublish');
    return false;
  });
  jQuery(document).on('click', '.mainwp-post-publish', function () {
    postAction(jQuery(this), 'publish');
    return false;
  });
  jQuery(document).on('click', '.mainwp-post-trash', function () {
    postAction(jQuery(this), 'trash');
    return false;
  });
  jQuery(document).on('click', '.mainwp-post-restore', function () {
    postAction(jQuery(this), 'restore');
    return false;
  });
  jQuery(document).on('click', '.mainwp-post-delete', function () {
    postAction(jQuery(this), 'delete');
    return false;
  });

});

// Publish, Unpublish, Trash, ... posts and pages
let postAction = function (elem, what) {
  let rowElement = jQuery(elem).closest('.grid');
  let postId = rowElement.children('.postId').val();
  let websiteId = rowElement.children('.websiteId').val();

  let data = mainwp_secure_data({
    action: 'mainwp_post_' + what,
    postId: postId,
    websiteId: websiteId
  });
  rowElement.hide();
  rowElement.next('.mainwp-row-actions-working').show();
  jQuery.post(ajaxurl, data, function (response) {
    if (response.error) {
      rowElement.show();
      rowElement.next('.mainwp-row-actions-working').hide();
      rowElement.html('<div class="sixteen wide column"><i class="times red icon"></i> ' + response.error + '</div>');
    } else if (response.result) {
      rowElement.show();
      rowElement.next('.mainwp-row-actions-working').hide();
      rowElement.html('<div class="sixteen wide column"><i class="check green icon"></i>' + response.result + '</div>');
    } else {
      rowElement.show();
      rowElement.next('.mainwp-row-actions-working').hide();
    }
  }, 'json');
  return false;
};


let mainwp_post_posting_start_next = function (start) {
  if (typeof start !== "undefined" && start) {
    bulkInstallDone = 0;
    bulkInstallCurrentThreads = 0;
    mainwpVars.bulkInstallTotal = jQuery('.site-bulk-posting[status="queue"]').length;
  }
  while ((siteToPosting = jQuery('.site-bulk-posting[status="queue"]:first')) && (siteToPosting.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) { // NOSONAR -- modified out side the function.
    mainwp_post_posting_start_specific(siteToPosting);
  }
};

let mainwp_post_posting_start_specific = function (siteToPosting) {
  siteToPosting.attr('status', 'progress');
  bulkInstallDone++;
  bulkInstallCurrentThreads++;
  let data = mainwp_secure_data({
    action: 'mainwp_post_postingbulk',
    post_id: jQuery('#bulk_posting_id').val(),
    site_id: jQuery(siteToPosting).attr('site-id'),
    count: bulkInstallDone,
    total: mainwpVars.bulkInstallTotal,
    delete_bulkpost: (bulkInstallDone == mainwpVars.bulkInstallTotal)
  });
  siteToPosting.find('.progress').html('<i class="notched circle loading icon"></i>');
  jQuery.post(ajaxurl, data, function (response) {
    bulkInstallCurrentThreads--;
    if (response?.result) {
      siteToPosting.find('.progress').html(response.result);
      if (response.edit_link !== '') {
        siteToPosting.after(response.edit_link);
      }
    }
    mainwp_post_posting_start_next();
  }, 'json');
}


/**
 * Plugins Widget
 */
jQuery(function () {
  jQuery(document).on('click', '.mainwp-plugin-deactivate', function () {
    pluginAction(jQuery(this), 'deactivate');
    return false;
  });
  jQuery(document).on('click', '.mainwp-plugin-activate', function () {
    pluginAction(jQuery(this), 'activate');
    return false;
  });
  jQuery(document).on('click', '.mainwp-plugin-delete', function () {
    pluginAction(jQuery(this), 'delete');
    return false;
  });
});


let pluginAction = function (elem, what) {
  let rowElement = jQuery(elem).closest('.row-manage-item');
  let plugin = rowElement.children('.pluginSlug').val();
  let websiteId = rowElement.children('.websiteId').val();

  let data = mainwp_secure_data({
    action: 'mainwp_widget_plugin_' + what,
    plugin: plugin,
    websiteId: websiteId
  });
  plugin_theme_doAction(data, rowElement);
  return false;
};

/**
 * Themes Widget
 */
jQuery(function () {
  jQuery(document).on('click', '.mainwp-theme-activate', function () {
    themeAction(jQuery(this), 'activate');
    return false;
  });
  jQuery(document).on('click', '.mainwp-theme-delete', function () {
    themeAction(jQuery(this), 'delete');
    return false;
  });
});

let themeAction = function (elem, what) {
  let rowElement = jQuery(elem).closest('.row-manage-item');
  let theme = rowElement.children('.themeSlug').val();
  let websiteId = rowElement.children('.websiteId').val();
  let data = mainwp_secure_data({
    action: 'mainwp_widget_theme_' + what,
    theme: theme,
    websiteId: websiteId
  });
  plugin_theme_doAction(data, rowElement);
  return false;
};

let plugin_theme_doAction = function (data, rowElement) {
  rowElement.children().hide();
  rowElement.children('.mainwp-row-actions-working').show();
  jQuery.post(ajaxurl, data, function (response) {
    if (response?.error) {
      rowElement.children().show();
      rowElement.html(response.error);
    } else if (response?.result) {
      rowElement.children().show();
      rowElement.html(response.result);
    } else {
      rowElement.children('.mainwp-row-actions-working').hide();
    }
  }, 'json');
};


// offsetRelative (or, if you prefer, positionRelative)
(function ($) {
  $.fn.offsetRelative = function (top) {
    let $this = $(this);
    let $parent = $this.offsetParent();
    let offset = $this.position();
    if (!top)
      return offset; // Didn't pass a 'top' element
    else if ($parent.get(0).tagName == "BODY")
      return offset; // Reached top of document
    else if ($(top, $parent).length)
      return offset; // Parent element contains the 'top' element we want the offset to be relative to
    else if ($parent[0] == $(top)[0])
      return offset; // Reached the 'top' element we want the offset to be relative to
    else { // Get parent's relative offset
      let parent_offset = $parent.offsetRelative(top);
      offset.top += parent_offset.top;
      offset.left += parent_offset.left;
      return offset;
    }
  };
  $.fn.positionRelative = function (top) {
    return $(this).offsetRelative(top);
  };
})(jQuery);

let hidingSubMenuTimers = {};
jQuery(function () {
  jQuery('.mainwp-submenu-wrapper').on({
    mouseenter: function () {
      let spanId = /^menu-mainwp-(.*)$/.exec(jQuery(this).attr('id'));
      if (spanId) {
        if (hidingSubMenuTimers[spanId[1]]) {
          clearTimeout(hidingSubMenuTimers[spanId[1]]);
        }
      }
    },
    mouseleave: function () {
      let spanId = /^menu-mainwp-(.*)$/.exec(jQuery(this).attr('id'));
      if (spanId) {
        hidingSubMenuTimers[spanId[1]] = setTimeout(function (span) {
          return function () {
            subMenuOut(span);
          };
        }(spanId[1]), 30);
      }
    }
  });
});
let subMenuOut = function (subName) {
  jQuery('#menu-mainwp-' + subName).hide();
  jQuery('#mainwp-' + subName).parent().parent().css('background-color', '');
  jQuery('#mainwp-' + subName).parent().parent().removeClass('hoverli');
  jQuery('#mainwp-' + subName).css('color', '');
};
// eslint-disable-next-line complexity
window.mainwp_js_get_error_not_detected_connect = function (jsonStr, what, elemId, retErrText) { // NOSONAR - complexity.
  if (undefined !== jsonStr && '' != jsonStr && undefined !== what && 'html_msg' === what) {
    try {
      let obj_err = JSON.parse(jsonStr);
      if (typeof obj_err === 'object') {
        if (obj_err?.el_before && obj_err?.el_link && obj_err?.el_text) {
          let elafter = obj_err.el_after !== undefined ? obj_err.el_after : '';
          if (true === retErrText) {
            return obj_err.el_before + obj_err.el_text + elafter;
          }
          if (undefined !== elemId && '' != elemId) {
            let el = document.createElement('a');
            el.text = obj_err.el_text;
            el.href = obj_err.el_link;
            el.target = "_blank";
            console.log(el);
            jQuery('#' + elemId).html('').append(document.createTextNode(obj_err.el_before), el, document.createTextNode(elafter));
            feedback_scroll(elemId, 'red');
          }
          return true;
        }
      } else {
        return false; // it is not or invalid json.
      }
    } catch (e) {
      return false; // it is not or invalid json.
    }
  }
  return __('MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, check the MainWP Community for help.');
}

window.mainwp_get_reconnect_error = function (response, siteId) { // NOSONAR - complexity.
  if ('reconnect_failed' === response) {
    return __('Reconnect failed. Please try again from the %1Site Settings page%2.', '<a href="admin.php?page=managesites&id=' + siteId + '">', '</a>');
  } else {
    return response;
  }
}

function shake_element(select) {
  let pos = jQuery(select).position();
  let type = jQuery(select).css('position');

  if (type == 'static') {
    jQuery(select).css({
      position: 'relative'
    });
  }

  if (type == 'static' || type == 'relative') {
    pos.top = 0;
    pos.left = 0;
  }

  jQuery(select).data('init-type', type);

  let shake = [[0, 5, 60], [0, 0, 60], [0, -5, 60], [0, 0, 60], [0, 2, 30], [0, 0, 30], [0, -2, 30], [0, 0, 30]];

  for (let s of shake) {
    jQuery(select).animate({
      top: pos.top + s[0],
      left: pos.left + s[1]
    }, s[2], 'linear');
  }
}


/**
 * Required
 */
window.feedback = function (id, text, type, append) {
  if (append) {
    let currentHtml = jQuery('#' + id).html();
    if (currentHtml == null)
      currentHtml = "";
    if (currentHtml != '') {
      currentHtml += '<br />' + text;
    } else {
      currentHtml = text;
    }
    jQuery('#' + id).html(currentHtml);
    jQuery('#' + id).removeClass('yellow');
    jQuery('#' + id).removeClass('green');
    jQuery('#' + id).removeClass('red');
    jQuery('#' + id).addClass(type);
  } else {
    jQuery('#' + id).html(text);
    jQuery('#' + id).removeClass('yellow');
    jQuery('#' + id).removeClass('green');
    jQuery('#' + id).removeClass('red');
    jQuery('#' + id).addClass(type);
  }
  jQuery('#' + id).show();

  // automatically scroll to error message if it's not visible
  scrollElementTop(id);
};

window.feedback_scroll = function (id, color) {
  jQuery('#' + id).removeClass('green red yellow');
  jQuery('#' + id).addClass(color);
  jQuery('#' + id).show();
  // automatically scroll to error message if it's not visible
  scrollElementTop(id);
}

window.scrollElementTop = function (id) {
  let scrolltop = jQuery(window).scrollTop();
  if (jQuery('#' + id).length == 0) {
    return;
  }
  let off = jQuery('#' + id).offset();
  if (scrolltop > off.top - 40)
    jQuery('html, body').animate({
      scrollTop: off.top - 40
    }, 1000, function () {
      shake_element('#' + id)
    });
  else
    shake_element('#' + id); // shake the error message to get attention :)
}

window.mainwp_showhide_message = function (id, content, cls, append, scroll) {

  if ('' === content) {
    jQuery('#' + id).html('').fadeOut(500);
    return;
  }

  if (append) {
    let html = jQuery('#' + id).html();
    if (html == null)
      html = "";
    if (html != '') {
      html += '<br />' + content;
    } else {
      html = content;
    }
    jQuery('#' + id).html(html);
  } else {
    jQuery('#' + id).html(content);
  }

  jQuery('#' + id).removeClass('yellow green red');
  jQuery('#' + id).addClass(cls);
  jQuery('#' + id).show();

  if (typeof scroll !== 'undefined' && scroll) {
    scrollElementTop(id);
  }

};

jQuery(function () {
  jQuery('div.mainwp-hidden').parent().parent().css("display", "none");
});

/**
 * Security Issues
 */

let securityIssues_fixes = [ 'core_updates', 'plugin_updates', 'theme_updates', 'db_reporting', 'php_reporting', 'wp_uptodate', 'phpversion_matched', 'sslprotocol', 'debug_disabled', 'sec_outdated_plugins', 'sec_inactive_plugins', 'sec_outdated_themes', 'sec_inactive_themes' ];
jQuery(function () {
  let securityIssueSite = jQuery('#securityIssueSite');
  if ((securityIssueSite.val() != null) && (securityIssueSite.val() != "")) {
    jQuery(document).on('click', '#securityIssues_refresh', function () {
      for (let ise of securityIssues_fixes) {
        let securityIssueCurrentIssue = jQuery('#' + ise + '_fix');
        if (securityIssueCurrentIssue) {
          securityIssueCurrentIssue.hide();
        }
        jQuery('#' + ise + '_extra').hide();
        jQuery('#' + ise + '_ok').hide();
        jQuery('#' + ise + '_nok').hide();
        jQuery('#' + ise + '_loading').show();
      }
      securityIssues_request(jQuery('#securityIssueSite').val());
    });

    for (let ise of securityIssues_fixes) {
        if(ise === 'wp_uptodate' || ise === 'sec_inactive_themes' || ise === 'sec_inactive_plugins' || ise === 'sec_outdated_plugins' || ise === 'sec_outdated_themes' ){
            continue;
        }
      jQuery('#' + ise + '_fix').on('click', function (what) {
        return function () {
          securityIssues_fix(what);
          return false;
        }
      }(ise));

      jQuery('#' + ise + '_unfix').on('click', function (what) {
        return function () {
          securityIssues_unfix(what);
          return false;
        }
      }(ise));
    }
    securityIssues_request(securityIssueSite.val());
  }
});
window.securityIssues_fix = function (feature) {
    if (jQuery('#' + feature + '_fix')) {
        jQuery('#' + feature + '_fix').hide();
    }
    jQuery('#' + feature + '_extra').hide();
    jQuery('#' + feature + '_ok').hide();
    jQuery('#' + feature + '_nok').hide();
    jQuery('#' + feature + '_loading').show();

  let data = mainwp_secure_data({
    action: 'mainwp_security_issues_fix',
    feature: feature,
    id: jQuery('#securityIssueSite').val()
  });

  jQuery.post(ajaxurl, data, function (response) {
    securityIssues_handle(response);
  }, 'json');
};

// Fix all sites all security issues
jQuery(document).on('click', '.fix-all-security-issues', function () {

  jQuery('#mainwp-secuirty-issues-loader').show();

  jQuery('#mainwp-security-issues-widget-list').show();
  mainwpVars.bulkInstallTotal = jQuery('#mainwp-security-issues-widget-list .item[status="queue"]').length;
  jQuery('.fix-all-site-security-issues').addClass('disabled');
  jQuery('.unfix-all-site-security-issues').addClass('disabled');
  mainwp_fix_all_security_issues_start_next();
});

let mainwp_fix_all_security_issues_start_next = function () {
  while ((siteToFix = jQuery('#mainwp-security-issues-widget-list .item[status="queue"]:first')) && (siteToFix.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) { // NOSONAR -- modified out side the function.
    mainwp_fix_all_security_issues_specific(siteToFix);
  }
}

let mainwp_fix_all_security_issues_specific = function (siteToFix) {

  bulkInstallCurrentThreads++;

  siteToFix.attr('status', 'progress');

  let data = mainwp_secure_data({
    action: 'mainwp_security_issues_fix',
    feature: 'all',
    id: siteToFix.attr('siteid')
  });

  let el = siteToFix.find('.fix-all-site-security-issues');
  el.hide();

  jQuery.post(ajaxurl, data, function () {
    return function () {
      siteToFix.attr('status', 'done');
      el.show();
      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      if (bulkInstallDone != 0 && (mainwpVars.bulkInstallTotal == 1 || (bulkInstallDone >= mainwpVars.bulkInstallTotal))) { // NOSONAR - modified outside the function.
        window.location.href = location.href;
      }
      mainwp_fix_all_security_issues_start_next();
    }
  }(), 'json');
}

// Fix all securtiy issues for a site
jQuery(document).on('click', '.fix-all-site-security-issues', function () {
  jQuery('#mainwp-secuirty-issues-loader').show();
  mainwp_fix_all_security_issues(jQuery(this).closest('.item').attr('siteid'), true);
});

let mainwp_fix_all_security_issues = function (siteId, refresh) {
  let data = mainwp_secure_data({
    action: 'mainwp_security_issues_fix',
    feature: 'all',
    id: siteId
  });

  let el = jQuery('#mainwp-security-issues-widget-list .item[siteid="' + siteId + '"] .fix-all-site-security-issues');

  el.hide();

  jQuery('.fix-all-site-security-issues').addClass('disabled');
  jQuery('.unfix-all-site-security-issues').addClass('disabled');

  jQuery.post(ajaxurl, data, function () {
    el.show();
    if (refresh) {
      window.location.href = location.href;
    };
  }, 'json');
};

jQuery(document).on('click', '.unfix-all-site-security-issues', function () {

  jQuery('#mainwp-secuirty-issues-loader').show();

  let data = mainwp_secure_data({
    action: 'mainwp_security_issues_unfix',
    feature: 'all',
    id: jQuery(jQuery(this).parents('.item')[0]).attr('siteid')
  });

  jQuery(this).hide();
  jQuery('.fix-all-site-security-issues').addClass('disabled');
  jQuery('.unfix-all-site-security-issues').addClass('disabled');

  jQuery.post(ajaxurl, data, function () {
    window.location.href = location.href;
  }, 'json');
});
let securityIssues_unfix = function (feature) {
  if (jQuery('#' + feature + '_unfix')) {
    jQuery('#' + feature + '_unfix').hide();
  }
  jQuery('#' + feature + '_extra').hide();
  jQuery('#' + feature + '_ok').hide();
  jQuery('#' + feature + '_nok').hide();
  jQuery('#' + feature + '_loading').show();

  let data = mainwp_secure_data({
    action: 'mainwp_security_issues_unfix',
    feature: feature,
    id: jQuery('#securityIssueSite').val()
  });
  jQuery.post(ajaxurl, data, function (response) {
    securityIssues_handle(response);
  }, 'json');
};
let securityIssues_request = function (websiteId) {
  let data = mainwp_secure_data({
    action: 'mainwp_security_issues_request',
    id: websiteId
  });
  jQuery.post(ajaxurl, data, function (response) {
    securityIssues_handle(response);
  }, 'json');
};
// eslint-disable-next-line complexity
let securityIssues_handle = function (response) { // NOSONAR - complex.
  let result = '';
  if (response.error) {
    result = getErrorMessage(response.error);
  } else {
    try {
      let res = response.result;
      for (let issue in res) {
        if (jQuery('#' + issue + '_loading')) {
          jQuery('#' + issue + '_loading').hide();
          if (res[issue] == 'Y' || res[issue] == 'Y_UNABLE') {
            jQuery('#' + issue + '_extra').hide();
            jQuery('#' + issue + '_nok').hide();
            if (jQuery('#' + issue + '_fix')) {
              jQuery('#' + issue + '_fix').hide();
            }

            if (jQuery('#' + issue + '_unfix')) {
              jQuery('#' + issue + '_unfix').show();
              if (res[issue] == 'Y_UNABLE') { // Y_UNABLE will disable unfix.
                jQuery('#' + issue + '_unfix').hide();
              }
            }

            jQuery('#' + issue + '_ok').show();
            jQuery('#' + issue + '-status-ok').show();
            jQuery('#' + issue + '-status-nok').hide();
          } else if (res[issue] == 'N' || res[issue] == 'N_UNABLE') {
            jQuery('#' + issue + '_extra').hide();
            jQuery('#' + issue + '_ok').hide();
            jQuery('#' + issue + '_nok').show();

            if (jQuery('#' + issue + '_fix')) {
              jQuery('#' + issue + '_fix').show();
              if (res[issue] == 'N_UNABLE') { // N_UNABLE will disable fix.
                jQuery('#' + issue + '_fix').hide().after('<a href="javascript:void(0);" class="ui mini fluid button" disabled="disabled">fix</a>');
              }
            }

            if (jQuery('#' + issue + '_unfix')) {
              jQuery('#' + issue + '_unfix').hide();
            }
            if (res[issue] != 'N') {
              jQuery('#' + issue + '_extra').html(res[issue]);
              jQuery('#' + issue + '_extra').show();
            }

            if('wp_uptodate' === issue){
                jQuery('#wp_upgrades').find('div[updated="-1"]').each(function(){
                    jQuery(this).attr('updated', 0);
                });
            }
            jQuery('#' + issue + '-status-ok').hide();
            jQuery('#' + issue + '-status-nok').show();
          }
        }
      }
    } catch (err) {
      result = '<i class="exclamation circle icon"></i> ' + __('Undefined error!');
    }
  }
  if (result != '') {
    //show error!
  }
};

window.updatesoverview_bulk_check_abandoned = function (which) {
  let confirmMsg;
  if ('plugin' == which) {
    confirmMsg = __("You are about to check abandoned plugins on the sites?");
  } else {
    confirmMsg = __("You are about to check abandoned themes on the sites?");
  }
  mainwp_confirm(confirmMsg, function () { mainwp_managesites_bulk_check_abandoned('all', which); });
}

window.mainwp_managesites_bulk_check_abandoned = function (siteIds, which) {
  let allWebsiteIds = jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
    return jQuery(el).val();
  });

  if ('all' == siteIds) {
    siteIds = allWebsiteIds;
  }

  let selectedIds = [], excludeIds = [];
  if (siteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, siteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (let id of excludeIds) {
      dashboard_update_site_hide(id);
    }
    allWebsiteIds = selectedIds;
  }

  let nrOfWebsites = allWebsiteIds.length;

  if (nrOfWebsites == 0) {
    managesites_reset_bulk_actions_params();
    return;
  }

  let siteNames = {};

  for (let id of allWebsiteIds) {
    dashboard_update_site_status(id, '<i class="clock outline icon"></i>');
    siteNames[id] = jQuery('.sync-site-status[siteid="' + id + '"]').attr('niceurl');
  }
  let initData = {
    progressMax: nrOfWebsites,
    title: 'Check abandoned ' + ('plugin' == which ? 'plugins' : 'themes'),
    statusText: __('started'),
    callback: function () {
      mainwpVars.bulkManageSitesTaskRunning = false;
      window.location.href = location.href;
    }
  };
  mainwpPopup('#mainwp-sync-sites-modal').init(initData);

  mainwp_managesites_check_abandoned_all_int(allWebsiteIds, which);
};

let mainwp_managesites_check_abandoned_all_int = function (websiteIds, which) {
  mainwpVars.websitesToUpgrade = websiteIds;
  mainwpVars.currentWebsite = 0;
  mainwpVars.websitesDone = 0;
  mainwpVars.websitesTotal = mainwpVars.websitesToUpgrade.length;
  mainwpVars.websitesLeft = mainwpVars.websitesToUpgrade.length;

  mainwpVars.bulkTaskRunning = true;
  mainwp_managesites_check_abandoned_all_loop_next(which);
};

let mainwp_managesites_check_abandoned_all_loop_next = function (which) {
  while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
    mainwp_managesites_check_abandoned_all_upgrade_next(which);
  }
};
let mainwp_managesites_check_abandoned_all_upgrade_next = function (which) {
  mainwpVars.currentThreads++;
  mainwpVars.websitesLeft--;

  let websiteId = mainwpVars.websitesToUpgrade[mainwpVars.currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

  mainwp_managesites_check_abandoned_int(websiteId, which);
};

let mainwp_managesites_check_abandoned_int = function (siteid, which) {

  let data = mainwp_secure_data({
    action: 'mainwp_check_abandoned',
    siteId: siteid,
    which: which
  });

  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pSiteid) {
      return function (response) {
        mainwpVars.currentThreads--;
        mainwpVars.websitesDone++;
        mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);
        if (response.error != undefined) {
          dashboard_update_site_status(pSiteid, '<i class="red times icon"></i>');
        } else if (response.result && response.result == 'success') {
          dashboard_update_site_status(pSiteid, '<i class="green check icon"></i>', true);
        } else {
          dashboard_update_site_status(pSiteid, '<i class="red times icon"></i>');
        }
        mainwp_managesites_check_abandoned_all_loop_next(which);
      }
    }(siteid),
    dataType: 'json'
  });
};

/**
 * MainWP UI.
 */
jQuery(function () {
  jQuery('#reset-overview-settings').on('click', function () {
    mainwp_confirm(__('Are you sure?'), function () {
      let which_set = jQuery('input[name=reset_overview_which_settings]').val();
      if ('sidebar_settings' == which_set) {
        jQuery('#mainwp_sidebarPosition').dropdown('set selected', 1);
      } else if ('overview_settings' == which_set) {
        jQuery('input[name=hide_update_everything]').prop('checked', false);
        jQuery('.mainwp_hide_wpmenu_checkboxes input[name="mainwp_show_widgets[]"]').prop('checked', true);
      }
      if (jQuery('input[name=mainwp_manageposts_show_columns_settings]').length > 0 || jQuery('input[name=mainwp_managepages_show_columns_settings]').length > 0 || jQuery('input[name=mainwp_manageusers_show_columns_settings]').length > 0) {
        jQuery('input[name="mainwp_show_columns[]"]').prop('checked', true);
      }
      jQuery('input[name=reset_overview_settings]').attr('value', 1);
      jQuery('#submit-overview-settings').click();
    }, false, false, true);
    return false;
  });
});

/**
 * Sync Sites
 */

jQuery(function () {
  jQuery('#mainwp-sync-sites').on('click', function () {
    mainwp_sync_sites_data();
  });

  // to compatible with extensions
  jQuery('#dashboard_refresh').on('click', function () {
    mainwp_sync_sites_data();
  });
  jQuery('.mainwp-sync-this-site').on('click', function () {
    let syncSiteIds = [];
    syncSiteIds.push(jQuery(this).attr('site-id'));
    mainwp_sync_sites_data(syncSiteIds);
  });
});

window.mainwp_sync_sites_data = function (syncSiteIds, pAction) {
  let allWebsiteIds = [];
  jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
    allWebsiteIds.push(jQuery(el).val());
  });
  let globalSync = true;
  let selectedIds = [], excludeIds = [];
  if (syncSiteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, syncSiteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (let id of excludeIds) {
      dashboard_update_site_hide(id);
    }
    allWebsiteIds = selectedIds;
    globalSync = false;
  }

  for (let id of allWebsiteIds) {
    dashboard_update_site_status(id, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Pending', 'mainwp') + '"><i class="clock outline icon"></i></span>');
  }

  let nrOfWebsites = allWebsiteIds.length;

  mainwpPopup('#mainwp-sync-sites-modal').init({
    title: (pAction == 'checknow' ? __('Check Now') : __('Data Synchronization')),
    progressMax: nrOfWebsites,
    statusText: (pAction == 'checknow' ? 'checked' : 'synced'),
    callback: function () {
      mainwpVars.bulkTaskRunning = false;
      history.pushState("", document.title, window.location.pathname + window.location.search); // to fix issue for url with hash
      window.location.href = location.href;
    }
  });

  if (jQuery('#mainwp-sync-sites-modal').attr('current-wpid') > 0) {
    globalSync = false;
  }

  dashboard_update(allWebsiteIds, globalSync, pAction);

  if (pAction != 'checknow') {
    if (nrOfWebsites > 0) {
      let data = {
        action: 'mainwp_status_saving',
        status: 'last_sync_sites',
        isGlobalSync: globalSync ? 1 : 0
      };
      jQuery.post(ajaxurl, mainwp_secure_data(data), function () {

      });
    }
  }
};

mainwpVars.websitesToUpdate = [];
mainwpVars.websitesTotal = 0;
mainwpVars.websitesLeft = 0;
mainwpVars.websitesDone = 0;
mainwpVars.currentWebsite = 0;
mainwpVars.bulkTaskRunning = false;
mainwpVars.currentThreads = 0;
mainwpVars.maxThreads = mainwpParams['maximumSyncRequests'] == undefined ? 8 : mainwpParams['maximumSyncRequests'];
let globalSync = true;

window.dashboard_update = function (websiteIds, isGlobalSync, pAction) {
  mainwpVars.websitesToUpdate = websiteIds;
  mainwpVars.currentWebsite = 0;
  mainwpVars.websitesDone = 0;
  mainwpVars.websitesTotal = mainwpVars.websitesLeft = mainwpVars.websitesToUpdate.length;
  globalSync = isGlobalSync;

  mainwpVars.bulkTaskRunning = true;

  if (mainwpVars.websitesTotal == 0) {
    dashboard_update_done(pAction);
  } else {
    dashboard_loop_next(pAction);
  }
};

window.dashboard_update_site_status = function (siteId, newStatus, isSuccess) {
  jQuery('.sync-site-status[siteid="' + siteId + '"]').html(newStatus);
  // Move successfully synced site to the bottom of the sync list
  if (typeof isSuccess !== 'undefined' && isSuccess) {
    let row = jQuery('.sync-site-status[siteid="' + siteId + '"]').closest('.item');
    jQuery(row).insertAfter(jQuery("#sync-sites-status .item").not('.disconnected-site').last());
  }
};

window.dashboard_update_site_hide = function (siteId) {
  jQuery('.sync-site-status[siteid="' + siteId + '"]').closest('.item').hide();
};

let dashboard_loop_next = function (pAction) {
  while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) {
    dashboard_update_next(pAction);
  }
};

let dashboard_update_done = function (pAction) {
  mainwpVars.currentThreads--;
  if (!mainwpVars.bulkTaskRunning)
    return;
  mainwpVars.websitesDone++;
  if (mainwpVars.websitesDone > mainwpVars.websitesTotal)
    mainwpVars.websitesDone = mainwpVars.websitesTotal;

  mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

  if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
    let successSites = jQuery('#mainwp-sync-sites-modal .check.green.icon').length;
    if (mainwpVars.websitesDone == successSites) {
      mainwpVars.bulkTaskRunning = false;
      setTimeout(function () {
        mainwpPopup('#mainwp-sync-sites-modal').close(true);
      }, 3000);
    } else {
      mainwpVars.bulkTaskRunning = false;
    }
    return;
  }

  dashboard_loop_next(pAction);
};

let dashboard_update_next = function (pAction) {
  mainwpVars.currentThreads++;
  mainwpVars.websitesLeft--;
  let websiteId = mainwpVars.websitesToUpdate[mainwpVars.currentWebsite++];
  if ('checknow' == pAction) {
    dashboard_update_site_status(websiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Checking uptime status...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  } else {
    dashboard_update_site_status(websiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Syncing data...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  }

  let data = mainwp_secure_data({
    action: ('checknow' == pAction ? 'mainwp_checksites' : 'mainwp_syncsites'),
    wp_id: websiteId,
    isGlobalSync: globalSync
  });



  dashboard_update_next_int(websiteId, data, 0, pAction);
};

let dashboard_update_next_int = function (websiteId, data, errors, action) {
  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pWebsiteId, pAction) {
      return function (response) {
        if (response.error) {
          let extErr = response.error;
          dashboard_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + extErr + '"><i class="exclamation red icon"></i></span>');
        } else {
          dashboard_update_site_status(websiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Synchronization process completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>', true);
        }
        dashboard_update_done(pAction);
      }
    }(websiteId, action),
    error: function (pWebsiteId, pData, pErrors, pAction) {
      return function () {
        if (pErrors > 5) {
          dashboard_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Process timed out. Please try again.', 'mainwp') + '"><i class="exclamation yellow icon"></i></span>');
          dashboard_update_done(pAction);
        } else {
          pErrors++;
          dashboard_update_next_int(pWebsiteId, pData, pErrors, pAction);
        }
      }
    }(websiteId, data, errors, action),
    dataType: 'json'
  });
};


/**
 * Delete site changes actions.
 */

let mainwp_delete_nonmainwp_data_start = function () {
    mainwp_delete_nonmainwp_data_start_next();
}

let mainwp_delete_nonmainwp_data_start_next = function () {
    while ((checkedBox = jQuery('#mainwp-module-log-records-body-table .check-column INPUT:checkbox:checked:first')) && (checkedBox.length > 0) && (bulkManageClientsCurrentThreads < bulkManageClientsMaxThreads)) { // NOSONAR -- modified out side the function.
        mainwp_delete_nonmainwp_data_next();
    }
}

let mainwp_delete_nonmainwp_data_next = function () {
  mainwpVars.currentThreads++;
  mainwpVars.websitesLeft--;
  let websiteId = mainwpVars.websitesToUpdate[mainwpVars.currentWebsite++];
  dashboard_update_site_status(websiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Deleting...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  let data = mainwp_secure_data({
    action: 'mainwp_delete_non_mainwp_actions',
    wp_id: websiteId,
  });
  mainwp_delete_nonmainwp_data_next_int(websiteId, data, 0);
};

let mainwp_delete_nonmainwp_data_next_int = function (websiteId, data, errors) {
  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pWebsiteId) {
      return function (response) {
        if (response.error) {
          let extErr = response.error;
          dashboard_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + extErr + '"><i class="exclamation red icon"></i></span>');
        } else {
          dashboard_update_site_status(websiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Process completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>', true);
        }
        mainwp_delete_nonmainwp_data_done();
      }
    }(websiteId),
    error: function (pWebsiteId, pData, pErrors) {
      return function () {
        if (pErrors > 5) {
          dashboard_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Process timed out. Please try again.', 'mainwp') + '"><i class="exclamation yellow icon"></i></span>');
          mainwp_delete_nonmainwp_data_done();
        } else {
          pErrors++;
          mainwp_delete_nonmainwp_data_next_int(pWebsiteId, pData, pErrors);
        }
      }
    }(websiteId, data, errors),
    dataType: 'json'
  });
};


let mainwp_delete_nonmainwp_data_done = function () {
  mainwpVars.currentThreads--;
  if (!mainwpVars.bulkTaskRunning)
    return;
  mainwpVars.websitesDone++;
  if (mainwpVars.websitesDone > mainwpVars.websitesTotal)
    mainwpVars.websitesDone = mainwpVars.websitesTotal;

  mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

  if (mainwpVars.websitesDone == mainwpVars.websitesTotal) {
    jQuery("#mainwp-non-mainwp-changes-table tbody").fadeOut("slow");
    let successSites = jQuery('#mainwp-sync-sites-modal .check.green.icon').length;
    if (mainwpVars.websitesDone == successSites) {
      mainwpVars.bulkTaskRunning = false;
      setTimeout(function () {
        mainwpPopup('#mainwp-sync-sites-modal').close(true);
      }, 3000);
    } else {
      mainwpVars.bulkTaskRunning = false;
    }
    return;
  }
  mainwp_delete_nonmainwp_data_loop_next();
};


let mainwp_tool_disconnect_sites = function () {

  mainwp_confirm('Are you sure that you want to disconnect your sites? This will function will break the connection and leave the MainWP Child plugin active and which makes your sites vulnerable.', function () {
    let allWebsiteIds = jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
      return jQuery(el).val();
    });

    for (let id of allWebsiteIds) {
      dashboard_update_site_status(id, '<i class="clock outline icon"></i>');
    }

    let nrOfWebsites = allWebsiteIds.length;

    mainwpPopup('#mainwp-sync-sites-modal').init({
      title: __('Disconnect All Sites'),
      progressMax: nrOfWebsites,
      statusText: __('disconnected'),
      callback: function () {
        window.location.href = location.href;
      }
    });

    mainwpVars.websitesToUpdate = allWebsiteIds;
    mainwpVars.currentWebsite = 0;
    mainwpVars.websitesDone = 0;
    mainwpVars.websitesTotal = mainwpVars.websitesLeft = mainwpVars.websitesToUpdate.length;

    mainwpVars.bulkTaskRunning = true;

    if (mainwpVars.websitesTotal == 0) {
      mainwp_tool_disconnect_sites_done();
    } else {
      mainwp_tool_disconnect_sites_loop_next();
    }
  }, false, false, false, 'DISCONNECT');
};

let mainwp_tool_disconnect_sites_done = function () {
  mainwpVars.currentThreads--;
  if (!mainwpVars.bulkTaskRunning)
    return;
  mainwpVars.websitesDone++;
  if (mainwpVars.websitesDone > mainwpVars.websitesTotal)
    mainwpVars.websitesDone = mainwpVars.websitesTotal;

  mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(mainwpVars.websitesDone);

  mainwp_tool_disconnect_sites_loop_next();
};

let mainwp_tool_disconnect_sites_loop_next = function () {
  while (mainwpVars.bulkTaskRunning && (mainwpVars.currentThreads < mainwpVars.maxThreads) && (mainwpVars.websitesLeft > 0)) { // NOSONAR - vars modified outside function.
    mainwp_tool_disconnect_sites_next();
  }
};

let mainwp_tool_disconnect_sites_next = function () {
  mainwpVars.currentThreads++;
  mainwpVars.websitesLeft--;
  let websiteId = mainwpVars.websitesToUpdate[mainwpVars.currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');
  let data = mainwp_secure_data({
    action: 'mainwp_disconnect_site',
    wp_id: websiteId
  });
  mainwp_tool_disconnect_sites_next_int(websiteId, data, 0);
};

let mainwp_tool_disconnect_sites_next_int = function (websiteId, data, errors) {
  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pWebsiteId) {
      return function (response) {
        if (response?.error) {
          dashboard_update_site_status(pWebsiteId, response.error + '<i class="exclamation red icon"></i>');
        } else if (response && response.result == 'success') {
          dashboard_update_site_status(websiteId, '<i class="check green icon"></i>', true);
        } else {
          dashboard_update_site_status(pWebsiteId, __('Undefined error!') + ' <i class="exclamation red icon"></i>');
        }
        mainwp_tool_disconnect_sites_done();
      }
    }(websiteId),
    error: function (pWebsiteId, pData, pErrors) {
      return function () {
        if (pErrors > 5) {
          dashboard_update_site_status(pWebsiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Process timed out. Please try again.', 'mainwp') + '"><i class="exclamation yellow icon"></i></span>');
          mainwp_tool_disconnect_sites_done();
        } else {
          pErrors++;
          mainwp_tool_disconnect_sites_next_int(pWebsiteId, pData, pErrors);
        }
      }
    }(websiteId, data, errors),
    dataType: 'json'
  });
};

let mainwp_tool_clear_activation_data = function (pObj) {
  let loc = jQuery(pObj).attr('href');
  mainwp_confirm('Are you sure?', function () {
    window.location = loc;
  });
};

/**
 * Manage sites page
 */

jQuery(function ($) {
  jQuery('#mainwp-backup-type').on('change', function () {
    if (jQuery(this).val() == 'full')
      jQuery('.mainwp-backup-full-exclude').show();
    else
      jQuery('.mainwp-backup-full-exclude').hide();
  });
  jQuery('.mainwp-checkbox-showhide-elements').on('click', function () {
    let hiel = $(this).attr('hide-parent');
    let chk = this;
    // support multi hide values.
    hiel.split(';').forEach(function (hi) {
      mainwp_showhide_elements(hi, $(chk).find('input').is(':checked'));
    });
  });

  jQuery('.mainwp-selecter-showhide-elements').on('change', function () {
    let hiel = $(this).attr('hide-parent');
    let hival = $(this).attr('hide-value');
    hival = hival.split(';'); // support multi hide values.
    let selectedval = $(this).val();
    mainwp_showhide_elements(hiel, hival.includes(selectedval));
  });
});

function mainwp_showhide_elements(attEl, valHi) {
  // support multi attr to hide.
  if (valHi) {
    attEl.split(';').forEach(function (att) {
      jQuery('[hide-element=' + att.trim() + ']').fadeOut(300);
      jQuery('[hide-sub-element=' + att.trim() + ']').fadeOut(300);
    });
  } else {
    attEl.split(';').forEach(function (att) {
      jQuery('[hide-element=' + att.trim() + ']').fadeIn(300);
      jQuery('[hide-sub-element=' + att.trim() + ']').fadeIn(300);
    });
  }
}


jQuery(function ($) {
  $('#mainwp_settings_verify_connection_method').on('change', function () {
    let selectedval = $(this).val();
    if (selectedval == 2) { // phpseclib.
      $('.mainwp-hide-elemenent-sign-algo').fadeOut(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo').fadeIn(200);
    }
  });

  $('#mainwp_managesites_edit_verify_connection_method').on('change', function () {
    let selectedval = $(this).val();
    if (selectedval == 2 || selectedval == 3) { // phpseclib.
      $('.mainwp-hide-elemenent-sign-algo').fadeOut(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo').fadeIn(200);
    }
  });


  $('#mainwp_managesites_edit_openssl_alg').on('change', function () {
    let selectedval = $(this).val();
    if (selectedval == 1) {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeIn(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeOut(200);
    }
  });

  $('#mainwp_settings_openssl_alg').on('change', function () {
    let selectedval = $(this).val();
    if (selectedval == 1) {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeIn(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeOut(200);
    }
  });

})

jQuery(function () {
  jQuery(document).on('change', '#mainwp_managesites_add_wpurl', function () {
    let url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    let protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();

    if (url.lastIndexOf('http://') === 0) {
      protocol = 'http';
      url = url.substring(7);
    } else if (url.lastIndexOf('https://') === 0) {
      protocol = 'https';
      url = url.substring(8);
    }

    if (jQuery('#mainwp_managesites_add_wpname').val() == '') {
      jQuery('#mainwp_managesites_add_wpname').val(url);
    }
    jQuery('#mainwp_managesites_add_wpurl').val(url);
    jQuery('#mainwp_managesites_add_wpurl_protocol').val(protocol).trigger("change");
  });

  // Trigger the single site reconnect process
  jQuery('.mainwp-manage-wpsites-table').on('click', '.mainwp_site_reconnect', function () {
    mainwp_managesites_reconnect(jQuery(this));
    return false;
  });

  jQuery('#mainwp-sites-previews').on('click', '.mainwp_site_card_reconnect', function () {
    mainwp_managesites_cards_reconnect(jQuery(this));
    return false;
  });

  jQuery('.mainwp-updates-overview-reconnect-site').on('click', function () {
    mainwp_site_overview_reconnect(jQuery(this));
    return false;
  });

  jQuery(".chk-sync-install-plugin").on('change', function () {
    let parent = jQuery(this).closest('.sync-ext-row');
    let opts = parent.find(".sync-options input[type='checkbox']");
    if (jQuery(this).is(':checked')) {
      opts.prop("checked", true);
    } else {
      opts.prop("checked", false);
      ///opts.attr( "disabled", "disabled" );
    }
  });

  managesites_init();
});

jQuery(document).on('change', '#mainwp_managesites_verify_installed_child', function () {
  if (jQuery(this).is(':checked')) {
    jQuery('#mainwp_message_verify_installed_child').hide();
  }
});

window.managesites_init = function () {
  mainwp_set_message_zone('#mainwp-message-zone');
  jQuery('.sync-ext-row span.status').html('');
  jQuery('.sync-ext-row span.status').css('color', '#0073aa');
};

let mainwp_site_overview_reconnect = function (pElement) {
  feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...', '');
  let data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: pElement.attr('siteid')
  });

  jQuery.post(ajaxurl, data, function () {
    return function (response) {
      response = response.trim();
      if (response.substring(0, 5) == 'ERROR') {
        let error;
        if (response.length == 5) {
          error = 'Undefined error! Please try again. If the process keeps failing, please review <a href="https://mainwp.com/kb/">MainWP Knowledgebase</a>, and if you still have issues, please let us know in the <a href="https://community.mainwp.com/c/community-support/5">MainWP Community</a>.'; // NOSONAR - noopener - open safe.
          feedback('mainwp-message-zone', error, 'red');
        } else {
          error = response.substring(6);
          let err = mainwp_js_get_error_not_detected_connect(error, 'html_msg', 'mainwp-message-zone');
          if (false === err) {
            feedback('mainwp-message-zone', error, 'red');  // it is not json error string.
          }
        }
      } else if ('reconnect_failed' === response) {
        mainwp_set_message_zone('#mainwp-message-zone');

        jQuery('#mainwp-reconnect-site-with-user-passwd-modal').modal({
          onHide: function () {
            window.location.href = location.href;
          },
          closable: false
        }).modal('show');
        jQuery('#mainwp_managesites_add_wpadmin').val(pElement.attr('adminuser'));
        jQuery(document).on('click', '#mainwp-popup-reconnect-site-btn', function () {
          mainwp_reconnect_with_pw(pElement.attr('siteid'));
          return false;
        });
      } else {
        mainwp_set_message_zone('#mainwp-message-zone');
        window.location.href = location.href;
      }
    }
  }());
};

let mainwp_reconnect_with_pw = function (siteid) {
  mainwp_set_message_zone('#mainwp-message-zone-reconnect');
  let errors = [];
  if (jQuery('#mainwp_managesites_add_wpadmin').val().trim() == '') {
    errors.push('Please enter a username of the website administrator.');
  }

  if (jQuery('#mainwp_managesites_add_admin_pwd').val().trim() == '') {
    errors.push('Please enter password of the website administrator.');
  }

  if (errors.length > 0) {
    mainwp_set_message_zone('#mainwp-message-zone-reconnect', errors.join('</br>'), 'red');
    return;
  }

  mainwp_set_message_zone('#mainwp-message-zone-reconnect', '<i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...', 'green');
  let data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    managesites_add_wpadmin: jQuery('#mainwp_managesites_add_wpadmin').val(),
    managesites_add_adminpwd: encodeURIComponent(jQuery('#mainwp_managesites_add_admin_pwd').val()),
    siteid: siteid
  });

  jQuery.post(ajaxurl, data, function (response) {
    response = response.trim();
    mainwp_set_message_zone('#mainwp-message-zone-reconnect');
    if (response.substring(0, 5) == 'ERROR') {
      let error;
      if (response.length == 5) {
        error = 'Undefined error! Please try again. If the process keeps failing, please review this <a href="https://mainwp.com/kb/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://community.mainwp.com/c/community-support/5">MainWP Community</a>.'; // NOSONAR - noopener - open safe.
        mainwp_set_message_zone('#mainwp-message-zone-reconnect', error, 'red');
      } else {
        error = response.substring(6);
        let err = mainwp_js_get_error_not_detected_connect(error, 'html_msg', 'mainwp-message-zone-reconnect');
        if (false === err) {
          mainwp_set_message_zone('#mainwp-message-zone-reconnect', error, 'red');  // it is not json error string.
        }
      }
    } else if ('reconnect_failed' === response) {
      // do not show reconnect popup again.
      mainwp_set_message_zone('#mainwp-message-zone-reconnect', mainwp_get_reconnect_error(response, siteid), 'red');
    } else {
      mainwp_set_message_zone('#mainwp-message-zone-reconnect', response, 'green');
      window.location.href = location.href;
    }
  });
};


let mainwp_managesites_reconnect = function (pElement) {
  let wrapElement = pElement.closest('tr');
  wrapElement.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...' + '</td>');
  let siteid = wrapElement.attr('siteid');
  let data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: siteid
  });

  jQuery.post(ajaxurl, data, function (pWrapElement) {
    return function (response) {
      response = response.trim();
      pWrapElement.hide(); // hide reconnect item
      if (response.substring(0, 5) == 'ERROR') {
        let error;
        if (response.length == 5) {
          error = 'Undefined error! Please try again. If the process keeps failing, please review this <a href="https://mainwp.com/kb/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://community.mainwp.com/c/community-support/5">MainWP Community</a>.'; // NOSONAR - noopener - open safe.
          feedback('mainwp-message-zone', error, 'red');
        } else {
          error = response.substring(6);
          let err = mainwp_js_get_error_not_detected_connect(error, 'html_msg', 'mainwp-message-zone');
          if (false === err) {
            feedback('mainwp-message-zone', error, 'red');  // it is not json error string.
          }
        }
      } else if ('reconnect_failed' === response) {
        feedback('mainwp-message-zone', mainwp_get_reconnect_error(response, siteid), 'error');
      } else {
        feedback('mainwp-message-zone', response, 'green');
      }
      setTimeout(function () {
        window.location.reload()
      }, 6000);
    }

  }(wrapElement));
};

let mainwp_managesites_cards_reconnect = function (element) {
  element.html('<i class="notched loading circle icon"></i> Reconnecting...');
  let siteid = element.attr('site-id');
  let data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: siteid
  });

  jQuery.post(ajaxurl, data, function (element) {
    return function (response) {
      response = response.trim();
      element.hide();
      if (response.substring(0, 5) == 'ERROR') {
        let error;
        if (response.length == 5) {
          error = 'Undefined error! Please try again. If the process keeps failing, please review this <a href="https://mainwp.com/kb/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://community.mainwp.com/c/community-support/5">MainWP Community</a>.'; // NOSONAR - noopener - open safe.
          feedback('mainwp-message-zone', error, 'red');
        } else {
          error = response.substring(6);
          let err = mainwp_js_get_error_not_detected_connect(error, 'html_msg', 'mainwp-message-zone');
          if (false === err) {
            feedback('mainwp-message-zone', error, 'red');  // it is not json error string.
          }
        }
      } else if ('reconnect_failed' === response) {
        feedback('mainwp-message-zone', mainwp_get_reconnect_error(response, siteid), 'error');
      } else {
        feedback('mainwp-message-zone', response, 'green');
      }
      setTimeout(function () {
        window.location.reload()
      }, 6000);
    }

  }(element));
};

// Connect a new website
let mainwp_managesites_add = function () {

  managesites_init();

  let valid_input = mainwp_managesites_add_valid();

  if (!valid_input) {
    return;
  }

  feedback('mainwp-message-zone', __('Adding the site to your MainWP Dashboard. Please wait...'), 'green');

  jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //disable button to add..

  //Check if valid user & rulewp is installed?
  let url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val().trim();

  if (!url.endsWith('/')) {
    url += '/';
  }

  let name = jQuery('#mainwp_managesites_add_wpname').val().trim();
  name = name.replace(/"/g, '&quot;');

  let data = mainwp_secure_data({
    action: 'mainwp_checkwp',
    name: name,
    url: url,
    admin: jQuery('#mainwp_managesites_add_wpadmin').val().trim(),
    verify_certificate: jQuery('#mainwp_managesites_verify_certificate').is(':checked') ? 1 : 0,
    ssl_version: jQuery('#mainwp_managesites_add_ssl_version').val(),
    http_user: jQuery('#mainwp_managesites_add_http_user').val().trim(),
    http_pass: jQuery('#mainwp_managesites_add_http_pass').val().trim()
  });

  jQuery.post(ajaxurl, data, function (res_things) {
    let response = res_things.response;
    response = response.trim();
    let errors = [];
    let url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val().trim();
    if (!url.endsWith('/')) {
      url += '/';
    }

    url = url.replace(/"/g, '&quot;');

    let show_resp = __('Click %1here%2 to see response from the child site.', '<a href="javascript:void(0)" class="mainwp-show-response">', '</a>');

    let resp_data = res_things.resp_data ? res_things.resp_data : '';
    if ('0' == resp_data) {
      resp_data = '';
    }
    jQuery('#mainwp-response-data-container').attr('resp-data', resp_data);

    if (response == 'HTTPERROR') {
      errors.push(__('This site can not be reached! Please use the Test Connection feature and see if the positive response will be returned. For additional help, please review this <a href="https://kb.mainwp.com/docs/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.')); // NOSONAR - noopener - open safe.
    } else if (response == 'NOMAINWP') {
      errors.push(mainwp_js_get_error_not_detected_connect());
    } else if (response.substring(0, 5) == 'ERROR') {
      if (response.length == 5) {
        errors.push(__('Undefined error occurred. Please try again. If the issue does not resolve, please review this <a href="https://kb.mainwp.com/docs/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.')); // NOSONAR - noopener - open safe.
      } else {
        errors.push(__('Error detected: ') + response.substring(6));
      }
    } else if (response == 'OK') {
      jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //Disable add button

      let name = jQuery('#mainwp_managesites_add_wpname').val();
      name = name.replace(/"/g, '&quot;');
      let group_ids = jQuery('#mainwp_managesites_add_addgroups').dropdown('get value');
      let client_id = jQuery('#mainwp_managesites_add_client_id').dropdown('get value');
      let data = mainwp_secure_data({
        action: 'mainwp_addwp',
        managesites_add_wpname: name,
        managesites_add_wpurl: url,
        managesites_add_wpadmin: jQuery('#mainwp_managesites_add_wpadmin').val(),
        managesites_add_adminpwd: encodeURIComponent(jQuery('#mainwp_managesites_add_admin_pwd').val().trim()),
        managesites_add_uniqueId: jQuery('#mainwp_managesites_add_uniqueId').val(),
        ssl_verify: jQuery('#mainwp_managesites_verify_certificate').is(':checked') ? 1 : 0,
        ssl_version: jQuery('#mainwp_managesites_add_ssl_version').val(),
        groupids: group_ids,
        clientid: client_id,
        selected_icon: jQuery('#mainwp_managesites_add_site_select_icon_hidden').val(),
        cust_color: jQuery('#mainwp_managesites_add_site_color').val(),
        uploaded_icon: jQuery('#mainwp_managesites_add_site_uploaded_icon_hidden').val(),
        managesites_add_http_user: jQuery('#mainwp_managesites_add_http_user').val(),
        managesites_add_http_pass: jQuery('#mainwp_managesites_add_http_pass').val(),
      });

      // to support add client reports tokens values
      jQuery("input[name^='creport_token_']").each(function () {
        let tname = jQuery(this).attr('name');
        let tvalue = jQuery(this).val();
        data[tname] = tvalue;
      });

      // support hooks fields
      jQuery(".mainwp_addition_fields_addsite input").each(function () {
        let tname = jQuery(this).attr('name');
        let tvalue = jQuery(this).val();
        data[tname] = tvalue;
      });

      jQuery.post(ajaxurl, data, function (res_things) {
        let site_id = 0;
        if (res_things.error) {
          response = 'Error detected: ' + res_things.error;
        } else {
          response = res_things.response;
          site_id = res_things.siteid;
        }
        response = response.trim();
        managesites_init();

        resp_data = res_things.resp_data ? res_things.resp_data : '';
        if ('0' == resp_data) {
          resp_data = '';
        }
        jQuery('#mainwp-response-data-container').attr('resp-data', resp_data);

        if (response.substring(0, 5) == 'ERROR') {
          mainwp_set_message_zone('#mainwp-message-zone', '', '', true);
          feedback('mainwp-message-zone', response.substring(6) + (resp_data != '' ? '<br>' + show_resp : ''), 'red');
        } else {
          mainwp_set_message_zone('#mainwp-message-zone', '', '', true);
          feedback('mainwp-message-zone', response, 'green');

          if (site_id > 0) {
            jQuery('.sync-ext-row').attr('status', 'queue');
            setTimeout(function () {
              mainwp_managesites_sync_extension_start_next(site_id);
            }, 1000);
          }

          //Reset fields
          jQuery('#mainwp_managesites_add_wpname').val('');
          jQuery('#mainwp_managesites_add_wpurl').val('');
          jQuery('#mainwp_managesites_add_wpurl_protocol').val('https');
          jQuery('#mainwp_managesites_add_wpadmin').val('');
          jQuery('#mainwp_managesites_add_admin_pwd').val('');
          jQuery('#mainwp_managesites_add_uniqueId').val('');
          jQuery('#mainwp_managesites_add_addgroups').dropdown('clear');
          jQuery('#mainwp_managesites_verify_certificate').val(1);

          jQuery("input[name^='creport_token_']").each(function () {
            jQuery(this).val('');
          });

          // support hooks fields
          jQuery(".mainwp_addition_fields_addsite input").each(function () {
            jQuery(this).val('');
          });
        }

        jQuery('#mainwp_managesites_add').prop("disabled", false); //Enable add button
      }, 'json');
    }
    if (errors.length > 0) {
      mainwp_set_message_zone('#mainwp-message-zone', '', '', true);
      managesites_init();
      jQuery('#mainwp_managesites_add').prop("disabled", false); //Enable add button
      if (resp_data != '') {
        errors.push(show_resp);
      }
      feedback('mainwp-message-zone', errors.join('<br />'), 'red');
    }
  }, 'json');
};

let mainwp_managesites_add_valid = function () {

  if (!jQuery('#mainwp_managesites_verify_installed_child').is(':checked')) {
    jQuery('#mainwp_message_verify_installed_child').show();
    scrollElementTop('mainwp_message_verify_installed_child');
    return false;
  } else {
    jQuery('#mainwp_message_verify_installed_child').hide();
  }

  let errors = [];

  if (jQuery('#mainwp_managesites_add_wpname').val().trim() == '') {
    errors.push(__('Please enter a name for the website.'));
  }
  if (jQuery('#mainwp_managesites_add_wpurl').val().trim() == '') {
    errors.push(__('Please enter a valid URL for your site.'));
  } else {
    let url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    if (!url.endsWith('/')) {
      url += '/';
    }

    jQuery('#mainwp_managesites_add_wpurl').val(url);

    if (!isUrl(jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val())) {
      errors.push(__('Please enter a valid URL for your site.'));
    }
  }
  if (jQuery('#mainwp_managesites_add_wpadmin').val().trim() == '') {
    errors.push(__('Please enter a username of the website administrator.'));
  }

  if (errors.length > 0) {
    feedback('mainwp-message-zone', errors.join('<br />'), 'yellow');
    return false;
  }
  return true;
}


let mainwp_managesites_sync_extension_start_next = function (siteId) {
  let pluginToInstall = jQuery('.sync-ext-row[status="queue"]:first')
  while (pluginToInstall && (pluginToInstall.length > 0) && (bulkInstallCurrentThreads < 1)) {  // NOSONAR - modified outside the function, bulkInstallMaxThreads - to fix install plugins and apply settings failed issue.
    pluginToInstall.attr('status', 'progress');
    mainwp_managesites_sync_extension_start_specific(pluginToInstall, siteId);
    pluginToInstall = jQuery('.sync-ext-row[status="queue"]:first');
  }

  if ((pluginToInstall.length == 0) && (bulkInstallCurrentThreads == 0)) { // NOSONAR - modified outside the function.
    jQuery('#mwp_applying_ext_settings').remove();
  }
};

let mainwp_managesites_sync_extension_start_specific = function (pPluginToInstall, pSiteId) {
  let syncGlobalSettings = pPluginToInstall.find(".sync-global-options input[type='checkbox']:checked").length > 0;
  let install_plugin = pPluginToInstall.find(".sync-install-plugin input[type='checkbox']:checked").length > 0;
  let apply_settings = pPluginToInstall.find(".sync-options input[type='checkbox']:checked").length > 0;

  if (syncGlobalSettings) {
    mainwp_extension_apply_plugin_settings(pPluginToInstall, pSiteId, true);
  } else if (install_plugin) {
    mainwp_extension_prepareinstallplugin(pPluginToInstall, pSiteId);
  } else if (apply_settings) {
    mainwp_extension_apply_plugin_settings(pPluginToInstall, pSiteId, false);
  } else {
    mainwp_managesites_sync_extension_start_next(pSiteId);
    return;
  }
};

let mainwp_extension_prepareinstallplugin = function (pPluginToInstall, pSiteId) {
  let site_Ids = [];
  site_Ids.push(pSiteId);
  bulkInstallCurrentThreads++;
  let plugin_slug = pPluginToInstall.find(".sync-install-plugin").attr('slug');
  let workingEl = pPluginToInstall.find(".sync-install-plugin i");
  let statusEl = pPluginToInstall.find(".sync-install-plugin span.status");

  let data = {
    action: 'mainwp_ext_prepareinstallplugintheme',
    type: 'plugin',
    slug: plugin_slug,
    'selected_sites[]': site_Ids,
    selected_by: 'site',
  };

  workingEl.show();
  statusEl.html(__('Preparing for installation...'));

  jQuery.post(ajaxurl, data, function (response) {
    workingEl.hide();
    if (response?.sites[pSiteId] != undefined) {
      statusEl.html(__('Installing...'));
      let data = mainwp_secure_data({
        action: 'mainwp_ext_performinstallplugintheme',
        type: 'plugin',
        url: response.url,
        siteId: pSiteId,
        activatePlugin: true,
        overwrite: false,
      });
      workingEl.show();
      jQuery.post(ajaxurl, data, function (response) {
        workingEl.hide();
        let apply_settings = false;
        let syc_msg = '';
        let _success = false;
        if ((response?.ok[pSiteId] != undefined)) {
          syc_msg = __('Installation successful!');
          statusEl.html(syc_msg);
          apply_settings = pPluginToInstall.find(".sync-options input[type='checkbox']:checked").length > 0;
          if (apply_settings) {
            mainwp_extension_apply_plugin_settings(pPluginToInstall, pSiteId, false);
          }
          _success = true;
        } else if (response?.errors[pSiteId] != undefined) {
          syc_msg = __('Installation failed!') + ': ' + response.errors[pSiteId][1];
          statusEl.html(syc_msg);
          statusEl.css('color', 'red');
        } else {
          syc_msg = __('Installation failed!');
          statusEl.html(syc_msg);
          statusEl.css('color', 'red');
        }

        if (syc_msg != '') {
          if (_success)
            syc_msg = '<span style="color:#0073aa">' + syc_msg + '!</span>';
          else
            syc_msg = '<span style="color:red">' + syc_msg + '!</span>';
          jQuery('#mainwp-message-zone').append('<br/>' + pPluginToInstall.find(".sync-install-plugin").attr('plugin_name') + ' ' + syc_msg);
        }

        if (!apply_settings) {
          bulkInstallCurrentThreads--;
          mainwp_managesites_sync_extension_start_next(pSiteId);
        }
      }, 'json');
    } else {
      statusEl.css('color', 'red');
      statusEl.html(__('Error while preparing the installation. Please, try again.'));
      bulkInstallCurrentThreads--;
    }
  }, 'json');
}

let mainwp_extension_apply_plugin_settings = function (pPluginToInstall, pSiteId, pGlobal) {
  let extSlug = pPluginToInstall.attr('slug');
  let workingEl = pPluginToInstall.find(".options-row i");
  let statusEl = pPluginToInstall.find(".options-row span.status");
  if (pGlobal)
    bulkInstallCurrentThreads++;

  let data = mainwp_secure_data({
    action: 'mainwp_ext_applypluginsettings',
    ext_dir_slug: extSlug,
    siteId: pSiteId
  });

  workingEl.show();
  statusEl.html(__('Applying settings...'));
  jQuery.post(ajaxurl, data, function (response) { // NOSONAR - complex.
    workingEl.hide();
    let syc_msg = '';
    let _success = false;
    if (response) {
      if (response.result && response.result == 'success') {
        let msg = '';
        if (response.message != undefined) {
          msg = ' ' + response.message;
        }
        statusEl.html(__('Applying settings successful!') + msg);
        syc_msg = __('Successful');
        _success = true
      } else if (response.error != undefined) {
        statusEl.html(__('Applying settings failed!') + ': ' + response.error);
        statusEl.css('color', 'red');
        syc_msg = __('failed');
      } else {
        statusEl.html(__('Applying settings failed!'));
        statusEl.css('color', 'red');
        syc_msg = __('failed');
      }
    } else {
      statusEl.html(__('Undefined error!'));
      statusEl.css('color', 'red');
      syc_msg = __('failed');
    }

    if (syc_msg != '') {
      if (_success)
        syc_msg = '<span style="color:#0073aa">' + syc_msg + '!</span>';
      else
        syc_msg = '<span style="color:red">' + syc_msg + '!</span>';
      if (pGlobal) {
        syc_msg = __('Apply global %1 options', pPluginToInstall.attr('ext_name')) + ' ' + syc_msg;
      } else {
        syc_msg = __('Apply %1 settings', pPluginToInstall.find('.sync-install-plugin').attr('plugin_name')) + ' ' + syc_msg;
      }
      jQuery('#mainwp-message-zone').append('<br/>' + syc_msg);
    }
    bulkInstallCurrentThreads--;
    mainwp_managesites_sync_extension_start_next(pSiteId);
  }, 'json');
}

// Test Connection (Add Site Page)
let mainwp_managesites_test = function () {

  let errors = [];

  if (jQuery('#mainwp_managesites_add_wpurl').val().trim() == '') {
    errors.push(__('Please enter a valid URL for your site.'));
  } else {
    let clean_url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    let protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();
    let url = protocol + '://' + clean_url;
    if (!url.endsWith('/')) {
      url += '/';
    }

    if (!isUrl(url)) {
      errors.push(__('Please enter a valid URL for your site'));
    }
  }

  if (errors.length > 0) {
    feedback('mainwp-message-zone', errors.join('<br />'), 'red');
  } else {
    jQuery('#mainwp-test-connection-modal').modal('setting', 'closable', false).modal('show');
    jQuery('#mainwp-test-connection-modal .dimmer').show();
    jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').hide();

    let clean_url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    let protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();
    let url = protocol + '://' + clean_url;

    if (!url.endsWith('/')) {
      url += '/';
    }

    let data = mainwp_secure_data({
      action: 'mainwp_testwp',
      url: url,
      test_verify_cert: jQuery('#mainwp_managesites_verify_certificate').is(':checked') ? 1 : 0,
      ssl_version: jQuery('#mainwp_managesites_add_ssl_version').val(),
      http_user: jQuery('#mainwp_managesites_add_http_user').val(),
      http_pass: jQuery('#mainwp_managesites_add_http_pass').val()
    });

    jQuery.post(ajaxurl, data, function (response) { // NOSONAR - complex.
      jQuery('#mainwp-test-connection-modal .dimmer').hide();
      jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').removeClass('red green check times');
      jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html('');
      jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html('');
      if (response.error) {
        if (response.httpCode) {
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection failed!'));
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('URL:') + ' ' + response.host + ' - ' + __('HTTP-code:') + ' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' - ' + __('Error message: ') + ' ' + response.error);
        } else {
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection test failed.'));
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('Error message:') + ' ' + response.error);
        }
      } else if (response.httpCode) {
        if (response.httpCode == '200') {
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('green check');
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection successful!'));
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('URL:') + ' ' + response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') + ' - ' + __('Received HTTP-code') + ' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : ''));
        } else {
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection test failed.'));
          jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('URL:') + ' ' + response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') + ' - ' + __('Received HTTP-code:') + ' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : ''));
        }
      } else {
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show('');
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection test failed.'));
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('Invalid response from the server, please try again.'));
      }
    }, 'json');
  }
};

// Test Connection (Edit Site Page)
let mainwp_managesites_edit_test = function () {

  let clean_url = jQuery('#mainwp_managesites_edit_siteurl').val();
  let protocol = jQuery('#mainwp_managesites_edit_siteurl_protocol').val();

  let url = protocol + '://' + clean_url;

  if (!url.endsWith('/')) {
    url += '/';
  }

  jQuery('#mainwp-test-connection-modal').modal('setting', 'closable', false).modal('show');
  jQuery('#mainwp-test-connection-modal .dimmer').show();
  jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').hide();

  let data = mainwp_secure_data({
    action: 'mainwp_testwp',
    url: url,
    test_verify_cert: jQuery('#mainwp_managesites_edit_verifycertificate').val(),
    ssl_version: jQuery('#mainwp_managesites_edit_ssl_version').val(),
    http_user: jQuery('#mainwp_managesites_edit_http_user').val(),
    http_pass: jQuery('#mainwp_managesites_edit_http_pass').val()
  });

  jQuery.post(ajaxurl, data, function (response) { // NOSONAR - complex.
    jQuery('#mainwp-test-connection-modal .dimmer').hide();
    jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').removeClass('red green check times');
    jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html('');
    jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html('');
    if (response.error) {
      if (response.httpCode) {
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection failed!'));
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('URL:') + ' ' + response.host + ' - ' + __('HTTP-code:') + ' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : '') + ' - ' + __('Error message: ') + ' ' + response.error);
      } else {
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection test failed.'));
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('Error message:') + ' ' + response.error);
      }
    } else if (response.httpCode) {
      if (response.httpCode == '200') {
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('green check');
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection successful!'));
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('URL:') + ' ' + response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') + ' - ' + __('Received HTTP-code') + ' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : ''));
      } else {
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show();
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection test failed.'));
        jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('URL:') + ' ' + response.host + (response.ip != undefined ? ' (IP: ' + response.ip + ')' : '') + ' - ' + __('Received HTTP-code:') + ' ' + response.httpCode + (response.httpCodeString ? ' (' + response.httpCodeString + ')' : ''));
      }
    } else {
      jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').show('');
      jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result i').addClass('red times');
      jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content span').html(__('Connection test failed.'));
      jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result .content .sub.header').html(__('Invalid response from the server, please try again.'));
    }
  }, 'json');
};

let managesites_remove = function (obj) {
  managesites_init();

  let name = jQuery(obj).attr('site-name');
  let id = jQuery(obj).attr('site-id');

  let msg = sprintf(__('Are you sure you want to remove %1 from your MainWP Dashboard?', name));

  mainwp_confirm(msg, function () {
    jQuery('tr#child-site-' + id).html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing and deactivating the MainWP Child plugin! Please wait...' + '</td>');
    let data = mainwp_secure_data({
      action: 'mainwp_removesite',
      id: id
    });

    jQuery.post(ajaxurl, data, function (response) {

      managesites_init();

      let result = '';
      let error = '';

      if (response.error != undefined) {
        error = response.error;
      } else if (response.result == 'SUCCESS') {
        result = '<i class="close icon"></i>' + __('The site has been removed and the MainWP Child plugin has been disabled.');
      } else if (response.result == 'NOSITE') {
        error = '<i class="close icon"></i>' + __('The requested site has not been found.');
      } else {
        result = '<i class="close icon"></i>' + __('The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly.');
      }

      if (error != '') {
        feedback('mainwp-message-zone', error, 'red');
      }

      if (result != '') {
        feedback('mainwp-message-zone', result, 'green');
      }

      jQuery('tr#child-site-' + id).remove();

    }, 'json');
  }, false, false, false, 'REMOVE');
  return false;
};

jQuery(function () {

  jQuery(document).on('click', '#mainwp_managesites_add', function () {
    mainwp_managesites_add();
  });

  // Hanlde click submit form import website
  jQuery(document).on('click', '#mainwp_managesites_bulkadd', function () {

    let error_messages = mainwp_managesites_import_handle_form_before_submit();
    // If there is an error, prevent submission and display the error
    if (error_messages.length > 0) {
      feedback('mainwp-message-zone', error_messages.join("<br/>"), "red");
    } else {
      jQuery('#mainwp_managesites_bulkadd_form').submit();
    }
    return false;
  });

  // Trigger Connection Test (Add Site Page)
  jQuery(document).on('click', '#mainwp_managesites_test', function () {
    mainwp_managesites_test();
  });

  // Trigger Connection Test (Edit Site Page)
  jQuery(document).on('click', '#mainwp_managesites_edit_test', function () {
    mainwp_managesites_edit_test();
  });

  // Handle submit add multi website
  jQuery(document).on('click', '#mainwp_managesites_add_multi_site', function () {
    let error_messages = [];
    let has_table_data = false;
    has_table_data = mainwp_managesites_validate_import_rows(error_messages, true);
    // If there is an error, prevent submission and display the error
    if (error_messages.length > 0 && !has_table_data) {
      feedback('mainwp-add-multi-new-site-message-zone', error_messages.join("<br/>"), "red");
    } else {
      jQuery('#mainwp_managesites_add_form').submit();
    }
    return false;
  });

  // Handle click remove website on management webiste.
  jQuery(document).on('click', '#mainwp-managesites-remove-site', function () {
    jQuery('#mainwp-remove-site-button').trigger('click');
  });
});

/**
 * Add new user
 */
jQuery(function () {
  jQuery(document).on('click', '#bulk_add_createuser', function () {
    mainwp_createuser();
  });
  jQuery('#bulk_import_createuser').on('click', function () {
    mainwp_bulkupload_users();
  });
});

let mainwp_createuser = function () {
  let cont = true;
  if (jQuery('#user_login').val() == '') {
    feedback('mainwp-message-zone', __('Username field is required! Please enter a username.'), 'yellow');
    cont = false;
  }

  if (jQuery('#email').val() == '') {
    feedback('mainwp-message-zone', __('E-mail field is required! Please enter an email address.'), 'yellow');
    cont = false;
  }

  if (jQuery('#password').val() == '') {
    feedback('mainwp-message-zone', __('Password field is required! Please enter the wanted password or generate a random one.'), 'yellow');
    cont = false;
  }

  let selected_sites = [];
  let selected_clients = [];
  let selected_groups = [];

  if (jQuery('#select_by').val() == 'site') {
    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });
    if (selected_sites.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or group or client.'), 'yellow');
      cont = false;
    }
  } else if (jQuery('#select_by').val() == 'client') {
    jQuery("input[name='selected_clients[]']:checked").each(function () {
      selected_clients.push(jQuery(this).val());
    });
    if (selected_clients.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or group or client.'), 'yellow');
      cont = false;
    }
  } else {
    jQuery("input[name='selected_groups[]']:checked").each(function () {
      selected_groups.push(jQuery(this).val());
    });
    if (selected_groups.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or group or client.'), 'yellow');
      cont = false;
    }
  }

  if (cont) {
    mainwp_set_message_zone('#mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Creating the user. Please wait...'), '', true);
    jQuery('#bulk_add_createuser').attr('disabled', 'disabled');
    //Add user via ajax!!
    let data = mainwp_secure_data({
      action: 'mainwp_bulkadduser',
      'select_by': jQuery('#select_by').val(),
      'selected_groups[]': selected_groups,
      'selected_sites[]': selected_sites,
      'selected_clients[]': selected_clients,
      'user_login': jQuery('#user_login').val(),
      'email': jQuery('#email').val(),
      'url': jQuery('#url').val(),
      'first_name': jQuery('#first_name').val(),
      'last_name': jQuery('#last_name').val(),
      'pass1': jQuery('#password').val(),
      'pass2': jQuery('#password').val(),
      'send_password': jQuery('#send_password').attr('checked'),
      'role': jQuery('#role').val()
    });

    jQuery.post(ajaxurl, data, function (response) {
      response = response.trim();
      mainwp_set_message_zone('#mainwp-message-zone');
      jQuery('#bulk_add_createuser').prop("disabled", false);
      if (response.substring(0, 5) == 'ERROR') {
        let responseObj = JSON.parse(response.substring(6));
        if (responseObj.error == undefined) {
          let errorMessageList = responseObj[1];
          let errorMessage = '';
          for (let iem of errorMessageList) {
            if (errorMessage != '') {
              errorMessage = errorMessage + "<br />";
            }
            errorMessage = errorMessage + iem;
          }
          if (errorMessage != '') {
            feedback('mainwp-message-zone', errorMessage, 'red');
          }
        }
      } else {
        jQuery('#mainwp-add-new-user-form').append(response);
        jQuery('#mainwp-creating-new-user-modal').modal('setting', 'closable', false).modal('show');

      }
    });
  }
};

/**
 * InstallPlugins/Themes
 */
jQuery(function () {
  jQuery('#MainWPInstallBulkNavSearch').on('click', function (event) {
    event.preventDefault();
    jQuery('#mainwp_plugin_bulk_install_btn').attr('bulk-action', 'install');
    jQuery('.mainwp-bulk-install-showhide-content').hide();
    jQuery('.mainwp-browse-plugins').show();
    jQuery('#mainwp-search-plugins-form').show();
  });
  jQuery('#MainWPInstallBulkNavUpload').on('click', function (event) {
    event.preventDefault();
    jQuery('#mainwp_plugin_bulk_install_btn').attr('bulk-action', 'upload');
    jQuery('.mainwp-bulk-install-showhide-content').hide();
    jQuery('.mainwp-upload-plugin').show();
  });

  // not used?
  jQuery(document).on('click', '.filter-links li.plugin-install a', function (event) {
    event.preventDefault();
    jQuery('.filter-links li.plugin-install a').removeClass('current');
    jQuery(this).addClass('current');
    let tab = jQuery(this).parent().attr('tab');
    if (tab == 'search') {
      mainwp_install_search(event);
    } else {
      jQuery('#mainwp_installbulk_s').val('');
      jQuery('#mainwp_installbulk_tab').val(tab);
      mainwp_install_plugin_tab_search('tab:' + tab);
    }
  });

  jQuery(document).on('click', '#mainwp_plugin_bulk_install_btn', function () {
    let act = jQuery(this).attr('bulk-action');
    if (act == 'install') {
      let selected = jQuery("input[type='radio'][name='install-plugin']:checked");
      if (selected.length == 0) {
        feedback('mainwp-message-zone', __('Please select plugin to install files.'), 'yellow');
      } else {
        let selectedId = /^install-([^-]*)-(.*)$/.exec(selected.attr('id'));
        if (selectedId) {
          mainwp_install_bulk('plugin', selectedId[2], selected.attr('plugin-name'));
        }
      }
    } else if (act == 'upload') {
      mainwp_upload_bulk('plugins');
    }

    return false;
  });

  jQuery(document).on('click', '#mainwp_theme_bulk_install_btn', function () {
    let act = jQuery(this).attr('bulk-action');
    if (act == 'install') {
      let selected = jQuery("input[type='radio'][name='install-theme']:checked");
      if (selected.length == 0) {
        feedback('mainwp-message-zone', __('Please select theme to install files.'), 'yellow');
      } else {
        let selectedId = /^install-([^-]*)-(.*)$/.exec(selected.attr('id'));
        if (selectedId)
          mainwp_install_bulk('theme', selectedId[2], selected.attr('theme-name'));
      }
    } else if (act == 'upload') {
      mainwp_upload_bulk('themes');
    }
    return false;
  });
});

// Generate the Go to WP Admin link
window.mainwp_links_visit_site_and_admin = function (url, siteId) {
  let links = '';
  if (url != '') {
    links += '<a href="' + url + '" target="_blank" class="mainwp-may-hide-referrer"><i class="external alternate icon"></i></a> ';
  }
  links += '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' + siteId + '&_opennonce=' + mainwpParams._wpnonce + '" target="_blank"><i class="sign in alternate icon"></i></a>';
  return links;
}

mainwpVars.bulkInstallTotal = 0;
bulkInstallDone = 0;

/**
 * Install Plugin/Theme from WP.org.
 *
 * Initiate the process.
 *
 * @param string type Plugin or theme.
 * @param string slug Plugin or theme slug.
 *
 * @return void
 */
let mainwp_install_bulk = function (type, slug, name) {
  let data = mainwp_secure_data({
    action: 'mainwp_preparebulkinstallplugintheme',
    type: type,
    slug: slug,
    name: name,
    selected_by: jQuery('#select_by').val()
  });
  let placeholder = '<div class="ui placeholder"><div class="paragraph"><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div></div></div>';

  if (jQuery('#select_by').val() == 'site') {

    let selected_sites = [];

    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });

    if (selected_sites.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }

    data['selected_sites[]'] = selected_sites;

  } else if (jQuery('#select_by').val() == 'client') {

    let selected_clients = [];

    jQuery("input[name='selected_clients[]']:checked").each(function () {
      selected_clients.push(jQuery(this).val());
    });

    if (selected_clients.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }

    data['selected_clients[]'] = selected_clients;

  } else {
    let selected_groups = [];

    jQuery("input[name='selected_groups[]']:checked").each(function () {
      selected_groups.push(jQuery(this).val());
    });

    if (selected_groups.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }

    data['selected_groups[]'] = selected_groups;

  }

  jQuery('#plugintheme-installation-queue').html(placeholder);
  jQuery.post(ajaxurl, data, function (type, activatePlugin, overwrite) {
    return function (response) {
      let installQueueContent = '';
      bulkInstallDone = 0;
      installQueueContent += '<div id="bulk_install_info"></div>';
      installQueueContent += '<div class="ui middle aligned divided list">';

      for (let siteId in response.sites) {
        let site = response.sites[siteId];
        installQueueContent +=
          '<div class="siteBulkInstall item" siteid="' + siteId + '" status="queue">' +
          '<div class="right floated content">' +
          '<span class="queue" data-inverted="" data-position="left center" data-tooltip="' + __('Queued') + '"><i class="clock outline icon"></i></span>' +
          '<span class="progress" data-inverted="" data-position="left center" data-tooltip="' + __('Installing...') + '" style="display:none"><i class="notched circle loading icon"></i></span>' +
          '<span class="status"></span>' +
          '</div>' +
          '<div class="content">' + mainwp_links_visit_site_and_admin('', siteId) + ' ' + '<a href="' + site['url'] + '">' + site['name'].replace(/\\(.)/mg, "$1") + '</a></div>' +
          '</div>';
        mainwpVars.bulkInstallTotal++;
      }

      installQueueContent += '</div>';

      jQuery('#plugintheme-installation-queue').html(installQueueContent);
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress({ value: 0, total: mainwpVars.bulkInstallTotal });
      mainwp_install_bulk_start_next(type, response.url, activatePlugin, overwrite, slug, response);
    }
  }(type, jQuery('#chk_activate_plugin').is(':checked'), jQuery('#chk_overwrite').is(':checked')), 'json');

  jQuery('#plugintheme-installation-progress-modal').modal('setting', 'closable', false).modal('show');

};


/**
 * Install Plugin/Theme from WP.org.
 *
 * Loop through sites.
 *
 * @param string type           Plugin or theme.
 * @param string url            URL.
 * @param bool   activatePlugin Determines if the item should be activated or not upon installation.
 * @param bool   overwrite      Determines if the item should overwrite exisitng version.
 *
 * @return void
 */
let mainwp_install_bulk_start_next = function (type, url, activatePlugin, overwrite, slug, installResults) { // NOSONAR - complex.
  while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) { // NOSONAR - modified outside the function.
    mainwp_install_bulk_start_specific(type, url, activatePlugin, overwrite, siteToInstall, slug, installResults);
  }
  if (bulkInstallDone == mainwpVars.bulkInstallTotal && mainwpVars.bulkInstallTotal != 0) {
    jQuery('#bulk_install_info').before('<div class="ui info message">' + mainwp_install_bulk_you_know_msg(type, 1) + '</div>');
    if (jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').length > 0) { // to support add to cost tracker pro.
      if (installResults.add_to_cost_tracker_id != undefined) {
        if (installResults.add_to_cost_tracker_id > 0) {
          jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').text(_('Edit Cost Tracker'));
          jQuery('#mainwp-cost-tracker-assistant-add-to-tracker-modal .header').text(_('Edit Cost Tracker'));
        }
        jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').attr('disabled', false);
        jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').removeClass('disabled');
        jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').attr('item-slug', slug);
        jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').attr('item-type', type);
        jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').attr('item-name', installResults.name);
        jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').attr('cost-id', installResults.add_to_cost_tracker_id);
        if (installResults.installed_sites != undefined) {
          jQuery('.mainwp-cost-tracker-assistant-add-to-cost-tracker-button').attr('installed-sites', installResults.installed_sites.join(','));
        }
      }
    }
  }
};

/**
 * Install Plugin/Theme from WP.org.
 *
 * Install specific item.
 *
 * @param string type           Plugin or theme.
 * @param string url            URL.
 * @param bool   activatePlugin Determines if the item should be activated or not upon installation.
 * @param bool   overwrite      Determines if the item should overwrite exisitng version.
 * @param object siteToInstall  Site to install the item to.
 *
 * @return void
 */
let mainwp_install_bulk_start_specific = function (type, url, activatePlugin, overwrite, siteToInstall, slug, installResults) {
  bulkInstallCurrentThreads++;

  siteToInstall.attr('status', 'progress');
  siteToInstall.find('.queue').hide();
  siteToInstall.find('.progress').show();
  let data = mainwp_secure_data({
    action: 'mainwp_installbulkinstallplugintheme',
    type: type,
    url: url,
    activatePlugin: activatePlugin,
    overwrite: overwrite,
    siteId: siteToInstall.attr('siteid')
  });

  jQuery.post(ajaxurl, data, function (type, url, activatePlugin, overwrite, siteToInstall) {
    return function (response) {
      siteToInstall.attr('status', 'done');
      siteToInstall.find('.progress').hide();
      let statusEl = siteToInstall.find('.status');
      statusEl.show();
      let _error = '';
      if (response.error != undefined) {
        statusEl.html(response.error);
        statusEl.css('color', 'red');
      } else if (response?.ok[siteToInstall.attr('siteid')]) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Installation completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>');
        if (installResults.installed_sites == undefined) {
          installResults.installed_sites = [];
        }
        installResults.installed_sites.push(siteToInstall.attr('siteid'));
      } else if (response?.errors[siteToInstall.attr('siteid')]) {
        _error = response.errors[siteToInstall.attr('siteid')][1];
      } else {
        _error = __('Undefined error occurred. Please try again.', 'mainwp');
      }

      if (_error !== '') {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + _error + '"><i class="times red icon"></i></span>');
      }

      bulkInstallCurrentThreads--;
      bulkInstallDone++;

      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress('set progress', bulkInstallDone);
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + mainwpVars.bulkInstallTotal + ' ' + __('Installed'));
      mainwp_install_bulk_start_next(type, url, activatePlugin, overwrite, slug, installResults);
    }
  }(type, url, activatePlugin, overwrite, siteToInstall), 'json');
};


let mainwp_install_bulk_you_know_msg = function (type, total) { // NOSONAR - complex.
  let msg = '';
  if (mainwpParams.installedBulkSettingsManager && mainwpParams.installedBulkSettingsManager == 1) {
    if (type == 'plugin') {
      if (total == 1)
        msg = __('Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', '<a href="https://mainwp.com/kb/bulk-settings-manager-extension/" target="_blank">', '</a>'); // NOSONAR - noopener - open safe.
      else
        msg = __('Would you like to use the Bulk Settings Manager with these plugins? Check out the %1Documentation%2.', '<a href="https://mainwp.com/kb/bulk-settings-manager-extension/" target="_blank">', '</a>'); // NOSONAR - noopener - open safe.
    } else if (type == 'theme') {
      if (total == 1)
        msg = __('Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', '<a href="https://mainwp.com/kb/bulk-settings-manager-extension/" target="_blank">', '</a>'); // NOSONAR - noopener - open safe.
      else
        msg = __('Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', '<a href="https://mainwp.com/kb/bulk-settings-manager-extension/" target="_blank">', '</a>'); // NOSONAR - noopener - open safe.
    }
  } else if (type == 'plugin') {
    if (total == 1)
      msg = __('Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>'); // NOSONAR - noopener - open safe.
    else
      msg = __('Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>'); // NOSONAR - noopener - open safe.
  } else if (type == 'theme') {
    if (total == 1)
      msg = __('Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>'); // NOSONAR - noopener - open safe.
    else
      msg = __('Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>'); // NOSONAR - noopener - open safe.
  }
  return msg;
}

/**
 * Install Plugin/Theme by Upload.
 *
 * Initiate the process.
 *
 * @param string type Plugin or theme.
 *
 * @return void
 */
let mainwp_upload_bulk = function (type) {

  if (type == 'plugins') {
    type = 'plugin';
  } else {
    type = 'theme';
  }

  let files = [];

  jQuery(".qq-upload-file").each(function () {
    if (jQuery(this).closest('.file-uploaded-item').hasClass('qq-upload-success')) {
      files.push(jQuery(this).attr('filename'));
    }
  });

  if (files.length == 0) {
    if (type == 'plugin') {
      feedback('mainwp-message-zone', __('Please upload at least one plugin to install.', 'mainwp'), 'yellow');
    } else {
      feedback('mainwp-message-zone', __('Please upload at least one theme to install.', 'mainwp'), 'yellow');
    }
    return;
  }

  let data = mainwp_secure_data({
    action: 'mainwp_preparebulkuploadplugintheme',
    type: type,
    selected_by: jQuery('#select_by').val()
  });

  let placeholder = '<div class="ui placeholder"><div class="paragraph"><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div></div></div>';

  if (jQuery('#select_by').val() == 'site') {
    let selected_sites = [];
    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });

    if (selected_sites.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }
    data['selected_sites[]'] = selected_sites;
  } else if (jQuery('#select_by').val() == 'client') {
    let selected_clients = [];
    jQuery("input[name='selected_clients[]']:checked").each(function () {
      selected_clients.push(jQuery(this).val());
    });

    if (selected_clients.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }
    data['selected_clients[]'] = selected_clients;
  } else {
    let selected_groups = [];
    jQuery("input[name='selected_groups[]']:checked").each(function () {
      selected_groups.push(jQuery(this).val());
    });
    if (selected_groups.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }
    data['selected_groups[]'] = selected_groups;
  }

  data['files[]'] = files;

  jQuery('#plugintheme-installation-queue').html(placeholder);

  jQuery.post(ajaxurl, data, function (type, files, activatePlugin, overwrite) {
    return function (response) {
      let installQueue = '';
      mainwpVars.bulkInstallTotal = 0;
      bulkInstallDone = 0;

      installQueue += '<div class="ui middle aligned selection divided list">';

      for (let siteId in response.sites) {
        let site = response.sites[siteId];

        installQueue +=
          '<div class="siteBulkInstall item" siteid="' + siteId + '" status="queue">' +
          '<div class="right floated content">' +
          '<span class="queue" data-inverted="" data-position="left center" data-tooltip="' + __('Queued', 'mainwp') + '"><i class="clock outline icon"></i></span>' +
          '<span class="progress" data-inverted="" data-position="left center" data-tooltip="' + __('Installing...', 'mainwp') + '" style="display:none"><i class="notched circle loading icon"></i></span>' +
          '<span class="status"></span>' +
          '</div>' +
          '<div class="content">' + mainwp_links_visit_site_and_admin('', siteId) + ' ' + '<a href="' + site['url'] + '">' + site['name'].replace(/\\(.)/mg, "$1") + '</a></div>' +
          '<div class="installation-entries"></div>' +
          '</div>';
        mainwpVars.bulkInstallTotal++;
      }

      installQueue += '</div>';

      jQuery('#plugintheme-installation-queue').html(installQueue);

      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress({ value: 0, total: mainwpVars.bulkInstallTotal });
      mainwp_upload_bulk_start_next(type, response.urls, activatePlugin, overwrite);
    }
  }(type, files, jQuery('#chk_activate_plugin').is(':checked'), jQuery('#chk_overwrite').is(':checked')), 'json');

  jQuery('#plugintheme-installation-progress-modal').modal('setting', 'closable', false).modal('show');

  jQuery('.qq-upload-list').html(''); // empty files list!

  return false;
};

/**
 * Install Plugin/Theme by Upload.
 *
 * Loop through sites.
 *
 * @param string type           Plugin or theme.
 * @param string urls           URLs.
 * @param bool   activatePlugin Determines if the item should be activated or not upon installation.
 * @param bool   overwrite      Determines if the item should overwrite exisitng version.
 *
 * @return void
 */
let mainwp_upload_bulk_start_next = function (type, urls, activatePlugin, overwrite) {
  while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) { // NOSONAR - modified outside the function.
    mainwp_upload_bulk_start_specific(type, urls, activatePlugin, overwrite, siteToInstall);
  }

  if ((siteToInstall.length == 0) && (bulkInstallCurrentThreads == 0)) { // NOSONAR - modified outside the function.
    let data = mainwp_secure_data({
      action: 'mainwp_cleanbulkuploadplugintheme',
    });

    jQuery.post(ajaxurl, data, function () {
      jQuery('.file-uploaded-item.qq-upload-completed').remove();
    });

    let msg = mainwp_install_bulk_you_know_msg(type, jQuery('#bulk_upload_info').attr('number-files'));

    jQuery('#bulk_upload_info').html('<div class="bui blue message">' + msg + '</div>');

    if (jQuery('.mainwp_cost_tracker_assistant_installed_items').length > 0) { // to support add to cost tracker pro.
      let cost_tracker_items = [];
      let cost_tracker_check_slugs = [];
      jQuery('.mainwp_cost_tracker_assistant_installed_items').each(function () {
        let slug = jQuery(this).attr('item-slug');
        let items_slug = {};
        if (!cost_tracker_check_slugs.includes(slug)) {
          cost_tracker_check_slugs.push(slug);
          let siteids = [];
          jQuery('.mainwp_cost_tracker_assistant_installed_items[item-slug="' + slug + '"]').each(function () {
            siteids.push(jQuery(this).attr('item-siteid'));
          });
          items_slug.slug = slug;
          items_slug.name = jQuery(this).attr('item-name');
          items_slug.type = jQuery(this).attr('item-type');
          items_slug.cost_id = jQuery(this).attr('cost-id');
          items_slug.sites_ids = siteids;
          cost_tracker_items.push(items_slug);
        }
      });

      if (cost_tracker_items.length > 0) {
        jQuery('.mainwp-cost-tracker-assistant-add-buttons-wrapper').html('');
        let multiAddTo = cost_tracker_items.length > 1 ? 1 : 0;
        cost_tracker_items.forEach(item => {
          jQuery('.mainwp-cost-tracker-assistant-add-buttons-wrapper').append('<a href="javascript:void(0)" item-type="' + item.type + '" item-slug="' + item.slug + '" cost-id="' + item.cost_id + '" item-name="' + item.name + '" installed-sites="' + item.sites_ids.join(',') + '" multi-add-to="' + multiAddTo + '" class="ui mini button mainwp-cost-tracker-assistant-add-to-cost-tracker-button">' + ((item.cost_id != undefined && item.cost_id > 0) ? __('Edit Cost Tracker') : __('Add to Cost Tracker')) + '</a>');
        });
      }
    }
  }
};

/**
 * Install Plugin/Theme by Upload.
 *
 * Install specific item.
 *
 * @param string type           Plugin or theme.
 * @param string urls           URLs.
 * @param bool   activatePlugin Determines if the item should be activated or not upon installation.
 * @param bool   overwrite      Determines if the item should overwrite exisitng version.
 * @param object siteToInstall  Site to install the item to.
 *
 * @return void
 */
let mainwp_upload_bulk_start_specific = function (type, urls, activatePlugin, overwrite, siteToInstall) {
  bulkInstallCurrentThreads++;
  siteToInstall.attr('status', 'progress');

  siteToInstall.find('.queue').hide();
  siteToInstall.find('.progress').show();

  let data = mainwp_secure_data({
    action: 'mainwp_installbulkuploadplugintheme',
    type: type,
    urls: urls,
    activatePlugin: activatePlugin,
    overwrite: overwrite,
    siteId: siteToInstall.attr('siteid')
  });

  jQuery.post(ajaxurl, data, function (type, urls, activatePlugin, overwrite, siteToInstall) {
    return function (response) {
      siteToInstall.attr('status', 'done');
      siteToInstall.find('.progress').hide();
      let statusEl = siteToInstall.find('.status');
      let siteid = siteToInstall.attr('siteid');
      statusEl.show();

      if (response.error != undefined) {
        statusEl.html(response.error);
        statusEl.css('color', 'red');
      } else if (response?.ok[siteid] != undefined) {
        let results = '';
        if (response?.results[siteid] != undefined) {
          let entries = Object.entries(response.results[siteid]);
          results += '<div class="ui tiny middle aligned list">';
          for (let entry of entries) {
            results += '<div class="item"><div class="right floated content">' + (entry[1] ? '<i class="check green icon"></i>' : '<i class="times red icon"></i>') + '</div><div class="content">' + entry[0] + '</div></div>';
          }
          results += '</div>';
        }
        jQuery('div[siteId="' + siteid + '"] .installation-entries').html(results);
        if (response.cost_tracker_installed_info != undefined) { // to support add to cost tracker pro.
          jQuery('div[siteId="' + siteid + '"] .installation-entries').after(response.cost_tracker_installed_info);
        }
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Installation completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>');
      } else if (response?.errors[siteid] != undefined) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + response.errors[siteid][1] + '"><i class="times red icon"></i></span>');
      } else {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Undefined error occurred. Please try again.', 'mainwp') + '"><i class="times red icon"></i></span>');
      }

      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress('set progress', bulkInstallDone);
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + mainwpVars.bulkInstallTotal + ' ' + __('Installed'));
      mainwp_upload_bulk_start_next(type, urls, activatePlugin, overwrite);
    }
  }(type, urls, activatePlugin, overwrite, siteToInstall), 'json');
};

jQuery(function ($) {
  jQuery(document).on('click', '.open-plugin-details-modal', function () {
    let itemDetail = this;

    let openwpp = jQuery(this).attr('open-wpplugin');
    let openwpp_site = '';
    if (typeof openwpp != "undefined" && 'yes' == openwpp) {
      let findNext = jQuery(this).closest('tr').next().find('tr[open-wpplugin-siteid]');
      if (findNext.length > 0) {
        openwpp_site = '&wpplugin=' + jQuery(findNext).attr('open-wpplugin-siteid');
      }
    }
    $('#mainwp-plugin-details-modal').modal({
      onHide: function () {
      },
      onShow: function () {
        $('#mainwp-plugin-details-modal').find('.ui.embed').embed({
          source: 'WP',
          url: $(itemDetail).attr('href') + openwpp_site,
        });
      }
    }).modal('show');
    return false;
  });
});


/**
 * Install check plugins.
 *
 */

window.mainwp_install_check_plugin_prepare = function (slug) {
  let selected = jQuery("input[name='install_checker[]']:checked");
  if (selected.length == 0) {
    feedback('mainwp-message-zone-install', __('Please select website to install plugin.'), 'yellow');
    return;
  } else {
    selected.each(function () {
      jQuery(this).closest('.siteBulkInstall').attr('status', 'queue');
    });
  }
  jQuery('#mainwp-install-check-btn').addClass('disabled');
  mainwp_set_message_zone('#mainwp-message-zone-install', '<i class="notched circle loading icon"></i> ', false, true); // false: not change the color class.
  let data = mainwp_secure_data({
    action: 'mainwp_preparebulkinstallcheckplugin',
    slug: slug,
  });
  jQuery.post(ajaxurl, data, function (response) {
    mainwp_set_message_zone('#mainwp-message-zone-install');
    mainwp_install_check_plugin_start_next(response.url);
  }, 'json');
};

let mainwp_install_check_plugin_start_next = function (url) {
  while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) { // NOSONAR - modified outside the function.
    mainwp_install_check_plugin_start_specific(url, siteToInstall);
  }
};

let mainwp_install_check_plugin_start_specific = function (url, siteToInstall) {
  bulkInstallCurrentThreads++;

  siteToInstall.attr('status', 'progress');
  siteToInstall.find('.queue').hide();
  siteToInstall.find('.progress').show();

  let data = mainwp_secure_data({
    action: 'mainwp_installbulkinstallplugintheme',
    type: 'plugin',
    url: url,
    activatePlugin: 'true',
    siteId: siteToInstall.attr('siteid')
  });

  jQuery.post(ajaxurl, data, function (url, siteToInstall) {
    return function (response) {
      siteToInstall.attr('status', 'done');
      siteToInstall.find('.progress').hide();

      let statusEl = siteToInstall.find('.status');
      statusEl.show();

      if (response.error != undefined) {
        statusEl.html(response.error);
        statusEl.css('color', 'red');
      } else if (response?.ok[siteToInstall.attr('siteid')]) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Installation completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>');
      } else if (response?.errors[siteToInstall.attr('siteid')]) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + response.errors[siteToInstall.attr('siteid')][1] + '"><i class="times red icon"></i></span>');
      } else {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Undefined error occurred. Please try again.', 'mainwp') + '"><i class="times red icon"></i></span>');
      }

      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress('set progress', bulkInstallDone);
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + mainwpVars.bulkInstallTotal + ' ' + __('Installed'));
      mainwp_install_check_plugin_start_next(url);
    }
  }(url, siteToInstall), 'json');
};

function isUrl(url) {
  try {
    new URL(url);
    return true;
  } catch (e) {
    return false;
  }
}

function removeUrlParams(url,params) {
    try {
        const urlObj = new URL(url);
        jQuery(params).each(function(idx, param){
            urlObj.searchParams.delete(param);
        });
        return urlObj.toString();
    } catch (e) {
        console.log(e);
    }
    return '';
}

function setVisible(what, vis) {
  if (vis) {
    jQuery(what).show();
  } else {
    jQuery(what).hide();
  }
}
function setHtml(what, text, ptag) {
  if (typeof ptag == "undefined")
    ptag = true;

  setVisible(what, true);
  if (ptag)
    jQuery(what).html('<span>' + text + '</span>');
  else
    jQuery(what).html(text);
  scrollToElement(what);
}


/**
 * Notes
 */
jQuery(function () {

  jQuery(document).on('click', '#mainwp-notes-cancel', function () {
    jQuery('#mainwp-notes-status').html('');
    jQuery('#mainwp-notes-status').removeClass('red green');
    mainwp_notes_hide();
    return false;
  });

  jQuery(document).on('click', '#mainwp-notes-save', function () {
    let which = jQuery('#mainwp-which-note').val();
    if (which == 'site') {
      mainwp_notes_site_save();
    } else if (which == 'theme') {
      mainwp_notes_theme_save();
    } else if (which == 'plugin') {
      mainwp_notes_plugin_save();
    } else if (which == 'client') {
      mainwp_notes_client_save();
    }
    let newnote = jQuery('#mainwp-notes-note').val();
    jQuery('#mainwp-notes-html').html(newnote);
    return false;
  });

  jQuery(document).on('click', '.mainwp-edit-site-note', function () {
    let id = jQuery(this).attr('id').substring(13);
    let note = jQuery('#mainwp-notes-' + id + '-note').html();
    jQuery('#mainwp-notes-html').html(note == '' ? __('No saved notes. Click the Edit button to edit site notes.') : note);
    jQuery('#mainwp-notes-note').val(note);
    jQuery('#mainwp-notes-websiteid').val(id);
    jQuery('#mainwp-which-note').val('site'); // to fix conflict.
    mainwp_notes_show();
    if(jQuery(this).attr('add-new')){
        jQuery( '#mainwp-notes-edit' ).trigger( "click" );
    }
    return false;
  });

  jQuery(document).on('click', '#mainwp-notes-edit', function () {
    jQuery('#mainwp-notes-html').hide();
    jQuery('#mainwp-notes-editor').show();
    jQuery(this).hide();
    jQuery('#mainwp-notes-save').show();
    jQuery('#mainwp-notes-status').html('');
    jQuery('#mainwp-notes-status').removeClass('red green');
    return false;
  });
  jQuery('#redirectForm').trigger("submit");
  if (jQuery('div.ui.open-site-close-window').length > 0) {
    setTimeout(function () {
      window.close()
    }, 3000);
  }
});

window.mainwp_notes_show = function (reloadClose) {
  if (reloadClose) {
    jQuery('#mainwp-notes-modal').modal({
      onHide: function () {
        window.location.href = location.href
      }
    }).modal('show');
  } else {
    jQuery('#mainwp-notes-modal').modal({ closable: false }).modal('show');
  }

  jQuery('#mainwp-notes-html').show();
  jQuery('#mainwp-notes-editor').hide();
  jQuery('#mainwp-notes-save').hide();
  jQuery('#mainwp-notes-edit').show();
};
let mainwp_notes_hide = function () {
  jQuery('#mainwp-notes-modal').modal('hide');
};
let mainwp_notes_site_save = function () {
  let normalid = jQuery('#mainwp-notes-websiteid').val();
  let newnote = jQuery('#mainwp-notes-note').val();
  newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
  let data = mainwp_secure_data({
    action: 'mainwp_notes_save',
    websiteid: normalid,
    note: newnote
  });

  jQuery('#mainwp-notes-status').html('<i class="notched circle loading icon"></i> ' + __('Saving note. Please wait...')).show();

  jQuery.post(ajaxurl, data, function (response) {
    if (response.error != undefined) {
      jQuery('#mainwp-notes-status').html(response.error).addClass('red');
    } else if (response.result == 'SUCCESS') {
      jQuery('#mainwp-notes-status').html(__('Note saved successfully.')).addClass('green');
      if (jQuery('#mainwp-notes-' + normalid + '-note').length > 0) {
        jQuery('#mainwp-notes-' + normalid + '-note').html(jQuery('#mainwp-notes-note').val());
      }
    } else {
      jQuery('#mainwp-notes-status').html(__('Undefined error occured while saving your note!')).addClass('red');
    }
  }, 'json');

  setTimeout(function () {
    jQuery('#mainwp-notes-status').fadeOut(300);
  }, 3000);

  jQuery('#mainwp-notes-html').show();
  jQuery('#mainwp-notes-editor').hide();
  jQuery('#mainwp-notes-save').hide();
  jQuery('#mainwp-notes-edit').show();

};

window.getErrorMessage = function (pError, msgOnly) { // NOSONAR - complex.
  if (pError.message == 'HTTPERROR') {
    return __('HTTP error') + '! ' + pError.extra;
  } else if (pError.message == 'NOMAINWP' || pError == 'NOMAINWP') {
    return mainwp_js_get_error_not_detected_connect();
  } else if (pError.message == 'ERROR') {
    return 'ERROR' + ((pError.extra != '') && (pError.extra != undefined) ? ': ' + pError.extra : '');
  } else if (pError.message == 'WPERROR') {
    let extrMsg = (pError.extra != '') && (pError.extra != undefined) ? pError.extra : '';
    if (msgOnly != undefined && msgOnly && extrMsg != '') {
      return extrMsg;
    } else {
      return __('ERROR on the child site') + ': ' + extrMsg;
    }

  } else if (pError.message != undefined && pError.message != '') {
    return pError.message;
  } else {
    return pError;
  }
};

window.getErrorMessageInfo = function (repError, outputType) { // NOSONAR - complex.
  let msg = '';
  let msgUI = '<i class="red times icon"></i>';

  if (repError.errorCode != undefined && repError.errorCode == 'SUSPENDED_SITE') {
    msg = __('Suspended site.');
    msgUI = '<span data-inverted="" data-position="left center" data-tooltip="' + __('Suspended site.') + '"><i class="pause circular yellow inverted icon"></i></span>';
  }

  if (repError.errorCode != undefined && repError.errorCode == 'MAINWP_NOTICE') {
    if (repError.message != undefined) {
      msg = repError.message;
      msgUI = '<span data-inverted="" data-position="left center" data-tooltip="' + msg + '"><i class="pause circular yellow inverted icon"></i></span>';
    }
  }

  if (msg == '') {
    msg = getErrorMessage(repError);

    if (repError.message == 'NOMAINWP' || repError == 'NOMAINWP') {
      msg = mainwp_js_get_error_not_detected_connect();
    }

    if (msg != '') {
      msgUI = '<span data-inverted="" data-position="left center" data-tooltip="' + msg + '"><i class="red times icon"></i></span>';
    }
  }

  if (msg != '') {
    if (outputType != undefined && outputType == 'ui') {
      return msgUI;
    } else {
      return msg;
    }
  }

  return repError;
}

window.dateToHMS = function (date) {
  if (mainwpParams?.time_format) {
    let time = moment(date);
    let format = mainwpParams['time_format'];
    format = format.replace('g', 'h');
    format = format.replace('i', 'mm');
    format = format.replace('s', 'ss');
    format = format.replace('F', 'MMMM');
    format = format.replace('j', 'D');
    format = format.replace('Y', 'YYYY');
    return time.format(format);
  }
  let h = date.getHours();
  let m = date.getMinutes();
  let s = date.getSeconds();
  return '' + (h <= 9 ? '0' + h : h) + ':' + (m <= 9 ? '0' + m : m) + ':' + (s <= 9 ? '0' + s : s);
};
window.appendToDiv = function (pSelector, pText, pScrolldown, pShowTime) {
  if (pScrolldown == undefined)
    pScrolldown = true;
  if (pShowTime == undefined)
    pShowTime = true;

  let theDiv = jQuery(pSelector);
  theDiv.append('<br />' + (pShowTime ? dateToHMS(new Date()) + ' ' : '') + pText);
  if (pScrolldown)
    theDiv.animate({ scrollTop: theDiv.prop("scrollHeight") }, 100);
};

jQuery.fn.exists = function () {
  return (this.length !== 0);
};


function __(text, _var1, _var2, _var3) {
  if (text == undefined || text == '')
    return text;
  let strippedText = text.replace(/\W/g, '_');

  if (strippedText == '')
    return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);

  if (mainwpTranslations == undefined)
    return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);
  if (mainwpTranslations[strippedText] == undefined)
    return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);

  return mainwpTranslations[strippedText].replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);
}

window.mainwp_secure_data = function (data, includeDts) {
  if (data['action'] == undefined)
    return data;

  if (security_nonces[data['action']] == undefined)
    return data;

  data['security'] = security_nonces[data['action']];
  if (includeDts)
    data['dts'] = Math.round(new Date().getTime() / 1000);
  return data;
};


window.mainwp_uid = function () {
  // always start with a letter (for DOM friendlyness)
  let idstr = String.fromCharCode(Math.floor((Math.random() * 25) + 65)); // NOSONAR - safe, it's id.
  do {
    // between numbers and characters (48 is 0 and 90 is Z (42-48 = 90)
    let ascicode = Math.floor((Math.random() * 42) + 48); // NOSONAR - safe, it's id.
    if (ascicode < 58 || ascicode > 64) {
      // exclude all chars between : (58) and @ (64)
      idstr += String.fromCharCode(ascicode);
    }
  } while (idstr.length < 32);

  return (idstr);
};

window.scrollToElement = function () {
  jQuery('html,body').animate({
    scrollTop: 0
  }, 1000);

  return false;
};

jQuery(function () {
  jQuery('#backup_filename').on('keypress', function (e) {
    let chr = String.fromCharCode(e.which);
    return ("$^&*/".indexOf(chr) < 0);
  });
  jQuery('#backup_filename').on('change', function () {
    let value = jQuery(this).val();
    let notAllowed = ['$', '^', '&', '*', '/'];
    for (let char of notAllowed) {
      if (value.indexOf(char) >= 0) {
        value = value.replace(new RegExp('\\' + char, 'g'), '');
        jQuery(this).val(value);
      }
    }
  });
});

/*
 * Server Info
 */

window.serverinfo_prepare_download_info = function (communi) {
  let report = "";
  jQuery('.mainwp-system-info-table thead, .mainwp-system-info-table tbody').each(function () {
    let td_len = [35, 55, 45, 12, 12];
    let th_count = 0;
    let i;
    if (jQuery(this).is('thead')) {
      i = 0;
      report = report + "\n### ";
      th_count = jQuery(this).find('th:not(".mwp-not-generate-row")').length;
      jQuery(this).find('th:not(".mwp-not-generate-row")').each(function () {
        let len = td_len[i];
        if (i == 0 || i == th_count - 1)
          len = len - 4;
        report = report + jQuery.mwp_strCut(jQuery(this).text().trim(), len, ' ');
        i++;
      });
      report = report + " ###\n\n";
    } else {
      jQuery('tr', jQuery(this)).each(function () {
        if (communi && jQuery(this).hasClass('mwp-not-generate-row'))
          return;
        i = 0;
        jQuery(this).find('td:not(".mwp-not-generate-row")').each(function () {
          if (jQuery(this).hasClass('mwp-hide-generate-row')) {
            report = report + jQuery.mwp_strCut(' ', td_len[i], ' ');
            i++;
            return;
          }
          report = report + jQuery.mwp_strCut(jQuery(this).text().trim(), td_len[i], ' ');
          i++;
        });
        report = report + "\n";
      });

    }
  });

  try {
    if (communi) {
      report = '```' + "\n" + report + "\n" + '```';
    }
    jQuery("#download-server-information textarea").val(report).trigger("select");
  } catch (e) {
    console.log('Error:');
  }
  return false;
}

jQuery(document).on('click', '#mainwp-download-system-report', function () {
  serverinfo_prepare_download_info(false);
  let server_info = jQuery('#download-server-information textarea').val();
  let blob = new Blob([server_info], { type: "text/plain;charset=utf-8" });
  saveAs(blob, "mainwp-system-report.txt");
  return false;
});

// Copies a string to the clipboard. Must be called from within an
// event handler such as click.
let mainwp_copy_to_clipboard = function (text, event) {
  let clipboardDT = event.clipboardData || window.clipboardData || event.originalEvent.clipboardData; // NOSONAR - to compatible.
  console.log(clipboardDT);
  if (clipboardDT && clipboardDT.setData) {  // NOSONAR - to compatible.
    console.warn("Copy to clipboard.");
    return clipboardDT.setData("Text", text);
  } else {
    try {
      if (document?.queryCommandSupported && document.queryCommandSupported("copy")) { // NOSONAR - to compatible.
        console.warn("Copy to clipboard exec.");
        return document.execCommand("copy");  // NOSONAR - to compatible, security exception may be thrown by some browsers.
      }
    } catch (ex) {
      console.warn("Copy to clipboard failed.", ex);
    }
  }
}

jQuery(document).on('click', '#mainwp-copy-meta-system-report', function (event) {
  event.preventDefault();
  jQuery("#download-server-information").slideDown(); // to able to select and copy
  serverinfo_prepare_download_info(true);
  jQuery("#download-server-information").slideUp(); // to support 'copy' method.
  mainwp_copy_to_clipboard(jQuery("#download-server-information").val(), event);
});


jQuery.mwp_strCut = function (i, l, s, w) {
  let o = i.toString();
  if (!s) {
    s = '0';
  }
  while (o.length < parseInt(l)) {
    // empty
    if (w == 'undefined') {
      o = s + o;
    } else {
      o = o + s;
    }
  }
  return o;
};

window.updateExcludedFolders = function () {
  let excludedBackupFiles = jQuery('#excludedBackupFiles').html();
  jQuery('#mainwp-kbl-content').val(excludedBackupFiles == undefined ? '' : excludedBackupFiles);

  let excludedCacheFiles = jQuery('#excludedCacheFiles').html();
  jQuery('#mainwp-kcl-content').val(excludedCacheFiles == undefined ? '' : excludedCacheFiles);

  let excludedNonWPFiles = jQuery('#excludedNonWPFiles').html();
  jQuery('#mainwp-nwl-content').val(excludedNonWPFiles == undefined ? '' : excludedNonWPFiles);
};


jQuery(document).on('click', '.mainwp-events-notice-dismiss', function () {
  let notice = jQuery(this).attr('notice');
  jQuery(this).closest('.ui.message').fadeOut(500);
  let data = mainwp_secure_data({
    action: 'mainwp_events_notice_hide',
    notice: notice
  });
  jQuery.post(ajaxurl, data, function () {
  });
  return false;
});

// Turn On child plugin auto update
jQuery(document).on('click', '#mainwp_btn_autoupdate_and_trust', function () {
  jQuery(this).attr('disabled', 'true');
  let data = mainwp_secure_data({
    action: 'mainwp_autoupdate_and_trust_child'
  });
  jQuery.post(ajaxurl, data, function (res) {
    if (res == 'ok') {
      location.reload(true);
    } else {
      jQuery(this).prop("disabled", false);
    }
  });
  return false;
});

// Hide installation warning
jQuery(document).on('click', '#remove-mainwp-installation-warning', function () {
  jQuery(this).closest('.ui.message').fadeOut("slow");
  let data = mainwp_secure_data({
    action: 'mainwp_installation_warning_hide'
  });
  jQuery.post(ajaxurl, data, function () { });
  return false;
});

jQuery(document).on('click', '.mainwp-notice-hide', function () {
  jQuery(this).closest('.ui.message').fadeOut("slow");
  return false;
});

// Hide after installtion notices (PHP version, Trust MainWP Child, Multisite Warning and OpenSSL warning)
jQuery(document).on('click', '.mainwp-notice-dismiss', function () {
  let notice_id = jQuery(this).attr('notice-id');
  jQuery(this).closest('.ui.message').fadeOut("slow");
  let data = {
    action: 'mainwp_notice_status_update'
  };
  data['notice_id'] = notice_id;
  jQuery.post(ajaxurl, mainwp_secure_data(data), function () { });
  return false;
});


window.mainwp_notice_dismiss = function (notice_id, time_set) {
  let data = {
    action: 'mainwp_notice_status_update'
  };
  data['notice_id'] = notice_id;
  if (typeof time_set !== "undefined") {
    data['time_set'] = time_set ? 1 : 0;
  }
  jQuery.post(ajaxurl, mainwp_secure_data(data), function () {
    console.log('dismissed');
  });
  return false;
}


jQuery(document).on('click', '.mainwp-activate-notice-dismiss', function () {
  jQuery(this).closest('tr').fadeOut("slow");
  let data = mainwp_secure_data({
    action: 'mainwp_dismiss_activate_notice',
    slug: jQuery(this).closest('tr').attr('slug')
  });
  jQuery.post(ajaxurl, data, function () {
  });
  return false;
});

jQuery(document).on('click', '.mainwp-install-check-dismiss', function () {
  let notice_id = jQuery(this).attr('notice-id');
  jQuery(this).closest('.ui.message').fadeOut("slow");
  let data = {
    action: 'mainwp_notice_status_update'
  };
  data['notice_id'] = notice_id;
  jQuery.post(ajaxurl, mainwp_secure_data(data), function () { });
  return false;
});

jQuery(document).on('click', '#mainwp-dismiss-sites-changes-actions-button', function () {
  mainwp_confirm('You are about to dismiss the selected changes?', function () {
    mainwp_delete_nonmainwp_data_start();
  });
  return false;
});

let mainwp_managesites_update_childsite_value = function (siteId, uniqueId) {
  let data = mainwp_secure_data({
    action: 'mainwp_updatechildsite_value',
    site_id: siteId,
    unique_id: uniqueId
  });
  jQuery.post(ajaxurl, data, function () {
  });
  return false;
};

jQuery(document).on('keyup', '#managegroups-filter', function () {
  let filter = jQuery(this).val();
  let groupItems = jQuery(this).parent().parent().find('li.managegroups-listitem');
  for (let igr of groupItems) {
    let currentElement = jQuery(igr);
    if (currentElement.hasClass('managegroups-group-add')) {
      continue;
    }
    let value = currentElement.find('span.text').text();
    if (value.indexOf(filter) > -1) {
      currentElement.show();
    } else {
      currentElement.hide();
    }
  }
});

// for normal checkboxes
jQuery(document).on('change', '#cb-select-all-top, #cb-select-all-bottom', function () {
  let $this = jQuery(this), $table, controlChecked = $this.prop('checked');

  $table = $this.closest('.dt-scroll').find('.dt-scroll-body table'); // for dt with scroll enabled.

  // if no scrollable table.
  if ($table.length == 0) {
    $table = $this.closest('table.table.dataTable');
  }

  if ($table.length == 0)
    return false;

  $table.children('tbody').filter(':visible')
    .children().children('.check-column').find(':checkbox')
    .prop('checked', function () {
      if (jQuery(this).is(':hidden,:disabled')) {
        return false;
      }
      if (controlChecked) {
        jQuery(this).closest('tr').addClass('selected');
        return true;
      }
      jQuery(this).closest('tr').removeClass('selected');
      return false;
    });

  $table.children('thead,  tfoot').filter(':visible')
    .children().children('.check-column').find(':checkbox')
    .prop('checked', function () {
      if (controlChecked) {
        jQuery(this).closest('tr').addClass('selected');
        return true;
      }
      jQuery(this).closest('tr').removeClass('selected');
      return false;
    });
  let dtApi = jQuery($table).dataTable().api();
  let setStatus = controlChecked ? 'selected' : 'deselected';
  mainwp_datatable_fix_to_update_selected_rows_status(dtApi, setStatus);
});


jQuery(document).on('change', '.cb-select-all-parent-top, .cb-select-all-parent-bottom', function () {

  let parentChecked = jQuery(this).is(":checked");
  let parentSelector = jQuery(this).attr('cb-parent-selector') ?? false;

  if (false === parentSelector) {
    return;
  }
  console.log(parentSelector);
  jQuery(parentSelector + ' .ui.checkbox').find(':checkbox')
    .prop('checked', function () {
      console.log(this);
      console.log(parentChecked);
      if (parentChecked) {
        jQuery(this).closest('tr').addClass('selected');
        return true;
      }
      jQuery(this).closest('tr').removeClass('selected');
      return false;
    });

});

jQuery(function ($) {
  // Trigger the bulk actions
  $('#mainwp_sites_changes_bulk_dismiss_selected_btn').on('click', function () {
    if ( jQuery('#mainwp-module-log-records-body-table tr').find('input[type="checkbox"]:checked').length == 0 ){
        return;
    }
    let confirmMsg = __("You are about to dismiss the selected changes?");
    mainwp_confirm(confirmMsg, function () { mainwp_sites_changes_actions_bulk_action('dismiss-selected'); });
  });

  $('#mainwp_sites_changes_bulk_dismiss_all_btn').on('click', function () {
    if ( jQuery('#mainwp-module-log-records-body-table tr').find('input[type="checkbox"]').length == 0 ){
        return;
    }
    let confirmMsg = __("You are about to dismiss all changes?");
    mainwp_confirm(confirmMsg, function () { mainwp_sites_changes_actions_bulk_action('dismiss-all'); });
  });

  $(document).on('click', '.insights-actions-row-dismiss', function () {
    return mainwp_insights_row_actions_dismiss(this);
  });
})

let mainwp_insights_row_actions_dismiss = function (obj) {
  let row = jQuery(obj).closest('tr');
  let confirmMsg = __("You are about to dismiss the selected change?");

  let _callback = () => {
        row.html('<td></td><td colspan="999"><i class="notched circle loading icon"></i> Please wait...</td>');
        let data = mainwp_secure_data({
            action: 'mainwp_insight_events_dismiss_actions',
            log_id: jQuery(row).attr('log-id')
        });
        jQuery.post(ajaxurl, data, function (response) {
        if (response) {
            if (response['error']) {
                row.html('<td></td><td colspan="999"><i class="times red icon"></i> ' + response['error'] + '</td>');
            } else if (response['success'] == 'yes') {
                row.html('<td></td><td colspan="999"><i class="green check icon"></i> The change has been dismissed.</td>');
                setTimeout(function () {
                    jQuery(row).fadeOut("slow");
                }, 2000);
            } else {
                row.html('<td></td><td colspan="999"><i class="times red icon"></i> The change could not be dismissed.</td>');
            }
        } else {
            row.html('<td></td><td colspan="999"><i class="times red icon"></i> The change could not be dismissed.</td>');
        }
        }, 'json');
    };
    mainwp_confirm(confirmMsg, _callback);
    return false;
}


// Manage Bulk Actions
let mainwp_sites_changes_actions_bulk_action = function (act) {
  mainwpVars.bulkInstallTotal = 0;
  bulkInstallCurrentThreads = 0;
  bulkInstallDone = 0;
  if ( act === 'dismiss-selected' ) {
    jQuery('#mainwp_sites_changes_bulk_dismiss_selected_btn').addClass('disabled');
    let selector = '#mainwp-module-log-records-body-table tr';
    mainwpVars.bulkInstallTotal = jQuery(selector).find('input[type="checkbox"]:checked').length;
    jQuery(selector).addClass('queue');
    mainwp_sites_changes_actions_dismiss_start_next(selector);
  } else if( act === 'dismiss-all' ) {
    jQuery('#mainwp_sites_changes_bulk_dismiss_all_btn').addClass('disabled');
    mainwp_sites_changes_actions_dismiss_all();
  }
}

let mainwp_sites_changes_actions_dismiss_start_next = function (selector) {
  while ((objProcess = jQuery(selector + '.queue:first')) && (objProcess.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) { // NOSONAR - modified outside the function.
    objProcess.removeClass('queue');
    if (objProcess.closest('tr').find('input[type="checkbox"]:checked').length == 0) {
      continue;
    }
    mainwp_sites_changes_actions_dismiss_specific(objProcess, selector);
  }
}

let mainwp_sites_changes_actions_dismiss_specific = function (pObj, selector) {
  let row = pObj.closest('tr');
  let act_id = jQuery(row).attr('log-id');

    bulkInstallCurrentThreads++;

  let data = mainwp_secure_data({
    action: 'mainwp_insight_events_dismiss_actions',
    log_id: act_id
  });

  row.html('<td></td><td colspan="999"><i class="notched circle loading icon"></i> Please wait...</td>');

  jQuery.post(ajaxurl, data, function (response) {
    pObj.removeClass('queue');
    if (response) {
      if (response['error']) {
        row.html('<td></td><td colspan="999"><i class="times red icon"></i> ' + response['error'] + '</td>');
      } else if (response['success'] == 'yes') {
        row.html('<td></td><td colspan="999"><i class="green check icon"></i> The change has been dismissed.</td>');
        setTimeout(function () {
          jQuery(row).fadeOut("slow");
        }, 2000);
      } else {
        row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
      }
    } else {
      row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
    }

    bulkInstallCurrentThreads--;
    bulkInstallDone++;
    mainwp_sites_changes_actions_dismiss_start_next(selector);
    if (mainwpVars.bulkInstallTotal == bulkInstallDone) {
        jQuery('#mainwp_sites_changes_bulk_dismiss_selected_btn').removeClass('disabled');
    }
  }, 'json');
  return false;
}

let mainwp_sites_changes_actions_dismiss_all = function(){
  let data = mainwp_secure_data({
    action: 'mainwp_insight_events_dismiss_all',
  });
  mainwp_showhide_message('mainwp-message-zone-top', '<i class="notched circle loading icon"></i> Please wait...', 'green' );
  jQuery.post(ajaxurl, data, function (response) {
    if (response) {
      if (response['error']) {
        mainwp_showhide_message('mainwp-message-zone-top', '<i class="times red icon"></i> ' + response['error'], 'red' );
      } else if (response['success'] == 'yes') {
        mainwp_showhide_message('mainwp-message-zone-top', '<i class="green check icon"></i> All changes has been dismissed.', 'green' );
        setTimeout(function () {
            window.location.href = location.href
        }, 2000);
      } else {
        mainwp_showhide_message('mainwp-message-zone-top', '<i class="times red icon"></i> Failed. Please try again.', 'green' );
      }
    } else {
        mainwp_showhide_message('mainwp-message-zone-top', '<i class="times red icon"></i> Failed. Please try again.', 'green' );
    }
  }, 'json');
  return false;

}


window.mainwp_datatable_fix_to_update_selected_rows_status = function (dtApi, setStatus) {
  if (dtApi) {
    console.log('Datatable - update selected rows status.');
    if ('selected' === setStatus) {
      dtApi.rows('.selected').select(); // update selected status.
    } else if ('deselected' === setStatus) {
      dtApi.rows().deselect(); // update deselected status.
    }
  }
}

window.mainwp_datatable_fix_to_update_rows_state = function (tblSelect) {
  if (jQuery(tblSelect).length) {
    let $table = jQuery(tblSelect);

    let dtApi = jQuery($table).dataTable().api(); // NOTE: not use DataTable().

    mainwp_datatable_fix_to_update_selected_rows_status(dtApi, 'deselected'); // clear saved state.

    $table.children('tbody').filter(':visible').find('tr').each(function () {
      if (jQuery(this).children('.check-column').find(':checkbox').is(':checked')) {
        jQuery(this).addClass('selected');
      } else if (jQuery(this).hasClass('selected')) {
        jQuery(this).removeClass('selected');
      }
    });

    mainwp_datatable_fix_to_update_selected_rows_status(dtApi, 'selected'); // to update selected state.
    console.log('Datatable - update rows state.');
  }
}

window.mainwp_datatable_fix_reorder_selected_rows_status = function () {
  console.log('Fixing: reordercol selected rows.');
  jQuery('.table.dataTable tbody').filter(':visible').children('tr.selected').find(':checkbox').prop('checked', true);
};

// fix menu overflow with scroll tables.
window.mainwp_datatable_fix_menu_overflow = function (pTableSelector, pTop, pRight ) {
    if( typeof pTableSelector === "undefined" ){
        console.warn('mainwp_datatable_fix_menu_overflow: requires params - $pTableSelector');
    }
  let dtScrollBdCls = '.dt-scroll-body';
  let dtScrollCls = '.dt-scroll';
  let fix_overflow = jQuery('.mainwp-content-wrap').attr('menu-overflow');
  jQuery(document).on('click', 'table td.check-column.dtr-control', function () {
    if (jQuery(this).parent().hasClass('parent')) {
      let chilRow = jQuery(this).parent().next();
      jQuery(chilRow).find('.ui.dropdown').dropdown();
      mainwp_datatable_fix_child_menu_overflow(chilRow, fix_overflow);
    }
  });
  let tblSelect = pTableSelector ?? 'table';

// to prevent double events.
jQuery(tblSelect + ' tr td .ui.right.pointing.dropdown').each(function () {
    let parentTB = jQuery(this).closest('table');
    if(parentTB.attr('fixed-menu-overflow') === undefined){
        parentTB.attr('fixed-menu-overflow', 'no'); // to init click menu events.
    }
});

jQuery(tblSelect + ' tr td .ui.left.pointing.dropdown').each(function () {
    let parentTB = jQuery(this).closest('table');
    if(parentTB.attr('fixed-menu-overflow') === undefined){
        parentTB.attr('fixed-menu-overflow', 'no'); // to init click menu events.
    }
});

// if table selector specific.
if(typeof pTableSelector !== "undefined" ){
    if(jQuery(pTableSelector + '[fixed-menu-overflow="yes"]' ).length){
        jQuery(pTableSelector + '[fixed-menu-overflow="yes"]').attr('fixed-menu-overflow', 'no'); // to init menus events click.
    }
}

  console.log('mainwp_datatable_fix_menu_overflow :: ' + tblSelect);

    // Fix the overflow prbolem for the actions menu element (right pointing menu).
    jQuery(tblSelect + '[fixed-menu-overflow="no"] tr td .ui.right.pointing.dropdown').on('click', function () {
        jQuery(this).closest(dtScrollBdCls).css('position', '');
        jQuery(this).closest(dtScrollCls).css('position', 'relative');
        jQuery(this).css('position', 'static');
        let fix_overflow = jQuery('.mainwp-content-wrap').attr('menu-overflow');
        let position = jQuery(this).position();
        let top = position.top;
        let right = 50;
        if (fix_overflow > 1) {
            position = jQuery(this).closest('td').position();
            top = position.top + 85; //85
        }

        if (pTop !== undefined) {
            top = top + pTop;
        }
        if (pRight !== undefined) {
            right = right + pRight;
        }

        console.log('right');
        console.log('tweaks: ' + pTop + ' right: ' + pRight);
        console.log('top: ' + top + ' right: ' + right);
        jQuery(this).find('.menu').css('min-width', '170px');
        jQuery(this).find('.menu').css('top', top);
        jQuery(this).find('.menu')[0].style.setProperty('right', right + 'px', 'important');
    });

    // Fix the overflow prbolem for the actions menu element (left pointing menu).
    jQuery(tblSelect + '[fixed-menu-overflow="no"] tr td .ui.left.pointing.dropdown').on('click', function () {
        jQuery(this).closest(dtScrollBdCls).css('position', '');
        jQuery(this).closest(dtScrollCls).css('position', 'relative');
        jQuery(this).css('position', 'static');
        let position = jQuery(this).position();

        let top = position.top;
        let left = position.left - 159;

        if (fix_overflow > 1) {
        position = jQuery(this).closest('td').position();
        let scroll_left = jQuery(this).closest(dtScrollBdCls).scrollLeft();
        top = position.top + 85;
        left = position.left - scroll_left - 145;
        }

        if (pTop !== undefined) {
        top = top + pTop;
        }

        console.log('left');
        console.log('top: ' + top + ' left: ' + left);

        jQuery(this).find('.menu').css('min-width', '150px');
        jQuery(this).removeClass('left');
        jQuery(this).addClass('right');
        jQuery(this).find('.menu').css('top', top);
        jQuery(this).find('.menu')[0].style.setProperty('left', left + 'px', 'important');
    });

    jQuery(tblSelect + '[fixed-menu-overflow="no"]').each(function () {
        jQuery(this).attr('fixed-menu-overflow', 'yes');
    });

    mainwp_datatable_fix_reorder_selected_rows_status();
}


let mainwp_datatable_fix_child_menu_overflow = function (chilRow, fix_overflow) {
  let dtScrollBdCls = '.dt-scroll-body';
  let dtScrollCls = '.dt-scroll';
  // Fix the overflow prbolem for the actions child menu element (pointing menu).
  jQuery(chilRow).find('.ui.pointing.dropdown').on('click', function () {

    let position = jQuery(this).position();
    let left = position.left + 30;
    let top = position.top;

    if (fix_overflow > 1) {
      position = jQuery(this).closest('td.child').position();
      top = position.top + jQuery(this).closest('td.child').height() + 85;
    }

    jQuery(this).closest(dtScrollBdCls).css('position', '');
    jQuery(this).closest(dtScrollCls).css('position', 'relative');
    jQuery(this).css('position', 'static');
    jQuery(this).find('.menu').css('top', top);
    jQuery(this).find('.menu').css('left', left);
    jQuery(this).find('.menu').css('min-width', '170px');
    console.log('top:' + top);
  });
}


window.mainwp_responsive_fix_remove_child_row = function (el) {
  if (jQuery(el).hasClass('dt-hasChild')) { // to fix.
    jQuery(el).next().remove();
  }
}

/* eslint-disable complexity */
function mainwp_according_table_sorting(pObj) { // NOSONAR - complex.
  let table, th, rows, switching, i, x, y, xVal, yVal, campare = false, shouldSwitch = false, dir, switchcount = 0, n, skip = 1;
  table = jQuery(pObj).closest('table')[0];
  let subline_skip = 2;
  if ('mainwp-wordpress-updates-table' == jQuery(table).attr('id')) {
    subline_skip = 1; // for rows without subline.
    skip = 0;
  }

  // get TH element
  if (jQuery(pObj)[0].tagName == 'TH') {
    th = jQuery(pObj)[0];
  } else {
    th = jQuery(pObj).closest('th')[0];
  }

  n = th.cellIndex;
  switching = true;

  // check header and footer of according table
  if (jQuery(table).children('thead,tfoot').length > 0)
    skip += jQuery(table).children('thead,tfoot').length; // skip sorting header, footer

  dir = "asc";
  /* loop until switching has been done: */
  while (switching) {
    switching = false;
    rows = table.rows;
    /* Loop through all table rows */
    for (i = 1; i < (rows.length - skip); i += subline_skip) {  // skip content according rows, sort by title rows only
      shouldSwitch = false;
      /* Get the two elements you want to compare,
      one from current row and one from the next-next: */
      x = rows[i].getElementsByTagName("TD")[n];
      y = rows[i + subline_skip].getElementsByTagName("TD")[n];

      // if sort value attribute existed then sorting on that else sorting on cell value
      if (x.hasAttribute('sort-value')) {
        xVal = parseInt(x.getAttribute('sort-value'));
        yVal = parseInt(y.getAttribute('sort-value'));
        let tmp = xVal > yVal ? -1 : 1;
        campare = (xVal == yVal) ? 0 : tmp;
      } else {
        // to prevent text() clear text content
        xVal = '<p>' + x.innerHTML + '</p>';
        yVal = '<p>' + y.innerHTML + '</p>';
        xVal = jQuery(xVal).text().trim().toLowerCase();
        yVal = jQuery(yVal).text().trim().toLowerCase();
        campare = yVal.localeCompare(xVal);
      }

      /* Check if the two rows should switch place */
      if (dir == "asc") {
        if (campare < 0) { //xVal > yVal
          shouldSwitch = true;
          // break the loop:
          break;
        }
      } else if (dir == "desc") {
        if (campare > 0) { //xVal < yVal
          // break the loop:
          shouldSwitch = true;
          break;
        }
      }
    }
    if (shouldSwitch) {
      if (2 == subline_skip) {
        rows[i].parentNode.insertBefore(rows[i + 2], rows[i]);
        rows[i + 1].parentNode.insertBefore(rows[i + 3], rows[i + 1]);
      } else {
        rows[i].parentNode.insertBefore(rows[i + 1], rows[i]); // switch 2 rows.
      }
      switching = true;
      // increase this count by 1, that is ok
      switchcount++;
    } else if (switchcount == 0 && dir == "asc") {
      /* If no switching has been done AND the direction is "asc",
      set the direction to "desc" and run the while loop again. */
      dir = "desc";
      switching = true;
    }
  }

  // no row sorting so change direction for arrows switch
  if (switchcount == 0) {
    if (jQuery(pObj).hasClass('ascending')) {
      dir = "desc";
    } else {
      dir = "asc";
    }
  }

  // add/remove class for arrows displaying
  if (dir == "asc") {
    jQuery(pObj).addClass('ascending');
    jQuery(pObj).removeClass('descending');
  } else {
    jQuery(pObj).removeClass('ascending');
    jQuery(pObj).addClass('descending');
  }
}
/* eslint-enable complexity */

jQuery(function () {
  jQuery('.handle-accordion-sorting').on('click', function () {
    mainwp_according_table_sorting(this);
    return false;
  });
});

// Force Dashboard to reestablish connection by destroying sessions - Part 1
let mainwp_force_destroy_sessions = function () {
  let confirmMsg = __('Are you sure you want to force your MainWP Dashboard to reconnect with your child sites?');
  mainwp_confirm(confirmMsg, function () {
    mainwp_force_destroy_sessions_websites = jQuery('.dashboard_wp_id[error-status=0]').map(function (indx, el) {
      return jQuery(el).val();
    });
    mainwpPopup('#mainwp-sync-sites-modal').setTitle(__('Re-establish Connection')); // popup displayed.
    mainwpPopup('#mainwp-sync-sites-modal').init({ progressMax: mainwp_force_destroy_sessions_websites.length });
    mainwp_force_destroy_sessions_part_2(0);
  });
};

let mainwp_force_destroy_sessions_part_2 = function (id) {
  if (id >= mainwp_force_destroy_sessions_websites.length) {
    mainwp_force_destroy_sessions_websites = [];
    if (mainwp_force_destroy_sessions_successed == mainwp_force_destroy_sessions_websites.length) {
      setTimeout(function () {
        mainwpPopup('#mainwp-sync-sites-modal').close(true);
      }, 3000);
    }
    mainwpPopup('#mainwp-sync-sites-modal').close(true);
    return;
  }

  let website_id = mainwp_force_destroy_sessions_websites[id];
  dashboard_update_site_status(website_id, '<i class="sync alternate loading icon"></i>');

  jQuery.post(ajaxurl, { 'action': 'mainwp_force_destroy_sessions', 'website_id': website_id, 'security': security_nonces['mainwp_force_destroy_sessions'] }, function (response) {
    let counter = id + 1;
    mainwp_force_destroy_sessions_part_2(counter);

    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(counter);

    if ('error' in response) {
      dashboard_update_site_status(website_id, '<i class="exclamation red icon"></i>');
    } else if ('success' in response) {
      mainwp_force_destroy_sessions_successed += 1;
      dashboard_update_site_status(website_id, '<i class="check green icon"></i>', true);
    } else {
      dashboard_update_site_status(website_id, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Process timed out. Please try again.', 'mainwp') + '">');
    }
  }, 'json').fail(function () {
    let counter = id + 1;
    mainwp_force_destroy_sessions_part_2(counter);
    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(counter);

    dashboard_update_site_status(website_id, '<i class="exclamation red icon"></i>');
  });

};

let mainwp_force_destroy_sessions_successed = 0;
let mainwp_force_destroy_sessions_websites = [];


jQuery(document).on('change', '#mainwp_archiveFormat', function () {
  let zipMethod = jQuery(this).val();
  zipMethod = zipMethod.replace(/\./g, '\\.');
  jQuery('span.archive_info').hide();
  jQuery('span#info_' + zipMethod).show();

  jQuery('tr.archive_method').hide();
  jQuery('tr.archive_' + zipMethod).show();

  // compare new layout
  jQuery('div.archive_method').hide();
  jQuery('div.archive_' + zipMethod).show();
});

let mainwp_import_demo_data_action = function (obj) {
  let confirmation = "Are you sure you want to import demo content into your MainWP Dashboard?";
  let msg_import = (jQuery(obj).attr('page-import') == 'qsw-import') ? '&message=qsw-import' : '';
  mainwp_confirm(confirmation, function () {
    feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Importing. Please wait...', 'mainwp'), '');
    let data = mainwp_secure_data({
      action: 'mainwp_import_demo_data',
    });

    jQuery.post(ajaxurl, data, function (response) {
      let error = false;
      if (response.count != undefined) {
        feedback('mainwp-message-zone', __('The demo content has been imported into your MainWP Dashboard.', 'mainwp'), 'green');
      } else {
        error = true;
        feedback('mainwp-message-zone', __('Undefined error. Please try again.', 'mainwp'), 'green');
      }
      if (!error) {
        setTimeout(function () {
          window.location = 'admin.php?page=mainwp_tab' + msg_import;
        }, 3000);
      }
    }, 'json');
  });
}

let mainwp_remove_demo_data_action = function () {
  let confirmation = "Are you sure you want to delete demo content from your MainWP Dashboard?";
  mainwp_confirm(confirmation, function () {
    feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Deleting. Please wait...', 'mainwp'), '');
    let data = mainwp_secure_data({
      action: 'mainwp_delete_demo_data',
    });
    jQuery.post(ajaxurl, data, function (response) {
      let error = false;
      if (response.success != undefined) {
        feedback('mainwp-message-zone', __('The demo content has been deleted from your MainWP Dashboard.', 'mainwp'), 'green');
      } else {
        error = true;
        feedback('mainwp-message-zone', __('Undefined error. Please try again.', 'mainwp'), 'green');
      }
      if (!error) {
        setTimeout(function () {
          window.location = 'admin.php?page=mainwp-setup';
        }, 3000);
      }

    }, 'json');
  });
}

// MainWP Tools
jQuery(function () {
  jQuery(document).on('click', '#force-destroy-sessions-button', function () {
    mainwp_force_destroy_sessions();
  });

  jQuery(document).on('click', '.mainwp-import-demo-data-button', function (event) {
    mainwp_import_demo_data_action(this);
    event.preventDefault();
  });

  jQuery(document).on('click', '.mainwp-remove-demo-data-button', function () {
    mainwp_remove_demo_data_action();
    return false; //required this return.
  });
});


let mainwp_tool_renew_connections_show = function () {
  jQuery('#mainwp-tool-renew-connect-modal').modal({
    allowMultiple: true,
    closable: false,
    onHide: function () {
      location.href = 'admin.php?page=MainWPTools';
    },
    onShow: function () {
      if (jQuery('#mainwp-tool-renew-connect-modal .mainwp_selected_sites_item.item.warning').length == 0) {
        jQuery('#mainwp-tool-renew-connect-modal .mainwp-ss-select-disconnected').hide();
        jQuery('#mainwp-tool-renew-connect-modal .mainwp-ss-deselect-disconnected').hide();
      }
    }
  }).modal('show');
};

let mainwp_tool_prepare_renew_connections = function (objBtn) {

  let errors = [];
  let selected_sites = [];
  mainwp_set_message_zone('#mainwp-message-zone-modal');

  jQuery("input[name='selected_sites[]']:checked").each(function () {
    selected_sites.push(jQuery(this).val());
  });
  if (selected_sites.length == 0) {
    errors.push(__('Please select at least one website to start.'));
  }

  if (errors.length > 0) {
    mainwp_set_message_zone('#mainwp-message-zone-modal', errors.join('<br />'), 'yellow');
    return;
  } else {
    mainwp_set_message_zone('#mainwp-message-zone-modal');
  }

  let confirmation = __("This process will create a new OpenSSL Key Pair on your MainWP Dashboard and Set the new Public Key to your Child site(s). Are you sure you want to proceed?");

  mainwp_confirm(confirmation, function () {
    jQuery(objBtn).attr('disabled', true);

    jQuery('#mainwp-tool-renew-connect-modal .mainwp-select-sites-wrapper').hide();

    let statusEl = jQuery('#mainwp-message-zone-modal');
    statusEl.html('<i class="notched circle loading icon"></i> ' + __('Please wait...'));
    statusEl.show();

    let data = mainwp_secure_data({
      action: 'mainwp_prepare_renew_connections',
      'sites[]': selected_sites,
    });

    jQuery.post(ajaxurl, data, function (response) {
      let undefError = false;
      if (response) {
        if (response.result != '') {
          jQuery('#mainwp-tool-renew-connect-modal').find('#mainwp-renew-connections-list').html(response.result);
          mainwpVars.bulkInstallTotal = jQuery('#mainwp-renew-connections-list .item').length;
          jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').show();
          jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').progress({ value: 0, total: mainwpVars.bulkInstallTotal });
          mainwp_tool_renew_connections_start_next();
          statusEl.hide();
        } else if (response.error) {
          statusEl.addClass('red');
          statusEl.html(response.error).fadeIn();
        } else {
          undefError = true;
        }
      } else {
        undefError = true;
      }

      if (undefError) {
        statusEl.addClass('red');
        statusEl.html(__('Undefined error occurred. Please try again.')).fadeIn();
      }
    }, 'json');
  }, false, false, true);
}

let connection_renew_status = function (siteId, newStatus) {
  jQuery('#mainwp-renew-connections-list .renew-site-status[siteid="' + siteId + '"]').html(newStatus);
};

let mainwp_tool_renew_connections_start_next = function () {
  while ((siteToReNew = jQuery('#mainwp-renew-connections-list .item[status="queue"]:first')) && (siteToReNew.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) { // NOSONAR - modified outside the function.
    mainwp_tool_renew_connections_start_specific(siteToReNew);
  }
}

let mainwp_tool_renew_connections_start_specific = function (siteItem) {

  bulkInstallCurrentThreads++;

  siteItem.attr('status', 'progress');
  let siteId = siteItem.find('.renew-site-status').attr('siteid');

  let data = mainwp_secure_data({
    action: 'mainwp_renew_connections',
    siteid: siteId
  });

  connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Processing...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  jQuery.post(ajaxurl, data, function (response) {
    if (response.error) {
      connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + response.error + '"><i class="times red icon"></i></span>');
    } else if (response.result == 'success') {
      connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Renew connnection process completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>');
    } else {
      connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Undefined error.') + '"><i class="times red icon"></i></span>');

    }
    bulkInstallCurrentThreads--;
    bulkInstallDone++;
    jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').progress('set progress', bulkInstallDone);
    jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + mainwpVars.bulkInstallTotal + ' ' + __('Processed'));
    mainwp_tool_renew_connections_start_next();
  }, 'json');
}


jQuery(function () {
  if (jQuery('body.mainwp-ui').length > 0) {
    jQuery('.mainwp-ui-page .ui.dropdown:not(.not-auto-init)').dropdown();
    jQuery('.mainwp-ui-page .ui.checkbox:not(.not-auto-init)').checkbox();
    jQuery('.mainwp-ui-page .ui.dropdown').filter('[init-value]').each(function () {
      let values = jQuery(this).attr('init-value').split(',');
      jQuery(this).dropdown('set selected', values);
    });
  }
});

// MainWP Action Logs
jQuery(document).on('click', '.mainwp-action-log-show-more', function () {
  let content = jQuery(this).closest('.item').find('.mainwp-action-log-site-response').text();
  jQuery('#mainwp-action-log-response-modal').modal({
    closable: false,
    onHide: function () {
      location.reload();
    }
  }).modal('show');
  jQuery('#mainwp-action-log-response-modal .content-response').text(content);
});

// MainWP Show Response
jQuery(document).on('click', '.mainwp-show-response', function () {
  let content = jQuery('#mainwp-response-data-container').attr('resp-data');
  jQuery('#mainwp-response-data-modal').modal({
    closable: false,
    onHide: function () {
      jQuery('#mainwp-response-data-modal .content-response').text('');
    }
  }).modal('show');
  jQuery('#mainwp-response-data-modal .content-response').text(content);
});

// Copy to clipboard for response modals.
jQuery(document).on('click', '.mainwp-response-copy-button', function (event) {
  let modal = jQuery(this).closest('.ui.modal');
  let data = jQuery(modal).find('.content.content-response').text();
  let $temp_txtarea = jQuery('<textarea style="opacity:0">');
  jQuery('body').append($temp_txtarea);
  $temp_txtarea.val(data).trigger("select"); // to support 'copy' method.
  mainwp_copy_to_clipboard(data, event);
  $temp_txtarea.remove();
  return false;
});

jQuery(function () {
  if (typeof postboxes !== "undefined" && typeof mainwp_postbox_page !== "undefined") {
    postboxes.add_postbox_toggles(mainwp_postbox_page);
  }
  mainwp_setCookie();
  mainwp_getCookie();
});

jQuery(document).on('click', '.close.icon', function () {
  jQuery(this).parent().hide();
});

/*
 * to compatible
 */
function mainwp_setCookie() {
  return false;
}

function mainwp_getCookie() {
  return false;
}

let mainwp_setttings_fields_indicator_show = function (specific_header_indicator) {
  if (typeof specific_header_indicator !== "undefined") {
    mainwp_setttings_fields_indicator_specific_show(specific_header_indicator);
    return;
  }
  // for each header indicator.
  jQuery('.settings-field-header-indicator').each(function () {
    mainwp_setttings_fields_indicator_specific_show(this);
  });
}

let mainwp_setttings_fields_indicator_specific_show = function (obj) {
  let cls = jQuery(obj).attr('field-indicator-wrapper-class');
  if ('' != cls && jQuery('.' + cls + ' .settings-field-icon-indicator.visible-indicator').length > 0) {
    jQuery(this).attr('style', 'display:inline-block;');
    jQuery(this).addClass('visible-indicator');
  }
}


jQuery(function ($) {
  if (jQuery('.mainwp-ui-page').length) {
    mainwp_setttings_fields_indicator_show();
  }

  jQuery(document).on('input', '.settings-field-value-change-handler', function () {
    let val = $(this).val();
    mainwp_settings_fields_value_on_change(this, val);
  });

  jQuery(document).on('change', '.settings-field-value-change-handler', function () { // NOSONAR - complex ok.
    let me = this;
    let objName = $(this).prop('tagName'), val;
    if ('DIV' === objName) { // ui dropdown select.
      val = $(this).dropdown('get value');
    } else if ($(this).is(':checkbox')) {
      val = $(this).is(":checked") ? '1' : '0';
      if ($(this).attr('name') === 'mainwp_show_widgets[]') {
        if ($('input[type="checkbox"][name="mainwp_show_widgets[]"]:not(:checked)').length == 0) {
          val = 'all';
        }
      } else if ($(this).attr('inverted-value')) {
        val = val === '1' ? '0' : '1'; // to fix compatible with some case checked is disable, value is 0.
      }
    } else {
      val = $(this).val();
      if ($(this).attr('name') === 'mainwp_rest_api_key_edit_pers') {
        val = val.split(',').length;
      } else if ($(this).attr('name') === 'cost_tracker_custom_product_types[title][]') {
        val = $('input[name="cost_tracker_custom_product_types[title][]"]').length; // default 0.
        me = $('.settings-field-indicator-wrapper.default-product-categories');
      } else if ($(this).attr('name') === 'cost_tracker_custom_payment_methods[title][]') {
        val = $('input[name="cost_tracker_custom_payment_methods[title][]"]').length; // default 0.
        me = $('.settings-field-indicator-wrapper.custom-payment-methods');
      }
    }
    console.log(objName);
    console.log(val);
    mainwp_settings_fields_value_on_change(me, val);
  });

  let mainwp_settings_fields_value_on_change = function (obj, val) {
    let parent = $(obj).closest('.settings-field-indicator-wrapper');
    if (parent.length) {
      let defval = $(parent).attr('default-indi-value') ?? ''; // put default-indi-value at wrapper because semantic ui some case move class of input too input's parent.
      let indiObj = parent.find('.settings-field-icon-indicator');
      if (indiObj.length) {
        console.log('value: ' + val + ' - default: ' + defval);
        if (val == defval || ('0' == val && '' === defval)) { // empty and zero are same.
          $(indiObj).removeClass('visible-indicator');
        } else {
          $(indiObj).addClass('visible-indicator');
        }
      }
    }
  }
});



let mainwp_common_filter_show_segments_modal = function (loadCallback) {
  jQuery('#mainwp-common-filter-segment-modal').modal({
    allowMultiple: false,
    onShow: function () {
      if (typeof loadCallback == 'function') {
        loadCallback();
      }
    }
  }).modal('show');
};

jQuery(function ($) {
  if (!window.mainwpSegmentModalUiHandle) {
    window.mainwpSegmentModalUiHandle = (function () {
      let _instance = {
        loadingStatus: function () {
          $('#mainwp-common-filter-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Loading segments. Please wait...')).show();
        },
        savingStatus: function () {
          $('#mainwp-common-filter-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Saving segment. Please wait...')).show();
        },
        deletingStatus: function () {
          $('#mainwp-common-filter-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Deleting segment. Please wait...')).show();

        },
        showStatus: function (status, addClass) {
          if (addClass) {
            $('#mainwp-common-filter-edit-segment-status').addClass(addClass);
          }
          $('#mainwp-common-filter-edit-segment-status').html(status).show();
        },
        hideSegmentStatus: function () {
          $('#mainwp-common-filter-edit-segment-status').removeClass('red green').hide();
        },
        showSegment: function (btnObj) {
          jQuery('#mainwp-common-filter-segment-modal > div.header').html(__('Save Segment'));
          jQuery('#mainwp-common-filter-segment-edit-fields').show();
          jQuery('#mainwp-common-filter-edit-segment-save').show();
          jQuery('#mainwp-common-filter-segment-select-fields').hide();
          jQuery('#mainwp-common-filter-select-segment-choose-button').hide();
          jQuery('#mainwp-common-filter-select-segment-delete-button').hide();
          jQuery('#mainwp-common-filter-edit-segment-name').val(jQuery(btnObj).attr('selected-segment-name'));
          this.hideSegmentStatus();
          mainwp_common_filter_show_segments_modal();
        },
        loadSegment: function (loadCallback) {
          jQuery('#mainwp-common-filter-segment-edit-fields').hide();
          jQuery('#mainwp-common-filter-edit-segment-save').hide();
          jQuery('#mainwp-common-filter-segment-modal > div.header').html(__('Load Segment'));
          jQuery('#mainwp-common-filter-segment-select-fields').show();
          jQuery('#mainwp-common-filter-select-segment-choose-button').show();
          jQuery('#mainwp-common-filter-select-segment-delete-button').show();
          this.hideSegmentStatus();
          mainwp_common_filter_show_segments_modal(loadCallback);
        },
        showResults: function (result) {
          jQuery('#mainwp-common-filter-edit-segment-status').hide();
          jQuery('#mainwp-common-filter-segments-lists-wrapper').html(result);
          jQuery('#mainwp-common-filter-segments-lists-wrapper .ui.dropdown').dropdown();
          jQuery('#mainwp-common-filter-segment-select-fields').show();
        }
      }
      return _instance;
    })();
  }

  if (!window.mainwpUIHandleWidgetsLayout) {
    window.mainwpUIHandleWidgetsLayout = (function () {
      const statusElemId = '#mainwp-common-edit-widgets-layout-status';
      let _instance = {
        loadingStatus: function () {
          $(statusElemId).html('<i class="notched circle loading icon"></i> ' + __('Loading layouts. Please wait...')).show();
        },
        savingStatus: function () {
          $(statusElemId).html('<i class="notched circle loading icon"></i> ' + __('Saving layout. Please wait...')).show();
        },
        deletingStatus: function () {
          $(statusElemId).html('<i class="notched circle loading icon"></i> ' + __('Deleting layout. Please wait...')).show();

        },
        showWorkingStatus: function (status, addClass) {
          if (addClass) {
            $(statusElemId).removeClass('red green yellow');
            $(statusElemId).addClass(addClass);
          }
          $(statusElemId).html(status).show();
        },
        hideWorkingStatus: function () {
          $(statusElemId).removeClass('red green').hide();
        },
        showLayout: function (showBtn) {
          jQuery('#mainwp-common-edit-widgets-layout-modal > div.header').html(__('Save Layout'));
          jQuery('#mainwp-common-edit-widgets-layout-edit-fields').show();
          jQuery('#mainwp-common-edit-widgets-layout-save-button').show();
          jQuery('#mainwp-common-layout-widgets-select-fields').hide();
          jQuery('#mainwp-common-edit-widgets-select-layout-button').hide();
          jQuery('#mainwp-common-edit-widgets-layout-delete-button').hide();

          if (jQuery('#mainwp-widgets-selected-layout').length > 0) {
            let name = jQuery('#mainwp-widgets-selected-layout').attr('layout-name');
            let lay_idx = jQuery('#mainwp-widgets-selected-layout').attr('layout-idx');
            jQuery('#mainwp-common-edit-widgets-layout-name').val(name);
            jQuery(showBtn).attr('selected-layout-id', lay_idx);
          }
          this.hideWorkingStatus();
          mainwp_common_show_edit_widgets_layout_modal();
        },
        loadSegment: function (loadCallback) {
          jQuery('#mainwp-common-edit-widgets-layout-edit-fields').hide();
          jQuery('#mainwp-common-edit-widgets-layout-save-button').hide();
          jQuery('#mainwp-common-edit-widgets-layout-modal > div.header').html(__('Load Layout'));
          jQuery('#mainwp-common-layout-widgets-select-fields').show();
          jQuery('#mainwp-common-edit-widgets-select-layout-button').show();
          jQuery('#mainwp-common-edit-widgets-layout-delete-button').show();
          this.hideWorkingStatus();
          mainwp_common_show_edit_widgets_layout_modal(loadCallback);
        },
        showResults: function (result) {
          jQuery(statusElemId).hide();
          jQuery('#mainwp-common-edit-widgets-layout-lists-wrapper').html(result);
          jQuery('#mainwp-common-edit-widgets-layout-lists-wrapper .ui.dropdown').dropdown();
          jQuery('#mainwp-common-layout-widgets-select-fields').show();
        }
      }
      return _instance;
    })();
  }

});


let mainwp_common_show_edit_widgets_layout_modal = function (loadCallback) {
  jQuery('#mainwp-common-edit-widgets-layout-modal').modal({
    allowMultiple: false,
    onShow: function () {
      if (typeof loadCallback == 'function') {
        loadCallback();
      }
    }
  }).modal('show');
};

let mainwp_common_ui_widgets_save_layout = function (itemClass, data, callBack) {

  let orders = [];
  let wgIds = [];

  const $items = document.querySelectorAll(itemClass);

  if ($items.length == 0) {
    return;
  }

  $items.forEach(function (item) {
    let obj = {};
    obj["x"] = item.getAttribute('gs-x');
    obj["y"] = item.getAttribute('gs-y');
    obj["w"] = item.getAttribute('gs-w');
    obj["h"] = item.getAttribute('gs-h');
    orders.push(obj);
    wgIds.push(item.id);
  });

  data.action = 'mainwp_ui_save_widgets_layout';
  data.order = orders;
  data.wgids = wgIds;

  jQuery.post(ajaxurl, mainwp_secure_data(data), function (res) {
    if (typeof callBack !== "undefined" && false !== callBack) {
      callBack(res);
    }
  }, 'json');
}

let mainwp_overview_gridstack_save_layout = function( item_id ){

    let orders = [];
    let wgIds = [];

    const $items = document.querySelectorAll('.grid-stack-item');

    $items.forEach(function(item) {
        let obj = {};
        obj["x"] = item.getAttribute('gs-x');
        obj["y"] = item.getAttribute('gs-y');
        obj["w"] = item.getAttribute('gs-w');
        obj["h"] = item.getAttribute('gs-h');
        orders.push(obj);
        wgIds.push(item.id);
    });

    console.log(orders);
    console.log(wgIds);

    let postVars = {
        action:'mainwp_widgets_order',
        page: page_sortablewidgets,
        order:JSON.stringify(orders),
        wgids: JSON.stringify(wgIds),
        item_id: item_id
    };
    jQuery.post( ajaxurl, mainwp_secure_data( postVars ), function () {
    } );
}

window.mainwp_init_ui_calendar = ($selectors) => {
  jQuery($selectors).calendar({
    type: 'date',
    monthFirst: false,
    today: true,
    touchReadonly: false,
    formatter: {
      date: function (date) {
        if (!date) return '';
        let day = date.getDate();
        let month = date.getMonth() + 1;
        let year = date.getFullYear();

        if (month < 10) {
          month = '0' + month;
        }
        if (day < 10) {
          day = '0' + day;
        }
        return year + '-' + month + '-' + day;
      }
    }
  });

}


jQuery(document).ready(function () {
  jQuery('.dt-scroll-head').css({
    'overflow-x': 'auto'
  }).on('scroll', function () {
    let scrollBody = jQuery(this).parent().find('.dt-scroll-body').get(0);
    scrollBody.scrollLeft = this.scrollLeft;
    jQuery(scrollBody).trigger('scroll');
  });
});

// Function to check valid email using regular expression.
const mainwp_validate_email = function (email) {
  const re = /^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,255}\.[A-Za-z]{2,}$/;
  return re.test(email);
}


jQuery(function ($) {

  $(document).on('click', '#delete_uptime_monitor_btn', function () {
    let is_sub = $('#monitor_edit_is_sub_url')?.val();

    let confirmation = __("Are you sure you want to delete this uptime monitor?");

    if (is_sub) {
      confirmation = __("Are you sure you want to delete this uptime sub-page monitor?");
    }

    mainwp_confirm(confirmation, () => {
      let wpid = $('#mainwp_edit_monitor_site_id').val();
      let moid = $('#mainwp_edit_monitor_id').val();

      mainwp_uptime_monitoring_remove(wpid, moid);
    }, false, false, true);
  });

  let mainwp_uptime_monitoring_remove = function (wpid, moid) {

    feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Removing Uptime Monitor...'), 'green');

    let data = mainwp_secure_data({
      action: 'mainwp_uptime_monitoring_remove_monitor',
      wpid: wpid,
      moid: moid
    });

    jQuery.post(ajaxurl, data, function (response) {
      if (response?.success) {
        feedback('mainwp-message-zone', __('Monitor have been removed.'), 'green');
        setTimeout(function () {
          window.location = 'admin.php?page=managesites&id=' + wpid;
        }, 2000);

      } else if (response?.error) {
        feedback('mainwp-message-zone', response.error, 'red');
      } else {
        feedback('mainwp-message-zone', __('Undefined error. Please try again.'), 'red');
      }
    }, 'json');
    return false;
  };

  $(document).on('click', '#increase-connection-security-btn', function () {
    feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Encryption in progress! Securing your OpenSSL private keys, this may take a few moments. Please wait until completed.'), 'green');
    let data = mainwp_secure_data({
      action: 'mainwp_increase_connection_security',
    });
    jQuery.post(ajaxurl, data, function (response) {
      if (response?.success) {
        setTimeout(function () {
          window.location.href = location.href
        }, 2000);

      } else if (response?.error) {
        feedback('mainwp-message-zone', response.error, 'red');
      } else {
        feedback('mainwp-message-zone', __('Undefined error. Please try again.'), 'red');
      }
    }, 'json');
    return false;
  });

});


jQuery(document).on('click', '#mainwp-sites-changes-filter-toggle-button', function () {
    jQuery('#mainwp-module-log-filters-row').toggle(300);
    return false;
});