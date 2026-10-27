'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function browser() {
    const ready = [];
    const events = [];
    const requests = [];
    const elements = new Map();
    const document = { getElementById: () => null };
    function element(selector) {
        if (!elements.has(selector)) {
            const state = { value: '', checked: false, data: {} };
            const methods = {
                length: 0,
                val(value) { if (arguments.length) { state.value = value; return proxy; } return state.value; },
                prop(name, value) { if (arguments.length > 1) { state[name] = value; return proxy; } return state[name]; },
                data(name, value) { if (arguments.length > 1) { state.data[name] = value; return proxy; } return state.data[name]; },
                is() { return state.checked; },
                on(event, selector, handler) { events.push({ event, selector, handler }); return proxy; },
                each() { return proxy; },
                dropdown(action) { return action === 'get value' ? '' : proxy; },
            };
            const proxy = new Proxy(methods, { get(target, key) { return key in target ? target[key] : () => proxy; } });
            elements.set(selector, proxy);
        }
        return elements.get(selector);
    }
    function jQuery(selector) {
        if (typeof selector === 'function') { ready.push(selector); return; }
        if (selector && typeof selector === 'object' && selector !== document) { return selector; }
        return element(selector);
    }
    jQuery.fn = {};
    jQuery.post = (url, data, callback) => {
        requests.push(data);
        callback(data.action === 'mainwp_checkwp' ? { response: 'OK' } : { response: 'OK', siteid: 0 });
    };
    const context = vm.createContext({ document, jQuery, mainwpParams: {}, window: {}, ajaxurl: '/ajax', console,
        MainWP: { I18n: { t: value => value } },
        setTimeout() {}, setInterval() {}, clearInterval() {}, clearTimeout() {},
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/js/mainwp.js'), 'utf8'), context);
    // Run the existing Add Site initializer, including its delegated change handlers.
    const initializer = ready.find(callback => callback.toString().includes("'#mainwp_managesites_add_wpurl'"));
    assert.ok(initializer, 'Add Site initializer must be loaded');
    initializer(jQuery);
    vm.runInContext(`managesites_init = () => {}; mainwp_managesites_add_valid = () => true;
        feedback = () => {}; mainwp_secure_data = data => data;
        mainwp_feedback_linked_message = () => ''; mainwp_set_message_zone = () => {};`, context);
    element('#mainwp_managesites_add_wpurl_protocol').val('https');
    element('#mainwp_managesites_add_wpurl').val('fixture.example.com');
    const checkbox = element('#mainwp_managesites_verify_certificate');
    checkbox.prop('checked', true);
    return {
        checkbox, requests,
        change(checked) {
            checkbox.prop('checked', checked);
            const listener = events.find(event => event.event === 'change' && event.selector === '#mainwp_managesites_verify_certificate');
            assert.ok(listener, 'Checkbox changes must be tracked');
            listener.handler.call(checkbox);
        },
        add() { vm.runInContext('mainwp_managesites_add()', context); },
    };
}

for (const choice of ['untouched', 'unchecked', 'rechecked']) {
    test(`Add Site sends the ${choice} choice through preflight and registration, then resets`, () => {
        const page = browser();
        if (choice !== 'untouched') { page.change(false); }
        if (choice === 'rechecked') { page.change(true); }
        page.add();
        assert.equal(page.requests.length, 2);
        assert.equal(page.requests[0].verify_certificate, choice === 'unchecked' ? 0 : 1);
        assert.equal(page.requests[1].ssl_verify, choice === 'unchecked' ? 0 : 1);
        for (const request of page.requests) {
            assert.equal(request.ssl_verify_explicit, choice === 'untouched' ? 0 : 1);
        }
        assert.equal(page.checkbox.prop('checked'), true, 'Success restores the checked default');
        assert.equal(page.checkbox.data('sslVerifyExplicit'), false, 'Success clears the explicit-choice state');
        page.add();
        assert.equal(page.requests[2].ssl_verify_explicit, 0);
        assert.equal(page.requests[3].ssl_verify_explicit, 0);
    });
}
