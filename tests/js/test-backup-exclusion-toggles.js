'use strict';
const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../assets/js/mainwp-backups.js' ), 'utf8' );

const TOGGLES = {
    '#mainwp-known-backup-locations': 'excludebackup',
    '#mainwp-known-cache-locations': 'excludecache',
    '#mainwp-non-wordpress-folders': 'excludenonwp',
    '#mainwp-zip-archives': 'excludezip',
    '#mainwp_maximumFileDescriptorsAuto': 'maximumFileDescriptorsAuto'
};

// Fomantic toggles flip the checked property and leave the rendered attribute
// alone, so the two disagree as soon as the user changes a toggle.
// `rendered` and `live` map each toggle selector to its own state.
function load( rendered, live ) {
    const sent = [];
    const chain = new Proxy( function () {}, {
        get: ( target, prop ) => {
            if ( prop === 'length' ) {
                return 1;
            }
            if ( prop === 'val' ) {
                return () => 'site';
            }
            if ( prop === 'each' ) {
                return callback => { callback.call( chain, 0 ); return chain; };
            }
            return () => chain;
        },
        apply: () => chain
    } );
    const element = selector => {
        if ( ! ( selector in TOGGLES ) ) {
            return chain;
        }
        return new Proxy( function () {}, {
            get: ( target, prop ) => {
                if ( prop === 'attr' ) {
                    return name => ( name === 'checked' && rendered[ selector ] ? 'checked' : undefined );
                }
                if ( prop === 'is' ) {
                    return query => ( query === ':checked' ? live[ selector ] : false );
                }
                if ( prop === 'prop' ) {
                    return name => ( name === 'checked' ? live[ selector ] : undefined );
                }
                return prop === 'length' ? 1 : () => chain;
            }
        } );
    };
    const jQuery = arg => ( typeof arg === 'string' ? element( arg ) : chain );
    jQuery.post = ( url, data ) => { sent.push( data ); return chain; };
    jQuery.ajax = options => { sent.push( options.data ); return chain; };
    jQuery.map = () => [];
    const context = {
        jQuery,
        ajaxurl: 'admin-ajax.php',
        MainWP: { I18n: { t: text => text } },
        mainwp_secure_data: data => data,
        mainwpPopup: () => chain,
        mainwp_uid: () => 'uid',
        feedback: () => {},
        setVisible: () => {},
        dateToHMS: () => '',
        location: {},
        document: {},
        setTimeout: () => 0,
        clearTimeout: () => {},
        Date
    };
    vm.createContext( context );
    vm.runInContext( source, context );
    return { context, sent };
}

const builders = {
    'per-site archive (mainwp_backup)': ctx => vm.runInContext( 'backup()', ctx ),
    'add task (mainwp_addbackup)': ctx => vm.runInContext( 'mainwp_managebackups_add()', ctx ),
    'update task (mainwp_updatebackup)': ctx => vm.runInContext( 'mainwp_managebackups_update()', ctx )
};

const selectors = Object.keys( TOGGLES );
const states = selectors.map( on => ( { label: `only ${ TOGGLES[ on ] } on`, live: Object.fromEntries( selectors.map( s => [ s, s === on ] ) ) } ) )
    .concat( [ false, true ].map( on => ( { label: `all ${ on ? 'on' : 'off' }`, live: Object.fromEntries( selectors.map( s => [ s, on ] ) ) } ) ) );

for ( const [ name, run ] of Object.entries( builders ) ) {
    for ( const { label, live } of states ) {
        test( `${ name } sends each toggle as the user left it (${ label }, rendered the opposite way)`, () => {
            const rendered = Object.fromEntries( selectors.map( s => [ s, ! live[ s ] ] ) );
            const { context, sent } = load( rendered, live );
            run( context );
            const data = sent.find( p => p && /^mainwp_(backup|addbackup|updatebackup)$/.test( p.action ) );
            assert.ok( data, 'the builder sent its request' );
            for ( const selector of selectors ) {
                assert.equal( data[ TOGGLES[ selector ] ], live[ selector ] ? 1 : 0, `${ TOGGLES[ selector ] } follows ${ selector }` );
            }
        } );
    }
}
