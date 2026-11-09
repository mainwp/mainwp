/**
 * MainWP Assistant onboarding wizard
 *
 * Drives #mainwp-assistant-onboarding-modal: reads onboarding state, walks the
 * Get key / Paste / Verify steps, and posts the key to
 * mainwp_assistant_onboarding_save_key. Every string the modal shows is
 * rendered server-side by MainWP_Assistant_Onboarding::render_wizard_modal();
 * this file only toggles panels and fills in the values a request returns.
 *
 * Knows nothing about the chat: a successful connection is announced as a
 * DOM event on the modal, and mainwp-mintlify-chat.js decides what to do
 * with it.
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
    function open( source ) {
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
