'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require.resolve('../../assets/js/mainwp-backups.js'), 'utf8');
function harness() {
    const elements = new Map(), requests = [], timers = [];
    function $(selector) {
        if (selector && selector.attrs) return selector;
        if (elements.has(selector)) return elements.get(selector);
        const el = {attrs: {}, label: '', classes: new Set(), children: [], visible: true,
            attr(key, value) { if (value === undefined) return this.attrs[key]; this.attrs[key] = value; return this; },
            text(value) { if (value === undefined) return this.label; this.label = value; return this; },
            empty() { this.children = []; return this; }, append(child) { this.children.push(child); return this; },
            appendTo(parent) { $(parent).append(this); return this; }, prependTo(parent) { return this.appendTo(parent); },
            find(key) { return $(String(selector) + key); },
            addClass(names) { names.split(' ').forEach(name => this.classes.add(name)); return this; },
            removeClass(names) { names.split(' ').forEach(name => this.classes.delete(name)); return this; },
            show() { this.visible = true; return this; }, hide() { this.visible = false; return this; },
            toggle(value) { this.visible = value; return this; }, progress(value) { this.progressValue = value; return this; },
            modal(value) { this.modalValue = value; return this; }, off() { return this; }, on() { return this; }, prop() { return this; }};
        if (typeof selector === 'string' && !selector.startsWith('<')) elements.set(selector, el);
        return el;
    }
    $.post = (url, data, callback) => { requests.push({data, callback}); return {fail() { return this; }}; };
    const context = {jQuery: $, mainwpBackupTaskMonitor: {active: false}, MainWP: {I18n: {t: value => value}},
        manageBackupsPollGeneration: 0, manageBackupsPollTimer: null, ajaxurl: 'ajax', clearTimeout() {},
        setTimeout(fn) { timers.push(fn); return 1; }, Date, mainwp_secure_data: value => value,
        mainwpBackupProgressSteps() {}, managebackups_render_task_phase() {}};
    vm.createContext(context);
    const start = source.indexOf('managebackups_run_now = function');
    vm.runInContext(source.slice(start, source.indexOf('\n};', start) + 3), context);
    const button = $('#run').attr('task_id', '7').attr('task_type', 'full').text('Run Now');
    context.managebackups_run_now(button);
    return {context, $, requests, timers, button};
}
test('Run Now opens the standard modal and closing/reopening does not initiate another run', () => {
    const h = harness();
    assert.equal(h.requests.length, 1);
    assert.equal(h.$('#managebackups-task-status-box').modalValue, 'show');
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.length, 1);
    assert.equal(h.button.text(), 'View Progress');
});
test('per-site progress keeps polling and completion leaves a reopenable result', () => {
    const h = harness();
    h.requests[0].callback({job_id: 9, started: 123, click: 123, status: 'running', run_open: true,
        sites: [{id: 1, name: 'Test Site', status: 'running', phase: 'archiving_files', percent: 30}]});
    assert.equal(h.timers.length, 1);
    h.timers.shift()();
    h.requests[1].callback({started: 123, status: 'completed', run_open: false,
        sites: [{id: 1, name: 'Test Site', status: 'completed', snapshot: {type: 'full'}, uploads: {total: 1, complete: 1, pending: 0, failed: 0}}]});
    assert.equal(h.context.mainwpBackupTaskMonitor.active, false);
    assert.equal(h.button.text(), 'View Result');
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.length, 2);
});
