'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(process.env.MAINWP_UI_JS_SOURCE || require.resolve('../../assets/js/mainwp-ui.js'), 'utf8');
const save = source.slice(source.indexOf('let mainwp_help_modal_content_onclick'), source.indexOf('let mainwp_help_modal_start_content_onclick'));

function harness(tours = 0, savedTours = 0) {
    const elements = new Map();
    const requests = [];
    const reloads = [];
    let success;
    let failure;
    let complete;
    function $(selector) {
        if (typeof selector === 'object') return selector;
        if (!elements.has(selector)) {
            const classes = new Set();
            const attrs = { 'data-saved': String(savedTours), 'data-error': 'Save failed', 'data-pending': 'Unsaved settings' };
            const element = {
                value: '',
                disabled: false,
                checkbox() { return selector.includes('tours') ? Boolean(tours) : false; },
                is() { return selector.includes('tours') ? Boolean(tours) : false; },
                html(value) { element.value = value; return element; },
                fadeIn() { return element; },
                hasClass(name) { return classes.has(name); },
                addClass(names) { names.split(' ').forEach(name => classes.add(name)); return element; },
                removeClass(names) { names.split(' ').forEach(name => classes.delete(name)); return element; },
                attr(name, value) { if (value === undefined) return attrs[name]; attrs[name] = value; return element; },
                prop(name, value) { element[name] = value; return element; },
                text(value) { element.value = value; return element; },
                hide() { return element; },
                show() { return element; },
                find() { return element; }
            };
            elements.set(selector, element);
        }
        return elements.get(selector);
    }
    $.post = function (url, data, callback) {
        requests.push(data);
        success = callback;
        const request = {
            fail(callback) { failure = callback; return request; },
            always(callback) { complete = callback; return request; }
        };
        return request;
    };
    const context = vm.createContext({ jQuery: $, ajaxurl: '/ajax', mainwp_secure_data: value => value, mainwp_forceReload: value => reloads.push(value) });
    vm.runInContext(save, context);
    return {
        $, requests, reloads,
        setTours(value) { tours = value; },
        save() { vm.runInContext('mainwp_help_modal_content_onclick()', context); },
        respond(value) { success(value); if (complete) complete(); },
        fail() { failure(); complete(); }
    };
}

test('saving a changed tour setting reloads before offering the tour', () => {
    const h = harness(1, 0);
    h.save();
    assert.equal(h.requests[0].enable_tour, 1);
    h.respond('ok');
    assert.equal(h.reloads.length, 1);
});

test('saving unchanged tours updates assistant state without reload', () => {
    const h = harness();
    h.save();
    h.respond('ok');
    assert.equal(h.reloads.length, 0);
    assert.equal(h.$('#mainwp-start-chat-card').attr('aria-disabled'), 'true');
    assert.equal(h.$('#mainwp-update-permissions-button').disabled, false);
});

test('a checkbox changed during a save keeps the pending message', () => {
    const h = harness();
    h.save();
    h.setTours(1);
    h.respond('ok');
    const message = h.$('#mainwp-support-settings-message');
    assert.equal(message.value, 'Unsaved settings');
    assert.equal(message.hasClass('positive'), false);
    assert.equal(h.$('#mainwp-start-tour-card').hasClass('mainwp-disabled-link'), true);
    assert.equal(h.reloads.length, 0);
});

for (const transportFailure of [false, true]) {
    test(`failed save preserves disabled access and permits retry (${transportFailure ? 'transport' : 'response'})`, () => {
        const h = harness(1, 0);
        h.$('#mainwp-start-chat-card').attr('aria-disabled', 'true');
        h.save();
        h.save();
        assert.equal(h.requests.length, 1, 'only one request may be in flight');
        if (transportFailure) h.fail(); else h.respond('-1');
        assert.equal(h.reloads.length, 0);
        assert.equal(h.$('#mainwp-start-chat-card').attr('aria-disabled'), 'true');
        assert.equal(h.$('#mainwp-support-settings-message').value, 'Save failed');
        assert.equal(h.$('#mainwp-update-permissions-button').disabled, false);
    });
}

test('starting a tour preserves the support options for reopening', () => {
    const start = source.slice(source.indexOf('let mainwp_help_modal_start_content_onclick'), source.indexOf("jQuery(document).on('click', '.mainwp-theme-picker"));
    const hidden = [];
    const tours = [];
    const context = vm.createContext({
        USETIFUL: { tour: { start: id => tours.push(id) } },
        jQuery: selector => ({
            hide() { hidden.push(selector); return this; },
            fadeOut() { hidden.push(selector); return this; }
        })
    });
    vm.runInContext(start, context);
    vm.runInContext('mainwp_help_modal_start_content_onclick(13112)', context);
    assert.deepEqual(tours, [13112]);
    assert.deepEqual(hidden, [], 'tour must not hide the support options or settings help');
});
