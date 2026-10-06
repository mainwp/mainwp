( function( window, document ) {
    const paletteConfig = window.mainwpCommandPalette || {};

    function getDispatcher() {
        if (
            ! window.wp ||
            ! window.wp.data ||
            typeof window.wp.data.dispatch !== 'function'
        ) {
            return null;
        }

        return window.wp.data.dispatch( 'core/commands' );
    }

    function applyCommands() {
        const dispatcher = getDispatcher();

        if (
            ! dispatcher ||
            typeof dispatcher.registerCommand !== 'function' ||
            typeof dispatcher.unregisterCommand !== 'function'
        ) {
            return;
        }

        if ( Array.isArray( paletteConfig.unregister ) ) {
            paletteConfig.unregister.forEach( ( commandName ) => {
                if ( commandName ) {
                    dispatcher.unregisterCommand( commandName );
                }
            } );
        }

        if ( ! Array.isArray( paletteConfig.commands ) ) {
            return;
        }

        paletteConfig.commands.forEach( ( command ) => {
            if ( ! command || ! command.name || ! command.label || ! command.url ) {
                return;
            }

            dispatcher.registerCommand( {
                name: command.name,
                label: command.label,
                searchLabel: command.searchLabel || command.label,
                keywords: Array.isArray( command.keywords ) ? command.keywords : [],
                category: 'view',
                callback: ( { close } = {} ) => {
                    if ( typeof close === 'function' ) {
                        close();
                    }

                    window.location.assign( command.url );
                },
            } );
        } );
    }

    function scheduleApply() {
        applyCommands();
        window.setTimeout( applyCommands, 0 );
        window.setTimeout( applyCommands, 150 );
        window.setTimeout( applyCommands, 500 );
        window.setTimeout( applyCommands, 1500 );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', scheduleApply, { once: true } );
    } else {
        scheduleApply();
    }

    window.addEventListener( 'load', applyCommands, { once: true } );
} )( window, document );
