( function( window, document ) {
    const paletteConfig = window.mainwpCommandPalette || {};
    const siteConfig = paletteConfig.siteSearch || {};
    let siteLoaderRegistered = false;
    let searchCommandsByValue = new Map();
    let siteActionUI = null;

    function getAdminNavigationUrl( value, page, idKey, id ) {
        if ( typeof value !== 'string' || ! /^[1-9]\d*$/.test( String( id ) ) ) {
            return null;
        }
        try {
            const url = new window.URL( value, window.location.href );
            const ajaxUrl = new window.URL( siteConfig.ajaxUrl, window.location.href );
            const adminUrl = new window.URL( 'admin.php', ajaxUrl );
            // Entity results may only navigate to their own Dashboard admin route.
            if ( ! [ 'http:', 'https:' ].includes( url.protocol ) || url.origin !== window.location.origin ||
                url.username || url.password || url.pathname !== adminUrl.pathname ||
                url.searchParams.getAll( 'page' ).length !== 1 || url.searchParams.get( 'page' ) !== page ||
                url.searchParams.getAll( idKey ).length !== 1 || url.searchParams.get( idKey ) !== String( id ) ) {
                return null;
            }
            return url.href;
        } catch {
            return null;
        }
    }

    function getSiteNavigationUrl( site, action ) {
        return getAdminNavigationUrl( site.actions?.[ action ]?.url, action === 'admin' ? 'SiteOpen' : 'managesites', action === 'admin' ? 'websiteid' : 'dashboard', site.siteId );
    }

    function runSiteAction( site, action, close = getDispatcher()?.close ) {
        const target = site?.actions?.[ action ];
        if ( ! target || ( action === 'sync' && ( ! supportsSiteSync() || window.mainwpVars?.bulkTaskRunning ) ) ) {
            return;
        }
        const navigationUrl = action === 'sync' ? null : getSiteNavigationUrl( site, action );
        if ( action !== 'sync' && ! navigationUrl ) {
            return;
        }
        const preferences = window.wp.data.select?.( 'core/preferences' );
        const history = preferences?.get( 'core/commands', 'recentlyUsed' ) || [];
        const preferenceActions = window.wp.data.dispatch( 'core/preferences' );
        if ( typeof preferenceActions?.set === 'function' ) {
            // Footer actions bypass the native command row's usage recording.
            preferenceActions.set( 'core/commands', 'recentlyUsed', [ site.name, ...( Array.isArray( history ) ? history.filter( ( name ) => name !== site.name ) : [] ) ].slice( 0, 30 ) );
        }
        if ( typeof close === 'function' ) {
            close();
        }
        if ( action === 'sync' ) {
            prepareSyncSelection( site );
            // The same entry point used by Manage Sites bulk Sync.
            window.mainwp_sync_sites_data( [ String( site.siteId ) ] );
            updateSiteActionBar();
        } else if ( action === 'admin' ) {
            window.open( navigationUrl, '_blank', 'noopener,noreferrer' );
        } else if ( navigationUrl ) {
            window.location.assign( navigationUrl );
        }
    }

    function describeSiteActions( ui, visible ) {
        const descriptions = ( ui.input.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( ( id ) => id && id !== ui.hint.id );
        if ( visible ) {
            descriptions.push( ui.hint.id );
        }
        if ( descriptions.length ) {
            ui.input.setAttribute( 'aria-describedby', descriptions.join( ' ' ) );
        } else {
            ui.input.removeAttribute( 'aria-describedby' );
        }
    }

    function updateSiteActionBar() {
        const ui = siteActionUI;
        if ( ! ui ) {
            return;
        }
        const query = ui.input.value.trim();
        if ( ui.query !== query ) {
            ui.query = query;
            ui.userNavigated = false;
            ui.autoSelectedQuery = null;
        }
        if ( ! ui.userNavigated && ui.autoSelectedQuery !== query && document.activeElement === ui.input && typeof window.PointerEvent === 'function' ) {
            const firstMatch = Array.from( ui.root.querySelectorAll( '[cmdk-item]' ) ).find( ( item ) => searchCommandsByValue.has( item.getAttribute( 'data-value' ) ) );
            if ( firstMatch ) {
                ui.autoSelectedQuery = query;
                // Select through cmdk's own pointer handler, keeping its state,
                // highlighted row and aria-activedescendant in agreement.
                firstMatch.dispatchEvent( new window.PointerEvent( 'pointermove', { bubbles: true } ) );
            }
        }
        const selected = ui.root.querySelector( '[cmdk-item][aria-selected="true"]' );
        const value = selected?.getAttribute( 'data-value' ) || '';
        const site = searchCommandsByValue.get( value.replace( /^recent-/, '' ) );

        if ( ui.row !== selected ) {
            ui.row?.classList.remove( 'mainwp-command-palette-site' );
            ui.row = selected;
        }
        const actionsFocused = ui.bar.contains( document.activeElement );
        ui.bar.hidden = ! site;
        describeSiteActions( ui, !! site );
        if ( ! site ) {
            if ( actionsFocused ) {
                ui.input.focus();
            }
            ui.site = null;
            return;
        }
        selected.classList.add( 'mainwp-command-palette-site' );

        if ( ui.site !== site ) {
            if ( ui.bar.contains( document.activeElement ) ) {
                ui.input.focus();
            }
            ui.site = site;
            ui.name.textContent = site.siteName;
            ui.group.setAttribute( 'aria-label', window.wp.i18n.sprintf( siteConfig.strings.actionsFor, site.siteName ) );
            ui.defaultAction.textContent = site.actions[ site.defaultAction ].label;
            ui.hint.textContent = window.wp.i18n.sprintf( siteConfig.strings.keyboard, ui.defaultAction.textContent );
            ui.group.replaceChildren();
            for ( const action of [ 'admin', 'sync' ] ) {
                if ( ! site.actions[ action ] ) {
                    continue;
                }
                const button = document.createElement( 'button' );
                button.type = 'button';
                button.className = 'components-button mainwp-command-palette-action';
                button.dataset.action = action;
                button.setAttribute( 'aria-label', window.wp.i18n.sprintf( siteConfig.strings.siteAction, site.actions[ action ].label, site.siteName ) );
                const icon = document.createElement( 'span' );
                icon.className = 'dashicons ' + ( action === 'admin' ? 'dashicons-migrate' : 'dashicons-update' );
                icon.setAttribute( 'aria-hidden', 'true' );
                const label = document.createElement( 'span' );
                label.textContent = site.actions[ action ].label;
                button.append( icon, label );
                button.addEventListener( 'click', () => runSiteAction( ui.site, action ) );
                ui.group.appendChild( button );
            }
        }
        const sync = ui.group.querySelector( '[data-action="sync"]' );
        if ( sync ) {
            const busy = !! window.mainwpVars?.bulkTaskRunning;
            sync.setAttribute( 'aria-disabled', String( busy ) );
            sync.lastElementChild.textContent = busy ? siteConfig.strings.syncingAction : site.actions.sync.label;
        }
    }

    function attachSiteActionBar() {
        const container = document.querySelector( '.commands-command-menu__container' );
        if ( siteActionUI?.container === container ) {
            return;
        }
        if ( siteActionUI ) {
            const ui = siteActionUI;
            ui.observer.disconnect();
            ui.container.removeEventListener( 'keydown', ui.onKeyDown, true );
            ui.container.removeEventListener( 'pointermove', ui.onPointerMove, true );
            ui.container.classList.remove( 'mainwp-command-palette-container' );
            ui.content?.classList.remove( 'mainwp-command-palette-content' );
            ui.modal?.classList.remove( 'mainwp-command-palette-modal' );
            describeSiteActions( ui, false );
            ui.bar.remove();
            siteActionUI = null;
        }
        const root = container?.querySelector( '[cmdk-root]' );
        const input = root?.querySelector( '[cmdk-input]' );
        if ( ! root || ! input || ! root.querySelector( '[cmdk-list]' ) ) {
            return;
        }
        const content = container.closest( '.components-modal__content' );
        const modal = content?.closest( '.commands-command-menu' );
        container.classList.add( 'mainwp-command-palette-container' );
        content?.classList.add( 'mainwp-command-palette-content' );
        modal?.classList.add( 'mainwp-command-palette-modal' );

        const bar = document.createElement( 'div' );
        bar.className = 'mainwp-command-palette-site-actions';
        bar.hidden = true;
        const row = document.createElement( 'div' );
        row.className = 'mainwp-command-palette-action-row';
        const name = document.createElement( 'span' );
        name.className = 'mainwp-command-palette-selected-site';
        const group = document.createElement( 'div' );
        group.className = 'mainwp-command-palette-action-group';
        group.setAttribute( 'role', 'group' );
        row.append( name, group );
        bar.appendChild( row );

        const keyboard = document.createElement( 'div' );
        keyboard.className = 'mainwp-command-palette-keyboard';
        keyboard.setAttribute( 'aria-hidden', 'true' );
        const defaultAction = document.createElement( 'span' );
        for ( const [ key, label ] of [ [ '↑ ↓', siteConfig.strings.selectSite ], [ siteConfig.strings.enter, defaultAction ], [ 'Tab', siteConfig.strings.actions ], [ 'Esc', siteConfig.strings.search ] ] ) {
            const item = document.createElement( 'span' );
            const kbd = document.createElement( 'kbd' );
            kbd.textContent = key;
            item.append( kbd, ' ', label );
            keyboard.appendChild( item );
        }
        bar.appendChild( keyboard );
        const hint = document.createElement( 'p' );
        hint.className = 'screen-reader-text';
        hint.id = 'mainwp-command-palette-action-hint';
        bar.appendChild( hint );

        // WordPress has no footer slot. Keep this DOM bridge outside both cmdk's
        // listbox and key handler; command registration/search remain native.
        container.appendChild( bar );
        const ui = { container, content, modal, root, input, bar, name, group, defaultAction, hint, site: null, row: null };
        ui.onKeyDown = ( event ) => {
            if ( event.target === input && [ 'ArrowUp', 'ArrowDown', 'Home', 'End' ].includes( event.key ) ) {
                ui.userNavigated = true;
            }
            if ( ! bar.contains( event.target ) ) {
                return;
            }
            if ( event.key === 'Escape' || event.key === 'ArrowUp' || event.key === 'ArrowDown' ) {
                event.preventDefault();
                event.stopPropagation();
                input.focus();
            } else if ( event.key === 'ArrowLeft' || event.key === 'ArrowRight' ) {
                event.preventDefault();
                event.stopPropagation();
                const buttons = Array.from( group.querySelectorAll( 'button' ) );
                const index = buttons.indexOf( document.activeElement );
                buttons[ Math.max( 0, Math.min( buttons.length - 1, index + ( event.key === 'ArrowRight' ? 1 : -1 ) ) ) ]?.focus();
            }
        };
        ui.onPointerMove = ( event ) => {
            // Hover must not silently change the target of a keyboard-focused action.
            if ( bar.contains( document.activeElement ) && event.target.closest( '[cmdk-item]' ) ) {
                event.stopPropagation();
            } else if ( event.isTrusted && event.target.closest( '[cmdk-item]' ) ) {
                ui.userNavigated = true;
            }
        };
        container.addEventListener( 'keydown', ui.onKeyDown, true );
        container.addEventListener( 'pointermove', ui.onPointerMove, true );
        ui.observer = new window.MutationObserver( updateSiteActionBar );
        ui.observer.observe( root, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'aria-selected', 'data-value' ] } );
        input.placeholder = siteConfig.strings.searchLabel;
        const label = root.querySelector( '[cmdk-label]' );
        if ( label ) {
            label.textContent = siteConfig.strings.searchLabel;
        }
        siteActionUI = ui;
        updateSiteActionBar();
    }

    function watchSiteActionBar() {
        if ( typeof window.MutationObserver !== 'function' || ! document.body ) {
            return;
        }
        // Native WordPress modals mount directly under body. Only watch that level
        // globally; selection observation is limited to the open palette.
        const observer = new window.MutationObserver( attachSiteActionBar );
        observer.observe( document.body, { childList: true } );
        attachSiteActionBar();
    }

    function requestSites( action, data, signal ) {
        const body = new window.URLSearchParams( data );
        body.set( 'action', action );

        return window.fetch( siteConfig.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body,
            signal,
        } ).then( ( response ) => response.json() ).then( ( response ) => {
            if ( ! response || ! response.success ) {
                throw new Error( typeof response?.data === 'string' ? response.data : '' );
            }
            return response.data;
        } );
    }

    function supportsSiteSync() {
        return typeof window.mainwp_sync_sites_data === 'function' &&
            !! window.dashboardSync?.initialized &&
            !! document.getElementById( 'mainwp-sync-sites-modal' );
    }

    // Populate the existing popup's selection when this page does not contain
    // the searched site. The shared bulk Sync routine owns all processing.
    function prepareSyncSelection( site ) {
        const siteId = String( site.siteId );
        const sync = window.dashboardSync;
        let row = sync.rowMap.get( siteId );
        if ( ! row ) {
            row = document.createElement( 'div' );
            row.className = 'item';
            const right = document.createElement( 'div' );
            right.className = 'right floated content';
            const status = document.createElement( 'div' );
            status.className = 'sync-site-status';
            status.setAttribute( 'siteid', siteId );
            status.setAttribute( 'niceurl', site.siteName );
            right.appendChild( status );
            const name = document.createElement( 'div' );
            name.className = 'content';
            name.textContent = site.siteName;
            row.append( right, name );
            sync.container.appendChild( row );
            sync.rowMap.set( siteId, row );
            sync.statusMap.set( siteId, status );
        }
        let selection = document.querySelector( '.dashboard_wp_id[value="' + siteId + '"]' );
        if ( ! selection ) {
            selection = document.createElement( 'input' );
            selection.type = 'hidden';
            selection.name = 'dashboard_wp_ids[]';
            selection.className = 'dashboard_wp_id';
            selection.setAttribute( 'value', siteId );
            row.appendChild( selection );
        }
        // The search response uses the site's current stored connection status.
        // Refresh a stale page selection as well as a newly added selection.
        selection.setAttribute( 'error-status', '0' );
        row.classList.remove( 'disconnected-site' );
        row.style.display = '';
    }

    function buildSearchCommands( commands, search ) {
        return commands.filter( ( command ) => command && command.name && command.label ).map( ( command ) => {
            // Clients are ordinary navigation commands with no site action bar.
            const clientMatch = /^mainwp\/client\/([1-9]\d*)$/.exec( command.name );
            if ( clientMatch ) {
                const url = getAdminNavigationUrl( command.url, 'ManageClients', 'client_id', clientMatch[ 1 ] );
                if ( ! url ) {
                    return null;
                }
                return {
                    name: command.name,
                    label: command.label,
                    searchLabel: command.name,
                    keywords: [ ...( command.keywords || [] ), search ],
                    category: command.category,
                    callback: ( { close } = {} ) => {
                        const dismiss = close || getDispatcher()?.close;
                        if ( typeof dismiss === 'function' ) {
                            dismiss();
                        }
                        window.location.assign( url );
                    },
                };
            }
            if ( ! /^[1-9]\d*$/.test( String( command.siteId ) ) || command.name !== 'mainwp/site/' + command.siteId ) {
                return null;
            }
            const actions = {};
            for ( const action of [ 'operations', 'admin', 'sync' ] ) {
                const target = command.actions?.[ action ];
                const url = action === 'sync' ? null : getSiteNavigationUrl( command, action );
                if ( target && ( action === 'sync' || url ) ) {
                    actions[ action ] = { ...target, ...( url ? { url } : {} ) };
                }
            }
            if ( ! supportsSiteSync() ) {
                delete actions.sync;
            }
            const defaultAction = actions[ command.defaultAction ] ? command.defaultAction : Object.keys( actions )[ 0 ];
            if ( ! defaultAction ) {
                return null;
            }
            const site = { ...command, actions, defaultAction };
            return {
                name: command.name,
                label: command.label,
                // A unique value avoids collisions between identical site names/URLs.
                searchLabel: command.name,
                // Keep server matches through core's own filter.
                keywords: [ ...( command.keywords || [] ), search ],
                category: command.category,
                site,
                callback: ( { close } = {} ) => runSiteAction( site, defaultAction, close ),
            };
        } ).filter( Boolean );
    }

    function useSiteSearch( { search } ) {
        const query = ( search || '' ).trim();
        const canSearch = Array.from( query ).length >= 2 && query.length <= 200;
        const history = window.wp.data.useSelect( ( select ) => select( 'core/preferences' ).get( 'core/commands', 'recentlyUsed' ) || [], [] );
        const recent = ! query && Array.isArray( history ) ? history.slice( 0, 5 ).filter( ( name ) => typeof name === 'string' && /^mainwp\/(site|client)\/[1-9]\d*$/.test( name ) ) : [];
        const requestKey = query ? 'search:' + query : 'recent:' + JSON.stringify( recent );
        const canLoad = canSearch || ( ! query && recent.length > 0 );
        const [ result, setResult ] = window.wp.element.useState( { query: '', commands: [], error: false } );
        const [ retry, setRetry ] = window.wp.element.useState( 0 );

        window.wp.element.useEffect( () => {
            if ( ! canLoad ) {
                return;
            }

            let active = true;
            const controller = new window.AbortController();
            setResult( { query: requestKey, commands: [], error: false, isLoading: true } );
            const timer = window.setTimeout( () => {
                requestSites( 'mainwp_command_palette_search_sites', {
                    ...( query ? { search: query } : { recent: JSON.stringify( recent ) } ),
                    security: siteConfig.searchNonce,
                }, controller.signal ).then( ( commands ) => {
                    if ( active ) {
                        setResult( { query: requestKey, commands: buildSearchCommands( Array.isArray( commands ) ? commands : [], query ), error: false } );
                    }
                } ).catch( () => {
                    if ( active ) {
                        setResult( { query: requestKey, commands: [], error: true } );
                    }
                } );
            }, query ? 250 : 0 );

            return () => {
                active = false;
                window.clearTimeout( timer );
                controller.abort();
            };
        }, [ requestKey, canLoad, retry ] );

        window.wp.element.useEffect( () => {
            const commands = canLoad && result.query === requestKey && ! result.error && ! result.isLoading ? result.commands : [];
            const values = new Map( commands.map( ( command ) => [ command.searchLabel, command.site ] ) );
            searchCommandsByValue = values;
            updateSiteActionBar();
            return () => {
                if ( searchCommandsByValue === values ) {
                    searchCommandsByValue = new Map();
                    updateSiteActionBar();
                }
            };
        }, [ requestKey, canLoad, result ] );

        if ( ! canLoad ) {
            return { commands: [], isLoading: false };
        }
        if ( result.query !== requestKey ) {
            return { commands: [], isLoading: true };
        }
        if ( result.error ) {
            if ( ! query ) {
                return { commands: [], isLoading: false };
            }
            return {
                commands: [ {
                    name: 'mainwp/site-search-retry',
                    label: siteConfig.strings.searchError,
                    keywords: [ query ],
                    category: 'command',
                    callback: () => setRetry( ( value ) => value + 1 ),
                } ],
                isLoading: false,
            };
        }
        return { commands: result.commands, isLoading: !! result.isLoading };
    }

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

        ( Array.isArray( paletteConfig.commands ) ? paletteConfig.commands : [] ).forEach( ( command ) => {
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

        if (
            ! siteLoaderRegistered && siteConfig.ajaxUrl && siteConfig.searchNonce &&
            siteConfig.strings && window.wp.element && window.wp.i18n &&
            typeof dispatcher.registerCommandLoader === 'function'
        ) {
            dispatcher.registerCommandLoader( {
                name: 'mainwp/site-search',
                hook: useSiteSearch,
            } );
            siteLoaderRegistered = true;
            watchSiteActionBar();
        }
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
