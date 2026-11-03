'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const { document, loadSiteNameHelper } = require( './fixtures/site-name-dom' );
const test = require( 'node:test' );

const mainwp = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );
const managesites = fs.readFileSync( require.resolve( '../../assets/js/mainwp-managesites-update.js' ), 'utf8' );
const plugins = fs.readFileSync( require.resolve( '../../assets/js/mainwp-plugins-themes.js' ), 'utf8' );
const backups = fs.readFileSync( require.resolve( '../../assets/js/mainwp-backups.js' ), 'utf8' );
const missingBody = 'A full backup has not been taken in the last days for the following sites:';
const offsiteBody = 'No backup from the last days has a verified offsite copy for the following sites:';
const offsiteHint = "Backup all cannot add an offsite copy. Check each site's storage destination on the Snapshots & Restore page.";

function sliceFunction( source, marker ) {
    const start = source.indexOf( marker );
    const end = source.indexOf( '\n}', start );
    assert.ok( start >= 0, marker );
    return source.slice( start, end < 0 ? source.length : end + 3 );
}

function harness() {
    const elements = new Map();
    const popups = new Map();
    const handlers = new Map();
    const requests = [];
    let ajax;
    let continued = 0;
    let reloaded = 0;
    const context = {
        document,
        ajaxurl: '/admin-ajax.php',
        MainWP: { I18n: { t: text => text } },
        mainwpParams: { backup_before_upgrade: true },
        mainwpVars: {},
        security_nonces: { mainwp_upgradewp: 'core-nonce', mainwp_upgradeplugintheme: 'item-nonce' },
        ignoredBackupBeforeUpdate: false,
        managesitesBackupError: false,
        updatesoverviewBackupError: false,
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
                    on( event, target, handler ) { handlers.set( target, handler ); return this; },
                    prop( key ) { return this[ key ]; },
                    show() { this.visible = true; return this; },
                    hide() { this.visible = false; return this; },
                    text( value ) { this.textContent = String( value ); return this; },
                    html() { return this.textContent.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); },
                    attr( key, value ) { this[ key ] = value; return this; },
                    data( key, ...value ) {
                        this.stored = this.stored || {};
                        if ( value.length === 0 ) {
                            return this.stored[ key ];
                        }
                        this.stored[ key ] = value[ 0 ];
                        return this;
                    },
                    val( ...value ) {
                        if ( value.length === 0 ) {
                            return this.value;
                        }
                        this.value = value[ 0 ];
                        // An <input type="button"> writes .value through to its
                        // value attribute, so defaultValue follows it.
                        if ( this.reflectsValue ) {
                            this.defaultValue = value[ 0 ];
                        }
                        return this;
                    }
                };
                if ( typeof selector === 'string' && selector.endsWith( '-backup-ignore' ) ) {
                    el.value = el.defaultValue = 'Ignore';
                    el.reflectsValue = true;
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
    context.jQuery.ajax = options => { ajax = options; requests.push( options.data ); };
    vm.createContext( context );
    loadSiteNameHelper( vm, mainwp, context );
    vm.runInContext( sliceFunction( mainwp, 'globalThis.mainwp_secure_data = ' ), context );
    vm.runInContext( sliceFunction( managesites, 'globalThis.mainwp_managesites_prepare_backup_popup = ' ), context );
    vm.runInContext( sliceFunction( managesites, 'let mainwp_managesites_checkBackups = ' ) + '\nglobalThis.manage = mainwp_managesites_checkBackups;', context );
    vm.runInContext( sliceFunction( plugins, 'let mainwp_manages_checkBackups = ' ) + '\nglobalThis.plugins = mainwp_manages_checkBackups;', context );
    vm.runInContext( sliceFunction( backups, 'mainwp_updatesoverview_checkBackups = ' ), context );
    for ( const [ source, prefix ] of [ [ managesites, 'managesites' ], [ backups, 'updatesoverview' ] ] ) {
        for ( const button of [ 'ignore', 'backupnow-close' ] ) {
            const selector = '#' + prefix + '-backup' + ( button === 'ignore' ? '-ignore' : 'now-close' );
            const start = source.indexOf( "jQuery(document).on('click', '" + selector + "'" );
            assert.ok( start >= 0, selector );
            vm.runInContext( source.slice( start, source.indexOf( '\n});', start ) + 4 ), context );
        }
    }
    return {
        context, elements, popups,
        continued: () => continued,
        reloaded: () => reloaded,
        click( selector ) { handlers.get( selector ).call( selector ); },
        request( action ) { return context.mainwp_secure_data( { action } ); },
        respond( result ) { ajax.success( { result } ); },
        requestData: () => ajax.data,
        updatesSent: () => requests.filter( data => data.action === 'mainwp_upgradeplugintheme' ).length,
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

for ( const entry of [ 'manage', 'plugins', 'updatesoverview' ] ) {
    const prefix = entry === 'updatesoverview' ? entry : 'managesites';

    test( entry + ': Ignore resumes the pending update and marks only update requests', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false } } );
        h.click( '#' + prefix + '-backup-ignore' );
        assert.equal( h.continued(), 1 );
        assert.equal( h.request( 'mainwp_upgradewp' ).ignore_backup_gate, 1 );
        assert.equal( h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate, 1 );
        assert.equal( h.request( 'mainwp_checkbackups' ).ignore_backup_gate, undefined );
        assert.equal( h.request( 'mainwp_upgradewp' ).security, 'core-nonce' );
        h.click( '#' + prefix + '-backup-ignore' );
        assert.equal( h.continued(), 1 );
    } );

    test( entry + ': Backup Now completion resumes without an override', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false } } );
        h.click( '#' + prefix + '-backupnow-close' );
        assert.equal( h.continued(), 1 );
        assert.equal( h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate, undefined );
    } );

    test( entry + ': Continue update anyway after a failed backup sends the override', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false } } );
        h.context[ prefix + 'BackupError' ] = true;
        h.click( '#' + prefix + '-backupnow-close' );
        assert.equal( h.continued(), 1 );
        assert.equal( h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate, 1 );
        assert.equal( h.context[ prefix + 'BackupError' ], false, 'A later backup run must not inherit this failure.' );
    } );

    test( entry + ': a passing new check clears an earlier Ignore', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false } } );
        h.click( '#' + prefix + '-backup-ignore' );
        h.context.managesitesContinueAfterBackup = h.context.updatesoverviewContinueAfterBackup = () => {};
        h.run( entry, { sites: { 3: true } } );
        assert.equal( h.request( 'mainwp_upgradewp' ).ignore_backup_gate, undefined );
        assert.equal( h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate, undefined );
    } );

    test( entry + ': disabling the setting clears an earlier Ignore', () => {
        const h = harness();
        h.run( entry, { sites: { 1: false } } );
        h.click( '#' + prefix + '-backup-ignore' );
        h.context.mainwpParams.backup_before_upgrade = false;
        h.context.mainwpParams.disable_checkBackupBeforeUpgrade = true;
        if ( entry === 'plugins' ) {
            h.context.plugins( [], {}, () => {} );
        } else if ( entry === 'manage' ) {
            h.context.manage( [], {} );
        } else {
            h.context.mainwp_updatesoverview_checkBackups( [], {} );
        }
        assert.equal( h.request( 'mainwp_upgradewp' ).ignore_backup_gate, undefined );
        assert.equal( h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate, undefined );
    } );
}

for ( const type of [ 'plugins', 'themes' ] ) {
    for ( const button of [ 'ignore', 'backupnow-close' ] ) {
        test( type + ': the page entry point sends its update after ' + button, () => {
            const h = harness();
            const selector = '.mainwp-manage-' + ( type === 'plugins' ? 'plugin' : 'theme' ) + '-item-website[' + ( type === 'plugins' ? 'plugin' : 'theme' ) + '-slug="example"][site-id="1"]';
            h.context.jQuery( selector ).find = () => ( { html() {} } );
            vm.runInContext( sliceFunction( plugins, 'let manage_' + type + '_upgrade_continueAfterBackup = ' ), h.context );
            vm.runInContext( sliceFunction( plugins, 'let manage_' + type + '_upgrade_int = ' ) + '\nglobalThis.startUpdate = manage_' + type + '_upgrade_int;', h.context );
            h.context.startUpdate( 'example', 1 );
            assert.equal( h.updatesSent(), 0 );
            h.respond( { sites: { 1: false } } );
            assert.equal( h.updatesSent(), 0 );
            h.click( '#managesites-backup' + ( button === 'ignore' ? '-ignore' : 'now-close' ) );
            assert.equal( h.requestData().action, 'mainwp_upgradeplugintheme' );
            assert.equal( h.requestData().type, type === 'plugins' ? 'plugin' : 'theme' );
            assert.equal( h.requestData().websiteId, 1 );
            assert.equal( h.requestData().slug, 'example' );
            assert.equal( h.requestData().ignore_backup_gate, button === 'ignore' ? 1 : undefined );
            assert.equal( h.updatesSent(), 1 );
        } );
    }
}

for ( const continueRun of [ false, true ] ) {
    test( 'Manage Sites: Ignore persists only for subsequent stages of the same run (' + continueRun + ')', () => {
        const h = harness();
        h.context.mainwpVars.ignoreBackupGate = true;
        h.context.ignoredBackupBeforeUpdate = true;
        h.context.ugradingWebsiteAll = false;
        h.context.mainwp_update_get_selected_sitesids = ( selected, all ) => all;
        h.context.dashboard_update_site_status = () => {};
        h.context.managesites_update_pluginsthemes = () => {};
        h.context.jQuery( '.dashboard_wp_id[error-status=0]' ).map = () => [ 1 ];
        vm.runInContext( sliceFunction( managesites, 'globalThis.mainwp_update_pluginsthemes = ' ), h.context );
        h.context.mainwp_update_pluginsthemes( 'plugin', [ 1 ], continueRun );
        assert.equal( h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate, continueRun ? 1 : undefined );
        if ( ! continueRun ) {
            assert.equal( h.requestData().action, 'mainwp_checkbackups' );
        }
    } );
}

test( 'Manage Sites: Continue update anyway after a failed backup carries into the next Update Everything stage', () => {
    const h = harness();
    h.context.ugradingWebsiteAll = false;
    h.context.mainwp_update_get_selected_sitesids = ( selected, all ) => all;
    h.context.dashboard_update_site_status = () => {};
    h.context.managesites_update_pluginsthemes = () => {};
    h.context.jQuery( '.dashboard_wp_id[error-status=0]' ).map = () => [ 1 ];
    vm.runInContext( sliceFunction( managesites, 'globalThis.mainwp_update_pluginsthemes = ' ), h.context );
    h.run( 'manage', { sites: { 1: false } } );
    h.context.managesitesBackupError = true;
    h.click( '#managesites-backupnow-close' );
    const checks = h.context.jQuery.ajax;
    let checked = 0;
    h.context.jQuery.ajax = options => { checked++; checks( options ); };
    h.context.mainwp_update_pluginsthemes( 'plugin', [ 1 ], true );
    assert.equal( checked, 0, 'The next stage must not ask for a backup again.' );
    assert.equal( h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate, 1 );
} );

test( 'Updates page: the per-item theme Update runs the backup check before updating', () => {
    const updates = fs.readFileSync( require.resolve( '../../assets/js/mainwp-updates.js' ), 'utf8' );
    const checks = [];
    const sent = [];
    const context = {
        MainWP: { I18n: { t: text => text } },
        mainwp_confirm: ( msg, callback ) => callback(),
        mainwp_updatesoverview_checkBackups: ( sites, names ) => { checks.push( { sites, names } ); return false; },
        updatesoverview_themes_upgrade_int: ( slug, id ) => { sent.push( [ slug, id ] ); },
        updatesoverviewContinueAfterBackup: undefined,
        jQuery: () => ( { first: () => ( { attr: () => 'Child One' } ) } )
    };
    vm.createContext( context );
    vm.runInContext( sliceFunction( updates, 'let updatesoverview_themes_upgrade = ' ) + '\nglobalThis.upgradeTheme = updatesoverview_themes_upgrade;', context );
    context.upgradeTheme( 'twentytwentythree', 1 );
    assert.equal( checks.length, 1 );
    assert.deepEqual( Array.from( checks[ 0 ].sites ), [ 1 ] );
    assert.equal( checks[ 0 ].names[ 1 ], 'Child One' );
    assert.deepEqual( sent, [], 'The update must wait for the backup check.' );
    context.updatesoverviewContinueAfterBackup();
    assert.deepEqual( sent, [ [ 'twentytwentythree', 1 ] ] );
    assert.equal( context.updatesoverviewContinueAfterBackup, undefined );
} );

for ( const entry of [ 'manage', 'plugins', 'updatesoverview' ] ) {
    const prefix = entry === 'updatesoverview' ? entry : 'managesites';
    const startCheck = ( h, site, resume ) => {
        if ( entry === 'plugins' ) {
            return h.context.plugins( [ site ], {}, resume );
        }
        // The page callers point the shared global at their update before they start the check.
        h.context[ prefix + 'ContinueAfterBackup' ] = resume;
        return entry === 'manage' ? h.context.manage( [ site ], {} ) : h.context.mainwp_updatesoverview_checkBackups( [ site ], {} );
    };
    const pendingChecks = h => {
        const pending = [];
        h.context.jQuery.ajax = options => { pending.push( options ); };
        return pending;
    };

    test( entry + ': a second update started while a backup check is pending is ignored', () => {
        const h = harness();
        const pending = pendingChecks( h );
        const resumed = [];
        startCheck( h, 1, () => { resumed.push( 'A' ); } );
        startCheck( h, 2, () => { resumed.push( 'B' ); } );
        assert.equal( pending.length, 1, 'Only the first backup check may be in flight.' );
        pending[ 0 ].success( { result: { sites: { 1: false } } } );
        assert.equal( h.popups.get( '#' + prefix + '-backup-box' ).title, 'Full backup required!' );
        h.click( '#' + prefix + '-backup-ignore' );
        assert.deepEqual( resumed, [ 'A' ], 'Ignore must resume the update the prompt was shown for.' );
        startCheck( h, 3, () => {} );
        assert.equal( pending.length, 2, 'A settled check must let the next update check again.' );
    } );

    test( entry + ': a passing check resumes the update it was started for after a dropped start', () => {
        const h = harness();
        const pending = pendingChecks( h );
        const resumed = [];
        startCheck( h, 1, () => { resumed.push( 'A' ); } );
        startCheck( h, 2, () => { resumed.push( 'B' ); } );
        pending[ 0 ].success( { result: { sites: { 1: true } } } );
        assert.deepEqual( resumed, [ 'A' ] );
    } );

    test( entry + ': an Ignore for one update never reaches an overlapping one', () => {
        const h = harness();
        const pending = pendingChecks( h );
        const overrides = {};
        const sent = name => () => { overrides[ name ] = h.request( 'mainwp_upgradeplugintheme' ).ignore_backup_gate; };
        startCheck( h, 1, sent( 'A' ) );
        startCheck( h, 2, sent( 'B' ) );
        pending[ 0 ].success( { result: { sites: { 1: false } } } );
        h.click( '#' + prefix + '-backup-ignore' );
        for ( const check of pending.slice( 1 ) ) {
            check.success( { result: { sites: { 2: true } } } );
        }
        assert.deepEqual( overrides, { A: 1 }, 'Only the update the user chose to Ignore may carry the override.' );
    } );

    test( entry + ': a failed backup check lets the next update check again', () => {
        const h = harness();
        const pending = pendingChecks( h );
        startCheck( h, 1, () => {} );
        pending[ 0 ].error();
        startCheck( h, 2, () => {} );
        assert.equal( pending.length, 2 );
    } );
}

const updatesSource = fs.readFileSync( require.resolve( '../../assets/js/mainwp-updates.js' ), 'utf8' );
const startedUiContext = pending => {
    const touched = [];
    const touch = name => () => { touched.push( name ); throw new Error( 'touched ' + name ); };
    const context = {
        mainwpVars: { bulkTaskRunning: false, backupCheckPending: pending },
        MainWP: { I18n: { t: text => text } },
        jQuery: touch( 'jQuery' ),
        mainwpPopup: touch( 'mainwpPopup' ),
        mainwp_confirm: touch( 'mainwp_confirm' ),
        mainwp_updatesoverview_checkBackups: touch( 'check' ),
        mainwp_manages_checkBackups: touch( 'check' )
    };
    vm.createContext( context );
    return { context, touched };
};
// Returns true when the start went past its guard: it called a stub or reached other page code.
const runStart = ( context, call ) => {
    try {
        vm.runInContext( call, context );
        return false;
    } catch ( e ) {
        return true;
    }
};

for ( const name of [
    'updatesoverview_wordpress_global_upgrade_all', 'updatesoverview_translations_global_upgrade_all', 'updatesoverview_translations_upgrade_all',
    'updatesoverview_plugins_global_upgrade_all', 'updatesoverview_plugins_upgrade_all', 'updatesoverview_themes_global_upgrade_all',
    'updatesoverview_themes_upgrade_all', 'updatesoverview_global_upgrade_all', 'updatesoverview_global_prepare_upgrade_all'
] ) {
    test( 'Updates page: ' + name + ' leaves the progress list alone while a backup check is pending', () => {
        const marker = ( updatesSource.includes( 'const ' + name + ' = ' ) ? 'const ' : 'let ' ) + name + ' = ';
        const source = sliceFunction( updatesSource, marker ) + '\nglobalThis.start = ' + name + ';';
        const idle = startedUiContext( false );
        vm.runInContext( source, idle.context );
        assert.equal( runStart( idle.context, 'start( "all", "" )' ), true, 'Without a pending check the start must reach the page.' );
        const held = startedUiContext( true );
        vm.runInContext( source, held.context );
        assert.equal( runStart( held.context, 'start( "all", "" )' ), false );
        assert.deepEqual( held.touched, [] );
    } );
}

test( 'Updates page: a prepared Update All is not stopped at its run step by a pending check', () => {
    // The entry points refuse a start while a check is pending. The run step after
    // "Preparing update data..." only keeps its bulkTaskRunning check, so a prepared
    // run goes on to build its form and hands over to the backup check.
    const source = sliceFunction( updatesSource, 'const updatesoverview_global_run_upgrade_all = ' ) + '\nglobalThis.start = updatesoverview_global_run_upgrade_all;';
    const startWith = vars => {
        const selectors = [];
        const context = {
            mainwpVars: vars,
            jQuery: selector => { selectors.push( selector ); throw new Error( 'form lookup' ); }
        };
        vm.createContext( context );
        vm.runInContext( source, context );
        runStart( context, 'start( "all" )' );
        return selectors;
    };
    assert.deepEqual( startWith( { bulkTaskRunning: false, backupCheckPending: true } ), [ '#wp_upgrades' ], 'The run must start building its form.' );
    assert.deepEqual( startWith( { bulkTaskRunning: true, backupCheckPending: false } ), [], 'A running bulk task still stops it.' );
} );

for ( const type of [ 'plugins', 'themes' ] ) {
    test( type + ': a per-item update dropped by a pending backup check leaves its row alone', () => {
        const source = sliceFunction( plugins, 'let manage_' + type + '_upgrade_int = ' ) + '\nglobalThis.start = manage_' + type + '_upgrade_int;';
        const idle = startedUiContext( false );
        vm.runInContext( source, idle.context );
        runStart( idle.context, 'start( "example", 1 )' );
        assert.deepEqual( idle.touched, [ 'jQuery' ], 'Without a pending check the row gets its spinner.' );
        const held = startedUiContext( true );
        vm.runInContext( source, held.context );
        assert.equal( runStart( held.context, 'start( "example", 1 )' ), false );
        assert.deepEqual( held.touched, [] );
    } );
}

test( 'manage: closing Checking backup settings cancels the check and lets the next update check again', () => {
    const h = harness();
    const pending = [];
    let aborted = 0;
    h.context.jQuery.ajax = options => {
        pending.push( options );
        return { abort() { aborted++; options.error(); } };
    };
    let busy;
    h.context.setTimeout = fn => { busy = fn; return 1; };
    const resumed = [];
    h.context.managesitesContinueAfterBackup = () => { resumed.push( 'A' ); };
    h.context.manage( [ 1 ], {} );
    busy();
    const box = h.popups.get( '#managesites-backup-box' );
    assert.equal( box.title, 'Checking backup settings...' );
    box.options.callback();
    assert.equal( aborted, 1 );
    assert.deepEqual( resumed, [], 'A cancelled check must not resume its update.' );
    h.context.managesitesContinueAfterBackup = () => {};
    h.context.manage( [ 2 ], {} );
    assert.equal( pending.length, 2 );
} );
