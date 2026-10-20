'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require.resolve('../../assets/js/mainwp-backups.js'), 'utf8');
const start = source.indexOf('globalThis.mainwpBackupProgressSteps =');
const end = source.indexOf('\n};', start) + 3;
function element() {
    return {children: [], attrs: {}, values: {}, value: '', empty() { this.children = []; return this; },
        data(key, value) { if (value === undefined) return this.values[key]; this.values[key] = value; return this; },
        append(child) { this.children.push(child); return this; }, appendTo(parent) { parent.append(this); return this; },
        text(value) { this.value = value; return this; }, attr(key, value) { this.attrs[key] = value; return this; }};
}
const context = {jQuery: element, MainWP: {I18n: {t: value => value}}};
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);
const icons = list => list.children.map(row => row.children[0].children[0].attrs.class);
test('both progress entry points share ordered phases and right-hand status icons', () => {
    const list = element();
    context.mainwpBackupProgressSteps(list, 'archiving_files', 45, 'running', 'full');
    assert.equal(list.children.length, 5);
    assert.deepEqual(icons(list).slice(0, 3), ['green check icon', 'green check icon', 'green circle notched loading icon']);
    assert.equal(list.children[2].children[1].value, 'Archiving Files');
    assert.equal(list.children[0].children[1].value, 'Backup Initiated');
    assert.equal(list.children[1].children[1].value, 'Database Exported');
    assert.equal(list.children[4].children[1].value, 'Finalize Backup');
});
test('database-only and files-only backups omit irrelevant phases', () => {
    for (const [type, omitted] of [['db', 'Archive Files'], ['files', 'Export Database']]) {
        const list = element();
        context.mainwpBackupProgressSteps(list, 'queued', null, 'queued', type);
        assert.equal(list.children.length, 4);
        assert.ok(!list.children.some(row => row.children[1].value === omitted));
    }
});
test('completion marks phases complete and failure retains the last active phase', () => {
    const list = element();
    context.mainwpBackupProgressSteps(list, 'uploading', 20, 'running', 'full');
    context.mainwpBackupProgressSteps(list, 'failed', null, 'failed', 'full');
    assert.equal(icons(list)[3], 'red times icon');
    context.mainwpBackupProgressSteps(list, 'completed', 100, 'completed', 'full');
    assert.ok(icons(list).every(icon => icon === 'green check icon'));
});
