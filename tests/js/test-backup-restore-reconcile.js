'use strict';
const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );

function harness() {
    const elements = new Map();
    const created = [];
    const context = {
        statusLabels: {}, rollbackStatusLabels: {}, rollbackActionLabels: { retry: 'Retry', rollBack: 'Roll Back' }, restoreOutcomeMessages: {},
        phaseOrder: [], rollbackRequestPending: false, currentSiteUrl: '', pollTimer: 0,
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
            attr() { return this; }
        };
        if ( ! selector.startsWith( '<' ) ) elements.set( selector, element );
        return element;
    };
    vm.createContext( context );
    for ( const name of [ 'showReconcileNote', 'showProgress' ] ) {
        const start = source.indexOf( 'function ' + name + '(' );
        assert.ok( start >= 0 );
        const code = source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length );
        vm.runInContext( code.replace( /<\?php[\s\S]*?\?>/g, php => {
            const strings = [ ...php.matchAll( /(['"])((?:\\.|(?!\1)[\s\S])*?)\1/g ) ];
            const index = php.includes( '_n(' ) && /, 2,/.test( php ) ? 1 : 0;
            return strings[ index ][2].replace( /\\'/g, "'" ).replace( /'/g, "\\'" );
        } ), context );
    }
    const note = () => context.$( '#mainwp-restore-reconcile-note' ).state;
    const text = node => [ node.text, ...node.children.map( text ) ].join( ' ' );
    return { context, note, text, created };
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

for ( const reason of [ 'unarchived_invalid', 'unarchived_overflow', 'unarchived_unsupported' ] ) {
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
