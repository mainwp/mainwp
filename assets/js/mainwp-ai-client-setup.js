/**
 * MainWP AI client setup snippets
 *
 * Builds the per-client setup text for the @mainwp/mcp stdio server shown on
 * the Application Passwords page. DOM-free so the browser and the Node test in
 * tests/js/test-ai-client-setup.js run the same code.
 *
 * @package MainWP/Dashboard
 */

( function ( root ) {
    'use strict';

    const PASSWORD_PLACEHOLDER = 'YOUR-APPLICATION-PASSWORD';

    // VS Code resolves ${input:...} by prompting once and storing the answer in
    // its secret storage, which keeps the password out of .vscode/mcp.json.
    const VSCODE_PASSWORD_INPUT = 'mainwp_password';

    const NPX_ARGS = [ '-y', '@mainwp/mcp' ];

    // POSIX single quoting: nothing inside is expanded, and an embedded quote
    // becomes '\''. Usernames and URLs can legally contain quotes and $.
    const shellQuote = function ( value ) {
        return "'" + value.replace( /'/g, "'\\''" ) + "'";
    };

    const buildSnippets = function ( args ) {
        const dashboardUrl = String( args.dashboardUrl || '' ).replace( /\/+$/, '' );
        const username = String( args.username || '' );
        const password = args.password ? String( args.password ) : PASSWORD_PLACEHOLDER;
        // Translated on the PHP side; VS Code shows it as the password prompt.
        const vscodePasswordPrompt = String( args.vscodePasswordPrompt || '' );

        const env = {
            MAINWP_URL: dashboardUrl,
            MAINWP_USER: username,
            MAINWP_APP_PASSWORD: password
        };

        const claudeCode = [
            // The default local scope registers the server for the current directory only.
            'claude mcp add --transport stdio --scope user mainwp',
            '  --env MAINWP_URL=' + shellQuote( dashboardUrl ),
            '  --env MAINWP_USER=' + shellQuote( username ),
            '  --env MAINWP_APP_PASSWORD=' + shellQuote( password ),
            '  -- npx ' + NPX_ARGS.join( ' ' )
        ].join( ' \\\n' );

        const cursor = {
            mcpServers: {
                mainwp: { command: 'npx', args: NPX_ARGS, env: env }
            }
        };

        const vscode = {
            inputs: [
                {
                    type: 'promptString',
                    id: VSCODE_PASSWORD_INPUT,
                    description: vscodePasswordPrompt,
                    password: true
                }
            ],
            servers: {
                mainwp: {
                    type: 'stdio',
                    command: 'npx',
                    args: NPX_ARGS,
                    env: {
                        MAINWP_URL: dashboardUrl,
                        MAINWP_USER: username,
                        MAINWP_APP_PASSWORD: '${input:' + VSCODE_PASSWORD_INPUT + '}'
                    }
                }
            }
        };

        return {
            'claude-code': claudeCode,
            cursor: JSON.stringify( cursor, null, 2 ),
            vscode: JSON.stringify( vscode, null, 2 )
        };
    };

    const api = {
        PASSWORD_PLACEHOLDER: PASSWORD_PLACEHOLDER,
        buildSnippets: buildSnippets
    };

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = api;
    } else {
        root.MainWPAIClientSetup = api;
    }
} )( typeof window !== 'undefined' ? window : this );
