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
    const editStart = source.indexOf("jQuery( '.mainwp-storage-edit' ).on");
    const editEnd = source.indexOf("jQuery( '.mainwp-storage-test' ).on", editStart);
    const script = source.slice(collectStart, collectEnd) + source.slice(resetStart, resetEnd)
        + source.slice(editStart, editEnd) + '\nglobalThis.form = {destinationData, resetForm};';
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
