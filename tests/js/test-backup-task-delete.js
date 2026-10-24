'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../assets/js/mainwp-backups.js'), 'utf8');
const start = source.indexOf('managebackups_remove = function');
const end = source.indexOf('\n};', start) + '\n};'.length;
assert.ok(start >= 0 && end > start);

function fixture() {
    const posts = [];
    const modals = [];
    const messages = [];
    const status = [];
    let checked = true;
    let removed = 0;
    const element = { task_id: '12' };
    const jquery = value => {
        if (value === '<div>') {
            let text = '';
            return { text(value) { text = value; return this; }, html() { return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); } };
        }
        return {
            attr: name => value[name],
            is: () => checked,
            html: text => status.push(text),
            closest: () => ({ remove: () => removed++ })
        };
    };
    jquery.post = (url, data, cb, type) => {
        const request = { url, data, cb, type };
        posts.push(request);
        return { fail(fn) { request.fail = fn; return this; } };
    };
    const context = {
        MainWP: { I18n: { t: text => text } }, ajaxurl: '/wp-admin/admin-ajax.php', jQuery: jquery,
        managebackups_init() {}, mainwp_confirm: (msg, cb) => modals.push({ msg, cb }),
        mainwp_secure_data: data => ({ ...data, security: 'nonce' }),
        managebackups_show_message: (text, color) => messages.push({ text, color }), setHtml() {}
    };
    vm.createContext(context);
    vm.runInContext(source.slice(start, end), context);
    return { context, posts, modals, messages, status, element, checked(value) { checked = value; }, removed: () => removed };
}
const begin = f => {
    assert.equal(f.context.managebackups_remove(f.element), false);
    assert.equal(f.modals.length, 0, 'No confirmation before an authorized preview succeeds.');
    assert.equal(f.posts.length, 1, 'Click requests only a preview.');
    assert.deepEqual({ ...f.posts[0].data }, { action: 'mainwp_removebackup', id: '12', dry_run: 1, delete_snapshots: 1, security: 'nonce' });
    assert.equal(f.posts[0].type, 'json');
};
for (const checked of [true, false]) {
    const f = fixture();
    begin(f);
    f.posts[0].cb({ warnings: ['Protected <img src=x onerror=alert(1)> & copy', 'Second warning.'] });
    assert.equal(f.modals.length, 1, 'Preview uses the existing single confirmation.');
    assert.match(f.modals[0].msg, /Are you sure you want to delete this backup task\?/);
    assert.match(f.modals[0].msg, /id="mainwp-backup-task-delete-snapshots" checked="checked"/);
    assert.match(f.modals[0].msg, /Protected &lt;img src=x onerror=alert\(1\)&gt; &amp; copy Second warning\./);
    assert.doesNotMatch(f.modals[0].msg, /<img/);
    assert.equal(f.posts.length, 1, 'There is no mutation until confirmation.');
    f.checked(checked);
    f.modals[0].cb();
    assert.equal(f.posts.length, 2);
    assert.deepEqual({ ...f.posts[1].data }, { action: 'mainwp_removebackup', id: '12', delete_snapshots: checked ? 1 : 0, security: 'nonce' });
    f.posts[1].cb({ result: 'SUCCESS' });
    assert.equal(f.removed(), 1);
}
const noWarning = fixture();
begin(noWarning);
noWarning.posts[0].cb({ warnings: [] });
assert.equal(noWarning.modals.length, 1);
assert.doesNotMatch(noWarning.modals[0].msg, /ui yellow message/);

const failures = [null, false, '0', '-1', {}, { notask: true }, { error: 'Denied.' }, { error: 'Denied.', warnings: [] }, { warnings: 'invalid' }, { warnings: [null] }];
for (const response of failures) {
    const f = fixture();
    begin(f);
    f.posts[0].cb(response);
    assert.equal(f.modals.length, 0, 'A refused or malformed preview never opens confirmation: ' + JSON.stringify(response));
    assert.equal(f.posts.length, 1);
    assert.equal(f.removed(), 0);
    assert.equal(f.messages.length, 1);
    assert.equal(f.messages[0].color, 'red');
}
const network = fixture();
begin(network);
network.posts[0].fail();
assert.equal(network.modals.length, 0);
assert.equal(network.posts.length, 1);
assert.equal(network.messages.length, 1);
assert.equal(network.messages[0].color, 'red');
const failedDelete = fixture();
begin(failedDelete);
failedDelete.posts[0].cb({ warnings: [] });
failedDelete.modals[0].cb();
failedDelete.posts[1].cb({ error: 'Deletion refused.' });
assert.equal(failedDelete.removed(), 0);
assert.equal(failedDelete.messages[0].text, 'Deletion refused.');
assert.equal(failedDelete.modals.length, 1);
for (const failure of ['network', 'parsererror']) {
    const f = fixture();
    begin(f);
    f.posts[0].cb({ warnings: [] });
    f.modals[0].cb();
    assert.equal(typeof f.posts[1].fail, 'function', 'Confirmed deletion must handle ' + failure);
    f.posts[1].fail({}, failure);
    assert.equal(f.status.at(-1), '');
    assert.equal(f.removed(), 0);
    assert.equal(f.posts.length, 2, 'An uncertain result must not retry the mutation.');
    assert.equal(f.modals.length, 1);
    assert.equal(f.messages.at(-1).color, 'red');
    assert.match(f.messages.at(-1).text, /Reload the page before trying again/);
}
console.log('OK (17 task delete preview/confirmation scenarios)');
