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

test('milestones wait for widget initialization and consume each animation marker once', () => {
    const widget = fs.readFileSync(require.resolve('../../widgets/widget-mainwp-get-started.php'), 'utf8');
    assert.match(widget, /\$celebrate \? ' data-celebrate="true"' : ''/);
    assert.doesNotMatch(widget, /tadaaaa/);
    const mainwp = fs.readFileSync(require.resolve('../../assets/js/mainwp.js'), 'utf8');
    const selector = '#mainwp-get-started-list [data-celebrate="true"]';
    const marker = mainwp.indexOf(selector);
    const start = mainwp.lastIndexOf('jQuery(function () {', marker);
    const end = mainwp.indexOf('\n});', marker) + 4;
    assert.ok(marker >= 0 && start >= 0 && end > marker, 'The milestone ready callback must exist.');
    const pending = { attributes: { 'data-celebrate': 'true' }, classes: new Set() };
    const seen = { attributes: {}, classes: new Set() };
    const incomplete = { attributes: {}, classes: new Set() };
    const icons = [pending, seen, incomplete];
    const ready = [];
    const starts = [];
    const context = vm.createContext({
        jQuery(input) {
            if (typeof input === 'function') { ready.push(input); return; }
            assert.equal(input, selector);
            const selected = icons.filter(icon => icon.attributes['data-celebrate'] === 'true');
            const result = {
                removeAttr(name) {
                    selected.forEach(icon => delete icon.attributes[name]);
                    return result;
                },
                addClass(name) {
                    selected.forEach(icon => { icon.classes.add(name); starts.push(icon); });
                    return result;
                }
            };
            return result;
        }
    });
    vm.runInContext(mainwp.slice(start, end), context);
    assert.equal(ready.length, 1);
    assert.equal(pending.classes.has('tadaaaa'), false, 'Parsing the widget must not start its animation.');
    ready[0]();
    assert.equal(pending.classes.has('tadaaaa'), true);
    assert.equal(pending.attributes['data-celebrate'], undefined);
    assert.equal(seen.classes.size, 0);
    assert.equal(incomplete.classes.size, 0);
    ready[0]();
    assert.deepEqual(starts, [pending], 'Repeated initialization must not replay a milestone.');
});

function uiHarness(mode = 'single-site', tabs = true, storage = new Map(), startedAt = 1000) {
    let now = startedAt;
    let nextId = 1;
    const timers = new Map();
    const elements = new Map();
    const events = new Map();
    const requests = [];
    const messages = [];
    const textWrites = [];
    const reloads = [];
    const readyCallbacks = [];
    const document = { hidden: false };
    const window = { localStorage: storage instanceof Map ? { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) } : storage };
    const mutations = [];
    let validations = 0;
    let submits = 0;
    const copy = {
        checking: 'Checking...', yes: 'Yes', notYet: 'Not yet', no: 'No', notChecked: 'Not checked',
        unreachable: 'Check the URL and that the site is online.',
        install: "Open the site's plugin installer",
        childReady: 'MainWP Child installed and active', childNotActive: 'MainWP Child not active',
        remaining: 'For security, MainWP Child switches off in about %d minutes if nothing connects to it.', remainingOne: 'For security, MainWP Child switches off in about 1 minute if nothing connects to it.', remainingLess: 'For security, MainWP Child switches off in less than a minute if nothing connects to it.',
        idleTitle: "We'll check the site for you", idleBody: "Enter the site URL and username, and we'll confirm the site is reachable and MainWP Child is ready.", checkingTitle: 'Checking %s…', checkingBody: 'This takes a few seconds.', connectingTitle: 'Connecting…', readyTitle: 'Ready to connect', installTitle: 'Install or activate MainWP Child', expiredTitle: 'MainWP Child switched itself off', stoppedTitle: "We couldn't finish checking %s", unreachableTitle: "Can't reach %s", polling: "We'll keep checking while this page is open.", childNotAnswering: 'Inactive Child error',
        windowClosed: 'The connection window may have ended. If connecting fails, check that MainWP Child is still active.',
        expired: "It wasn't connected within %d minutes, so it turned off for security. Reactivate it on the site's Plugins page, then check again.",
        expiredOne: "It wasn't connected within 1 minute, so it turned off for security. Reactivate it on the site's Plugins page, then check again.",
        expiredUnknown: "Its connection window ended, so it turned off for security. Reactivate it on the site's Plugins page, then check again.",
        error: 'Could not connect. Try again.', password: 'Enter the administrator password.'
    };
    function trigger(selector, event) {
        if (selector === '#mainwp_connect_first_site_form' && event === 'submit') submits++;
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
                length: ['#mainwp-onboarding-detection', '#mainwp_connect_first_site_form', '#mainwp-managesites-row-import-sites'].includes(selector) ? 1 : 0,
                0: {},
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
        const request = {
            options: {
                ...options,
                success(...args) { request.settled = true; return options.success?.(...args); },
                error(...args) { request.settled = true; return options.error?.(...args); }
            },
            settled: false, aborted: false,
            abort() { request.aborted = true; request.options.error({}, 'abort'); }
        };
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
    if (tabs) $('.menu-connect-first-site .active.item').attr('data-tab', mode);
    const context = load({
        MutationObserver: class { constructor(callback) { mutations.push(callback); } observe() {} disconnect() {} },
        mainwp_managesites_import_handle_form_before_submit() { validations++; return []; },
        jQuery: $, document, window, mainwpSetupLocalize: copy, Date: { now: () => now }, ajaxurl: '/admin-ajax.php',
        setTimeout: browserTimer((callback, delay) => schedule(callback, delay)), clearTimeout: browserTimer(id => timers.delete(id)),
        setInterval: browserTimer((callback, delay) => schedule(callback, delay, true)), clearInterval: browserTimer(id => timers.delete(id)),
        mainwp_set_message_zone: (...args) => messages.push(args), mainwp_forceReload: url => reloads.push(url)
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
    return { $, context, requests, messages, textWrites, reloads, timers, advance, document, storage, mutations,
        validations: () => validations, submits: () => submits, now: () => now,
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

const matrixAdding = '<i class="notched circle loading icon"></i> Adding the site to your MainWP Dashboard. Please wait...';

for (const onboarding of [true, false]) {
    test(onboarding
        ? 'onboarding connect success redirects immediately without clearing fields or showing success'
        : 'non-onboarding setup success keeps field clearing, success message and delayed redirect', () => {
        const h = uiHarness();
        h.$('#mainwp_connect_first_site_form').length = onboarding ? 1 : 0;
        h.fill();
        const values = {
            wpname: 'My site', wpurl: 'example.org/wordpress', wpurl_protocol: 'http',
            wpadmin: 'admin', admin_pwd: 'fixture-password', uniqueId: 'fixture-id'
        };
        for (const [field, value] of Object.entries(values)) h.$('#mainwp_managesites_add_' + field).val(value);
        h.$('#mainwp_managesites_add_wpname').attr('data-user-typed', '1');
        const extras = ["input[name^='creport_token_']", '.mainwp_addition_fields_addsite input'];
        extras.forEach((selector, index) => {
            const field = h.$('extension-field-' + index).attr('name', 'extension_' + index).val('fixture-' + index);
            h.$(selector).each = callback => callback.call(field);
        });
        h.trigger('#mainwp_managesites_add', 'click');
        assert.equal(h.requests.at(-1).options.data.action, 'mainwp_addwp');
        h.reply({ response: 'Connected', siteid: '42' });
        const destination = 'admin.php?page=mainwp-setup&step=next_steps&siteid=42';
        assert.deepEqual(h.reloads, onboarding ? [destination] : []);
        assert.equal([...h.timers.values()].filter(timer => timer.at - h.now() === 3000).length, onboarding ? 0 : 1);
        assert.equal(h.messages.some(message => message[2] === 'green'), !onboarding);
        if (onboarding) assert.deepEqual(h.messages.at(-1), ['#mainwp-message-zone', matrixAdding, '']);
        for (const [field, value] of Object.entries(values)) {
            assert.equal(h.$('#mainwp_managesites_add_' + field).val(), onboarding ? value : field === 'wpurl_protocol' ? 'https' : '', field);
        }
        extras.forEach((_, index) => assert.equal(h.$('extension-field-' + index).val(), onboarding ? 'fixture-' + index : ''));
        assert.equal(h.requests.some(request => request.options.data.action === 'mainwp_get_site_icon'), false);
        if (!onboarding) {
            h.advance(2999);
            assert.deepEqual(h.reloads, []);
            h.advance(1);
            assert.deepEqual(h.reloads, [destination]);
        }
    });
}

// Each expanded row records all observable columns. Replies need a live check request;
// settled states with a passed deadline first ask checkExpiry() for one. Other late replies are ignored.
// Deadlines are seeded independently of status, including combinations that normal entry paths avoid.
const matrixStatuses = {
    idle:        ['Checking...', 'Checking...', false, false, false, false],
    checking:    ['Checking...', 'Checking...', false, false, false, false],
    waiting:     ['Yes', 'Not yet', true, false, false, false],
    detected:    ['Yes', 'Yes', false, true, false, false],
    expired:     ['Yes', 'No', false, false, true, true],
    unreachable: ['No', 'Not checked', false, false, false, true],
    error:       ['Not checked', 'Not checked', false, false, false, true],
    timed_out:   ['Not checked', 'Not checked', false, false, false, true],
    stopped:     ['Not checked', 'Not checked', false, false, false, true],
    connecting:  ['Yes', 'Yes', false, false, false, false]
};
const matrixReplies = {
    'reply OK': { response: 'OK', connection_window_remaining: 120 },
    'reply OK remaining 0': { response: 'OK', connection_window_remaining: 0 },
    'reply NOMAINWP': { response: 'NOMAINWP' },
    'reply HTTPERROR': { response: 'HTTPERROR' },
    'reply TIMEOUT': { response: 'TIMEOUT' },
    'reply ERROR': { response: 'ERROR blocked', failure_ux: '<p>Blocked</p>' }
};
const matrixEvents = [...Object.keys(matrixReplies), 'changed field', 'unchanged change', 'tab hidden',
    'tab shown', 'countdown deadline', 'Retry', 'connect success', 'connect ERROR', 'connect transport error'];
const matrixZone = ['#mainwp-message-zone', 'validation', 'yellow'];
const matrixClosed = 'The connection window may have ended. If connecting fails, check that MainWP Child is still active.';
const matrixDefault = '';
const matrixSite = 'https://example.org/wordpress/';

function matrixView(status, deadline, timers = [], request = null, zone = matrixZone, rows = matrixStatuses[status]) {
    return {
        status, timers: [...timers].sort(), request,
        rows: rows.slice(0, 2),
        rowClasses: rows.slice(0, 2).map(label => [label === 'Yes', label === 'No']),
        rowIcons: rows.slice(0, 2).map(label => ({
            Yes: 'green check icon', No: 'red times icon', 'Checking...': 'notched circle loading icon',
            'Not yet': 'exclamation circle icon', 'Not checked': 'circle outline icon'
        })[label]),
        childLabel: rows[0] === 'Yes' && rows[1] !== 'Yes' ? 'MainWP Child not active' : 'MainWP Child installed and active',
        install: rows[2], installHref: rows[2] ? matrixSite + 'wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term' : '',
        timer: rows[3] && deadline !== 'none', expiry: rows[4], retry: rows[5],
        timerLine: deadline === 'none' ? matrixDefault : deadline === 'future' ? 'For security, MainWP Child switches off in less than a minute if nothing connects to it.' : matrixClosed,
        detectionMessage: status === 'unreachable' ? 'Check the URL and that the site is online.'
            : status === 'error' ? '<p>Blocked</p>' : '',
        messageClass: ['detected'].includes(status) ? 'ui green message' : ['waiting', 'expired'].includes(status) || (status === 'timed_out' && rows[0] === 'Yes') ? 'ui yellow message' : ['error', 'unreachable'].includes(status) ? 'ui red message' : 'ui message',
        headline: ({ idle: "We'll check the site for you", checking: `Checking ${matrixSite}…`, connecting: 'Connecting…', detected: 'Ready to connect', waiting: 'Install or activate MainWP Child', expired: 'MainWP Child switched itself off', unreachable: `Can't reach ${matrixSite}`, error: 'Could not connect. Try again.', timed_out: rows[0] === 'Yes' ? 'Install or activate MainWP Child' : `We couldn't finish checking ${matrixSite}`, stopped: `We couldn't finish checking ${matrixSite}` })[status],
        stateIcon: ['checking', 'connecting'].includes(status) ? 'notched circle loading icon' : status === 'detected' ? 'check icon' : ['waiting', 'expired'].includes(status) || (status === 'timed_out' && rows[0] === 'Yes') ? 'exclamation icon' : ['error', 'unreachable'].includes(status) ? 'times icon' : 'info icon',
        polling: status === 'waiting',
        zone
    };
}

function matrixExpected(status, event, deadline) {
    const active = status === 'checking' ? ['cap'] : status === 'waiting' ? ['cap', 'poll']
        : status === 'detected' && deadline === 'future' ? ['countdown'] : [];
    const request = status === 'checking' ? 'mainwp_checkwp' : status === 'connecting' ? 'mainwp_addwp' : null;
    const base = matrixView(status, deadline, active, request);
    if (event in matrixReplies) {
        const canRecheck = deadline === 'passed' && !['checking', 'waiting', 'connecting'].includes(status);
        if (status !== 'checking' && !canRecheck) return base;
        if (event === 'reply OK') {
            const next = matrixView('detected', 'future', ['countdown']);
            next.timerLine = 'For security, MainWP Child switches off in about 2 minutes if nothing connects to it.';
            return next;
        }
        if (event === 'reply OK remaining 0') return matrixView('detected', 'passed');
        if (event === 'reply NOMAINWP') return deadline === 'passed'
            ? matrixView('expired', deadline, [], null, matrixZone)
            : matrixView('waiting', deadline, ['cap', 'poll']);
        return matrixView(event === 'reply ERROR' ? 'error' : 'unreachable', deadline);
    }
    if (event === 'changed field') return matrixView('idle', 'none', ['debounce'], status === 'connecting' ? request : null);
    if (event === 'unchanged change') return base;
    if (event === 'Retry') return status === 'connecting' ? matrixView('idle', 'none', [], request)
        : matrixView('checking', status === 'detected' ? deadline : 'none', ['cap'], 'mainwp_checkwp');
    if (event === 'tab hidden') {
        base.timers = ['idle', 'checking', 'waiting'].includes(status) ? ['cap'] : [];
        if (status === 'checking') base.request = null;
        return base;
    }
    if (event === 'tab shown') {
        if (deadline === 'passed' && !['checking', 'waiting', 'connecting'].includes(status)) base.request = 'mainwp_checkwp';
        return base;
    }
    if (event === 'countdown deadline') {
        if (status === 'detected' && deadline === 'future') {
            base.timers = [];
            base.request = 'mainwp_checkwp';
            base.timerLine = matrixClosed;
        }
        return base;
    }
    // Connect is independent of detection. Both failure callbacks select the same target first.
    if (event === 'connect success') return matrixView('connecting', deadline, [], null,
        status === 'connecting' ? matrixZone : ['#mainwp-message-zone', matrixAdding, ''], [...matrixStatuses[status].slice(0, 2).map(label => label === 'Not checked' ? 'Checking...' : label), false, false, false, false]);
    const zone = ['#mainwp-message-zone', event === 'connect ERROR' ? '<p>Wrong password</p>' : 'Could not connect. Try again.', 'red'];
    if (event === 'connect transport error' || deadline === 'passed') {
        const rows = matrixStatuses[status].slice(0, 2).map(label => label === 'Checking...' ? 'Not checked' : label);
        return matrixView('stopped', deadline, [], event === 'connect ERROR' ? 'mainwp_checkwp' : null,
            zone, [...rows, false, false, false, true]);
    }
    return matrixView('checking', deadline, ['cap'], 'mainwp_checkwp', zone);
}

const detectionMatrix = Object.keys(matrixStatuses).flatMap(status => matrixEvents.flatMap(event =>
    ['none', 'future', 'passed'].map(deadline => ({
        name: `${status} | ${event} | ${deadline}`, status, event, deadline,
        expected: matrixExpected(status, event, deadline)
    }))));

function matrixSeed(h, status, deadline) {
    h.fill(); h.advance(400);
    const controller = vm.runInContext('mainwp_onboarding_detection', h.context);
    if (status === 'waiting') h.reply({ response: 'NOMAINWP' });
    else if (status !== 'checking') controller.stop(status === 'connecting' ? 'detected' : status);
    const state = controller.state();
    const labels = matrixStatuses[status];
    Object.assign(state, {
        reachable: labels[0] === 'Yes' ? 'ok' : labels[0] === 'No' ? 'failed' : 'pending',
        child: labels[1] === 'Yes' ? 'ok' : labels[1] === 'No' ? 'failed' : 'pending',
        detected: deadline !== 'none' || ['detected', 'expired', 'connecting'].includes(status),
        deadline: deadline === 'none' ? null : h.now() + (deadline === 'future' ? 2000 : -1),
        minutes: null, result: status === 'error' ? { response: 'ERROR blocked', failure_ux: '<p>Blocked</p>' } : {}
    });
    if (status === 'connecting') {
        h.$('#mainwp_managesites_add_admin_pwd').val('wrong');
        h.trigger('#mainwp_managesites_add', 'click');
    } else controller.refresh();
    h.context.mainwp_set_message_zone(...matrixZone);
    return controller;
}

function matrixApply(h, controller, event, deadline) {
    if (event in matrixReplies) {
        if (deadline === 'passed') controller.checkExpiry();
        const pending = h.requests.findLast(r => !r.aborted && !r.settled && r.options.data.action === 'mainwp_checkwp');
        if (pending) pending.options.success(matrixReplies[event]);
    } else if (event === 'changed field') {
        h.$('#mainwp_managesites_add_wpurl').val('other.example');
        h.trigger('#mainwp_managesites_add_wpurl', 'input');
    } else if (event === 'unchanged change') h.trigger('#mainwp_managesites_add_wpurl', 'change');
    else if (event === 'tab hidden') h.visible(true);
    else if (event === 'tab shown') h.visible(false);
    else if (event === 'countdown deadline') {
        if (controller.state().status === 'detected' && deadline === 'future') h.advance(2000);
    } else if (event === 'Retry') h.trigger('#mainwp-onboarding-retry', 'click');
    else {
        if (controller.state().status !== 'connecting') {
            h.$('#mainwp_managesites_add_admin_pwd').val('wrong');
            h.trigger('#mainwp_managesites_add', 'click');
        }
        if (event === 'connect success') h.reply({ response: 'Connected', siteid: 42 });
        else if (event === 'connect ERROR') h.reply({ response: 'ERROR Wrong password', failure_ux: '<p>Wrong password</p>' });
        else h.requests.at(-1).options.error({}, 'error');
    }
}

function matrixSnapshot(h, controller) {
    const visible = id => h.$('#mainwp-onboarding-' + id).prop('hidden') === false;
    const row = id => h.$('#mainwp-onboarding-' + id);
    return {
        status: controller.state().status,
        timers: [...h.timers.values()].map(t => t.interval ? 'countdown' : t.at - h.now() === 400 ? 'debounce'
            : t.at - h.now() === 3000 ? 'reload' : t.at - h.now() === 5000 ? 'poll' : 'cap').sort(),
        request: h.requests.filter(r => !r.aborted && !r.settled).map(r => r.options.data.action).join(',') || null,
        rows: ['reachable', 'child'].map(id => row(id).text()),
        rowClasses: ['reachable', 'child'].map(id => [row(id).prop('is-ok'), row(id).prop('is-failed')]),
        rowIcons: ['reachable', 'child'].map(id => row(id + '-icon').attr('class')),
        childLabel: row('child-label').text(),
        install: visible('install'), installHref: row('install-link').attr('href'),
        timer: visible('timer'), timerLine: row('time-left').text(),
        expiry: visible('expiry'), retry: visible('retry'), detectionMessage: row('detection-message').html(),
        messageClass: row('detection').attr('class'), headline: row('detection-headline').text(),
        stateIcon: row('state-icon').attr('class'), polling: visible('polling'),
        zone: h.messages.at(-1)
    };
}

const matrixSequences = [
    ...['checking', 'waiting'].flatMap(status => ['NOMAINWP', 'HTTPERROR', 'TIMEOUT', 'ERROR', 'OK'].map(response => ({
        name: `E1 ${status} | hidden then shown then ${response} | passed`, status, deadline: 'passed',
        run(h) {
            h.visible(true); h.visible(false);
            h.reply({ response: response === 'ERROR' ? 'ERROR blocked' : response,
                failure_ux: response === 'ERROR' ? '<p>Blocked</p>' : undefined, connection_window_remaining: 120 });
        },
        expected: response === 'OK' ? { ...matrixView('detected', 'future', ['countdown']), timerLine: 'For security, MainWP Child switches off in about 2 minutes if nothing connects to it.' }
            : matrixView(response === 'NOMAINWP' ? 'expired' : response === 'ERROR' ? 'error' : 'unreachable', 'passed', [], null,
                matrixZone)
    }))),
    ...Object.entries(matrixReplies).map(([event, result]) => ({
        name: `E2 detected | countdown then ${event} | future`, status: 'detected', deadline: 'future',
        run(h) { h.advance(2000); h.reply(result); },
        expected: matrixExpected('detected', event, 'passed')
    })),
    ...['URL', 'protocol', 'username'].map(field => ({
        name: `E4 waiting | normalized ${field} unchanged on blur | none`, status: 'waiting', deadline: 'none',
        run(h) {
            const id = field === 'username' ? 'wpadmin' : field === 'protocol' ? 'wpurl_protocol' : 'wpurl';
            if (field === 'URL') h.$('#mainwp_managesites_add_wpurl').val(' https://EXAMPLE.org/wordpress/?ignored=1#anchor ');
            if (field === 'username') h.$('#mainwp_managesites_add_wpadmin').val(' admin ');
            h.trigger('#mainwp_managesites_add_' + id, 'change');
        },
        expected: matrixView('waiting', 'none', ['cap', 'poll'])
    })),
    {
        name: 'E4 waiting | http:// URL typed with https dropdown, blur | none', status: 'waiting', deadline: 'none',
        run(h) {
            h.$('#mainwp_managesites_add_wpurl').val('http://' + h.$('#mainwp_managesites_add_wpurl').val());
            h.trigger('#mainwp_managesites_add_wpurl', 'input');
            h.advance(400);
            h.reply({ response: 'NOMAINWP' });
            h.trigger('#mainwp_managesites_add_wpurl', 'change');
        },
        expected: { ...matrixView('waiting', 'none', ['cap', 'poll']),
            installHref: 'http://example.org/wordpress/wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term' }
    },
    {
        name: 'E4 detected | http:// URL with https dropdown, blur after the deadline then NOMAINWP | passed', status: 'detected', deadline: 'passed',
        run(h) {
            h.$('#mainwp_managesites_add_wpurl').val('http://' + h.$('#mainwp_managesites_add_wpurl').val());
            h.trigger('#mainwp_managesites_add_wpurl', 'input');
            h.advance(400);
            h.reply({ response: 'OK', connection_window_remaining: 2 });
            h.advance(2000);
            h.trigger('#mainwp_managesites_add_wpurl', 'change');
            h.advance(400);
            h.reply({ response: 'NOMAINWP' });
        },
        expected: matrixView('expired', 'passed')
    },
    ...['none', 'future', 'passed'].map(deadline => ({
        name: `E3 expired | password validation then refresh | ${deadline}`, status: 'expired', deadline,
        run(h, controller) { h.trigger('#mainwp_managesites_add', 'click'); controller.refresh(); },
        expected: matrixView('expired', deadline, [], null, ['#mainwp-message-zone', 'Enter the administrator password.', 'yellow'])
    })),
    ...['future', 'passed'].map(deadline => ({
        name: `E4 detected | blur then connect ERROR then late NOMAINWP | ${deadline}`, status: 'detected', deadline,
        run(h, controller) {
            h.trigger('#mainwp_managesites_add_wpurl', 'change');
            matrixApply(h, controller, 'connect ERROR', deadline);
            if (deadline === 'future') h.advance(2000);
            h.reply({ response: 'NOMAINWP' });
        },
        expected: matrixView('expired', 'passed', [], null, ['#mainwp-message-zone', '<p>Wrong password</p>', 'red'])
    })),
    ...['connect ERROR', 'connect transport error'].map(event => ({
        name: `connecting | changed target then ${event} then NOMAINWP | passed`, status: 'connecting', deadline: 'passed',
        run(h, controller) {
            matrixApply(h, controller, 'changed field', 'passed'); h.advance(400);
            matrixApply(h, controller, event, 'passed'); h.reply({ response: 'NOMAINWP' });
        },
        expected: { ...matrixView('waiting', 'none', ['cap', 'poll'], null,
            ['#mainwp-message-zone', event === 'connect ERROR' ? '<p>Wrong password</p>' : 'Could not connect. Try again.', 'red']),
            installHref: 'https://other.example/wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term' }
    })),
    {
        name: 'detected | expiry request then edit and stale NOMAINWP | passed', status: 'detected', deadline: 'passed',
        run(h, controller) {
            controller.checkExpiry(); const old = h.requests.at(-1);
            matrixApply(h, controller, 'changed field', 'passed'); old.options.success({ response: 'NOMAINWP' });
        },
        expected: matrixView('idle', 'none', ['debounce'])
    },
    {
        name: 'expired | Retry then reactivated Child | passed', status: 'expired', deadline: 'passed',
        run(h) {
            h.trigger('#mainwp-onboarding-retry', 'click');
            h.reply({ response: 'OK', connection_window_remaining: 120 });
        },
        expected: { ...matrixView('detected', 'future', ['countdown']), timerLine: 'For security, MainWP Child switches off in about 2 minutes if nothing connects to it.' }
    }
];

test('detection state/input matrix (480 rows)', async t => {
    const rows = [...detectionMatrix, ...matrixSequences];
    assert.equal(rows.length, 480);
    for (const row of rows) await t.test(row.name, () => {
        const h = uiHarness();
        const controller = matrixSeed(h, row.status, row.deadline);
        if (row.run) row.run(h, controller);
        else matrixApply(h, controller, row.event, row.deadline);
        assert.deepEqual(matrixSnapshot(h, controller), row.expected);
    });
});

for (const mode of ['single-site', 'multiple-site']) {
    test('chosen ' + mode + ' mode works without the tab menu', () => {
        const h = uiHarness(mode, false);
        h.fill(); h.advance(400);
        assert.equal(h.requests.length, mode === 'single-site' ? 1 : 0);
        assert.equal(h.$('#mainwp_managesites_add').prop('visible'), mode === 'single-site');
        assert.equal(h.$('#mainwp_managesites_add_import').prop('visible'), mode === 'multiple-site');
        if (mode === 'single-site') {
            h.reply({ response: 'NOMAINWP' });
            assert.equal(h.$('#mainwp-onboarding-reachable').text(), 'Yes');
            assert.equal(h.$('#mainwp-onboarding-child').text(), 'Not yet');
            assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), false);
            h.advance(5000);
            h.reply({ response: 'OK', connection_window_remaining: 120 });
            assert.equal(h.$('#mainwp-onboarding-child').text(), 'Yes');
            assert.equal(h.$('#mainwp-onboarding-timer').prop('hidden'), false);
        }
    });
}

test('wizard fields debounce detection, render install guidance, and enable Connect independently of pre-check', () => {
    const h = uiHarness();
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), true);
    assert.equal(h.$('#mainwp-onboarding-detection').prop('hidden'), false);
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
    assert.equal(h.$('#mainwp-onboarding-polling').prop('hidden'), false);
    h.$('#mainwp_managesites_add_admin_pwd').val('password');
    h.trigger('#mainwp_managesites_add_admin_pwd', 'input');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    h.advance(5000);
    assert.equal(h.requests.length, 2);
    assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-install-link').attr('href'), 'https://example.org/wordpress/wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term');
    h.reply({ response: 'HTTPERROR', failure_ux: '<p>Existing transport advice</p>' });
    assert.equal(h.$('#mainwp-onboarding-reachable').text(), 'No');
    assert.equal(h.$('#mainwp-onboarding-detection-message').text(), 'Check the URL and that the site is online.');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    h.trigger('#mainwp_managesites_add', 'click');
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_addwp');
    assert.equal(Object.hasOwn(h.requests.at(-1).options, 'timeout'), false);
    assert.equal(h.requests.at(-1).options.data.managesites_add_adminpwd, 'password');
    h.reply({ response: 'ERROR Bad password', failure_ux: '<p>Specific authentication error</p>' });
    assert.equal(h.messages.at(-1)[1], '<p>Specific authentication error</p>');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
});

test('automatic re-poll keeps the needs-Child presentation while a user check starts fresh', () => {
    const h = uiHarness();
    h.fill(); h.advance(400); h.reply({ response: 'NOMAINWP' });
    assert.equal(vm.runInContext('mainwp_onboarding_detection.state().status', h.context), 'waiting');
    h.advance(5000);
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests.at(-1).settled, false);
    assert.equal(vm.runInContext('mainwp_onboarding_detection.state().status', h.context), 'checking');
    assert.equal(h.$('#mainwp-onboarding-detection').attr('class'), 'ui yellow message');
    assert.equal(h.$('#mainwp-onboarding-detection-headline').text(), 'Install or activate MainWP Child');
    assert.equal(h.$('#mainwp-onboarding-state-icon').attr('class'), 'exclamation icon');
    assert.equal(h.$('#mainwp-onboarding-polling').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-child-label').text(), 'MainWP Child not active');
    assert.equal(h.$('#mainwp-onboarding-detection-body').prop('hidden'), true);
    assert.equal(h.$('#mainwp-onboarding-detection-body').text(), '');
    h.trigger('#mainwp-onboarding-retry', 'click');
    assert.equal(h.$('#mainwp-onboarding-detection').attr('class'), 'ui message');
    assert.equal(h.$('#mainwp-onboarding-detection-headline').text(), 'Checking https://example.org/wordpress/…');
    assert.equal(h.$('#mainwp-onboarding-state-icon').attr('class'), 'notched circle loading icon');
    assert.equal(h.$('#mainwp-onboarding-detection-body').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-detection-body').text(), 'This takes a few seconds.');
    assert.equal(h.$('#mainwp-onboarding-polling').prop('hidden'), true);
    assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), true);
});

test('detected countdown pauses rendering in hidden tabs, catches up on return, and checks expiry without assuming it', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 61 });
    assert.equal(h.$('#mainwp-onboarding-timer').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'For security, MainWP Child switches off in about 2 minutes if nothing connects to it.');
    h.advance(1000);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'For security, MainWP Child switches off in about 1 minute if nothing connects to it.');
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

test('countdown writes its live region only when the displayed text changes', () => {
    const h = uiHarness();
    h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_remaining: 120 });
    const writes = () => h.textWrites.filter(write => write.selector === '#mainwp-onboarding-time-left').length;
    const initial = writes();
    h.advance(59000);
    assert.equal(writes(), initial);
    h.advance(1000);
    assert.equal(h.$('#mainwp-onboarding-time-left').text(), 'For security, MainWP Child switches off in about 1 minute if nothing connects to it.');
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
    assert.equal(h.$('#mainwp-onboarding-detection').prop('hidden'), false);
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

test('older Child without metadata and disabled windows hide the timer', () => {
    for (const [fields, expected] of [[{}, ''], [{ connection_window_minutes: 0 }, '']]) {
        const h = uiHarness();
        h.fill(); h.advance(400);
        h.reply({ response: 'OK', ...fields });
        assert.equal(h.$('#mainwp-onboarding-time-left').text(), expected);
        assert.equal(h.$('#mainwp-onboarding-timer').prop('hidden'), true);
        assert.equal(h.$('#mainwp-onboarding-expiry').prop('hidden'), true);
        assert.equal(h.timers.size, 0);
    }
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
    h.$('#mainwp_managesites_add_wpadmin').val('other-admin');
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
    assert.match(h.messages.at(-1)[1], /Enter the administrator password/);
    h.$('#mainwp-administrator-password-checkbox-field').prop('checked', false);
    h.trigger('#mainwp-administrator-password-checkbox-field', 'change');
    assert.equal(h.$('#mainwp_managesites_add').prop('disabled'), false);
    h.trigger('#mainwp_managesites_add', 'click');
    assert.equal(h.requests.at(-1).options.data.action, 'mainwp_addwp');
});


test('deadline survives reload through two bind harnesses sharing storage', () => {
    const storage = new Map();
    const first = uiHarness('single-site', false, storage);
    first.fill(); first.advance(400);
    first.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 1 });
    assert.equal(storage.size, 1);
    const second = uiHarness('single-site', false, storage, 5000);
    second.fill(); second.advance(400); second.reply({ response: 'NOMAINWP' });
    assert.equal(vm.runInContext('mainwp_onboarding_detection.state().status', second.context), 'expired');
    assert.equal(second.$('#mainwp-onboarding-expiry').prop('hidden'), false);
});

for (const scenario of ['missing', 'future', 'mismatch', 'invalid deadline', 'invalid minutes', 'parse', 'throw']) {
    test('stored carry validation: ' + scenario, () => {
        const storage = new Map();
        const first = uiHarness('single-site', false, storage);
        first.fill(); first.advance(400);
        first.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 60 });
        const [key, value] = [...storage][0] || ['missing', '{}'];
        const entry = JSON.parse(value);
        if (scenario === 'missing') storage.clear();
        if (scenario === 'mismatch') storage.set(key, JSON.stringify({ ...entry, url: 'https://other.example/' }));
        if (scenario === 'invalid deadline') storage.set(key, JSON.stringify({ ...entry, deadline: '1000' }));
        if (scenario === 'invalid minutes') storage.set(key, JSON.stringify({ ...entry, minutes: -1 }));
        if (scenario === 'parse') storage.set(key, '{');
        const blocked = { getItem() { throw Error('blocked'); }, setItem() { throw Error('blocked'); }, removeItem() { throw Error('blocked'); } };
        const second = uiHarness('single-site', false, scenario === 'throw' ? blocked : storage, scenario === 'future' ? 2000 : 100000);
        second.fill(); second.advance(400); second.reply({ response: 'NOMAINWP' });
        assert.equal(vm.runInContext('mainwp_onboarding_detection.state().status', second.context), 'waiting');
        second.trigger('#mainwp-onboarding-retry', 'click');
        second.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 120 });
        assert.equal(second.$('#mainwp-onboarding-detection-headline').text(), 'Ready to connect');
    });
}

for (const result of [{ response: 'OK' }, { response: 'OK', connection_window_minutes: 0 }]) {
    test('OK without usable deadline deletes stored history: ' + JSON.stringify(result), () => {
        const h = uiHarness(); h.fill(); h.advance(400);
        h.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 60 });
        assert.equal(h.storage.size, 1);
        h.trigger('#mainwp-onboarding-retry', 'click'); h.reply(result);
        assert.equal(h.storage.size, 0);
    });
}

test('successful connect deletes history before redirect', () => {
    const h = uiHarness(); h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_minutes: 20, connection_window_remaining: 60 });
    assert.equal(h.storage.size, 1);
    h.context.mainwp_forceReload = url => { assert.equal(h.storage.size, 0); h.reloads.push(url); };
    h.$('#mainwp_managesites_add_admin_pwd').val('password');
    h.trigger('#mainwp_managesites_add', 'click'); h.reply({ response: 'Connected', siteid: 42 });
    assert.equal(h.reloads.length, 1);
});

for (const missing of [true, false]) {
    test(missing ? 'Child-not-answering failure uses detection and clears on detected' : 'wrong-password diagnostics survive detection OK', () => {
        const h = uiHarness(); h.fill(); h.advance(400); h.reply({ response: 'NOMAINWP' });
        h.$('#mainwp_managesites_add_admin_pwd').val('password');
        h.trigger('#mainwp_managesites_add', 'click');
        h.reply({ response: missing ? 'ERROR Inactive Child error' : 'ERROR Wrong password', failure_ux: '<p>Diagnostics</p>' });
        assert.equal(h.messages.some(message => message[1] === '<p>Diagnostics</p>'), !missing);
        h.reply({ response: 'OK' });
        assert.deepEqual(h.messages.at(-1), missing ? ['#mainwp-message-zone'] : ['#mainwp-message-zone', '<p>Diagnostics</p>', 'red']);
    });
}

test('escaped translated Child-not-answering errors use detection without generic diagnostics', () => {
    const h = uiHarness(); h.fill(); h.advance(400); h.reply({ response: 'NOMAINWP' });
    h.context.mainwpSetupLocalize.childNotAnswering = "MainWP Child isn't answering";
    h.$('#mainwp_managesites_add_admin_pwd').val('password');
    h.trigger('#mainwp_managesites_add', 'click');
    h.reply({ response: 'ERROR MainWP Child isn&#039;t answering', failure_ux: '<p>Diagnostics</p>' });
    assert.equal(vm.runInContext('mainwp_onboarding_connect_failure.category', h.context), 'child_not_answering');
    assert.equal(h.messages.some(message => message[1] === '<p>Diagnostics</p>'), false);
    assert.deepEqual(h.messages.at(-1), ['#mainwp-message-zone']);
});

test('error matching decodes only esc_html entities in one pass with ampersands last', () => {
    const c = load();
    assert.equal(c.mainwp_onboarding_decode('&#039; &#39; &quot; &lt; &gt; &amp;'), "' ' \" < > &");
    assert.equal(c.mainwp_onboarding_decode('&amp;#039; &amp;quot; &amp;lt; &amp;gt; &amp;amp;'), '&#039; &quot; &lt; &gt; &amp;');
    assert.equal(c.mainwp_onboarding_decode('&#x27; &#34; &apos; &nbsp;'), '&#x27; &#34; &apos; &nbsp;');
});

test('detection for a different URL does not clear a Child failure for the original URL', () => {
    const h = uiHarness(); h.fill(); h.advance(400); h.reply({ response: 'NOMAINWP' });
    h.$('#mainwp_managesites_add_admin_pwd').val('password'); h.trigger('#mainwp_managesites_add', 'click');
    h.reply({ response: 'ERROR Inactive Child error', failure_ux: '<p>Diagnostics</p>' });
    h.context.mainwp_set_message_zone('#mainwp-message-zone', 'Other message', 'yellow');
    h.$('#mainwp_managesites_add_wpurl').val('other.example'); h.trigger('#mainwp_managesites_add_wpurl', 'input'); h.advance(400);
    h.reply({ response: 'OK' });
    assert.equal(h.messages.at(-1)[1], 'Other message');
});

test('Check again focuses the headline and automatic polling never moves focus', () => {
    const h = uiHarness(); let focused = 0;
    h.$('#mainwp-onboarding-detection-headline').on('focus', () => focused++);
    h.fill(); h.advance(400); h.reply({ response: 'NOMAINWP' }); h.advance(5000);
    assert.equal(focused, 0);
    h.reply({ response: 'HTTPERROR' }); h.trigger('#mainwp-onboarding-retry', 'click');
    assert.equal(focused, 1);
});

test('timed out reachable site keeps install actions without promising automatic checks', () => {
    const h = uiHarness(); h.fill(); h.advance(400); h.reply({ response: 'NOMAINWP' });
    h.advance(1200000);
    assert.equal(h.$('#mainwp-onboarding-detection').attr('class'), 'ui yellow message');
    assert.equal(h.$('#mainwp-onboarding-detection-headline').text(), 'Install or activate MainWP Child');
    assert.equal(h.$('#mainwp-onboarding-install').prop('hidden'), false);
    assert.equal(h.$('#mainwp-onboarding-polling').prop('hidden'), true);
    assert.equal(h.$('#mainwp-onboarding-retry').prop('hidden'), false);
});

test('CSV allows file-only submit, blocks empty, and recomputes after file and row changes', () => {
    const h = uiHarness('multiple-site', false);
    const button = h.$('#mainwp_managesites_add_import');
    const file = h.$('#connect_first_site_file_bulkupload');
    assert.equal(button.prop('disabled'), true);
    h.trigger('#mainwp_managesites_add_import', 'click');
    assert.equal(h.validations(), 0); assert.equal(h.submits(), 0);
    file.val('sites.csv'); h.trigger('#connect_first_site_file_bulkupload', 'change');
    assert.equal(button.prop('disabled'), false);
    h.trigger('#mainwp_managesites_add_import', 'click');
    assert.equal(h.validations(), 1); assert.equal(h.submits(), 1);
    file.val(''); h.trigger('#connect_first_site_file_bulkupload', 'change');
    assert.equal(button.prop('disabled'), true);
    const row = h.$('row-url').val('example.org');
    h.$('#mainwp_connect_first_site_form .mainwp-managesites-import-site-url').each = callback => callback.call(row);
    h.trigger('#mainwp_connect_first_site_form .mainwp-managesites-import-site-url', 'input');
    assert.equal(button.prop('disabled'), false);
    row.val(''); h.trigger('#mainwp_connect_first_site_form .mainwp-managesites-import-site-url', 'change');
    assert.equal(button.prop('disabled'), true);
    row.val('restored.example'); h.mutations.forEach(callback => callback());
    assert.equal(button.prop('disabled'), false);
    h.$('#mainwp_connect_first_site_form .mainwp-managesites-import-site-url').each = () => {};
    h.mutations.forEach(callback => callback());
    assert.equal(button.prop('disabled'), true);
});

test('wizard stylesheet contains its gutters, reserves status space and suppresses duplicate theme icons', () => {
    const css = fs.readFileSync(require.resolve('../../assets/css/mainwp-onboarding.css'), 'utf8');
    assert.match(css, /#mainwp-quick-setup-wizard\.ui\.grid\s*\{[^}]*margin-left:\s*0;[^}]*margin-right:\s*0;/);
    assert.doesNotMatch(css, /min-height:\s*20rem/);
    const reserved = [...css.matchAll(/([^{}]+)\{([^{}]*min-height:\s*(9|12)rem;[^{}]*)\}/g)];
    assert.equal(reserved.length, 2);
    for (const [, selectors] of reserved) {
        for (const state of ['idle', 'checking', 'connecting', 'detected']) {
            assert.ok(selectors.includes(`#mainwp-onboarding-detection[data-state="${state}"]`), state);
        }
    }
    assert.match(css, /#mainwp-onboarding-detection\.ui\.message::before\s*\{[^}]*display:\s*none/);
    assert.match(css, /\.mainwp-onboarding-password-explanation\[hidden\][^{]*\{[^}]*display:\s*none/);
    assert.match(css, /#mainwp-onboarding-detection \[hidden\]\s*\{\s*display:\s*none !important;/);
    assert.match(css, /#mainwp-onboarding-install\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;[^}]*align-items:\s*center;[^}]*gap:\s*0\.5em 1em;/);
    assert.match(css, /#mainwp-onboarding-install a:not\(\.button\)\s*\{[^}]*white-space:\s*nowrap;/);
    assert.match(css, /#mainwp-onboarding-install \.ui\.button\s*\{[^}]*margin-top:\s*0;/);
    assert.match(css, /@media \(max-width: 991px\)\s*\{\s*#mainwp-onboarding-tiles\s*\{[^}]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\);[^}]*grid-auto-flow:\s*dense;/);
});

test('welcome titles resist attached-label margins and wrap between words with room for the badge', () => {
    const css = fs.readFileSync(require.resolve('../../assets/css/mainwp-onboarding.css'), 'utf8');
    assert.match(css, /\.card \.header\s*\{[^}]*margin-top:\s*0 !important;[^}]*overflow-wrap:\s*normal;[^}]*word-break:\s*normal;/);
    assert.doesNotMatch(css, /padding-right:\s*8em/);
    assert.match(css, /grid-template-columns:\s*minmax\(0, 1fr\) max-content/);
    assert.match(css, /@media \(max-width: 991px\)[\s\S]*?\.content:not\(\.extra\)\s*\{[^}]*padding-top:\s*3em/);
    assert.match(css, /gap:\s*0\.5rem 1\.5rem/);
});

test('minute copy uses localized singular and plural strings for countdown and expiry', () => {
    const php = fs.readFileSync(require.resolve('../../pages/page-mainwp-setup-wizard.php'), 'utf8');
    const localized = Object.fromEntries([...php.matchAll(/'(remaining(?:One|Less)?|expired(?:One|Unknown)?)'\s*=> __\( ('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"), 'mainwp' \)/g)]
        .map(([, key, literal]) => [key, require('node:child_process').execFileSync('php', ['-r', 'echo ' + literal + ';'], { encoding: 'utf8' })]));
    assert.equal(Object.keys(localized).length, 6);
    const h = uiHarness();
    Object.assign(h.context.mainwpSetupLocalize, localized);
    for (const [seconds, expected] of [[59, 'less than a minute'], [60, 'about 1 minute if'], [61, 'about 2 minutes'], [1200, 'about 20 minutes']]) {
        assert.ok(h.context.mainwp_onboarding_remaining_text(seconds).includes(expected));
    }
    for (const [minutes, expected] of [[1, 'within 1 minute,'], [2, 'within 2 minutes,'], [20, 'within 20 minutes,'], [null, 'Its connection window ended']]) {
        h.context.mainwp_onboarding_render_detection({ status: 'expired', reachable: 'ok', child: 'failed', minutes, deadline: 0, result: {} });
        const text = h.$('#mainwp-onboarding-expiry-message').text();
        assert.ok(text.includes(expected), text);
        assert.doesNotMatch(text, /1 minutes|%d/);
    }
});

test('wizard theme mapping loads bundled default-2024 and preserves other themes and custom URLs', () => {
    const php = fs.readFileSync(require.resolve('../../pages/page-mainwp-setup-wizard.php'), 'utf8');
    const start = php.indexOf('$selected_theme = MainWP_Settings::get_instance()->get_selected_theme();');
    const end = php.indexOf("\n        if ( ! empty( $_POST['save_step'] )", start);
    assert.ok(start > 0 && end > start);
    const mapping = php.slice(start, end);
    const runner = `
define('MAINWP_PLUGIN_URL', 'https://dashboard.example/plugins/mainwp/');
define('MAINWP_VERSION', '6');
class MainWP_Settings {
    public static function get_instance() { return new self(); }
    public function get_selected_theme() { return stream_get_contents(STDIN); }
    public function get_custom_theme_folder() { return array('', 'https://dashboard.example/uploads/mainwp/themes/'); }
}
function wp_enqueue_style($handle, $src, $deps, $version) { echo json_encode(array($handle, $src)); }
${mapping}`;
    for (const [theme, file] of [['default-2024', 'default-2024'], ['default', 'default'], ['default-dark', 'default-dark'], ['dark', 'dark'], ['wpadmin', 'wpadmin'], ['minimalistic', 'minimalistic'], ['custom.css', null]]) {
        const [handle, url] = JSON.parse(require('node:child_process').execFileSync('php', ['-r', runner], { input: theme, encoding: 'utf8' }));
        assert.equal(url, file ? `https://dashboard.example/plugins/mainwp/assets/css/themes/mainwp-${file}-theme.css` : 'https://dashboard.example/uploads/mainwp/themes/custom.css');
        if (theme === 'default-2024') assert.equal(handle, 'mainwp-custom-dashboard-extension-default-2024-theme');
        if (file) assert.ok(fs.existsSync(require('node:path').resolve(__dirname, `../../assets/css/themes/mainwp-${file}-theme.css`)));
    }
});

test('status rows keep localized screen-reader text for success, failure and pending states', () => {
    const php = fs.readFileSync(require.resolve('../../pages/page-mainwp-setup-wizard.php'), 'utf8');
    for (const id of ['reachable', 'child']) {
        assert.ok(php.includes(`class="screen-reader-text" id="mainwp-onboarding-${id}"`));
        assert.match(php, new RegExp(`<i id="mainwp-onboarding-${id}-icon"[^>]*aria-hidden="true"`));
    }
    const css = fs.readFileSync(require.resolve('../../assets/css/mainwp-onboarding.css'), 'utf8');
    assert.match(css, /#mainwp-onboarding-detection \.screen-reader-text\s*\{[^}]*position:\s*absolute;[^}]*clip-path:\s*inset\(50%\)/);
    const h = uiHarness();
    assert.match(php, /<span id="mainwp-onboarding-child-label"><\?php esc_html_e\( 'MainWP Child installed and active', 'mainwp' \); \?><\/span> <span class="screen-reader-text"/);
    for (const [status, reachable, child, labels, childLabel] of [
        ['detected', 'ok', 'ok', ['Yes', 'Yes'], 'MainWP Child installed and active'],
        ['waiting', 'ok', 'pending', ['Yes', 'Not yet'], 'MainWP Child not active'],
        ['expired', 'ok', 'failed', ['Yes', 'No'], 'MainWP Child not active'],
        ['unreachable', 'failed', 'pending', ['No', 'Not checked'], 'MainWP Child installed and active'],
        ['checking', 'pending', 'pending', ['Checking...', 'Checking...'], 'MainWP Child installed and active']
    ]) {
        h.context.mainwp_onboarding_render_detection({ status, reachable, child, minutes: null, deadline: null, result: {} });
        assert.equal(h.$('#mainwp-onboarding-detection').attr('data-state'), status);
        assert.deepEqual(['reachable', 'child'].map(id => h.$('#mainwp-onboarding-' + id).text()), labels);
        assert.equal(h.$('#mainwp-onboarding-child-label').text(), childLabel);
    }
});

test('wizard status accents, password help and summary details retain their presentation hooks', () => {
    const css = fs.readFileSync(require.resolve('../../assets/css/mainwp-onboarding.css'), 'utf8');
    assert.match(css, /#mainwp-onboarding-state-icon\s*\{[^}]*width:\s*28px;[^}]*height:\s*28px;[^}]*border-radius:\s*50%;[^}]*color:\s*var\(--mainwp-onboarding-icon-foreground, #fff\) !important;[^}]*background:\s*var\(--onboarding-state-color\)/);
    assert.match(css, /#mainwp-onboarding-detection \.ui\.horizontal\.list > \.item > \.content\s*\{[^}]*color:\s*var\(--mainwp-onboarding-text, #2f3437\)/);
    assert.match(css, /#mainwp-onboarding-detection \.ui\.horizontal\.list > \.item > \.red\.icon\s*\{[^}]*color:\s*var\(--mainwp-onboarding-danger, #b94141\) !important/);
    assert.match(css, /#mainwp-onboarding-detection\.ui\.message\s*\{[^}]*border-left:\s*0 !important/);
    assert.match(css, /#mainwp-onboarding-detection a:not\(\.button\),[\s\S]*?\{[^}]*color:[^}]*!important;[^}]*text-decoration:\s*none/);
    assert.match(css, /#mainwp-onboarding-detection a:not\(\.button\):hover,[\s\S]*?#mainwp-onboarding-detection a:not\(\.button\):focus,[\s\S]*?\{[^}]*text-decoration:\s*underline !important/);
    assert.match(css, /\.mainwp-onboarding-password-note\s*\{[^}]*background:\s*var\(--mainwp-onboarding-info-surface, #ecf3f7\)/);
    assert.match(css, /\.mainwp-onboarding-password-note > \.lock\.icon\s*\{[^}]*width:\s*22px;[^}]*height:\s*22px;[^}]*border-radius:\s*50%/);
    assert.match(css, /\.mainwp-onboarding-disclosure\s*\{[^}]*display:\s*inline/);
    assert.match(css, /\.mainwp-onboarding-check\s*\{[^}]*border-radius:\s*50%;[^}]*background:\s*var\(--mainwp-onboarding-success[^}]*box-shadow:[^}]*color-mix[^}]*color:\s*var\(--mainwp-onboarding-icon-foreground, #fff\)/);
    assert.match(css, /prefers-reduced-motion: reduce[\s\S]*?\.mainwp-onboarding-check\s*\{\s*animation:\s*none/);
    assert.match(css, /#mainwp-onboarding-next-steps small\s*\{[^}]*display:\s*inline/);
    assert.match(css, /#mainwp-onboarding-disable-monitoring\s*\{[^}]*display:\s*block;[^}]*width:\s*fit-content;[^}]*white-space:\s*nowrap/);
    assert.match(summary(), /id="mainwp-onboarding-disable-monitoring"[^>]*>Turn off<\/a>/);
});


test('fresh OK overwrites the stored deadline and expiry uses the reported window', () => {
    const h = uiHarness(); h.fill(); h.advance(400);
    h.reply({ response: 'OK', connection_window_minutes: 2, connection_window_remaining: 1 });
    h.advance(1000); h.reply({ response: 'OK', connection_window_minutes: 5, connection_window_remaining: 120 });
    const entry = JSON.parse([...h.storage.values()][0]);
    assert.equal(entry.minutes, 5);
    assert.equal(entry.deadline, h.now() + 120000);
    const reloaded = uiHarness('single-site', false, h.storage, entry.deadline + 1);
    reloaded.fill(); reloaded.advance(400); reloaded.reply({ response: 'NOMAINWP' });
    assert.equal(reloaded.$('#mainwp-onboarding-expiry-message').text(), "It wasn't connected within 5 minutes, so it turned off for security. Reactivate it on the site's Plugins page, then check again.");
});

test('file-only wizard submit passes the real shared CSV validator with empty rows', () => {
    const h = uiHarness('multiple-site', false);
    const shared = fs.readFileSync(require.resolve('../../assets/js/mainwp-managesites-import.js'), 'utf8');
    vm.runInContext(shared.slice(shared.indexOf('const mainwp_managesites_import_handle_form_before_submit =')), h.context);
    const file = h.$('#connect_first_site_file_bulkupload');
    h.$('input[name="mainwp_managesites_file_bulkupload"]').val = value => file.val(value);
    // The shared validator sees the same empty manual row as the wizard.
    h.$('#mainwp-managesites-row-import-sites .mainwp-managesites-import-rows').each = callback => callback(0);
    file.val('sites.csv'); h.trigger('#connect_first_site_file_bulkupload', 'change');
    h.trigger('#mainwp_managesites_add_import', 'click');
    assert.equal(h.submits(), 1);
    file.val(''); h.trigger('#connect_first_site_file_bulkupload', 'change');
    h.trigger('#mainwp_managesites_add_import', 'click');
    assert.equal(h.submits(), 1);
});


const summaryPhp = String.raw`/**
 * Render the real summary and icon helper without a WordPress database.
 *
 * @package MainWP/Dashboard/Tests
 */

$input = json_decode( stream_get_contents( STDIN ), true );
define( 'ABSPATH', __DIR__ );
set_error_handler(
	static function ( $level, $message ) {
		throw new RuntimeException( $message );
	}
);
$site = (object) array(
	'id'                  => 42,
	'name'                => 'Agency <Site>',
	'url'                 => 'https://site.example/',
	'cust_site_icon_info' => $input['icon'] ?? '',
	'sync_errors'         => '',
	'plugin_upgrades'     => '[]',
	'theme_upgrades'      => '[]',
);
$_GET = array( 'siteid' => ( $input['variant'] ?? 'single' ) === 'single' ? 42 : 0 );

function get_option( $name, $default = false ) {
	return $GLOBALS['input']['options'][ $name ] ?? $default;
}
function mainwp_current_user_can( $type, $right ) {
	return ! in_array( $right, $GLOBALS['input']['denied'] ?? array(), true );
}
function absint( $value ) { return abs( (int) $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function __( $text, $domain ) { return $text; }
function esc_html__( $text, $domain ) { return esc_html( $text ); }
function esc_attr__( $text, $domain ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain ) { echo esc_html( $text ); }
function _n( $single, $plural, $count, $domain ) { return 1 === (int) $count ? $single : $plural; }
function wp_kses_post( $html ) { return $html; }
function admin_url( $path ) { return 'https://dashboard.example.org/wp-admin/' . $path; }
function add_query_arg( $name, $value, $url ) { return $url . '&' . $name . '=' . $value; }
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="nonce-' . esc_attr( $action ) . '">'; }
function checked( $value ) { if ( $value ) { echo 'checked="checked"'; } }

class Summary_DB {
	public static function instance() { return new self(); }
	public function get_websites_count() { return $GLOBALS['input']['count'] ?? 0; }
	public function get_website_by_id( $id ) { return $GLOBALS['site']; }
	public function get_website_option( $site, $name ) {
		if ( 'site_info' === $name ) {
			return empty( $GLOBALS['input']['unsynced'] ) ? array( 'wpversion' => '6.7' ) : array();
		}
		return 'favi_icon' === $name ? ( $GLOBALS['input']['favicon'] ?? '' ) : array();
	}
}
class Summary_Utility {
	public static function get_decoded_array( $value ) { return is_array( $value ) ? $value : ( json_decode( $value, true ) ?: array() ); }
	public static function remove_http_prefix( $url ) { return preg_replace( '#^https?:#', '', $url ); }
}
class Summary_Monitoring {
	public static function get_global_monitoring_settings() { return array( 'active' => $GLOBALS['input']['active'] ?? 1 ); }
}
class Summary_Monitors {
	public static function instance() { return new self(); }
	public function get_primary_monitor( $id ) { return (object) array( 'active' => $GLOBALS['input']['monitor'] ?? -1 ); }
}
class Summary_Notifications {
	public static function get_general_email_settings( $type ) {
		return $GLOBALS['input'][ $type ] ?? array( 'disable' => 0, 'recipients' => 'alerts@example.org' );
	}
}
class Summary_System {
	public static function can_edit_website( $site ) { return true; }
	public static function is_admin() { return $GLOBALS['input']['admin'] ?? true; }
	public static function get_mainwp_dir( $path, $create ) { return array( '', 'https://dashboard.example.org/site-icons/' ); }
}
class Summary_Abilities {
	public static function check_rest_api_permission() { return $GLOBALS['input']['rest'] ?? true; }
}
class Summary_Menu {
	public static function is_disable_menu_item( $level, $slug ) { return $GLOBALS['input']['hide_ai'] ?? false; }
}
class Summary_Updates {
	public static function prepare_update_data( $type, $id ) {
		return array(
			'total_wp_upgrades'          => 0,
			'total_plugin_upgrades'      => 0,
			'total_theme_upgrades'       => 0,
			'total_translation_upgrades' => $GLOBALS['input']['translations'] ?? 0,
			'all_plugins_updates'       => array(),
			'all_themes_updates'        => array(),
		);
	}
	public static function render_bottom( $ids, $all ) { echo '<div id="fixture-update-modal">' . implode( ',', $ids ) . '</div>'; }
}
class Summary_Footer {
	public static function render_footer_content( $sites, $id ) { echo '<div id="fixture-update-footer">' . (int) $id . '</div>'; }
}
foreach ( array(
	'MainWP_DB'                       => Summary_DB::class,
	'MainWP_Utility'                  => Summary_Utility::class,
	'MainWP_Uptime_Monitoring_Handle'  => Summary_Monitoring::class,
	'MainWP_DB_Uptime_Monitoring'      => Summary_Monitors::class,
	'MainWP_Notification_Settings'    => Summary_Notifications::class,
	'MainWP_System_Utility'           => Summary_System::class,
	'MainWP_Abilities_Util'           => Summary_Abilities::class,
	'MainWP_Menu'                     => Summary_Menu::class,
	'MainWP_Updates_Overview'         => Summary_Updates::class,
	'MainWP_System_View'              => Summary_Footer::class,
) as $name => $class ) {
	class_alias( $class, 'MainWP\\Dashboard\\' . $name );
}
require $input['root'] . '/pages/page-mainwp-setup-wizard.php';
require $input['root'] . '/pages/page-mainwp-manage-sites.php';
require $input['root'] . '/class/class-mainwp-connect.php';
class Summary_Wizard extends MainWP\Dashboard\MainWP_Setup_Wizard {
	public function get_next_step_link( $step = '' ) { return admin_url( 'admin.php?page=mainwp-setup&step=' . $step ); }
}
$wizard = ( new ReflectionClass( Summary_Wizard::class ) )->newInstanceWithoutConstructor();
$wizard->mwp_setup_ready();
`;

function summary(input = {}) {
    const { execFileSync } = require('node:child_process');
    const path = require('node:path');
    return execFileSync('php', ['-r', summaryPhp], {
        input: JSON.stringify({ root: path.resolve(__dirname, '../..'), ...input }), encoding: 'utf8'
    });
}

test('summary hero celebrates single and CSV connections, with a neutral empty state', () => {
    const single = summary();
    assert.match(single, /Your first site is connected/);
    assert.match(single, /class="mainwp-onboarding-check"/);
    assert.match(single, /class="mainwp-onboarding-site"[\s\S]*Agency &lt;Site&gt;/);
    assert.doesNotMatch(single, /Site health:|What(?:'|&#039;)s next|<code>/);
    for (const [count, headline] of [[0, 'No sites connected yet'], [1, '1 site connected'], [3, '3 sites connected']]) {
        const html = summary({ variant: 'csv', count });
        assert.ok(html.includes(headline));
        assert.equal(html.includes('class="mainwp-onboarding-check"'), count > 0);
        assert.equal(html.includes('admin.php?page=UpdatesManage'), count > 0);
        assert.equal(html.includes('Add another site'), count > 0);
        assert.equal(html.includes('Add your first site'), count === 0);
        assert.doesNotMatch(html, /id="mainwp-onboarding-wordpress"|id="mainwp-onboarding-updates"/);
        assert.match(html, /id="mainwp-onboarding-uptime"/);
        assert.match(html, /id="mainwp-onboarding-digest"/);
    }
});

test('summary displays the Manage Sites icon independently of the hidden request marker', () => {
    for (const [input, expected, request] of [
        [{}, /big wordpress/, true],
        [{ favicon: 'https://site.example/icon.png' }, /src="https:\/\/site.example\/icon.png"/, false],
        [{ favicon: 'images/icon.png' }, /src="\/\/site.example\/images\/icon.png"/, false],
        [{ icon: 'uploaded:agency.png;selected:;color:' }, /src="https:\/\/dashboard.example.org\/site-icons\/agency.png"/, false],
        [{ icon: 'uploaded:;selected:star;color:blue' }, /star big icon/, false]
    ]) {
        const html = summary(input);
        assert.match(html, expected);
        assert.equal(html.includes('id="mainwp-onboarding-site-icon" hidden'), request);
    }
});

test('summary update tiles retain synced, unsynced and restricted actions and modal renderers', () => {
    const current = summary();
    assert.match(current, /id="mainwp-onboarding-wordpress"/);
    assert.match(current, /Up to date/);
    assert.match(current, /Nothing waiting right now/);
    assert.doesNotMatch(current, /id="mainwp-onboarding-update-all"/);
    const waiting = summary({ translations: 3 });
    assert.match(waiting, /ui yellow segment mainwp-onboarding-tile mainwp-onboarding-updates-waiting/);
    assert.match(waiting, /3 updates waiting/);
    assert.match(waiting, /id="mainwp-onboarding-update-list"/);
    assert.match(waiting, /id="mainwp-onboarding-update-all" data-siteid="42" data-confirm=/);
    assert.match(waiting, /Review updates first/);
    for (const input of [
        { translations: 3, options: { mainwp_hide_update_everything: true } },
        ...['update_wordpress', 'update_plugins', 'update_themes', 'update_translations'].map(right => ({ translations: 3, denied: [right] })),
        { unsynced: true, translations: 3 }
    ]) {
        const html = summary(input);
        assert.doesNotMatch(html, /id="mainwp-onboarding-update-all"/);
        assert.match(html, /View updates/);
        assert.match(html, /admin.php\?page=managesites&amp;updateid=42/);
        if (input.unsynced) {
            assert.match(html, /Updates will show here after the first sync<\/div>/);
            assert.doesNotMatch(html, /3 updates waiting|Up to date|id="mainwp-onboarding-update-list"/);
        }
    }
    assert.match(waiting, /id="fixture-update-footer">42/);
    assert.match(waiting, /id="fixture-update-modal">42/);
});

test('summary uptime and digest tiles honor effective monitoring and email branches', () => {
    for (const [input, value, detail, off] of [
        [{}, 'On', 'Alerts go to alerts@example.org', true],
        [{ uptime: { disable: 1, recipients: 'alerts@example.org' } }, 'On', 'Email alerts are disabled', true],
        [{ uptime: { disable: 0, recipients: '  ' } }, 'On', 'Email alerts are disabled', true],
        [{ monitor: 0 }, 'Off', '', true],
        [{ active: 0 }, 'Off', '', false],
        [{ active: 0, monitor: 1 }, 'On', 'Alerts go to alerts@example.org', false],
        [{ variant: 'csv', count: 2 }, 'On', '', true],
        [{ variant: 'csv', count: 0, active: 0 }, 'Off', '', false]
    ]) {
        const html = summary(input);
        const uptime = html.match(/id="mainwp-onboarding-uptime"[\s\S]*?<div class="mainwp-onboarding-value">([\s\S]*?)<\/div>/);
        assert.ok(uptime);
        assert.equal(uptime[1].replace(/<[^>]*>/g, '').trim(), value);
        if (detail) assert.ok(html.includes(detail));
        else assert.doesNotMatch(html, /Alerts go to|Email alerts are disabled/);
        assert.equal(html.includes('id="mainwp-onboarding-disable-monitoring"'), off);
    }
    const escaped = summary({ uptime: { disable: 0, recipients: '<script>bad()</script>' } });
    assert.match(escaped, /Alerts go to &lt;script&gt;bad\(\)&lt;\/script&gt;/);
    assert.doesNotMatch(escaped, /<script>/);
    for (const [disable, recipients, shown] of [[1, 'digest@example.org', false], [0, '', false], [0, '  ', false], [0, 'digest@example.org', true]]) {
        const html = summary({ daily_digest: { disable, recipients } });
        assert.equal(html.includes('id="mainwp-onboarding-digest"'), shown);
        if (shown) assert.match(html, /Emailed when updates are waiting or a site disconnects<\/div>/);
    }
});

test('summary keeps guarded AI links and the Dashboard submit beside secondary setup controls', () => {
    const html = summary();
    assert.match(html, /id="mainwp-onboarding-next-steps"/);
    assert.match(html, /More you can do:/);
    assert.match(html, /Assign this site to a client/);
    assert.match(html, /Connect Claude or Cursor/);
    assert.match(html, /changes wait for your approval/);
    assert.match(html, /<form[^>]*>[\s\S]*class="ui basic button"[^>]*>Add another site/);
    assert.match(html, /<button type="submit" class="ui green button">Go to Dashboard/);
    assert.match(html, /name="save_step" value="1"/);
    assert.match(html, /mainwp-onboarding-tour-help[\s\S]*Usetiful Privacy Policy/);
    for (const input of [{ admin: false }, { rest: false }, { hide_ai: true }]) {
        const restricted = summary(input);
        assert.doesNotMatch(restricted, /Connect Claude or Cursor|changes wait for your approval|<code>/);
        assert.match(restricted, /id="mainwp-onboarding-next-steps"/);
    }
});
