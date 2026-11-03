'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(require.resolve('../../assets/js/mainwp-managesites-import.js'), 'utf8');
const display = 'Site,https://import.example.test,admin,********,Tag,********,http-user,********,Y,auto';

function harness(mode) {
    const elements = new Map();
    const handlers = new Map();
    const requests = [];
    let saved;
    const make = () => ({ content: '', attributes: {}, children: [],
        append(...values) { for (const value of values) { if (typeof value === 'object') { this.children.push(value); this.content += value.content; } else this.content += value; } return this; },
        text(value) { if (value === undefined) return this.content; this.content = String(value); return this; },
        html(value) { this.content = value; return this; },
        val() { return this.value; },
        attr(name, value) { if (value === undefined) return this.attributes[name]; this.attributes[name] = value; return this; },
        on(event, selector, callback) { handlers.set(selector, callback); return this; },
        ready() {}, show() { return this; }, hide() { return this; }, prop() { return this; },
        scrollTop() { return this; }, height() { return 0; },
        each(callback) { for (const child of this.children) callback.call(child); return this; }
    });
    const jq = selector => {
        if (typeof selector === 'function') { selector(); return; }
        if (typeof selector === 'object' && selector.content !== undefined) return selector;
        if (typeof selector === 'string' && selector.startsWith('<')) return make();
        if (selector === '#mainwp_managesites_import_fail_logging span') return jq('#mainwp_managesites_import_fail_logging');
        if (!elements.has(selector)) elements.set(selector, make());
        return elements.get(selector);
    };
    jq('#mainwp_managesites_total_import').value = 1;
    jq('#mainwp_managesites_do_import').value = 0;
    jq('#mainwp_managesites_import_csv_line_1').attributes = {
        original: display,
        'encoded-data': JSON.stringify({ name: mode === 'validation' ? '' : 'Site', url: 'https://import.example.test', adminname: 'admin', adminpwd: 'fixture-admin-password', uniqueId: 'fixture-security-id', http_user: 'http-user', http_pass: 'fixture-http-password', wpgroups: 'Tag', verify_certificate: '0' })
    };
    jq.post = (url, data, callback) => {
        requests.push(data);
        const transport = mode === 'transport' && data.action === 'mainwp_addwp';
        if (!transport) callback(data.action === 'mainwp_checkwp'
            ? { response: mode === 'check' ? 'HTTPERROR' : 'OK', check_me: 1 }
            : { response: 'ERROR: fixture failure', add_me: 1 });
        return { fail(handler) { if (transport) handler({}, 'error', 'fixture failure'); return this; } };
    };
    const context = vm.createContext({ jQuery: jq, document: { createTextNode: text => ({ content: text }) }, MainWP: { I18n: { t: text => text } }, mainwp_secure_data: data => data,
        mainwp_entities_text: text => text, ajaxurl: '/ajax', Blob, saveAs: blob => { saved = blob; } });
    vm.runInContext(source, context);
    vm.runInContext('mainwp_managesites_import_sites()', context);
    return { jq, requests, download: async () => { handlers.get('#mainwp_managesites_btn_save_csv')(); return saved.text(); } };
}

for (const mode of ['validation', 'check', 'add', 'transport']) {
    test(`${mode} failure logs and downloads the server display row as text`, async () => {
        const h = harness(mode);
        const log = h.jq('#mainwp_managesites_import_logging .log');
        const failed = h.jq('#mainwp_managesites_import_fail_logging');
        assert.equal(log.children[0].content, '[1] << ' + display);
        assert.equal(failed.children[0].content, display);
        assert.equal(await h.download(), display + '\r');
        assert.doesNotMatch(log.content + failed.content + await h.download(), /fixture-admin-password|fixture-security-id|fixture-http-password/);
        if (mode === 'add' || mode === 'transport') {
            const payload = h.requests.find(request => request.action === 'mainwp_addwp');
            assert.equal(decodeURIComponent(payload.managesites_add_adminpwd), 'fixture-admin-password');
            assert.equal(payload.managesites_add_uniqueId, 'fixture-security-id');
            assert.equal(payload.managesites_add_http_pass, 'fixture-http-password');
        }
    });
}
