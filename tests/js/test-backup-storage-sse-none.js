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
    const calls = [];
    const row = {'destination-id': 7, name: 'Bucket', provider: 's3'};
    function jQuery(selector) {
        const node = {
            val(value) {
                if (arguments.length === 0) return values[selector] ?? '';
                for (const field of String(selector).split(',')) values[field.trim()] = String(value);
                return node;
            },
            is() { return false; },
            dropdown(action, value) {
                calls.push([selector, action, value]);
                if (action === 'set selected') values[selector] = value;
                return node;
            },
            prop() { return node; }, text() { return node; }, hide() { return node; },
            closest() { return node; }, data(key) { return row[key]; },
            on(event, callback) { handlers[selector] = callback; return node; }
        };
        return node;
    }
    jQuery.post = (url, data, callback) => requests.push(callback);
    const context = {jQuery, ajaxurl: '', mainwp_secure_data: data => data,
        storageModal: {modal() {}}, formFeedback() {}};
    vm.createContext(context);
    const resetStart = source.indexOf('let resetForm = function');
    const resetEnd = source.indexOf('let storageModal = ', resetStart);
    const mapStart = source.indexOf('let settingsFieldMap = ');
    const editEnd = source.indexOf("jQuery( '.mainwp-storage-test' ).on", mapStart);
    const script = source.slice(resetStart, resetEnd) + source.slice(mapStart, editEnd);
    vm.runInContext(script.replace(/<\?php[\s\S]*?\?>/g, 'COPY'), context);
    return {values, requests, calls,
        edit: () => handlers['.mainwp-storage-edit'].call({}, {preventDefault() {}})};
}

const sseSelections = calls => calls.filter(([selector, action]) => selector === '#mainwp-storage-sse' && action === 'set selected');

test('a stored empty SSE value reopens with None selected', () => {
    const form = storageForm();
    form.edit();
    form.requests[0]({provider: 's3', settings: {sse: ''}});
    assert.equal(form.values['#mainwp-storage-sse'], 'none');
});

test('a destination saved before SSE existed reopens with None selected', () => {
    const form = storageForm();
    form.edit();
    form.requests[0]({provider: 's3', settings: {}});
    assert.equal(form.values['#mainwp-storage-sse'], 'none');
});

for (const mode of ['AES256', 'aws:kms']) {
    test(`a stored ${mode} value reopens selected`, () => {
        const form = storageForm();
        form.edit();
        form.requests[0]({provider: 's3', settings: {sse: mode}});
        assert.equal(form.values['#mainwp-storage-sse'], mode);
        assert.deepEqual(sseSelections(form.calls).map(call => call[2]), [mode]);
    });
}
