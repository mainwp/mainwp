'use strict';
const assert = require( 'node:assert/strict' );
const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const now = 1800000000;
const cacheKey = 'mainwp_php_security_releases';
const stats = { phpversion_matched: 'Y', db_reporting: 'Y', php_reporting: 'N' };
const catalog = { 8: { supported_versions: [ '8.3' ] }, 7: { supported_versions: [] } };
const history = { '8.3.35': { tags: [ 'security' ] }, '8.3.38': { tags: [ '' ] }, '8.3.36': { tags: [ 'security' ] } };
const fresh = () => ( { [ cacheKey ]: { checked_at: now, branches: {
    '8.2': { latest: '8.2.35', security: '8.2.35' },
    '8.3': { latest: '8.3.38', security: '8.3.36' },
    '8.4': { latest: '8.4.27', security: '8.4.27' },
    '8.5': { latest: '8.5.12', security: '8.5.12' }
} } } );

function run( input ) {
    return JSON.parse( execFileSync( process.env.WP_PHP_BINARY || 'php', [ require.resolve( './fixtures/php-security.inc' ) ], {
        input: JSON.stringify( input ), encoding: 'utf8', timeout: 10000
    } ) );
}
const check = version => ( { method: 'check', version } );
const refresh = { method: 'refresh' };
const replies = () => [ { data: catalog }, { data: history } ];

for ( const [ version, expected ] of [
    [ '7.4.33', 'N' ], [ '8.0.30', 'N' ], [ '8.1.34', 'N' ],
    [ '8.2.34', 'N' ], [ '8.2.35', 'Y' ], [ '8.3.35', 'N' ], [ '8.3.36', 'Y' ], [ '8.3.37', 'Y' ], [ '8.3.38', 'Y' ],
    [ '8.4.26', 'N' ], [ '8.4.27', 'Y' ], [ '8.5.11', 'N' ], [ '8.5.12', 'Y' ],
    [ '8.3.35-1+deb12u1', 'N' ], [ '8.3.36-1+deb12u1', 'Y' ], [ '8.3.36-arch1', 'Y' ],
    [ '8.3.39', 'N' ], [ '8.6.0', 'N' ], [ '8.6.0RC1', 'N' ], [ '8.3.36-rc1', 'N' ], [ '8.3.36-dev', 'N' ],
    [ '08.3.36', 'N' ], [ '8.3', 'N' ], [ '', 'N' ], [ '<img src=x onerror=alert(1)>', 'N' ], [ [], 'N' ], [ null, 'N' ]
] ) {
    test( `PHP ${JSON.stringify( version )} produces ${expected === 'Y' ? 'Good' : 'Bad'}`, () => {
        const result = run( { options: fresh(), actions: [ check( version ) ] } );
        assert.equal( result.outputs[ 0 ].status, expected );
        assert.deepEqual( Object.keys( result.outputs[ 0 ] ), [ 'status', 'message' ] );
        assert.deepEqual( result.requests, [], 'Comparisons must only read cached data' );
    } );
}

test( 'row messages distinguish an update, an unsupported branch and a passing version', () => {
    const result = run( { options: fresh(), actions: [ check( '8.3.35' ), check( '8.1.34' ), check( '8.3.36' ) ] } );
    assert.match( result.outputs[ 0 ].message, /8\.3\.35.*8\.3\.36.*backported/ );
    assert.match( result.outputs[ 1 ].message, /not listed as a supported/ );
    assert.match( result.outputs[ 2 ].message, /8\.3\.36 meets/ );
} );

for ( const cache of [ null, {}, { checked_at: now + 1, branches: fresh()[ cacheKey ].branches },
    { checked_at: now - 86400, branches: fresh()[ cacheKey ].branches },
    { checked_at: now, branches: { '8.3': { latest: [], security: '8.3.36' } } },
    { checked_at: now, branches: { '8.3': { latest: '8.4.27', security: '8.4.27' } } },
    { checked_at: now, branches: { '8.3': { latest: '8.3.35', security: '8.3.36' } } }
] ) {
    test( `missing, expired or malformed cache gives Bad with an unavailable explanation: ${JSON.stringify( cache )}`, () => {
        const result = run( { options: { [ cacheKey ]: cache }, actions: [ check( '8.3.36' ) ] } );
        assert.equal( result.outputs[ 0 ].status, 'N' );
        assert.match( result.outputs[ 0 ].message, /information is currently unavailable/ );
        assert.deepEqual( result.requests, [] );
    } );
}

test( 'general background job refreshes one shared cache and selects the latest security-tagged release', () => {
    const result = run( { actions: [ { method: 'general_cron' }, check( '8.3.35' ), check( '8.3.36' ), refresh ], responses: replies() } );
    assert.equal( result.outputs[ 0 ].status, 'N' );
    assert.equal( result.outputs[ 1 ].status, 'Y' );
    assert.deepEqual( result.options[ cacheKey ].branches[ '8.3' ], { latest: '8.3.38', security: '8.3.36' } );
    assert.equal( result.requests.length, 2, 'Repeated background runs reuse the cache' );
    assert.equal( result.autoload[ cacheKey ], false );
    for ( const request of result.requests ) {
        const url = new URL( request.url );
        assert.equal( url.origin, 'https://www.php.net' );
        assert.equal( url.pathname, '/releases/index.php' );
        assert.deepEqual( [ ...url.searchParams.keys() ].sort(), url.searchParams.has( 'version' ) ? [ 'json', 'max', 'version' ] : [ 'json' ] );
        assert.equal( request.args[ 'user-agent' ], 'MainWP PHP release check' );
        assert.equal( request.args.timeout, 3 );
        assert.equal( request.args.redirection, 0 );
        assert.equal( request.args.limit_response_size, 1048576 );
        assert.deepEqual( request.args.headers, { Accept: 'application/json' } );
        assert.deepEqual( request.args.cookies, [] );
        assert.equal( request.args.sslverify, true );
    }
} );

test( 'background data refreshes after six hours', () => {
    const result = run( { options: fresh(), actions: [ refresh, { method: 'tick', seconds: 21600 }, refresh ], responses: replies() } );
    assert.equal( result.requests.length, 2 );
    assert.equal( result.options[ cacheKey ].checked_at, now + 21600 );
} );

test( 'a branch loses Good status when PHP.net removes it from supported branches', () => {
    const options = fresh();
    options[ cacheKey ].checked_at -= 21600;
    const responses = [ { data: { 8: { supported_versions: [ '8.3', '8.4', '8.5' ] } } }, { data: history },
        { data: { '8.4.27': { tags: [ 'security' ] } } }, { data: { '8.5.12': { tags: [ 'security' ] } } } ];
    const result = run( { options, actions: [ check( '8.2.35' ), refresh, check( '8.2.35' ) ], responses } );
    assert.equal( result.outputs[ 0 ].status, 'Y' );
    assert.equal( result.outputs[ 1 ].status, 'N' );
    assert.match( result.outputs[ 1 ].message, /not listed as a supported/ );
} );

test( 'an incomplete multi-branch refresh preserves the last complete cache', () => {
    const options = fresh();
    options[ cacheKey ].checked_at -= 21600;
    const responses = [ { data: { 8: { supported_versions: [ '8.3', '8.4' ] } } }, { data: history }, { error: true } ];
    const result = run( { options, actions: [ refresh ], responses } );
    assert.deepEqual( result.options[ cacheKey ], options[ cacheKey ] );
} );

for ( const response of [ { error: true }, { code: 500, data: catalog }, { body: '<html>unavailable</html>' }, { data: {} }, { data: { 8: {} } } ] ) {
    test( `failed fetch does not pass or retry repeatedly: ${JSON.stringify( response )}`, () => {
        const result = run( { actions: [ refresh, refresh, check( '8.3.36' ) ], responses: [ response ] } );
        assert.equal( result.requests.length, 1 );
        assert.equal( result.outputs[ 0 ].status, 'N' );
        assert.equal( result.options[ cacheKey ], undefined );
    } );
}

test( 'failed lookup recovers after fifteen minutes', () => {
    const result = run( { actions: [ refresh, { method: 'tick', seconds: 900 }, refresh, check( '8.3.36' ) ], responses: [ { error: true }, ...replies() ] } );
    assert.equal( result.outputs[ 0 ].status, 'Y' );
    assert.equal( result.requests.length, 3 );
} );

test( 'temporary outage preserves usable cached data but cannot extend it beyond one day', () => {
    const options = fresh();
    options[ cacheKey ].checked_at -= 21600;
    const result = run( { options, actions: [ refresh, check( '8.3.36' ), { method: 'tick', seconds: 64800 }, check( '8.3.36' ) ] } );
    assert.equal( result.outputs[ 0 ].status, 'Y' );
    assert.equal( result.outputs[ 1 ].status, 'N' );
    assert.equal( result.options[ cacheKey ].checked_at, now - 21600 );
} );

for ( const invalidHistory of [ {}, { '8.4.27': { tags: [ 'security' ] } }, { '8.3.36': {} }, { '8.3.36': { tags: [ [] ] } } ] ) {
    test( `invalid release history cannot replace the cache: ${JSON.stringify( invalidHistory )}`, () => {
        const result = run( { actions: [ refresh ], responses: [ { data: catalog }, { data: invalidHistory } ] } );
        assert.equal( result.options[ cacheKey ], undefined );
    } );
}

test( 'a newly supported branch without security tags uses its latest stable release', () => {
    const result = run( { actions: [ refresh, check( '8.6.0' ) ], responses: [ { data: { 8: { supported_versions: [ '8.6' ] } } }, { data: { '8.6.0': { tags: [] } } } ] } );
    assert.equal( result.outputs[ 0 ].status, 'Y' );
} );

test( 'a rollback cannot lower a known security baseline', () => {
    const options = fresh();
    options[ cacheKey ].checked_at -= 21600;
    const result = run( { options, actions: [ refresh ], responses: [ { data: catalog }, { data: { '8.3.35': { tags: [ 'security' ] } } } ] } );
    assert.equal( result.options[ cacheKey ].branches[ '8.3' ].security, '8.3.36' );
    assert.equal( result.options[ cacheKey ].checked_at, now - 21600 );
} );

test( 'a successful background refresh persists changed site results before the next scheduled process', () => {
    const options = fresh();
    options[ cacheKey ].checked_at -= 21600;
    options[ cacheKey ].branches[ '8.3' ].security = '8.3.35';
    const result = run( {
        options, responses: replies(), actions: [ { method: 'general_cron' } ],
        site_options: {
            1: { phpversion: '8.3.35', security_stats: JSON.stringify( stats ) },
            2: { phpversion: '8.3.36', security_stats: JSON.stringify( { ...stats, phpversion_matched: 'N' } ) },
            3: { phpversion: '8.3.36', security_stats: JSON.stringify( stats ) }
        },
        site_values: { 1: { securityIssues: '1' }, 2: { securityIssues: '2' }, 3: { securityIssues: '1' } }
    } );
    assert.deepEqual( JSON.parse( result.site_options[ 1 ].security_stats ), { ...stats, phpversion_matched: 'N' } );
    assert.deepEqual( JSON.parse( result.site_options[ 2 ].security_stats ), stats );
    assert.equal( result.site_values[ 1 ].securityIssues, 2 );
    assert.equal( result.site_values[ 2 ].securityIssues, 1 );
    assert.deepEqual( result.sequence_site_values, result.site_values, 'Persist before the cron dispatcher moves on' );
    assert.deepEqual( result.site_writes.map( write => write[ 0 ] ), [ 1, 1, 2, 2 ], 'Unchanged sites need no writes' );
    assert.deepEqual( result.site_queries, [ {
        for_manager: true, view: 'custom_view', others_fields: [ 'phpversion', 'security_stats' ]
    } ], 'Use the shared background query across all sites' );
    assert.equal( result.child_request, null );
    assert.equal( result.requests.length, 2, 'Only the shared PHP.net requests run' );
} );

test( 'removing a supported branch immediately updates saved site status and recommendation count', () => {
    const options = fresh();
    options[ cacheKey ].checked_at -= 21600;
    const result = run( {
        options, responses: replies(), actions: [ refresh ],
        site_options: { 1: { phpversion: '8.2.35', security_stats: JSON.stringify( stats ) } },
        site_values: { 1: { securityIssues: 1 } }
    } );
    assert.deepEqual( JSON.parse( result.site_options[ 1 ].security_stats ), { ...stats, phpversion_matched: 'N' } );
    assert.equal( result.site_values[ 1 ].securityIssues, 2 );
} );

test( 'a new bugfix release alone does not change saved Good status or cause unnecessary writes', () => {
    const options = fresh();
    options[ cacheKey ].checked_at -= 21600;
    options[ cacheKey ].branches[ '8.3' ].latest = '8.3.36';
    const result = run( {
        options, responses: replies(), actions: [ refresh ],
        site_options: { 1: { phpversion: '8.3.36', security_stats: JSON.stringify( stats ) } },
        site_values: { 1: { securityIssues: '1' } }
    } );
    assert.deepEqual( result.site_writes, [] );
    assert.equal( result.options[ cacheKey ].branches[ '8.3' ].latest, '8.3.38' );
} );

test( 'background recalculation honors existing extension overrides and counts custom issues', () => {
    const result = run( {
        responses: replies(), actions: [ refresh ], filter_stats: { phpversion_matched: 'Y', custom: 'N' },
        site_options: { 1: { phpversion: '8.3.35', security_stats: JSON.stringify( stats ) } },
        site_values: { 1: { securityIssues: 1 } }
    } );
    assert.deepEqual( JSON.parse( result.site_options[ 1 ].security_stats ), { ...stats, custom: 'N' } );
    assert.equal( result.site_values[ 1 ].securityIssues, 2 );
    assert.equal( result.child_request, null );
} );

test( 'background refresh skips missing, malformed and legacy results without inventing other checks', () => {
    const site_options = Object.fromEntries( [ null, '', '{invalid', '[]', 'null', JSON.stringify( { db_reporting: 'N' } ) ]
        .map( ( security_stats, i ) => [ i + 1, { phpversion: '8.3.35', security_stats } ] ) );
    const result = run( { responses: replies(), actions: [ refresh ], site_options } );
    assert.deepEqual( result.site_options, site_options );
    assert.deepEqual( result.site_writes, [] );
} );

test( 'a saved Good result without a detected PHP version becomes unverifiable after refresh', () => {
    const result = run( {
        responses: replies(), actions: [ refresh ],
        site_options: { 1: { security_stats: JSON.stringify( stats ) } },
        site_values: { 1: { securityIssues: 1 } }
    } );
    assert.deepEqual( JSON.parse( result.site_options[ 1 ].security_stats ), { ...stats, phpversion_matched: 'N' } );
    assert.equal( result.site_values[ 1 ].securityIssues, 2 );
} );

test( 'background recalculation repairs an outdated count even if all stored statuses already match', () => {
    const result = run( {
        responses: replies(), actions: [ refresh ],
        site_options: { 1: { phpversion: '8.3.36', security_stats: JSON.stringify( stats ) } },
        site_values: { 1: { securityIssues: 0 } }
    } );
    assert.deepEqual( result.site_writes, [ [ 1, { securityIssues: 1 } ] ] );
} );

for ( const [ name, responses, fail_cache_write ] of [
    [ 'failed request', [ { error: true } ], false ],
    [ 'incomplete response', [ { data: catalog }, { data: {} } ], false ],
    [ 'baseline rollback', [ { data: catalog }, { data: { '8.3.35': { tags: [ 'security' ] } } } ], false ],
    [ 'failed cache write', replies(), true ]
] ) {
    test( `${name} leaves saved results untouched and does not start site recalculation`, () => {
        const options = fresh();
        options[ cacheKey ].checked_at -= 21600;
        const site_options = { 1: { phpversion: '8.3.35', security_stats: JSON.stringify( stats ) } };
        const result = run( { options, site_options, responses, fail_cache_write, actions: [ refresh ] } );
        assert.deepEqual( result.options, options );
        assert.deepEqual( result.site_options, site_options );
        assert.deepEqual( result.site_queries, [] );
        assert.deepEqual( result.site_writes, [] );
    } );
}

test( 'a fresh cache skips both upstream requests and site recalculation', () => {
    const result = run( {
        options: fresh(), actions: [ refresh ],
        site_options: { 1: { phpversion: '8.3.35', security_stats: JSON.stringify( stats ) } }
    } );
    assert.deepEqual( result.requests, [] );
    assert.deepEqual( result.site_queries, [] );
    assert.deepEqual( result.site_writes, [] );
} );

for ( const [ version, status, issues ] of [ [ '8.3.35', 'N', 2 ], [ '8.3.36', 'Y', 1 ], [ false, 'N', 2 ] ] ) {
    test( `scan reuses detected PHP ${version} and preserves other statuses and counts`, () => {
        const result = run( { options: fresh(), site_options: { 1: { phpversion: version } }, child_reply: stats, actions: [ { method: 'post' } ] } );
        assert.deepEqual( result.outputs[ 0 ].result, { ...stats, phpversion_matched: status } );
        assert.deepEqual( Object.keys( result.outputs[ 0 ] ), [ 'result', 'phpversion_message' ] );
        assert.equal( result.site_values[ 1 ].securityIssues, issues );
        assert.deepEqual( result.child_request, [ 1, 'security', [] ], 'No Child protocol additions' );
        assert.equal( result.secured_action, 'mainwp_security_issues_request' );
        assert.deepEqual( Object.keys( result.site_options[ 1 ] ).sort(), [ 'phpversion', 'security_stats' ] );
        assert.deepEqual( result.requests, [] );
    } );
}

test( 'extension filters retain control over existing flat statuses', () => {
    const result = run( { options: fresh(), site_options: { 1: { phpversion: '8.3.35' } }, child_reply: stats, filter_stats: { phpversion_matched: 'Y', custom: 'N' }, actions: [ { method: 'scan' } ] } );
    assert.equal( result.outputs[ 0 ].phpversion_matched, 'Y' );
    assert.equal( result.site_values[ 1 ].securityIssues, 2 );
} );

test( 'site access denial cannot fetch Child data or modify stored data', () => {
    const result = run( { authorized: false, actions: [ { method: 'scan' } ] } );
    assert.equal( result.child_request, null );
    assert.deepEqual( result.site_options, [] );
    assert.deepEqual( result.site_values, [] );
} );

test( 'the actual PHP row retains only Good and Bad labels and its existing structure', () => {
    const html = run( { actions: [ { method: 'render' } ] } ).outputs[ 0 ];
    const row = html.slice( html.lastIndexOf( '<tr>', html.indexOf( 'phpversion_matched_loading' ) ), html.indexOf( '</tr>', html.indexOf( 'phpversion_matched_loading' ) ) );
    assert.match( row, /green label">Good<\/span>/ );
    assert.match( row, /red label">Bad<\/span>/ );
    assert.doesNotMatch( row, /unknown|yellow|dates|PHP upstream support policy|Check required/ );
} );

const source = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );
for ( const status of [ 'Y', 'N' ] ) {
    test( `GUI preserves ${status === 'Y' ? 'Good' : 'Bad'} and changes only explanatory text safely`, () => {
        const elements = new Map();
        const get = selector => {
            if ( !elements.has( selector ) ) elements.set( selector, { visible: false, value: '' } );
            return elements.get( selector );
        };
        const context = { jQuery: selector => ( {
            hide() { get( selector ).visible = false; return this; },
            show() { get( selector ).visible = true; return this; },
            text( value ) { selector.split( ', ' ).forEach( item => { get( item ).value = value; } ); return this; },
            html() { assert.fail( 'PHP message must use a text sink' ); }
        } ) };
        vm.createContext( context );
        const start = source.indexOf( 'let securityIssues_handle = function ' );
        const end = source.indexOf( '\n};', start ) + 3;
        vm.runInContext( source.slice( start, end ), context );
        const message = '<img src=x onerror=alert(1)> &quot;';
        context.response = { result: { phpversion_matched: status }, phpversion_message: message };
        vm.runInContext( 'securityIssues_handle(response)', context );
        assert.equal( get( '#phpversion_matched_ok' ).visible, status === 'Y' );
        assert.equal( get( '#phpversion_matched_nok' ).visible, status === 'N' );
        assert.equal( get( '#phpversion_matched-status-ok' ).value, message );
        assert.equal( get( '#phpversion_matched-status-nok' ).value, message );
        assert.ok( ![ ...elements.keys() ].some( key => /unknown|dates| \.label/.test( key ) ) );
    } );
}
