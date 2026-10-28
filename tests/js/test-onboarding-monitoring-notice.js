'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(require.resolve('../../assets/js/mainwp.js'), 'utf8');
const start = source.indexOf("jQuery(document).on('click', '#mainwp-onboarding-enable-monitoring'");
const end = source.indexOf("jQuery(document).on('click', '.mainwp-notice-dismiss'", start);
assert.ok(start >= 0 && end > start, 'The production notice handler must be present.');
const handler = source.slice(start, end);

function harness() {
    const elements = new Map();
    const requests = [];
    let click;
    let done;
    let fail;
    let always;
    function $(selector) {
        if (selector && typeof selector === 'object' && selector.attr) return selector;
        if (!elements.has(selector)) {
            const attributes = {};
            const classes = new Set();
            let content = '';
            const element = {
                attr(name, value) {
                    if (value === undefined) return attributes[name];
                    attributes[name] = value;
                    return element;
                },
                prop(name, value) { return element.attr(name, value); },
                addClass(name) { classes.add(name); return element; },
                removeClass(name) { classes.delete(name); return element; },
                hasClass(name) { return classes.has(name); },
                text(value) {
                    if (value === undefined) return content;
                    content = value;
                    return element;
                },
                find(name) { return $(String(selector) + ' ' + name); },
                on(event, target, callback) {
                    assert.equal(event, 'click');
                    assert.equal(target, '#mainwp-onboarding-enable-monitoring');
                    click = callback;
                    return element;
                }
            };
            elements.set(selector, element);
        }
        return elements.get(selector);
    }
    $.post = (url, data, callback, type) => {
        assert.equal(url, '/admin-ajax.php');
        assert.equal(type, 'json');
        requests.push(data);
        const request = {
            done(fn) { done = fn; return request; },
            fail(fn) { fail = fn; return request; },
            always(fn) { always = fn; return request; }
        };
        return request;
    };
    const button = $('#mainwp-onboarding-enable-monitoring')
        .attr('data-nonce', 'fixture-nonce')
        .attr('data-error', 'Unable to turn on uptime monitoring. Please try again.');
    const notice = $('#mainwp-onboarding-monitoring-notice');
    const step = $('#mainwp-get-started-list a[href$="page=MonitoringSettings"]');
    const error = notice.find('.mainwp-onboarding-monitoring-error').prop('hidden', true);
    const context = vm.createContext({ jQuery: $, document: {}, ajaxurl: '/admin-ajax.php' });
    vm.runInContext(handler, context);
    return {
        $, button, notice, step, error, requests,
        click() { click.call(button); },
        resolve(response) { done(response); always(); },
        reject() { fail(); always(); }
    };
}

test('notice sends its nonce once while pending and confirms the checklist after success', () => {
    const h = harness();
    h.click();
    assert.equal(h.button.prop('disabled'), true);
    assert.equal(h.button.hasClass('loading'), true);
    h.click();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].action, 'mainwp_onboarding_enable_monitoring');
    assert.equal(h.requests[0].security, 'fixture-nonce');
    assert.notEqual(h.step.attr('data-complete'), 'true');
    h.resolve({ success: true, data: { message: 'Uptime monitoring is on.' } });
    assert.equal(h.notice.text(), 'Uptime monitoring is on.');
    assert.equal(h.notice.hasClass('green'), true);
    assert.equal(h.step.attr('data-complete'), 'true');
    assert.equal(h.step.find('i.icon').attr('class'), 'green check circle icon');
    assert.equal(h.button.prop('disabled'), false);
    assert.equal(h.button.hasClass('loading'), false);
});

for (const [label, response, message] of [
    ['authorization failure', { success: false, data: { message: 'Permission denied.' } }, 'Permission denied.'],
    ['empty response', null, 'Unable to turn on uptime monitoring. Please try again.'],
    ['incomplete success', { success: true }, 'Unable to turn on uptime monitoring. Please try again.'],
    ['unexpected payload', '0', 'Unable to turn on uptime monitoring. Please try again.']
]) {
    test('notice keeps its actions and permits retry after ' + label, () => {
        const h = harness();
        h.click();
        h.resolve(response);
        assert.equal(h.error.text(), message);
        assert.equal(h.error.prop('hidden'), false);
        assert.equal(h.notice.hasClass('green'), false);
        assert.notEqual(h.step.attr('data-complete'), 'true');
        assert.equal(h.button.prop('disabled'), false);
        assert.equal(h.button.hasClass('loading'), false);
        h.click();
        assert.equal(h.requests.length, 2);
        assert.equal(h.error.prop('hidden'), true);
    });
}

test('network failure restores the button and leaves the checklist incomplete', () => {
    const h = harness();
    h.click();
    h.reject();
    assert.equal(h.error.text(), h.button.attr('data-error'));
    assert.equal(h.error.prop('hidden'), false);
    assert.equal(h.button.prop('disabled'), false);
    assert.equal(h.button.hasClass('loading'), false);
    assert.notEqual(h.step.attr('data-complete'), 'true');
});

test('server message text never enters an HTML sink', () => {
    const h = harness();
    h.click();
    h.resolve({ success: false, data: { message: '<img src=x onerror=alert(1)>' } });
    assert.equal(h.error.text(), '<img src=x onerror=alert(1)>');
    h.click();
    h.resolve({ success: true, data: { message: '<img src=x onerror=alert(1)>' } });
    assert.equal(h.notice.text(), '<img src=x onerror=alert(1)>');
});
