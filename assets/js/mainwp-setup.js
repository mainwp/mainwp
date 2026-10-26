const MAINWP_ONBOARDING_POLL_INTERVAL = 5000;
const MAINWP_ONBOARDING_MAX_DURATION = 20 * 60 * 1000;
let mainwp_onboarding_detection = null;
let mainwp_onboarding_connecting = false;

function mainwp_onboarding_site_url(value, protocol) {
  try {
    if (value.includes('://') && !/^https?:\/\//i.test(value.trim())) return null;
    const url = new URL(/^https?:\/\//i.test(value.trim()) ? value.trim() : protocol + '://' + value.trim());
    if (!['http:', 'https:'].includes(url.protocol) || !url.hostname || url.username || url.password || ['21', '22'].includes(url.port)) return null;
    url.search = '';
    url.hash = '';
    url.pathname = url.pathname.replace(/\/+$/, '') + '/';
    return url.href;
  } catch (error) {
    return null;
  }
}

function mainwp_onboarding_install_url(site) {
  const url = mainwp_onboarding_site_url(site, 'https');
  return url ? url + 'wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term' : null;
}

function mainwp_onboarding_initial_state(now) {
  return { status: 'idle', startedAt: now, reachable: 'pending', child: 'pending', detected: false, deadline: null, minutes: null, result: {} };
}

function mainwp_onboarding_deadline_passed(state, now) {
  return state.detected && state.deadline !== null && now >= state.deadline;
}

function mainwp_onboarding_detection_result(state, result, now) {
  const next = Object.assign({}, state, { result: result || {} });
  const response = String(next.result.response || '').trim();
  if (response === 'OK') {
    next.status = 'detected';
    next.reachable = 'ok';
    next.child = 'ok';
    next.detected = true;
    next.minutes = Number.isInteger(next.result.connection_window_minutes) ? next.result.connection_window_minutes : null;
    next.deadline = next.minutes !== 0 && Number.isInteger(next.result.connection_window_remaining) && next.result.connection_window_remaining >= 0
      ? now + next.result.connection_window_remaining * 1000 : null;
  } else if (response === 'NOMAINWP') {
    next.status = mainwp_onboarding_deadline_passed(state, now) ? 'expired' : 'waiting';
    next.reachable = 'ok';
    next.child = next.status === 'expired' ? 'failed' : 'pending';
  } else if (['HTTPERROR', 'TIMEOUT', 'INVALID_URL'].includes(response) || /Invalid URL/i.test(next.result.error || '')) {
    next.status = 'unreachable';
    next.reachable = 'failed';
    next.child = 'pending';
  } else {
    next.status = 'error';
    next.reachable = 'pending';
    next.child = 'pending';
  }
  return next;
}

// The controller owns one request chain; generations discard replies after edits or tab changes.
function mainwp_onboarding_detection_controller(options) {
  let state = mainwp_onboarding_initial_state(options.now());
  let generation = 0;
  let pollTimer = null;
  let limitTimer = null;
  let request = null;
  let paused = false;
  function publish() { options.render(state); }
  function cancel() {
    ++generation;
    options.clearTimeout(pollTimer);
    options.clearTimeout(limitTimer);
    pollTimer = limitTimer = null;
    if (request) request.abort();
    request = null;
  }
  function stop(status) {
    cancel();
    if (status === 'idle') state = mainwp_onboarding_initial_state(options.now());
    state.status = status;
    publish();
  }
  function exhausted() {
    if (options.now() - state.startedAt < MAINWP_ONBOARDING_MAX_DURATION) return false;
    stop('timed_out');
    return true;
  }
  function armLimit() {
    limitTimer = options.setTimeout(() => stop('timed_out'), Math.max(0, MAINWP_ONBOARDING_MAX_DURATION - (options.now() - state.startedAt)));
  }
  function poll() {
    if (exhausted() || options.hidden() || paused) return;
    const current = generation;
    state.status = 'checking';
    publish();
    request = options.request(function (result) {
      if (current !== generation) return;
      request = null;
      if (exhausted()) return;
      state = mainwp_onboarding_detection_result(state, result, options.now());
      publish();
      if (state.status === 'waiting') {
        pollTimer = options.setTimeout(poll, MAINWP_ONBOARDING_POLL_INTERVAL);
      } else {
        cancel();
      }
    });
  }
  function restart(carry) {
    cancel();
    paused = options.hidden();
    state = mainwp_onboarding_initial_state(options.now());
    // Same target after a failed connect: keep the reported deadline so a later NOMAINWP can still be read as expiry.
    if (carry && carry.detected) Object.assign(state, { detected: true, deadline: carry.deadline, minutes: carry.minutes });
    if (!options.ready()) { publish(); return; }
    armLimit();
    poll();
  }
  return {
    restart,
    stop,
    state: () => state,
    refresh: publish,
    checkExpiry() {
      // A connect attempt in flight runs its own check when it fails.
      if (state.status === 'connecting' || !mainwp_onboarding_deadline_passed(state, options.now()) || options.hidden()) return;
      cancel();
      const current = generation;
      request = options.request(function (result) {
        if (current !== generation) return;
        request = null;
        const next = mainwp_onboarding_detection_result(state, result, options.now());
        // A Child that still answers reports a fresh window, so the countdown restarts from it.
        if (['expired', 'detected'].includes(next.status)) { state = next; publish(); }
      });
    },
    pause() {
      if (!['checking', 'waiting', 'idle'].includes(state.status)) {
        if (request) cancel();
        return;
      }
      paused = true;
      cancel();
      if (options.ready()) armLimit();
    },
    resume() {
      if (!options.ready()) { paused = false; return; }
      if (!paused || exhausted()) return;
      paused = false;
      if (options.ready()) poll();
    }
  };
}

function mainwp_onboarding_copy(key) {
  return typeof mainwpSetupLocalize === 'object' ? mainwpSetupLocalize[key] || '' : '';
}

function mainwp_onboarding_escape(text) {
  return jQuery('<div>').text(text).html();
}

function mainwp_onboarding_fields() {
  return {
    url: mainwp_onboarding_site_url(jQuery('#mainwp_managesites_add_wpurl').val(), jQuery('#mainwp_managesites_add_wpurl_protocol').val()),
    admin: jQuery('#mainwp_managesites_add_wpadmin').val().trim()
  };
}

function mainwp_onboarding_check_request(fields, done) {
  return jQuery.ajax({
    url: ajaxurl, type: 'POST', dataType: 'json', timeout: 20000,
    data: mainwp_setup_secure_data({ action: 'mainwp_checkwp', url: fields.url, admin: fields.admin }),
    success: done,
    error: function (xhr, status) { if (status !== 'abort') done({ response: status === 'timeout' ? 'TIMEOUT' : 'HTTPERROR' }); }
  });
}

function mainwp_onboarding_bind_detection() {
  let debounce = null;
  let countdown = null;
  function singleSite() {
    return jQuery('.menu-connect-first-site .active.item').attr('data-tab') === 'single-site';
  }
  function ready() {
    const fields = mainwp_onboarding_fields();
    return singleSite() && !!fields.admin && !!jQuery('#mainwp_managesites_add_wpurl').val().trim() && !mainwp_onboarding_connecting;
  }
  function updateButton() {
    const fields = mainwp_onboarding_fields();
    const passwordRequired = jQuery('#mainwp-administrator-password-checkbox-field').prop('checked');
    const password = jQuery('#mainwp_managesites_add_admin_pwd').val();
    jQuery('#mainwp_managesites_add').prop('disabled', mainwp_onboarding_connecting || !fields.url || !fields.admin || (passwordRequired && !password));
  }
  mainwp_onboarding_detection = mainwp_onboarding_detection_controller({
    now: () => Date.now(), hidden: () => document.hidden, ready,
    // Wrapped so the timers keep the window as their receiver; browsers reject options.setTimeout().
    setTimeout: (callback, delay) => setTimeout(callback, delay), clearTimeout: id => clearTimeout(id),
    request: done => {
      const fields = mainwp_onboarding_fields();
      if (!fields.url) { done({ response: 'INVALID_URL' }); return null; }
      return mainwp_onboarding_check_request(fields, done);
    },
    render: state => {
      clearInterval(countdown);
      countdown = null;
      mainwp_onboarding_render_detection(state);
      if (state.status === 'detected' && state.deadline !== null && state.deadline > Date.now() && !document.hidden) {
        countdown = setInterval(() => {
          mainwp_onboarding_render_time(state);
          if (Date.now() >= state.deadline) {
            clearInterval(countdown);
            countdown = null;
            mainwp_onboarding_detection.checkExpiry();
          }
        }, 1000);
      }
      updateButton();
    }
  });
  jQuery('#mainwp_managesites_add_wpurl, #mainwp_managesites_add_wpurl_protocol, #mainwp_managesites_add_wpadmin').on('input change', function () {
    clearTimeout(debounce);
    // A different target must never inherit the previous Child's deadline.
    mainwp_onboarding_detection.stop('idle');
    debounce = setTimeout(() => mainwp_onboarding_detection.restart(), 400);
  });
  jQuery('#mainwp_managesites_add_admin_pwd, #mainwp-administrator-password-checkbox-field').on('input change', updateButton);
  jQuery('#mainwp-onboarding-retry').on('click', () => mainwp_onboarding_detection.restart());
  jQuery(document).on('visibilitychange', function () {
    if (document.hidden) {
      clearInterval(countdown);
      mainwp_onboarding_detection.pause();
    } else {
      mainwp_onboarding_detection.refresh();
      mainwp_onboarding_detection.resume();
      mainwp_onboarding_detection.checkExpiry();
    }
  });
  jQuery(window).on('pagehide', function () {
    clearTimeout(debounce);
    clearInterval(countdown);
    mainwp_onboarding_detection.stop('stopped');
  });
  const mode = jQuery('#mainwp-qsw-connect-site-form').attr('data-mode');
  jQuery('#mainwp_managesites_add_import').toggle(mode === 'multiple-site');
  jQuery('#mainwp_managesites_add').toggle(mode !== 'multiple-site');
  mainwp_onboarding_detection.restart();
}

function mainwp_onboarding_remaining_text(seconds) {
  if (seconds < 60) return mainwp_onboarding_copy('remainingLess');
  const minutes = Math.ceil(seconds / 60);
  return minutes === 1 ? mainwp_onboarding_copy('remainingOne') : mainwp_onboarding_copy('remaining').replace('%d', minutes);
}

function mainwp_onboarding_render_time(state) {
  let text = '';
  if (state.minutes !== 0) {
    text = state.deadline === null ? (state.minutes === null ? mainwp_onboarding_copy('defaultTime') : '')
      : Date.now() >= state.deadline ? mainwp_onboarding_copy('windowClosed')
      : mainwp_onboarding_remaining_text(Math.max(0, Math.ceil((state.deadline - Date.now()) / 1000)));
  }
  const timeLeft = jQuery('#mainwp-onboarding-time-left');
  if (timeLeft.text() !== text) timeLeft.text(text);
}

function mainwp_onboarding_render_detection(state) {
  const fields = mainwp_onboarding_fields();
  const site = fields.url || jQuery('#mainwp_managesites_add_wpurl').val().trim();
  jQuery('#mainwp-onboarding-detection').prop('hidden', state.status === 'idle' && (!fields.url || !fields.admin));
  const settled = ['error', 'unreachable', 'timed_out', 'stopped', 'expired'].includes(state.status);
  for (const [id, status] of [['reachable', state.reachable], ['child', state.child]]) {
    const label = status === 'ok' ? 'yes' : status === 'failed' ? 'no' : id === 'child' && state.reachable === 'ok' ? 'notYet' : settled ? 'notChecked' : 'checking';
    jQuery('#mainwp-onboarding-' + id).text(mainwp_onboarding_copy(label)).toggleClass('is-ok', status === 'ok').toggleClass('is-failed', status === 'failed');
  }
  const install = ['waiting', 'timed_out', 'checking'].includes(state.status) && state.reachable === 'ok' && !!fields.url;
  jQuery('#mainwp-onboarding-install').prop('hidden', !install);
  jQuery('#mainwp-onboarding-install-link').attr('href', install ? mainwp_onboarding_install_url(fields.url) : '').text(mainwp_onboarding_copy('install').replace('%s', site));
  jQuery('#mainwp-onboarding-install-fallback').text(mainwp_onboarding_copy('fallback').replace('%s', site));
  jQuery('#mainwp-onboarding-timer').prop('hidden', state.status !== 'detected' || state.minutes === 0);
  mainwp_onboarding_render_time(state);
  jQuery('#mainwp-onboarding-expiry').prop('hidden', state.status !== 'expired');
  if (state.status === 'expired') mainwp_set_message_zone('#mainwp-message-zone');
  jQuery('#mainwp-onboarding-expiry-message').text(mainwp_onboarding_copy('expired').replace('%s', site));
  jQuery('#mainwp-onboarding-plugins-link').attr('href', fields.url ? fields.url + 'wp-admin/plugins.php' : '');
  jQuery('#mainwp-onboarding-retry').prop('hidden', !['expired', 'error', 'unreachable', 'timed_out', 'stopped'].includes(state.status));
  const message = jQuery('#mainwp-onboarding-detection-message').empty();
  if (state.status === 'unreachable') message.text(mainwp_onboarding_copy('unreachable').replace('%s', site));
  else if (state.status === 'error') {
    if (state.result.failure_ux) message.html(state.result.failure_ux);
    else message.text(state.result.error || String(state.result.response || mainwp_onboarding_copy('error')).replace(/^ERROR\s*/, ''));
  }
}

jQuery(function () {
  jQuery(document).on('input', '#mainwp_managesites_add_wpname', function () {
    jQuery(this).attr('data-autofilled', '');
  });

  jQuery('#mainwp-onboarding-advanced-toggle').on('click', function () {
    const expanded = jQuery(this).attr('aria-expanded') === 'true';
    jQuery(this).attr('aria-expanded', String(!expanded));
    jQuery('#mainwp-onboarding-advanced').prop('hidden', expanded);
  });

  const passwordHelp = jQuery('#mainwp-onboarding-password-help');
  passwordHelp.popup({
    popup: '#mainwp-onboarding-password-explanation',
    on: 'hover',
    position: 'top center'
  }).on('focus', function () {
    jQuery(this).popup('show');
  }).on('blur', function () {
    jQuery(this).popup('hide');
  }).on('click', function (event) {
    event.preventDefault();
    jQuery(this).popup('show');
  });

  jQuery('#mainwp-onboarding-update-all').on('click', function () {
    updatesoverview_global_upgrade_all('all', Number(jQuery(this).attr('data-siteid')));
  });

  // Handle submit import file CVS.
  jQuery(document).on('click', '#mainwp_managesites_add_import', function () {
    let error_messages = mainwp_managesites_import_handle_form_before_submit();
    // If there is an error, prevent submission and display the error
    if (error_messages.length > 0) {
      feedback('mainwp-message-zone', error_messages, "red");
    } else {
      jQuery('#mainwp_connect_first_site_form').trigger('submit');
    }

    return false;
  });

  jQuery('#mainwp_qsw_client_name_field').on('keyup', function () {
    if (jQuery(this).val()) {
      jQuery('#bulk_add_createclient').show();
      jQuery('#mainwp_qsw_add_client_continue_button').hide();
    } else {
      jQuery('#bulk_add_createclient').hide();
      jQuery('#mainwp_qsw_add_client_continue_button').show();
    }
  });

  jQuery('#mainwp-toggle-optional-settings').on('click', function () {
    jQuery('#mainwp-qsw-optional-settings-form').toggle(300);
    return false;
  });

  jQuery('.ui.checkbox:not(.not-auto-init)').checkbox();
  jQuery('.ui.dropdown:not(.not-auto-init)').dropdown();

  jQuery('.mainwp-checkbox-showhide-elements').on('click', function () {
    let $el = jQuery(this);
    let hiels = $el.attr('hide-parent');
    if (hiels !== undefined && hiels !== '') {
        let isChecked = $el.find('input').is(':checked');
        let items = hiels.split(';');
        items.forEach(function(hiel) {
            // if semantic ui checkbox is checked.
            if (isChecked) {
                jQuery('[hide-element=' + hiel + ']').fadeIn(500);
            } else {
                jQuery('[hide-element=' + hiel + ']').fadeOut(500);
            }
        });
    }
  });

  jQuery(document).on('click', '#mainwp_managesites_add', function () {
    mainwp_setup_managesites_add();
  });

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

    const title = jQuery('#mainwp_managesites_add_wpname');
    // A title filled from the URL follows URL corrections until the user types one.
    if (title.val() == '' || title.attr('data-autofilled')) {
      title.val(url).attr('data-autofilled', '1');
    }

    jQuery('#mainwp_managesites_add_wpurl').val(url);
    jQuery('#mainwp_managesites_add_wpurl_protocol').val(protocol).trigger("change");
  });

  if (jQuery('#mainwp-onboarding-detection').length) {
    mainwp_onboarding_bind_detection();
  }
});

// Handle tab onvisible.
const mainwp_menu_connect_first_site_onvisible_callback = function (objItem) {
  const tab = jQuery(objItem).attr("data-tab");
  if (tab === 'multiple-site') {
    jQuery('#mainwp_managesites_add_import').show();
    jQuery('#mainwp_managesites_add').hide();
  } else if (tab === 'single-site') {
    jQuery('#mainwp_managesites_add').show();
    jQuery('#mainwp_managesites_add_import').hide();
  }
  if (mainwp_onboarding_detection) {
    mainwp_onboarding_detection.restart();
  }
}
// Connect a new website
let mainwp_setup_managesites_add = function () {
  if (mainwp_onboarding_connecting) return;
  mainwp_set_message_zone('#mainwp-message-zone');
  let errors = [];

  const title = jQuery('#mainwp_managesites_add_wpname');
  if (title.val().trim() == '' || title.attr('data-autofilled')) {
    // A failed attempt already added the trailing slash to the URL field.
    title.val(jQuery('#mainwp_managesites_add_wpurl').val().trim().replace(/\/+$/, '')).attr('data-autofilled', '1');
  }

  const fields = mainwp_onboarding_fields();
  if (!fields.url) errors.push('Please enter a valid URL for the site.');

  if (jQuery('#mainwp_managesites_add_wpadmin').val().trim() == '') {
    errors.push('Please enter a username of the website administrator.');
  }

  if (jQuery('#mainwp-administrator-password-checkbox-field').prop('checked') && !jQuery('#mainwp_managesites_add_admin_pwd').val()) {
    errors.push(mainwp_onboarding_copy('password'));
  }

  if (errors.length > 0) {
    mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'yellow');
  } else {
    mainwp_set_message_zone('#mainwp-message-zone', '<i class="notched circle loading icon"></i> Adding the site to your MainWP Dashboard. Please wait...', '');
    jQuery('#mainwp_managesites_add').prop('disabled', true);

    const url = fields.url;

    const detectionState = mainwp_onboarding_detection ? mainwp_onboarding_detection.state() : null;
    mainwp_onboarding_connecting = true;
    if (mainwp_onboarding_detection) mainwp_onboarding_detection.stop('connecting');
    let name = jQuery('#mainwp_managesites_add_wpname').val();
    name = name.replace(/"/g, '&quot;');
    let group_ids = '';
    let data = mainwp_setup_secure_data({
      action: 'mainwp_addwp',
      managesites_add_wpname: name,
      managesites_add_wpurl: url,
      managesites_add_wpadmin: jQuery('#mainwp_managesites_add_wpadmin').val(),
      managesites_add_adminpwd: encodeURIComponent(jQuery('#mainwp_managesites_add_admin_pwd').val()),
      managesites_add_uniqueId: jQuery('#mainwp_managesites_add_uniqueId').val(),
      groupids: group_ids,
      qsw_page: true,
    });

    // Extensions can supply report tokens and additional connection fields.
    jQuery("input[name^='creport_token_']").each(function () {
      let tname = jQuery(this).attr('name');
      let tvalue = jQuery(this).val();
      data[tname] = tvalue;
    });

    jQuery(".mainwp_addition_fields_addsite input").each(function () {
      let tname = jQuery(this).attr('name');
      let tvalue = jQuery(this).val();
      data[tname] = tvalue;
    });

    jQuery.ajax({ url: ajaxurl, type: 'POST', data: data, dataType: 'json', success: function (res_things) {

      let response = String(res_things.error || res_things.response || '').trim();

      mainwp_set_message_zone('#mainwp-message-zone');
      jQuery('#mainwp-info-zone').hide();

      if (res_things.error || response.substring(0, 5) == 'ERROR' || !res_things.siteid) {
        mainwp_onboarding_connecting = false;
        mainwp_set_message_zone('#mainwp-message-zone', res_things.failure_ux ? res_things.failure_ux : response.substring(0, 5) === 'ERROR' ? response.substring(6) : mainwp_onboarding_escape(response || mainwp_onboarding_copy('error')), 'red');
        if (mainwp_onboarding_detection) {
          // Only a passed reported deadline can explain the failure as expiry; otherwise re-check so the rows and timer stay current.
          if (mainwp_onboarding_detection.state() !== detectionState) {
            mainwp_onboarding_detection.restart();
          } else if (mainwp_onboarding_deadline_passed(detectionState, Date.now())) {
            mainwp_onboarding_detection.stop('stopped');
            mainwp_onboarding_detection.checkExpiry();
          } else {
            mainwp_onboarding_detection.restart(detectionState);
          }
        }
      } else {
        mainwp_set_message_zone('#mainwp-message-zone', response, 'green');

        jQuery('#mainwp_managesites_add_wpname').val('');
        jQuery('#mainwp_managesites_add_wpurl').val('');
        jQuery('#mainwp_managesites_add_wpurl_protocol').val('https');
        jQuery('#mainwp_managesites_add_wpadmin').val('');
        jQuery('#mainwp_managesites_add_admin_pwd').val('');
        jQuery('#mainwp_managesites_add_uniqueId').val('');

        jQuery("input[name^='creport_token_']").each(function () {
          jQuery(this).val('');
        });

        jQuery(".mainwp_addition_fields_addsite input").each(function () {
          jQuery(this).val('');
        });

        setTimeout(function () {
          mainwp_forceReload('admin.php?page=mainwp-setup&step=next_steps&siteid=' + Number(res_things.siteid));
        }, 3000);
      }

      if (!mainwp_onboarding_detection && !mainwp_onboarding_connecting) jQuery('#mainwp_managesites_add').prop('disabled', false);
    }, error: function () {
      mainwp_onboarding_connecting = false;
      if (mainwp_onboarding_detection) {
        if (mainwp_onboarding_detection.state() === detectionState) mainwp_onboarding_detection.stop('stopped');
        else mainwp_onboarding_detection.restart();
      }
      mainwp_set_message_zone('#mainwp-message-zone', mainwp_onboarding_escape(mainwp_onboarding_copy('error')), 'red');
      if (!mainwp_onboarding_detection) jQuery('#mainwp_managesites_add').prop('disabled', false);
    }});
  }
};

let mainwp_setup_secure_data = function (data) {
  if (data['action'] == undefined)
    return data;
  data.security = jQuery('input[name="qsw_nonce[' + data.action + ']"]').val() || '';
  return data;
};
