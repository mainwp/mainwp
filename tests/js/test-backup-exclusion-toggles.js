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
function load( rendered, live ) {
    const payloads = [];
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
                    return name => ( name === 'checked' && rendered ? 'checked' : undefined );
                }
                if ( prop === 'is' ) {
                    return query => ( query === ':checked' ? live : false );
                }
                if ( prop === 'prop' ) {
                    return name => ( name === 'checked' ? live : undefined );
                }
                return prop === 'length' ? 1 : () => chain;
            }
        } );
    };
    const jQuery = arg => ( typeof arg === 'string' ? element( arg ) : chain );
    jQuery.post = () => chain;
    jQuery.ajax = () => chain;
    jQuery.map = () => [];
    const context = {
        jQuery,
        ajaxurl: 'admin-ajax.php',
        MainWP: { I18n: { t: text => text } },
        mainwp_secure_data: data => { payloads.push( data ); return data; },
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
    return { context, payloads };
}

const builders = {
    'per-site archive (mainwp_backup)': ctx => vm.runInContext( 'backup()', ctx ),
    'add task (mainwp_addbackup)': ctx => vm.runInContext( 'mainwp_managebackups_add()', ctx ),
    'update task (mainwp_updatebackup)': ctx => vm.runInContext( 'mainwp_managebackups_update()', ctx )
};

for ( const [ name, run ] of Object.entries( builders ) ) {
    for ( const [ rendered, live ] of [ [ true, false ], [ false, true ], [ true, true ], [ false, false ] ] ) {
        test( `${ name } posts the live toggle state (rendered ${ rendered }, now ${ live })`, () => {
            const { context, payloads } = load( rendered, live );
            run( context );
            const data = payloads.find( p => p.action && /^mainwp_(backup|addbackup|updatebackup)$/.test( p.action ) );
            assert.ok( data, 'the builder posted a payload' );
            for ( const key of Object.values( TOGGLES ) ) {
                assert.equal( data[ key ], live ? 1 : 0, `${ key } follows the toggle the user sees` );
            }
        } );
    }
}
