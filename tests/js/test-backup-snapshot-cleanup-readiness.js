'use strict';
const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.env.MAINWP_SNAPSHOTS_RENDERED_FILE || require.resolve('../../pages/backup/page-mainwp-backup-snapshots-view.php'), 'utf8');

function harness() {
    const elements = new Map();
    const handlers = new Map();
    const requests = [];
    const confirmations = [];
    const statuses = [];
    const placeholders = [];
    const tableEvents = [];
    const context = {
        currentSite: 12, snapshotListRequest: 0, backupFollowGen: 0, snapshotDeleteRequest: 0, snapshotDeletePreview: null,
        backupPendingKickoffs: {}, backupCancelledEpoch: {}, backupPendingStarts: {}, backupPollTimer: null,
        siteDisconnected: false, backupInProgress: false, currentJob: null,
        snapshotsPlaceholders: { loading: 'Loading', empty: 'Empty', error: 'List error' },
        nonces: { list: 'list-nonce', snapshotsCleanup: 'cleanup-nonce' }, ajaxurl: '/local/admin-ajax.php',
        setSnapshotsPlaceholder(value) { placeholders.push(value); }, showDisconnected() {}, setBackupInProgress() {}, setBackupFollowControls() {},
        setBackupStatus(text, isError) { statuses.push({text, isError}); },
        mainwp_confirm(message, confirm) { confirmations.push({message, confirm}); }
    };
    context.$ = selector => {
        selector = String(selector);
        if (!elements.has(selector)) elements.set(selector, {data: {}, props: {}, classes: new Set()});
        const state = elements.get(selector);
        const api = {
            on(event, handler) { handlers.set(selector, handler); return api; },
            data(name, value) { if (value === undefined) return state.data[name]; state.data[name] = value; return api; },
            removeData(name) { delete state.data[name]; return api; },
            prop(name, value) { if (value === undefined) return state.props[name]; state.props[name] = value; return api; },
            hasClass(name) { return state.classes.has(name); },
            addClass(name) { state.classes.add(name); return api; },
            removeClass(name) { state.classes.delete(name); return api; },
            toggleClass(name, value) { if (value) state.classes.add(name); else state.classes.delete(name); return api; },
            find() { return api; }, hide() { return api; }, empty() { return api; },
            attr() { return api; }, toggle() { return api; }, html() { return api; }, modal() { return api; }
        };
        return api;
    };
    context.$.post = (url, fields, success, type) => {
        const request = {url, fields, success, type};
        requests.push(request);
        return {fail(callback) { request.failure = callback; return this; }};
    };
    context.snapshotsTable = {clear() { tableEvents.push('clear'); return this; }, draw() { tableEvents.push('draw'); return this; }, rows: {add(rows) { tableEvents.push(rows); }}};
    vm.createContext(context);
    const evaluate = code => vm.runInContext(code.replace(/<\?php[\s\S]*?\?>/g, 'Localized text'), context);
    for (const name of ['escAttr', 'enableSnapshotControls', 'loadSnapshots']) {
        const start = source.indexOf('function ' + name + '(');
        assert.ok(start >= 0, name + ' must come from production source.');
        const end = source.indexOf('\n            }', start) + '\n            }'.length;
        assert.ok(end > start);
        evaluate(source.slice(start, end));
    }
    const selector = '#mainwp-snapshots-cleanup-btn';
    const start = source.indexOf("$( '" + selector + "' ).on( 'click'");
    assert.ok(start >= 0, 'Clean Up must use the production event handler.');
    const end = source.indexOf('\n            } );', start) + '\n            } );'.length;
    evaluate(source.slice(start, end));
    return {
        context, requests, confirmations, statuses, placeholders, tableEvents,
        click() { handlers.get(selector).call(selector); },
        load(site = 12) { context.loadSnapshots(site); return requests.at(-1); },
        warnings() { return context.$(selector).data('retention-warnings'); }
    };
}
const list = fields => ({snapshots: [], failed_restore_jobs: [], ...fields});
const cleanupRequests = h => h.requests.filter(request => request.fields.action === 'mainwp_snapshots_cleanup');
const blocked = h => {
    h.click();
    assert.equal(h.confirmations.length, 0, 'Unavailable warning state must not open confirmation.');
    assert.equal(cleanupRequests(h).length, 0, 'Unavailable warning state must never post cleanup.');
    assert.equal(h.statuses.length, 1, 'The blocked click must explain how to retry.');
    assert.equal(h.statuses[0].isError, true);
};

test('pending list invalidates previously loaded warnings before an enabled click', () => {
    const h = harness();
    h.load().success(list({cleanup_warnings: ['Previous minimum']}));
    h.load();
    assert.equal(h.warnings(), undefined);
    blocked(h);
});
for (const [name, warnings] of [['missing', undefined], ['null', null], ['false', false], ['number', 0], ['string', 'Minimum'], ['object', {}]]) {
    test(name + ' warnings never become a loaded empty array', () => {
        const h = harness();
        h.load().success(list(warnings === undefined ? {} : {cleanup_warnings: warnings}));
        assert.equal(h.warnings(), null, 'Invalid warning data must retain the unavailable state.');
        blocked(h);
    });
}
for (const warnings of [undefined, []]) {
    test('list error refuses cleanup even with warnings=' + JSON.stringify(warnings), () => {
        const h = harness();
        h.load().success(list({error: 'List refused', cleanup_warnings: warnings}));
        blocked(h);
    });
}
test('network failure after reload cannot reuse the previous warnings', () => {
    const h = harness();
    h.load().success(list({cleanup_warnings: []}));
    h.load().failure();
    blocked(h);
});
for (const warnings of [[], ['Protected <img src=x onerror=alert(1)> & "copy"', 'Second warning']]) {
    test('loaded warnings=' + JSON.stringify(warnings) + ' keep confirmation, escaping and payload', () => {
        const h = harness();
        h.load().success(list({cleanup_warnings: warnings}));
        h.click();
        assert.equal(h.confirmations.length, 1);
        assert.equal(cleanupRequests(h).length, 0, 'A confirmation is never an automatic cleanup.');
        assert.equal(h.statuses.length, 0);
        if (warnings.length) {
            assert.match(h.confirmations[0].message, /Protected &lt;img src=x onerror=alert\(1\)&gt; &amp; &quot;copy&quot;/);
            assert.doesNotMatch(h.confirmations[0].message, /<img/);
            assert.match(h.confirmations[0].message, /Second warning/);
        } else {
            assert.doesNotMatch(h.confirmations[0].message, /ui yellow message/);
        }
        h.confirmations[0].confirm();
        const request = cleanupRequests(h)[0];
        assert.equal(cleanupRequests(h).length, 1);
        assert.deepEqual({...request.fields}, {action: 'mainwp_snapshots_cleanup', security: 'cleanup-nonce', site_id: 12});
        assert.equal(request.type, 'json');
    });
}
test('cancelling loaded confirmation leaves cleanup unposted', () => {
    const h = harness();
    h.load().success(list({cleanup_warnings: []}));
    h.click();
    assert.equal(h.confirmations.length, 1);
    assert.equal(cleanupRequests(h).length, 0);
});
test('site switch ignores the previous site reply and waits for current warnings', () => {
    const h = harness();
    const old = h.load(12);
    const current = h.load(24);
    old.success(list({cleanup_warnings: []}));
    blocked(h);
    h.statuses.length = 0;
    current.success(list({cleanup_warnings: []}));
    h.click();
    assert.equal(h.confirmations.length, 1);
    h.confirmations[0].confirm();
    assert.equal(cleanupRequests(h)[0].fields.site_id, 24);
});
test('no selected site retains the existing guard', () => {
    const h = harness();
    h.context.currentSite = 0;
    h.click();
    assert.equal(h.confirmations.length, 0);
    assert.equal(cleanupRequests(h).length, 0);
    assert.equal(h.statuses.length, 0);
});

test('disabled control retains its existing guard after a valid list', () => {
    const h = harness();
    h.load().success(list({cleanup_warnings: []}));
    h.context.$('#mainwp-snapshots-cleanup-btn').addClass('disabled');
    h.click();
    assert.equal(h.confirmations.length, 0);
    assert.equal(cleanupRequests(h).length, 0);
    assert.equal(h.statuses.length, 0);
});


for (const stale of [[], ['Old A minimum warning']]) {
    test('older same-site success cannot ready the newest pending list: ' + JSON.stringify(stale), () => {
        const h = harness();
        const old = h.load(12);
        h.load(12);
        const before = h.tableEvents.length;
        old.success(list({cleanup_warnings: stale}));
        assert.equal(h.warnings(), undefined, 'Only the newest in-flight list can supply warnings.');
        assert.equal(h.placeholders.at(-1), 'Loading');
        assert.equal(h.tableEvents.length, before, 'A stale callback must not repaint the list.');
        blocked(h);
    });
    test('older same-site success cannot replace the newest completed warnings: ' + JSON.stringify(stale), () => {
        const h = harness();
        const old = h.load(12);
        h.load(12).success(list({cleanup_warnings: ['Current B minimum warning']}));
        const before = h.tableEvents.length;
        old.success(list({cleanup_warnings: stale}));
        assert.deepEqual(Array.from(h.warnings()), ['Current B minimum warning']);
        assert.equal(h.tableEvents.length, before);
        h.click();
        assert.equal(h.confirmations.length, 1);
        assert.match(h.confirmations[0].message, /Current B minimum warning/);
        assert.doesNotMatch(h.confirmations[0].message, /Old A minimum warning/);
        assert.equal(cleanupRequests(h).length, 0);
    });
}
for (const ready of [false, true]) {
    test('older same-site transport failure cannot repaint newest list, ready=' + ready, () => {
        const h = harness();
        const old = h.load(12);
        const current = h.load(12);
        if (ready) current.success(list({cleanup_warnings: ['Current minimum warning']}));
        const before = h.tableEvents.length;
        old.failure();
        assert.equal(h.placeholders.at(-1), ready ? 'Empty' : 'Loading');
        assert.equal(h.tableEvents.length, before);
        if (ready) {
            assert.deepEqual(Array.from(h.warnings()), ['Current minimum warning']);
            h.click();
            assert.equal(h.confirmations.length, 1);
            assert.match(h.confirmations[0].message, /Current minimum warning/);
        } else {
            blocked(h);
        }
        assert.equal(cleanupRequests(h).length, 0);
    });
}
test('older same-site list error cannot replace current ready data', () => {
    const h = harness();
    const old = h.load(12);
    h.load(12).success(list({cleanup_warnings: ['Current minimum warning']}));
    const before = h.tableEvents.length;
    old.success(list({error: 'Old request refused', cleanup_warnings: []}));
    assert.deepEqual(Array.from(h.warnings()), ['Current minimum warning']);
    assert.equal(h.tableEvents.length, before);
    h.click();
    assert.equal(h.confirmations.length, 1);
    assert.match(h.confirmations[0].message, /Current minimum warning/);
    assert.equal(cleanupRequests(h).length, 0);
});
test('returning to the same site cannot accept its earlier pending success', () => {
    const h = harness();
    const first = h.load(12);
    const other = h.load(24);
    h.load(12);
    first.success(list({cleanup_warnings: []}));
    other.success(list({cleanup_warnings: []}));
    assert.equal(h.warnings(), undefined);
    assert.equal(h.placeholders.at(-1), 'Loading');
    blocked(h);
});
test('returning to the same site preserves newest success against its earlier failure', () => {
    const h = harness();
    const first = h.load(12);
    h.load(24);
    h.load(12).success(list({cleanup_warnings: ['Latest site12 minimum']}));
    const before = h.tableEvents.length;
    first.failure();
    assert.equal(h.placeholders.at(-1), 'Empty');
    assert.equal(h.tableEvents.length, before);
    assert.deepEqual(Array.from(h.warnings()), ['Latest site12 minimum']);
    h.click();
    assert.equal(h.confirmations.length, 1);
    assert.match(h.confirmations[0].message, /Latest site12 minimum/);
    assert.equal(cleanupRequests(h).length, 0);
});
test('newest list failure cannot be replaced by an older same-site success', () => {
    const h = harness();
    const old = h.load(12);
    h.load(12).failure();
    const before = h.tableEvents.length;
    old.success(list({cleanup_warnings: []}));
    assert.equal(h.placeholders.at(-1), 'List error');
    assert.equal(h.tableEvents.length, before);
    assert.equal(h.warnings(), undefined);
    blocked(h);
});
