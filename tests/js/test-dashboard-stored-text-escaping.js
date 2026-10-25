'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const { decodeEntities } = require('./fixtures/site-name-dom');

const source = fs.readFileSync(require.resolve('../../assets/js/mainwp-managesites-import.js'), 'utf8');
const payload = 'Evil" <img src=x onerror=x> & More';
const escape = value => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function harness(reply, addReply, invalid = false, line = payload) {
    const elements = new Map();
    const handlers = new Map();
    const htmlInputs = [];
    let saved;
    let context;
    function make(tag = '') {
        return { tag, content: '', children: [], attributes: {}, length: 1,
            append(...values) { for (const value of values) {
                if (typeof value === 'string') { htmlInputs.push(value); this.content += value; }
                else { this.children.push(value); this.content += value.content; }
            } return this; },
            text(value) { if (value === undefined) return this.content; this.content = String(value); return this; },
            html(value) { if (value === undefined) return escape(this.content); htmlInputs.push(value); this.content = value; return this; },
            attr(name, value) { if (value === undefined) return this.attributes[name]; this.attributes[name] = value; return this; },
            val(value) { if (value === undefined) return this.value; this.value = value; return this; },
            on(event, selector, callback) { handlers.set(selector, callback); return this; },
            ready() { return this; },
            each(callback) { for (const child of jq('#mainwp_managesites_import_fail_logging').children) callback.call(child); return this; },
            find() { return make('a'); },
            show() { return this; }, hide() { return this; }, prop() { return this; }, height() { return 0; }, scrollTop() { return this; }
        };
    }
    function jq(selector) {
        if (typeof selector === 'function') { selector(); return; }
        if (typeof selector === 'object') return selector.on ? selector : jq('document');
        if (selector.startsWith('<')) return make(selector);
        if (!elements.has(selector)) elements.set(selector, make());
        return elements.get(selector);
    }
    jq('#mainwp_managesites_total_import').value = 1;
    jq('#mainwp_managesites_import_csv_line_1').attributes = {
        original: line,
        'encoded-data': JSON.stringify({name: invalid ? '' : 'Fixture', url: 'https://example.test', adminname: 'fixture'})
    };
    let calls = 0;
    jq.post = (url, data, callback) => {
        const current = calls++ === 0 ? reply : addReply;
        vm.runInContext('import_stop_by_user = true', context);
        if (current !== 'transport') callback(current);
        return { fail(handler) { if (current === 'transport') handler({}, 'error', payload); return this; } };
    };
    context = vm.createContext({ jQuery: jq, document: { createTextNode: value => ({content: String(value)}) },
        MainWP: { I18n: { t: text => text } }, mainwp_entities_text: decodeEntities, mainwp_secure_data: data => data, ajaxurl: '/ajax',
        Blob, saveAs: blob => { saved = blob; }, setTimeout() {}, console });
    vm.runInContext(source, context);
    vm.runInContext('mainwp_managesites_import_sites()', context);
    return { jq, htmlInputs, saved: () => saved, download: () => handlers.get('#mainwp_managesites_btn_save_csv')() };
}

for (const [label, reply, addReply, invalid] of [
    ['validation', {}, {}, true],
    ['check failure', {response: 'ERROR: ' + payload, check_me: 1}],
    ['add failure', {response: 'OK', check_me: 1}, {error: payload, add_me: 1}],
    ['transport failure', {response: 'OK', check_me: 1}, 'transport']
]) {
    test('import renders CSV and child text safely on ' + label, () => {
        const h = harness(reply, addReply, invalid);
        assert.equal(h.htmlInputs.filter(value => value.includes('<img')).length, 0, 'untrusted text must never enter an HTML parser');
        const log = h.jq('#mainwp_managesites_import_logging .log');
        assert.equal(log.children[0].tag, '<strong>');
        assert.equal(log.children[0].content, '[1] << ' + payload);
        assert.equal(h.jq('#mainwp_managesites_import_fail_logging').children[0].content, payload);
    });
}

test('failed CSV download preserves normal punctuation and literal entity spellings', async () => {
    const line = 'Example & More,"quoted",&lt;literal&gt;';
    const h = harness({}, {}, true, line);
    h.download();
    assert.equal(await h.saved().text(), line + '\r');
    assert.equal(h.jq('#mainwp_managesites_import_logging .log').children[0].content, '[1] << ' + line);
});

test('returned error entities are decoded once before text insertion', () => {
    for (const reply of [
        {response: 'ERROR: O&#039;Brien &amp; More &amp;lt;literal&amp;gt;', check_me: 1},
        {error: 'O&#039;Brien &amp; More &amp;lt;literal&amp;gt;', add_me: 1}
    ]) {
        const h = reply.error ? harness({response: 'OK', check_me: 1}, reply) : harness(reply);
        assert.ok(h.jq('#mainwp_managesites_import_logging .log').content.includes("O'Brien & More &lt;literal&gt;"));
    }
});

test('server-built success link and line breaks remain markup', () => {
    const response = 'Site successfully added - Visit the Site\'s <a href="admin.php?page=managesites&dashboard=123">Dashboard</a> now.<br/>';
    const h = harness({response: 'OK', check_me: 1}, {response, add_me: 1, siteid: 123}, false, 'Example & More');
    assert.ok(h.htmlInputs.includes('[1] >> ' + response + '<br/>'));
    assert.equal(h.jq('#mainwp_managesites_import_logging .log').children[0].content, '[1] << Example & More');
});

test('latest plugin version attribute is displayed as text', () => {
    const php = fs.readFileSync(require.resolve('../../pages/page-mainwp-plugins.php'), 'utf8');
    const callback = php.match(/\$\( '\.lastest-version-hidden' \)\.each\(function\(\)\{([\s\S]*?)\}\);/)[1];
    for (const version of [payload, '1.2.3 & More', '1.2 &lt;literal&gt;']) {
        let output;
        const cell = { text(value) { output = value; }, html() { assert.fail('version reached an HTML sink'); } };
        const item = { attr: () => version, closest: () => ({find: () => cell}) };
        vm.runInNewContext(callback, {$: () => item});
        assert.equal(output, version);
    }
});
