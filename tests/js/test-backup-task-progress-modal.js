'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(process.env.SRC || require.resolve('../../assets/js/mainwp-backups.js'), 'utf8');
function harness() {
    const elements = new Map(), requests = [], timers = [], nodes = [], clearedTimers = [];
    function $(selector) {
        if (selector && selector.attrs) return selector;
        if (elements.has(selector)) return elements.get(selector);
        const classMatch = typeof selector === 'string' && selector.match(/^<[^>]*class="([^"]*)"/);
        const el = {values: {}, attrs: {}, label: '', classes: new Set(classMatch ? classMatch[1].split(' ') : []), children: [], parent: null, visible: true,
            attr(key, value) { if (value === undefined) return key === 'class' ? [...this.classes].join(' ') : this.attrs[key]; this.attrs[key] = value; if (key === 'class') this.classes = new Set(value.split(' ')); return this; },
            data(key, value) { if (value === undefined) return this.values[key]; this.values[key] = value; return this; },
            text(value) { if (value === undefined) return this.label; this.label = value; this.writes = (this.writes || 0) + 1; return this; },
            empty() { this.children.forEach(child => { child.parent = null; }); this.children = []; return this; },
            detach() { if (this.parent) this.parent.children = this.parent.children.filter(child => child !== this); this.parent = null; return this; },
            append(child) { child.detach(); this.children.push(child); child.parent = this; return this; },
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
            toggle(value) { this.visible = value; return this; }, is() { return this.visible; },
            progress(value, amount) { if (value === 'get percent') return this.percent || 0; if (value && typeof value === 'object') this.percent = value.percent; this.progressValue = value; this.progressAmount = amount; return this; },
            modal(value) { this.modalValue = value; return this; }, off() { return this; }, on() { return this; }, prop() { return this; }};
        if (typeof selector === 'string' && !selector.startsWith('<')) elements.set(selector, el);
        nodes.push(el);
        if (typeof selector === 'string' && selector.includes('managebackups-task-status-phase')) {
            el.find('.label').addClass('managebackups-task-status-phase');
        }
        return el;
    }
    $.post = (url, data, callback) => { requests.push({data, callback}); return {fail() { return this; }}; };
    const context = {jQuery: $, MainWP: {I18n: {t: value => value}},
        ajaxurl: 'ajax', backupDownloadRunning: true, clearTimeout(id) { clearedTimers.push(id); },
        setTimeout(fn, delay) { const timer = () => fn(); timer.delay = delay; timers.push(timer); return 1; }, Date, mainwp_secure_data: value => value,
        managebackups_render_task_phase() {}};
    vm.createContext(context);
    vm.runInContext(source.slice(0, source.indexOf('/**\n * Backup')).replace('let mainwpBackupTaskMonitor', 'var mainwpBackupTaskMonitor').replace('const mainwpBackupTaskMonitors', 'var mainwpBackupTaskMonitors'), context);
    const start = source.indexOf('managebackups_run_now = function');
    vm.runInContext(source.slice(start, source.indexOf('\n};', start) + 3), context);
    const button = $('#run').attr('task_id', '7').attr('task_type', 'full').text('Run Now');
    context.managebackups_run_now(button);
    return {context, $, requests, timers, button, nodes, clearedTimers};
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

function uploadReply(state, done, updated = Math.floor(Date.now() / 1000)) {
    return {job_id: 9, started: 123, click: 123, status: 'completed', run_open: false,
        sites: [{id: 1, name: 'Test Site', status: 'completed', snapshot: {type: 'full'},
            uploads: {total: 1, complete: state === 'done' ? 1 : 0, pending: state === 'done' ? 0 : 1, failed: 0},
            upload_progress: [{state, bytes_done: done * 1048576, bytes_total: 264 * 1048576,
                destination: 'pCloud (US)', updated, next_retry_at: Math.floor(Date.now() / 1000) + 60}]}]};
}
test('upload bytes drive the task bar and preserve the upload spinner node across polls', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('uploading', 118));
    assert.equal(h.$('#mainwp-task-backup-progress-label').text(), 'Uploading backups to storage: 44%');
    assert.equal(h.$('#mainwp-task-backup-progress').progressValue.percent, 44);
    const label = h.nodes.find(n => n.text() === 'Uploading to pCloud (US): 118 MB of 264 MB');
    assert.ok(label);
    const body = label.parent;
    const item = body.parent;
    const spinner = item.children[0].children[0];
    const phaseSpinner = h.nodes.find(n => n.attr('class') === 'green check icon');
    const count = h.nodes.length;
    h.timers.shift()();
    h.requests[1].callback(uploadReply('uploading', 122));
    assert.equal(h.nodes.length, count);
    assert.equal(item.children[0].children[0], spinner);
    assert.ok(isVisible(spinner));
    assert.ok(h.nodes.includes(phaseSpinner));
    assert.equal(label.text(), 'Uploading to pCloud (US): 122 MB of 264 MB');
    assert.ok(h.nodes.some(n => n.text() === 'Backup done, uploading to storage'));
});
test('retryable uploads keep polling, report stale activity and stop after completion', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('retrying', 118, Math.floor(Date.now() / 1000) - 90));
    assert.equal(h.timers.length, 1);
    assert.ok(h.nodes.some(n => n.text().startsWith('Upload interrupted, retrying at ')));
    assert.ok(h.nodes.some(n => n.text() === 'Last activity 90 seconds ago'));
    h.timers.shift()();
    h.requests[1].callback(uploadReply('done', 264));
    assert.equal(h.timers.length, 0);
    assert.equal(h.context.mainwpBackupTaskMonitor.active, false);
    assert.ok(h.nodes.some(n => n.text() === 'Uploaded to pCloud (US)'));
});
test('pending and submitting uploads explain their current state', () => {
    for (const [state, label] of [['waiting', 'Waiting for the upload to start'], ['verifying', 'Verifying the upload']]) {
        const h = harness();
        h.requests[0].callback(uploadReply(state, 0));
        assert.ok(h.nodes.some(n => n.text() === label));
        assert.equal(h.timers.length, 1);
    }
});
test('backup phase spinner survives repeated running polls', () => {
    const h = harness();
    const response = {job_id: 9, started: 123, click: 123, status: 'running', run_open: true,
        sites: [{id: 1, name: 'Test Site', status: 'running', phase: 'archiving_files', percent: 30}]};
    h.requests[0].callback(response);
    const spinner = h.nodes.find(n => n.attr('class') === 'green circle notched loading icon');
    const parent = spinner.parent;
    h.timers.shift()();
    h.requests[1].callback(response);
    assert.equal(parent.children[0], spinner);
    assert.ok(isVisible(spinner));
});

test('retryable uploads continue polling after ten minutes', () => {
    const h = harness();
    let now = Date.now();
    h.context.Date = class extends Date { static now() { return now; } };
    h.requests[0].callback(uploadReply('retrying', 118));
    now += 11 * 60 * 1000;
    h.timers.shift()();
    assert.equal(h.requests.length, 2);
    h.requests[1].callback(uploadReply('retrying', 122));
    assert.equal(h.timers.length, 1);
    assert.equal(h.context.mainwpBackupTaskMonitor.active, true);
});


function closeMonitor(h) {
    const start = source.indexOf("jQuery(document).on('click', '#managebackups-task-status-close', function () {");
    const fn = source.indexOf('function () {', start);
    vm.runInContext('var closeMonitor = ' + source.slice(fn, source.indexOf('\n    });', fn)) + '\n};', h.context);
    h.context.closeMonitor();
}
test('closing Running Task cancels its timer and ignores an in-flight status reply', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('retrying', 118));
    const scheduled = h.timers[0];
    scheduled();
    assert.equal(h.requests.length, 2);
    closeMonitor(h);
    assert.ok(h.clearedTimers.includes(1));
    assert.equal(h.context.mainwpBackupTaskMonitor, null);
    assert.equal(h.context.mainwpBackupTaskMonitors.get('7').active, true, 'The run continues, so the page keeps its handle.');
    assert.equal(h.context.mainwpBackupTaskMonitors.get('7').paused, true);
    assert.equal(h.$('#managebackups-task-status-box').modalValue, 'hide');
    h.requests[1].callback(uploadReply('retrying', 122));
    assert.equal(h.timers.length, 1, 'The late reply cannot schedule another poll.');
    scheduled();
    assert.equal(h.requests.length, 2, 'An already queued timer cannot post after close.');
});

for (const state of ['waiting', 'uploading', 'verifying', 'retrying']) {
    test('upload phase stops polling at sixty minutes and shows the background notice: ' + state, () => {
        const h = harness();
        let now = Date.now();
        h.context.Date = class extends Date { static now() { return now; } };
        h.requests[0].callback({job_id: 9, started: 123, status: 'running', sites: []});
        now += 90 * 60 * 1000;
        h.timers.shift()();
        h.requests[1].callback(uploadReply(state, 118));
        now += 59 * 60 * 1000;
        h.timers.shift()();
        h.requests[2].callback(uploadReply(state, 122));
        assert.equal(h.context.mainwpBackupTaskMonitor.active, true);
        now += 60 * 1000;
        h.timers.shift()();
        assert.equal(h.requests.length, 3, 'No status request is sent after the upload deadline.');
        assert.equal(h.timers.length, 0);
        assert.equal(h.context.mainwpBackupTaskMonitor.active, false);
        assert.ok(h.nodes.some(n => n.text() === 'Remaining uploads continue in the background. Check the Snapshots page for their state.'));
    });
}

test('View Progress after closing during uploads resumes the same run without a second run_now', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('uploading', 118));
    h.timers.shift()();
    const inFlight = h.requests[1];
    closeMonitor(h);
    h.timers.length = 0;
    assert.equal(h.button.text(), 'View Progress');
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.filter(r => r.data.action === 'mainwp_backuptask_run_now').length, 1, 'View Progress must not start another run.');
    assert.equal(h.$('#managebackups-task-status-box').modalValue, 'show');
    assert.equal(h.context.mainwpBackupTaskMonitor.paused, false);
    const resumed = h.requests.at(-1);
    assert.equal(resumed.data.action, 'mainwp_backuptask_run_status');
    assert.equal(resumed.data.started, 123);
    assert.equal(resumed.data.click, 123);
    inFlight.callback(uploadReply('uploading', 120));
    assert.equal(h.timers.length, 0, 'The reply from before the close cannot schedule a poll.');
    resumed.callback(uploadReply('uploading', 122));
    assert.equal(h.timers.length, 1, 'Polling resumes.');
    assert.ok(h.nodes.some(n => n.text() === 'Uploading to pCloud (US): 122 MB of 264 MB'));
});
test('closing before run_now answers keeps the run, and View Progress renders its answer', () => {
    const h = harness();
    closeMonitor(h);
    h.requests[0].callback(uploadReply('uploading', 118));
    assert.equal(h.timers.length, 0, 'A closed dialog does not poll.');
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[1].data.action, 'mainwp_backuptask_run_status');
    assert.equal(h.requests[1].data.started, 123);
});
test('closing a finished run removes its monitor and restores the original Run Now text', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('done', 264));
    closeMonitor(h);
    assert.equal(h.context.mainwpBackupTaskMonitor, null);
    assert.equal(h.context.mainwpBackupTaskMonitors.has('7'), false);
    assert.equal(h.button.text(), 'Run Now');
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.at(-1).data.action, 'mainwp_backuptask_run_now');
});
test('upload sizes read KB under 1 MB and MB above', () => {
    for (const [done, total, text] of [[300 * 1024, 400 * 1024, '300 KB of 400 KB'], [96 * 1048576, 264 * 1048576, '96 MB of 264 MB']]) {
        const h = harness();
        const reply = uploadReply('uploading', 0);
        Object.assign(reply.sites[0].upload_progress[0], {bytes_done: done, bytes_total: total});
        h.requests[0].callback(reply);
        assert.ok(h.nodes.some(n => n.text() === 'Uploading to pCloud (US): ' + text), text);
    }
});
test('while a run is paused, its own View Progress resumes it and Run Now on another task starts that task', () => {
    for (const clicked of ['same', 'other']) {
        const h = harness();
        h.requests[0].callback(uploadReply('uploading', 118));
        const scheduled = h.timers.shift();
        closeMonitor(h);
        const paused = h.context.mainwpBackupTaskMonitors.get('7');
        const button = clicked === 'same' ? h.button : h.$('#run-other').attr('task_id', '8').attr('task_type', 'full').text('Run Now');
        h.context.managebackups_run_now(button);
        const runNow = h.requests.filter(r => r.data.action === 'mainwp_backuptask_run_now');
        const last = h.requests.at(-1);
        if (clicked === 'same') {
            assert.equal(runNow.length, 1);
            assert.equal(last.data.action, 'mainwp_backuptask_run_status');
            assert.equal(last.data.task_id, '7');
            assert.equal(h.context.mainwpBackupTaskMonitor, paused);
            continue;
        }
        assert.equal(runNow.length, 2, 'The clicked task runs.');
        assert.equal(last.data.action, 'mainwp_backuptask_run_now');
        assert.equal(last.data.task_id, '8');
        assert.equal(h.requests.filter(r => r.data.action === 'mainwp_backuptask_run_status').length, 0, 'The paused run is not resumed.');
        assert.equal(paused.active, true);
        assert.equal(paused.paused, true);
        assert.equal(h.context.mainwpBackupTaskMonitors.get('7'), paused);
        assert.equal(h.context.mainwpBackupTaskMonitor.taskId, '8');
        assert.equal(h.context.mainwpBackupTaskMonitor.active, true);
        assert.equal(button.text(), 'View Progress');
        scheduled();
        assert.equal(h.requests.filter(r => r.data.action === 'mainwp_backuptask_run_status').length, 0, 'The paused run\'s timer cannot post.');
    }
});


test('A paused, B started, A View Progress resumes A while B keeps its own monitor', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('uploading', 118));
    h.timers.shift()();
    const staleA = h.requests.at(-1);
    const monitorA = h.context.mainwpBackupTaskMonitor;
    closeMonitor(h);
    const buttonB = h.$('#run-b').attr('task_id', '8').attr('task_type', 'full').text('Run Now');
    h.context.managebackups_run_now(buttonB);
    h.requests.at(-1).callback({...uploadReply('uploading', 12), started: 456, click: 456});
    const monitorB = h.context.mainwpBackupTaskMonitor;
    const contentB = h.$('#managebackups-task-status-box').find('.mainwp-modal-content').children[0];
    h.timers.shift()();
    const staleB = h.requests.at(-1);
    h.context.managebackups_run_now(h.button);
    const resumedA = h.requests.at(-1);
    assert.equal(h.requests.filter(r => r.data.action === 'mainwp_backuptask_run_now').length, 2);
    assert.equal(resumedA.data.task_id, '7');
    assert.equal(resumedA.data.started, 123);
    assert.equal(monitorB.active, true);
    assert.equal(monitorB.paused, true);
    assert.equal(h.context.mainwpBackupTaskMonitors.get('8'), monitorB);
    assert.equal(buttonB.text(), 'View Progress');
    const label = h.$('#mainwp-task-backup-progress-label').text();
    staleA.callback(uploadReply('done', 264));
    staleB.callback(uploadReply('done', 264));
    assert.equal(h.$('#mainwp-task-backup-progress-label').text(), label);
    assert.equal(h.timers.length, 0, 'Replies from older generations cannot schedule polls.');
    assert.equal(monitorA.active, true);
    resumedA.callback(uploadReply('done', 264));
    closeMonitor(h);
    assert.equal(h.button.text(), 'Run Now');
    assert.equal(h.context.mainwpBackupTaskMonitors.has('7'), false);
    assert.equal(h.context.mainwpBackupTaskMonitors.get('8'), monitorB);
    h.context.managebackups_run_now(buttonB);
    assert.equal(h.requests.at(-1).data.task_id, '8');
    assert.equal(h.requests.at(-1).data.started, 456);
    assert.equal(h.$('#managebackups-task-status-box').find('.mainwp-modal-content').children[0], contentB);
    assert.equal(h.$('#mainwp-task-backup-progress').progressValue.percent, 4);
    assert.equal(h.requests.filter(r => r.data.action === 'mainwp_backuptask_run_now').length, 2);
});

test('a kickoff reply received while B owns the modal retains A identity without repainting B', () => {
    const h = harness();
    closeMonitor(h);
    const buttonB = h.$('#run-b').attr('task_id', '8').attr('task_type', 'full').text('Run Now');
    h.context.managebackups_run_now(buttonB);
    h.requests[1].callback(uploadReply('uploading', 12));
    const label = h.$('#mainwp-task-backup-progress-label').text();
    h.requests[0].callback({...uploadReply('uploading', 118), started: 789, initiation_id: 'task-a'});
    assert.equal(h.$('#mainwp-task-backup-progress-label').text(), label);
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.at(-1).data.started, 789);
    assert.equal(h.requests.at(-1).data.initiation_id, 'task-a');
    assert.equal(h.requests.filter(r => r.data.action === 'mainwp_backuptask_run_now').length, 2);
});

test('all retrying uploads poll from the earliest next_retry_at using server time', () => {
    for (const [wait, expected] of [[120, 120000], [600, 300000], [-10, 3000]]) {
        const h = harness();
        h.context.Date = class extends Date { static now() { return 1000000; } };
        const reply = uploadReply('retrying', 118, 1000);
        reply.server_time = 1120;
        const site = reply.sites[0];
        site.uploads = {total: 4, complete: 1, pending: 2, failed: 1};
        site.upload_progress[0].next_retry_at = 1120 + wait + 30;
        site.upload_progress.push({...site.upload_progress[0], next_retry_at: 1120 + wait},
            {...site.upload_progress[0], state: 'done', next_retry_at: 0},
            {...site.upload_progress[0], state: 'failed', next_retry_at: 0});
        h.requests[0].callback(reply);
        assert.equal(h.timers.at(-1).delay, expected);
    }
});

test('one non-retrying pending upload keeps the three-second poll', () => {
    for (const state of ['waiting', 'uploading', 'verifying', 'retrying']) {
        const h = harness();
        const reply = uploadReply('retrying', 118);
        reply.sites[0].uploads.pending = 2;
        reply.sites[0].upload_progress.push({...reply.sites[0].upload_progress[0], state, next_retry_at: 0});
        h.requests[0].callback(reply);
        assert.equal(h.timers.at(-1).delay, 3000);
    }
});

test('Last activity uses a server clock 120 seconds ahead and refreshes the offset on status replies', () => {
    const h = harness();
    let now = 1000000;
    h.context.Date = class extends Date { static now() { return now; } };
    h.requests[0].callback({job_id: 9, started: 123, status: 'running', sites: [], server_time: 1120});
    now += 3000;
    h.timers.shift()();
    h.requests[1].callback({...uploadReply('uploading', 118, 1033), server_time: 1123});
    assert.ok(h.nodes.some(n => n.text() === 'Last activity 90 seconds ago'));
    now += 3000;
    h.timers.shift()();
    h.requests[2].callback({...uploadReply('uploading', 120, 1066), server_time: 1156});
    assert.ok(h.nodes.some(n => n.text() === 'Last activity 90 seconds ago'));
});

test('pending uploads append the failed count and keep the site label red', () => {
    const h = harness();
    const reply = uploadReply('uploading', 118);
    reply.sites[0].uploads = {total: 3, complete: 0, pending: 1, failed: 2};
    h.requests[0].callback(reply);
    const label = h.nodes.find(n => n.text() === 'Backup done, uploading to storage. 2 upload(s) failed.');
    assert.ok(label);
    assert.ok(label.classes.has('mainwp-red'));
});


test('a timer queued before closing cannot post after its monitor resumes', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('uploading', 118));
    const staleTimer = h.timers.shift();
    closeMonitor(h);
    h.context.managebackups_run_now(h.button);
    const count = h.requests.length;
    staleTimer();
    assert.equal(h.requests.length, count);
    h.requests.at(-1).callback(uploadReply('uploading', 122));
    assert.equal(h.timers.length, 1);
});

test('switching to a finished task restores its own result until it is closed', () => {
    const h = harness();
    h.requests[0].callback(uploadReply('done', 264));
    const resultA = h.$('#managebackups-task-status-box').find('.mainwp-modal-content').children[0];
    const buttonB = h.$('#run-b').attr('task_id', '8').attr('task_type', 'full').text('Run Now');
    h.context.managebackups_run_now(buttonB);
    h.requests.at(-1).callback(uploadReply('uploading', 12));
    const count = h.requests.length;
    h.context.managebackups_run_now(h.button);
    assert.equal(h.requests.length, count, 'The retained result needs no new run or poll.');
    assert.equal(h.$('#managebackups-task-status-box').find('.mainwp-modal-content').children[0], resultA);
    assert.equal(h.$('#mainwp-task-backup-progress-label').text(), 'Backup task completed.');
    assert.equal(h.$('#mainwp-task-backup-progress').progressValue.percent, 100);
    closeMonitor(h);
    assert.equal(h.button.text(), 'Run Now');
    assert.equal(h.context.mainwpBackupTaskMonitors.has('7'), false);
    assert.equal(h.context.mainwpBackupTaskMonitors.get('8').active, true);
});
