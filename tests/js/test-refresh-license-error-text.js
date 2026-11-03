'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const { document, escapeHtml, loadSiteNameHelper } = require('./fixtures/site-name-dom');

const main = fs.readFileSync(require.resolve('../../assets/js/mainwp.js'), 'utf8');
const extensions = fs.readFileSync(require.resolve('../../assets/js/mainwp-extensions.js'), 'utf8');

function refresh(response, failedRequest = false) {
    const handlers = new Map();
    const requests = [];
    const classes = new Set();
    let reloads = 0;
    const status = {
        value: '',
        text(value) { this.value = value; return this; },
        html() { throw new Error('Refresh License must keep its text sink'); },
    };
    const button = {
        closest() { return { find: () => status }; },
        data() { return 'Please try again.'; },
        hasClass(name) { return classes.has(name); },
        addClass(names) { names.split(' ').forEach(name => classes.add(name)); return this; },
        removeClass(names) { names.split(' ').forEach(name => classes.delete(name)); return this; },
    };
    function jQuery(target) {
        if (target === button) return button;
        if (typeof target === 'function') return;
        return { on(event, selector, handler) { handlers.set(selector, handler); }, ready() {} };
    }
    jQuery.post = (url, data, callback, type) => {
        requests.push({ data, type });
        if (!failedRequest) callback(response);
        return { fail(handler) { if (failedRequest) handler(); } };
    };
    const context = vm.createContext({ document, jQuery, ajaxurl: '/ajax', mainwpParams: {},
        mainwp_secure_data: data => data,
        location: { reload() { reloads++; } },
    });
    loadSiteNameHelper(vm, main, context);
    vm.runInContext(extensions, context);
    const handler = handlers.get('.mainwp-extensions-refresh-license');
    assert.equal(typeof handler, 'function', 'The production Refresh License handler must be registered');
    handler.call(button);
    assert.equal(requests[0].data.action, 'mainwp_extension_testextensionapilogin');
    assert.equal(requests[0].type, 'json');
    return { text: status.value, html: escapeHtml(status.value), classes, reloads };
}

test('Refresh License decodes the server-escaped apostrophe and keeps image markup as text', () => {
    const result = refresh({ error: 'The MainWP license server&#039;s SSL certificate failed. Details: &lt;img src=x onerror=alert(1)&gt; &amp; &quot;quoted&quot;' });
    assert.equal(result.text, 'The MainWP license server\'s SSL certificate failed. Details: <img src=x onerror=alert(1)> & "quoted"');
    assert.ok(!result.text.includes('&#039;'));
    assert.ok(!result.html.includes('<img'));
    assert.ok(result.html.includes('&lt;img'));
    assert.ok(!result.classes.has('loading'));
});

test('Refresh License decodes entities once', () => {
    assert.equal(refresh({ error: '&amp;lt;img&amp;gt;' }).text, '&lt;img&gt;');
});

test('Refresh License retains missing-error and request-failure fallbacks', () => {
    assert.equal(refresh({}).text, 'Please try again.');
    assert.equal(refresh(null, true).text, 'Please try again.');
});

test('Refresh License still reloads after success', () => {
    const result = refresh({ result: 'SUCCESS' });
    assert.equal(result.reloads, 1);
    assert.equal(result.text, '');
});
