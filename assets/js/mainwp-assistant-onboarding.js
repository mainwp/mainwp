/**
 * Shared Assistant connection dialogs.
 *
 * Personal connections and site-wide keys use separate permission gates.
 * Sharing the dialogs keeps each surface consistent with those gates.
 *
 * @package MainWP/Dashboard
 * @since   6.0.0
 */

/* global jQuery, mainwpMintlifyChatL10n, mainwpAssistantSettings, MainWPAssistantChatLogic */

( function( $, root ) {
    'use strict';

    var MODAL_ID = '#mainwp-assistant-onboarding-modal',
        nextWizardStep = MainWPAssistantChatLogic.nextWizardStep,
        classifyKeyFailure = MainWPAssistantChatLogic.classifyKeyFailure;

    var lastStatus = null,
        currentPanel = '',
        openSource = '',
        agentAvailable = false;

    // What the modal reports about itself when it closes, and whether it is
    // still on screen when a verification answer comes back. The settings page
    // reloads off these instead of guessing from event order: a close can
    // arrive before the verification it was waiting for, and a Try it close
    // must never reload the page the chat panel is about to be handed.
    var modalOpen = false,
        sessionConnected = false,
        closeAction = 'close';

    // Each open() is a launch session. Async answers hold the session they
    // were sent from and stand down when a newer launch owns the modal, so a
    // settings-launched verification can never render into a chat-launched
    // wizard (or the reverse). A stale but VERIFIED answer still announces the
    // connection under its own source: the fact that a key now works is real
    // regardless of which session is on screen.
    var launchSession = 0;

    /**
     * Read a localized string from whichever object this page registered.
     *
     * The panel path localizes mainwpMintlifyChatL10n, the settings page
     * localizes mainwpAssistantSettings.strings; both carry the same keys.
     *
     * @param {string} key      Localization key.
     * @param {string} fallback Text to use when the key is missing.
     * @return {string} Localized string.
     */
    function text( key, fallback ) {
        if ( typeof mainwpMintlifyChatL10n !== 'undefined' && mainwpMintlifyChatL10n[ key ] ) {
            return mainwpMintlifyChatL10n[ key ];
        }
        if ( typeof mainwpAssistantSettings !== 'undefined' && mainwpAssistantSettings.strings && mainwpAssistantSettings.strings[ key ] ) {
            return mainwpAssistantSettings.strings[ key ];
        }
        return fallback;
    }

    /**
     * Nonce for both onboarding endpoints.
     *
     * @return {string} Nonce, or '' when this page registered none.
     */
    function nonce() {
        if ( typeof mainwpMintlifyChatL10n !== 'undefined' && mainwpMintlifyChatL10n.onboarding_nonce ) {
            return mainwpMintlifyChatL10n.onboarding_nonce;
        }
        if ( typeof mainwpAssistantSettings !== 'undefined' && mainwpAssistantSettings.onboardingNonce ) {
            return mainwpAssistantSettings.onboardingNonce;
        }
        return '';
    }

    /**
     * Admin-ajax endpoint URL.
     *
     * @return {string} URL.
     */
    function endpoint() {
        if ( typeof mainwpAssistantSettings !== 'undefined' && mainwpAssistantSettings.ajaxurl ) {
            return mainwpAssistantSettings.ajaxurl;
        }
        return typeof root.ajaxurl !== 'undefined' ? root.ajaxurl : '';
    }

    /**
     * The wizard modal, if this page rendered one.
     *
     * @return {Object} jQuery collection, possibly empty.
     */
    function $modal() {
        return $( MODAL_ID );
    }

    /**
     * Whether the wizard can be opened on this page.
     *
     * The modal is only rendered for users who hold the same rights the
     * endpoints enforce, so its presence is the gate.
     *
     * @return {boolean} True when open() will do something.
     */
    function isAvailable() {
        return $modal().length > 0 && '' !== nonce() && '' !== endpoint();
    }

    /**
     * Show one panel and the buttons that belong to it.
     *
     * @param {string} panel Panel key: loading, get_key, paste, override, verify or blocked.
     */
    function showPanel( panel ) {
        var $m = $modal();

        currentPanel = panel;

        $m.find( '.mainwp-assistant-onboarding-loading' ).prop( 'hidden', 'loading' !== panel );
        $m.find( '.mainwp-assistant-onboarding-panel' ).each( function() {
            this.hidden = $( this ).data( 'panel' ) !== panel;
        } );

        // The header steps track progress, so the override and blocked
        // sub-states stay on the step they interrupted.
        var stepName = panel;
        if ( 'override' === panel ) {
            stepName = 'paste';
        } else if ( 'blocked' === panel || 'loading' === panel ) {
            stepName = '';
        }

        $m.find( '.mainwp-assistant-onboarding-steps .step' ).each( function() {
            $( this ).toggleClass( 'active', $( this ).data( 'step' ) === stepName );
        } );

        syncActions( panel );
        focusPanel( panel );
    }

    /**
     * Show the action buttons for a panel and hide the rest.
     *
     * @param {string} panel Panel key.
     */
    function syncActions( panel ) {
        var $m = $modal(),
            visible = [];

        if ( 'get_key' === panel ) {
            visible = [ 'open-google', 'goto-paste' ];
        } else if ( 'paste' === panel ) {
            visible = [ 'submit' ];
        } else if ( 'override' === panel || 'blocked' === panel ) {
            visible = [ 'connectors' ];
        } else if ( 'verify' === panel ) {
            visible = verifyActions();
        }

        $m.find( '.actions [data-action]' ).each( function() {
            var name = $( this ).data( 'action' );
            if ( 'close' === name ) {
                return;
            }
            this.hidden = visible.indexOf( name ) === -1;
        } );
    }

    /**
     * Buttons the current verify outcome offers.
     *
     * @return {Array} Action names.
     */
    function verifyActions() {
        var $m = $modal();

        if ( ! $m.find( '[data-outcome="success"]' ).prop( 'hidden' ) ) {
            // Try it hands the conversation back to the chat panel, so it only
            // makes sense where a panel exists to hand it to and where Sites
            // mode answers this page load. Without it the success copy already
            // says the reload is what turns Sites mode on, so Close is the
            // only honest offer.
            return agentAvailable && $( '#mainwp-assistant-panel' ).length ? [ 'try-it' ] : [];
        }

        if ( ! $m.find( '[data-outcome="failure"]' ).prop( 'hidden' ) ) {
            var action = $m.find( '[data-outcome="failure"]' ).data( 'retry-action' );
            if ( 'retry_verify' === action ) {
                return [ 'retry-verify' ];
            }
            if ( 'link_out' === action ) {
                return [ 'connectors' ];
            }
            return [ 'retry-key' ];
        }

        return [];
    }

    /**
     * Move focus to the first control of a panel.
     *
     * @param {string} panel Panel key.
     */
    function focusPanel( panel ) {
        var $m = $modal(),
            $target;

        if ( 'paste' === panel ) {
            $target = $m.find( '#mainwp-assistant-onboarding-key' );
        } else {
            $target = $m.find( '.actions [data-action]:not([hidden])' ).first();
        }

        if ( $target && $target.length ) {
            root.setTimeout( function() {
                $target.trigger( 'focus' );
            }, 0 );
        }
    }

    /**
     * Reset the modal to its opening state.
     */
    function resetModal() {
        var $m = $modal();

        agentAvailable = false;
        sessionConnected = false;
        closeAction = 'close';
        clearKeyField();
        $m.find( '[data-outcome]' ).prop( 'hidden', true );
        $m.find( '[data-outcome="failure"]' ).removeClass( 'red yellow' ).removeData( 'retry-action' );
        $m.find( '[data-field]' ).text( '' );
        $m.find( '[data-field="provider-message"], [data-field="suggestion"]' ).prop( 'hidden', true );
        $m.find( '[data-override]' ).prop( 'hidden', true );
        $m.find( '[data-hint="prefix"]' ).prop( 'hidden', true );
    }

    /**
     * Empty the key field and put it back into masked mode.
     */
    function clearKeyField() {
        var $m = $modal(),
            $key = $m.find( '#mainwp-assistant-onboarding-key' );

        $key.val( '' ).attr( 'type', 'password' );
        $m.find( '#mainwp-assistant-onboarding-toggle-key' )
            .attr( 'aria-pressed', 'false' )
            .attr( 'aria-label', text( 'onboarding_show_key', 'Show key' ) )
            .find( 'i' ).attr( 'class', 'eye icon' );
    }

    /**
     * Open the wizard.
     *
     * @param {string} source Where the wizard was launched from. 'replace'
     *                        skips the Google instructions and opens on Paste.
     */
    function open( source, apiOnly ) {
        if ( ! apiOnly && 'replace' !== source && root.MainWPAssistantChatGPT ) {
            root.MainWPAssistantChatGPT.openChoices( source );
            return;
        }
        if ( ! isAvailable() ) {
            return;
        }

        openSource = source || '';
        launchSession++;
        resetModal();
        modalOpen = true;

        $modal()
            .modal( {
                onHidden: function() {
                    modalOpen = false;

                    // The key never survives a closed modal, in this tab or in
                    // a screenshot of it.
                    clearKeyField();
                    $modal().trigger( 'mainwp-assistant-onboarding-closed', [ {
                        connected: sessionConnected,
                        action: closeAction,
                        source: openSource
                    } ] );
                }
            } )
            .modal( 'show' );

        showPanel( 'loading' );
        requestStatus();
    }

    /**
     * Ask the server what state this dashboard is in, then show a step.
     */
    function requestStatus() {
        var session = launchSession;

        $.post( endpoint(), {
            action: 'mainwp_assistant_onboarding_status',
            nonce: nonce()
        } ).done( function( response ) {
            if ( session !== launchSession ) {
                return;
            }
            if ( ! response || ! response.success || ! response.data ) {
                showBlocked( '' );
                return;
            }

            lastStatus = response.data;

            var step = nextWizardStep( {
                requested: 'replace' === openSource ? 'paste' : 'get_key',
                status: lastStatus
            } );

            if ( 'blocked' === step ) {
                // A blocked precondition always names itself; an empty code
                // here means the contract read fine and only the provider is
                // inactive.
                showBlocked( lastStatus.error_code || 'provider_inactive' );
                return;
            }

            applyCredentialsUrl( lastStatus.credentials_url );

            if ( 'override' === step ) {
                showOverride( lastStatus.key_source );
                return;
            }

            showPanel( step );
        } ).fail( function() {
            if ( session !== launchSession ) {
                return;
            }
            showBlocked( '' );
        } );
    }

    /**
     * Show the blocked panel, picking the reason the server reported.
     *
     * @param {string} errorCode Status error_code, '' when the request itself failed.
     */
    function showBlocked( errorCode ) {
        var $m = $modal(),
            $reasons = $m.find( '[data-blocked]' ),
            matched = false;

        $reasons.each( function() {
            var mine = $( this ).data( 'blocked' ) === errorCode;
            this.hidden = ! mine;
            matched = matched || mine;
        } );

        if ( ! matched ) {
            $m.find( '[data-blocked="generic"]' )
                .text( text( 'onboarding_request_failed', 'The request did not reach this dashboard. Check your connection and try again.' ) )
                .prop( 'hidden', false );
        }

        showPanel( 'blocked' );
    }

    /**
     * Show the environment or constant explanation instead of the paste field.
     *
     * @param {string} source Key source reported by the server.
     */
    function showOverride( source ) {
        var $m = $modal();

        $m.find( '[data-override]' ).each( function() {
            this.hidden = $( this ).data( 'override' ) !== source;
        } );

        showPanel( 'override' );
    }

    /**
     * Point the Google button at the URL the connector contract supplies.
     *
     * @param {string} url Credentials URL.
     */
    function applyCredentialsUrl( url ) {
        if ( url ) {
            $modal().find( '[data-action="open-google"]' ).attr( 'href', url );
        }
    }

    /**
     * Post the key, then render the outcome on step 3.
     */
    function submitKey() {
        var $m = $modal(),
            $key = $m.find( '#mainwp-assistant-onboarding-key' ),
            value = $.trim( $key.val() || '' );

        // A missing AIza prefix is a hint, never a block: Google has changed
        // its key format before and is retiring the old one in 2026.
        $m.find( '[data-hint="prefix"]' ).prop( 'hidden', 0 === value.indexOf( 'AIza' ) || '' === value );

        $m.find( '[data-outcome]' ).prop( 'hidden', true );
        $m.find( '[data-field="working-text"]' ).text( text( 'onboarding_verifying', 'Checking the key with Google...' ) );
        $m.find( '[data-outcome="working"]' ).prop( 'hidden', false );
        showPanel( 'verify' );

        var session = launchSession,
            sessionSource = openSource;

        $.post( endpoint(), {
            action: 'mainwp_assistant_onboarding_save_key',
            nonce: nonce(),
            api_key: value
        } ).done( function( response ) {
            if ( session !== launchSession ) {
                if ( response && response.success && response.data ) {
                    $( document ).trigger( 'mainwp-assistant-connected', [ {
                        agent_available: !! response.data.agent_available,
                        modal_open: false,
                        source: sessionSource
                    } ] );
                }
                return;
            }
            if ( response && response.success && response.data ) {
                renderSuccess( response.data );
                return;
            }
            renderFailure( ( response && response.data ) || {} );
        } ).fail( function( xhr ) {
            if ( session !== launchSession ) {
                return;
            }
            var data = ( xhr && xhr.responseJSON && xhr.responseJSON.data ) || {};
            if ( ! data.message ) {
                data = { error_code: '', message: text( 'onboarding_request_failed', 'The request did not reach this dashboard. Check your connection and try again.' ) };
            }
            renderFailure( data );
        } );
    }

    /**
     * Render the connected outcome.
     *
     * @param {Object} data save_key success payload.
     */
    function renderSuccess( data ) {
        var $m = $modal(),
            model = data.effective_model || data.default_model || '',
            message;

        clearKeyField();
        agentAvailable = !! data.agent_available;
        sessionConnected = true;

        if ( ! agentAvailable ) {
            // The key is stored and verified but this request still resolves
            // agent mode as unavailable, so promising a working Sites mode now
            // would be a lie.
            message = text( 'onboarding_connected_reload', 'Key verified. Sites mode becomes available after you reload this page.' );
        } else if ( model ) {
            message = text( 'onboarding_connected', 'Connected. Sites mode is on, using %s.' ).replace( '%s', model );
        } else {
            message = text( 'onboarding_connected_plain', 'Connected. Sites mode is on.' );
        }

        $m.find( '[data-outcome]' ).prop( 'hidden', true );
        $m.find( '[data-field="success-text"]' ).text( message );

        var $suggestion = $m.find( '[data-field="suggestion"]' );
        if ( data.suggested_prompt ) {
            $suggestion.text( data.suggested_prompt ).prop( 'hidden', false );
            $m.find( '[data-action="try-it"]' ).data( 'prompt', data.suggested_prompt );
        }

        $m.find( '[data-outcome="success"]' ).prop( 'hidden', false );
        showPanel( 'verify' );

        // The save is what changed the connection state, so the chat panel
        // hears about it here rather than from a Try it click the user may
        // never make. The flag travels with it because a verified key that
        // only takes effect after a reload must not flip Sites mode on. This
        // fires even when the user closed the modal mid-verification, which is
        // the only signal a listener gets for a connection its close event
        // could not yet report.
        $m.trigger( 'mainwp-assistant-connected', [ {
            agent_available: agentAvailable,
            modal_open: modalOpen,
            source: openSource
        } ] );
    }

    /**
     * Render a failed save or validation.
     *
     * @param {Object} data save_key failure payload.
     */
    function renderFailure( data ) {
        var $m = $modal(),
            outcome = classifyKeyFailure( data.error_code ),
            $failure = $m.find( '[data-outcome="failure"]' );

        $m.find( '[data-outcome]' ).prop( 'hidden', true );

        $failure
            .removeClass( 'red yellow' )
            .addClass( 'warning' === outcome.severity ? 'yellow' : 'red' )
            .data( 'retry-action', outcome.action );

        $failure.find( '[data-field="failure-text"]' ).text( data.message || '' );

        // Google's own wording is what separates a retired key from a wrong
        // one, so it goes in as a text node and never through innerHTML.
        var $provider = $failure.find( '[data-field="provider-message"]' );
        if ( data.provider_message ) {
            $provider[ 0 ].textContent = String( data.provider_message );
            $provider.prop( 'hidden', false );
        } else {
            $provider.text( '' ).prop( 'hidden', true );
        }

        if ( data.fallback_url ) {
            $m.find( '[data-action="connectors"]' ).attr( 'href', data.fallback_url );
        }

        $failure.prop( 'hidden', false );
        showPanel( 'verify' );
    }

    /**
     * Wire the modal's controls. Delegated so the modal can be rendered late.
     */
    function bindEvents() {
        $( document ).on( 'click', MODAL_ID + ' [data-action="goto-paste"]', function( e ) {
            e.preventDefault();
            showPanel( 'paste' );
        } );

        $( document ).on( 'click', MODAL_ID + ' [data-action="submit"]', function( e ) {
            e.preventDefault();
            submitKey();
        } );

        $( document ).on( 'click', MODAL_ID + ' [data-action="retry-key"]', function( e ) {
            e.preventDefault();
            clearKeyField();
            showPanel( 'paste' );
        } );

        // The stored key survived the failed check, and the field still holds
        // it, so verifying again re-runs the same request rather than asking
        // the user to paste it a second time.
        $( document ).on( 'click', MODAL_ID + ' [data-action="retry-verify"]', function( e ) {
            e.preventDefault();
            submitKey();
        } );

        // The panel already knows it is connected; this click only hands over
        // the conversation, with the suggested prompt waiting in the input.
        $( document ).on( 'click', MODAL_ID + ' [data-action="try-it"]', function( e ) {
            e.preventDefault();
            var prompt = $( this ).data( 'prompt' ) || '';

            // Named before the hide so the close event can say which button
            // ended the modal: a listener that reloads the page here would
            // throw the prompt handoff away.
            closeAction = 'try_it';

            $modal().modal( 'hide' );
            $modal().trigger( 'mainwp-assistant-connected', [ {
                agent_available: agentAvailable,
                suggestedPrompt: prompt,
                action: 'try_it',
                modal_open: modalOpen,
                source: openSource
            } ] );
        } );

        $( document ).on( 'keydown', MODAL_ID + ' #mainwp-assistant-onboarding-key', function( e ) {
            if ( 13 === e.which ) {
                e.preventDefault();
                submitKey();
            }
        } );

        $( document ).on( 'click', MODAL_ID + ' #mainwp-assistant-onboarding-toggle-key', function( e ) {
            e.preventDefault();
            var $button = $( this ),
                $key = $modal().find( '#mainwp-assistant-onboarding-key' ),
                show = 'password' === $key.attr( 'type' );

            $key.attr( 'type', show ? 'text' : 'password' );
            $button
                .attr( 'aria-pressed', show ? 'true' : 'false' )
                .attr( 'aria-label', show ? text( 'onboarding_hide_key', 'Hide key' ) : text( 'onboarding_show_key', 'Show key' ) )
                .find( 'i' ).attr( 'class', show ? 'eye slash icon' : 'eye icon' );
        } );
    }

    $( function() {
        bindEvents();
    } );

    root.MainWPAssistantOnboarding = {
        open: open,
        isAvailable: isAvailable,

        /**
         * Panel currently shown. Exposed for the Node tests and for debugging.
         *
         * @return {string} Panel key.
         */
        currentPanel: function() {
            return currentPanel;
        }
    };

} )( jQuery, window );

( function( $, root ) {
    'use strict';

    var status = {}, busy = false, welcomeOpen = false, pageTitle = null;
    var MODAL = '#mainwp-assistant-chatgpt-modal';

    function config() {
        return ( root.mainwpAssistantSettings && root.mainwpAssistantSettings.chatgpt ) ||
            ( root.mainwpMintlifyChatL10n && root.mainwpMintlifyChatL10n.chatgpt ) || {};
    }

    function text( key ) {
        return ( config().strings || {} )[ key ] || '';
    }

    function request( action, fields ) {
        return $.ajax( {
            url: root.ajaxurl || ( root.mainwpAssistantSettings || {} ).ajaxurl,
            type: 'POST',
            data: root.mainwp_secure_data( $.extend( {}, fields, { action: 'mainwp_assistant_chatgpt_' + action } ) ),
            timeout: 30000
        } );
    }

    function errorText( code ) {
        var errors = config().errors || {};
        return errors[ code ] || errors.generic || '';
    }

    function error( $target, code ) {
        $target.text( errorText( code ) ).prop( 'hidden', false );
    }

    function publish( data, changed ) {
        status = $.extend( {}, status, data );
        renderSettings();
        $( document ).trigger( 'mainwp-assistant-chatgpt-updated', [ status, changed === true ] );
    }

    function button( key, callback, primary, compact ) {
        return $( '<button type="button"></button>' )
            .attr( 'class', 'ui ' + ( compact ? 'mini ' : '' ) + ( primary ? 'green ' : 'basic ' ) + 'button' )
            .text( text( key ) ).on( 'click', callback );
    }

    function connectButton( compact, newRegistration ) {
        var key = status.status === 'plan_disabled' ? 'turn_on' : 'continue';
        return button( key, function() { openConnect( newRegistration ); }, true, compact )
            .attr( 'data-oai-siwc-button', '' );
    }

    function manageLink( compact ) {
        return $( '<a target="_blank" rel="noopener noreferrer"></a>' )
            .attr( 'class', 'ui ' + ( compact ? 'mini ' : '' ) + ( status.connected ? 'green ' : 'basic ' ) + 'button' )
            .attr( 'href', status.manage_usage_url || 'https://chatgpt.com/settings/usage' ).text( text( 'manage' ) );
    }

    // The settings page leaves out the API key choice: its own Google Gemini row
    // below the card knows whether a key is already connected.
    function choices( source, compact, includePlan, includeApi ) {
        var $choices = $( '<div class="mainwp-assistant-connection-choices"></div>' );
        if ( includePlan !== false ) {
            var $plan = $( '<section class="mainwp-assistant-connection-choice"></section>' );
            $plan.append( $( '<h3></h3>' ).text( text( 'plan_title' ) ) );
            if ( status.status === 'needs_reconnect' || status.status === 'plan_disabled' ) {
                $plan.append( $( '<p></p>' ).text( text( status.status === 'plan_disabled' ? 'disabled' : 'reconnect' ) ) );
            }
            if ( config().can_connect === true ) {
                // The settings page is where people read before they start, so
                // the error page the sign-in ends on is no surprise.
                if ( 'settings' === source && ! status.connected ) {
                    $plan.append( signinSteps() );
                }
                $plan.append( connectButton( compact, false ) );
                if ( status.status === 'disconnected' && ( status.email || status.client_id ) ) {
                    $plan.append( button( 'different', function() { openConnect( true ); }, false, compact ) );
                }
            } else {
                $plan.append( $( '<p></p>' ).text( text( 'unavailable' ) ) );
            }
            $plan.append( $( '<p class="mainwp-assistant-connect-note"></p>' ).text( text( 'plan_detail' ) ) );
            $choices.append( $plan );
        }

        if ( includeApi !== false && config().can_manage_settings ) {
            var $api = $( '<section class="mainwp-assistant-connection-choice"></section>' );
            $api.append( $( '<h3></h3>' ).text( text( 'api_title' ) ) );
            var $apiActions = $( '<div class="mainwp-assistant-connect-actions"></div>' );
            if ( root.MainWPAssistantOnboarding && root.MainWPAssistantOnboarding.isAvailable() ) {
                $apiActions.append( button( 'api_connect', function() {
                    $( '#mainwp-assistant-connection-modal' ).modal( 'hide' );
                    root.MainWPAssistantOnboarding.open( source, true );
                }, true, compact ) );
            }
            $apiActions.append( $( '<a></a>' ).attr( 'href', config().connectors_url ).text( text( 'api_alt' ) ) );
            $api.append( $apiActions );
            $api.append( $( '<p class="mainwp-assistant-connect-note"></p>' ).text( text( 'disclosure' ) ) );
            $choices.append( $api );
        }
        return $choices;
    }

    function openChoices( source ) {
        var $modal = $( '#mainwp-assistant-connection-modal' );
        if ( ! $modal.length ) {
            $modal = $( '<div id="mainwp-assistant-connection-modal" class="ui small modal mainwp-assistant-connection-modal" role="dialog" aria-modal="true" aria-labelledby="mainwp-assistant-connection-title"><div class="header" id="mainwp-assistant-connection-title"></div><div class="content"></div><div class="actions"></div></div>' ).appendTo( 'body' );
            $modal.find( '.header' ).text( text( 'choices' ) );
            $modal.find( '.actions' ).append( button( 'close', function() { $modal.modal( 'hide' ); } ) );
        }
        $modal.find( '.content' ).empty().append( choices( source, false ) );
        $modal.modal( 'show' );
    }

    function connectModal() {
        var $modal = $( MODAL );
        if ( $modal.length ) {
            return $modal;
        }
        $modal = $( '<div id="mainwp-assistant-chatgpt-modal" class="ui small modal mainwp-assistant-connection-modal" role="dialog" aria-modal="true" aria-labelledby="mainwp-assistant-chatgpt-title"><div class="header" id="mainwp-assistant-chatgpt-title"></div><div class="content"><div data-step="start"></div><div data-step="paste" hidden><p></p><div class="ui form"><div class="field"><label for="mainwp-assistant-chatgpt-callback"></label><div class="ui fluid action input"><input id="mainwp-assistant-chatgpt-callback" type="text" autocomplete="off" spellcheck="false" autocapitalize="off"><button type="button" class="ui basic button" data-paste hidden></button></div></div></div><p class="mainwp-assistant-chatgpt-reopen" data-reopen hidden><a target="_blank" rel="noopener noreferrer"></a></p></div><div class="ui message" data-notice hidden role="status"></div><div class="ui red message" data-error hidden role="alert"></div></div><div class="actions"></div></div>' ).appendTo( 'body' );
        $modal.find( '.header' ).text( text( 'plan_title' ) );
        // The sign-in ends on a loopback error page, so say so before the user leaves.
        $modal.find( '[data-step="start"]' ).append( $( '<p></p>' ).text( text( 'plan_detail' ) ), signinSteps() );
        $modal.find( '[data-step="paste"] > p:first-child' ).text( text( 'paste_detail' ).replace( '%s', copyKeys() ) );
        $modal.find( 'label' ).text( text( 'callback' ) );
        $modal.find( '[data-reopen] a' ).text( text( 'reopen' ) );
        var clipboard = root.navigator && root.navigator.clipboard;
        var canPaste = !! ( root.isSecureContext && clipboard && typeof clipboard.readText === 'function' );
        // Fomantic squares off an action input's right edge for the button.
        $modal.find( '.ui.input' ).toggleClass( 'action', canPaste );
        $modal.find( '[data-paste]' ).text( text( 'paste' ) ).prop( 'hidden', ! canPaste ).on( 'click', pasteFromClipboard );
        $modal.find( '.actions' ).append(
            button( 'continue', start, true ).attr( 'data-start', '' ).attr( 'data-oai-siwc-button', '' ),
            button( 'finish', complete, true ).attr( 'data-finish', '' ).prop( 'hidden', true ),
            $( '<a href="#" data-restart hidden></a>' ).text( text( 'start_over' ) ).on( 'click', function( event ) {
                event.preventDefault();
                if ( ! busy ) {
                    openConnect( $modal.data( 'new-registration' ) === true );
                }
            } ),
            button( 'close', function() { $modal.modal( 'hide' ); } )
        );
        $modal.find( 'input' ).on( 'keydown', function( event ) {
            if ( event.key === 'Enter' ) {
                event.preventDefault();
                complete();
            }
        } ).on( 'paste', function( event ) {
            var data = ( event.originalEvent || event ).clipboardData;
            var value = data ? data.getData( 'text' ) : '';
            if ( isCallback( value ) ) {
                event.preventDefault();
                $modal.find( 'input' ).val( value.trim() );
                complete();
            }
        } );
        $( root ).on( 'focus', focusPaste );
        $modal.modal( {
            closable: false,
            onHidden: function() {
                // Callback addresses contain single-use credentials.
                $modal.find( 'input' ).val( '' );
                waitTitle( false );
            }
        } );
        return $modal;
    }

    // The account email stays off screen (screen shares, recordings) until
    // the user hovers or clicks to see which account is connected.
    function accountLine() {
        var $line = $( '<p class="mainwp-assistant-plan-account"></p>' ), labelled = false;
        if ( status.connected ) {
            $line.append( $( '<span></span>' ).text( text( 'connected' ) ) );
            labelled = true;
        }
        if ( status.email ) {
            var $reveal = $( '<a href="#" role="button"></a>' ).text( text( 'show_account' ) )
                .attr( 'data-tooltip', status.email ).attr( 'data-inverted', '' ).attr( 'data-position', 'top left' );
            $reveal.on( 'click', function( event ) {
                event.preventDefault();
                $reveal.replaceWith( $( '<span></span>' ).text( status.email ) );
            } );
            if ( labelled ) {
                $line.append( $( '<span></span>' ).text( ' · ' ) );
            }
            $line.append( $reveal );
        }
        return $line;
    }

    function signinSteps() {
        return $( '<ol class="mainwp-assistant-chatgpt-steps"></ol>' ).append(
            $( '<li></li>' ).text( text( 'step_signin' ) ),
            $( '<li></li>' ).text( text( 'step_error' ) ),
            $( '<li></li>' ).text( text( 'step_paste' ).replace( '%s', copyKeys() ) )
        );
    }

    // Name the copy shortcut the reader's keyboard has.
    function copyKeys() {
        var nav = root.navigator || {};
        var platform = ( nav.userAgentData && nav.userAgentData.platform ) || nav.platform || '';
        return /mac|iphone|ipad/i.test( platform ) ? '⌘C' : 'Ctrl+C';
    }

    // While MainWP waits for the address, its tab title says so, which makes
    // the Dashboard easy to find among the tabs the sign-in opened.
    function waitTitle( on ) {
        var doc = root.document;
        if ( ! doc ) {
            return;
        }
        if ( on && pageTitle === null ) {
            pageTitle = doc.title;
            doc.title = text( 'waiting_title' ).replace( '%s', pageTitle );
        } else if ( ! on && pageTitle !== null ) {
            doc.title = pageTitle;
            pageTitle = null;
        }
    }

    // A refused address has usually used up the sign-in attempt, and the
    // refusals tell the user to continue with ChatGPT again, so the button
    // that does that replaces Finish.
    function offerRetry( $modal ) {
        $modal.find( '[data-finish], [data-restart], [data-reopen]' ).prop( 'hidden', true );
        $modal.find( '[data-start]' ).prop( 'hidden', false );
    }

    // Only a quick shape check so obvious mistakes never auto-submit. The
    // server validates the address against the pending sign-in.
    // Copies can carry line breaks, quotes, no http:// or a trailing slash;
    // the server accepts the same variations.
    function pastedUrl( value ) {
        var raw = String( value || '' ).replace( /\s+/g, '' ).replace( /^["'<]+|["'>]+$/g, '' );
        if ( /^127\.0\.0\.1:1456\//.test( raw ) ) {
            raw = 'http://' + raw;
        }
        try { return new URL( raw ); } catch ( e ) { return null; }
    }

    function isCallback( value ) {
        var url = pastedUrl( value );
        return !! url && url.protocol === 'http:' &&
            url.hostname === '127.0.0.1' && url.port === '1456' &&
            url.pathname.replace( /\/+$/, '' ) === '/auth/callback' &&
            url.searchParams.has( 'state' ) && ( url.searchParams.has( 'code' ) || url.searchParams.has( 'error' ) );
    }

    function isSigninPage( value ) {
        var url = pastedUrl( value );
        return !! url && /(^|\.)(openai|chatgpt)\.com$/i.test( url.hostname );
    }

    function pasteFromClipboard() {
        if ( busy ) {
            return;
        }
        var $modal = connectModal(), $input = $modal.find( 'input' );
        root.navigator.clipboard.readText().then( function( value ) {
            // Never copy unrelated clipboard text into the page.
            if ( isCallback( value ) ) {
                $input.val( String( value ).trim() );
                complete();
            } else {
                error( $modal.find( '[data-error]' ), isSigninPage( value ) ? 'signin_address' : 'clipboard_no_callback' );
                $input.trigger( 'focus' );
            }
        }, function() {
            // Clipboard access was refused; the field still takes a normal paste.
            $input.trigger( 'focus' );
        } );
    }

    // Coming back from the ChatGPT tab, the user's next step is pasting.
    function focusPaste() {
        var $modal = $( MODAL );
        if ( busy || ! $modal.length || ! $modal.is( ':visible' ) || $modal.find( '[data-step="paste"]' ).prop( 'hidden' ) ) {
            return;
        }
        if ( ! $modal.find( 'input' ).val() ) {
            $modal.find( 'input' ).trigger( 'focus' );
        }
    }

    function openConnect( newRegistration ) {
        if ( config().can_connect !== true || busy ) {
            return;
        }
        $( '#mainwp-assistant-connection-modal' ).modal( 'hide' );
        var $modal = connectModal();
        $modal.data( 'new-registration', newRegistration === true );
        $modal.find( '[data-step="start"], [data-start]' ).prop( 'hidden', false );
        $modal.find( '[data-step="paste"], [data-finish], [data-restart], [data-error], [data-notice]' ).prop( 'hidden', true );
        $modal.find( 'input' ).val( '' );
        waitTitle( false );
        $modal.modal( 'show' );
    }

    function setBusy( value ) {
        busy = value;
        $( MODAL ).find( 'button, input' ).prop( 'disabled', value );
    }

    function start() {
        if ( busy ) {
            return;
        }
        var $modal = connectModal();
        // Open synchronously so the browser keeps this user-initiated tab.
        var tab = root.open( 'about:blank', '_blank' );
        if ( tab ) {
            tab.opener = null;
        }
        setBusy( true );
        $modal.find( '[data-error], [data-notice]' ).prop( 'hidden', true );
        request( 'start', { new_registration: $modal.data( 'new-registration' ) ? 1 : 0 } )
            .done( function( response ) {
                if ( response && response.data ) {
                    publish( response.data, true );
                }
                if ( ! response || ! response.success || ! response.data || ! response.data.authorize_url ) {
                    if ( tab ) { tab.close(); }
                    error( $modal.find( '[data-error]' ), response && response.data && response.data.error_code );
                    return;
                }
                var url;
                try { url = new URL( response.data.authorize_url ); } catch ( e ) { url = null; }
                if ( ! url || url.origin !== 'https://auth.openai.com' ) {
                    if ( tab ) { tab.close(); }
                    error( $modal.find( '[data-error]' ), 'authorization_failed' );
                    return;
                }
                if ( tab ) {
                    tab.location.href = url.href;
                } else {
                    $modal.find( '[data-error]' ).empty().append(
                        $( '<span></span>' ).text( text( 'popup_blocked' ) + ' ' ),
                        $( '<a target="_blank" rel="noopener noreferrer" data-oai-siwc-button></a>' ).attr( 'href', url.href ).text( text( 'continue' ) )
                    ).prop( 'hidden', false );
                }
                $modal.find( '[data-step="start"], [data-start]' ).prop( 'hidden', true );
                $modal.find( '[data-step="paste"], [data-finish]' ).prop( 'hidden', false );
                $modal.find( '[data-restart]' ).prop( 'hidden', false );
                // For a closed tab. The blocked-tab message already carries this link.
                $modal.find( '[data-reopen] a' ).attr( 'href', url.href );
                $modal.find( '[data-reopen]' ).prop( 'hidden', ! tab );
                waitTitle( true );
                if ( response.data.last_error && response.data.last_error.code === 'revoke_unconfirmed' ) {
                    $modal.find( '[data-notice]' ).text( text( 'revoke' ) ).prop( 'hidden', false );
                }
            } ).fail( function() {
                if ( tab ) { tab.close(); }
                error( $modal.find( '[data-error]' ), 'request_failed' );
            } ).always( function() {
                setBusy( false );
                $modal.find( 'input:visible' ).trigger( 'focus' );
            } );
    }

    function complete() {
        if ( busy || $( MODAL ).find( '[data-step="paste"]' ).prop( 'hidden' ) ) {
            return;
        }
        var $modal = connectModal(), callback = $modal.find( 'input' ).val();
        if ( ! callback || ! callback.trim() ) {
            error( $modal.find( '[data-error]' ), 'empty_callback' );
            $modal.find( 'input' ).trigger( 'focus' );
            return;
        }
        setBusy( true );
        $modal.find( '[data-error]' ).prop( 'hidden', true );
        request( 'complete', { callback: callback } ).done( function( response ) {
            $modal.find( 'input' ).val( '' );
            if ( ! response || ! response.data ) {
                error( $modal.find( '[data-error]' ) );
                offerRetry( $modal );
                return;
            }
            publish( response.data, true );
            if ( ! response.success ) {
                error( $modal.find( '[data-error]' ), response.data.error_code );
                // The sign-in page's address leaves the attempt unused: the user
                // still approves in that tab and pastes the next address.
                if ( 'signin_address' === response.data.error_code ) {
                    $modal.find( 'input' ).trigger( 'focus' );
                } else {
                    offerRetry( $modal );
                }
                return;
            }
            $modal.modal( 'hide' );
            if ( response.data.welcome_pending ) {
                welcome( response.data );
            } else if ( response.data.notice ) {
                noticeModal( response.data.notice );
            }
        } ).fail( function() {
            $modal.find( 'input' ).val( '' );
            error( $modal.find( '[data-error]' ), 'request_failed' );
            offerRetry( $modal );
        } ).always( function() { setBusy( false ); } );
    }

    function noticeModal( notice ) {
        var $modal = $( '<div class="ui small modal mainwp-assistant-connection-modal" role="dialog" aria-modal="true"><div class="header"></div><div class="content"></div><div class="actions"></div></div>' ).appendTo( 'body' );
        $modal.attr( 'aria-label', text( 'plan_title' ) );
        $modal.find( '.header' ).text( text( 'plan_title' ) );
        $modal.find( '.content' ).text( notice );
        $modal.find( '.actions' ).append( button( 'close', function() { $modal.modal( 'hide' ); } ) );
        $modal.modal( { onHidden: function() { $modal.remove(); } } ).modal( 'show' );
    }

    function welcome( data ) {
        if ( ! data.welcome_pending || welcomeOpen ) {
            return;
        }
        welcomeOpen = true;
        var $modal = $( '<div class="ui small modal mainwp-assistant-connection-modal" role="dialog" aria-modal="true" aria-labelledby="mainwp-assistant-chatgpt-welcome-title"><div class="header" id="mainwp-assistant-chatgpt-welcome-title"></div><div class="content"><p></p><div class="ui message" data-notice hidden></div><div class="ui red message" data-error hidden role="alert"></div></div><div class="actions"></div></div>' ).appendTo( 'body' );
        $modal.find( '.header' ).text( text( 'welcome' ) );
        $modal.find( 'p' ).text( text( 'welcome_body' ) );
        if ( data.notice ) {
            $modal.find( '[data-notice]' ).text( data.notice ).prop( 'hidden', false );
        }
        var $gotIt = button( 'got_it', function() {
            $gotIt.prop( 'disabled', true );
            request( 'welcome_seen' ).done( function( response ) {
                if ( response && response.success ) {
                    publish( response.data, false );
                    $modal.modal( 'hide' );
                } else {
                    error( $modal.find( '[data-error]' ), response && response.data && response.data.error_code );
                }
            } ).fail( function() { error( $modal.find( '[data-error]' ), 'request_failed' ); } )
                .always( function() { $gotIt.prop( 'disabled', false ); } );
        }, true );
        $modal.find( '.actions' ).append( $gotIt );
        $modal.modal( { closable: false, onHidden: function() { welcomeOpen = false; $modal.remove(); } } ).modal( 'show' );
    }

    function refreshOnOpen() {
        var $card = $( '#mainwp-assistant-chatgpt-card' );
        return request( 'status' ).done( function( response ) {
            if ( response && response.success ) {
                publish( response.data, false );
                if ( config().can_connect === true ) {
                    welcome( response.data );
                }
            } else {
                var $error = $card.find( '[data-plan-error]' );
                error( $error.length ? $error : $card, response && response.data && response.data.error_code );
            }
        } ).fail( function() {
            var $error = $card.find( '[data-plan-error]' );
            error( $error.length ? $error : $card, 'request_failed' );
        } );
    }

    function renderSettings() {
        var $card = $( '#mainwp-assistant-chatgpt-card' );
        if ( ! $card.length ) {
            return;
        }
        $card.empty();
        var hasPlan = status.connected || status.status === 'needs_reconnect' || status.status === 'plan_disabled';
        if ( hasPlan ) {
            var $plan = $( '<section class="ui segment mainwp-assistant-plan-card"></section>' );
            $plan.append( $( '<h3></h3>' ).text( text( 'plan_title' ) ), accountLine() );
            if ( status.connected ) {
                var $form = $( '<div class="ui form"><div class="field"><label for="mainwp-assistant-chatgpt-model"></label></div></div>' );
                var $models = $( '<select id="mainwp-assistant-chatgpt-model" class="ui dropdown"></select>' );
                $form.find( 'label' ).text( text( 'model' ) );
                ( status.models || [] ).forEach( function( model ) {
                    $models.append( $( '<option></option>' ).val( model.slug ).text( model.display_name || model.slug ) );
                } );
                $models.val( status.model ).prop( 'disabled', ! ( status.models || [] ).length );
                $models.on( 'change', function() {
                    $models.prop( 'disabled', true );
                    mutate( 'set_model', { model: $models.val() } );
                } );
                $form.find( '.field' ).append( $models );
                $plan.append( $form );
            } else {
                $plan.append( $( '<p></p>' ).text( text( status.status === 'plan_disabled' ? 'disabled' : 'reconnect' ) ) );
                if ( config().can_connect ) {
                    $plan.append( connectButton( true, false ) );
                }
            }
            $plan.append( manageLink( true ), button( 'disconnect', function() { mutate( 'disconnect' ); }, false, true ) );
            if ( config().can_connect ) {
                $plan.append( button( 'different', function() { openConnect( true ); }, false, true ) );
            }
            $card.append( $plan );
        }
        $card.append( choices( 'settings', false, ! hasPlan, false ) );
        $card.append( $( '<div class="ui message" data-plan-notice hidden role="status"></div>' ) );
        $card.append( $( '<div class="ui red message" data-plan-error hidden role="alert"></div>' ) );
        $card.find( '.ui.dropdown' ).dropdown();
    }

    function mutate( action, fields ) {
        var $card = $( '#mainwp-assistant-chatgpt-card' );
        $card.find( 'button, select' ).prop( 'disabled', true );
        request( action, fields ).done( function( response ) {
            if ( response && response.data ) {
                publish( response.data, true );
            } else {
                renderSettings();
            }
            // AI Connection renders "Sites mode uses your ChatGPT plan" on the
            // server, so after a disconnect it would contradict the card. A
            // notice the user must read keeps the page.
            if ( 'disconnect' === action && response && response.success && response.data && ! response.data.connected && ! response.data.notice && $( '.mainwp-assistant-site-connection[data-plan-connected="1"]' ).length ) {
                root.location.reload();
                return;
            }
            if ( ! response || ! response.success ) {
                error( $card.find( '[data-plan-error]' ), response && response.data && response.data.error_code );
            }
            if ( response && response.data && response.data.notice ) {
                $card.find( '[data-plan-notice]' ).text( response.data.notice ).prop( 'hidden', false );
            }
        } ).fail( function() {
            renderSettings();
            error( $card.find( '[data-plan-error]' ), 'request_failed' );
        } );
    }

    root.MainWPAssistantChatGPT = {
        choices: choices,
        openChoices: openChoices,
        open: openConnect,
        refreshOnOpen: refreshOnOpen,
        updateSummary: function( data ) { status = $.extend( {}, status, data ); },
        errorText: errorText
    };

    $( function() {
        if ( $( '#mainwp-assistant-chatgpt-card' ).length ) {
            $( '#mainwp-assistant-chatgpt-card' ).text( text( 'loading' ) );
            refreshOnOpen();
        }
    } );
} )( jQuery, window );
