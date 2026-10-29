'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const updateSource = fs.readFileSync( require.resolve( '../../assets/js/mainwp-managesites-update.js' ), 'utf8' );
const mainSource = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );

function slice( source, startText, endText ) {
    const start = source.indexOf( startText );
    const end = source.indexOf( endText, start );
    assert.ok( start >= 0 && end > start, startText );
    return source.slice( start, end );
}

const selectSites = slice( updateSource, 'let mainwp_update_get_selected_sitesids =', '\n\n\n// global variable.' );
const checkAbandoned = slice( mainSource, 'globalThis.mainwp_managesites_bulk_check_abandoned =', '\nlet mainwp_managesites_check_abandoned_all_int =' );
const hideSite = slice( mainSource, '    globalThis.dashboard_update_site_hide =', '\n\n    // -----------------------------' );
const syncSites = slice( mainSource, 'globalThis.mainwp_sync_sites_data =', '\n\nmainwpVars.websitesToUpdate = [];' );

class Collection {
    constructor( values ) {
        this.length = values.length;
        Object.assign( this, values );
    }

    each( callback ) {
        Array.from( this ).forEach( ( value, index ) => callback.call( value, index, value ) );
        return this;
    }

    map( callback ) {
        return new Collection( Array.from( this, ( value, index ) => callback( index, value ) ) );
    }

    [ Symbol.iterator ]() {
        return Array.prototype[ Symbol.iterator ].call( this );
    }
}

function load( connected = [ '1' ] ) {
    const disconnected = [ '2', '3' ];
    const rows = new Map( [ ...connected, ...disconnected ].map( id => [ id, { style: { display: '' } } ] ) );
    const inputs = ids => new Collection( ids.map( id => ( { value: id } ) ) );
    const jQuery = selector => {
        if ( 'object' === typeof selector ) {
            return { val: () => selector.value };
        }
        if ( '.dashboard_wp_id[error-status=0]' === selector ) {
            return inputs( connected );
        }
        if ( '.dashboard_wp_id[error-status=1]' === selector ) {
            return inputs( disconnected );
        }
        if ( '#mainwp-sync-sites-modal' === selector ) {
            return { attr: () => '0' };
        }
        assert.match( selector, /^\.sync-site-status\[siteid="\d+"\]$/ );
        return { attr: () => 'example.test' };
    };
    jQuery.grep = ( values, callback ) => Array.from( values ).filter( callback );
    jQuery.inArray = ( value, values ) => Array.from( values ).indexOf( value );
    const requests = [];
    const progress = [];
    const context = {
        jQuery,
        dashboardSync: { initialized: true, rowMap: rows },
        mainwpVars: {},
        MainWP: { I18n: { t: text => text } },
        dashboard_update_site_status: () => {},
        managesites_reset_bulk_actions_params: () => {},
        mainwpPopup: () => ( { init: data => progress.push( data.progressMax ) } ),
        mainwp_managesites_check_abandoned_all_int: ids => requests.push( Array.from( ids ) ),
        dashboard_update: ids => requests.push( Array.from( ids ) )
    };
    vm.createContext( context );
    vm.runInContext( hideSite + selectSites + '\nglobalThis.selectSites = mainwp_update_get_selected_sitesids;\n' + checkAbandoned + syncSites, context );
    return {
        context, requests, progress,
        connectedIds: new Collection( connected ),
        visible: () => Array.from( rows ).filter( ( [ , row ] ) => 'none' !== row.style.display ).map( ( [ id ] ) => id )
    };
}

const cases = [
    { name: 'selected site hides unrelated disconnected rows', selection: [ '1' ], visible: [ '1' ], requested: [ '1' ] },
    { name: 'no selection keeps disconnected rows', selection: undefined, visible: [ '1', '2', '3' ], requested: [ '1' ] },
    { name: 'all sites keeps disconnected rows', selection: 'all', visible: [ '1', '2', '3' ], requested: [ '1' ] },
    { name: 'selected disconnected site keeps its row without a request', selection: [ '1', '2' ], visible: [ '1', '2' ], requested: [ '1' ] },
    { name: 'empty selection hides every row', selection: [], visible: [], requested: [] },
    { name: 'unselected connected rows still hide', connected: [ '1', '4' ], selection: [ '1' ], visible: [ '1' ], requested: [ '1' ] }
];

for ( const action of [ 'updates', 'abandoned' ] ) {
    for ( const item of cases ) {
        test( action + ': ' + item.name, () => {
            const fixture = load( item.connected );
            if ( 'updates' === action ) {
                const ids = fixture.context.selectSites( item.selection, fixture.connectedIds );
                assert.deepEqual( Array.from( ids ), item.requested );
            } else {
                fixture.context.mainwp_managesites_bulk_check_abandoned( item.selection, 'plugin' );
                assert.deepEqual( fixture.requests, item.requested.length ? [ item.requested ] : [] );
                assert.deepEqual( fixture.progress, item.requested.length ? [ item.requested.length ] : [] );
            }
            assert.deepEqual( fixture.visible(), item.visible );
        } );
    }

    test( action + ': second selection restores previously hidden connected and disconnected rows', () => {
        const fixture = load( [ '1', '4' ] );
        if ( 'updates' === action ) {
            const firstIds = fixture.context.selectSites( [ '1' ], fixture.connectedIds );
            assert.deepEqual( Array.from( firstIds ), [ '1' ] );
            assert.deepEqual( fixture.visible(), [ '1' ] );
            const secondIds = fixture.context.selectSites( [ '4', '2' ], fixture.connectedIds );
            assert.deepEqual( Array.from( secondIds ), [ '4' ] );
        } else {
            fixture.context.mainwp_managesites_bulk_check_abandoned( [ '1' ], 'plugin' );
            assert.deepEqual( fixture.visible(), [ '1' ] );
            fixture.context.mainwp_managesites_bulk_check_abandoned( [ '4', '2' ], 'plugin' );
            assert.deepEqual( fixture.requests, [ [ '1' ], [ '4' ] ] );
            assert.deepEqual( fixture.progress, [ 1, 1 ] );
        }
        assert.deepEqual( fixture.visible(), [ '4', '2' ] );
    } );
}

test( 'sync after a selected update shows every row again', () => {
    const fixture = load( [ '1', '4' ] );
    fixture.context.selectSites( [ '1' ], fixture.connectedIds );
    assert.deepEqual( fixture.visible(), [ '1' ] );
    fixture.context.mainwp_sync_sites_data( undefined, 'checknow' );
    assert.deepEqual( fixture.requests, [ [ '1', '4' ] ] );
    assert.deepEqual( fixture.visible(), [ '1', '4', '2', '3' ] );
} );

test( 'showing every row skips a status element without a row', () => {
    const fixture = load();
    fixture.context.dashboardSync.rowMap.set( '9', null );
    assert.doesNotThrow( () => fixture.context.dashboard_update_site_show_all() );
    fixture.context.dashboardSync.rowMap.delete( '9' );
    assert.deepEqual( fixture.visible(), [ '1', '2', '3' ] );
} );
