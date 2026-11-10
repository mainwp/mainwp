'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../widgets/widget-mainwp-connection-status.php'), 'utf8');
function harness(translation) {
    const nodes = new Map(), requests = [], reloads = [];
    let click;
    function $(key) {
        if (typeof key === 'object') return key;
        const node = {
            value: '', disabled: false, children: [], classes: [],
            on(event, callback) { click = callback; return this; },
            prop(name, val) { this.disabled = val; return this; },
            empty() { this.children = []; return this; },
            text(val) { this.value = val; return this; },
            appendTo(parent) { parent.children.push(this); return this; },
            append(child) { this.children.push(child); return this; },
            addClass(cls) { this.classes.push(cls); return this; },
        };
        if (key === '<p>') return node;
        if (!nodes.has(key)) nodes.set(key, node);
        return nodes.get(key);
    }
    $.ajax = options => {
        const callbacks = {};
        const req = { options, done(fn) { callbacks.done = fn; return this; }, fail(fn) { callbacks.fail = fn; return this; }, always(fn) { callbacks.always = fn; return this; }, resolve(data) { callbacks.done(data); callbacks.always?.(); }, reject() { callbacks.fail(); callbacks.always?.(); } };
        requests.push(req);
        return req;
    };
    const context = { jQuery: $, ajaxurl: '/ajax', mainwp_secure_data: data => data,
        document: { createTextNode: text => ({text}) },
        mainwp_reconnect_error_nodes: text => ({safeErrorText: text}),
        mainwp_entities_text: text => text.replace(/&#039;/g, "'").replace(/&amp;/g, '&'),
        mainwp_widgets_connections_status_get_table: tab => ({ajax: {reload(callback) { reloads.push(tab); callback({recordsTotal: 9}); }}}),
    };
    const start = source.indexOf('let reconnecting = false;');
    const end = source.indexOf('setTimeout(() => {', start);
    assert.ok(start > 0 && end > start);
    let code = source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, php => php.match(/__\( '([^']*)'/)[1]);
    // Match esc_js(): HTML entities remain in the JavaScript string.
    if (translation) code = code.replaceAll('Site has been reconnected successfully!', translation.replaceAll('&', '&amp;').replaceAll("'", "\\'"));
    vm.runInNewContext(code, context);
    return {nodes, requests, reloads, click: () => click.call($('#mainwp-connection-status-reconnect-all'))};
}
test('all 31 IDs run sequentially; duplicate clicks blocked; failures continue; counts and rows refresh', () => {
    const h = harness();
    h.click(); h.click();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.data.reconnect_all, 1);
    h.requests[0].resolve({sites: Array.from({length:31}, (_, i) => ({id:i + 1, name:'<img onerror=alert(1)>'}))});
    for (let i = 1; i <= 31; i++) {
        assert.equal(h.requests.length, i + 1, 'only one site request in flight');
        assert.equal(h.requests[i].options.data.siteid, i);
        assert.equal(h.requests[i].options.data.action, 'mainwp_reconnectwp');
        assert.equal(h.requests[i].options.dataType, 'text');
        if (i === 2) h.requests[i].reject();
        else h.requests[i].resolve(i === 3 ? 'ERROR <script>alert(1)</script>' : i === 4 ? 'reconnect_failed' : 'Site has been reconnected successfully!');
    }
    assert.deepEqual(h.reloads, ['all-sites', 'connected', 'disconnected']);
    const result = h.nodes.get('#mainwp-connection-status-reconnect-results');
    assert.equal(result.children.length, 32);
    assert.equal(result.children[0].value, '31 / 31');
    assert.equal(result.children[2].classes[0], 'red');
    assert.equal(result.children[3].children[0].safeErrorText, '<script>alert(1)</script>');
    assert.equal(result.children[4].classes[0], 'red');
    assert.equal(h.nodes.get('#mainwp-connection-status-reconnect-all').disabled, false);
    assert.equal(h.nodes.get('#mainwp-connection-status-count-disconnected').value, 9);
});
test('empty queue, invalid response, and transport failure release the button', () => {
    for (const response of [{sites:[]}, {error:'denied'}, null]) {
        const h = harness(); h.click();
        if (response) h.requests[0].resolve(response); else h.requests[0].reject();
        assert.equal(h.requests.length, 1);
        assert.equal(h.nodes.get('#mainwp-connection-status-reconnect-all').disabled, false);
        assert.equal(h.reloads.length, 3);
    }
});

test('HTML-escaped translated success is recognized as success', () => {
    const h = harness("Site's connection restored & ready");
    h.click();
    h.requests[0].resolve({sites:[{id:1, name:'Site'}]});
    h.requests[1].resolve('Site&#039;s connection restored &amp; ready');
    assert.equal(h.nodes.get('#mainwp-connection-status-reconnect-results').children[1].classes[0], 'green');
});
