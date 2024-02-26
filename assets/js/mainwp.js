/* eslint complexity: ["error", 100] */
// current complexity is the only way to achieve desired results, pull request solutions appreciated.

jQuery(document).ready(function ($) {

  // review for new UI update
  jQuery(document).on('click', '#mainwp-category-add-submit', function () {
    var newCat = jQuery('#newcategory').val();
    if (jQuery('#categorychecklist').find('option[value="' + encodeURIComponent(newCat) + '"]').length > 0)
      return;
    jQuery('#categorychecklist').append('<option value="' + encodeURIComponent(newCat) + '">' + newCat + '</option>');
    jQuery('#category-adder').addClass('wp-hidden-children');
    jQuery('#newcategory').val('');
  });

  // Show/Hide new category field and button
  jQuery('#category-add-toggle').on('click', function () {
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
    var side_id = jQuery(this).attr('site-id');
    var confirmation = "Are you sure you want to remove this site from your MainWP Dashboard?";
    mainwp_confirm(confirmation, function () {

      feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Removing the site. Please wait...', 'mainwp'), '');

      var data = mainwp_secure_data({
        action: 'mainwp_removesite',
        id: side_id
      });

      jQuery.post(ajaxurl, data, function (response) {

        var error = false;

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

        if (error == false) {
          setTimeout(function () {
            window.location = 'admin.php?page=managesites';
          }, 3000);
        }

      }, 'json');
    }, false, false, false, 'REMOVE');

    return false;
  });

});

/**
 * Global
 */
jQuery(document).ready(function () {
  jQuery('.mainwp-row').on({
    mouseenter: function () {
      rowMouseEnter(this);
    },
    mouseleave: function () {
      rowMouseLeave(this);
    }
  });
});
rowMouseEnter = function (elem) {
  if (!jQuery(elem).children('.mainwp-row-actions-working').is(":visible"))
    jQuery(elem).children('.mainwp-row-actions').show();
};
rowMouseLeave = function (elem) {
  if (jQuery(elem).children('.mainwp-row-actions').is(":visible"))
    jQuery(elem).children('.mainwp-row-actions').hide();
};


mainwp_sidebar_position_onchange = function (me) {
  jQuery(me).closest("form").submit();
}

/**
 * Recent posts
 */
jQuery(document).ready(function () {
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
postAction = function (elem, what) {
  var rowElement = jQuery(elem).closest('.grid');
  var postId = rowElement.children('.postId').val();
  var websiteId = rowElement.children('.websiteId').val();

  var data = mainwp_secure_data({
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
      rowElement.html('<div class="sixteen wide column"><i class="times circle red icon"></i> ' + response.error + '</div>');
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


mainwp_post_posting_start_next = function (start) {
  if (typeof start !== "undefined" && start) {
    bulkInstallDone = 0;
    bulkInstallCurrentThreads = 0;
    bulkInstallTotal = jQuery('.site-bulk-posting[status="queue"]').length;
  }
  while ((siteToPosting = jQuery('.site-bulk-posting[status="queue"]:first')) && (siteToPosting.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    mainwp_post_posting_start_specific(siteToPosting);
  }
};

mainwp_post_posting_start_specific = function (siteToPosting) {
  siteToPosting.attr('status', 'progress');
  bulkInstallDone++;
  bulkInstallCurrentThreads++;
  var data = mainwp_secure_data({
    action: 'mainwp_post_postingbulk',
    post_id: jQuery('#bulk_posting_id').val(),
    site_id: jQuery(siteToPosting).attr('site-id'),
    count: bulkInstallDone,
    total: bulkInstallTotal,
    delete_bulkpost: (bulkInstallDone == bulkInstallTotal) ? true : false
  });
  siteToPosting.find('.progress').html('<i class="notched circle loading icon"></i>');
  jQuery.post(ajaxurl, data, function (response) {
    bulkInstallCurrentThreads--;
    if (response && response.result) {
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
jQuery(document).ready(function () {
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

pluginAction = function (elem, what) {
  var rowElement = jQuery(elem).parent().parent();
  var plugin = rowElement.children('.pluginSlug').val();
  var websiteId = rowElement.children('.websiteId').val();

  var data = mainwp_secure_data({
    action: 'mainwp_widget_plugin_' + what,
    plugin: plugin,
    websiteId: websiteId
  });
  rowElement.children().hide();
  rowElement.children('.mainwp-row-actions-working').show();
  jQuery.post(ajaxurl, data, function (response) {
    if (response && response.error) {
      rowElement.children().show();
      rowElement.html(response.error);
    } else if (response && response.result) {
      rowElement.children().show();
      rowElement.html(response.result);
    } else {
      rowElement.children('.mainwp-row-actions-working').hide();
    }
  }, 'json');

  return false;
};

/**
 * Themes Widget
 */
jQuery(document).ready(function () {
  jQuery(document).on('click', '.mainwp-theme-activate', function () {
    themeAction(jQuery(this), 'activate');
    return false;
  });
  jQuery(document).on('click', '.mainwp-theme-delete', function () {
    themeAction(jQuery(this), 'delete');
    return false;
  });
});

themeAction = function (elem, what) {
  var rowElement = jQuery(elem).parent().parent();
  var theme = rowElement.children('.themeSlug').val();
  var websiteId = rowElement.children('.websiteId').val();

  var data = mainwp_secure_data({
    action: 'mainwp_widget_theme_' + what,
    theme: theme,
    websiteId: websiteId
  });
  rowElement.children().hide();
  rowElement.children('.mainwp-row-actions-working').show();
  jQuery.post(ajaxurl, data, function (response) {
    if (response && response.error) {
      rowElement.children().show();
      rowElement.html(response.error);
    } else if (response && response.result) {
      rowElement.children().show();
      rowElement.html(response.result);
    } else {
      rowElement.children('.mainwp-row-actions-working').hide();
    }
  }, 'json');

  return false;
};

// offsetRelative (or, if you prefer, positionRelative)
(function ($) {
  $.fn.offsetRelative = function (top) {
    var $this = $(this);
    var $parent = $this.offsetParent();
    var offset = $this.position();
    if (!top)
      return offset; // Didn't pass a 'top' element
    else if ($parent.get(0).tagName == "BODY")
      return offset; // Reached top of document
    else if ($(top, $parent).length)
      return offset; // Parent element contains the 'top' element we want the offset to be relative to
    else if ($parent[0] == $(top)[0])
      return offset; // Reached the 'top' element we want the offset to be relative to
    else { // Get parent's relative offset
      var parent_offset = $parent.offsetRelative(top);
      offset.top += parent_offset.top;
      offset.left += parent_offset.left;
      return offset;
    }
  };
  $.fn.positionRelative = function (top) {
    return $(this).offsetRelative(top);
  };
}(jQuery));

var hidingSubMenuTimers = {};
jQuery(document).ready(function () {
  // jQuery('span[id^=mainwp]').each(function () {
  //   jQuery(this).parent().parent().on("mouseenter", function () {
  //     var spanEl = jQuery(this).find('span[id^=mainwp]');
  //     var spanId = /^mainwp-(.*)$/.exec(spanEl.attr('id'));
  //     if (spanId) {
  //       if (hidingSubMenuTimers[spanId[1]]) {
  //         clearTimeout(hidingSubMenuTimers[spanId[1]]);
  //       }
  //       var currentMenu = jQuery('#menu-mainwp-' + spanId[1]);
  //       var offsetVal = jQuery(this).offset();
  //       currentMenu.css('left', offsetVal.left + jQuery(this).outerWidth() - 30);

  //       currentMenu.css('top', offsetVal.top - 15 - jQuery(this).outerHeight()); // + tmp);
  //       subMenuIn(spanId[1]);
  //     }
  //   }).on("mouseleave", function () {
  //     var spanEl = jQuery(this).find('span[id^=mainwp]');
  //     var spanId = /^mainwp-(.*)$/.exec(spanEl.attr('id'));
  //     if (spanId) {
  //       hidingSubMenuTimers[spanId[1]] = setTimeout(function (span) {
  //         return function () {
  //           subMenuOut(span);
  //         };
  //       }(spanId[1]), 30);
  //     }
  //   });
  // });
  jQuery('.mainwp-submenu-wrapper').on({
    mouseenter: function () {
      var spanId = /^menu-mainwp-(.*)$/.exec(jQuery(this).attr('id'));
      if (spanId) {
        if (hidingSubMenuTimers[spanId[1]]) {
          clearTimeout(hidingSubMenuTimers[spanId[1]]);
        }
      }
    },
    mouseleave: function () {
      var spanId = /^menu-mainwp-(.*)$/.exec(jQuery(this).attr('id'));
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
subMenuIn = function (subName) {
  jQuery('#menu-mainwp-' + subName).show();
  jQuery('#mainwp-' + subName).parent().parent().addClass('hoverli');
  jQuery('#mainwp-' + subName).parent().parent().css('background-color', '#EAF2FA');
  jQuery('#mainwp-' + subName).css('color', '#333');
};
subMenuOut = function (subName) {
  jQuery('#menu-mainwp-' + subName).hide();
  jQuery('#mainwp-' + subName).parent().parent().css('background-color', '');
  jQuery('#mainwp-' + subName).parent().parent().removeClass('hoverli');
  jQuery('#mainwp-' + subName).css('color', '');
};


function shake_element(select) {
  var pos = jQuery(select).position();
  var type = jQuery(select).css('position');

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

  var shake = [[0, 5, 60], [0, 0, 60], [0, -5, 60], [0, 0, 60], [0, 2, 30], [0, 0, 30], [0, -2, 30], [0, 0, 30]];

  for (s = 0; s < shake.length; s++) {
    jQuery(select).animate({
      top: pos.top + shake[s][0],
      left: pos.left + shake[s][1]
    }, shake[s][2], 'linear');
  }
}


/**
 * Required
 */
feedback = function (id, text, type, append) {
  if (append == true) {
    var currentHtml = jQuery('#' + id).html();
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

scrollElementTop = function (id) {
  var scrolltop = jQuery(window).scrollTop();
  if (jQuery('#' + id).length == 0) {
    return;
  }
  var off = jQuery('#' + id).offset();
  if (scrolltop > off.top - 40)
    jQuery('html, body').animate({
      scrollTop: off.top - 40
    }, 1000, function () {
      shake_element('#' + id)
    });
  else
    shake_element('#' + id); // shake the error message to get attention :)
}

jQuery(document).ready(function () {
  jQuery('div.mainwp-hidden').parent().parent().css("display", "none");
});

/**
 * Security Issues
 */

var securityIssues_fixes = ['listing', 'wp_version', 'rsd', 'wlw', 'core_updates', 'plugin_updates', 'theme_updates', 'db_reporting', 'php_reporting', 'versions', 'registered_versions', 'admin', 'readme', 'wp_uptodate', 'phpversion_matched', 'sslprotocol', 'debug_disabled'];
jQuery(document).ready(function () {
  var securityIssueSite = jQuery('#securityIssueSite');
  if ((securityIssueSite.val() != null) && (securityIssueSite.val() != "")) {
    jQuery(document).on('click', '#securityIssues_fixAll', function () {
      securityIssues_fix('all');
    });

    jQuery(document).on('click', '#securityIssues_refresh', function () {
      for (var i = 0; i < securityIssues_fixes.length; i++) {
        var securityIssueCurrentIssue = jQuery('#' + securityIssues_fixes[i] + '_fix');
        if (securityIssueCurrentIssue) {
          securityIssueCurrentIssue.hide();
        }
        jQuery('#' + securityIssues_fixes[i] + '_extra').hide();
        jQuery('#' + securityIssues_fixes[i] + '_ok').hide();
        jQuery('#' + securityIssues_fixes[i] + '_nok').hide();
        jQuery('#' + securityIssues_fixes[i] + '_loading').show();
      }
      securityIssues_request(jQuery('#securityIssueSite').val());
    });

    for (var i = 0; i < securityIssues_fixes.length; i++) {
      jQuery('#' + securityIssues_fixes[i] + '_fix').on('click', function (what) {
        return function () {
          securityIssues_fix(what);
          return false;
        }
      }(securityIssues_fixes[i]));

      jQuery('#' + securityIssues_fixes[i] + '_unfix').on('click', function (what) {
        return function () {
          securityIssues_unfix(what);
          return false;
        }
      }(securityIssues_fixes[i]));
    }
    securityIssues_request(securityIssueSite.val());
  }
});
securityIssues_fix = function (feature) {
  if (feature == 'all') {
    for (var i = 0; i < securityIssues_fixes.length; i++) {
      if (jQuery('#' + securityIssues_fixes[i] + '_nok').css('display') != 'none') {
        if (jQuery('#' + securityIssues_fixes[i] + '_fix')) {
          jQuery('#' + securityIssues_fixes[i] + '_fix').hide();
        }
        jQuery('#' + securityIssues_fixes[i] + '_extra').hide();
        jQuery('#' + securityIssues_fixes[i] + '_ok').hide();
        jQuery('#' + securityIssues_fixes[i] + '_nok').hide();
        jQuery('#' + securityIssues_fixes[i] + '_loading').show();
      }
    }
  } else {
    if (jQuery('#' + feature + '_fix')) {
      jQuery('#' + feature + '_fix').hide();
    }
    jQuery('#' + feature + '_extra').hide();
    jQuery('#' + feature + '_ok').hide();
    jQuery('#' + feature + '_nok').hide();
    jQuery('#' + feature + '_loading').show();
  }

  var data = mainwp_secure_data({
    action: 'mainwp_security_issues_fix',
    feature: feature,
    id: jQuery('#securityIssueSite').val()
  });

  jQuery.post(ajaxurl, data, function (response) {
    securityIssues_handle(response);
  }, 'json');
};

// Securtiy issues Widget

// Show/Hide the list
jQuery(document).on('click', '#show-security-issues-widget-list', function () {
  jQuery('#mainwp-security-issues-widget-list').toggle();
  return false;
});

// Fix all sites all security issues
jQuery(document).on('click', '.fix-all-security-issues', function () {

  jQuery('#mainwp-secuirty-issues-loader').show();

  jQuery('#mainwp-security-issues-widget-list').show();
  bulkInstallTotal = jQuery('#mainwp-security-issues-widget-list .item[status="queue"]').length;
  jQuery('.fix-all-site-security-issues').addClass('disabled');
  jQuery('.unfix-all-site-security-issues').addClass('disabled');
  mainwp_fix_all_security_issues_start_next();
});

mainwp_fix_all_security_issues_start_next = function () {
  while ((siteToFix = jQuery('#mainwp-security-issues-widget-list .item[status="queue"]:first')) && (siteToFix.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    mainwp_fix_all_security_issues_specific(siteToFix);
  }
}

mainwp_fix_all_security_issues_specific = function (siteToFix) {

  bulkInstallCurrentThreads++;

  siteToFix.attr('status', 'progress');

  var data = mainwp_secure_data({
    action: 'mainwp_security_issues_fix',
    feature: 'all',
    id: siteToFix.attr('siteid')
  });

  var el = siteToFix.find('.fix-all-site-security-issues');
  el.hide();

  jQuery.post(ajaxurl, data, function () {
    return function () {
      siteToFix.attr('status', 'done');
      el.show();
      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      if (bulkInstallDone != 0 && (bulkInstallTotal == 1 || (bulkInstallDone >= bulkInstallTotal))) {
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

mainwp_fix_all_security_issues = function (siteId, refresh) {
  var data = mainwp_secure_data({
    action: 'mainwp_security_issues_fix',
    feature: 'all',
    id: siteId
  });

  var el = jQuery('#mainwp-security-issues-widget-list .item[siteid="' + siteId + '"] .fix-all-site-security-issues');

  el.hide();

  jQuery('.fix-all-site-security-issues').addClass('disabled');
  jQuery('.unfix-all-site-security-issues').addClass('disabled');

  jQuery.post(ajaxurl, data, function (pRefresh) {
    return function () {
      el.show();
      if (pRefresh) {
        window.location.href = location.href;
      }
    }
  }(refresh, el), 'json');
};

jQuery(document).on('click', '.unfix-all-site-security-issues', function () {

  jQuery('#mainwp-secuirty-issues-loader').show();

  var data = mainwp_secure_data({
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
securityIssues_unfix = function (feature) {
  if (jQuery('#' + feature + '_unfix')) {
    jQuery('#' + feature + '_unfix').hide();
  }
  jQuery('#' + feature + '_extra').hide();
  jQuery('#' + feature + '_ok').hide();
  jQuery('#' + feature + '_nok').hide();
  jQuery('#' + feature + '_loading').show();

  var data = mainwp_secure_data({
    action: 'mainwp_security_issues_unfix',
    feature: feature,
    id: jQuery('#securityIssueSite').val()
  });
  jQuery.post(ajaxurl, data, function (response) {
    securityIssues_handle(response);
  }, 'json');
};
securityIssues_request = function (websiteId) {
  var data = mainwp_secure_data({
    action: 'mainwp_security_issues_request',
    id: websiteId
  });
  jQuery.post(ajaxurl, data, function (response) {
    securityIssues_handle(response);
  }, 'json');
};
securityIssues_handle = function (response) {
  var result = '';
  if (response.error) {
    result = getErrorMessage(response.error);
  } else {
    try {
      var res = response.result;
      for (var issue in res) {
        if (jQuery('#' + issue + '_loading')) {
          jQuery('#' + issue + '_loading').hide();
          if (res[issue] == 'Y') {
            jQuery('#' + issue + '_extra').hide();
            jQuery('#' + issue + '_nok').hide();
            if (jQuery('#' + issue + '_fix')) {
              jQuery('#' + issue + '_fix').hide();
            }
            if (jQuery('#' + issue + '_unfix')) {
              jQuery('#' + issue + '_unfix').show();
            }
            jQuery('#' + issue + '_ok').show();
            jQuery('#' + issue + '-status-ok').show();
            jQuery('#' + issue + '-status-nok').hide();
            if (issue == 'readme') {
              jQuery('#readme-wpe-nok').hide();
            }
          } else {
            jQuery('#' + issue + '_extra').hide();
            jQuery('#' + issue + '_ok').hide();
            jQuery('#' + issue + '_nok').show();
            if (jQuery('#' + issue + '_fix')) {
              jQuery('#' + issue + '_fix').show();
            }
            if (jQuery('#' + issue + '_unfix')) {
              jQuery('#' + issue + '_unfix').hide();
            }

            if (res[issue] != 'N') {
              jQuery('#' + issue + '_extra').html(res[issue]);
              jQuery('#' + issue + '_extra').show();
            }
          }
        }
      }

      var unSetFeatures = jQuery('#mainwp-security-issues-table').attr('un-set');
      if (unSetFeatures != '') {
        unSetFeatures = unSetFeatures.split(',');
        if (unSetFeatures.length > 0) {
          for (var ival in unSetFeatures) {
            issue = unSetFeatures[ival];
            console.log(res[issue]);
            if (res[issue] == 'Y') {
              securityIssues_unfix(issue);
            }
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

updatesoverview_bulk_check_abandoned = function (which) {
  if ('plugin' == which) {
    confirmMsg = __("You are about to check abandoned plugins on the sites?");
  } else {
    confirmMsg = __("You are about to check abandoned themes on the sites?");
  }
  mainwp_confirm(confirmMsg, _callback = function () { mainwp_managesites_bulk_check_abandoned('all', which); });
}

mainwp_managesites_bulk_check_abandoned = function (siteIds, which) {
  var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
    return jQuery(el).val();
  });

  if ('all' == siteIds) {
    siteIds = allWebsiteIds;
  }

  var selectedIds = [], excludeIds = [];
  if (siteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, siteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (var i = 0; i < excludeIds.length; i++) {
      dashboard_update_site_hide(excludeIds[i]);
    }
    allWebsiteIds = selectedIds;
    //jQuery('#refresh-status-total').text(allWebsiteIds.length);
  }

  var nrOfWebsites = allWebsiteIds.length;

  if (nrOfWebsites == 0)
    return false;

  var siteNames = {};

  for (var i = 0; i < allWebsiteIds.length; i++) {
    dashboard_update_site_status(allWebsiteIds[i], '<i class="clock outline icon"></i>');
    siteNames[allWebsiteIds[i]] = jQuery('.sync-site-status[siteid="' + allWebsiteIds[i] + '"]').attr('niceurl');
  }
  var initData = {
    progressMax: nrOfWebsites,
    title: 'Check abandoned ' + ('plugin' == which ? 'plugins' : 'themes'),
    statusText: __('started'),
    callback: function () {
      bulkManageSitesTaskRunning = false;
      window.location.href = location.href;
    }
  };
  mainwpPopup('#mainwp-sync-sites-modal').init(initData);

  mainwp_managesites_check_abandoned_all_int(allWebsiteIds, which);
};

mainwp_managesites_check_abandoned_all_int = function (websiteIds, which) {
  websitesToUpgrade = websiteIds;
  currentWebsite = 0;
  websitesDone = 0;
  websitesTotal = websitesLeft = websitesToUpgrade.length;

  bulkTaskRunning = true;
  mainwp_managesites_check_abandoned_all_loop_next(which);
};

mainwp_managesites_check_abandoned_all_loop_next = function (which) {
  while (bulkTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
    mainwp_managesites_check_abandoned_all_upgrade_next(which);
  }
};
mainwp_managesites_check_abandoned_all_upgrade_next = function (which) {
  currentThreads++;
  websitesLeft--;

  var websiteId = websitesToUpgrade[currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');

  mainwp_managesites_check_abandoned_int(websiteId, which);
};

mainwp_managesites_check_abandoned_int = function (siteid, which) {

  var data = mainwp_secure_data({
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
        currentThreads--;
        websitesDone++;
        mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);
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
  return false;
};

/**
 * MainWP UI.
 */
jQuery(function () {
  jQuery('#reset-overview-settings').on('click', function () {
    mainwp_confirm(__('Are you sure.'), function () {
      var which_set = jQuery('input[name=reset_overview_which_settings]').val();
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

jQuery(document).ready(function () {
  jQuery('#mainwp-sync-sites').on('click', function () {
    mainwp_sync_sites_data();
  });

  // to compatible with extensions
  jQuery('#dashboard_refresh').on('click', function () {
    mainwp_sync_sites_data();
  });
  jQuery('.mainwp-sync-this-site').on('click', function () {
    var syncSiteIds = [];
    syncSiteIds.push(jQuery(this).attr('site-id'));
    mainwp_sync_sites_data(syncSiteIds);
  });
});

mainwp_sync_sites_data = function (syncSiteIds, pAction) {
  var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
    return jQuery(el).val();
  });
  var globalSync = true;
  var selectedIds = [], excludeIds = [];
  if (syncSiteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, syncSiteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (var i = 0; i < excludeIds.length; i++) {
      dashboard_update_site_hide(excludeIds[i]);
    }
    allWebsiteIds = selectedIds;
    globalSync = false;
  }

  for (var i = 0; i < allWebsiteIds.length; i++) {
    dashboard_update_site_status(allWebsiteIds[i], '<span data-inverted="" data-position="left center" data-tooltip="' + __('Pending', 'mainwp') + '"><i class="clock outline icon"></i></span>');
  }

  var nrOfWebsites = allWebsiteIds.length;

  mainwpPopup('#mainwp-sync-sites-modal').init({
    title: (pAction == 'checknow' ? __('Check Now') : __('Data Synchronization')),
    progressMax: nrOfWebsites,
    statusText: (pAction == 'checknow' ? 'checked' : 'synced'),
    callback: function () {
      bulkTaskRunning = false;
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
      var data = {
        action: 'mainwp_status_saving',
        status: 'last_sync_sites',
        isGlobalSync: globalSync ? 1 : 0
      };
      jQuery.post(ajaxurl, mainwp_secure_data(data), function () {

      });
    }
  }
};

var websitesToUpdate = [];
var websitesTotal = 0;
var websitesLeft = 0;
var websitesDone = 0;
var currentWebsite = 0;
var bulkTaskRunning = false;
var currentThreads = 0;
var maxThreads = mainwpParams['maximumSyncRequests'] == undefined ? 8 : mainwpParams['maximumSyncRequests'];
var globalSync = true;

dashboard_update = function (websiteIds, isGlobalSync, pAction) {
  websitesToUpdate = websiteIds;
  currentWebsite = 0;
  websitesDone = 0;
  websitesTotal = websitesLeft = websitesToUpdate.length;
  globalSync = isGlobalSync;

  bulkTaskRunning = true;

  if (websitesTotal == 0) {
    dashboard_update_done(pAction);
  } else {
    dashboard_loop_next(pAction);
  }
};

dashboard_update_site_status = function (siteId, newStatus, isSuccess) {
  jQuery('.sync-site-status[siteid="' + siteId + '"]').html(newStatus);
  // Move successfully synced site to the bottom of the sync list
  if (typeof isSuccess !== 'undefined' && isSuccess) {
    var row = jQuery('.sync-site-status[siteid="' + siteId + '"]').closest('.item');
    jQuery(row).insertAfter(jQuery("#sync-sites-status .item").not('.disconnected-site').last());
  }
};

dashboard_update_site_hide = function (siteId) {
  jQuery('.sync-site-status[siteid="' + siteId + '"]').closest('.item').hide();
};

dashboard_loop_next = function (pAction) {
  while (bulkTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
    dashboard_update_next(pAction);
  }
};

dashboard_update_done = function (pAction) {
  currentThreads--;
  if (!bulkTaskRunning)
    return;
  websitesDone++;
  if (websitesDone > websitesTotal)
    websitesDone = websitesTotal;

  mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);

  if (websitesDone == websitesTotal) {
    var successSites = jQuery('#mainwp-sync-sites-modal .check.green.icon').length;
    if (websitesDone == successSites) {
      bulkTaskRunning = false;
      setTimeout(function () {
        mainwpPopup('#mainwp-sync-sites-modal').close(true);
      }, 3000);
    } else {
      bulkTaskRunning = false;
    }
    return;
  }

  dashboard_loop_next(pAction);
};

dashboard_update_next = function (pAction) {
  currentThreads++;
  websitesLeft--;
  var websiteId = websitesToUpdate[currentWebsite++];
  dashboard_update_site_status(websiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Syncing...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  var data = mainwp_secure_data({
    action: ('checknow' == pAction ? 'mainwp_checksites' : 'mainwp_syncsites'),
    wp_id: websiteId,
    isGlobalSync: globalSync
  });



  dashboard_update_next_int(websiteId, data, 0, pAction);
};

dashboard_update_next_int = function (websiteId, data, errors, action) {
  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pWebsiteId, pAction) {
      return function (response) {
        if (response.error) {
          var extErr = response.error;
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
 * Delete nonmainwp actions.
 */
mainwp_delete_nonmainwp_data_start = function (syncSiteIds) {
  var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
    return jQuery(el).val();
  });
  var selectedIds = [], excludeIds = [];
  if (syncSiteIds instanceof Array) {
    jQuery.grep(allWebsiteIds, function (el) {
      if (jQuery.inArray(el, syncSiteIds) !== -1) {
        selectedIds.push(el);
      } else {
        excludeIds.push(el);
      }
    });
    for (var i = 0; i < excludeIds.length; i++) {
      dashboard_update_site_hide(excludeIds[i]);
    }
    allWebsiteIds = selectedIds;
  }

  for (var i = 0; i < allWebsiteIds.length; i++) {
    dashboard_update_site_status(allWebsiteIds[i], '<span data-inverted="" data-position="left center" data-tooltip="' + __('Pending', 'mainwp') + '"><i class="clock outline icon"></i></span>');
  }

  var nrOfWebsites = allWebsiteIds.length;

  mainwpPopup('#mainwp-sync-sites-modal').init({
    title: __('Delete Non-MainWP Changes'),
    progressMax: nrOfWebsites,
    statusText: 'deleted',
    callback: function () {
      bulkTaskRunning = false;
      history.pushState("", document.title, window.location.pathname + window.location.search); // to fix issue for url with hash
      window.location.href = location.href;
    }
  });
  mainwp_delete_nonmainwp_data_start_next(allWebsiteIds);
};


mainwp_delete_nonmainwp_data_start_next = function (websiteIds) {
  websitesToUpdate = websiteIds;
  currentWebsite = 0;
  websitesDone = 0;
  websitesTotal = websitesLeft = websitesToUpdate.length;

  bulkTaskRunning = true;

  if (websitesTotal == 0) {
    mainwp_delete_nonmainwp_data_done();
  } else {
    mainwp_delete_nonmainwp_data_loop_next();
  }
};


mainwp_delete_nonmainwp_data_loop_next = function () {
  while (bulkTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
    mainwp_delete_nonmainwp_data_next();
  }
};

mainwp_delete_nonmainwp_data_next = function () {
  currentThreads++;
  websitesLeft--;
  var websiteId = websitesToUpdate[currentWebsite++];
  dashboard_update_site_status(websiteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Deleting...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  var data = mainwp_secure_data({
    action: 'mainwp_delete_non_mainwp_actions',
    wp_id: websiteId,
  });
  mainwp_delete_nonmainwp_data_next_int(websiteId, data, 0);
};

mainwp_delete_nonmainwp_data_next_int = function (websiteId, data, errors) {
  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pWebsiteId) {
      return function (response) {
        if (response.error) {
          var extErr = response.error;
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


mainwp_delete_nonmainwp_data_done = function () {
  currentThreads--;
  if (!bulkTaskRunning)
    return;
  websitesDone++;
  if (websitesDone > websitesTotal)
    websitesDone = websitesTotal;

  mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);

  if (websitesDone == websitesTotal) {
    jQuery("#mainwp-non-mainwp-changes-table tbody").fadeOut("slow");
    var successSites = jQuery('#mainwp-sync-sites-modal .check.green.icon').length;
    if (websitesDone == successSites) {
      bulkTaskRunning = false;
      setTimeout(function () {
        mainwpPopup('#mainwp-sync-sites-modal').close(true);
      }, 3000);
    } else {
      bulkTaskRunning = false;
    }
    return;
  }
  mainwp_delete_nonmainwp_data_loop_next();
};


mainwp_tool_disconnect_sites = function () {

  mainwp_confirm('Are you sure that you want to disconnect your sites? This will function will break the connection and leave the MainWP Child plugin active and which makes your sites vulnerable.', function () {
    var allWebsiteIds = jQuery('.dashboard_wp_id').map(function (indx, el) {
      return jQuery(el).val();
    });

    for (var i = 0; i < allWebsiteIds.length; i++) {
      dashboard_update_site_status(allWebsiteIds[i], '<i class="clock outline icon"></i>');
    }

    var nrOfWebsites = allWebsiteIds.length;

    mainwpPopup('#mainwp-sync-sites-modal').init({
      title: __('Disconnect All Sites'),
      progressMax: nrOfWebsites,
      statusText: __('disconnected'),
      callback: function () {
        window.location.href = location.href;
      }
    });

    websitesToUpdate = allWebsiteIds;
    currentWebsite = 0;
    websitesDone = 0;
    websitesTotal = websitesLeft = websitesToUpdate.length;

    bulkTaskRunning = true;

    if (websitesTotal == 0) {
      mainwp_tool_disconnect_sites_done();
    } else {
      mainwp_tool_disconnect_sites_loop_next();
    }
  }, false, false, false, 'DISCONNECT');
};

mainwp_tool_disconnect_sites_done = function () {
  currentThreads--;
  if (!bulkTaskRunning)
    return;
  websitesDone++;
  if (websitesDone > websitesTotal)
    websitesDone = websitesTotal;

  mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(websitesDone);

  mainwp_tool_disconnect_sites_loop_next();
};

mainwp_tool_disconnect_sites_loop_next = function () {
  while (bulkTaskRunning && (currentThreads < maxThreads) && (websitesLeft > 0)) {
    mainwp_tool_disconnect_sites_next();
  }
};

mainwp_tool_disconnect_sites_next = function () {
  currentThreads++;
  websitesLeft--;
  var websiteId = websitesToUpdate[currentWebsite++];
  dashboard_update_site_status(websiteId, '<i class="sync alternate loading icon"></i>');
  var data = mainwp_secure_data({
    action: 'mainwp_disconnect_site',
    wp_id: websiteId
  });
  mainwp_tool_disconnect_sites_next_int(websiteId, data, 0);
};

mainwp_tool_disconnect_sites_next_int = function (websiteId, data, errors) {
  jQuery.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    success: function (pWebsiteId) {
      return function (response) {
        if (response && response.error) {
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

mainwp_tool_clear_activation_data = function (pObj) {
  var loc = jQuery(pObj).attr('href');
  mainwp_confirm('Are you sure?', function () {
    window.location = loc;
  });
};

/**
 * Manage sites page
 */

jQuery(document).ready(function ($) {
  jQuery('#mainwp-backup-type').on('change', function () {
    if (jQuery(this).val() == 'full')
      jQuery('.mainwp-backup-full-exclude').show();
    else
      jQuery('.mainwp-backup-full-exclude').hide();
  });
  jQuery('.mainwp-checkbox-showhide-elements').on('click', function () {
    var hiel = $(this).attr('hide-parent');
    mainwp_showhide_elements(hiel, $(this).find('input').is(':checked'));
  });

  jQuery('.mainwp-selecter-showhide-elements').on('change', function () {
    var hiel = $(this).attr('hide-parent');
    var hival = $(this).attr('hide-value');
    hival = hival.split('-'); // support multi hide values.
    var selectedval = $(this).val();
    mainwp_showhide_elements(hiel, hival.includes(selectedval));
  });
});

function mainwp_showhide_elements(attEl, valHi) {
  if (valHi) {
    jQuery('[hide-element=' + attEl + ']').fadeOut(300);
  } else {
    jQuery('[hide-element=' + attEl + ']').fadeIn(200);
  }
}


jQuery(document).ready(function ($) {
  $('#mainwp_settings_verify_connection_method').on('change', function () {
    var selectedval = $(this).val();
    var selectedval = $(this).val();
    if (selectedval == 2) { // phpseclib.
      $('.mainwp-hide-elemenent-sign-algo').fadeOut(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo').fadeIn(200);
    }
  });

  $('#mainwp_managesites_edit_verify_connection_method').on('change', function () {
    var selectedval = $(this).val();
    if (selectedval == 2 || selectedval == 3) { // phpseclib.
      $('.mainwp-hide-elemenent-sign-algo').fadeOut(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo').fadeIn(200);
    }
  });


  $('#mainwp_managesites_edit_openssl_alg').on('change', function () {
    var selectedval = $(this).val();
    if (selectedval == 1) {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeIn(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeOut(200);
    }
  });

  $('#mainwp_settings_openssl_alg').on('change', function () {
    var selectedval = $(this).val();
    if (selectedval == 1) {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeIn(200);
    } else {
      $('.mainwp-hide-elemenent-sign-algo-note').fadeOut(200);
    }
  });

})

jQuery(document).ready(function () {
  jQuery(document).on('change', '#mainwp_managesites_add_wpurl', function () {
    var url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    var protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();

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
    var parent = jQuery(this).closest('.sync-ext-row');
    var opts = parent.find(".sync-options input[type='checkbox']");
    if (jQuery(this).is(':checked')) {
      //opts.prop("disabled", false);
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

managesites_init = function () {
  jQuery('#mainwp-message-zone').hide();
  jQuery('.sync-ext-row span.status').html('');
  jQuery('.sync-ext-row span.status').css('color', '#0073aa');
};

mainwp_site_overview_reconnect = function (pElement) {
  feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...', '');
  var data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: pElement.attr('siteid')
  });

  jQuery.post(ajaxurl, data, function () {
    return function (response) {
      response = response.trim();
      if (response.substr(0, 5) == 'ERROR') {
        var error;
        if (response.length == 5) {
          error = 'Undefined error! Please try again. If the process keeps failing, please review <a href="https://kb.mainwp.com/">MainWP Knowledgebase</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.';
        } else {
          error = response.substr(6);
        }
        feedback('mainwp-message-zone', error, 'red');
      } else {
        location.reload();
      }
    }
  }());
};

mainwp_managesites_reconnect = function (pElement) {
  var wrapElement = pElement.closest('tr');
  wrapElement.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Trying to reconnect. Please wait...' + '</td>');
  var data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: wrapElement.attr('siteid')
  });

  jQuery.post(ajaxurl, data, function (pWrapElement) {
    return function (response) {
      response = response.trim();
      pWrapElement.hide(); // hide reconnect item
      if (response.substr(0, 5) == 'ERROR') {
        var error;
        if (response.length == 5) {
          error = 'Undefined error! Please try again. If the process keeps failing, please review this <a href="https://kb.mainwp.com/docs/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.';
        } else {
          error = response.substr(6);
        }
        feedback('mainwp-message-zone', error, 'red');
      } else {
        feedback('mainwp-message-zone', response, 'green');
      }
      setTimeout(function () {
        window.location.reload()
      }, 6000);
    }

  }(wrapElement));
};

mainwp_managesites_cards_reconnect = function (element) {
  element.html('<i class="notched loading circle icon"></i> Reconnecting...');
  var data = mainwp_secure_data({
    action: 'mainwp_reconnectwp',
    siteid: element.attr('site-id')
  });

  jQuery.post(ajaxurl, data, function (element) {
    return function (response) {
      response = response.trim();
      element.hide();
      if (response.substr(0, 5) == 'ERROR') {
        var error;
        if (response.length == 5) {
          error = 'Undefined error! Please try again. If the process keeps failing, please review this <a href="https://kb.mainwp.com/docs/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.';
        } else {
          error = response.substr(6);
        }
        feedback('mainwp-message-zone', error, 'red');
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
mainwp_managesites_add = function () {
  managesites_init();
  if (!jQuery('#mainwp_managesites_verify_installed_child').is(':checked')) {
    jQuery('#mainwp_message_verify_installed_child').show();
    scrollElementTop('mainwp_message_verify_installed_child');
    return;
  } else {
    jQuery('#mainwp_message_verify_installed_child').hide();
  }

  var errors = [];

  if (jQuery('#mainwp_managesites_add_wpname').val().trim() == '') {
    errors.push(__('Please enter a name for the website.'));
  }
  if (jQuery('#mainwp_managesites_add_wpurl').val().trim() == '') {
    errors.push(__('Please enter a valid URL for your site.'));
  } else {
    var url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    if (url.substr(-1) != '/') {
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
  } else {
    feedback('mainwp-message-zone', __('Adding the site to your MainWP Dashboard. Please wait...'), 'green');

    jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //disable button to add..

    //Check if valid user & rulewp is installed?
    var url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val().trim();
    if (url.substr(-1) != '/') {
      url += '/';
    }

    var name = jQuery('#mainwp_managesites_add_wpname').val().trim();
    name = name.replace(/"/g, '&quot;');

    var data = mainwp_secure_data({
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
      response = res_things.response;
      response = response.trim();
      var url = jQuery('#mainwp_managesites_add_wpurl_protocol').val() + '://' + jQuery('#mainwp_managesites_add_wpurl').val().trim();
      if (url.substr(-1) != '/') {
        url += '/';
      }

      url = url.replace(/"/g, '&quot;');

      var show_resp = __('Click %1here%2 to see response from the child site.', '<a href="javascript:void(0)" class="mainwp-show-response">', '</a>');

      var resp_data = res_things.resp_data ? res_things.resp_data : '';
      if ('0' == resp_data) {
        resp_data = '';
      }
      jQuery('#mainwp-response-data-container').attr('resp-data', resp_data);

      if (response == 'HTTPERROR') {
        errors.push(__('This site can not be reached! Please use the Test Connection feature and see if the positive response will be returned. For additional help, please review this <a href="https://kb.mainwp.com/docs/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.'));
      } else if (response == 'NOMAINWP') {
        errors.push(__('MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, please review this <a href="https://kb.mainwp.com/docs/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.'));
      } else if (response.substr(0, 5) == 'ERROR') {
        if (response.length == 5) {
          errors.push(__('Undefined error occurred. Please try again. If the issue does not resolve, please review this <a href="https://kb.mainwp.com/docs/potential-issues/">Knowledgebase document</a>, and if you still have issues, please let us know in the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a>.'));
        } else {
          errors.push(__('Error detected: ') + response.substr(6));
        }
      } else if (response == 'OK') {
        jQuery('#mainwp_managesites_add').attr('disabled', 'true'); //Disable add button

        var name = jQuery('#mainwp_managesites_add_wpname').val();
        name = name.replace(/"/g, '&quot;');
        var group_ids = jQuery('#mainwp_managesites_add_addgroups').dropdown('get value');
        var client_id = jQuery('#mainwp_managesites_add_client_id').dropdown('get value');
        var data = mainwp_secure_data({
          action: 'mainwp_addwp',
          managesites_add_wpname: name,
          managesites_add_wpurl: url,
          managesites_add_wpadmin: jQuery('#mainwp_managesites_add_wpadmin').val(),
          managesites_add_uniqueId: jQuery('#mainwp_managesites_add_uniqueId').val(),
          ssl_verify: jQuery('#mainwp_managesites_verify_certificate').is(':checked') ? 1 : 0,
          ssl_version: jQuery('#mainwp_managesites_add_ssl_version').val(),
          groupids: group_ids,
          clientid: client_id,
          managesites_add_http_user: jQuery('#mainwp_managesites_add_http_user').val(),
          managesites_add_http_pass: jQuery('#mainwp_managesites_add_http_pass').val(),
        });

        // to support add client reports tokens values
        jQuery("input[name^='creport_token_']").each(function () {
          var tname = jQuery(this).attr('name');
          var tvalue = jQuery(this).val();
          data[tname] = tvalue;
        });

        // support hooks fields
        jQuery(".mainwp_addition_fields_addsite input").each(function () {
          var tname = jQuery(this).attr('name');
          var tvalue = jQuery(this).val();
          data[tname] = tvalue;
        });

        jQuery.post(ajaxurl, data, function (res_things) {
          var site_id = 0;
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

          if (response.substr(0, 5) == 'ERROR') {
            jQuery('#mainwp-message-zone').removeClass('green');
            feedback('mainwp-message-zone', response.substr(6) + (resp_data != '' ? '<br>' + show_resp : ''), 'red');
          } else {
            //Message the WP was added
            jQuery('#mainwp-message-zone').removeClass('red');
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
        jQuery('#mainwp-message-zone').removeClass('green');
        managesites_init();
        jQuery('#mainwp_managesites_add').prop("disabled", false); //Enable add button
        if (resp_data != '') {
          errors.push(show_resp);
        }
        feedback('mainwp-message-zone', errors.join('<br />'), 'red');
      }
    }, 'json');
  }
};

mainwp_managesites_sync_extension_start_next = function (siteId) {
  while ((pluginToInstall = jQuery('.sync-ext-row[status="queue"]:first')) && (pluginToInstall.length > 0) && (bulkInstallCurrentThreads < 1 /* bulkInstallMaxThreads // to fix install plugins and apply settings failed issue */)) {
    mainwp_managesites_sync_extension_start_specific(pluginToInstall, siteId);
  }

  if ((pluginToInstall.length == 0) && (bulkInstallCurrentThreads == 0)) {
    jQuery('#mwp_applying_ext_settings').remove();
  }
};

mainwp_managesites_sync_extension_start_specific = function (pPluginToInstall, pSiteId) {
  pPluginToInstall.attr('status', 'progress');
  var syncGlobalSettings = pPluginToInstall.find(".sync-global-options input[type='checkbox']:checked").length > 0 ? true : false;
  var install_plugin = pPluginToInstall.find(".sync-install-plugin input[type='checkbox']:checked").length > 0 ? true : false;
  var apply_settings = pPluginToInstall.find(".sync-options input[type='checkbox']:checked").length > 0 ? true : false;

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

mainwp_extension_prepareinstallplugin = function (pPluginToInstall, pSiteId) {
  var site_Ids = [];
  site_Ids.push(pSiteId);
  bulkInstallCurrentThreads++;
  var plugin_slug = pPluginToInstall.find(".sync-install-plugin").attr('slug');
  var workingEl = pPluginToInstall.find(".sync-install-plugin i");
  var statusEl = pPluginToInstall.find(".sync-install-plugin span.status");

  var data = {
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
    if (response.sites && response.sites[pSiteId]) {
      statusEl.html(__('Installing...'));
      var data = mainwp_secure_data({
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
        var apply_settings = false;
        var syc_msg = '';
        var _success = false;
        if ((response.ok != undefined) && (response.ok[pSiteId] != undefined)) {
          syc_msg = __('Installation successful!');
          statusEl.html(syc_msg);
          apply_settings = pPluginToInstall.find(".sync-options input[type='checkbox']:checked").length > 0 ? true : false;
          if (apply_settings) {
            mainwp_extension_apply_plugin_settings(pPluginToInstall, pSiteId, false);
          }
          _success = true;
        } else if ((response.errors != undefined) && (response.errors[pSiteId] != undefined)) {
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
          jQuery('#mainwp-message-zone').append(pPluginToInstall.find(".sync-install-plugin").attr('plugin_name') + ' ' + syc_msg + '<br/>');
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

mainwp_extension_apply_plugin_settings = function (pPluginToInstall, pSiteId, pGlobal) {
  var extSlug = pPluginToInstall.attr('slug');
  var workingEl = pPluginToInstall.find(".options-row i");
  var statusEl = pPluginToInstall.find(".options-row span.status");
  if (pGlobal)
    bulkInstallCurrentThreads++;

  var data = mainwp_secure_data({
    action: 'mainwp_ext_applypluginsettings',
    ext_dir_slug: extSlug,
    siteId: pSiteId
  });

  workingEl.show();
  statusEl.html(__('Applying settings...'));
  jQuery.post(ajaxurl, data, function (response) {
    workingEl.hide();
    var syc_msg = '';
    var _success = false;
    if (response) {
      if (response.result && response.result == 'success') {
        var msg = '';
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
        syc_msg = __('Apply global %1 options', pPluginToInstall.attr('ext_name')) + ' ' + syc_msg + '<br/>';
      } else {
        syc_msg = __('Apply %1 settings', pPluginToInstall.find('.sync-install-plugin').attr('plugin_name')) + ' ' + syc_msg + '<br/>';
      }
      jQuery('#mainwp-message-zone').append(syc_msg);
    }
    bulkInstallCurrentThreads--;
    mainwp_managesites_sync_extension_start_next(pSiteId);
  }, 'json');
}

// Test Connection (Add Site Page)
mainwp_managesites_test = function () {

  var errors = [];

  if (jQuery('#mainwp_managesites_add_wpurl').val().trim() == '') {
    errors.push(__('Please enter a valid URL for your site.'));
  } else {
    var clean_url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    var protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();
    url = protocol + '://' + clean_url;
    if (url.substr(-1) != '/') {
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

    var clean_url = jQuery('#mainwp_managesites_add_wpurl').val().trim();
    var protocol = jQuery('#mainwp_managesites_add_wpurl_protocol').val();
    url = protocol + '://' + clean_url;

    if (url.substr(-1) != '/') {
      url += '/';
    }

    var data = mainwp_secure_data({
      action: 'mainwp_testwp',
      url: url,
      test_verify_cert: jQuery('#mainwp_managesites_verify_certificate').is(':checked') ? 1 : 0,
      ssl_version: jQuery('#mainwp_managesites_add_ssl_version').val(),
      http_user: jQuery('#mainwp_managesites_add_http_user').val(),
      http_pass: jQuery('#mainwp_managesites_add_http_pass').val()
    });

    jQuery.post(ajaxurl, data, function (response) {
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
mainwp_managesites_edit_test = function () {

  var clean_url = jQuery('#mainwp_managesites_edit_siteurl').val();
  var protocol = jQuery('#mainwp_managesites_edit_siteurl_protocol').val();

  url = protocol + '://' + clean_url;

  if (url.substr(-1) != '/') {
    url += '/';
  }

  jQuery('#mainwp-test-connection-modal').modal('setting', 'closable', false).modal('show');
  jQuery('#mainwp-test-connection-modal .dimmer').show();
  jQuery('#mainwp-test-connection-modal .content #mainwp-test-connection-result').hide();

  var data = mainwp_secure_data({
    action: 'mainwp_testwp',
    url: url,
    test_verify_cert: jQuery('#mainwp_managesites_edit_verifycertificate').val(),
    ssl_version: jQuery('#mainwp_managesites_edit_ssl_version').val(),
    http_user: jQuery('#mainwp_managesites_edit_http_user').val(),
    http_pass: jQuery('#mainwp_managesites_edit_http_pass').val()
  });

  jQuery.post(ajaxurl, data, function (response) {
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

managesites_remove = function (obj) {
  managesites_init();

  var name = jQuery(obj).attr('site-name');
  var id = jQuery(obj).attr('site-id');

  var msg = sprintf(__('Are you sure you want to remove %1 from your MainWP Dashboard?', name));

  mainwp_confirm(msg, function () {
    jQuery('tr#child-site-' + id).html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Removing and deactivating the MainWP Child plugin! Please wait...' + '</td>');
    var data = mainwp_secure_data({
      action: 'mainwp_removesite',
      id: id
    });

    jQuery.post(ajaxurl, data, function (response) {

      managesites_init();

      var result = '';
      var error = '';

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

jQuery(document).ready(function () {

  jQuery(document).on('click', '#mainwp_managesites_add', function (event) {
    mainwp_managesites_add(event);
  });

  jQuery(document).on('click', '#mainwp_managesites_bulkadd', function () {
    if (jQuery('#mainwp_managesites_file_bulkupload').val() == '') {
      setHtml('#mainwp-message-zone', __('Please enter csv file for upload.'), false);
    } else {
      jQuery('#mainwp_managesites_bulkadd_form').submit();
    }
    return false;
  });

  // Trigger Connection Test (Add Site Page)
  jQuery(document).on('click', '#mainwp_managesites_test', function (event) {
    mainwp_managesites_test(event);
  });

  // Trigger Connection Test (Edit Site Page)
  jQuery(document).on('click', '#mainwp_managesites_edit_test', function (event) {
    mainwp_managesites_edit_test(event);
  });

});

/**
 * Add new user
 */
jQuery(document).ready(function () {
  jQuery(document).on('click', '#bulk_add_createuser', function (event) {
    mainwp_createuser(event);
  });
  jQuery('#bulk_import_createuser').on('click', function () {
    mainwp_bulkupload_users();
  });
});

mainwp_createuser = function () {
  var cont = true;
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

  if (jQuery('#select_by').val() == 'site') {
    var selected_sites = [];
    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });
    if (selected_sites.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or group or client.'), 'yellow');
      cont = false;
    }
  } else if (jQuery('#select_by').val() == 'client') {
    var selected_clients = [];
    jQuery("input[name='selected_clients[]']:checked").each(function () {
      selected_clients.push(jQuery(this).val());
    });
    if (selected_clients.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or group or client.'), 'yellow');
      cont = false;
    }
  } else {
    var selected_groups = [];
    jQuery("input[name='selected_groups[]']:checked").each(function () {
      selected_groups.push(jQuery(this).val());
    });
    if (selected_groups.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or group or client.'), 'yellow');
      cont = false;
    }
  }

  if (cont) {
    jQuery('#mainwp-message-zone').removeClass('red green yellow');
    jQuery('#mainwp-message-zone').html('<i class="notched circle loading icon"></i> ' + __('Creating the user. Please wait...'));
    jQuery('#mainwp-message-zone').show();
    jQuery('#bulk_add_createuser').attr('disabled', 'disabled');
    //Add user via ajax!!
    var data = mainwp_secure_data({
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
      jQuery('#mainwp-message-zone').hide();
      jQuery('#bulk_add_createuser').prop("disabled", false);
      if (response.substring(0, 5) == 'ERROR') {
        var responseObj = JSON.parse(response.substring(6));
        if (responseObj.error == undefined) {
          var errorMessageList = responseObj[1];
          var errorMessage = '';
          for (var i = 0; i < errorMessageList.length; i++) {
            if (errorMessage != '')
              errorMessage = errorMessage + "<br />";
            errorMessage = errorMessage + errorMessageList[i];
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
jQuery(document).ready(function () {
  jQuery('#MainWPInstallBulkNavSearch').on('click', function (event) {
    event.preventDefault();
    jQuery('#mainwp_plugin_bulk_install_btn').attr('bulk-action', 'install');
    jQuery('.mainwp-browse-plugins').show();
    jQuery('.mainwp-upload-plugin').hide();
    jQuery('#mainwp-search-plugins-form').show();
  });
  jQuery('#MainWPInstallBulkNavUpload').on('click', function (event) {
    event.preventDefault();
    jQuery('#mainwp_plugin_bulk_install_btn').attr('bulk-action', 'upload');
    jQuery('.mainwp-upload-plugin').show();
    jQuery('.mainwp-browse-plugins').hide();
    jQuery('#mainwp-search-plugins-form').hide();
  });

  // not used?
  jQuery(document).on('click', '.filter-links li.plugin-install a', function (event) {
    event.preventDefault();
    jQuery('.filter-links li.plugin-install a').removeClass('current');
    jQuery(this).addClass('current');
    var tab = jQuery(this).parent().attr('tab');
    if (tab == 'search') {
      mainwp_install_search(event);
    } else {
      jQuery('#mainwp_installbulk_s').val('');
      jQuery('#mainwp_installbulk_tab').val(tab);
      mainwp_install_plugin_tab_search('tab:' + tab);
    }
  });

  jQuery(document).on('click', '#mainwp_plugin_bulk_install_btn', function () {
    var act = jQuery(this).attr('bulk-action');
    if (act == 'install') {
      var selected = jQuery("input[type='radio'][name='install-plugin']:checked");
      if (selected.length == 0) {
        feedback('mainwp-message-zone', __('Please select plugin to install files.'), 'yellow');
      } else {
        var selectedId = /^install-([^-]*)-(.*)$/.exec(selected.attr('id'));
        if (selectedId) {
          mainwp_install_bulk('plugin', selectedId[2], selected.attr('plugin-name'), selected.attr('plugin-version'));
        }
      }
    } else if (act == 'upload') {
      mainwp_upload_bulk('plugins');
    }

    return false;
  });

  jQuery(document).on('click', '#mainwp_theme_bulk_install_btn', function () {
    var act = jQuery(this).attr('bulk-action');
    if (act == 'install') {
      var selected = jQuery("input[type='radio'][name='install-theme']:checked");
      if (selected.length == 0) {
        feedback('mainwp-message-zone', __('Please select theme to install files.'), 'yellow');
      } else {
        var selectedId = /^install-([^-]*)-(.*)$/.exec(selected.attr('id'));
        if (selectedId)
          mainwp_install_bulk('theme', selectedId[2], selected.attr('theme-name'), selected.attr('theme-version'));
      }
    } else if (act == 'upload') {
      mainwp_upload_bulk('themes');
    }
    return false;
  });
});

// Generate the Go to WP Admin link
mainwp_links_visit_site_and_admin = function (url, siteId) {
  var links = '';
  if (url != '') {
    links += '<a href="' + url + '" target="_blank" class="mainwp-may-hide-referrer"><i class="external alternate icon"></i></a> ';
  }
  links += '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' + siteId + '&_opennonce=' + mainwpParams._wpnonce + '" target="_blank"><i class="sign in alternate icon"></i></a>';
  return links;
}

bulkInstallTotal = 0;
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
mainwp_install_bulk = function (type, slug, name) {
  var data = mainwp_secure_data({
    action: 'mainwp_preparebulkinstallplugintheme',
    type: type,
    slug: slug,
    name: name,
    selected_by: jQuery('#select_by').val()
  });
  var placeholder = '<div class="ui placeholder"><div class="paragraph"><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div></div></div>';

  if (jQuery('#select_by').val() == 'site') {

    var selected_sites = [];

    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });

    if (selected_sites.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }

    data['selected_sites[]'] = selected_sites;

  } else if (jQuery('#select_by').val() == 'client') {

    var selected_clients = [];

    jQuery("input[name='selected_clients[]']:checked").each(function () {
      selected_clients.push(jQuery(this).val());
    });

    if (selected_clients.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }

    data['selected_clients[]'] = selected_clients;

  } else {
    var selected_groups = [];

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
      var installQueueContent = '';
      bulkInstallDone = 0;
      installQueueContent += '<div id="bulk_install_info"></div>';
      installQueueContent += '<div class="ui middle aligned divided selection list">';

      for (var siteId in response.sites) {
        var site = response.sites[siteId];
        installQueueContent +=
          '<div class="siteBulkInstall item" siteid="' + siteId + '" status="queue">' +
          '<div class="right floated content">' +
          '<span class="queue" data-inverted="" data-position="left center" data-tooltip="' + __('Queued') + '"><i class="clock outline icon"></i></span>' +
          '<span class="progress" data-inverted="" data-position="left center" data-tooltip="' + __('Installing...') + '" style="display:none"><i class="notched circle loading icon"></i></span>' +
          '<span class="status"></span>' +
          '</div>' +
          '<div class="content">' + mainwp_links_visit_site_and_admin('', siteId) + ' ' + '<a href="' + site['url'] + '">' + site['name'].replace(/\\(.)/mg, "$1") + '</a></div>' +
          '</div>';
        bulkInstallTotal++;
      }

      installQueueContent += '</div>';

      jQuery('#plugintheme-installation-queue').html(installQueueContent);
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress({ value: 0, total: bulkInstallTotal });
      mainwp_install_bulk_start_next(type, response.url, activatePlugin, overwrite, slug, response);
    }
  }(type, jQuery('#chk_activate_plugin').is(':checked'), jQuery('#chk_overwrite').is(':checked')), 'json');

  jQuery('#plugintheme-installation-progress-modal').modal('setting', 'closable', false).modal('show');

};

bulkInstallMaxThreads = mainwpParams['maximumInstallUpdateRequests'] == undefined ? 3 : mainwpParams['maximumInstallUpdateRequests'];
bulkInstallCurrentThreads = 0;

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
mainwp_install_bulk_start_next = function (type, url, activatePlugin, overwrite, slug, installResults) {
  while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    mainwp_install_bulk_start_specific(type, url, activatePlugin, overwrite, siteToInstall, slug, installResults);
  }
  if (bulkInstallDone == bulkInstallTotal && bulkInstallTotal != 0) {
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
mainwp_install_bulk_start_specific = function (type, url, activatePlugin, overwrite, siteToInstall, slug, installResults) {
  bulkInstallCurrentThreads++;

  siteToInstall.attr('status', 'progress');
  siteToInstall.find('.queue').hide();
  siteToInstall.find('.progress').show();
  var data = mainwp_secure_data({
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

      var statusEl = siteToInstall.find('.status');
      statusEl.show();

      var success = false;
      var _error = '';
      if (response.error != undefined) {
        statusEl.html(response.error);
        statusEl.css('color', 'red');
      } else if ((response.ok != undefined) && (response.ok[siteToInstall.attr('siteid')] != undefined)) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Installation completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>');
        success = true;
        if (installResults.installed_sites == undefined) {
          installResults.installed_sites = [];
        }
        installResults.installed_sites.push(siteToInstall.attr('siteid'));
      } else if ((response.errors != undefined) && (response.errors[siteToInstall.attr('siteid')] != undefined)) {
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
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + bulkInstallTotal + ' ' + __('Installed'));
      mainwp_install_bulk_start_next(type, url, activatePlugin, overwrite, slug, installResults);
    }
  }(type, url, activatePlugin, overwrite, siteToInstall), 'json');
};


mainwp_install_bulk_you_know_msg = function (type, total) {
  var msg = '';
  if (mainwpParams.installedBulkSettingsManager && mainwpParams.installedBulkSettingsManager == 1) {
    if (type == 'plugin') {
      if (total == 1)
        msg = __('Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', '<a href="https://kb.mainwp.com/docs/bulk-settings-manager-extension/" target="_blank">', '</a>');
      else
        msg = __('Would you like to use the Bulk Settings Manager with these plugins? Check out the %1Documentation%2.', '<a href="https://kb.mainwp.com/docs/bulk-settings-manager-extension/" target="_blank">', '</a>');
    } else {
      if (total == 1)
        msg = __('Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', '<a href="https://kb.mainwp.com/docs/bulk-settings-manager-extension/" target="_blank">', '</a>');
      else
        msg = __('Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', '<a href="https://kb.mainwp.com/docs/bulk-settings-manager-extension/" target="_blank">', '</a>');
    }
  } else {
    if (type == 'plugin') {
      if (total == 1)
        msg = __('Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>');
      else
        msg = __('Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>');
    } else {
      if (total == 1)
        msg = __('Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>');
      else
        msg = __('Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', '<a href="https://mainwp.com/extension/bulk-settings-manager/" target="_blank">Bulk Settings Extension</a>');
    }
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
mainwp_upload_bulk = function (type) {

  if (type == 'plugins') {
    type = 'plugin';
  } else {
    type = 'theme';
  }

  var files = [];

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

  var data = mainwp_secure_data({
    action: 'mainwp_preparebulkuploadplugintheme',
    type: type,
    selected_by: jQuery('#select_by').val()
  });

  var placeholder = '<div class="ui placeholder"><div class="paragraph"><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div><div class="line"></div></div></div>';

  if (jQuery('#select_by').val() == 'site') {
    var selected_sites = [];
    jQuery("input[name='selected_sites[]']:checked").each(function () {
      selected_sites.push(jQuery(this).val());
    });

    if (selected_sites.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }
    data['selected_sites[]'] = selected_sites;
  } else if (jQuery('#select_by').val() == 'client') {
    var selected_clients = [];
    jQuery("input[name='selected_clients[]']:checked").each(function () {
      selected_clients.push(jQuery(this).val());
    });

    if (selected_clients.length == 0) {
      feedback('mainwp-message-zone', __('Please select at least one website or a group or client.', 'mainwp'), 'yellow');
      return;
    }
    data['selected_clients[]'] = selected_clients;
  } else {
    var selected_groups = [];
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
      var installQueue = '';
      bulkInstallTotal = 0;
      bulkInstallDone = 0;

      installQueue += '<div class="ui middle aligned selection divided list">';

      for (var siteId in response.sites) {
        var site = response.sites[siteId];

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
        bulkInstallTotal++;
      }

      installQueue += '</div>';

      jQuery('#plugintheme-installation-queue').html(installQueue);

      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress({ value: 0, total: bulkInstallTotal });
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
mainwp_upload_bulk_start_next = function (type, urls, activatePlugin, overwrite) {
  while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    mainwp_upload_bulk_start_specific(type, urls, activatePlugin, overwrite, siteToInstall);
  }

  if ((siteToInstall.length == 0) && (bulkInstallCurrentThreads == 0)) {
    var data = mainwp_secure_data({
      action: 'mainwp_cleanbulkuploadplugintheme'
    });
    jQuery.post(ajaxurl, data, function () { });
    var msg = mainwp_install_bulk_you_know_msg(type, jQuery('#bulk_upload_info').attr('number-files'));

    jQuery('#bulk_upload_info').html('<div class="mainwp-notice mainwp-notice-blue">' + msg + '</div>');

    if (jQuery('.mainwp_cost_tracker_assistant_installed_items').length > 0) { // to support add to cost tracker pro.
      var cost_tracker_items = [];
      var cost_tracker_check_slugs = [];
      jQuery('.mainwp_cost_tracker_assistant_installed_items').each(function () {
        var slug = jQuery(this).attr('item-slug');
        var items_slug = {};
        if (!cost_tracker_check_slugs.includes(slug)) {
          cost_tracker_check_slugs.push(slug);
          var siteids = [];
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
        var multiAddTo = cost_tracker_items.length > 1 ? 1 : 0;
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
mainwp_upload_bulk_start_specific = function (type, urls, activatePlugin, overwrite, siteToInstall) {
  bulkInstallCurrentThreads++;
  siteToInstall.attr('status', 'progress');

  siteToInstall.find('.queue').hide();
  siteToInstall.find('.progress').show();

  var data = mainwp_secure_data({
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
      var statusEl = siteToInstall.find('.status');
      var siteid = siteToInstall.attr('siteid');
      statusEl.show();

      if (response.error != undefined) {
        statusEl.html(response.error);
        statusEl.css('color', 'red');
      } else if ((response.ok != undefined) && (response.ok[siteid] != undefined)) {
        var results = '';
        if ((response.results != undefined) && (response.results[siteid] != undefined)) {
          entries = Object.entries(response.results[siteid]);
          results += '<div class="ui tiny middle aligned selection list">';
          for (var entry of entries) {
            results += '<div class="item"><div class="right floated content">' + (entry[1] ? '<i class="check green icon"></i>' : '<i class="times red icon"></i>') + '</div><div class="content">' + entry[0] + '</div></div>';
          }
          results += '</div>';
        }
        jQuery('div[siteId="' + siteid + '"] .installation-entries').html(results);
        if (response.cost_tracker_installed_info != undefined) { // to support add to cost tracker pro.
          jQuery('div[siteId="' + siteid + '"] .installation-entries').after(response.cost_tracker_installed_info);
        }
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Installation completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>');
      } else if ((response.errors != undefined) && (response.errors[siteid] != undefined)) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + response.errors[siteid][1] + '"><i class="times red icon"></i></span>');
      } else {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Undefined error occurred. Please try again.', 'mainwp') + '"><i class="times red icon"></i></span>');
      }

      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress('set progress', bulkInstallDone);
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + bulkInstallTotal + ' ' + __('Installed'));
      mainwp_upload_bulk_start_next(type, urls, activatePlugin, overwrite);
    }
  }(type, urls, activatePlugin, overwrite, siteToInstall), 'json');
};

jQuery(document).ready(function ($) {
  jQuery(document).on('click', '.open-plugin-details-modal', function () {
    var itemDetail = this;

    var openwpp = jQuery(this).attr('open-wpplugin');
    var openwpp_site = '';
    if (typeof openwpp != "undefined" && 'yes' == openwpp) {
      var findNext = jQuery(this).closest('tr').next().find('tr[open-wpplugin-siteid]');
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

mainwp_install_check_plugin_prepare = function (slug) {
  var selected = jQuery("input[name='install_checker[]']:checked");
  if (selected.length == 0) {
    feedback('mainwp-message-zone-install', __('Please select website to install plugin.'), 'yellow');
    return;
  } else {
    selected.each(function () {
      jQuery(this).closest('.siteBulkInstall').attr('status', 'queue');
    });
  }
  jQuery('#mainwp-install-check-btn').addClass('disabled');
  jQuery('#mainwp-message-zone-install').html('<i class="notched circle loading icon"></i> ').show();
  var data = mainwp_secure_data({
    action: 'mainwp_preparebulkinstallcheckplugin',
    slug: slug,
  });
  jQuery.post(ajaxurl, data, function (response) {
    jQuery('#mainwp-message-zone-install').html('').hide();
    mainwp_install_check_plugin_start_next(response.url);
  }, 'json');
};

mainwp_install_check_plugin_start_next = function (url) {
  while ((siteToInstall = jQuery('.siteBulkInstall[status="queue"]:first')) && (siteToInstall.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    mainwp_install_check_plugin_start_specific(url, siteToInstall);
  }
};

mainwp_install_check_plugin_start_specific = function (url, siteToInstall) {
  bulkInstallCurrentThreads++;

  siteToInstall.attr('status', 'progress');
  siteToInstall.find('.queue').hide();
  siteToInstall.find('.progress').show();

  var data = mainwp_secure_data({
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

      var statusEl = siteToInstall.find('.status');
      statusEl.show();

      if (response.error != undefined) {
        statusEl.html(response.error);
        statusEl.css('color', 'red');
      } else if ((response.ok != undefined) && (response.ok[siteToInstall.attr('siteid')] != undefined)) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Installation completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>');
      } else if ((response.errors != undefined) && (response.errors[siteToInstall.attr('siteid')] != undefined)) {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + response.errors[siteToInstall.attr('siteid')][1] + '"><i class="times red icon"></i></span>');
      } else {
        statusEl.html('<span data-inverted="" data-position="left center" data-tooltip="' + __('Undefined error occurred. Please try again.', 'mainwp') + '"><i class="times red icon"></i></span>');
      }

      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').progress('set progress', bulkInstallDone);
      jQuery('#plugintheme-installation-progress-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + bulkInstallTotal + ' ' + __('Installed'));
      mainwp_install_check_plugin_start_next(url);
    }
  }(url, siteToInstall), 'json');
};



/**
 * Utility
 */
function isUrl(s) {
  var regexp = /(ftp|http|https):\/\/(\w+:{0,1}\w*@)?(\S+)(:[0-9]+)?(\/|\/([\w#!:.?+=&%@!\-/]))?/;
  return regexp.test(s);
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
jQuery(document).ready(function () {

  jQuery(document).on('click', '#mainwp-notes-cancel', function () {
    jQuery('#mainwp-notes-status').html('');
    jQuery('#mainwp-notes-status').removeClass('red green');
    mainwp_notes_hide();
    return false;
  });

  jQuery(document).on('click', '#mainwp-notes-save', function () {
    var which = jQuery('#mainwp-which-note').val();
    if (which == 'site') {
      mainwp_notes_site_save();
    } else if (which == 'theme') {
      mainwp_notes_theme_save();
    } else if (which == 'plugin') {
      mainwp_notes_plugin_save()
    } else if (which == 'client') {
      mainwp_notes_client_save()
    }
    var newnote = jQuery('#mainwp-notes-note').val();
    jQuery('#mainwp-notes-html').html(newnote);
    return false;
  });

  jQuery(document).on('click', '.mainwp-edit-site-note', function () {
    var id = jQuery(this).attr('id').substr(13);
    var note = jQuery('#mainwp-notes-' + id + '-note').html();
    jQuery('#mainwp-notes-html').html(note == '' ? __('No saved notes. Click the Edit button to edit site notes.') : note);
    jQuery('#mainwp-notes-note').val(note);
    jQuery('#mainwp-notes-websiteid').val(id);
    jQuery('#mainwp-which-note').val('site'); // to fix conflict.
    mainwp_notes_show();
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

mainwp_notes_show = function (reloadClose) {
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
mainwp_notes_hide = function () {
  jQuery('#mainwp-notes-modal').modal('hide');
};
mainwp_notes_site_save = function () {
  var normalid = jQuery('#mainwp-notes-websiteid').val();
  var newnote = jQuery('#mainwp-notes-note').val();
  newnote = newnote.replace(/(?:\r\n|\r|\n)/g, '<br>');
  var data = mainwp_secure_data({
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

getErrorMessage = function (pError, msgOnly) {
  if (pError.message == 'HTTPERROR') {
    return __('HTTP error') + '! ' + pError.extra;
  } else if (pError.message == 'NOMAINWP' || pError == 'NOMAINWP') {
    var error = '';
    if (pError.extra) {
      error = __('MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, check the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a> for help.', pError.extra); // to fix incorrect encoding
    } else {
      error = __('MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, check the <a href="https://managers.mainwp.com/c/community-support/5">MainWP Community</a> for help.');
    }

    return error;
  } else if (pError.message == 'ERROR') {
    return 'ERROR' + ((pError.extra != '') && (pError.extra != undefined) ? ': ' + pError.extra : '');
  } else if (pError.message == 'WPERROR') {
    var extrMsg = (pError.extra != '') && (pError.extra != undefined) ? pError.extra : '';
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

getErrorMessageInfo = function (repError, outputType) {
  var msg = '';
  var msgUI = '<i class="red times icon"></i>';

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
      msg = __('MainWP Child plugin not detected or could not be reached! Ensure the MainWP Child plugin is installed and activated on the child site, and there are no security rules blocking requests.  If you continue experiencing this issue, check the MainWP Community for help.');
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

dateToHMS = function (date) {
  if (mainwpParams != undefined && mainwpParams['time_format'] != undefined) {
    var time = moment(date);
    var format = mainwpParams['time_format'];
    format = format.replace('g', 'h');
    format = format.replace('i', 'mm');
    format = format.replace('s', 'ss');
    format = format.replace('F', 'MMMM');
    format = format.replace('j', 'D');
    format = format.replace('Y', 'YYYY');
    return time.format(format);
  }
  var h = date.getHours();
  var m = date.getMinutes();
  var s = date.getSeconds();
  return '' + (h <= 9 ? '0' + h : h) + ':' + (m <= 9 ? '0' + m : m) + ':' + (s <= 9 ? '0' + s : s);
};
appendToDiv = function (pSelector, pText, pScrolldown, pShowTime) {
  if (pScrolldown == undefined)
    pScrolldown = true;
  if (pShowTime == undefined)
    pShowTime = true;

  var theDiv = jQuery(pSelector);
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
  var strippedText = text.replace(/ /g, '_');
  strippedText = strippedText.replace(/[^A-Za-z0-9_]/g, '');

  if (strippedText == '')
    return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);

  if (mainwpTranslations == undefined)
    return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);
  if (mainwpTranslations[strippedText] == undefined)
    return text.replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);

  return mainwpTranslations[strippedText].replace('%1', _var1).replace('%2', _var2).replace('%3', _var3);
}

mainwp_secure_data = function (data, includeDts) {
  if (data['action'] == undefined)
    return data;

  if (security_nonces[data['action']] == undefined)
    return data;

  data['security'] = security_nonces[data['action']];
  if (includeDts)
    data['dts'] = Math.round(new Date().getTime() / 1000);
  return data;
};


mainwp_uid = function () {
  // always start with a letter (for DOM friendlyness)
  var idstr = String.fromCharCode(Math.floor((Math.random() * 25) + 65));
  do {
    // between numbers and characters (48 is 0 and 90 is Z (42-48 = 90)
    var ascicode = Math.floor((Math.random() * 42) + 48);
    if (ascicode < 58 || ascicode > 64) {
      // exclude all chars between : (58) and @ (64)
      idstr += String.fromCharCode(ascicode);
    }
  } while (idstr.length < 32);

  return (idstr);
};

scrollToElement = function () {
  jQuery('html,body').animate({
    scrollTop: 0
  }, 1000);

  return false;
};

jQuery(document).ready(function () {
  jQuery('#backup_filename').on('keypress', function (e) {
    var chr = String.fromCharCode(e.which);
    return ("$^&*/".indexOf(chr) < 0);
  });
  jQuery('#backup_filename').on('change', function () {
    var value = jQuery(this).val();
    var notAllowed = ['$', '^', '&', '*', '/'];
    for (var i = 0; i < notAllowed.length; i++) {
      var char = notAllowed[i];
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

serverinfo_prepare_download_info = function (communi) {
  var report = "";
  jQuery('.mainwp-system-info-table thead, .mainwp-system-info-table tbody').each(function () {
    var td_len = [35, 55, 45, 12, 12];
    var th_count = 0;
    var i;
    if (jQuery(this).is('thead')) {
      i = 0;
      report = report + "\n### ";
      th_count = jQuery(this).find('th:not(".mwp-not-generate-row")').length;
      jQuery(this).find('th:not(".mwp-not-generate-row")').each(function () {
        var len = td_len[i];
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
  var server_info = jQuery('#download-server-information textarea').val();
  var blob = new Blob([server_info], { type: "text/plain;charset=utf-8" });
  saveAs(blob, "mainwp-system-report.txt");
  return false;
});

jQuery(document).on('click', '#mainwp-copy-meta-system-report', function () {
  jQuery("#download-server-information").slideDown(); // to able to select and copy
  serverinfo_prepare_download_info(true);
  jQuery("#download-server-information").slideUp();
  try {
    var successful = document.execCommand('copy');
    var msg = successful ? 'successful' : 'unsuccessful';
    console.log('Copying text command was ' + msg);
  } catch (err) {
    console.log('Oops, unable to copy');
  }
  return false;
});


jQuery.mwp_strCut = function (i, l, s, w) {
  var o = i.toString();
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

updateExcludedFolders = function () {
  var excludedBackupFiles = jQuery('#excludedBackupFiles').html();
  jQuery('#mainwp-kbl-content').val(excludedBackupFiles == undefined ? '' : excludedBackupFiles);

  var excludedCacheFiles = jQuery('#excludedCacheFiles').html();
  jQuery('#mainwp-kcl-content').val(excludedCacheFiles == undefined ? '' : excludedCacheFiles);

  var excludedNonWPFiles = jQuery('#excludedNonWPFiles').html();
  jQuery('#mainwp-nwl-content').val(excludedNonWPFiles == undefined ? '' : excludedNonWPFiles);
};


jQuery(document).on('click', '.mainwp-events-notice-dismiss', function () {
  var notice = jQuery(this).attr('notice');
  jQuery(this).closest('.ui.message').fadeOut(500);
  var data = mainwp_secure_data({
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
  var data = mainwp_secure_data({
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
  var data = mainwp_secure_data({
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
  var notice_id = jQuery(this).attr('notice-id');
  jQuery(this).closest('.ui.message').fadeOut("slow");
  var data = {
    action: 'mainwp_notice_status_update'
  };
  data['notice_id'] = notice_id;
  jQuery.post(ajaxurl, mainwp_secure_data(data), function () { });
  return false;
});


mainwp_notice_dismiss = function (notice_id, time_set) {
  var data = {
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
  var data = mainwp_secure_data({
    action: 'mainwp_dismiss_activate_notice',
    slug: jQuery(this).closest('tr').attr('slug')
  });
  jQuery.post(ajaxurl, data, function () {
  });
  return false;
});

jQuery(document).on('click', '.mainwp-install-check-dismiss', function () {
  var notice_id = jQuery(this).attr('notice-id');
  jQuery(this).closest('.ui.message').fadeOut("slow");
  var data = {
    action: 'mainwp_notice_status_update'
  };
  data['notice_id'] = notice_id;
  jQuery.post(ajaxurl, mainwp_secure_data(data), function () { });
  return false;
});

jQuery(document).on('click', '.mainwp-action-dismiss', function () {
  var action_id = jQuery(this).attr('action-id');
  jQuery(this).closest('tr').fadeOut("slow");
  var data = {
    action: 'mainwp_site_actions_dismiss'
  };
  data['action_id'] = action_id;
  jQuery.post(ajaxurl, mainwp_secure_data(data), function () {

  });
  return false;
});

jQuery(document).on('click', '#mainwp-delete-all-nonmainwp-actions-button', function () {
  mainwp_confirm('Are you sure you want to delete all Non-MainWP changes? This action can not be undone!', function () {
    mainwp_delete_nonmainwp_data_start();
  });
  return false;
});

mainwp_managesites_update_childsite_value = function (siteId, uniqueId) {
  var data = mainwp_secure_data({
    action: 'mainwp_updatechildsite_value',
    site_id: siteId,
    unique_id: uniqueId
  });
  jQuery.post(ajaxurl, data, function () {
  });
  return false;
};

jQuery(document).on('keyup', '#managegroups-filter', function () {
  var filter = jQuery(this).val();
  var groupItems = jQuery(this).parent().parent().find('li.managegroups-listitem');
  for (var i = 0; i < groupItems.length; i++) {
    var currentElement = jQuery(groupItems[i]);
    if (currentElement.hasClass('managegroups-group-add'))
      continue;
    var value = currentElement.find('span.text').text();
    if (value.indexOf(filter) > -1) {
      currentElement.show();
    } else {
      currentElement.hide();
    }
  }
});

// for normal checkboxes
jQuery(document).on('change', '#cb-select-all-top, #cb-select-all-bottom', function () {
  var $this = jQuery(this),
    $table = $this.closest('table'),
    controlChecked = $this.prop('checked');

  if ($table.length == 0)
    return false;

  $table.children('tbody').filter(':visible')
    .children().children('.check-column').find(':checkbox')
    .prop('checked', function () {
      if (jQuery(this).is(':hidden,:disabled')) {
        return false;
      }
      if (controlChecked) {
        return true;
      }
      return false;
    });

  $table.children('thead,  tfoot').filter(':visible')
    .children().children('.check-column').find(':checkbox')
    .prop('checked', function () {
      if (controlChecked) {
        return true;
      }
      return false;
    });
});


jQuery(document).ready(function ($) {
  // Trigger the bulk actions
  $('#mainwp_non_mainwp_actions_action_btn').on('click', function () {
    var bulk_act = jQuery('#non_mainwp_actions_bulk_action').dropdown("get value");
    var confirmMsg = '';
    switch (bulk_act) {
      case 'delete':
        confirmMsg = __("You are about to delete the selected changes?");
        break;
      case 'dismiss':
        confirmMsg = __("You are about to dismiss the selected changes?");
        break;
    }
    if (confirmMsg == '') {
      return false;
    }
    mainwp_confirm(confirmMsg, function () { mainwp_non_mainwp_actions_table_bulk_action(bulk_act); });
    return false; // return those case
  });


  $(document).on('click', '.non-mainwp-action-row-dismiss', function () {
    var row = $(this).closest('tr');
    var confirmMsg = __("You are about to dismiss the selected changes?");
    mainwp_confirm(confirmMsg, function () {
      row.html('<td></td><td colspan="999"><i class="notched circle loading icon"></i> Please wait...</td>');
      var data = mainwp_secure_data({
        action: 'mainwp_non_mainwp_changes_dismiss_actions',
        act_id: $(row).attr('action-id')
      });
      $.post(ajaxurl, data, function (response) {
        if (response) {
          if (response['error']) {
            row.html('<td></td><td colspan="999"><i class="times red icon"></i> ' + response['error'] + '</td>');
          } else if (response['success'] == 'yes') {
            row.html('<td></td><td colspan="999"><i class="green check icon"></i> Successfully.</td>');
            setTimeout(function () {
              jQuery(row).fadeOut("slow");
            }, 2000);
          } else {
            row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
          }
        } else {
          row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
        }
      }, 'json');
    });

    return false;
  });

  $(document).on('click', '.non-mainwp-action-row-delete', function () {
    var row = $(this).closest('tr');
    var confirmMsg = __("You are about to delete the selected changes?");

    mainwp_confirm(confirmMsg, function () {
      row.html('<td></td><td colspan="999"><i class="notched circle loading icon"></i> Please wait...</td>');
      var data = mainwp_secure_data({
        action: 'mainwp_non_mainwp_changes_delete_actions',
        act_id: $(row).attr('action-id')
      });
      $.post(ajaxurl, data, function (response) {
        if (response) {
          if (response['error']) {
            row.html('<td></td><td colspan="999"><i class="times red icon"></i> ' + response['error'] + '</td>');
          } else if (response['success'] == 'yes') {
            row.html('<td></td><td colspan="999"><i class="green check icon"></i> Successfully.</td>');
            setTimeout(function () {
              jQuery(row).fadeOut("slow");
            }, 2000);
          } else {
            row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
          }
        } else {
          row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
        }
      }, 'json');
    });
    return false;
  });

})


// Manage Bulk Actions
mainwp_non_mainwp_actions_table_bulk_action = function (act) {
  bulkInstallTotal = 0;
  bulkInstallCurrentThreads = 0;
  bulkInstallDone = 0;
  var selector = '';
  switch (act) {
    case 'delete':
      selector += '#mainwp-manage-non-mainwp-actions-table tbody tr';
      jQuery(selector).addClass('queue');
      mainwp_non_mainwp_actions_delete_start_next(selector, true);
      break;
    case 'dismiss':
      selector += '#mainwp-manage-non-mainwp-actions-table tbody tr';
      jQuery(selector).addClass('queue');
      mainwp_non_mainwp_actions_dismiss_start_next(selector, true);
      break;
  }
}

mainwp_non_mainwp_actions_delete_start_next = function (selector) {
  if (bulkInstallTotal == 0) {
    bulkInstallTotal = jQuery('#mainwp-manage-non-mainwp-actions-table tbody').find('input[type="checkbox"]:checked').length;
  }
  while ((objProcess = jQuery(selector + '.queue:first')) && (objProcess.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    objProcess.removeClass('queue');
    if (objProcess.closest('tr').find('input[type="checkbox"]:checked').length == 0) {
      continue;
    }
    mainwp_non_mainwp_actions_delete_specific(objProcess, selector, true);
  }
}

mainwp_non_mainwp_actions_delete_specific = function (pObj, selector, pBulk) {
  var row = pObj.closest('tr');
  var act_id = jQuery(row).attr('action-id');
  var bulk = pBulk ? true : false;

  if (bulk) {
    bulkInstallCurrentThreads++;
  }

  var data = mainwp_secure_data({
    action: 'mainwp_non_mainwp_changes_delete_actions',
    act_id: act_id,
  });

  row.html('<td></td><td colspan="999"><i class="notched circle loading icon"></i> Please wait...</td>');

  jQuery.post(ajaxurl, data, function (response) {
    pObj.removeClass('queue');
    if (response) {
      if (response['error']) {
        row.html('<td></td><td colspan="999"><i class="times red icon"></i> ' + response['error'] + '</td>');
      } else if (response['success'] == 'yes') {
        row.html('<td></td><td colspan="999"><i class="green check icon"></i> Successfully.</td>');
        setTimeout(function () {
          jQuery(row).fadeOut("slow");
        }, 2000);
      } else {
        row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
      }
    } else {
      row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
    }

    if (bulk) {
      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      mainwp_non_mainwp_actions_delete_start_next(selector);
      if (bulkInstallTotal == bulkInstallDone) {
        setTimeout(function () {
          window.location.reload(true);
        }, 3000);
      }
    }

  }, 'json');
  return false;
}



mainwp_non_mainwp_actions_dismiss_start_next = function (selector) {
  if (bulkInstallTotal == 0) {
    bulkInstallTotal = jQuery('#mainwp-manage-non-mainwp-actions-table tbody').find('input[type="checkbox"]:checked').length;
  }
  while ((objProcess = jQuery(selector + '.queue:first')) && (objProcess.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    objProcess.removeClass('queue');
    if (objProcess.closest('tr').find('input[type="checkbox"]:checked').length == 0) {
      continue;
    }
    mainwp_non_mainwp_actions_dismiss_specific(objProcess, selector, true);
  }
}

mainwp_non_mainwp_actions_dismiss_specific = function (pObj, selector, pBulk) {
  var row = pObj.closest('tr');
  var act_id = jQuery(row).attr('action-id');
  var bulk = pBulk ? true : false;

  if (bulk) {
    bulkInstallCurrentThreads++;
  }

  var data = mainwp_secure_data({
    action: 'mainwp_non_mainwp_changes_dismiss_actions',
    act_id: act_id,
  });

  row.html('<td></td><td colspan="999"><i class="notched circle loading icon"></i> Please wait...</td>');

  jQuery.post(ajaxurl, data, function (response) {
    pObj.removeClass('queue');
    if (response) {
      if (response['error']) {
        row.html('<td></td><td colspan="999"><i class="times red icon"></i> ' + response['error'] + '</td>');
      } else if (response['success'] == 'yes') {
        row.html('<td></td><td colspan="999"><i class="green check icon"></i> Successfully.</td>');
        setTimeout(function () {
          jQuery(row).fadeOut("slow");
        }, 2000);
      } else {
        row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
      }
    } else {
      row.html('<td></td><td colspan="999"><i class="times red icon"></i> Failed. Please try again.</td>');
    }

    if (bulk) {
      bulkInstallCurrentThreads--;
      bulkInstallDone++;
      mainwp_non_mainwp_actions_dismiss_start_next(selector);
      if (bulkInstallTotal == bulkInstallDone) {
        setTimeout(function () {
          window.location.reload(true);
        }, 3000);
      }
    }

  }, 'json');
  return false;
}


// fix menu overflow with scroll tables.
mainwp_datatable_fix_menu_overflow = function (pTableSelector, pTop, pRight, pLeft) {
  var fix_overflow = jQuery('.mainwp-content-wrap').attr('menu-overflow');
  jQuery(document).on('click', 'table td.check-column.dtr-control', function () {
    if (jQuery(this).parent().hasClass('parent')) {
      var chilRow = jQuery(this).parent().next();
      jQuery(chilRow).find('.ui.dropdown').dropdown();
      mainwp_datatable_fix_child_menu_overflow(chilRow, fix_overflow);
    }
  });
  var tblSelect = pTableSelector !== '' && pTableSelector !== undefined && pTableSelector !== false ? pTableSelector : 'table';

  console.log('mainwp_datatable_fix_menu_overflow :: ' + tblSelect);

  // Fix the overflow prbolem for the actions menu element (right pointing menu).
  jQuery(tblSelect + ' tr td .ui.right.pointing.dropdown.button').on('click', function () {
    jQuery(this).closest('.dataTables_scrollBody').css('position', '');
    jQuery(this).closest('.dataTables_scroll').css('position', 'relative');
    jQuery(this).css('position', 'static');
    var fix_overflow = jQuery('.mainwp-content-wrap').attr('menu-overflow');
    var position = jQuery(this).position();
    var top = position.top;
    var right = 48;
    if (fix_overflow > 1) {
      position = jQuery(this).closest('td').position();
      top = position.top + 85;
    }
    if (pTop !== undefined) {
      top = top + pTop;
    }
    if (pRight !== undefined) {
      right = right + pRight;
    }
    console.log('right');
    console.log('top: ' + top + ' right: ' + right);
    //return false;
    jQuery(this).find('.menu').css('min-width', '170px');
    jQuery(this).find('.menu').css('top', top);
    jQuery(this).find('.menu').css('right', right);
  });

  // Fix the overflow prbolem for the actions menu element (left pointing menu).
  jQuery(tblSelect + ' tr td .ui.left.pointing.dropdown.button').on('click', function () {
    jQuery(this).closest('.dataTables_scrollBody').css('position', '');
    jQuery(this).closest('.dataTables_scroll').css('position', 'relative');
    jQuery(this).css('position', 'static');
    var position = jQuery(this).position();
    var top = position.top;
    var left = position.left - 159;

    if (fix_overflow > 1) {
      position = jQuery(this).closest('td').position();
      var scroll_left = jQuery(this).closest('.dataTables_scrollBody').scrollLeft();
      top = position.top + 85;
      left = position.left - scroll_left - 145;
    }

    if (pTop !== undefined) {
      top = top + pTop;
    }
    if (pRight !== undefined) {
      right = right + pRight;
    }
    console.log('left');
    console.log('top: ' + top + ' left: ' + left);

    jQuery(this).find('.menu').css('min-width', '150px');
    jQuery(this).removeClass('left');
    jQuery(this).addClass('right');
    //return false;
    jQuery(this).find('.menu').css('top', top);
    jQuery(this).find('.menu').css('left', left);
  });
}

mainwp_datatable_fix_child_menu_overflow = function (chilRow, fix_overflow) {
  // Fix the overflow prbolem for the actions child menu element (pointing menu).
  jQuery(chilRow).find('.ui.pointing.dropdown.button').on('click', function () {

    var position = jQuery(this).position();
    var left = position.left + 30;
    var top = position.top;

    if (fix_overflow > 1) {
      position = jQuery(this).closest('td.child').position();
      top = position.top + jQuery(this).closest('td.child').height() + 85;
    }

    jQuery(this).closest('.dataTables_scrollBody').css('position', '');
    jQuery(this).closest('.dataTables_scroll').css('position', 'relative');
    jQuery(this).css('position', 'static');
    jQuery(this).find('.menu').css('top', top);
    jQuery(this).find('.menu').css('left', left);
    jQuery(this).find('.menu').css('min-width', '170px');
    console.log('top:' + top);
  });
}


mainwp_responsive_fix_remove_child_row = function (el) {
  if (jQuery(el).hasClass('dt-hasChild')) { // to fix.
    jQuery(el).next().remove();
  }
}

/* eslint-disable complexity */
function mainwp_according_table_sorting(pObj) {
  var table, th, rows, switching, i, x, y, xVal, yVal, campare = false, shouldSwitch = false, dir, switchcount = 0, n, skip = 1;
  table = jQuery(pObj).closest('table')[0];
  var subline_skip = 2;
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
        campare = (xVal == yVal) ? 0 : (xVal > yVal ? -1 : 1);
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
    } else {
      /* If no switching has been done AND the direction is "asc",
      set the direction to "desc" and run the while loop again. */
      if (switchcount == 0 && dir == "asc") {
        dir = "desc";
        switching = true;
      }
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

jQuery(document).ready(function () {
  jQuery('.handle-accordion-sorting').on('click', function () {
    mainwp_according_table_sorting(this);
    return false;
  });
});

// Force Dashboard to reestablish connection by destroying sessions - Part 1
mainwp_force_destroy_sessions = function () {
  var confirmMsg = __('Are you sure you want to force your MainWP Dashboard to reconnect with your child sites?');
  mainwp_confirm(confirmMsg, function () {
    mainwp_force_destroy_sessions_websites = jQuery('.dashboard_wp_id').map(function (indx, el) {
      return jQuery(el).val();
    });
    mainwpPopup('#mainwp-sync-sites-modal').setTitle(__('Re-establish Connection')); // popup displayed.
    mainwpPopup('#mainwp-sync-sites-modal').init({ progressMax: mainwp_force_destroy_sessions_websites.length });
    mainwp_force_destroy_sessions_part_2(0);
  });
};

mainwp_force_destroy_sessions_part_2 = function (id) {
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

  var website_id = mainwp_force_destroy_sessions_websites[id];
  dashboard_update_site_status(website_id, '<i class="sync alternate loading icon"></i>');

  jQuery.post(ajaxurl, { 'action': 'mainwp_force_destroy_sessions', 'website_id': website_id, 'security': security_nonces['mainwp_force_destroy_sessions'] }, function (response) {
    var counter = id + 1;
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
    var counter = id + 1;
    mainwp_force_destroy_sessions_part_2(counter);
    mainwpPopup('#mainwp-sync-sites-modal').setProgressSite(counter);

    dashboard_update_site_status(website_id, '<i class="exclamation red icon"></i>');
  });

};

var mainwp_force_destroy_sessions_successed = 0;
var mainwp_force_destroy_sessions_websites = [];


jQuery(document).on('change', '#mainwp_archiveFormat', function () {
  var zipMethod = jQuery(this).val();
  zipMethod = zipMethod.replace(/\./g, '\\.');
  jQuery('span.archive_info').hide();
  jQuery('span#info_' + zipMethod).show();

  jQuery('tr.archive_method').hide();
  jQuery('tr.archive_' + zipMethod).show();

  // compare new layout
  jQuery('div.archive_method').hide();
  jQuery('div.archive_' + zipMethod).show();
});


// MainWP Tools
jQuery(function () {
  jQuery(document).on('click', '#force-destroy-sessions-button', function () {
    mainwp_force_destroy_sessions();
  });

  jQuery(document).on('click', '.mainwp-import-demo-data-button', function () {
    var confirmation = "Are you sure you want to import demo content into your MainWP Dashboard?";
    var msg_import = (jQuery(this).attr('page-import') == 'qsw-import') ? '&message=qsw-import' : '';
    mainwp_confirm(confirmation, function () {
      feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Importing. Please wait...', 'mainwp'), '');
      var data = mainwp_secure_data({
        action: 'mainwp_import_demo_data',
      });

      jQuery.post(ajaxurl, data, function (response) {
        var error = false;
        if (response.count != undefined) {
          feedback('mainwp-message-zone', __('The demo content has been imported into your MainWP Dashboard.', 'mainwp'), 'green');
        } else {
          error = true;
          feedback('mainwp-message-zone', __('Undefined error. Please try again.', 'mainwp'), 'green');
        }
        if (error == false) {
          setTimeout(function () {
            window.location = 'admin.php?page=mainwp_tab' + msg_import;
          }, 3000);
        }
      }, 'json');
    });
    return false;
  });

  jQuery(document).on('click', '.mainwp-remove-demo-data-button', function () {

    var confirmation = "Are you sure you want to delete demo content from your MainWP Dashboard?";
    mainwp_confirm(confirmation, function () {

      feedback('mainwp-message-zone', '<i class="notched circle loading icon"></i> ' + __('Deleting. Please wait...', 'mainwp'), '');

      var data = mainwp_secure_data({
        action: 'mainwp_delete_demo_data',
      });

      jQuery.post(ajaxurl, data, function (response) {

        var error = false;

        if (response.success != undefined) {
          feedback('mainwp-message-zone', __('The demo content has been deleted from your MainWP Dashboard.', 'mainwp'), 'green');
        } else {
          error = true;
          feedback('mainwp-message-zone', __('Undefined error. Please try again.', 'mainwp'), 'green');
        }

        if (error == false) {
          setTimeout(function () {
            window.location = 'admin.php?page=mainwp-setup';
          }, 3000);
        }

      }, 'json');
    });
    return false;
  });


});


mainwp_tool_renew_connections_show = function () {
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

mainwp_tool_prepare_renew_connections = function (objBtn) {

  var errors = [];
  var selected_sites = [];

  jQuery('#mainwp-message-zone-modal').removeClass('yellow ').hide();

  jQuery("input[name='selected_sites[]']:checked").each(function () {
    selected_sites.push(jQuery(this).val());
  });
  if (selected_sites.length == 0) {
    errors.push(__('Please select at least one website to start.'));
  }

  if (errors.length > 0) {
    jQuery('#mainwp-message-zone-modal').html(errors.join('<br />'));
    jQuery('#mainwp-message-zone-modal').addClass('yellow ').show();
    return;
  } else {
    jQuery('#mainwp-message-zone-modal').html("");
    jQuery('#mainwp-message-zone-modal').removeClass('yellow ').hide();
  }

  var confirmation = __("This process will create a new OpenSSL Key Pair on your MainWP Dashboard and Set the new Public Key to your Child site(s). Are you sure you want to proceed?");

  mainwp_confirm(confirmation, function () {
    jQuery(objBtn).attr('disabled', true);

    jQuery('#mainwp-tool-renew-connect-modal .mainwp-select-sites-wrapper').hide();

    var statusEl = jQuery('#mainwp-message-zone-modal');
    statusEl.html('<i class="notched circle loading icon"></i> ' + __('Please wait...'));
    statusEl.show();

    var data = mainwp_secure_data({
      action: 'mainwp_prepare_renew_connections',
      'sites[]': selected_sites,
    });

    jQuery.post(ajaxurl, data, function (response) {
      var undefError = false;
      if (response) {
        if (response.result != '') {
          jQuery('#mainwp-tool-renew-connect-modal').find('#mainwp-renew-connections-list').html(response.result);
          bulkInstallTotal = jQuery('#mainwp-renew-connections-list .item').length;
          jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').show();
          jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').progress({ value: 0, total: bulkInstallTotal });
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

connection_renew_status = function (siteId, newStatus) {
  jQuery('#mainwp-renew-connections-list .renew-site-status[siteid="' + siteId + '"]').html(newStatus);
};

mainwp_tool_renew_connections_start_next = function () {
  while ((siteToReNew = jQuery('#mainwp-renew-connections-list .item[status="queue"]:first')) && (siteToReNew.length > 0) && (bulkInstallCurrentThreads < bulkInstallMaxThreads)) {
    mainwp_tool_renew_connections_start_specific(siteToReNew);
  }
}

mainwp_tool_renew_connections_start_specific = function (siteItem) {

  bulkInstallCurrentThreads++;

  siteItem.attr('status', 'progress');
  var siteId = siteItem.find('.renew-site-status').attr('siteid');

  var data = mainwp_secure_data({
    action: 'mainwp_renew_connections',
    siteid: siteId
  });

  connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Processing...', 'mainwp') + '"><i class="sync alternate loading icon"></i></span>');
  jQuery.post(ajaxurl, data, function (response) {
    if (response.error) {
      connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + response.error + '"><i class="times red icon"></i></span>');
    } else if (response.result == 'success') {
      connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Renew connnection process completed successfully.', 'mainwp') + '"><i class="check green icon"></i></span>', true);
    } else {
      connection_renew_status(siteId, '<span data-inverted="" data-position="left center" data-tooltip="' + __('Undefined error.') + '"><i class="times red icon"></i></span>');

    }
    bulkInstallCurrentThreads--;
    bulkInstallDone++;
    jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').progress('set progress', bulkInstallDone);
    jQuery('#mainwp-tool-renew-connect-modal .mainwp-modal-progress').find('.label').html(bulkInstallDone + '/' + bulkInstallTotal + ' ' + __('Processed'));
    mainwp_tool_renew_connections_start_next();
  }, 'json');
}


jQuery(function () {
  if (jQuery('body.mainwp-ui').length > 0) {
    jQuery('.mainwp-ui-page .ui.dropdown:not(.not-auto-init)').dropdown();
    jQuery('.mainwp-ui-page .ui.checkbox:not(.not-auto-init)').checkbox();
    jQuery('.mainwp-ui-page .ui.dropdown').filter('[init-value]').each(function () {
      var values = jQuery(this).attr('init-value').split(',');
      jQuery(this).dropdown('set selected', values);
    });
  }
});

// MainWP Action Logs
jQuery(document).on('click', '.mainwp-action-log-show-more', function () {
  var content = jQuery(this).closest('.item').find('.mainwp-action-log-site-response').text();
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
  var content = jQuery('#mainwp-response-data-container').attr('resp-data');
  jQuery('#mainwp-response-data-modal').modal({
    closable: false,
    onHide: function () {
      jQuery('#mainwp-response-data-modal .content-response').text('');
    }
  }).modal('show');
  jQuery('#mainwp-response-data-modal .content-response').text(content);
});

// Copy to clipboard for response modals.
jQuery(document).on('click', '.mainwp-response-copy-button', function () {
  var modal = jQuery(this).closest('.ui.modal');
  var data = jQuery(modal).find('.content.content-response').text();
  var $temp_txtarea = jQuery('<textarea style="opacity:0">');
  jQuery('body').append($temp_txtarea);
  $temp_txtarea.val(data).trigger("select");
  try {
    var successful = document.execCommand('copy');
    var msg = successful ? 'successful' : 'unsuccessful';
    console.log('Copying text command was ' + msg);
  } catch (err) {
    console.log('Oops, unable to copy');
  }
  $temp_txtarea.remove();
  return false;
});

jQuery(document).ready(function () {
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
