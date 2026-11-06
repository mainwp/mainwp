'use strict';
const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
const WALK_INVALID_NOTE = "Files added after this backup were not removed, because the site's MainWP Child could not use the folder list this Dashboard sent for the comparison. Check that the Dashboard and the site's MainWP Child are both up to date.";

function phpToJs( code ) {
    return code.replace( /<\?php[\s\S]*?\?>/g, php => {
        const strings = [ ...php.matchAll( /(['"])((?:\\.|(?!\1)[\s\S])*?)\1/g ) ];
        const index = php.includes( '_n(' ) && /, 2,/.test( php ) ? 1 : 0;
        return strings[ index ][2].replace( /\\'/g, "'" ).replace( /'/g, "\\'" );
    } );
}

function harness() {
    const elements = new Map();
    const handlers = new Map();
    const created = [];
    const context = {
        statusLabels: { pending: 'Queued' }, rollbackStatusLabels: {}, rollbackActionLabels: { retry: 'Retry', rollBack: 'Roll Back' }, restoreOutcomeMessages: {},
        restoreStepJob: null, updateRestoreSteps: () => { context.restoreStepJob = context.currentJob; return -1; }, restoreProgressPercent: 0, phaseOrder: [], currentSiteUrl: '', pollTimer: 0, restorePollInFlight: false, canRestore: true,
        restoreGeneration: 0, restorePolling: false, restoreFollowSite: 0, restoreSites: {}, restoreDialogOpen: true, serverClockOffset: null,
        stopBackupTimers() {}, setBackupInProgress() {}, setBackupStatus() {}, showCancelBackup() {}, reenableBackupBtn() {}, backupSinceTs: 0, backupProgressCache: null, backupStartTime: null,
        restoreWarnings: {default: 'Warning'}, siteIsMultisite: false, siteHasWooCommerce: false, safetyBackupLabels: {default: 'Safety backup'},
        setSnapshotDeleteBusy() {}, showPreserveNote() {}, loadSnapshots() {}, window: { clearTimeout() {}, crypto: require( 'node:crypto' ).webcrypto }
    };
    context.$ = selector => {
        if ( typeof selector === 'object' ) return selector;
        const state = { tag: selector, text: '', children: [], classes: new Set(), visible: false };
        if ( selector.startsWith( '<' ) ) {
            assert.match( selector, /^<(p|ul|li)>$/, 'Construct elements from constant tags only.' );
            created.push( state );
        } else if ( elements.has( selector ) ) return elements.get( selector );
        const element = {
            state,
            text( value ) { assert.equal( typeof value, 'string' ); state.text = value; state.children = []; return this; },
            empty() { state.text = ''; state.children = []; return this; },
            append( child ) { assert.equal( typeof child, 'object', 'Never append path HTML.' ); state.children.push( child.state ); return this; },
            appendTo( target ) { context.$( target ).append( this ); return this; },
            addClass( names ) { names.split( ' ' ).forEach( name => state.classes.add( name ) ); return this; },
            hasClass( name ) { return state.classes.has( name ); },
            removeClass( names ) { names.split( ' ' ).forEach( name => state.classes.delete( name ) ); return this; },
            progress() { return this; },
            toggleClass(name, value) { if (value) state.classes.add(name); else state.classes.delete(name); return this; },
            show() { state.visible = true; state.wasShown = true; return this; },
            hide() { state.visible = false; return this; },
            toggle( value ) { state.visible = value; if (value) state.wasShown = true; return this; },
            is(selector) { return selector === ':checked' ? !!state.checked : state.visible; },
            data(name, value) { if (value === undefined) return state[name]; state[name] = value; return this; },
            removeData() { return this; },
            val() { return state.value; },
            modal() { return this; },
            attr() { return this; },
            find() { return this; },
            prop(name, value) { if (value === undefined) return state[name]; state[name] = value; return this; },
            on( event, callback ) { handlers.set( selector + ':' + event, callback ); return this; }
        };
        if ( ! selector.startsWith( '<' ) ) elements.set( selector, element );
        return element;
    };
    vm.createContext( context );
    for ( const name of [ 'restoreCancelLabels', 'restoreReportLabels', 'restoreOutcomeMessages' ] ) {
        const labelsStart = source.indexOf( 'var ' + name + ' = ' );
        vm.runInContext( phpToJs( source.slice( labelsStart, source.indexOf( '\n            };', labelsStart ) + '\n            };'.length ) ), context );
    }
    for ( const name of [ 'showReconcileNote', 'restoreOutcomeSentence', 'showRestoreOutcomeNote', 'showProgress', 'setPane', 'siteRecord', 'restoreDialogShows', 'showRestoreModal', 'startJobFollow', 'adoptRestoreJob', 'showRestoreChecking', 'showCachePurgeNote', 'newAdmissionToken', 'unresolvedAdmission', 'scheduleAdmissionRecheck', 'admissionStatusFailed', 'showRestoreInitiateError', 'reconcileRestoreAttempt', 'restoreAttemptUnknown', 'restoreCancelLost', 'stopRestoreFollow', 'uploadProgressText' ] ) {
        const start = source.indexOf( 'function ' + name + '(' );
        assert.ok( start >= 0 );
        const code = source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length );
        vm.runInContext( phpToJs( code ), context );
    }
    for (const selector of ['#mainwp-restore-start-btn', '#mainwp-restore-cancel-btn', '#mainwp-restore-close-btn', '#mainwp-restore-consent', '#mainwp-restore-site-selector', '#mainwp-restore-rollback-btn']) {
        const event = ['#mainwp-restore-consent', '#mainwp-restore-site-selector'].includes(selector) ? 'change' : 'click';
        const clickStart = source.indexOf("$( '" + selector + "' ).on( '" + event + "'");
        vm.runInContext(phpToJs(source.slice(clickStart, source.indexOf('\n            } );', clickStart) + '\n            } );'.length)), context);
    }
    const openStart = source.indexOf("$( document ).on( 'click', '.mainwp-restore-snapshot', function ( event ) {");
    const openFn = source.indexOf('function ( event ) {', openStart);
    vm.runInContext(phpToJs('var openRestore = ' + source.slice(openFn, source.indexOf('\n            } );', openFn)) + '};'), context);
    const note = () => context.$( '#mainwp-restore-reconcile-note' ).state;
    const text = node => [ node.text, ...node.children.map( text ) ].join( ' ' );
    const requests = [];
    Object.assign( context, { ajaxurl: '/ajax', nonces: {}, currentJob: 0, currentSite: 7, siteId: 7, currentSnapshot: 17, backupInProgress: false, snapshotListRequest: 1, pollSnapshots() {} } );
    const timers = [];
    let nextTimer = 0;
    context.window.setTimeout = (fn, delay) => { const id = ++nextTimer; timers.push({fn, delay, id}); return id; };
    context.window.clearTimeout = id => { const index = timers.findIndex(timer => timer.id === id); if (index !== -1) timers.splice(index, 1); };
    context.$.post = ( url, data, success ) => { const request = { data, success }; requests.push( request ); return { fail(fn) { request.fail = fn; return this; }, always(fn) { request.always = fn; return this; } }; };
    context.$.ajax = options => {
        const request = {data: options.data, options}; requests.push(request);
        return {done(fn) { request.success = fn; return this; }, fail(fn) { request.fail = fn; return this; }, always(fn) { request.always = fn; return this; }};
    };
    const pollStart = source.indexOf( 'function poll()' );
    vm.runInContext( phpToJs( source.slice( pollStart, source.indexOf( '\n            }', pollStart ) + '\n            }'.length ) ), context );
    return { context, note, text, created, requests, timers,
        open: () => context.openRestore.call(context.$('#restore-row').data('snapshot', 17), {preventDefault() {}}),
        close: () => handlers.get('#mainwp-restore-close-btn:click')(),
        consent: () => { context.$('#mainwp-restore-consent').prop('checked', true); handlers.get('#mainwp-restore-consent:change').call({checked: true}); },
        start: () => handlers.get('#mainwp-restore-start-btn:click').call(context.$('#mainwp-restore-start-btn')), cancel: () => handlers.get( '#mainwp-restore-cancel-btn:click' )(),
        rollback: () => { if (!context.$('#mainwp-restore-rollback-btn').prop('disabled')) handlers.get('#mainwp-restore-rollback-btn:click')(); },
        switchSite: id => {
            context.loadSnapshots = siteId => { context.currentSite = siteId; };
            const selector = context.$('#mainwp-restore-site-selector');
            selector.state.value = String(id);
            handlers.get('#mainwp-restore-site-selector:change').call(selector);
        } };
}

function loadRollback( h ) {
    const start = source.indexOf( 'function startRollback(' );
    vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) ), h.context );
}

function loadSnapshotRequests(h) {
    Object.assign(h.context, {
        backupFollowGen: 0, backupPendingKickoffs: {}, backupCancelledEpoch: {},
        enableSnapshotControls() {}, setSnapshotsPlaceholder() {}, snapshotsPlaceholders: {loading: 'Loading'},
        snapshotsTable: {rows() { return {data() { return {toArray() { return []; }}; }}; }, clear() { return this; }, draw() {}}
    });
    const start = source.indexOf('            var snapshotPollTimer =');
    vm.runInContext(phpToJs(source.slice(start, source.indexOf('            function setPane(', start))), h.context);
}

function createdJobCallback( h, action ) {
    if (action === 'mainwp_restore_initiate') {
        h.start();
    } else {
        loadRollback( h );
        h.context.startRollback( 99 );
    }
    return h.requests.pop().success;
}

for ( const [ action, label ] of [ [ 'mainwp_restore_initiate', 'Cancel Restore' ], [ 'mainwp_restore_rollback', 'Cancel Rollback' ] ] ) {
    for ( const canRestore of [ true, false ] ) {
        test( action + ' paints Queued and the permitted cancel before the first poll reply: ' + canRestore, () => {
            const h = harness();
            h.context.canRestore = canRestore;
            createdJobCallback( h, action )( { job_id: 123 } );
            assert.equal( h.requests.length, 1 );
            assert.equal( h.requests[0].data.action, 'mainwp_restore_progress' );
            assert.equal( h.context.$( '#mainwp-restore-status-text' ).state.text, 'Queued' );
            assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.text, label );
            assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.visible, canRestore );
        } );
    }
}

test( 'page-load resume waits for verifying progress without painting Queued or Cancel', () => {
    const h = harness();
    const start = source.indexOf( 'if ( response.active_restore_job_id && ! siteRecord( siteId ).attempt' );
    assert.ok( start >= 0 );
    const end = source.indexOf( 'if ( response.manual_backup_running', start );
    h.context.response = { active_restore_job_id: 123 };
    const element = h.context.$( '#mainwp-restore-modal' );
    element.modal = () => true;
    vm.runInContext( source.slice( start, end ), h.context );
    assert.equal( h.context.$( '#mainwp-restore-status-text' ).state.text, '' );
    assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.visible, false );
    h.requests[0].success( { status: 'verifying', cancel_action: '' } );
    assert.equal( h.context.$( '#mainwp-restore-status-text' ).state.text, 'verifying' );
    assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.visible, false );
} );

test( 'running rollback uses Cancel Rollback while restore keeps Cancel Restore', () => {
    const h = harness();
    for ( const is_rollback of [ true, false ] ) {
        h.context.showProgress( { status: 'running', is_rollback, cancel_action: 'cancel' } );
        assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.text, is_rollback ? 'Cancel Rollback' : 'Cancel Restore' );
    }
    h.context.showProgress( { status: 'verifying', is_rollback: true, cancel_action: 'stop_checking' } );
    assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.text, 'Stop Checking' );
} );

test( 'a new follow hides Visit site from the completed restore', () => {
    const h = harness();
    h.context.currentSiteUrl = 'https://example.com';
    h.context.showProgress( { status: 'completed' } );
    assert.equal( h.context.$( '#mainwp-restore-visit-site-wrap' ).state.visible, true );
    h.context.startJobFollow( 456 );
    assert.equal( h.context.$( '#mainwp-restore-visit-site-wrap' ).state.visible, false );
} );

test( 'Roll Back hides Visit site from the completed restore before its request answers', () => {
    const h = harness();
    const start = source.indexOf( 'function startRollback(' );
    vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) ), h.context );
    h.context.currentSiteUrl = 'https://example.com';
    h.context.showProgress( { status: 'completed' } );
    assert.equal( h.context.$( '#mainwp-restore-visit-site-wrap' ).state.visible, true );
    h.context.startRollback( 123 );
    assert.equal( h.requests.at( -1 ).data.action, 'mainwp_restore_rollback' );
    assert.equal( h.context.$( '#mainwp-restore-visit-site-wrap' ).state.visible, false );
} );

for ( const outcome of [ 'refused', 'accepted', 'unanswered', 'transport' ] ) {
    test( 'AR3-2 Roll Back ' + outcome + ' reconciles Visit site visibility', () => {
        const h = harness();
        const start = source.indexOf( 'function startRollback(' );
        vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) ), h.context );
        h.context.currentSiteUrl = 'https://example.com';
        h.context.showProgress( { status: 'completed' } );
        const visit = h.context.$( '#mainwp-restore-visit-site-wrap' ).state;
        assert.equal( visit.visible, true );
        h.context.startRollback( 123 );
        assert.equal( visit.visible, false );
        const request = h.requests.at( -1 );
        if ( outcome === 'transport' ) request.fail();
        else request.success( outcome === 'refused' ? { error: 'Disconnected', retryable: true } : outcome === 'accepted' ? { job_id: 456 } : {} );
        assert.equal( visit.visible, outcome === 'refused' );
        if ( outcome === 'accepted' ) {
            assert.equal( h.context.currentJob, 456 );
            h.requests.at( -1 ).success( { status: 'running', is_rollback: true } );
            assert.equal( visit.visible, false );
            h.context.showProgress( { status: 'completed', is_rollback: true } );
            assert.equal( visit.visible, true );
        }
    } );
}

test( 'cache advisory has a small message container hidden on initial render', () => {
    assert.match( source, /class="ui small message" id="mainwp-restore-cache-note" style="display:none;"/ );
} );

for ( const is_rollback of [ true, false ] ) {
    test( 'completed cache purge advisory and reset for rollback=' + is_rollback, () => {
        const h = harness();
        const note = h.context.$( '#mainwp-restore-cache-note' ).state;
        h.context.showProgress( { status: 'completed', is_rollback, verification: { cache_purge: { error: '<private provider error>' } } } );
        assert.equal( note.visible, true );
        assert.ok( note.classes.has( 'yellow' ) );
        assert.equal( note.text, "The site's cache could not be purged after the restore. If pages look out of date, purge the cache on the site." );
        h.context.currentSiteUrl = 'https://example.com';
        h.context.showProgress( { status: 'completed', is_rollback, verification: { cache_purge: { error: true } } } );
        assert.equal( h.context.$( '#mainwp-restore-visit-site-wrap' ).state.visible, true );
        h.context.startJobFollow( 456 );
        assert.equal( note.visible, false );
        assert.equal( h.context.$( '#mainwp-restore-visit-site-wrap' ).state.visible, false );
        h.context.showProgress( { status: 'completed', is_rollback, verification: { cache_purge: { error: true } } } );
        h.context.setPane( true );
        assert.equal( note.visible, false );
    } );
}

test( 'completed jobs without a cache purge error have no advisory, including after an earlier failure', () => {
    const h = harness();
    for ( const verification of [ undefined, {}, { cache_purge: { attempted: false } }, { cache_purge: { error: '' } }, { cache_purge: { attempted: true } } ] ) {
        h.context.showProgress( { status: 'completed', verification: { cache_purge: { error: true } } } );
        h.context.showProgress( { status: 'completed', verification } );
        assert.equal( h.context.$( '#mainwp-restore-cache-note' ).state.visible, false );
    }
} );

test( 'kept files show totals, reasons and at most twenty paths as text', () => {
    const h = harness();
    const unsafe = '<img src=x onerror=alert(1)>';
    const paths = [ unsafe, ...Array.from( { length: 24 }, ( _, i ) => 'wp-content/host-' + i + '.php' ) ];
    h.context.showReconcileNote( { reconcile: {
        status: 'done', removed: 2, failed: 1, kept: 25,
        kept_by_reason: { mu_plugins: 24, unarchived: 1 }, kept_paths: paths
    } }, 'files', false );
    const text = h.text( h.note() );
    assert.match( text, /Kept 25 files/ );
    assert.match( text, /mu-plugins files missing from the backup/ );
    assert.match( text, /files the backup could not read/ );
    assert.match( text, /Showing 20 of 25/ );
    assert.match( text, /Review the kept mu-plugins files if you did not add them\./ );
    assert.match( text, /Removed 2 files/ );
    assert.match( text, /1 file could not be removed/ );
    assert.equal( h.created.filter( node => node.tag === '<li>' ).length, 20 );
    assert.ok( h.created.some( node => node.tag === '<li>' && node.text === unsafe ) );
    assert.ok( h.note().classes.has( 'yellow' ) );
} );

test( 'kept-only result is visible, and a later empty result clears samples', () => {
    const h = harness();
    h.context.showReconcileNote( { reconcile: { status: 'done', kept: 1, kept_by_reason: { mu_plugins: 1 }, kept_paths: [ 'host.php' ] } }, 'files', false );
    assert.match( h.text( h.note() ), /Kept 1 file/ );
    assert.doesNotMatch( h.text( h.note() ), /Showing/ );
    assert.ok( h.note().visible );
    h.context.showReconcileNote( { reconcile: { status: 'done', removed: 0 } }, 'files', false );
    assert.equal( h.note().visible, false );
    assert.equal( h.text( h.note() ).trim(), '' );
} );

test( 'kept symlinks get their own sentence and never claim the backup had no copy', () => {
    const h = harness();
    h.context.showReconcileNote( { reconcile: { status: 'done', removed: 0, kept: 1, kept_by_reason: { symlink: 1 }, kept_paths: [ 'wp-content/mu-plugins/wp-fail2ban.php' ] } }, 'full', false );
    const text = h.text( h.note() );
    assert.match( text, /\b1 symbolic link\b/ );
    assert.doesNotMatch( text, /no copy of/ );
    assert.doesNotMatch( text, /Reasons:/ );
    assert.ok( h.created.some( node => node.tag === '<li>' && node.text === 'wp-content/mu-plugins/wp-fail2ban.php' ) );
    assert.ok( h.note().visible );
    assert.ok( h.note().classes.has( 'blue' ) );
} );

test( 'mixed kept results split symlinks from files the backup has no copy of', () => {
    const h = harness();
    const paths = Array.from( { length: 22 }, ( _, i ) => 'wp-content/kept-' + i + '.php' );
    h.context.showReconcileNote( { reconcile: { status: 'done', removed: 0, kept: 22, kept_by_reason: { mu_plugins: 1, unarchived: 1, symlink: 20 }, kept_paths: paths } }, 'files', false );
    const text = h.text( h.note() );
    assert.match( text, /Kept 2 files this backup has no copy of\./ );
    assert.match( text, /mu-plugins files missing from the backup/ );
    assert.match( text, /\b20 symbolic links\b/ );
    assert.match( text, /Showing 20 of 22 kept files/ );
    assert.equal( h.created.filter( node => node.tag === '<li>' ).length, 20 );
} );

test( 'a file wp-config.php loads gets its own sentence after the kept count', () => {
    const h = harness();
    h.context.showReconcileNote( { reconcile: { status: 'done', removed: 0, kept: 2, kept_by_reason: { config_include: 1, symlink: 1 }, kept_paths: [ 'host-include.php', 'wp-content/mu-plugins/link.php' ] } }, 'full', false );
    const paragraphs = h.created.filter( node => node.tag === '<p>' ).map( node => node.text );
    assert.deepEqual( paragraphs.slice( 0, 2 ), [ 'Kept 1 file this backup has no copy of.', '1 file was kept because wp-config.php loads it.' ] );
    assert.doesNotMatch( h.text( h.note() ), /Reasons:/ );
    assert.ok( h.created.some( node => node.tag === '<li>' && node.text === 'host-include.php' ) );
    assert.ok( h.note().classes.has( 'blue' ) );
} );

test( 'several files wp-config.php loads read in the plural beside other reasons', () => {
    const h = harness();
    h.context.showReconcileNote( { reconcile: { status: 'done', removed: 0, kept: 5, kept_by_reason: { config_include: 3, mu_plugins: 1, unarchived: 1 }, kept_paths: [ 'a.php', 'b.php', 'c.php', 'wp-content/mu-plugins/m.php', 'wp-content/u.txt' ] } }, 'files', false );
    const paragraphs = h.created.filter( node => node.tag === '<p>' ).map( node => node.text );
    assert.equal( paragraphs[0], 'Kept 5 files this backup has no copy of. Reasons: mu-plugins files missing from the backup; files the backup could not read.' );
    assert.equal( paragraphs[1], '3 files were kept because wp-config.php loads them.' );
} );

test( 'a config_include count never exceeds the kept files outside symlinks', () => {
    const h = harness();
    h.context.showReconcileNote( { reconcile: { status: 'done', removed: 0, kept: 2, kept_by_reason: { config_include: 5, symlink: 1 }, kept_paths: [ 'a.php', 'link.php' ] } }, 'full', false );
    assert.match( h.text( h.note() ), /\b1 file was kept because wp-config\.php loads it\./ );
} );

test( 'kept symlinks still show when reconciliation was skipped', () => {
    const h = harness();
    h.context.showReconcileNote( { reconcile: { status: 'skipped', reason: 'no_manifest', kept: 2, kept_by_reason: { symlink: 2 }, kept_paths: [ 'a.php', 'b.php' ] } }, 'full', false );
    const text = h.text( h.note() );
    assert.match( text, /no file list to compare against/ );
    assert.match( text, /\b2 symbolic links\b/ );
    assert.doesNotMatch( text, /no copy of/ );
    assert.ok( h.note().classes.has( 'yellow' ) );
} );

test( 'walk_invalid explains why added files were kept', () => {
    const h = harness();
    h.context.showReconcileNote( { reconcile: { status: 'skipped', reason: 'walk_invalid' } }, 'full', false );
    assert.ok( h.note().visible );
    assert.ok( h.note().classes.has( 'yellow' ) );
    assert.equal( h.text( h.note() ), WALK_INVALID_NOTE );
} );

for ( const reason of [ 'unarchived_invalid', 'unarchived_overflow', 'unarchived_unsupported', 'walk_invalid' ] ) {
    test( reason + ' shows a yellow skipped note', () => {
        const h = harness();
        h.context.showReconcileNote( { reconcile: { status: 'skipped', reason } }, 'files', false );
        assert.ok( h.note().visible );
        assert.ok( h.note().classes.has( 'yellow' ) );
        assert.ok( h.text( h.note() ).length > 0 );
    } );
}

for ( const status of [ 'completed', 'failed_permanent', 'failed_retryable' ] ) {
    test( status + ' shows reconcile results while excluding db and rollback', () => {
        const h = harness();
        const data = { status, sub_type: 'files', verification: { reconcile: { status: 'done', kept: 1, kept_paths: [ 'host.php' ] } } };
        h.context.showProgress( data );
        assert.ok( h.note().visible );
        h.context.showProgress( { ...data, sub_type: 'db' } );
        assert.equal( h.note().visible, false );
        h.context.showProgress( { ...data, is_rollback: true } );
        assert.equal( h.note().visible, false );
    } );
}

test( 'cancel and rollback stay hidden without the restore right', () => {
    for ( const canRestore of [ true, false ] ) {
        const h = harness();
        h.context.canRestore = canRestore;
        h.context.showProgress( { status: 'running', sub_type: 'full', cancel_action: 'cancel' } );
        assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.visible, canRestore );
        assert.equal( h.context.$( '#mainwp-restore-cancel-warning' ).state.visible, canRestore );
        h.context.showProgress( { status: 'completed', sub_type: 'full', has_rollback: true } );
        assert.equal( h.context.$( '#mainwp-restore-rollback-btn' ).state.visible, canRestore );
    }
} );

test( 'resolved cancel actions control the label and preserve restore rights', () => {
    for ( const canRestore of [ true, false ] ) {
        const h = harness();
        h.context.canRestore = canRestore;
        for ( const action of [ 'stop_checking', 'cancel', '' ] ) {
            h.context.showProgress( { status: 'failed_retryable', cancel_action: action, cancel_requested: action === 'stop_checking' } );
            const button = h.context.$( '#mainwp-restore-cancel-btn' ).state;
            assert.equal( button.visible, canRestore && action !== '' );
            if ( action ) assert.equal( button.text, action === 'stop_checking' ? 'Stop Checking' : 'Cancel Restore' );
            assert.equal( h.context.$( '#mainwp-restore-cancel-warning' ).state.visible, false );
        }
    }
} );

test( 'a stopped verification shows its result and retains rollback', () => {
    const h = harness();
    h.context.showProgress( { status: 'failed_permanent', cancel_action: '', verify_stopped: true, has_rollback: true, last_error: 'Raw unreachable error' } );
    assert.equal( h.context.$( '#mainwp-restore-result-message' ).state.text, 'Stopped checking. The restore finished on the site, but the Dashboard could not reach it afterwards, so this restore is recorded as failed. Open the site to check it.' );
    assert.equal( h.context.$( '#mainwp-restore-rollback-btn' ).state.visible, true );
    assert.equal( h.context.$( '#mainwp-restore-cancel-btn' ).state.visible, false );
} );

test( 'only a successful stop polls at once; refusals paint the error', () => {
    for ( const response of [ { result: 'stopped' }, { result: 'ok' }, { result: 'pending' }, { error: 'Refused' }, { error: 'Server refusal', error_code: 'mainwp_restore_checking_changed' } ] ) {
        const h = harness();
        let polls = 0;
        let clears = 0;
        Object.assign( h.context, { ajaxurl: '/ajax', nonces: { cancel: 'nonce' }, currentJob: 42, poll: () => { polls++; } } );
        h.context.window.clearTimeout = () => { clears++; };
        h.context.$.ajax = ({data}) => {
            return {done(callback) {
            assert.equal( data.action, 'mainwp_restore_cancel' );
            assert.equal( data.job_id, 42 );
            callback( response );
            return this; }, fail() { return this; }};
        };
        h.cancel();
        assert.equal( polls, response.result === 'stopped' ? 1 : 0 );
        assert.equal( clears, polls );
        if ( response.error ) {
            const expected = response.error_code ? h.context.restoreCancelLabels.checking : response.error;
            assert.equal( h.context.$( '#mainwp-restore-result-message' ).state.text, expected );
        }
    }
} );

for ( const data of [
    { status: 'running', restore_outcome: { valid: false } },
    { status: 'completed', restore_outcome: { valid: false } },
    { status: 'failed_permanent', restore_outcome: null },
    { status: 'failed_permanent', restore_outcome: { valid: true, files: 'applied', database: 'applied' } },
    { status: 'failed_permanent', restore_outcome: { valid: true, files: 'applied', database: 'not_applicable' } },
    { status: 'failed_permanent', restore_outcome: { valid: true, files: 'not_applicable', database: 'applied' } },
    { status: 'cancelled', error_code: 'mainwp_restore_rolled_back', restore_outcome: { valid: false } },
    { status: 'cancelled', error_code: 'mainwp_restore_rollback_failed', restore_outcome: { valid: false } }
] ) {
    test( 'outcome note clears stale text for ' + JSON.stringify( data ), () => {
        const h = harness();
        const note = h.context.$( '#mainwp-restore-outcome-note' );
        note.text( 'Old report' ).show();
        h.context.showProgress( data );
        assert.equal( note.state.visible, false );
        assert.equal( note.state.text, '' );
        if ( data.error_code === 'mainwp_restore_rollback_failed' ) {
            assert.equal( h.context.$( '#mainwp-restore-maintenance-note' ).state.visible, true );
            assert.equal( h.context.$( '#mainwp-restore-result-message' ).state.text, h.context.restoreOutcomeMessages.mainwp_restore_rollback_failed );
        }
    } );
}

test( 'incomplete outcome is plain text and does not suggest rollback', () => {
    const h = harness();
    h.context.showProgress( { status: 'failed_permanent', has_rollback: true, restore_outcome: { valid: false, files: '<img src=x>' } } );
    const note = h.context.$( '#mainwp-restore-outcome-note' ).state;
    assert.equal( note.visible, true );
    assert.equal( note.text, h.context.restoreReportLabels.unknown );
} );

for ( const [ files, database ] of [ [ 'not_started', 'not_started' ], [ 'not_started', 'not_applicable' ], [ 'not_applicable', 'not_started' ] ] ) {
    test( 'unchanged applicable halves use one sentence: ' + files + '/' + database, () => {
        const h = harness();
        h.context.showProgress( { status: 'cancelled', has_rollback: true, restore_outcome: { valid: true, files, database } } );
        const note = h.context.$( '#mainwp-restore-outcome-note' ).state;
        assert.equal( note.visible, true );
        assert.equal( note.text, h.context.restoreReportLabels.nothingChanged );
    } );
}

test( 'partial outcome suggests Roll Back only for a restore safety backup', () => {
    for ( const canRestore of [ true, false ] ) {
        for ( const rollbackRequestPending of [ true, false ] ) {
            for ( const has_rollback of [ true, false ] ) {
                for ( const is_rollback of [ true, false ] ) {
                    const h = harness();
                    h.context.canRestore = canRestore;
                    h.context.siteRecord( 7 ).attempt = rollbackRequestPending ? { kind: 'rollback' } : null;
                    h.context.showProgress( { status: 'failed_permanent', has_rollback, is_rollback, restore_outcome: { valid: true, files: 'applied', database: 'left_partial' } } );
                    const visible = h.context.$( '#mainwp-restore-rollback-btn' ).state.visible;
                    assert.equal( visible, canRestore && ! rollbackRequestPending && has_rollback );
                    assert.equal( h.context.$( '#mainwp-restore-outcome-note' ).state.text,
                        h.context.restoreReportLabels.filesApplied + ' ' + h.context.restoreReportLabels.databasePartial + ( visible && ! is_rollback ? ' ' + h.context.restoreReportLabels.rollBack : '' ) );
                }
            }
        }
    }
} );

test( 'partial-state copy preserves uncertainty about site changes', () => {
    const h = harness();
    assert.equal( h.context.restoreReportLabels.filesPartial, 'Some files from the backup may have been copied to the site before the restore stopped. Any copied files stay in place.' );
    assert.equal( h.context.restoreReportLabels.databasePartial, 'The database may be partly restored and may not match the backup.' );
} );

for ( const [ files, database, labels ] of [
    [ 'left_partial', 'not_started', [ 'filesPartial', 'databaseNotStarted' ] ],
    [ 'applied', 'not_started', [ 'filesApplied', 'databaseNotStarted' ] ],
    [ 'not_started', 'applied', [ 'filesNotStarted', 'databaseApplied' ] ],
    [ 'not_applicable', 'rolled_back', [ 'databaseRolledBack' ] ],
    [ 'left_partial', 'not_applicable', [ 'filesPartial' ] ]
] ) {
    test( 'applicable halves use their own sentences: ' + files + '/' + database, () => {
        const h = harness();
        h.context.showProgress( { status: 'cancelled', restore_outcome: { valid: true, files, database } } );
        assert.equal( h.context.$( '#mainwp-restore-outcome-note' ).state.text, labels.map( label => h.context.restoreReportLabels[ label ] ).join( ' ' ) );
    } );
}

test( 'following another job hides the previous outcome before polling', () => {
    const h = harness();
    h.context.setPane = () => {};
    h.context.poll = () => {};
    const start = source.indexOf( 'function startJobFollow(' );
    vm.runInContext( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ), h.context );
    h.context.$( '#mainwp-restore-outcome-note' ).text( 'Old report' ).show();
    h.context.startJobFollow( 123 );
    assert.equal( h.context.$( '#mainwp-restore-outcome-note' ).state.visible, false );
} );

function snapshotPollHarness() {
    const timers = new Map(), requests = [], rows = [{id: 1, uploads: [{status: 'uploading'}]}];
    let nextTimer = 0;
    const notices = [], reloads = [], draws = [];
    const context = {currentSite: 7, snapshotListRequest: 1, ajaxurl: '/ajax', nonces: {}, serverClockOffset: null,
        loadSnapshots(site) { reloads.push(site); },
        snapshotsTable: {rows() { return {data() { return {toArray() { return rows; }}; }, every(fn) { rows.forEach((row, i) => fn.call({data(value) { if (value) rows[i] = value; return rows[i]; }, child: {isShown() { return false; }}})); }}; }, draw() { draws.push(true); }},
        window: {clearTimeout(id) { timers.delete(id); }, setTimeout(fn, delay) { timers.set(++nextTimer, {fn, delay}); return nextTimer; }},
        $: {ajax(options) { const request = {options}; requests.push(request); return {done(fn) { request.done = fn; return this; }, fail(fn) { request.fail = fn; return this; }}; }}
    };
    const ajax = context.$.ajax;
    context.$ = () => ({text(message) { notices.push(message); return this; }, show() { return this; }});
    context.$.ajax = ajax;
    vm.createContext(context);
    const start = source.indexOf('            var snapshotPollTimer =');
    vm.runInContext(phpToJs(source.slice(start, source.indexOf('            function loadSnapshots(', start))), context);
    function tick() { const [id, timer] = timers.entries().next().value; timers.delete(id); timer.fn(); }
    return {context, timers, requests, rows, tick, notices, reloads, draws};
}
test('snapshot poll starts for every active upload state, updates bytes and stops on completion', () => {
    for (const status of ['pending', 'chunking', 'uploading', 'submitting', 'failed_retryable']) {
        const h = snapshotPollHarness();
        h.context.scheduleSnapshotPoll([{uploads: [{status}]}], 7, 1);
        assert.equal(h.timers.size, 1);
        assert.equal([...h.timers.values()][0].delay, 5000);
        h.tick();
        assert.equal(h.requests[0].options.timeout, 30000);
        h.requests[0].done({snapshots: [{id: 1, uploads: [{status: 'uploading', upload_progress: {bytes_done: 118, bytes_total: 264}}]}]});
        assert.equal(h.rows[0].uploads[0].upload_progress.bytes_done, 118);
        h.tick();
        h.requests[1].done({snapshots: [{id: 1, uploads: [{status: 'complete'}]}]});
        assert.equal(h.timers.size, 0);
    }
});
test('snapshot poll backs off after transport and server errors and recovers', () => {
    const h = snapshotPollHarness();
    h.context.scheduleSnapshotPoll(h.rows, 7, 1);
    h.tick();
    h.requests[0].fail();
    assert.equal([...h.timers.values()][0].delay, 10000);
    h.tick();
    h.requests[1].done({error: 'Temporary error'});
    assert.equal([...h.timers.values()][0].delay, 20000);
    h.tick();
    h.requests[2].done({snapshots: h.rows});
    assert.equal([...h.timers.values()][0].delay, 5000);
});
test('snapshot poll ignores a reply and timer after a site switch', () => {
    const h = snapshotPollHarness();
    h.context.scheduleSnapshotPoll(h.rows, 7, 1);
    h.tick();
    h.context.currentSite = 8;
    h.context.snapshotListRequest++;
    h.requests[0].done({snapshots: [{id: 1, uploads: []}]});
    h.requests[0].fail();
    assert.equal(h.rows[0].uploads[0].status, 'uploading');
    assert.equal(h.timers.size, 0);
    h.context.pollSnapshots(7, 1);
    assert.equal(h.requests.length, 1);
});

for (const admitted of [true, false]) {
    test('initiate timeout reconciles by token, admitted=' + admitted, () => {
        const h = harness();
        h.consent(); h.start();
        const token = h.requests[0].data.admission_token;
        assert.match(token, /^[a-f0-9]{32}$/);
        assert.equal(h.requests[0].options.timeout, 90000);
        h.requests[0].fail({}, 'timeout');
        assert.equal(h.requests[1].data.action, 'mainwp_restore_admission_status');
        assert.equal(h.requests[1].data.admission_token, token);
        h.requests[1].success(admitted ? {state: 'admitted', job_id: 123} : {state: 'not_admitted'});
        assert.equal(h.context.currentJob, admitted ? 123 : 0);
        assert.equal(h.context.$('#mainwp-restore-confirm-pane').state.visible, !admitted);
        if (!admitted) {
            assert.equal(h.context.$('#mainwp-restore-consent').prop('checked'), false);
            assert.equal(h.context.$('#mainwp-restore-start-btn').prop('disabled'), false);
            h.consent(); h.start();
            assert.notEqual(h.requests[2].data.admission_token, token);
        }
    });
}
for (const status of ['completed', 'running', 'failed_permanent', 'cancelled']) {
    test('lost initiate response follows its token even when ' + status, () => {
        const h = harness();
        h.consent(); h.start(); h.requests[0].fail();
        h.requests[1].success({state: 'admitted', job_id: 123});
        assert.equal(h.context.currentJob, 123);
        assert.equal(h.requests[2].data.job_id, 123);
        h.requests[2].success({status});
        assert.equal(h.context.$('#mainwp-restore-start-btn').state.visible, false);
        assert.equal(h.timers.length, status === 'running' ? 1 : 0);
    });
}
test('backup-action refusal retains its server message when the token was not admitted', () => {
    const h = harness();
    h.consent(); h.start();
    const error = 'Another backup action is working on this site. Try again in a minute.';
    let refresh;
    h.context.pollSnapshots = (...args) => { refresh = args; };
    h.requests[0].success({error, error_code: 'mainwp_backup_already_running'});
    h.requests[1].success({state: 'not_admitted'});
    assert.equal(h.context.$('#mainwp-restore-confirm-error').state.text, error);
    assert.equal(h.context.$('#mainwp-restore-consent').prop('checked'), false);
    assert.deepEqual(refresh, [7, 1, true]);
});

for (const callback of ['success', 'fail']) {
    test('closing during an in-flight restore poll stops its ' + callback + ' chain', () => {
        const h = harness();
        h.context.startJobFollow(123);
        h.close();
        if (callback === 'success') h.requests[0].success({status: 'running'});
        else h.requests[0].fail();
        assert.equal(h.timers.length, 0);
        assert.equal(h.requests.length, 1);
        h.open();
        assert.equal(h.requests.length, 2);
        assert.equal(h.requests[1].data.job_id, 123);
        h.requests[1].success({status: 'running'});
        assert.equal(h.timers.length, 1, 'Reopening owns a fresh poll chain.');
        assert.equal(h.context.$('#mainwp-restore-start-btn').state.visible, false);
    });
}

test('a closed poll reply cannot release the reopened poll in-flight guard', () => {
    const h = harness();
    h.context.startJobFollow(123);
    h.close(); h.open();
    assert.equal(h.requests.length, 2);
    h.requests[0].success({status: 'completed'});
    h.context.poll();
    assert.equal(h.requests.length, 2);
    assert.equal(h.timers.length, 0);
    h.requests[1].success({status: 'running'});
    assert.equal(h.timers.length, 1);
});

function uploadSummary(uploads) {
    const h = harness();
    const start = source.indexOf('if ( snap.uploads && snap.uploads.length )');
    const end = source.indexOf('\n                            return sourceCell;', start);
    h.context.snap = {uploads};
    vm.runInContext(phpToJs('var sourceCell = ""; ' + source.slice(start, end - '\n                            }'.length)), h.context);
    return h.context.sourceCell;
}

test('upload summary omits an empty progress parenthetical', () => {
    assert.match(uploadSummary([{status: 'complete'}]), /1 of 1 uploaded<\/small>/);
    assert.match(uploadSummary([{status: 'uploading'}]), /0 of 1 uploaded<\/small>/);
});

test('upload summary includes bytes only for active uploads with progress', () => {
    const upload_progress = {bytes_done: 264 * 1048576, bytes_total: 264 * 1048576};
    for (const status of ['complete', 'failed_permanent', 'credentials_invalid', 'cancelled']) {
        assert.doesNotMatch(uploadSummary([{status, upload_progress}]), /MB|\(\)/);
    }
    for (const status of ['pending', 'chunking', 'uploading', 'submitting', 'failed_retryable']) {
        const result = uploadSummary([{status: 'complete', upload_progress}, {status, upload_progress}]);
        assert.match(result, /1 of 2 uploaded \(264 MB of 264 MB\)/);
    }
});

test('failed status checks keep Start disabled and give up after eight consecutive failures', () => {
    const h = harness();
    h.consent(); h.start(); h.requests[0].fail();
    for (let i = 0; i < 8; i++) {
        h.requests.at(-1).fail();
        assert.equal(h.context.$('#mainwp-restore-start-btn').prop('disabled'), true);
        h.start();
        assert.equal(h.requests.filter(r => r.data.action === 'mainwp_restore_initiate').length, 1);
        if (i < 7) {
            const timer = h.timers.shift();
            assert.ok(timer.delay >= 3000 && timer.delay <= 60000);
            timer.fn();
        }
    }
    assert.equal(h.timers.length, 0);
    assert.equal(h.context.$('#mainwp-restore-confirm-error').state.text, 'Reload the page to see whether the restore started.');
    assert.equal(h.context.$('#mainwp-restore-consent').prop('checked'), false);
});
test('cancel timeout keeps the job and progress pane, resumes polling and permits another cancel', () => {
    const h = harness();
    h.context.startJobFollow(123);
    h.context.$('#mainwp-restore-cancel-btn').show();
    h.cancel();
    assert.equal(h.requests[1].options.timeout, 30000);
    h.requests[1].fail({}, 'timeout');
    assert.equal(h.context.currentJob, 123);
    assert.equal(h.context.$('#mainwp-restore-progress-pane').state.visible, true);
    assert.equal(h.context.$('#mainwp-restore-result-message').state.visible, true);
    assert.equal(h.context.$('#mainwp-restore-cancel-btn').state.visible, true);
    assert.equal(h.timers.length, 0, 'The in-flight status request owns the poll chain.');
    h.requests[0].success({status: 'running'});
    h.timers.at(-1).fn();
    assert.equal(h.requests.at(-1).data.action, 'mainwp_restore_progress');
    h.cancel();
    assert.equal(h.requests.at(-1).data.job_id, 123);
});
for (const response of [{error: 'Test restore running', error_code: 'mainwp_backup_already_running', test_restore_running: true}, {error: 'Try again', retryable: true}, {needs_confirmation: true}]) {
    test('restore refusal stays on the confirmation pane: ' + JSON.stringify(response), () => {
        const h = harness();
        h.context.$('#mainwp-restore-consent').prop('checked', true);
        h.start(); h.requests[0].success(response);
        assert.equal(h.context.$('#mainwp-restore-confirm-pane').state.visible, true);
        assert.equal(h.context.$('#mainwp-restore-progress-pane').state.visible, false);
        assert.equal(h.context.$('#mainwp-restore-confirm-error').state.visible, true);
        assert.equal(h.context.$('#mainwp-restore-start-btn').hasClass('disabled'), !!response.needs_confirmation);
        if (response.error) {
            assert.equal(h.context.$('#mainwp-restore-confirm-error').state.text, response.error);
            assert.equal(h.context.$('#mainwp-restore-start-btn').prop('disabled'), false);
        }
    });
}
test('test-restore badge clears when the reservation disappears, including without a result', () => {
    const h = snapshotPollHarness();
    h.rows[0] = {id: 1, test_restore_running: true, test_restore_state: ''};
    const start = source.indexOf('function testRestoreCell(');
    h.context.escAttr = String;
    h.context.testRestoreLabels = {};
    vm.runInContext(phpToJs(source.slice(start, source.indexOf('\n            }', start) + '\n            }'.length)), h.context);
    assert.match(h.context.testRestoreCell(h.rows[0]), /Test restore running/);
    h.context.scheduleSnapshotPoll(h.rows, 7, 1); h.tick();
    h.requests[0].done({snapshots: [{id: 1, test_restore_running: false, test_restore_state: ''}]});
    assert.doesNotMatch(h.context.testRestoreCell(h.rows[0]), /Test restore running/);
    assert.equal(h.timers.size, 0);
});


test('another admin active job is only resumed by the full list after this token is refused', () => {
    const h = harness();
    h.consent(); h.start();
    h.requests[0].success({error: 'Already running', error_code: 'mainwp_backup_already_running'});
    h.requests[1].success({state: 'not_admitted', active_restore_job_id: 456});
    assert.equal(h.context.currentJob, 0);
    assert.equal(h.context.siteRecord(7).attempt, null);
    const start = source.indexOf('if ( response.active_restore_job_id && ! siteRecord( siteId ).attempt');
    h.context.response = {active_restore_job_id: 456};
    vm.runInContext(source.slice(start, source.indexOf('if ( response.manual_backup_running', start)), h.context);
    assert.equal(h.context.currentJob, 456);
});

for (const result of ['lost', 'stopped']) {
    for (const progressResult of ['success', 'failure']) {
        test('cancel ' + result + ' during an in-flight progress ' + progressResult + ' keeps one poll chain', () => {
            const h = harness();
            h.context.startJobFollow(123);
            h.cancel();
            if (result === 'lost') h.requests[1].fail();
            else h.requests[1].success({result: 'stopped'});
            assert.equal(h.requests.filter(r => r.data.action === 'mainwp_restore_progress').length, 1);
            assert.equal(h.timers.length, 0);
            if (result === 'lost') assert.equal(h.context.$('#mainwp-restore-result-message').state.text, 'The cancel request did not get a response. Checking the restore status. You can try Cancel again.');
            if (progressResult === 'success') h.requests[0].success({status: 'running'});
            else h.requests[0].fail();
            assert.equal(h.timers.length, 1);
            h.timers[0].fn();
            assert.equal(h.requests.filter(r => r.data.action === 'mainwp_restore_progress').length, 2);
            h.requests[2].success({status: 'running'});
            assert.equal(h.timers.length, 1);
        });
    }
}

test('snapshot polling stops after eight consecutive failures with a reload notice', () => {
    const h = snapshotPollHarness();
    h.context.scheduleSnapshotPoll(h.rows, 7, 1);
    for (let i = 0; i < 8; i++) {
        assert.equal(h.timers.size, 1);
        h.tick();
        if (i % 2) h.requests[i].done({error: 'Temporary error'});
        else h.requests[i].fail();
    }
    assert.equal(h.timers.size, 0);
    assert.deepEqual(h.notices, ['Reload the page to refresh the upload status.']);
});

test('a successful snapshot read resets the consecutive failure budget', () => {
    const h = snapshotPollHarness();
    h.context.scheduleSnapshotPoll(h.rows, 7, 1);
    for (let i = 0; i < 7; i++) { h.tick(); h.requests[i].fail(); }
    h.tick(); h.requests[7].done({snapshots: h.rows});
    h.tick(); h.requests[8].fail();
    assert.equal(h.timers.size, 1);
    assert.deepEqual(h.notices, []);
});

test('activity poll sends only active ids, merges dynamic fields and reloads on missing', () => {
    const h = snapshotPollHarness();
    h.rows[0].type = 'db'; h.rows[0].created_label = 'Today'; h.rows[0].offsite_state = 'pending';
    h.rows.push({id: 2, uploads: [{status: 'complete'}]});
    h.context.pollSnapshots(7, 1);
    const data = h.requests[0].options.data;
    assert.equal(data.action, 'mainwp_restore_snapshot_activity');
    assert.deepEqual([...data.snapshot_ids], [1]);
    h.requests[0].done({snapshots: [{id: 1, offsite_state: 'uploading', uploads: [{status: 'uploading'}]}], missing: [], server_time: 2000000000});
    assert.equal(h.rows[0].type, 'db');
    assert.equal(h.rows[0].created_label, 'Today');
    assert.equal(h.rows[0].offsite_state, 'uploading');
    assert.equal(h.rows[1].uploads[0].status, 'complete');
    h.rows[0].uploads[0].status = 'uploading';
    h.context.pollSnapshots(7, 1);
    h.requests[1].done({snapshots: [], missing: [1]});
    assert.deepEqual(h.reloads, [7]);
});
test('activity ending reloads the full list once without drawing or scheduling', () => {
    const h = snapshotPollHarness();
    h.rows[0].offsite_state = 'uploading';
    h.rows.push({id: 2, test_restore_running: true});
    h.rows.push({id: 3, uploads: [{status: 'uploading'}]});
    h.context.scheduleSnapshotPoll(h.rows, 7, 1); h.tick();
    h.requests[0].done({snapshots: [
        {id: 1, status: 'complete', offsite_state: 'complete', uploads: [{status: 'complete'}]},
        {id: 2, test_restore_running: false},
        {id: 3, uploads: [{status: 'uploading'}]}
    ]});
    assert.deepEqual(h.reloads, [7]);
    assert.equal(h.rows[0].offsite_state, 'complete');
    assert.equal(h.rows[0].status, 'complete');
    assert.equal(h.draws.length, 0);
    assert.equal(h.timers.size, 0);
});
test('still active and inactive rows do not reload the full list', () => {
    const h = snapshotPollHarness();
    h.rows.push({id: 2, uploads: [{status: 'complete'}]});
    h.context.scheduleSnapshotPoll(h.rows, 7, 1); h.tick();
    h.requests[0].done({snapshots: [
        {id: 1, offsite_state: 'uploading', uploads: [{status: 'uploading'}]},
        {id: 2, offsite_state: 'complete', uploads: [{status: 'complete'}]}
    ]});
    assert.deepEqual(h.reloads, []);
    assert.equal(h.rows[0].offsite_state, 'uploading');
    assert.equal(h.rows[1].offsite_state, 'complete');
    assert.equal(h.draws.length, 1);
    assert.equal(h.timers.size, 1);
});
test('activity poll rotates batches of at most fifty active ids', () => {
    const h = snapshotPollHarness();
    h.rows.splice(0, 1, ...Array.from({length: 60}, (_, i) => ({id: i + 1, uploads: [{status: 'uploading'}]})));
    h.context.pollSnapshots(7, 1);
    assert.equal(h.requests[0].options.data.snapshot_ids.length, 50);
    h.requests[0].done({snapshots: [], missing: []}); h.tick();
    assert.equal(h.requests[1].options.data.snapshot_ids[0], 51);
});

// One row per event in the restore attempt state model. Each row drives the
// real handlers and checks the site record and what the dialog shows.
const CHECKING = 'Checking whether the restore started';
const record = (h, site = 7) => h.context.siteRecord(site);
const sent = (h, action) => h.requests.filter(r => r.data.action === action);
const last = (h, action) => sent(h, action).at(-1);
function assertBlocked(h) {
    assert.equal(h.context.$('#mainwp-restore-start-btn').prop('disabled'), true);
    const before = sent(h, 'mainwp_restore_initiate').length;
    h.consent(); h.start();
    assert.equal(sent(h, 'mainwp_restore_initiate').length, before);
}
const matrix = [
    ['Start generates a fresh token and shows Checking immediately', h => {
        h.consent(); h.start();
        assert.match(record(h).attempt.token, /^[a-f0-9]{32}$/);
        assert.equal(last(h, 'mainwp_restore_initiate').data.admission_token, record(h).attempt.token);
        assert.equal(h.context.$('#mainwp-restore-status-text').state.text, CHECKING);
        assertBlocked(h);
    }],
    ['lost reply adopts only its admitted token job', h => {
        h.consent(); h.start(); h.requests[0].fail();
        last(h, 'mainwp_restore_admission_status').success({state: 'admitted', job_id: 123});
        assert.deepEqual({...record(h)}, {attempt: null, jobId: 123, active: true});
        assert.equal(last(h, 'mainwp_restore_progress').data.job_id, 123);
    }],
    ['pending rechecks and keeps Start disabled', h => {
        h.consent(); h.start(); h.requests[0].fail();
        last(h, 'mainwp_restore_admission_status').success({state: 'pending'});
        assertBlocked(h);
        assert.equal(h.timers[0].delay, 3000);
        h.timers.shift().fn();
        assert.equal(sent(h, 'mainwp_restore_admission_status').length, 2);
    }],
    ['pending resets consecutive status failures', h => {
        h.consent(); h.start(); h.requests[0].fail();
        last(h, 'mainwp_restore_admission_status').fail(); h.timers.shift().fn();
        last(h, 'mainwp_restore_admission_status').success({state: 'pending'});
        assert.equal(record(h).attempt.failures, 0);
        assert.equal(h.timers[0].delay, 3000);
    }],
    ['pending reaches ten minute ceiling with Start disabled', h => {
        h.consent(); h.start(); h.requests[0].fail();
        record(h).attempt.startedAt = Date.now() - 600000;
        last(h, 'mainwp_restore_admission_status').success({state: 'pending'});
        assert.equal(h.timers.length, 0);
        assertBlocked(h);
        assert.equal(h.context.$('#mainwp-restore-confirm-error').state.text, 'Reload the page to see whether the restore started.');
    }],
    ['not_admitted clears consent and permits a fresh deliberate click', h => {
        h.consent(); h.start(); h.requests[0].fail();
        const token = record(h).attempt.token;
        last(h, 'mainwp_restore_admission_status').success({state: 'not_admitted'});
        assert.equal(record(h).attempt, null);
        assert.equal(h.context.$('#mainwp-restore-consent').prop('checked'), false);
        assert.equal(h.context.$('#mainwp-restore-confirm-error').state.text, 'The restore did not start. Try again.');
        h.consent(); h.start();
        assert.notEqual(record(h).attempt.token, token);
    }],
    ['another admin job is never adopted from status extras', h => {
        h.consent(); h.start(); h.requests[0].fail();
        last(h, 'mainwp_restore_admission_status').success({state: 'pending', active_restore_job_id: 456, job_id: 456});
        assert.equal(record(h).jobId, 0);
        assert.equal(sent(h, 'mainwp_restore_progress').length, 0);
        assertBlocked(h);
    }],
    ['needs_confirmation clears the attempt without reconciliation', h => {
        h.consent(); h.start(); h.requests[0].success({needs_confirmation: true});
        assert.equal(record(h).attempt, null);
        assert.equal(h.context.$('#mainwp-restore-consent').prop('checked'), false);
        assert.equal(sent(h, 'mainwp_restore_admission_status').length, 0);
    }],
    ['definite test restore refusal refreshes the full snapshot list', h => {
        loadSnapshotRequests(h);
        h.consent(); h.start();
        last(h, 'mainwp_restore_initiate').success({error: 'Test restore running', error_code: 'mainwp_backup_already_running', test_restore_running: true});
        assert.equal(sent(h, 'mainwp_restore_snapshot_list').length, 1);
        assert.equal(last(h, 'mainwp_restore_snapshot_list').data.site_id, 7);
        assert.equal(sent(h, 'mainwp_restore_admission_status').length, 0);
        assert.equal(record(h).attempt, null);
    }],
    ['close and reopen during initiation preserves the attempt', h => {
        h.consent(); h.start(); h.close(); h.open();
        assert.equal(h.context.$('#mainwp-restore-status-text').state.text, CHECKING);
        assertBlocked(h);
        h.requests[0].success({job_id: 123});
        assert.equal(last(h, 'mainwp_restore_progress').data.job_id, 123);
    }],
    ['an answer after a site switch is recorded only on its site', h => {
        h.consent(); h.start(); h.close(); h.switchSite(8);
        h.requests[0].success({job_id: 123});
        assert.equal(record(h, 7).jobId, 123);
        assert.equal(record(h, 8).jobId, 0);
        assert.equal(sent(h, 'mainwp_restore_progress').length, 0);
        h.switchSite(7); h.open();
        assert.equal(last(h, 'mainwp_restore_progress').data.job_id, 123);
    }],
    ['closed reconciliation records admission without repainting another site', h => {
        h.consent(); h.start(); h.requests[0].fail(); h.close(); h.switchSite(8);
        last(h, 'mainwp_restore_admission_status').success({state: 'admitted', job_id: 123});
        assert.equal(record(h, 7).jobId, 123);
        assert.equal(record(h, 8).jobId, 0);
    }],
    ['rollback timeout reconciles with rollback wording', h => {
        loadRollback(h); h.context.startRollback(123);
        const request = last(h, 'mainwp_restore_rollback');
        assert.equal(request.options.timeout, 90000);
        assert.equal(request.data.admission_token, record(h).attempt.token);
        request.fail({}, 'timeout');
        assert.equal(h.context.$('#mainwp-restore-status-text').state.text, 'Checking whether the rollback started');
        assertBlocked(h);
        last(h, 'mainwp_restore_admission_status').success({state: 'admitted', job_id: 456});
        assert.equal(last(h, 'mainwp_restore_progress').data.job_id, 456);
    }],
    ['rollback missing reply and not_admitted permit a fresh rollback from the progress pane', h => {
        loadRollback(h); h.consent(); h.context.startRollback(123);
        const token = record(h).attempt.token;
        let reloads = 0;
        h.context.loadSnapshots = () => { reloads++; };
        last(h, 'mainwp_restore_rollback').success({});
        last(h, 'mainwp_restore_admission_status').success({state: 'not_admitted'});
        assert.equal(record(h).attempt, null);
        assert.equal(h.context.currentJob, 123);
        assert.equal(h.context.$('#mainwp-restore-progress-pane').state.visible, true);
        assert.equal(h.context.$('#mainwp-restore-confirm-pane').state.wasShown, undefined);
        assert.equal(h.context.$('#mainwp-restore-start-btn').state.visible, false);
        assert.equal(h.context.$('#mainwp-restore-spinner').state.visible, false);
        assert.equal(h.context.$('#mainwp-restore-result-message').state.text, 'The rollback did not start. Try again.');
        assert.equal(h.context.$('#mainwp-restore-result-message').hasClass('red'), true);
        assert.equal(h.context.$('#mainwp-restore-result-message').state.visible, true);
        assert.equal(h.context.$('#mainwp-restore-rollback-btn').state.visible, true);
        assert.equal(h.context.$('#mainwp-restore-rollback-btn').prop('disabled'), false);
        assert.equal(reloads, 1);
        h.rollback();
        assert.equal(sent(h, 'mainwp_restore_rollback').length, 2);
        assert.equal(last(h, 'mainwp_restore_rollback').data.job_id, 123);
        assert.notEqual(record(h).attempt.token, token);
        assert.equal(h.context.$('#mainwp-restore-confirm-pane').state.wasShown, undefined);
    }],
    ['unknown rollback stays on the progress pane and blocks all new attempts', h => {
        loadRollback(h); h.context.startRollback(123);
        last(h, 'mainwp_restore_rollback').fail({}, 'timeout');
        const attempt = record(h).attempt;
        for (let i = 0; i < 8; i++) {
            last(h, 'mainwp_restore_admission_status').fail();
            if (i < 7) h.timers.shift().fn();
        }
        assert.equal(record(h).attempt, attempt);
        assert.equal(h.context.$('#mainwp-restore-progress-pane').state.visible, true);
        assert.equal(h.context.$('#mainwp-restore-confirm-pane').state.wasShown, undefined);
        assert.equal(h.context.$('#mainwp-restore-spinner').state.visible, false);
        assert.equal(h.context.$('#mainwp-restore-result-message').state.text, 'Reload the page to see whether the rollback started.');
        assert.equal(h.context.$('#mainwp-restore-result-message').hasClass('red'), true);
        assert.equal(h.context.$('#mainwp-restore-result-message').state.visible, true);
        assert.equal(h.context.$('#mainwp-restore-start-btn').state.visible, false);
        assert.equal(h.context.$('#mainwp-restore-rollback-btn').state.visible, false);
        assert.equal(h.timers.length, 0);
        assertBlocked(h);
        h.context.startRollback(123);
        assert.equal(sent(h, 'mainwp_restore_rollback').length, 1);
        assert.equal(h.context.$('#mainwp-restore-confirm-pane').state.wasShown, undefined);
    }],
    ['two sites rollbacks do not block each other', h => {
        loadRollback(h); h.context.startRollback(123);
        const first = record(h, 7).attempt;
        h.switchSite(8); h.context.startRollback(234);
        assert.equal(sent(h, 'mainwp_restore_rollback').length, 2);
        assert.notEqual(first.token, record(h, 8).attempt.token);
        sent(h, 'mainwp_restore_rollback')[0].fail();
        assert.equal(last(h, 'mainwp_restore_admission_status').data.site_id, 7);
        last(h, 'mainwp_restore_admission_status').success({state: 'admitted', job_id: 345});
        assert.equal(record(h, 7).jobId, 345);
        assert.equal(record(h, 8).attempt.jobId, 234);
    }]
];
for (const [name, run] of matrix) test('restore attempt matrix: ' + name, () => run(harness()));
for (const code of ['mainwp_backup_unclassified_error', 'mainwp_backup_already_running', 'mainwp_restore_admission_busy', 'mainwp_restore_admission_used']) {
    for (const rollback of [false, true]) {
        test('unresolved ' + code + ' reconciles token, rollback=' + rollback, () => {
            const h = harness();
            if (rollback) { loadRollback(h); h.context.startRollback(123); } else { h.consent(); h.start(); }
            h.requests[0].success({error: 'Server message', error_code: code});
            assert.equal(h.requests[1].data.action, 'mainwp_restore_admission_status');
            assertBlocked(h);
            h.requests[1].success({state: 'not_admitted'});
            const selector = rollback ? '#mainwp-restore-result-message' : '#mainwp-restore-confirm-error';
            assert.equal(h.context.$(selector).state.text, 'Server message');
            if (rollback) assert.equal(h.context.$('#mainwp-restore-confirm-pane').state.wasShown, undefined);
        });
    }
}

test('upload progress shows KB under 1 MB and MB above', () => {
    const h = harness();
    assert.equal(h.context.uploadProgressText({upload_progress: {bytes_done: 300 * 1024, bytes_total: 400 * 1024}}), '300 KB of 400 KB');
    assert.equal(h.context.uploadProgressText({upload_progress: {bytes_done: 96 * 1048576, bytes_total: 264 * 1048576}}), '96 MB of 264 MB');
});

test('snapshot poll waits for the earliest upload retry while every active upload is retrying', () => {
    const offset = 3600;
    const serverNow = () => Date.now() / 1000 + offset;
    const retrying = next => ({status: 'failed_retryable', upload_progress: {next_retry_at: next}});
    for (const [label, snapshots, expected] of [
        ['earliest retry of two snapshots', [{uploads: [retrying(serverNow() + 600)]}, {uploads: [retrying(serverNow() + 120), {status: 'complete'}]}], 120000],
        ['four-hour backoff is capped at five minutes', [{uploads: [retrying(serverNow() + 4 * 3600)]}], 300000],
        ['an overdue retry polls at the five second floor', [{uploads: [retrying(serverNow() - 30)]}], 5000],
        ['an uploading job keeps five seconds', [{uploads: [retrying(serverNow() + 600), {status: 'uploading'}]}], 5000],
        ['a pending job on another snapshot keeps five seconds', [{uploads: [retrying(serverNow() + 600)]}, {uploads: [{status: 'pending'}]}], 5000],
        ['a running test restore keeps five seconds', [{test_restore_running: true, uploads: [retrying(serverNow() + 600)]}], 5000],
        ['a retry without a time keeps five seconds', [{uploads: [retrying(serverNow() + 600), {status: 'failed_retryable'}]}], 5000]
    ]) {
        const h = snapshotPollHarness();
        h.context.serverClockOffset = offset;
        h.context.scheduleSnapshotPoll(snapshots, 7, 1);
        const delay = [...h.timers.values()][0].delay;
        assert.ok(Math.abs(delay - expected) <= 1000, label + ': ' + delay);
    }
});
test('snapshot poll keeps the failure backoff when it is longer than the retry wait', () => {
    const h = snapshotPollHarness();
    h.context.snapshotPollFailures = 4;
    h.context.scheduleSnapshotPoll([{uploads: [{status: 'failed_retryable', upload_progress: {next_retry_at: Date.now() / 1000 + 10}}]}], 7, 1);
    assert.equal([...h.timers.values()][0].delay, 60000);
});
