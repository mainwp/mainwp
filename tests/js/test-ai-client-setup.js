'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );
const setup = require( '../../assets/js/mainwp-ai-client-setup.js' );

assert.equal( typeof setup.buildSnippets, 'function' );
assert.equal( typeof setup.PASSWORD_PLACEHOLDER, 'string' );
assert.ok( setup.PASSWORD_PLACEHOLDER.length > 0 );

const password = 'abcd EFGH ijkl MNOP qrst UVWX';
const filled = setup.buildSnippets( { dashboardUrl: 'https://dash.example.com/', username: 'admin', password: password } );
assert.deepEqual( Object.keys( filled ).sort(), [ 'claude-code', 'cursor', 'vscode' ] );

// Run the Claude Code command through a real POSIX shell with `claude` swapped
// for printf, so quoting is proven by the shell's own word splitting.
function shellArgs( command ) {
    assert.ok( command.startsWith( 'claude mcp add ' ), command );
    const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'ai-setup-' ) );
    const out = execFileSync( '/bin/sh', [ '-c', 'printf "%s\\n" ' + command.slice( 'claude '.length ) ], { cwd: dir, encoding: 'utf8' } );
    const created = fs.readdirSync( dir );
    fs.rmSync( dir, { recursive: true, force: true } );
    assert.deepEqual( created, [], 'shell input must not execute anything' );
    return out.replace( /\n$/, '' ).split( '\n' );
}

assert.deepEqual( shellArgs( filled[ 'claude-code' ] ), [
    'mcp', 'add', '--transport', 'stdio', 'mainwp',
    '--env', 'MAINWP_URL=https://dash.example.com',
    '--env', 'MAINWP_USER=admin',
    '--env', 'MAINWP_APP_PASSWORD=' + password,
    '--', 'npx', '-y', '@mainwp/mcp'
] );

const cursor = JSON.parse( filled.cursor );
assert.deepEqual( cursor, {
    mcpServers: {
        mainwp: {
            command: 'npx',
            args: [ '-y', '@mainwp/mcp' ],
            env: { MAINWP_URL: 'https://dash.example.com', MAINWP_USER: 'admin', MAINWP_APP_PASSWORD: password }
        }
    }
} );

// VS Code prompts for the password and keeps it in its secret storage, so the
// password must never be written into .vscode/mcp.json.
const vscode = JSON.parse( filled.vscode );
assert.ok( ! filled.vscode.includes( password ) );
assert.deepEqual( vscode.inputs, [ { type: 'promptString', id: 'mainwp_password', description: 'MainWP Application Password', password: true } ] );
assert.deepEqual( vscode.servers.mainwp, {
    type: 'stdio',
    command: 'npx',
    args: [ '-y', '@mainwp/mcp' ],
    env: { MAINWP_URL: 'https://dash.example.com', MAINWP_USER: 'admin', MAINWP_APP_PASSWORD: '${input:mainwp_password}' }
} );

// Placeholder form, used by the onboarding notice before any password exists.
for ( const missing of [ undefined, null, '' ] ) {
    const placeholder = setup.buildSnippets( { dashboardUrl: 'https://dash.example.com', username: 'admin', password: missing } );
    assert.ok( shellArgs( placeholder[ 'claude-code' ] ).includes( 'MAINWP_APP_PASSWORD=' + setup.PASSWORD_PLACEHOLDER ) );
    assert.equal( JSON.parse( placeholder.cursor ).mcpServers.mainwp.env.MAINWP_APP_PASSWORD, setup.PASSWORD_PLACEHOLDER );
    assert.equal( JSON.parse( placeholder.vscode ).servers.mainwp.env.MAINWP_APP_PASSWORD, '${input:mainwp_password}' );
}

// Hostile values: a quote, shell expansion and a script tag must survive as data.
const hostile = {
    dashboardUrl: 'https://dash.example.com/a$(touch pwned)`touch pwned2`',
    username: "o'neil \"x\" </script><b>",
    password: "p'w $HOME"
};
const hostileSnippets = setup.buildSnippets( hostile );
assert.deepEqual( shellArgs( hostileSnippets[ 'claude-code' ] ).slice( 6, 12 ), [
    'MAINWP_URL=' + hostile.dashboardUrl, '--env',
    'MAINWP_USER=' + hostile.username, '--env',
    'MAINWP_APP_PASSWORD=' + hostile.password, '--'
] );
assert.deepEqual( JSON.parse( hostileSnippets.cursor ).mcpServers.mainwp.env, {
    MAINWP_URL: hostile.dashboardUrl,
    MAINWP_USER: hostile.username,
    MAINWP_APP_PASSWORD: hostile.password
} );
assert.equal( JSON.parse( hostileSnippets.vscode ).servers.mainwp.env.MAINWP_USER, hostile.username );

// The page script must insert snippets as text, never as markup.
const page = fs.readFileSync( 'assets/js/mainwp-rest-api.js', 'utf8' );
const start = page.indexOf( 'const fill_ai_client_setup = ' );
const end = page.indexOf( '\n    };', start );
assert.ok( start > 0 && end > start, 'fill_ai_client_setup must exist' );
const fill = page.slice( start, end );
assert.match( fill, /MainWPAIClientSetup\.buildSnippets\(/ );
assert.match( fill, /\.textContent = / );
assert.doesNotMatch( fill, /\.html\(|innerHTML|outerHTML|insertAdjacentHTML|\.append\(/ );

console.log( 'test-ai-client-setup: ok' );
