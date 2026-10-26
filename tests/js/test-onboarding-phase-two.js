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

function uiHarness(mode = 'single-site', tabs = true) {
    let now = 1000;
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
    const window = {};
    const copy = {
        checking: 'Checking...', yes: 'Yes', notYet: 'Not yet', no: 'No', notChecked: 'Not checked',
        unreachable: "We can't reach %s. Check the URL and that the site is online.",
        install: 'Install MainWP Child on %s →',
        fallback: "If the link doesn't open the plugin installer, install MainWP Child from Plugins > Add New on %s.",
        remaining: 'About %d minutes left to connect.', remainingOne: 'About 1 minute left to connect.', remainingLess: 'Less than a minute left to connect.', defaultTime: 'By default this is 20 minutes after activation.',
        windowClosed: 'The connection window may have ended. If connecting fails, check that MainWP Child is still active.',
        expired: 'MainWP Child may have switched itself off because the connection window expired. Check that it is active on %s, reactivate it if needed, and click Retry.',
        error: 'Could not connect. Try again.', password: 'Enter the administrator password.'
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
                length: ['#mainwp-onboarding-detection', '#mainwp_connect_first_site_form'].includes(selector) ? 1 : 0,
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
    return { $, context, requests, messages, textWrites, reloads, timers, advance, document, now: () => now,
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
const matrixDefault = 'By default this is 20 minutes after activation.';
const matrixSite = 'https://example.org/wordpress/';

function matrixView(status, deadline, timers = [], request = null, zone = matrixZone, rows = matrixStatuses[status]) {
    return {
        status, timers: [...timers].sort(), request,
        rows: rows.slice(0, 2),
        rowClasses: rows.slice(0, 2).map(label => [label === 'Yes', label === 'No']),
        rowIcons: rows.slice(0, 2).map(label => ({
            Yes: 'green check icon', No: 'red times icon', 'Checking...': 'notched circle loading icon',
            'Not yet': 'orange circle icon', 'Not checked': 'grey circle icon'
        })[label]),
        install: rows[2], installHref: rows[2] ? matrixSite + 'wp-admin/plugin-install.php?s=mainwp+child&tab=search&type=term' : '',
        timer: rows[3], expiry: rows[4], retry: rows[5],
        timerLine: deadline === 'none' ? matrixDefault : deadline === 'future' ? 'Less than a minute left to connect.' : matrixClosed,
        detectionMessage: status === 'unreachable' ? `We can't reach ${matrixSite}. Check the URL and that the site is online.`
            : status === 'error' ? '<p>Blocked</p>' : '',
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
            next.timerLine = 'About 2 minutes left to connect.';
            return next;
        }
        if (event === 'reply OK remaining 0') return matrixView('detected', 'passed');
        if (event === 'reply NOMAINWP') return deadline === 'passed'
            ? matrixView('expired', deadline, [], null, status === 'expired' ? matrixZone : ['#mainwp-message-zone'])
            : matrixView('waiting', deadline, ['cap', 'poll']);
        return matrixView(event === 'reply ERROR' ? 'error' : 'unreachable', deadline);
    }
    if (event === 'changed field') return matrixView('idle', 'none', ['debounce'], status === 'connecting' ? request : null);
    if (event === 'unchanged change') return base;
    if (event === 'Retry') return status === 'connecting' ? matrixView('idle', 'none', [], request)
        : matrixView('checking', 'none', ['cap'], 'mainwp_checkwp');
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
        install: visible('install'), installHref: row('install-link').attr('href'),
        timer: visible('timer'), timerLine: row('time-left').text(),
        expiry: visible('expiry'), retry: visible('retry'), detectionMessage: row('detection-message').html(),
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
        expected: response === 'OK' ? { ...matrixView('detected', 'future', ['countdown']), timerLine: 'About 2 minutes left to connect.' }
            : matrixView(response === 'NOMAINWP' ? 'expired' : response === 'ERROR' ? 'error' : 'unreachable', 'passed', [], null,
                response === 'NOMAINWP' ? ['#mainwp-message-zone'] : matrixZone)
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
        expected: matrixView('expired', 'passed', [], null, ['#mainwp-message-zone'])
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
        expected: matrixView('expired', 'passed', [], null, ['#mainwp-message-zone'])
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
        expected: { ...matrixView('detected', 'future', ['countdown']), timerLine: 'About 2 minutes left to connect.' }
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
