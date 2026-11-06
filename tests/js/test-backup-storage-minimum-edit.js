'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require.resolve('../../pages/backup/page-mainwp-backup-storage-view.php'), 'utf8');

function storageForm() {
    const values = {};
    const handlers = {};
    const requests = [];
    const row = {'destination-id': 7, name: 'Archive', provider: 'local'};
    function jQuery(selector) {
        const node = {
            val(value) {
                if (arguments.length === 0) return values[selector] ?? '';
                for (const field of String(selector).split(',')) values[field.trim()] = String(value);
                return node;
            },
            is() { return false; },
            dropdown(action, value) { if (action === 'set selected') values[selector] = value; return node; },
            prop() { return node; }, text() { return node; }, hide() { return node; },
            closest() { return node; }, data(key) { return row[key]; },
            on(event, callback) { handlers[selector] = callback; return node; }
        };
        return node;
    }
    jQuery.post = (url, data, callback) => requests.push(callback);
    const context = {jQuery, ajaxurl: '', mainwp_secure_data: data => data,
        storageModal: {modal() {}}, formFeedback() {}, settingsFieldMap: {}};
    vm.createContext(context);
    const collectStart = source.indexOf('let collectSettings = function');
    const collectEnd = source.indexOf("jQuery( '#mainwp-storage-provider' ).on", collectStart);
    const resetStart = source.indexOf('let resetForm = function');
    const resetEnd = source.indexOf('let storageModal = ', resetStart);
    const mapStart = source.indexOf('let settingsFieldMap = {');
    const editStart = source.indexOf("jQuery( '.mainwp-storage-edit' ).on");
    const editEnd = source.indexOf("jQuery( '.mainwp-storage-test' ).on", editStart);
    const script = source.slice(collectStart, collectEnd) + source.slice(resetStart, resetEnd)
        + source.slice(mapStart, editStart) + source.slice(editStart, editEnd) + '\nglobalThis.form = {destinationData, resetForm};';
    vm.runInContext(script.replace(/<\?php[\s\S]*?\?>/g, 'COPY'), context);
    return {values, requests, payload: () => context.form.destinationData('mainwp_storage_save'),
        reset: () => context.form.resetForm(), edit: () => handlers['.mainwp-storage-edit'].call({}, {preventDefault() {}})};
}

test('new destinations explicitly start with a disabled minimum', () => {
    const form = storageForm();
    form.reset();
    assert.equal(form.payload()['settings[minimum_remote_retention_days]'], '0');
});

test('pending or failed edit reads do not replace an existing minimum', () => {
    const form = storageForm();
    form.edit();
    assert.equal(Object.hasOwn(form.payload(), 'settings[minimum_remote_retention_days]'), false);
    form.requests[0]({error: 'Read failed'});
    assert.equal(Object.hasOwn(form.payload(), 'settings[minimum_remote_retention_days]'), false);
});

test('loaded edits preserve the minimum and allow explicit zero', () => {
    const form = storageForm();
    form.edit();
    form.requests[0]({provider: 'local', settings: {minimum_remote_retention_days: 90}});
    assert.equal(form.payload()['settings[minimum_remote_retention_days]'], '90');
    form.values['#mainwp-storage-minimum-remote-retention-days'] = '0';
    assert.equal(form.payload()['settings[minimum_remote_retention_days]'], '0');
});

test('pCloud edits carry the minimum and folder, expose no app, token, region or code and reset every field', () => {
    const form = storageForm();
    form.edit();
    form.requests[0]({provider: 'pcloud', settings: {client_id: 'client', base_path: 'Archive', minimum_remote_retention_days: 30}});
    form.values['#mainwp-storage-provider'] = 'pcloud';
    form.values['#mainwp-storage-pcloud-authorization-code'] = 'pasted-code';
    assert.equal(form.payload()['settings[base_path]'], 'Archive');
    assert.equal(form.payload()['settings[minimum_remote_retention_days]'], '30');
    for (const field of ['client_id', 'client_secret', 'access_token', 'locationid', 'authorization_code']) {
        assert.equal(Object.hasOwn(form.payload(), 'settings[' + field + ']'), false, field);
    }
    form.reset();
    for (const field of ['authorization-code', 'base-path']) {
        assert.equal(form.values['#mainwp-storage-pcloud-' + field], '');
    }
    assert.equal(form.payload()['settings[minimum_remote_retention_days]'], '0');
});

test('Save sends a pasted pCloud code to the connect action and saves without one', () => {
    const handlers = {};
    const values = {};
    const requests = [];
    const feedback = [];
    const busyStates = [];
    let fail;
    const context = {
        jQuery: selector => ({
            on: (event, callback) => {handlers[selector] = callback;},
            val: () => values[selector] ?? ''
        }),
        ajaxurl: '/ajax', location: {pathname: '/admin.php', search: '?page=ManageBackupsStorage'}, requestFailed: 'Network failed',
        destinationData: action => ({action, 'settings[base_path]': 'Archive'}),
        formFeedback: (message, color) => feedback.push([message, color]),
        busy: (button, on) => busyStates.push(on)
    };
    context.jQuery.post = (url, data, callback) => {
        requests.push({url, data, callback});
        return {fail: callback => {fail = callback;}};
    };
    vm.createContext(context);
    const start = source.indexOf("jQuery( '#mainwp-storage-save' ).on");
    const end = source.indexOf("jQuery( '#mainwp-storage-test-only' ).on", start);
    vm.runInContext(source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, 'COPY'), context);
    const click = () => handlers['#mainwp-storage-save'].call({});

    values['#mainwp-storage-provider'] = 'pcloud';
    values['#mainwp-storage-pcloud-authorization-code'] = '  pasted-code  ';
    click();
    assert.equal(requests[0].data.action, 'mainwp_pcloud_connect');
    assert.equal(requests[0].data.authorization_code, 'pasted-code');
    assert.equal(Object.hasOwn(requests[0].data, 'settings[authorization_code]'), false);
    requests[0].callback({error: 'pCloud did not accept the code'});
    assert.deepEqual(feedback.at(-1), ['pCloud did not accept the code', 'red']);
    assert.equal(busyStates.at(-1), false);
    click();
    fail();
    assert.deepEqual(feedback.at(-1), ['Network failed', 'red']);

    values['#mainwp-storage-pcloud-authorization-code'] = '   ';
    click();
    assert.equal(requests[2].data.action, 'mainwp_storage_save');
    assert.equal(Object.hasOwn(requests[2].data, 'authorization_code'), false);
    requests[2].callback({id: 7});
    assert.equal(context.location.href, '/admin.php?page=ManageBackupsStorage&saved=1');

    values['#mainwp-storage-provider'] = 's3';
    values['#mainwp-storage-pcloud-authorization-code'] = 'stale-code';
    click();
    assert.equal(requests[3].data.action, 'mainwp_storage_save');
    assert.equal(Object.hasOwn(requests[3].data, 'authorization_code'), false);
});

test('Test Connection keeps a pasted pCloud code for Save and tests without one', () => {
    const handlers = {};
    const values = {};
    const requests = [];
    const feedback = [];
    const context = {
        jQuery: selector => ({
            on: (event, callback) => {handlers[selector] = callback;},
            val: () => values[selector] ?? ''
        }),
        ajaxurl: '/ajax', requestFailed: 'Network failed',
        destinationData: action => ({action}),
        formFeedback: (message, color) => feedback.push([message, color]),
        busy: () => {},
        COPY: 'COPY'
    };
    context.jQuery.post = (url, data) => {
        requests.push(data);
        const chain = {fail: () => chain, always: () => chain};
        return chain;
    };
    vm.createContext(context);
    const start = source.indexOf("jQuery( '#mainwp-storage-test-only' ).on");
    const end = source.indexOf('let resetForm = function', start);
    // feedback() renders text, so an esc_js()-encoded "&" would show as "&amp;".
    assert.doesNotMatch(source.slice(start, end), /esc_js\( __\( '[^']*&/);
    vm.runInContext(source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, 'COPY'), context);
    const click = () => handlers['#mainwp-storage-test-only'].call({});

    values['#mainwp-storage-provider'] = 'pcloud';
    values['#mainwp-storage-pcloud-authorization-code'] = ' pasted-code ';
    click();
    assert.equal(requests.length, 0);
    assert.deepEqual(feedback.at(-1), ['COPY', 'yellow']);

    values['#mainwp-storage-pcloud-authorization-code'] = '';
    click();
    assert.equal(requests[0].action, 'mainwp_storage_test');
});
