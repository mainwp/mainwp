'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const setup = fs.readFileSync(require.resolve('../../assets/js/mainwp-setup.js'), 'utf8');
const updates = fs.readFileSync(require.resolve('../../assets/js/mainwp-updates.js'), 'utf8');

function harness() {
    const elements = new Map();
    const handlers = new Map();
    const requests = [];
    const reloads = [];
    const messages = [];
    const logs = [];
    function $(selector) {
        if (typeof selector === 'function') {
            selector();
            return;
        }
        if (selector && typeof selector === 'object' && selector.attr) return selector;
        if (!elements.has(selector)) {
            const attrs = {};
            let value = selector && String(selector).includes('qsw_nonce') ? 'fixture-nonce' : '';
            let checkboxOptions = {};
            const el = {
                length: 0,
                attr(name, next) {
                    if (next === undefined) return attrs[name];
                    attrs[name] = next;
                    return el;
                },
                prop(name, next) { return el.attr(name, next); },
                val(next) {
                    if (next === undefined) return value;
                    value = next;
                    return el;
                },
                on(event, target, callback) {
                    handlers.set(typeof target === 'string' ? target : selector, callback || target);
                    handlers.set(selector + ':' + event, callback || target);
                    return el;
                },
                change(callback) { handlers.set(selector + ':change', callback); return el; },
                tab() { return el; },
                trigger() { return el; },
                each() { return el; },
                checkbox(option) {
                    if (typeof option === 'object') checkboxOptions = option;
                    else if (option === 'set checked' || option === 'set unchecked') {
                        el.prop('checked', option === 'set checked');
                        if (checkboxOptions.onChange) checkboxOptions.onChange.call(el);
                    }
                    return el;
                },
                is() { return !!el.prop('checked'); },
                ready(callback) { callback(); return el; },
                accordion() { return el; },
                fadeIn() { return el.show(); },
                fadeOut() { return el.hide(); },
                dropdown() { return el; },
                popup() { return el; },
                hide() { el.prop('visible', false); return el; },
                show() { el.prop('visible', true); return el; },
                find() { return []; },
                text(next) { value = next; return el; },
                html() { return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
            };
            elements.set(selector, el);
        }
        return elements.get(selector);
    }
    $.post = (url, data, callback) => {
        requests.push(data);
        if (callback) callback(data.action === 'mainwp_checkwp' ? { response: 'OK' } : { response: 'Connected', siteid: 42 });
        return { fail() { return this; } };
    };
    $.ajax = options => {
        requests.push(options.data);
        options.success({ response: 'Connected', siteid: 42 });
    };
    $.each = (items, callback) => Object.entries(items).forEach(([key, value]) => callback(key, value));
    const context = vm.createContext({
        jQuery: $, document: {}, URL, console: { log: error => logs.push(error) }, ajaxurl: '/admin-ajax.php',
        mainwp_set_message_zone: (...args) => messages.push(args),
        mainwp_forceReload: url => reloads.push(url),
        mainwp_secure_data: data => data,
        setTimeout: callback => callback(),
        MainWP: { I18n: { t: text => text } },
        mainwpVars: {},
        mainwp_site_name_html: value => value,
        mainwp_decode_slug: value => value,
        mainwp_links_visit_site_and_admin: () => '',
        updatesmanage_link_to_site: () => '',
        mainwpPopup: () => ({ init() {}, clearList() {}, appendItemsList() {} })
    });
    return { $, context, handlers, requests, reloads, messages, logs };
}

test('wizard connects with an optional title, keeps nonces, and navigates with returned site ID', () => {
    const h = harness();
    h.$('#mainwp_managesites_add_wpurl').val('example.org');
    h.$('#mainwp_managesites_add_wpurl_protocol').val('https');
    h.$('#mainwp_managesites_add_wpadmin').val('admin');
    h.$('#mainwp_managesites_add_admin_pwd').val('fixture-password');
    vm.runInContext(setup, h.context);
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].action, 'mainwp_addwp');
    assert.equal(h.requests[0].managesites_add_wpname, 'example.org');
    assert.equal(h.requests[0].qsw_page, true);
    assert.equal(h.requests[0].defer_site_icon, true);
    assert.equal(h.requests.some(request => request.action === 'mainwp_get_site_icon'), false);
    assert.ok(h.requests.every(request => request.security === 'fixture-nonce'));
    assert.deepEqual(h.reloads, ['admin.php?page=mainwp-setup&step=next_steps&siteid=42']);
});

test('invalid wizard URL does not send a connection request', () => {
    const h = harness();
    h.$('#mainwp_managesites_add_wpurl').val('not a valid url');
    h.$('#mainwp_managesites_add_wpurl_protocol').val('https');
    h.$('#mainwp_managesites_add_wpadmin').val('admin');
    vm.runInContext(setup, h.context);
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests.length, 0);
    assert.equal(h.reloads.length, 0);
    assert.match(h.messages.at(-1)[1], /valid URL/);
    assert.equal(h.logs.length, 0);
});

test('a title filled from an invalid URL follows the corrected URL until the user types one', () => {
    const h = harness();
    h.$('#mainwp_managesites_add_wpurl').val('exampel org');
    h.$('#mainwp_managesites_add_wpurl_protocol').val('https');
    h.$('#mainwp_managesites_add_wpadmin').val('admin');
    vm.runInContext(setup, h.context);
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests.length, 0);
    assert.equal(h.$('#mainwp_managesites_add_wpname').val(), 'exampel org');
    assert.equal(h.$('#mainwp_managesites_add_wpname').attr('data-autofilled'), '1');
    h.$('#mainwp_managesites_add_wpurl').val('example.org');
    h.handlers.get('#mainwp_managesites_add_wpurl')();
    assert.equal(h.$('#mainwp_managesites_add_wpname').val(), 'example.org');
    h.$('#mainwp_managesites_add_wpname').val('Client Site');
    h.handlers.get('#mainwp_managesites_add_wpname').call(h.$('#mainwp_managesites_add_wpname'));
    h.$('#mainwp_managesites_add_wpurl').val('client.example.org');
    h.handlers.get('#mainwp_managesites_add_wpurl')();
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].action, 'mainwp_addwp');
    assert.equal(h.requests[0].managesites_add_wpname, 'Client Site');
});

test('a title the shared Add Site script filled still follows a corrected URL', () => {
    const h = harness();
    h.$('#mainwp_managesites_add_wpurl_protocol').val('http');
    h.$('#mainwp_managesites_add_wpadmin').val('admin');
    vm.runInContext(setup, h.context);
    // The shared script fills the title on URL change without marking it.
    h.$('#mainwp_managesites_add_wpname').val('first.example.org');
    h.$('#mainwp_managesites_add_wpurl').val('second.example.org');
    h.handlers.get('#mainwp_managesites_add_wpurl')();
    assert.equal(h.$('#mainwp_managesites_add_wpname').val(), 'second.example.org');
});

test('a title filled from the URL keeps no trailing slash when a failed attempt is retried', () => {
    for (const url of ['example.org', 'example.org/']) {
        const h = harness();
        h.$('#mainwp_managesites_add_wpurl').val(url);
        h.$('#mainwp_managesites_add_wpurl_protocol').val('https');
        h.$('#mainwp_managesites_add_wpadmin').val('admin');
        h.$.ajax = options => {
            h.requests.push(options.data);
            options.success(h.requests.length === 1
                ? { response: 'ERROR Connection failed' }
                : { response: 'Connected', siteid: 42 });
        };
        vm.runInContext(setup, h.context);
        h.handlers.get('#mainwp_managesites_add')();
        assert.equal(h.requests.length, 1);
        assert.equal(h.requests[0].action, 'mainwp_addwp');
        assert.equal(h.requests[0].managesites_add_wpname, 'example.org');
        assert.equal(h.$('#mainwp_managesites_add_wpname').val(), 'example.org');
        assert.equal(h.$('#mainwp_managesites_add_wpname').attr('data-autofilled'), '1');
        assert.equal(h.$('#mainwp_managesites_add_wpurl').val(), url);
        assert.equal(h.reloads.length, 0);
        h.handlers.get('#mainwp_managesites_add')();
        assert.equal(h.requests.length, 2);
        assert.equal(h.requests[1].action, 'mainwp_addwp');
        assert.equal(h.requests[1].managesites_add_wpname, 'example.org');
        assert.ok(h.requests.every(request => request.managesites_add_wpurl === 'https://example.org/'));
        assert.deepEqual(h.reloads, ['admin.php?page=mainwp-setup&step=next_steps&siteid=42']);
    }
});

test('Advanced options toggles visibility and its accessibility state', () => {
    const h = harness();
    vm.runInContext(setup, h.context);
    const toggle = h.$('#mainwp-onboarding-advanced-toggle').attr('aria-expanded', 'false');
    h.handlers.get('#mainwp-onboarding-advanced-toggle').call(toggle);
    assert.equal(toggle.attr('aria-expanded'), 'true');
    assert.equal(h.$('#mainwp-onboarding-advanced').prop('hidden'), false);
    h.handlers.get('#mainwp-onboarding-advanced-toggle').call(toggle);
    assert.equal(toggle.attr('aria-expanded'), 'false');
    assert.equal(h.$('#mainwp-onboarding-advanced').prop('hidden'), true);
});

test('summary update click uses the single-site preparation, confirmation and backup chain', () => {
    const h = harness();
    let confirmation;
    let backupSites;
    let upgradedSites;
    h.context.mainwp_confirm = (message, callback) => { confirmation = { message, callback }; };
    h.context.mainwp_updatesoverview_checkBackups = sites => { backupSites = Array.from(sites); };
    h.context.updatesoverview_update_popup_init = () => {};
    h.context.updatesoverview_upgrade_all_int = sites => { upgradedSites = Array.from(sites); };
    const item = h.$('plugin-row').attr('site_id', '42').attr('site_name', 'Example').attr('plugin_slug', 'example/plugin.php');
    h.$('#wp_plugin_upgrades').find = () => [item];
    h.$.ajax = options => {
        h.requests.push(options.data);
        options.success('<prepared update rows>');
    };
    h.$('#mainwp-onboarding-update-all').attr('data-siteid', '42').attr('data-confirm', 'Update <Example> now? This installs 1 plugin update.');
    const start = updates.indexOf('const updatesoverview_global_upgrade_all =');
    const end = updates.indexOf('/* eslint-enable complexity */', start);
    assert.ok(start >= 0 && end > start);
    vm.runInContext('let updatesoverviewContinueAfterBackup;\n' + updates.slice(start, end), h.context);
    vm.runInContext(setup, h.context);
    h.handlers.get('#mainwp-onboarding-update-all').call(h.$('#mainwp-onboarding-update-all'));
    assert.equal(h.requests[0].action, 'mainwp_overview_prepare_upgradeall');
    assert.equal(h.requests[0].site_id, 42);
    assert.match(confirmation.message, /1 plugin update/);
    assert.match(confirmation.message, /&lt;Example&gt;/);
    assert.equal(backupSites, undefined);
    assert.equal(upgradedSites, undefined);
    confirmation.callback();
    assert.deepEqual(backupSites, ['42']);
    assert.equal(upgradedSites, undefined);
    vm.runInContext('updatesoverviewContinueAfterBackup()', h.context);
    assert.deepEqual(upgradedSites, ['42']);
});


test('wizard auth starts with password despite saved state; Advanced can still switch it off', () => {
    const php = fs.readFileSync(require.resolve('../../pages/page-mainwp-manage-sites.php'), 'utf8');
    const start = php.indexOf('public static function render_add_site_scripts()');
    const script = php.slice(start).match(/<script[^>]*>([\s\S]*?)<\/script>/)[1];
    for (const wizard of [true, false]) {
        const h = harness();
        h.$('#mainwp_connect_first_site_form').length = wizard ? 1 : 0;
        h.context.mainwp_ui_state_init = (key, callback) => callback(key === 'addsite-uniqueid' ? '2' : '0');
        const saved = [];
        h.context.mainwp_ui_state_save = (key, value) => saved.push([key, value]);
        vm.runInContext(script, h.context);
        assert.equal(h.$('#mainwp-administrator-password-field').prop('visible'), wizard);
        assert.equal(h.$('#mainwp-unique-security-id-field').prop('visible'), !wizard);
        if (wizard) {
            h.$('#addsite-adminpwd').checkbox('set unchecked');
            assert.equal(h.$('#mainwp-administrator-password-field').prop('visible'), false);
            assert.deepEqual(saved.at(-1), ['addsite-adminpwd', 0]);
        }
    }
});

test('summary uptime format preserves positional placeholders', () => {
    const { execFileSync } = require('node:child_process');
    const wizard = fs.readFileSync(require.resolve('../../pages/page-mainwp-setup-wizard.php'), 'utf8');
    const match = wizard.match(/esc_html__\(\s*((?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'))\s*,\s*'mainwp'\s*\), esc_html\( \$email\['recipients'\] \)/);
    assert.ok(match, 'Find the translated uptime format used by the summary.');
    const php = `set_error_handler(function($level, $message) { throw new RuntimeException($message); }); echo sprintf(${match[1]}, 'alerts@example.org', 'Onboarding');`;
    const result = execFileSync('php', ['-r', php], { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
    assert.equal(result, "Uptime monitoring is on. We'll email alerts@example.org if Onboarding goes down.");
});

test('first wizard exits keep their requested destinations instead of activation redirects', () => {
    const { execFileSync } = require('node:child_process');
    const path = require('node:path');
    for (const step of ['welcome', 'connect_first_site', 'next_steps', 'add_client']) {
        for (const destination of [
            { page: 'managesites', updateid: 42 },
            { page: 'mainwp_tab' },
            { page: 'managesites', do: 'new' },
            { page: 'mainwp-mcp' },
            { page: 'Settings', tab: 'monitoring' }
        ]) {
            const input = { root: path.resolve(__dirname, '../..'), step, destination, quick_setup: step !== 'welcome' };
            const output = JSON.parse(execFileSync('php', [require.resolve('./fixtures/php-onboarding-redirects.inc')], { input: JSON.stringify(input), encoding: 'utf8' }));
            assert.equal(output.enqueued, true);
            assert.deepEqual(output.redirects, [], `${step} to ${JSON.stringify(destination)} must not bounce`);
            assert.equal(output.options.mainwp_activated, undefined);
            assert.equal(output.options.mainwp_run_quick_setup, undefined);
        }
    }
});

test('ordinary admin requests do not consume wizard entry redirects', () => {
    const { execFileSync } = require('node:child_process');
    const path = require('node:path');
    const input = { root: path.resolve(__dirname, '../..'), entry_page: 'Settings', step: 'welcome', quick_setup: true };
    const output = JSON.parse(execFileSync('php', [require.resolve('./fixtures/php-onboarding-redirects.inc')], { input: JSON.stringify(input), encoding: 'utf8' }));
    assert.equal(output.enqueued, false);
    assert.equal(output.options.mainwp_activated, 'yes');
    assert.equal(output.options.mainwp_run_quick_setup, 'yes');
});

for (const prefix of ['mainwp-onboarding', 'mainwp-onboarding-csv']) {
    test(`${prefix} password explanation opens on hover, focus and click and closes on blur`, () => {
        const h = harness();
        const selector = `#${prefix}-password-help`;
        const help = h.$(selector);
        const popup = { visible: false, options: undefined };
        help.popup = action => {
            if (typeof action === 'object') popup.options = action;
            else popup.visible = action === 'show';
            return help;
        };
        vm.runInContext(setup, h.context);
        assert.equal(popup.options?.on, 'hover');
        assert.equal(popup.options?.popup, `#${prefix}-password-explanation`);
        assert.equal(popup.options?.position, 'top center');
        h.handlers.get(selector + ':focus').call(help);
        assert.equal(popup.visible, true);
        h.handlers.get(selector + ':blur').call(help);
        assert.equal(popup.visible, false);
        let prevented = false;
        h.handlers.get(selector + ':click').call(help, { preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(popup.visible, true);
    });
}

test('choosing a connection mode keeps Skip for now available', () => {
    const h = harness();
    h.$('#mainwp_addsite_continue_button').show();
    vm.runInContext(setup, h.context);
    for (const value of ['single-site', 'multiple-site']) {
        h.context.connectionTab = h.$('connection-tab').attr('data-tab', value);
        vm.runInContext('mainwp_menu_connect_first_site_onvisible_callback(connectionTab)', h.context);
        assert.equal(h.$('#mainwp_addsite_continue_button').prop('visible'), true);
        assert.equal(h.$('#mainwp_managesites_add').prop('visible'), value === 'single-site');
        assert.equal(h.$('#mainwp_managesites_add_import').prop('visible'), value === 'multiple-site');
    }
});


test('loaded summary starts the icon request with its site ID and action nonce', () => {
    const h = harness();
    h.$('#mainwp-onboarding-site-icon').length = 1;
    h.$('#mainwp-onboarding-site-icon').attr('data-siteid', '42').attr('data-security', 'icon-nonce');
    vm.runInContext(setup, h.context);
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].action, 'mainwp_get_site_icon');
    assert.equal(h.requests[0].siteId, 42);
    assert.equal(h.requests[0].security, 'icon-nonce');
    assert.deepEqual(h.reloads, []);
});

test('Add New defers icons and fetches after success without waiting or replacing custom icons', () => {
    const source = fs.readFileSync(require.resolve('../../assets/js/mainwp.js'), 'utf8');
    const start = source.indexOf('let mainwp_managesites_add = function');
    const end = source.indexOf('let mainwp_managesites_add_valid', start);
    for (const custom of ['', 'uploaded', 'selected']) {
        const h = harness();
        const values = ['wpurl', 'wpadmin', 'admin_pwd', 'http_user', 'http_pass', 'wpname'];
        for (const field of values) h.$('#mainwp_managesites_add_' + field).val('fixture');
        h.$('#mainwp_managesites_add_site_uploaded_icon_hidden').val(custom === 'uploaded' ? 'custom.png' : '');
        h.$('#mainwp_managesites_add_site_select_icon_hidden').val(custom === 'selected' ? 'star' : '');
        let extensionSync;
        Object.assign(h.context, {
            managesites_init() {}, mainwp_managesites_add_valid: () => true,
            feedback() {}, mainwp_feedback_linked_message: () => '',
            mainwp_managesites_sync_extension_start_next: site => { extensionSync = site; }
        });
        vm.runInContext(source.slice(start, end), h.context);
        vm.runInContext('mainwp_managesites_add()', h.context);
        assert.equal(h.requests[0].action, 'mainwp_checkwp');
        assert.equal(h.requests[1].action, 'mainwp_addwp');
        assert.equal(h.requests[1].defer_site_icon, true);
        assert.equal(h.requests.length, custom ? 2 : 3);
        if (!custom) {
            assert.equal(h.requests[2].action, 'mainwp_get_site_icon');
            assert.equal(h.requests[2].siteId, 42);
        }
        // The icon request receives no callback; normal success work still finishes.
        assert.equal(extensionSync, 42);
        assert.equal(h.$('#mainwp_managesites_add_wpurl').val(), '');
        assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    }
});

test('summary Turn off reloads only on success and reports rejected or failed requests', () => {
    for (const result of ['success', 'rejected', 'network']) {
        const h = harness();
        const link = h.$('#mainwp-onboarding-disable-monitoring').attr('data-security', 'off-nonce').attr('data-error', 'Try again.');
        h.$.post = (url, data, callback) => {
            h.requests.push(data);
            if (result !== 'network') callback({ success: result === 'success', data: { message: '<denied>' } });
            return { fail(handler) { if (result === 'network') handler(); } };
        };
        vm.runInContext(setup, h.context);
        let prevented = false;
        h.handlers.get('#mainwp-onboarding-disable-monitoring').call(link, { preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(h.requests[0].action, 'mainwp_onboarding_disable_monitoring');
        assert.equal(h.requests[0].security, 'off-nonce');
        assert.equal(h.requests[0]['mainwp-guided-tours-option'], undefined);
        assert.equal(h.reloads.length, result === 'success' ? 1 : 0);
        if (result !== 'success') assert.equal(h.messages.at(-1)[1], result === 'network' ? 'Try again.' : '&lt;denied&gt;');
    }
});

test('completed update modal reloads the summary once after success or closing partial results', () => {
    const initStart = updates.indexOf('let updatesoverview_update_popup_init =');
    const initEnd = updates.indexOf('let updatesmanage_link_to_site', initStart);
    const doneStart = updates.indexOf('let updatesoverview_upgrade_all_update_done =');
    const doneEnd = updates.indexOf('/* eslint-disable complexity */', doneStart);
    const popupSource = fs.readFileSync(require.resolve('../../assets/js/mainwp-popup.js'), 'utf8');
    const hideBody = popupSource.match(/onHideModal: function \(\) \{([\s\S]*?)\n                \},/)[1];
    const closeBody = popupSource.match(/close: function \(execCallback\) \{([\s\S]*?)\n                \},/)[1];
    for (const errors of [0, 1]) {
        const h = harness();
        let modal;
        const popup = {
            init(settings) { this.actionsCloseCallback = settings.callback; },
            setProgressSite() {}, updateStatusLabel() {},
            closePopup() { modal.onHide(); }
        };
        vm.runInContext('globalThis.hidePopup = function () {' + hideBody + '}; globalThis.closePopup = function (execCallback) {' + closeBody + '};', h.context);
        popup.close = h.context.closePopup;
        popup.onHideModal = h.context.hidePopup;
        modal = { onHide: () => popup.onHideModal() };
        h.context.mainwpPopup = () => popup;
        h.$.extend = Object.assign;
        Object.assign(h.context.mainwpVars, { bulkTaskRunning: true, currentThreads: 1, websitesDone: 0, websitesTotal: 1, errorCount: errors });
        vm.runInContext(updates.slice(initStart, initEnd) + updates.slice(doneStart, doneEnd), h.context);
        vm.runInContext('updatesoverview_update_popup_init();', h.context);
        assert.equal(h.reloads.length, 0);
        vm.runInContext('updatesoverview_upgrade_all_update_done();', h.context);
        assert.equal(h.context.mainwpVars.bulkTaskRunning, false);
        assert.equal(h.reloads.length, errors ? 0 : 1);
        if (errors) popup.close(true);
        assert.deepEqual(h.reloads, [undefined], 'Reload the current summary URL, retaining siteid.');
    }
});
