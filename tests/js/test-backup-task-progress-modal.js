'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(process.env.SRC || require.resolve('../../assets/js/mainwp-backups.js'), 'utf8');
function harness() {
    const elements = new Map(), requests = [], timers = [], nodes = [];
    function $(selector) {
        if (selector && selector.attrs) return selector;
        if (elements.has(selector)) return elements.get(selector);
        const classMatch = typeof selector === 'string' && selector.match(/^<[^>]*class="([^"]*)"/);
        const el = {attrs: {}, label: '', classes: new Set(classMatch ? classMatch[1].split(' ') : []), children: [], parent: null, visible: true,
            attr(key, value) { if (value === undefined) return this.attrs[key]; this.attrs[key] = value; return this; },
            text(value) { if (value === undefined) return this.label; this.label = value; this.writes = (this.writes || 0) + 1; return this; },
            empty() { this.children.forEach(child => { child.parent = null; }); this.children = []; return this; },
            append(child) { this.children.push(child); child.parent = this; return this; },
            appendTo(parent) { $(parent).append(this); return this; }, prependTo(parent) { return this.appendTo(parent); },
            find(key) {
                const existing = this.children.find(child => child.selector === key || child.classes.has(key.slice(1)));
                if (existing) return existing;
                const child = $('<div></div>');
                child.selector = key;
                if (key.startsWith('.')) child.addClass(key.slice(1));
                this.append(child);
                return child;
            },
            addClass(names) { names.split(' ').forEach(name => this.classes.add(name)); return this; },
            removeClass(names) { names.split(' ').forEach(name => this.classes.delete(name)); return this; },
            show() { this.visible = true; return this; }, hide() { this.visible = false; return this; },
            toggle(value) { this.visible = value; return this; }, progress(value) { this.progressValue = value; return this; },
            modal(value) { this.modalValue = value; return this; }, off() { return this; }, on() { return this; }, prop() { return this; }};
        if (typeof selector === 'string' && !selector.startsWith('<')) elements.set(selector, el);
        nodes.push(el);
        if (typeof selector === 'string' && selector.includes('managebackups-task-status-phase')) {
            el.find('.label').addClass('managebackups-task-status-phase');
        }
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
    return {context, $, requests, timers, button, nodes};
}
test('Run Now opens the standard modal and closing/reopening does not initiate another run', () => {
    const h = harness();
    assert.equal(h.requests.length, 1);
    assert.equal(h.$('#managebackups-task-status-box').modalValue, 'show');
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.length, 1);
    assert.equal(h.button.text(), 'View Progress');
});

function isVisible(node) {
    return node.visible && (!node.parent || isVisible(node.parent));
}
function isDescendant(node, ancestor) {
    for (let parent = node.parent; parent; parent = parent.parent) {
        if (parent === ancestor) return true;
    }
    return false;
}
test('a failed site shows its error outside the hidden progress bar with a live region', () => {
    const h = harness();
    const detail = 'Child error <img src=x onerror=alert(1)>\n' + '/long/path/'.repeat(40);
    h.requests[0].callback({job_id: 9, started: 123, click: 123, status: 'completed_with_errors', run_open: false,
        sites: [{id: 1, name: 'Test Site', status: 'failed', error: detail}]});
    const bar = h.nodes.find(node => node.classes.has('managebackups-task-status-progress'));
    const error = h.nodes.find(node => node.text() === detail);
    assert.ok(bar && error, 'The failed site must have a progress bar and an error text node.');
    assert.equal(bar.visible, false);
    assert.equal(isDescendant(error, bar), false, 'The error must not be a descendant of the hidden bar.');
    assert.ok(isVisible(error), 'The full error must be visible.');
    assert.equal(error.attr('aria-live'), 'polite');
    assert.ok(error.classes.has('mainwp-backup-error-text'));
});
test('site errors start empty, use the fallback text, and clear on a later non-failed poll', () => {
    const h = harness();
    h.requests[0].callback({job_id: 9, started: 123, click: 123, status: 'running', run_open: true,
        sites: [{id: 1, name: 'Test Site', status: 'running', percent: 10}]});
    const error = h.nodes.find(node => node.classes.has('mainwp-backup-error-text'));
    assert.ok(error, 'Create the live region before the site fails.');
    assert.equal(error.text(), '');
    assert.equal(error.attr('aria-live'), 'polite');
    h.timers.shift()();
    h.requests[1].callback({started: 123, status: 'running', run_open: true,
        sites: [{id: 1, name: 'Test Site', status: 'failed'}]});
    assert.equal(error.text(), 'Backup task failed.');
    h.timers.shift()();
    h.requests[2].callback({started: 123, status: 'running', run_open: true,
        sites: [{id: 1, name: 'Test Site', status: 'running', percent: 20}]});
    assert.equal(error.text(), '');
});
test('a repeated failed poll does not rewrite the live region', () => {
    const h = harness();
    const failed = {started: 123, status: 'running', run_open: true,
        sites: [{id: 1, name: 'Test Site', status: 'failed', error: 'Child refused the backup.'}]};
    h.requests[0].callback(Object.assign({job_id: 9, click: 123}, failed));
    const error = h.nodes.find(node => node.classes.has('mainwp-backup-error-text'));
    assert.equal(error.text(), 'Child refused the backup.');
    const writes = error.writes;
    h.timers.shift()();
    h.requests[1].callback(failed);
    assert.equal(error.writes, writes, 'The same error must not be announced again.');
    assert.equal(error.text(), 'Child refused the backup.');
    h.timers.shift()();
    h.requests[2].callback({started: 123, status: 'running', run_open: true,
        sites: [{id: 1, name: 'Test Site', status: 'running', percent: 20}]});
    assert.equal(error.text(), '');
});
test('aggregate errors preserve their text in a wrapping element', () => {
    const h = harness();
    const detail = 'Site: child.example\n' + '/long/path/'.repeat(40);
    h.requests[0].callback({job_id: 9, started: 123, click: 123, status: 'completed_with_errors', run_open: false,
        sites: [], errors: detail});
    const error = h.nodes.find(node => node.text() === detail);
    assert.ok(error);
    assert.ok(isVisible(error));
    assert.ok(error.classes.has('mainwp-backup-error-text'));
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
