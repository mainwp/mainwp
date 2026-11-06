'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require.resolve('../../pages/backup/page-mainwp-backup-storage-view.php'), 'utf8');

function saveForm() {
    const handlers = {};
    const values = {
        '#mainwp-storage-provider': 'pcloud',
        '#mainwp-storage-pcloud-authorization-code': ' pasted-code '
    };
    const requests = [];
    const confirmations = [];
    const feedback = [];
    const busyStates = [];
    const context = {
        jQuery: selector => ({
            on(event, callback) { handlers[selector] = callback; },
            val(value) {
                if (arguments.length === 0) return values[selector] ?? '';
                values[selector] = value;
            }
        }),
        ajaxurl: '/ajax',
        formOpen: true,
        location: {pathname: '/admin.php', search: '?page=ManageBackupsStorage'},
        requestFailed: 'Network failed',
        destinationData: action => ({action, id: 20, name: 'Archive', 'settings[base_path]': 'Backups'}),
        formFeedback: (message, color) => feedback.push([message, color]),
        busy: (button, on) => busyStates.push(on),
        mainwp_confirm: (message, confirm, cancel, updateType, allowMultiple) => {
            confirmations.push({message, confirm, cancel, allowMultiple});
            context.formOpen = allowMultiple === true;
        }
    };
    context.jQuery.post = (url, data, callback) => {
        const request = {url, data, callback};
        requests.push(request);
        return {fail: callback => {request.fail = callback;}};
    };
    vm.createContext(context);
    const start = source.indexOf("jQuery( '#mainwp-storage-save' ).on");
    const end = source.indexOf("jQuery( '#mainwp-storage-test-only' ).on", start);
    vm.runInContext(source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, 'COPY'), context);
    return {values, requests, confirmations, feedback, busyStates, context,
        click: () => handlers['#mainwp-storage-save'].call({})};
}

test('pCloud account confirmation posts the stash without reusing the spent code', () => {
    const form = saveForm();
    form.click();
    assert.equal(form.requests[0].data.authorization_code, 'pasted-code');
    const message = 'Switch from old@example.com to new@example.com? Revoke the old account in Linked Apps.';
    form.requests[0].callback({error: message, stash_key: 'a'.repeat(32),
        old_account_email: 'old@example.com', new_account_email: 'new@example.com'});
    assert.equal(form.requests.length, 1);
    assert.equal(form.confirmations[0].message, message);
    assert.equal(form.confirmations[0].allowMultiple, true);
    assert.equal(form.values['#mainwp-storage-pcloud-authorization-code'], '');
    assert.equal(form.busyStates.at(-1), false);
    form.confirmations[0].confirm();
    const confirmed = form.requests[1];
    assert.equal(confirmed.data.action, 'mainwp_pcloud_connect');
    assert.equal(confirmed.data.stash_key, 'a'.repeat(32));
    assert.equal(confirmed.data.id, 20);
    assert.equal(confirmed.data['settings[base_path]'], 'Backups');
    assert.equal(Object.hasOwn(confirmed.data, 'authorization_code'), false);
    confirmed.callback({id: 20});
    assert.equal(form.context.location.href, '/admin.php?page=ManageBackupsStorage&saved=1');
});

test('declining pCloud confirmation sends no second request and clears the spent code', () => {
    const form = saveForm();
    form.click();
    form.requests[0].callback({error: 'Switch accounts?', stash_key: 'a'.repeat(32)});
    // mainwp_confirm's No button only closes the modal and calls the cancel callback, so none must be passed.
    assert.equal(form.confirmations[0].cancel, false);
    assert.equal(form.requests.length, 1);
    assert.equal(form.confirmations[0].allowMultiple, true);
    assert.equal(form.context.formOpen, true);
    assert.equal(form.values['#mainwp-storage-pcloud-authorization-code'], '');
    assert.equal(form.busyStates.at(-1), false);
    assert.equal(form.context.location.href, undefined);
});

test('expired confirmation and network failure release the Save button and show the error', () => {
    for (const failure of ['expired', 'network']) {
        const form = saveForm();
        form.click();
        form.requests[0].callback({error: 'Switch accounts?', stash_key: 'a'.repeat(32)});
        form.confirmations[0].confirm();
        if (failure === 'expired') {
            form.requests[1].callback({error: 'Paste a new code.'});
            assert.deepEqual(form.feedback.at(-1), ['Paste a new code.', 'red']);
        } else {
            form.requests[1].fail();
            assert.deepEqual(form.feedback.at(-1), ['Network failed', 'red']);
        }
        assert.equal(form.busyStates.at(-1), false);
        assert.equal(form.confirmations.length, 1);
        assert.equal(form.context.location.href, undefined);
        assert.equal(form.context.formOpen, true);
    }
});

test('delete confirmation adds pCloud revoke guidance only for pCloud rows', () => {
    const handlers = {};
    const confirmations = [];
    const deletions = [];
    let provider;
    const guidance = "Deleting this destination doesn't revoke its access in pCloud. To revoke it, open pCloud and remove MainWP Dashboard under Settings > Linked Accounts > Linked Apps.";
    const context = {
        jQuery: () => ({
            on(event, callback) {handlers.delete = callback;},
            closest() {return {data: key => key === 'provider' ? provider : 20};}
        }),
        mainwp_confirm: (message, confirm) => confirmations.push({message, confirm}),
        deleteDestination: (id, confirmed) => deletions.push([id, confirmed]),
        COPY: guidance
    };
    vm.createContext(context);
    const start = source.indexOf("jQuery( '.mainwp-storage-delete' ).on");
    const end = source.indexOf("jQuery( '.mainwp-storage-make-default' ).on", start);
    const script = source.slice(start, end);
    assert.match(script, /esc_html__\( "Deleting this destination doesn't revoke its access in pCloud\."/);
    assert.match(script, /MainWP_Backup_Storage_Handler::pcloud_revoke_guidance\(\)/);
    vm.runInContext(script.replace(/<\?php[\s\S]*?\?>/g, 'COPY'), context);
    for (provider of ['pcloud', 'dropbox', 's3', 'local', 'gcs', 'sftp']) {
        handlers.delete.call({});
        const confirmation = confirmations.at(-1);
        assert.equal(confirmation.message.includes(guidance), provider === 'pcloud', provider);
        confirmation.confirm();
        assert.deepEqual(deletions.at(-1), [20, false]);
    }
});
