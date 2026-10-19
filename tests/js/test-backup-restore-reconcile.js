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
        statusLabels: {}, rollbackStatusLabels: {}, rollbackActionLabels: { retry: 'Retry', rollBack: 'Roll Back' }, restoreOutcomeMessages: {},
        phaseOrder: [], rollbackRequestPending: false, currentSiteUrl: '', pollTimer: 0, canRestore: true,
        setSnapshotDeleteBusy() {}, showPreserveNote() {}, window: { clearTimeout() {} }
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
            show() { state.visible = true; return this; },
            hide() { state.visible = false; return this; },
            toggle( value ) { state.visible = value; return this; },
            attr() { return this; },
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
    for ( const name of [ 'showReconcileNote', 'restoreOutcomeSentence', 'showRestoreOutcomeNote', 'showProgress' ] ) {
        const start = source.indexOf( 'function ' + name + '(' );
        assert.ok( start >= 0 );
        const code = source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length );
        vm.runInContext( phpToJs( code ), context );
    }
    const clickStart = source.indexOf( "$( '#mainwp-restore-cancel-btn' ).on( 'click'" );
    vm.runInContext( phpToJs( source.slice( clickStart, source.indexOf( '\n            } );', clickStart ) + '\n            } );'.length ) ), context );
    const note = () => context.$( '#mainwp-restore-reconcile-note' ).state;
    const text = node => [ node.text, ...node.children.map( text ) ].join( ' ' );
    return { context, note, text, created, cancel: () => handlers.get( '#mainwp-restore-cancel-btn:click' )() };
}

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
        h.context.$.post = ( url, data, callback ) => {
            assert.equal( data.action, 'mainwp_restore_cancel' );
            assert.equal( data.job_id, 42 );
            callback( response );
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
                    Object.assign( h.context, { canRestore, rollbackRequestPending } );
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
