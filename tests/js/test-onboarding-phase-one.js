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
                change() { return el; },
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
        callback(data.action === 'mainwp_checkwp' ? { response: 'OK' } : { response: 'Connected', siteid: 42 });
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
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[0].action, 'mainwp_checkwp');
    assert.equal(h.requests[1].action, 'mainwp_addwp');
    assert.equal(h.requests[1].managesites_add_wpname, 'example.org');
    assert.equal(h.requests[1].qsw_page, true);
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
    assert.equal(h.logs[0].code, 'ERR_INVALID_URL');
});

test('a title filled from an invalid URL follows the corrected URL until the user types one', () => {
    const h = harness();
    h.$('#mainwp_managesites_add_wpurl').val('exampel org');
    h.$('#mainwp_managesites_add_wpurl_protocol').val('https');
    h.$('#mainwp_managesites_add_wpadmin').val('admin');
    vm.runInContext(setup, h.context);
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests.length, 0);
    h.$('#mainwp_managesites_add_wpurl').val('example.org');
    h.handlers.get('#mainwp_managesites_add_wpurl')();
    assert.equal(h.$('#mainwp_managesites_add_wpname').val(), 'example.org');
    h.$('#mainwp_managesites_add_wpname').val('Client Site');
    h.handlers.get('#mainwp_managesites_add_wpname').call(h.$('#mainwp_managesites_add_wpname'));
    h.$('#mainwp_managesites_add_wpurl').val('client.example.org');
    h.handlers.get('#mainwp_managesites_add_wpurl')();
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests[1].managesites_add_wpname, 'Client Site');
});

test('a title filled from the URL keeps no trailing slash when a failed attempt is retried', () => {
    const h = harness();
    h.$('#mainwp_managesites_add_wpurl').val('example.org');
    h.$('#mainwp_managesites_add_wpurl_protocol').val('https');
    vm.runInContext(setup, h.context);
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests.length, 0);
    assert.equal(h.$('#mainwp_managesites_add_wpurl').val(), 'example.org/');
    h.$('#mainwp_managesites_add_wpadmin').val('admin');
    h.handlers.get('#mainwp_managesites_add')();
    assert.equal(h.requests[1].managesites_add_wpname, 'example.org');
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

test('password explanation opens on hover and keyboard focus and closes on blur', () => {
    const h = harness();
    const help = h.$('#mainwp-onboarding-password-help');
    const popup = { visible: false, options: undefined };
    help.popup = action => {
        if (typeof action === 'object') popup.options = action;
        else popup.visible = action === 'show';
        return help;
    };
    vm.runInContext(setup, h.context);
    assert.equal(popup.options?.on, 'hover');
    assert.equal(popup.options?.popup, '#mainwp-onboarding-password-explanation');
    h.handlers.get('#mainwp-onboarding-password-help:focus').call(help);
    assert.equal(popup.visible, true);
    h.handlers.get('#mainwp-onboarding-password-help:blur').call(help);
    assert.equal(popup.visible, false);
});
