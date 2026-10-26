'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require.resolve('../../assets/js/mainwp-setup.js'), 'utf8');

function load(extra = {}) {
    const context = vm.createContext({ jQuery() {}, URL, console, ...extra });
    vm.runInContext(source, context);
    return context;
}

function machine() {
    const context = load();
    let now = 1000;
    let hidden = false;
    let ready = true;
    let nextId = 1;
    const timers = new Map();
    const requests = [];
    const renders = [];
    const controller = context.mainwp_onboarding_detection_controller({
        now: () => now, hidden: () => hidden, ready: () => ready,
        render: state => renders.push({ ...state }),
        setTimeout(callback, delay) { const id = nextId++; timers.set(id, { callback, at: now + delay }); return id; },
        clearTimeout: id => timers.delete(id),
        request(callback) {
            const request = { callback, aborted: false, abort() { request.aborted = true; } };
            requests.push(request);
            return request;
        }
    });
    function advance(ms) {
        const end = now + ms;
        while (true) {
            const entry = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!entry) break;
            timers.delete(entry[0]);
            now = entry[1].at;
            entry[1].callback();
        }
        now = end;
    }
    return { context, controller, requests, renders, timers, advance,
        reply: result => requests.at(-1).callback(result),
        hidden: value => { hidden = value; }, ready: value => { ready = value; },
        now: () => now };
}

test('detection maps reachable, missing Child, success and non-retryable failures', () => {
    const c = load();
    const initial = c.mainwp_onboarding_initial_state(1000);
    for (const [result, expected] of [
        [{ response: 'NOMAINWP', failure_ux: '<p>Install Child</p>' }, ['waiting', 'ok', 'pending']],
        [{ response: 'OK' }, ['detected', 'ok', 'ok']],
        [{ response: 'HTTPERROR' }, ['unreachable', 'failed', 'pending']],
        [{ response: 'TIMEOUT' }, ['unreachable', 'failed', 'pending']],
        [{ response: 'INVALID_URL' }, ['unreachable', 'failed', 'pending']],
        [{ error: 'Invalid URL! Please enter valid URL to the Site URL field.' }, ['unreachable', 'failed', 'pending']],
        [{ response: 'ERROR Site is already connected to your MainWP Dashboard.' }, ['error', 'pending', 'pending']],
        [{ response: 'ERROR blocked', failure_ux: '<p>Specific error</p>' }, ['error', 'pending', 'pending']],
        [null, ['error', 'pending', 'pending']]
    ]) {
        const state = c.mainwp_onboarding_detection_result(initial, result, 1000);
        assert.deepEqual([state.status, state.reachable, state.child], expected);
        assert.equal(initial.status, 'idle', 'Transitions do not mutate the previous snapshot.');
    }
});

test('only a detected Child with a reported passed deadline followed by NOMAINWP suggests expiry', () => {
    const c = load();
    const initial = c.mainwp_onboarding_initial_state(1000);
    const detected = c.mainwp_onboarding_detection_result(initial, { response: 'OK', connection_window_minutes: 20, connection_window_remaining: 10 }, 1000);
    assert.equal(detected.deadline, 11000);
    assert.equal(c.mainwp_onboarding_detection_result(detected, { response: 'NOMAINWP' }, 10999).status, 'waiting');
    assert.equal(c.mainwp_onboarding_detection_result(detected, { response: 'NOMAINWP' }, 11000).status, 'expired');
    assert.equal(c.mainwp_onboarding_detection_result(detected, { response: 'NOMAINWP' }, 11001).status, 'expired');
    for (const response of ['TIMEOUT', 'HTTPERROR', 'ERROR Password incorrect', 'ERROR already connected']) {
        assert.notEqual(c.mainwp_onboarding_detection_result(detected, { response }, 50000).status, 'expired');
    }
    assert.equal(detected.status, 'detected', 'Passing time alone cannot claim expiry.');
    assert.equal(c.mainwp_onboarding_detection_result(initial, { response: 'NOMAINWP' }, 50000).status, 'waiting');
    for (const fields of [{}, { connection_window_minutes: 0, connection_window_remaining: 10 }, { connection_window_remaining: '10' }, { connection_window_remaining: -1 }, { connection_window_minutes: 20 }]) {
        const state = c.mainwp_onboarding_detection_result(initial, { response: 'OK', ...fields }, 1000);
        assert.equal(state.deadline, null);
        assert.equal(c.mainwp_onboarding_detection_result(state, { response: 'NOMAINWP' }, 9999999).status, 'waiting');
    }
});

test('install URL uses validated HTTP(S) site URL and preserves WordPress subdirectories', () => {
    const c = load();
    const suffix = 'wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term';
    assert.equal(c.mainwp_onboarding_install_url('https://example.org/'), 'https://example.org/' + suffix);
    assert.equal(c.mainwp_onboarding_install_url('https://example.org/wordpress/?x=bad#fragment'), 'https://example.org/wordpress/' + suffix);
    assert.equal(c.mainwp_onboarding_site_url('http://example.org/subdir', 'https'), 'http://example.org/subdir/');
    assert.equal(c.mainwp_onboarding_site_url('example.org', 'https'), 'https://example.org/');
    for (const url of ['javascript:alert(1)', 'ftp://example.org', 'https://user:pass@example.org/', 'https://example.org:21/', 'https://example.org:22/', 'https://example.org/" onmouseover="alert(1)', 'not a URL', '']) {
        const value = c.mainwp_onboarding_install_url(url);
        if (url.includes('onmouseover')) assert.ok(!value || !value.includes('"'), 'URL serialization keeps attributes inert.');
        else assert.equal(value, null, url);
    }
});

test('polls only after NOMAINWP at five seconds, stops on detection and never overlaps requests', () => {
    const h = machine();
    h.controller.restart();
    assert.equal(h.requests.length, 1);
    h.advance(15000);
    assert.equal(h.requests.length, 1, 'No next poll while a request is pending.');
    h.reply({ response: 'NOMAINWP' });
    h.advance(4999);
    assert.equal(h.requests.length, 1);
    h.advance(1);
    assert.equal(h.requests.length, 2);
    h.reply({ response: 'OK' });
    h.advance(1200000);
    assert.equal(h.requests.length, 2);
    assert.equal(h.controller.state().status, 'detected');
    assert.equal(h.timers.size, 0);
});

test('each non-retryable failure cancels the chain; incomplete fields never request', () => {
    for (const result of [{ response: 'HTTPERROR' }, { response: 'TIMEOUT' }, { response: 'ERROR already connected' }, { response: 'INVALID_URL' }]) {
        const h = machine();
        h.controller.restart();
        h.reply(result);
        h.advance(1200000);
        assert.equal(h.requests.length, 1);
        assert.equal(h.timers.size, 0);
    }
    const h = machine();
    h.ready(false);
    h.controller.restart();
    assert.equal(h.controller.state().status, 'idle');
    assert.equal(h.requests.length, 0);
});

test('edits and explicit stops abort in-flight work, discard stale replies and reset the target deadline', () => {
    const h = machine();
    h.controller.restart();
    const old = h.requests[0];
    h.controller.stop('idle');
    assert.equal(old.aborted, true);
    old.callback({ response: 'OK', connection_window_remaining: 1 });
    assert.equal(h.controller.state().status, 'idle');
    h.controller.restart();
    h.reply({ response: 'OK', connection_window_remaining: 1 });
    assert.equal(h.controller.state().deadline, 2000);
    h.controller.stop('idle');
    assert.equal(h.controller.state().deadline, null);
    h.controller.restart();
    const last = h.requests.at(-1);
    h.controller.stop('stopped');
    assert.equal(last.aborted, true);
    last.callback({ response: 'NOMAINWP' });
    h.advance(1200000);
    assert.equal(h.controller.state().status, 'stopped');
    assert.equal(h.timers.size, 0);
});

test('twenty-minute wall clock budget aborts in-flight work and also bounds repeated missing-Child polls', () => {
    const h = machine();
    h.controller.restart();
    h.advance(1199999);
    assert.equal(h.controller.state().status, 'checking');
    h.advance(1);
    assert.equal(h.controller.state().status, 'timed_out');
    assert.equal(h.requests[0].aborted, true);
    h.reply({ response: 'OK' });
    assert.equal(h.controller.state().status, 'timed_out');
    const p = machine();
    p.controller.restart();
    for (let i = 0; i < 240; i++) {
        p.reply({ response: 'NOMAINWP' });
        p.advance(5000);
    }
    assert.equal(p.requests.length, 240);
    assert.equal(p.controller.state().status, 'timed_out');
    assert.equal(p.timers.size, 0);
});

test('hidden tabs abort requests and pause polls, resume once, and retain the wall-clock cap', () => {
    const h = machine();
    h.hidden(true);
    h.controller.restart();
    assert.equal(h.requests.length, 0);
    h.advance(5000);
    h.hidden(false);
    h.controller.resume();
    assert.equal(h.requests.length, 1);
    h.hidden(true);
    h.controller.pause();
    assert.equal(h.requests[0].aborted, true);
    h.reply({ response: 'OK' });
    assert.notEqual(h.controller.state().status, 'detected');
    h.advance(5000);
    h.hidden(false);
    h.controller.resume();
    h.controller.resume();
    assert.equal(h.requests.length, 2);
    h.reply({ response: 'NOMAINWP' });
    h.hidden(true);
    h.controller.pause();
    h.advance(1200000);
    h.hidden(false);
    h.controller.resume();
    assert.equal(h.controller.state().status, 'timed_out');
    assert.equal(h.requests.length, 2);
});

test('connection failure recheck suggests expiry only after a reported deadline and NOMAINWP, and remains cancellable', () => {
    const h = machine();
    h.controller.restart();
    h.reply({ response: 'OK', connection_window_remaining: 10 });
    h.controller.stop('connecting');
    h.controller.checkExpiry();
    assert.equal(h.requests.length, 1);
    h.advance(10000);
    h.controller.stop('stopped');
    h.controller.checkExpiry();
    h.reply({ response: 'TIMEOUT' });
    assert.equal(h.controller.state().status, 'stopped');
    h.controller.checkExpiry();
    h.reply({ response: 'NOMAINWP' });
    assert.equal(h.controller.state().status, 'expired');
    h.controller.restart();
    assert.equal(h.controller.state().deadline, null);
    h.reply({ response: 'OK', connection_window_remaining: 0 });
    h.controller.checkExpiry();
    const pending = h.requests.at(-1);
    h.controller.stop('stopped');
    assert.equal(pending.aborted, true);
    pending.callback({ response: 'NOMAINWP' });
    assert.equal(h.controller.state().status, 'stopped');
});

test('celebration keyframes load on Overview and wizard and respect reduced motion', () => {
    const css = fs.readFileSync(require.resolve('../../assets/css/mainwp-onboarding.css'), 'utf8');
    assert.match(css, /@keyframes tadaaaa/);
    assert.match(css, /animation: tadaaaa 0\.6s ease-in-out 1 forwards/);
    assert.match(css, /prefers-reduced-motion: reduce[\s\S]*?\.tadaaaa\s*{\s*animation: none/);
    for (const file of ['page-mainwp-overview.php', 'page-mainwp-setup-wizard.php']) {
        const php = fs.readFileSync(require.resolve('../../pages/' + file), 'utf8');
        assert.match(php, /wp_enqueue_style\( 'mainwp-onboarding'.*mainwp-onboarding\.css/);
        assert.doesNotMatch(php, /@keyframes tadaaaa/);
    }
});

function uiHarness(mode = 'single-site') {
    let now = 1000;
    let nextId = 1;
    const timers = new Map();
    const elements = new Map();
    const events = new Map();
    const requests = [];
    const messages = [];
    const textWrites = [];
    const readyCallbacks = [];
    const document = { hidden: false };
    const window = {};
    const copy = {
        checking: 'Checking...', yes: 'Yes', notYet: 'Not yet', no: 'No', notChecked: 'Not checked',
        unreachable: "We can't reach %s. Check the URL and that the site is online.",
        install: 'Install MainWP Child on %s →',
        fallback: "If the link doesn't open the plugin installer, install MainWP Child from Plugins > Add New on %s.",
        remaining: 'About %d minutes left to connect.', remainingOne: 'About 1 minute left to connect.', remainingLess: 'Less than a minute left to connect.', defaultTime: 'By default this is 20 minutes after activation.',
        windowClosed: 'The connection window may have ended. If connecting fails, check that MainWP Child is still active.',
        expired: 'MainWP Child may have switched itself off because the connection window expired. Check that it is active on %s, reactivate it if needed, and click Retry.',
        error: 'Undefined error occurred. Please try again.', password: 'Please enter the administrator password.'
    };
    function trigger(selector, event) {
        for (const callback of events.get(selector + ':' + event) || []) callback.call($(selector));
    }
    function $(selector) {
        if (typeof selector === 'function') { readyCallbacks.push(selector); return; }
        if (selector?.attr) return selector;
        const key = selector === document ? 'document' : selector === window ? 'window' : selector;
        if (!elements.has(key)) {
            const attributes = {};
            let text = '';
            const el = {
                length: selector === '#mainwp-onboarding-detection' ? 1 : 0,
                attr(name, value) { if (value === undefined) return attributes[name]; attributes[name] = value; return el; },
                prop(name, value) { return el.attr(name, value); },
                val(value) { if (value === undefined) return attributes.value || (String(key).includes('qsw_nonce') ? 'test-nonce' : ''); attributes.value = value; return el; },
                on(names, target, callback) {
                    const selected = typeof target === 'string' ? target : key;
                    for (const part of selected.split(',').map(s => s.trim())) {
                        for (const event of names.split(' ')) {
                            const id = part + ':' + event;
                            events.set(id, [...(events.get(id) || []), callback || target]);
                        }
                    }
                    return el;
                },
                change(callback) { return el.on('change', callback); },
                trigger(event) { trigger(key, event); return el; },
                text(value) { if (value === undefined) return text; textWrites.push({ selector: key, value }); text = String(value); attributes.html = undefined; return el; },
                html(value) { if (value === undefined) return attributes.html ?? text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); attributes.html = value; return el; },
                empty() { text = ''; delete attributes.html; return el; },
                toggleClass(name, enabled) { attributes[name] = enabled; return el; },
                toggle(visible) { attributes.visible = visible; return el; },
                show() { return el.toggle(true); }, hide() { return el.toggle(false); },
                popup() { return el; }, checkbox() { return el; }, dropdown() { return el; },
                each() { return el; }
            };
            elements.set(key, el);
        }
        return elements.get(key);
    }
    $.ajax = options => {
        const request = { options, aborted: false, abort() { request.aborted = true; options.error?.({}, 'abort'); } };
        requests.push(request);
        return request;
    };
    // Browsers throw "Illegal invocation" when timer functions run with a receiver other than the window.
    const browserTimer = fn => function (...args) {
        if (this && typeof this === 'object' && !('document' in this)) throw new TypeError('Illegal invocation');
        return fn(...args);
    };
    const schedule = (callback, delay, interval = false) => {
        const id = nextId++;
        timers.set(id, { callback, at: now + delay, interval: interval ? delay : 0 });
        return id;
    };
    $('#mainwp_managesites_add_wpurl_protocol').val('https');
    $('#mainwp-administrator-password-checkbox-field').prop('checked', true);
    $('#mainwp-qsw-connect-site-form').attr('data-mode', mode);
    $('.menu-connect-first-site .active.item').attr('data-tab', mode);
    const context = load({
        jQuery: $, document, window, mainwpSetupLocalize: copy, Date: { now: () => now }, ajaxurl: '/admin-ajax.php',
        setTimeout: browserTimer((callback, delay) => schedule(callback, delay)), clearTimeout: browserTimer(id => timers.delete(id)),
        setInterval: browserTimer((callback, delay) => schedule(callback, delay, true)), clearInterval: browserTimer(id => timers.delete(id)),
        mainwp_set_message_zone: (...args) => messages.push(args), mainwp_forceReload() {}
    });
    readyCallbacks.forEach(callback => callback());
    function advance(ms) {
        const end = now + ms;
        while (true) {
            const entry = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!entry) break;
            const [id, timer] = entry;
            now = timer.at;
            if (timer.interval) timer.at += timer.interval;
            else timers.delete(id);
            timer.callback();
        }
        now = end;
    }
    return { $, context, requests, messages, textWrites, timers, advance, document,
        trigger: (selector, event) => trigger(selector, event),
        reply: result => requests.at(-1).options.success(result),
        fill() {
            $('#mainwp_managesites_add_wpurl').val('example.org/wordpress');
            $('#mainwp_managesites_add_wpadmin').val('admin');
            trigger('#mainwp_managesites_add_wpadmin', 'input');
        },
        visible(hidden) { document.hidden = hidden; trigger('document', 'visibilitychange'); }
    };
}

test('wizard fields debounce detection, render install guidance, and enable Connect independently of pre-check', () => {
    const h = uiHarness();
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), true);
    assert.equal(h.$('#mainwp-onboarding-detection').prop('hidden'), true);
    h.fill();
    assert.equal(h.$('#mainwp-onboarding-detection').prop('hidden'), false);
    h.advance(399);
    assert.equal(h.requests.length, 0);
    h.advance(1);
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.data.action, 'mainwp_checkwp');
    assert.equal(h.requests[0].options.timeout, 20000);
    assert.equal(h.requests[0].options.data.security, 'test-nonce');
    assert.equal(h.requests[0].options.data.url, 'https://example.org/wordpress/');
    assert.equal(h.requests[0].options.data.admin, 'admin');
    h.reply({ response: 'NOMAINWP', failure_ux: '<p>Legacy missing-Child message</p>' });
    assert.equal(h.$('#mainwp-onboarding-reachable').text(), 'Yes');
    assert.equal(h.$('#mainwp-onboarding-child').text(), 'Not yet');
    assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-install-link').attr('href'), 'https://example.org/wordpress/wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term');
    assert.match(h.$('#mainwp-onboarding-install-fallback').text(), /Plugins > Add New on https:\/\/example.org\/wordpress\//);
    h.$('#mainwp_managesites_add_admin_pwd').val('password');
    h.trigger('#mainwp_managesites_add_admin_pwd', 'input');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    h.advance(5000);
    assert.equal(h.requests.length, 2);
    assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-install-link').attr('href'), 'https://example.org/wordpress/wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term');
    h.reply({ response: 'HTTPERROR', failure_ux: '<p>Existing transport advice</p>' });
    assert.equal(h.$('#mainwp-onboarding-reachable').text(), 'No');
    assert.equal(h.$('#mainwp-onboarding-detection-message').text(), "We can't reach https://example.org/wordpress/. Check the URL and that the site is online.");
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    h.trigger('#mainwp_managesites_add', 'click');
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_addwp');
    assert.equal(Object.hasOwn(h.requests.at(-1).options, 'timeout'), false);
    assert.equal(h.requests.at(-1).options.data.managesites_add_adminpwd, 'password');
    h.reply({ response: 'ERROR Bad password', failure_ux: '<p>Specific authentication error</p>' });
    assert.equal(h.messages.at(-1)[1], '<p>Specific authentication error</p>');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
});

test('detected countdown pauses rendering in hidden tabs, catches up on return, and checks expiry without assuming it', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 61 });
    assert.equal(h.$('#mainwp-onboarding-timer').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'About 2 minutes left to connect.');
    h.advance(1000);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'About 1 minute left to connect.');
    h.visible(true);
    h.advance(60000);
    assert.equal(h.requests.length, 1);
    h.visible(false);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'The connection window may have ended. If connecting fails, check that MainWP Child is still active.');
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_checkwp');
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    h.reply({ response: 'OK' });
    assert.equal(vm.runInContext('mainwp_onboarding_detection.state().status', h.context), 'detected');
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    h.advance(7200000);
    assert.equal(h.requests.length, 2);
    assert.equal(h.timers.size, 0);
});

test('visible countdown checks once at the deadline and NOMAINWP shows expiry recovery', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 1 });
    h.advance(999);
    assert.equal(h.requests.length, 1);
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    h.advance(1);
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_checkwp');
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'The connection window may have ended. If connecting fails, check that MainWP Child is still active.');
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    h.reply({ response: 'NOMAINWP' });
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-retry').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-plugins-link').attr('href'), 'https://example.org/wordpress/wp-admin/plugins.php');
    h.advance(7200000);
    assert.equal(h.requests.length, 2);
    assert.equal(h.timers.size, 0);
});

test('countdown writes its live region only when the displayed text changes', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_remaining: 120 });
    const writes = () => h.textWrites.filter(write => write.selector === '#mainwp-onboarding-time-left').length;
    const initial = writes();
    h.advance(59000);
    assert.equal(writes(), initial);
    h.advance(1000);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'About 1 minute left to connect.');
    assert.equal(writes(), initial + 1);
    h.trigger('window', 'pagehide');
    assert.equal(h.timers.size, 0);
});

test('empty fields remain idle after a hidden tab exceeds the polling cap and filling them starts detection', () => {
    const h = uiHarness();
    h.visible(true);
    h.advance(1200001);
    h.visible(false);
    assert.equal(vm.runInContext('mainwp_onboarding_detection.state().status', h.context), 'idle');
    assert.equal(h.$('#mainwp-onboarding-detection').prop('hidden'), true);
    assert.equal(h.$('#mainwp-onboarding-retry').prop('hidden'), true);
    assert.equal(h.requests.length, 0);
    assert.equal(h.timers.size, 0);
    h.fill();
    assert.equal(h.$('#mainwp-onboarding-detection').prop('hidden'), false);
    h.advance(400);
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.data.action, 'mainwp_checkwp');
    h.trigger('window', 'pagehide');
    assert.equal(h.timers.size, 0);
});

test('older Child default explanation and disabled timer are distinct from reported countdowns', () => {
    for (const [fields, expected] of [[{}, 'By default this is 20 minutes after activation.'], [{ connection_window_minutes: 0 }, '']]) {
        const h = uiHarness();
        h.fill(); h.advance(400);
        h.reply({ response: 'OK', ...fields });
        assert.equal(h.$('#mainwp-onboarding-time-left').text(), expected);
        assert.equal(h.$('#mainwp-onboarding-timer').prop('hidden'), fields.connection_window_minutes === 0);
        assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
        assert.equal(h.timers.size, 0);
    }
});

test('connection error after the deadline rechecks Child, offers expiry recovery and Retry detects reactivation', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_remaining: 1 });
    h.advance(1000);
    h.$('#mainwp_managesites_add_admin_pwd').val('password');
    h.trigger('#mainwp_managesites_add_admin_pwd', 'input');
    h.trigger('#mainwp_managesites_add', 'click');
    h.reply({ response: 'ERROR MainWP Child not detected', failure_ux: '<p>Child not detected</p>' });
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_checkwp');
    h.reply({ response: 'NOMAINWP' });
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), false);
    assert.deepEqual(h.messages.at(-1), ['#mainwp-message-zone']);
    assert.match(h.$('#mainwp-onboarding-expiry-message').text(), /may have switched itself off/);
    assert.equal(h.$('#mainwp-onboarding-plugins-link').attr('href'), 'https://example.org/wordpress/wp-admin/plugins.php');
    assert.equal(h.$('#mainwp-onboarding-retry').prop('hidden'), false);
    h.trigger('#mainwp-onboarding-retry', 'click');
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    h.reply({ response: 'OK', connection_window_remaining: 1200 });
    assert.equal(h.$('#mainwp-onboarding-child').text(), 'Yes');
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'About 20 minutes left to connect.');
});

test('field edits abort live detection immediately, debounce once and page exit cancels pending work', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    const old = h.requests[0];
    h.$('#mainwp_managesites_add_wpurl').val('other.example');
    h.trigger('#mainwp_managesites_add_wpurl', 'input');
    assert.equal(old.aborted, true);
    old.options.success({ response: 'OK', connection_window_remaining: 1200 });
    assert.equal(h.$('#mainwp-onboarding-child').text(), 'Checking...');
    h.advance(200);
    h.trigger('#mainwp_managesites_add_wpadmin', 'input');
    h.advance(399);
    assert.equal(h.requests.length, 1);
    h.advance(1);
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests.at(-1).options.data.url, 'https://other.example/');
    h.trigger('window', 'pagehide');
    assert.equal(h.requests.at(-1).aborted, true);
    h.advance(1200000);
    assert.equal(h.requests.length, 2);
    assert.equal(h.timers.size, 0);
});

test('CSV mode never starts detection and the single-site tab can restart it', () => {
    const h = uiHarness('multiple-site');
    h.fill(); h.advance(400);
    assert.equal(h.requests.length, 0);
    assert.equal(h.$('#mainwp_managesites_add').prop('visible'), false);
    assert.equal(h.$('#mainwp_managesites_add_import').prop('visible'), true);
    h.$('.menu-connect-first-site .active.item').attr('data-tab', 'single-site');
    h.context.activeTab = h.$('.menu-connect-first-site .active.item');
    vm.runInContext('mainwp_menu_connect_first_site_onvisible_callback(activeTab)', h.context);
    assert.equal(h.requests.length, 1);
    assert.equal(h.$('#mainwp_managesites_add').prop('visible'), true);
});


test('specific server errors stay visible, request failures stop, and invalid URLs never send detection', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'ERROR already connected', failure_ux: '<p>Already connected to another Dashboard.</p>' });
    assert.equal(h.$('#mainwp-onboarding-detection-message').html(), '<p>Already connected to another Dashboard.</p>');
    assert.equal(h.$('#mainwp-onboarding-retry').prop('hidden'), false);
    h.$('#mainwp_managesites_add_wpurl').val('not a valid URL');
    h.trigger('#mainwp_managesites_add_wpurl', 'input');
    h.advance(400);
    assert.equal(h.requests.length, 1);
    assert.equal(h.$('#mainwp-onboarding-reachable').text(), 'No');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), true);
    h.$('#mainwp_managesites_add_wpurl').val('example.org');
    h.trigger('#mainwp_managesites_add_wpurl', 'input');
    h.advance(400);
    h.requests.at(-1).options.error({}, 'timeout');
    assert.equal(h.$('#mainwp-onboarding-reachable').text(), 'No');
    h.advance(30000);
    assert.equal(h.requests.length, 2);
});

test('password controls enable Connect, while the advanced password-off mode remains supported', () => {
    const h = uiHarness();
    h.fill(); h.advance(400); h.reply({ response: 'NOMAINWP' });
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), true);
    h.trigger('#mainwp_managesites_add', 'click');
    assert.equal(h.requests.length, 1);
    assert.match(h.messages.at(-1)[1], /Please enter the administrator password/);
    h.$('#mainwp-administrator-password-checkbox-field').prop('checked', false);
    h.trigger('#mainwp-administrator-password-checkbox-field', 'change');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    h.trigger('#mainwp_managesites_add', 'click');
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_addwp');
});

test('editing during a connection attempt cannot apply the old target deadline to a different site', () => {
    const h = uiHarness();
    h.fill(); h.advance(400); h.reply({ response: 'OK', connection_window_remaining: 0 });
    h.$('#mainwp_managesites_add_admin_pwd').val('password');
    h.trigger('#mainwp_managesites_add', 'click');
    const connection = h.requests.at(-1);
    h.$('#mainwp_managesites_add_wpurl').val('new.example');
    h.trigger('#mainwp_managesites_add_wpurl', 'input');
    h.advance(400);
    connection.options.success({ response: 'ERROR Could not connect' });
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_checkwp');
    assert.equal(h.requests.at(-1).options.data.url, 'https://new.example/');
    h.reply({ response: 'NOMAINWP' });
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), false);
});

test('a failed connect before the deadline re-checks the Child and keeps the countdown', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_remaining: 1200 });
    h.$('#mainwp_managesites_add_admin_pwd').val('wrong');
    h.trigger('#mainwp_managesites_add_admin_pwd', 'input');
    h.trigger('#mainwp_managesites_add', 'click');
    h.reply({ response: 'ERROR Wrong password', failure_ux: '<p>Check the administrator password.</p>' });
    assert.equal(h.messages.at(-1)[1], '<p>Check the administrator password.</p>');
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_checkwp');
    h.reply({ response: 'OK', connection_window_remaining: 1100 });
    assert.equal(h.$('#mainwp-onboarding-timer').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'About 19 minutes left to connect.');
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    h.trigger('window', 'pagehide');
    assert.equal(h.timers.size, 0);
});

test('an expiry check answered by a still-active Child restarts the countdown from its fresh window', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_remaining: 1 });
    h.advance(1000);
    assert.equal(h.requests.length, 2);
    h.reply({ response: 'OK', connection_window_remaining: 600 });
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'About 10 minutes left to connect.');
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
    h.advance(60000);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'About 9 minutes left to connect.');
    h.trigger('window', 'pagehide');
    assert.equal(h.timers.size, 0);
});

test('returning to the tab during a connect attempt sends no expiry check', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_remaining: 1 });
    h.advance(1000);
    h.reply({ response: 'OK', connection_window_remaining: 0 });
    h.$('#mainwp_managesites_add_admin_pwd').val('password');
    h.trigger('#mainwp_managesites_add_admin_pwd', 'input');
    h.trigger('#mainwp_managesites_add', 'click');
    const connection = h.requests.at(-1);
    assert.equal(connection.options.data.action, 'mainwp_addwp');
    h.visible(true);
    h.visible(false);
    assert.equal(h.requests.at(-1), connection);
    connection.options.success({ response: 'ERROR MainWP Child not detected' });
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_checkwp');
    h.reply({ response: 'NOMAINWP' });
    assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), false);
});

test('rows that a stopped check never reached read Not checked instead of Checking', () => {
    for (const [result, reachable, child] of [
        [{ response: 'HTTPERROR' }, 'No', 'Not checked'],
        [{ response: 'ERROR Site is already connected to your MainWP Dashboard.' }, 'Not checked', 'Not checked']
    ]) {
        const h = uiHarness();
        h.fill(); h.advance(400);
        h.reply(result);
        assert.equal(h.$('#mainwp-onboarding-reachable').text(), reachable);
        assert.equal(h.$('#mainwp-onboarding-child').text(), child);
    }
    const h = uiHarness();
    h.fill(); h.advance(400);
    assert.equal(h.$('#mainwp-onboarding-reachable').text(), 'Checking...');
    h.reply({ response: 'NOMAINWP' });
    assert.equal(h.$('#mainwp-onboarding-child').text(), 'Not yet');
});
