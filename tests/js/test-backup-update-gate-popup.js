'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const managesites = fs.readFileSync( require.resolve( '../../assets/js/mainwp-managesites-update.js' ), 'utf8' );
const plugins = fs.readFileSync( require.resolve( '../../assets/js/mainwp-plugins-themes.js' ), 'utf8' );
const backups = fs.readFileSync( require.resolve( '../../assets/js/mainwp-backups.js' ), 'utf8' );
const missingBody = 'A full backup has not been taken in the last days for the following sites:';
const offsiteBody = 'No backup from the last days has a verified offsite copy for the following sites:';
const offsiteHint = "Backup all cannot add an offsite copy. Check each site's storage destination on the Snapshots & Restore page.";

function sliceFunction( source, marker ) {
    const start = source.indexOf( marker );
    const end = source.indexOf( '\n};', start );
    assert.ok( start >= 0, marker );
    return source.slice( start, end < 0 ? source.length : end + 3 );
}

function harness() {
    const elements = new Map();
    const popups = new Map();
    let ajax;
    let continued = 0;
    let reloaded = 0;
    const context = {
        ajaxurl: '/admin-ajax.php',
        MainWP: { I18n: { t: text => text } },
        mainwpParams: { backup_before_upgrade: true },
        mainwpVars: {},
        mainwp_secure_data: data => data,
        mainwp_get_primaryBackup_link: method => '/backup/' + method,
        mainwp_forceReload: () => { reloaded++; },
        managesitesContinueAfterBackup: () => { continued++; },
        updatesoverviewContinueAfterBackup: () => { continued++; },
        window: { location: { href: '/updates' } },
        location: { href: '/updates' },
        setTimeout: () => 1,
        clearTimeout: () => {},
        jQuery: selector => {
            if ( ! elements.has( selector ) ) {
                const el = {
                    length: 0,
                    visible: false,
                    show() { this.visible = true; return this; },
                    hide() { this.visible = false; return this; },
                    attr( key, value ) { this[ key ] = value; return this; },
                    prop( key ) { return this[ key ]; },
                    val( value ) { this.value = value; return this; }
                };
                if ( selector.endsWith( '-backup-ignore' ) ) {
                    el.value = el.defaultValue = 'Ignore';
                }
                elements.set( selector, el );
            }
            return elements.get( selector );
        },
        mainwpPopup: selector => {
            if ( ! popups.has( selector ) ) {
                const popup = {
                    html: '',
                    title: '',
                    close() {},
                    getContentEl() { return { html: value => { this.html = value; } }; },
                    init( options ) { this.title = options.title; this.options = options; }
                };
                popups.set( selector, popup );
            }
            return popups.get( selector );
        }
    };
    context.jQuery.ajax = options => { ajax = options; };
    vm.createContext( context );
    vm.runInContext( sliceFunction( managesites, 'globalThis.mainwp_managesites_prepare_backup_popup = ' ), context );
    vm.runInContext( sliceFunction( managesites, 'let mainwp_managesites_checkBackups = ' ) + '\nglobalThis.manage = mainwp_managesites_checkBackups;', context );
    vm.runInContext( sliceFunction( plugins, 'let mainwp_manages_checkBackups = ' ) + '\nglobalThis.plugins = mainwp_manages_checkBackups;', context );
    vm.runInContext( sliceFunction( backups, 'mainwp_updatesoverview_checkBackups = ' ), context );
    return {
        context, elements, popups,
        continued: () => continued,
        reloaded: () => reloaded,
        run( entry, result ) {
            const names = { 1: 'Missing%20site', 2: 'Offsite%20site', 3: 'Passing%20site' };
            const fn = entry === 'updatesoverview' ? context.mainwp_updatesoverview_checkBackups : context[ entry ];
            const returned = fn( [ 1, 2, 3 ], names, () => { continued++; } );
            assert.equal( ajax.data.action, 'mainwp_checkbackups' );
            ajax.success( { result } );
            return returned;
        }
    };
}

for ( const entry of [ 'manage', 'plugins', 'updatesoverview' ] ) {
    const prefix = entry === 'updatesoverview' ? entry : 'managesites';
    const popupFor = h => h.popups.get( '#' + prefix + '-backup-box' );
    const backupAll = h => h.elements.get( '#' + prefix + '-backup-all' )?.visible === true;

    test( entry + ': missing backups keep the existing box and Backup all', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false, 3: true }, gate_verdicts: { 1: 'missing' }, require_offsite: false } );
        const popup = popupFor( h );
        assert.equal( popup.title, 'Full backup required!' );
        assert.ok( popup.html.includes( missingBody ) );
        assert.ok( popup.html.includes( 'class="' + prefix + '-backup-site" siteid="1"' ) );
        assert.ok( ! popup.html.includes( 'Passing site' ) );
        assert.ok( ! popup.html.includes( offsiteBody ) );
        assert.equal( backupAll( h ), true );
        assert.equal( h.continued(), 0 );
    } );

    test( entry + ': pending offsite shows its reason without Backup all', () => {
        const h = harness();
        h.run( entry, { sites: { 2: false }, gate_verdicts: { 2: 'pending_offsite' }, require_offsite: true } );
        const popup = popupFor( h );
        assert.equal( popup.title, 'Offsite backup required!' );
        assert.ok( popup.html.includes( offsiteBody ) );
        assert.ok( popup.html.includes( offsiteHint ) );
        assert.ok( popup.html.includes( 'Offsite site' ) );
        assert.ok( ! popup.html.includes( prefix + '-backup-site' ) );
        assert.ok( ! popup.html.includes( missingBody ) );
        assert.equal( backupAll( h ), false );
        assert.equal( h.elements.get( '#' + prefix + '-backup-ignore' ).visible, true );
    } );

    test( entry + ': mixed reasons offer Backup all only for missing backups', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false, 2: false }, gate_verdicts: { 1: 'missing', 2: 'pending_offsite' }, require_offsite: false } );
        const popup = popupFor( h );
        assert.equal( popup.title, 'Offsite backup required!' );
        assert.ok( popup.html.includes( missingBody ) && popup.html.includes( offsiteBody ) );
        assert.ok( popup.html.includes( offsiteHint ) );
        assert.ok( popup.html.includes( 'class="' + prefix + '-backup-site" siteid="1"' ) );
        assert.ok( ! popup.html.includes( 'class="' + prefix + '-backup-site" siteid="2"' ) );
        assert.equal( backupAll( h ), true );
    } );

    test( entry + ': with the offsite policy on, a site with no recent backup still gets Backup all', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false, 2: false }, gate_verdicts: { 1: 'missing', 2: 'pending_offsite' }, require_offsite: true } );
        const popup = popupFor( h );
        assert.equal( popup.title, 'Offsite backup required!' );
        assert.ok( popup.html.includes( missingBody ) && popup.html.includes( offsiteBody ) );
        assert.ok( popup.html.includes( '<span class="' + prefix + '-backup-site" siteid="1">' ) );
        assert.ok( ! popup.html.includes( 'siteid="2"' ) );
        assert.equal( backupAll( h ), true );
    } );

    test( entry + ': a pending-offsite box resets a prior Backup all', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false } } );
        assert.equal( backupAll( h ), true );
        h.run( entry, { sites: { 2: false }, gate_verdicts: { 2: 'pending_offsite' }, require_offsite: true } );
        assert.equal( popupFor( h ).title, 'Offsite backup required!' );
        assert.equal( backupAll( h ), false );
    } );

    test( entry + ': old bool-only responses retain the missing-backup behavior', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false } } );
        assert.equal( popupFor( h ).title, 'Full backup required!' );
        assert.equal( backupAll( h ), true );
        assert.ok( popupFor( h ).html.includes( prefix + '-backup-site' ) );
    } );

    test( entry + ': primary backup keeps its link and Proceed with Updates', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false }, primary_backup: 'example' } );
        assert.equal( popupFor( h ).title, 'Full backup required!' );
        assert.ok( popupFor( h ).html.includes( missingBody ) );
        assert.ok( ! popupFor( h ).html.includes( prefix + '-backup-site' ) );
        assert.equal( backupAll( h ), false );
        const link = h.elements.get( '#' + prefix + '-backup-now' );
        assert.equal( link.visible, true );
        assert.equal( link.href, '/backup/example' );
        assert.equal( h.elements.get( '#' + prefix + '-backup-ignore' ).value, 'Proceed with Updates' );
    } );

    test( entry + ': a native offsite hold keeps its reason when another site uses a backup plugin', () => {
        const h = harness();
        h.run( entry, { sites: { 1: true, 2: false }, primary_backup: 'example', gate_verdicts: { 2: 'pending_offsite' }, require_offsite: true } );
        const popup = popupFor( h );
        assert.equal( popup.title, 'Offsite backup required!' );
        assert.ok( popup.html.includes( offsiteBody ) && popup.html.includes( offsiteHint ) );
        assert.ok( ! popup.html.includes( missingBody ) );
        assert.equal( backupAll( h ), false );
        assert.equal( h.elements.get( '#' + prefix + '-backup-now' )?.visible === true, false );
        assert.equal( h.elements.get( '#' + prefix + '-backup-ignore' ).value, 'Ignore' );
    } );

    test( entry + ': mixed providers offer Backup Now for the plugin site and Backup all for the native one', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false, 2: false, 3: false }, primary_backup: 'example', gate_verdicts: { 2: 'pending_offsite', 3: 'missing' }, require_offsite: true } );
        const popup = popupFor( h );
        assert.equal( popup.title, 'Offsite backup required!' );
        assert.ok( popup.html.includes( missingBody ) && popup.html.includes( offsiteBody ) );
        assert.ok( popup.html.includes( 'class="' + prefix + '-backup-site" siteid="3"' ) );
        assert.ok( ! popup.html.includes( 'siteid="1"' ) && ! popup.html.includes( 'siteid="2"' ) );
        assert.ok( popup.html.includes( '<span>Missing site</span>' ) );
        assert.equal( backupAll( h ), true );
        const link = h.elements.get( '#' + prefix + '-backup-now' );
        assert.equal( link.visible, true );
        assert.equal( link.href, '/backup/example' );
        assert.equal( h.elements.get( '#' + prefix + '-backup-ignore' ).value, 'Proceed with Updates' );
    } );

    test( entry + ': a native box after a backup plugin box drops Backup Now and restores Ignore', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false }, primary_backup: 'example' } );
        assert.equal( h.elements.get( '#' + prefix + '-backup-now' ).visible, true );
        h.run( entry, { sites: { 2: false }, gate_verdicts: { 2: 'pending_offsite' }, require_offsite: true } );
        assert.equal( h.elements.get( '#' + prefix + '-backup-now' ).visible, false );
        assert.equal( h.elements.get( '#' + prefix + '-backup-ignore' ).value, 'Ignore' );
        assert.equal( h.elements.get( '#' + prefix + '-backup-ignore' ).visible, true );
    } );

    test( entry + ': passing sites continue updates without a hold', () => {
        const h = harness();
        h.run( entry, { sites: { 3: true }, require_offsite: true } );
        assert.equal( h.continued(), 1 );
        assert.equal( popupFor( h ).title, '' );
    } );
}

test( 'Updates global keeps its parameters and false return contract', () => {
    const h = harness();
    assert.equal( h.context.mainwp_updatesoverview_checkBackups.length, 2 );
    assert.equal( h.run( 'updatesoverview', { sites: { 2: false }, gate_verdicts: { 2: 'pending_offsite' }, require_offsite: true } ), false );
    h.context.mainwpParams.disable_checkBackupBeforeUpgrade = true;
    assert.equal( h.context.mainwp_updatesoverview_checkBackups( [], {} ), false );
    assert.equal( h.continued(), 1 );
} );
