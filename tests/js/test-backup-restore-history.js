'use strict';
const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );

function phpToJs( code ) {
	return code.replace( /<\?php[\s\S]*?\?>/g, php => {
		const strings = [ ...php.matchAll( /(['"])((?:\\.|(?!\1)[\s\S])*?)\1/g ) ];
		return strings[0][2].replace( /\\'/g, "'" ).replace( /'/g, "\\'" );
	} );
}

function harness( jobs, canRestore = true ) {
	const elements = new Map();
	const tags = new Set( [
		'<td class="collapsing"></td>', '<td></td>', '<tr></tr>', '<div></div>',
		'<span class="ui small label"></span>',
		'<button type="button" class="ui mini basic button mainwp-restore-failed-job"></button>'
	] );
	const context = {
		canRestore, currentSite: 1, currentJob: 0, backupPollTimer: null, backupFollowGen: 0, backupPendingStarts: {}, siteDisconnected: false,
		ajaxurl: '/admin-ajax.php', nonces: { list: 'list-nonce' }, snapshotsPlaceholders: {},
		statusLabels: { failed_permanent: 'Failed', cancelled: 'Cancelled', completed: 'Completed' },
		rollbackStatusLabels: { failed_permanent: 'Rollback failed' },
		rollbackActionLabels: { retry: 'Retry rollback', rollBack: 'Roll Back' },
		historyStatusColors: { completed: 'green', failed_permanent: 'red', cancelled: 'grey' },
		enableSnapshotControls() {}, setSnapshotsPlaceholder() {}, showDisconnected() {},
		startJobFollow() {}, resumeBackupFollow() {}, escAttr: value => String( value ),
		snapshotsTable: { clear() { return this; }, draw() {}, rows: { add() {} } }
	};
	context.$ = selector => {
		if ( typeof selector === 'object' ) return selector;
		if ( elements.has( selector ) ) return elements.get( selector );
		const markup = selector.startsWith( '<' );
		if ( markup ) assert.ok( tags.has( selector ), 'Only constant element tags may be parsed: ' + selector );
		const state = { tag: selector, text: '', children: [], attrs: {}, props: {}, visible: false };
		const element = {
			state,
			text( value ) { assert.equal( typeof value, 'string' ); state.text = value; state.children = []; return this; },
			empty() { state.text = ''; state.children = []; return this; },
			append( ...children ) {
				for ( const child of children ) {
					assert.equal( typeof child, 'object', 'Never append error or report HTML.' );
					assert.ok( child.state );
					state.children.push( child.state );
				}
				return this;
			},
			appendTo( target ) { context.$( target ).append( this ); return this; },
			attr( name, value ) { state.attrs[ name ] = value; return this; },
			prop( name, value ) { state.props[ name ] = value; return this; },
			addClass() { return this; },
			hide() { state.visible = false; return this; },
			toggle( value ) { state.visible = value; return this; },
			find( name ) { return context.$( selector + ' ' + name ); },
			html( value ) {
				assert.ok( [ '#mainwp-native-backup-now-note .mainwp-backup-local-note', '#mainwp-native-backup-now-note .mainwp-backup-destinations-note' ].includes( selector ), 'History must use text nodes.' );
				assert.equal( typeof value, 'string' );
				return this;
			}
		};
		if ( ! markup ) elements.set( selector, element );
		return element;
	};
	context.$.post = ( url, data, callback, type ) => {
		assert.equal( url, context.ajaxurl );
		assert.equal( data.action, 'mainwp_restore_snapshot_list' );
		assert.equal( type, 'json' );
		callback( { failed_restore_jobs: jobs, snapshots: [], active_restore_job_id: 0 } );
		return { fail() {} };
	};
	vm.createContext( context );
	for ( const name of [ 'restoreReportLabels', 'restoreOutcomeMessages' ] ) {
		const start = source.indexOf( 'var ' + name + ' = ' );
		assert.ok( start >= 0 );
		vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            };', start ) + '\n            };'.length ) ), context );
	}
	const safetyStart = source.indexOf( 'var safetyNoteText = ' );
	vm.runInContext( phpToJs( source.slice( safetyStart, source.indexOf( '\n', safetyStart ) ) ), context );
	for ( const name of [ 'restoreOutcomeSentence', 'loadSnapshots' ] ) {
		const start = source.indexOf( 'function ' + name + '(' );
		if ( name === 'restoreOutcomeSentence' && start < 0 ) continue;
		assert.ok( start >= 0 );
		vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) ), context );
	}
	context.loadSnapshots( 1 );
	const rows = context.$( '#mainwp-restore-history-table tbody' ).state.children;
	assert.equal( rows.length, jobs.length );
	for ( const row of rows ) assert.equal( row.children.length, 5 );
	return { context, rows, details: rows.map( row => row.children[3] ), actions: rows.map( row => row.children[4].children ) };
}

function job( overrides = {} ) {
	return { job_id: 12, snapshot_id: 5, status: 'failed_permanent', date_label: 'Today', last_error: 'Restore stopped', error_code: '', has_rollback: false, is_rollback: false, ...overrides };
}

test( 'guard: older Child history keeps its existing details and row', () => {
	const h = harness( [ job() ] );
	assert.equal( h.details[0].text, 'Restore stopped' );
	assert.deepEqual( h.details[0].children, [] );
	assert.equal( h.rows[0].children[0].text, '#12' );
	assert.equal( h.rows[0].children[1].text, 'Today' );
	assert.equal( h.rows[0].children[2].children[0].text, 'Failed' );
	assert.deepEqual( h.actions[0], [] );
} );

test( 'guard: completed history with a report keeps only the safety note', () => {
	const h = harness( [ job( { status: 'completed', last_error: '', has_rollback: true, restore_outcome: { valid: true, files: 'applied', database: 'left_partial' } } ) ] );
	assert.equal( h.details[0].text, h.context.safetyNoteText );
	assert.deepEqual( h.details[0].children, [] );
	assert.equal( h.actions[0][0].text, 'Roll Back' );
} );

for ( const error_code of [ 'mainwp_restore_rolled_back', 'mainwp_restore_rollback_failed' ] ) {
	test( 'guard: history keeps only the mapped message for ' + error_code, () => {
		const h = harness( [ job( { error_code, restore_outcome: { valid: false } } ) ] );
		assert.equal( h.details[0].text, h.context.restoreOutcomeMessages[ error_code ] );
		assert.deepEqual( h.details[0].children, [] );
	} );
}

for ( const [ name, overrides ] of [
	[ 'null report', { restore_outcome: null } ],
	[ 'non-terminal', { status: 'running', restore_outcome: { valid: false } } ],
	[ 'applied halves', { restore_outcome: { valid: true, files: 'applied', database: 'not_applicable' } } ],
	[ 'empty sentence', { restore_outcome: { valid: true, files: 'unknown', database: 'unknown' } } ]
] ) {
	test( 'guard: history adds no div for ' + name, () => {
		const h = harness( [ job( overrides ) ] );
		assert.equal( h.details[0].text, 'Restore stopped' );
		assert.deepEqual( h.details[0].children, [] );
	} );
}

for ( const report of [ { valid: false }, {}, { valid: false, files: 'applied' } ] ) {
	test( 'red: invalid or incomplete history report shows uncertainty: ' + JSON.stringify( report ), () => {
		const h = harness( [ job( { restore_outcome: report } ) ] );
		assert.equal( h.details[0].text, 'Restore stopped' );
		assert.equal( h.details[0].children.length, 1 );
		assert.equal( h.details[0].children[0].tag, '<div></div>' );
		assert.equal( h.details[0].children[0].text, h.context.restoreReportLabels.unknown );
	} );
}

test( 'red: cancelled history with no applied changes shows the unchanged sentence', () => {
	const h = harness( [ job( { status: 'cancelled', restore_outcome: { valid: true, files: 'not_started', database: 'not_started' } } ) ] );
	assert.equal( h.details[0].text, 'Restore stopped' );
	assert.equal( h.details[0].children[0]?.text, h.context.restoreReportLabels.nothingChanged );
} );

test( 'red: failed rollback history explains partial state and offers Retry without Roll Back advice', () => {
	const h = harness( [ job( { is_rollback: true, has_rollback: true, restore_outcome: { valid: true, files: 'applied', database: 'left_partial' } } ) ] );
	assert.equal( h.details[0].text, 'Restore stopped' );
	assert.equal( h.details[0].children[0]?.text, h.context.restoreReportLabels.filesApplied + ' ' + h.context.restoreReportLabels.databasePartial );
	assert.equal( h.actions[0][0].text, 'Retry rollback' );
	assert.equal( h.rows[0].children[2].children[0].text, 'Rollback failed' );
} );

for ( const has_rollback of [ true, false ] ) {
	for ( const canRestore of [ true, false ] ) {
		test( 'red: history rollback advice matches its action, source=' + has_rollback + ', capability=' + canRestore, () => {
			const h = harness( [ job( { has_rollback, restore_outcome: { valid: true, files: 'left_partial', database: 'not_started' } } ) ], canRestore );
			const offered = has_rollback && canRestore;
			assert.equal( h.details[0].text, 'Restore stopped' );
			assert.equal( h.details[0].children[0]?.text, h.context.restoreReportLabels.filesPartial + ' ' + h.context.restoreReportLabels.databaseNotStarted + ( offered ? ' ' + h.context.restoreReportLabels.rollBack : '' ) );
			assert.equal( h.actions[0].length, offered ? 1 : 0 );
			if ( offered ) {
				assert.equal( h.actions[0][0].text, 'Roll Back' );
				assert.equal( h.actions[0][0].attrs['data-job-id'], 12 );
				assert.equal( h.actions[0][0].props.disabled, false );
			}
		} );
	}
}

test( 'guard: untrusted history errors remain text without a report', () => {
	const unsafe = '<img src=x onerror=alert(1)>';
	const h = harness( [ job( { last_error: unsafe } ) ] );
	assert.equal( h.details[0].text, unsafe );
	assert.deepEqual( h.details[0].children, [] );
} );

test( 'red: untrusted history errors and reports use text nodes and known sentences only', () => {
	const unsafe = '<img src=x onerror=alert(1)>';
	const h = harness( [
		job( { last_error: unsafe, restore_outcome: { valid: false, files: unsafe, database: unsafe } } ),
		job( { last_error: unsafe, restore_outcome: { valid: true, files: unsafe, database: 'left_partial' } } )
	] );
	assert.equal( h.details[0].text, unsafe );
	assert.equal( h.details[1].text, unsafe );
	assert.equal( h.details[0].children[0]?.text, h.context.restoreReportLabels.unknown );
	assert.equal( h.details[1].children[0]?.text, h.context.restoreReportLabels.databasePartial );
	for ( const detail of h.details ) {
		assert.equal( detail.children.length, 1 );
		assert.equal( detail.children[0].tag, '<div></div>' );
		assert.deepEqual( detail.children[0].children, [] );
		assert.ok( ! detail.children[0].text.includes( unsafe ) );
	}
} );
