/**
 * MainWP Mintlify Chat Module
 *
 * Native JavaScript chat interface for Mintlify Assistant integration.
 * Handles user input, AJAX communication, message rendering, session management,
 * and batch job polling for long-running operations.
 *
 * @package MainWP/Dashboard
 * @since   5.4
 */

/* global jQuery, ajaxurl, mainwp_secure_data, mainwp_uid, mainwpMintlifyChatL10n, MainWPAssistantChatLogic */

window.MainWPMintlifyChat = ( function( $ ) {
    'use strict';

    // Private variables.
    let $container,
        $messages,
        $input,
        $sendButton,
        sessionFingerprint,
        messageHistory = [],
        isInitialized = false;

    // Mode and control state variables.
    let currentMode = 'ask',          // 'ask' or 'agent'.
        currentProvider = null,
        currentConversationId = null,
        abortController = null,
        lastUserMessage = null,
        lastAssistantMessageId = null,
        thinkingStartTime = null,
        thinkingInterval = null,
        providerCheckComplete = false, // Track if provider availability check has resolved.
        isHistoryDisabled = typeof mainwpMintlifyChatL10n !== 'undefined' && mainwpMintlifyChatL10n.support_history_disabled === true,
        pendingRegeneration = null,    // Track message being regenerated for restore on failure.
        isSending = false,             // Guard to prevent duplicate sendMessage() calls.
        currentChatPhase = null,       // Current async processing phase from poll endpoint.
        safetyTimeoutTriggered = false, // True when the safety timeout aborts the request.
        isConversationLoadInProgress = false,
        autoRestoreAttempted = false,  // Keep automatic restore to a single post-bootstrap attempt.
        isBatchRendering = false,      // Suppresses per-message scrollToBottom() during conversation load.
        pendingConfirmationData = null, // Confirmation payload awaiting a typed or clicked response.
        $activeDocsHandoffOffer = null,
        $docsHandoffUserBubble = null,
        docsHandoffMessage = null,
        docsHandoffAwaitingProvider = null, // Request and receipt of an offer shown without Continue in Sites.
        requestDocsHandoffBypass = false,
        $activeConfirmCard = null,     // Live inline confirmation card; disabled when resolved or superseded.
        budgetState = null,            // Daily spend cap state from the last provider check.
        providerReasonCode = '',       // Why agent mode is unavailable: '' | 'no_provider' | 'wp_version'.
        wizardAvailable = false,       // Whether this user may run the connect wizard.
        chatgptPlan = {};

    // Session cost state: per-bubble unfolded spend keyed by data-usage-key,
    // so the running total is derived (regenerate deletes its entry) rather
    // than accumulated.
    let sessionUsageByKey = {},
        sessionUsageSeq = 0;

    // Mode toggle variables.
    let userModeOverride = null,       // Manual mode selection: null = no session override; 'ask' or 'agent' = selected mode.
        availableProviderName = null,  // null = unknown, false = known absent, string = provider name.
        requestMode = 'ask';           // The pill request: 'ask' | 'agent'. State variable,
                                       // never read back from the DOM. Set only at init restore,
                                       // applyModeChange, and convergence.
                                       // Tested in tests/js/test-mode-guards.js

    // Migrate old preference values from docs/ai rename, then restore.
    // Unknown and missing preferences default to Docs.
    ( function() {
        var saved = localStorage.getItem( 'mainwp_assistant_mode_preference' );
        if ( 'ai' === saved ) {
            localStorage.setItem( 'mainwp_assistant_mode_preference', 'agent' );
            saved = 'agent';
        } else if ( 'docs' === saved ) {
            localStorage.setItem( 'mainwp_assistant_mode_preference', 'ask' );
            saved = 'ask';
        }
        if ( 'agent' !== saved && 'ask' !== saved ) {
            saved = 'ask';
            localStorage.setItem( 'mainwp_assistant_mode_preference', saved );
        }
        requestMode = saved;
        currentMode = saved;
    } )();

    // Batch job tracking variables.
    let activeBatchJob = null,
        batchPollInterval = null,
        batchRetryTimeout = null,
        batchStartTime = null,
        batchRetryCount = 0,
        isPollInFlight = false,
        batchErrorsListSeq = 0; // Unique ids for batch error lists (aria-controls targets).

    // Async chat job tracking variables.
    let activeChatJob = null,
        chatPollInterval = null,
        chatPollQueuedCount = 0,
        chatPollRetryCount = 0,
        isChatPollInFlight = false;

    // SSE (Server-Sent Events) tracking variables. Both timers are tracked so
    // closeSSEConnection() can cancel them. An untracked timer could revive
    // a job that was cancelled or superseded while it was pending.
    let activeSSEConnection = null,
        sseSupported = null,
        sseReconnectCount = 0,
        sseConnectionTimeout = null,
        sseReconnectTimeout = null;

    /**
     * Whether a callback's job is still THE active chat job. Poll, SSE, and
     * fallback callbacks all capture their jobId at schedule time; by the
     * time they fire the user may have started a new job (regenerate, resend)
     * or cleared it (Stop, New Chat), and a stale callback must not settle
     * the new job's state.
     *
     * @param {string} jobId The job the callback was scheduled for.
     * @return {boolean} True when jobId is the current active chat job.
     */
    function isCurrentChatJob( jobId ) {
        return !! ( activeChatJob && activeChatJob.job_id === jobId );
    }

    // Constants.
    const BATCH_POLL_INTERVAL = 5000,       // 5 seconds.
          BATCH_MAX_RETRIES = 3,
          BATCH_TIMEOUT_MS = 30 * 60 * 1000, // 30 minutes client-side timeout.
          CHAT_POLL_MAX_RETRIES = 3,
          CHAT_POLL_TIMEOUT_MS = 5 * 60 * 1000, // 5 minutes client-side timeout for chat jobs.
          CHAT_POLL_QUEUED_FALLBACK = 5,    // After 5 consecutive 'queued' polls, trigger fallback.
          STORAGE_KEY = 'mainwp_assistant_active_job',
          CONVERSATION_KEY = 'mainwp_assistant_conversation_id',
          // SSE configuration.
          SSE_CONNECTION_TIMEOUT = 5000,    // 5 seconds to establish SSE connection.
          SSE_MAX_RECONNECTS = 2;           // Max reconnection attempts before falling back to polling.

    /**
     * Get localized string with fallback.
     *
     * @param {string} key      The localization key.
     * @param {string} fallback Fallback string if key not found.
     * @return {string} Localized string.
     */
    function l10n( key, fallback ) {
        if ( typeof mainwpMintlifyChatL10n !== 'undefined' && mainwpMintlifyChatL10n[ key ] ) {
            return mainwpMintlifyChatL10n[ key ];
        }
        return fallback || key;
    }

    // classifyAckLocally() lives in mainwp-assistant-chat-logic.js (loaded as a
    // script dependency before this file) so the browser and the Node test
    // suite share one copy. See MainWPAssistantChatLogic.classifyAckLocally.
    var classifyAckLocally = MainWPAssistantChatLogic.classifyAckLocally;

    /**
     * Persist the active conversation ID for cross-page restore.
     *
     * @param {?number|string} id Conversation ID, or null to clear.
     */
    function setCurrentConversation( id ) {
        currentConversationId = id;

        try {
            if ( id ) {
                localStorage.setItem( CONVERSATION_KEY, String( id ) );
            } else {
                localStorage.removeItem( CONVERSATION_KEY );
            }
        } catch ( e ) {
            // localStorage unavailable, degrade silently.
        }
    }

    /**
     * Build the welcome/empty-state HTML.
     *
     * @return {string} Welcome message HTML.
     */
    function getWelcomeHtml() {
        return '<div class="mainwp-mintlify-welcome">' +
                   '<div class="mainwp-mintlify-welcome-icon">' +
                       '<i class="comments outline icon"></i>' +
                   '</div>' +
                   '<div class="mainwp-mintlify-welcome-text">' +
                       escapeHtml( l10n( 'welcome_title', 'Welcome to MainWP Assistant' ) ) +
                   '</div>' +
                   '<div class="mainwp-mintlify-welcome-subtext">' +
                       escapeHtml( l10n( 'welcome_subtext', 'Ask me anything about MainWP' ) ) +
                   '</div>' +
               '</div>';
    }

    /**
     * Determine whether the chat UI is still at its initial welcome state.
     *
     * @return {boolean} True if no conversation content has been rendered yet.
     */
    function isWelcomeState() {
        return !!( $messages && $messages.length && messageHistory.length === 0 && $messages.find( '.mainwp-mintlify-welcome' ).length );
    }

    /**
     * Attempt to restore the last active conversation after provider bootstrap settles.
     */
    function attemptConversationRestore() {
        if ( autoRestoreAttempted ) {
            return;
        }

        autoRestoreAttempted = true;

        if ( currentConversationId || isHistoryDisabled || isConversationLoadInProgress || ! isWelcomeState() ) {
            return;
        }

        try {
            let savedConversationId = parseInt( localStorage.getItem( CONVERSATION_KEY ), 10 );

            if ( ! Number.isInteger( savedConversationId ) || savedConversationId <= 0 ) {
                return;
            }

            loadConversation( savedConversationId, {
                silent: true
            } );
        } catch ( e ) {
            // localStorage unavailable, degrade silently.
        }
    }

    /**
     * Initialize the chat interface.
     *
     * Caches DOM elements, binds events, generates session fingerprint,
     * displays welcome message, and checks for active batch jobs.
     */
    function init() {
        if ( isInitialized ) {
            if ( window.MainWPAssistantChatGPT ) {
                window.MainWPAssistantChatGPT.refreshOnOpen();
            }
            checkProviderAvailability();
            return;
        }

        $container  = $( '#mainwp-mintlify-chat-container' );
        $messages   = $( '#mainwp-mintlify-messages' );
        $input      = $( '#mainwp-mintlify-input' );
        $sendButton = $( '#mainwp-mintlify-send' );

        if ( ! $container.length || ! $messages.length ) {
            return;
        }

        $sendButton.off( 'click.mintlify' ).on( 'click.mintlify', function( e ) {
            e.preventDefault();
            e.stopPropagation();
            sendMessage();
        } );

        $input.off( 'keydown.mintlify' ).on( 'keydown.mintlify', function( e ) {
            // Enter sends message, Shift+Enter adds new line.
            if ( e.key === 'Enter' && ! e.shiftKey ) {
                e.preventDefault();
                sendMessage();
            }
        } );

        $input.off( 'input.mintlify' ).on( 'input.mintlify', function() {
            this.style.height = 'auto';
            this.style.height = Math.min( this.scrollHeight, 120 ) + 'px';
        } );

        $messages.off( 'click.mintlify-regen' ).on( 'click.mintlify-regen', '.mainwp-assistant-regenerate-btn', function( e ) {
            e.preventDefault();
            handleRegenerate();
        } );

        // Retry uses a plain send because a failed request persists nothing on
        // either path: the user message still needs storing, whereas regeneration
        // replaces a stored answer.
        $messages.off( 'click.mintlify-retry' ).on( 'click.mintlify-retry', '.mainwp-assistant-error-retry', function( e ) {
            e.preventDefault();
            if ( $( this ).hasClass( 'mainwp-assistant-error-retry-waiting' ) ) {
                return;
            }
            var retryMessage = $( this ).data( 'retry-message' ) || lastUserMessage;
            if ( retryMessage && ! $input.prop( 'disabled' ) ) {
                sendMessage( retryMessage, false, undefined, { docsHandoffBypass: $( this ).attr( 'data-docs-bypass' ) === '1' } );
            }
        } );

        // Wizard anchors are built as HTML strings inside error bubbles, so the
        // click is delegated rather than bound at build time.
        $messages.off( 'click.mintlify-wizard' ).on( 'click.mintlify-wizard', '.mainwp-assistant-wizard-link', function( e ) {
            e.preventDefault();
            if ( wizardReady() ) {
                window.MainWPAssistantOnboarding.open( 'error_bubble' );
            }
        } );

        $( document ).off( 'mainwp-assistant-chatgpt-updated.mintlify' ).on( 'mainwp-assistant-chatgpt-updated.mintlify', function( event, status, changed ) {
            chatgptPlan = status || {};
            updatePlanIndicator();
            if ( changed ) {
                $messages.find( '.mainwp-assistant-connect-card' ).closest( '.mainwp-mintlify-message' ).not( '.mainwp-assistant-handoff-offer' ).remove();
                checkProviderAvailability();
            }
        } );

        // The wizard stored a key, so the connect card is stale either way.
        // Everything else waits on agent_available: a key this page load
        // cannot use yet would leave the pill promising a mode that answers
        // in Docs.
        $( document ).off( 'mainwp-assistant-connected.mintlify' ).on( 'mainwp-assistant-connected.mintlify', function( e, detail ) {
            detail = detail || {};

            $messages.find( '.mainwp-assistant-connect-card' ).closest( '.mainwp-mintlify-message' ).remove();

            if ( true !== detail.agent_available ) {
                return;
            }

            wizardAvailable = false;
            providerReasonCode = '';
            // Unknown rather than a guessed name: the next turn's receipt
            // reports which provider actually answered.
            availableProviderName = null;
            setAgentPillUnavailable( false );
            applyModeChange( 'agent' );

            if ( detail.suggestedPrompt && ! $input.prop( 'disabled' ) ) {
                $input.val( detail.suggestedPrompt ).trigger( 'input.mintlify' ).trigger( 'focus' );
            }
        } );

        $messages.off( 'click.mintlify-copy' ).on( 'click.mintlify-copy', '.mainwp-assistant-copy-btn', function( e ) {
            e.preventDefault();
            var $btn = $( this );
            if ( $btn.prop( 'disabled' ) ) {
                return;
            }
            var focusTarget = document.activeElement === $btn[0] ? $btn[0] : null;
            clearTimeout( $btn.data( 'copy-reset' ) );
            var $msg = $btn.closest( '.mainwp-mintlify-assistant-message' );
            var $clone = $msg.find( '.message-content' ).clone();

            // Strip UI chrome, keeping only the answer text.
            $clone.find( '.mainwp-assistant-cost-line, .mainwp-mintlify-sources, .mainwp-mintlify-suggestions, .mainwp-assistant-truncated-notice' ).remove();
            $clone.find( 'br' ).replaceWith( '\n' );
            $clone.find( 'p, li, pre, h1, h2, h3, h4, h5, h6, blockquote' ).append( '\n' );

            var text = $clone.text().trim();
            var $status = $btn.siblings( '.mainwp-assistant-copy-status' );
            if ( ! $status.length ) {
                $status = $( '<span class="mainwp-assistant-copy-status" role="status"></span>' );
                $btn.after( $status );
            }
            $status.text( '' );
            $btn.removeClass( 'copied' ).prop( 'disabled', true );

            function fallbackCopy() {
                if ( document.activeElement !== document.body ) {
                    focusTarget = document.activeElement;
                }
                var $temp = $( '<textarea readonly aria-hidden="true"></textarea>' );
                $temp.css( { position: 'fixed', left: '-9999px', top: '0' } );
                $temp.val( text ).appendTo( 'body' ).trigger( 'focus' ).trigger( 'select' );
                try {
                    if ( ! document.execCommand( 'copy' ) ) {
                        throw new Error( 'Copy command failed' );
                    }
                } finally {
                    $temp.remove();
                }
            }

            var write;
            try {
                write = navigator.clipboard && typeof navigator.clipboard.writeText === 'function'
                    ? navigator.clipboard.writeText( text )
                    : Promise.reject( new Error( 'Clipboard API unavailable' ) );
            } catch ( error ) {
                write = Promise.reject( error );
            }
            Promise.resolve( write ).catch( fallbackCopy ).then( function() {
                $btn.prop( 'disabled', false ).addClass( 'copied' );
                if ( focusTarget && document.activeElement === document.body ) {
                    focusTarget.focus();
                }
                $status.text( l10n( 'copy_success', 'Copied!' ) );
                $btn.data( 'copy-reset', setTimeout( function() {
                    $btn.removeClass( 'copied' );
                    $status.text( '' );
                }, 1500 ) );
            }, function() {
                $btn.prop( 'disabled', false );
                if ( focusTarget && document.activeElement === document.body ) {
                    focusTarget.focus();
                }
                $status.text( l10n( 'copy_failed', 'Copy failed. Select the response text and copy it manually.' ) );
            } );
        } );

        $( '#mainwp-mintlify-disclosure-dismiss' ).off( 'click.mintlify' ).on( 'click.mintlify', function() {
            dismissDisclosure();
        } );

        // Batch errors disclosure toggle (delegated, because the lists render into
        // the message stream long after init). A real <button>, so keyboard
        // activation arrives as a click; only aria-expanded needs syncing.
        $( document ).off( 'click.mintlify', '.mainwp-batch-errors-header' );
        $( document ).on( 'click.mintlify', '.mainwp-batch-errors-header', function() {
            let $header = $( this );
            let expanded = $header.attr( 'aria-expanded' ) === 'true';
            $( '#' + $header.attr( 'aria-controls' ) ).toggle( ! expanded );
            $header.attr( 'aria-expanded', expanded ? 'false' : 'true' );
        } );

        sessionFingerprint = mainwp_uid();

        messageHistory = [];

        isInitialized = true;

        checkForActiveJob();

        checkFirstUseDisclosure();

        maybeShowModesIntro();

        // Initialize pill toggle click handlers. Default mode is 'ask'.
        // checkProviderAvailability() restores the saved preference after completing,
        // so we don't read localStorage here (it would trigger an unnecessary revert).
        $( '.mainwp-mode-pill' ).on( 'click.mintlify', function() {
            var mode = $( this ).data( 'mode' );

            // Update active state on pills; applyModeChange() reverts via
            // syncPillToggle() when the switch is guarded off.
            syncPillToggle( mode );

            applyModeChange( mode );
        } );

        // Arrow-key movement across the mode pills completes the WAI-ARIA
        // radiogroup pattern: Left/Up select the previous pill, Right/Down the
        // next, Home/End the ends. Selection follows focus, mirroring a click
        // (needs-setup pills stay reachable; pills disabled during a request
        // are skipped).
        $( '.mainwp-mode-pill' ).on( 'keydown.mintlify', function( e ) {
            var key = e.key;
            if ( 'ArrowRight' !== key && 'ArrowLeft' !== key && 'ArrowUp' !== key && 'ArrowDown' !== key && 'Home' !== key && 'End' !== key ) {
                return;
            }

            var $pills = $( '.mainwp-mode-pill' ).filter( function() {
                return ! $( this ).prop( 'disabled' );
            } );
            var currentIndex = $pills.index( this );
            if ( $pills.length < 2 || -1 === currentIndex ) {
                return;
            }

            e.preventDefault();

            var newIndex = currentIndex;
            if ( 'ArrowRight' === key || 'ArrowDown' === key ) {
                newIndex = ( currentIndex + 1 ) % $pills.length;
            } else if ( 'ArrowLeft' === key || 'ArrowUp' === key ) {
                newIndex = ( currentIndex - 1 + $pills.length ) % $pills.length;
            } else if ( 'Home' === key ) {
                newIndex = 0;
            } else if ( 'End' === key ) {
                newIndex = $pills.length - 1;
            }

            if ( newIndex === currentIndex ) {
                return;
            }

            var mode = $pills.eq( newIndex ).data( 'mode' );
            syncPillToggle( mode );
            applyModeChange( mode );
            $pills.eq( newIndex ).trigger( 'focus' );
        } );

        syncPillToggle( requestMode );
        updateInfoBar( requestMode );

        checkProviderAvailability();
        if ( window.MainWPAssistantChatGPT ) {
            window.MainWPAssistantChatGPT.refreshOnOpen();
        }

        $input.focus();
    }

    /**
     * Check for and resume active batch job from localStorage.
     */
    function checkForActiveJob() {
        let storedJob = localStorage.getItem( STORAGE_KEY );
        if ( storedJob ) {
            try {
                let jobData = JSON.parse( storedJob );
                if ( jobData && jobData.job_id ) {
                    resumeBatchJob( jobData );
                }
            } catch ( e ) {
                // Invalid stored data, clear it.
                localStorage.removeItem( STORAGE_KEY );
            }
        }
    }

    /**
     * Resume a batch job after page refresh.
     *
     * @param {Object} jobData Stored job data.
     */
    function resumeBatchJob( jobData ) {
        if ( jobData.conversation_id ) {
            setCurrentConversation( jobData.conversation_id );
        }

        appendSystemMessage( l10n( 'batch_resumed', 'Resuming batch operation...' ) );

        activeBatchJob = {
            job_id: jobData.job_id,
            total: jobData.total || 0,
            poll_endpoint: jobData.poll_endpoint || 'mainwp_assistant_poll_batch_job',
            poll_interval: jobData.poll_interval || BATCH_POLL_INTERVAL
        };
        batchStartTime = jobData.startTime || Date.now();

        setInputState( false );

        appendBatchProgressMessage( activeBatchJob );

        pollBatchStatus( jobData.job_id );

        startPolling( jobData.job_id );

        // Show stop button so user can cancel the resumed batch.
        setSendButtonStop();
    }

    /**
     * Send user message.
     *
     * Validates input, displays user message, and makes AJAX request.
     *
     * @param {string}  messageOverride Optional message to send instead of input value (for regenerate).
     * @param {boolean} isRegenerate    Whether this is a regeneration request.
     * @param {string}  modeOverride    Optional one-shot mode for this request (escalation).
     * @param {Object}  options         Optional Docs handoff bypass.
     */
    function sendMessage( messageOverride, isRegenerate, modeOverride, options ) {
        clearDocsHandoffOffer();
        // Prevent duplicate sends (e.g. rapid clicks, event propagation).
        if ( isSending ) {
            return;
        }

        let userMessage = messageOverride || $input.val().trim();

        if ( ! userMessage ) {
            return;
        }

        isSending = true;

        // Client-side only; there is no server-side length gate behind it. The number is
        // duplicated in the error_message_too_long string
        // (class-mainwp-system.php), so change both together.
        if ( userMessage.length > 2000 ) {
            appendErrorMessage( l10n( 'error_message_too_long', 'Message is too long. Please keep it under 2000 characters.' ) );
            isSending = false; // Reset guard so user can retry.
            return;
        }

        // Typed response to a pending confirmation: an affirmative executes the
        // pending action through the same confirm endpoint the Proceed button
        // uses, a negative cancels it, and anything else abandons it and sends
        // normally. Regeneration/escalation overrides bypass the intercept.
        if ( pendingConfirmationData && ! messageOverride ) {
            let ack = classifyAckLocally( userMessage );
            if ( 'affirmative' === ack ) {
                let confirmData = pendingConfirmationData;
                appendUserMessage( userMessage );
                if ( ! isHistoryDisabled ) {
                    messageHistory.push( { role: 'user', content: userMessage } );
                }
                $input.val( '' ).css( 'height', 'auto' );
                isSending = false;
                executeConfirmedAction( confirmData );
                return;
            }
            if ( 'negative' === ack ) {
                let cancelData = pendingConfirmationData;
                appendUserMessage( userMessage );
                if ( ! isHistoryDisabled ) {
                    messageHistory.push( { role: 'user', content: userMessage } );
                }
                $input.val( '' ).css( 'height', 'auto' );
                resolveActiveConfirmCard();
                // persistCancellation() renders the outcome notice from the
                // server's answer.
                persistCancellation( cancelData, userMessage );
                isSending = false;
                setInputState( true );
                return;
            }
            resolveActiveConfirmCard();
        }

        // Keep the prompt for retries and regeneration.
        lastUserMessage = userMessage;

        hideRegenerateLinks();

        setInputState( false );

        $messages.find( '.mainwp-mintlify-welcome' ).remove();

        // Sending a first message retires the mode explainer for good.
        dismissModesIntro( true );

        if ( ! messageOverride ) {
            appendUserMessage( userMessage );

            if ( ! isHistoryDisabled ) {
                messageHistory.push( {
                    role: 'user',
                    content: userMessage
                } );
            }
        }

        $input.val( '' ).css( 'height', 'auto' );

        sendToAPI( userMessage, isRegenerate, modeOverride, options );
    }

    /**
     * Send message to API via AJAX.
     *
     * Supports AbortController for stopping generation and tracks usage/cost data.
     *
     * @param {string}  userMessage   The user's message.
     * @param {boolean} isRegenerate  Whether this is a regeneration request.
     * @param {string}  modeOverride  Optional one-shot mode for this request (escalation).
     * @param {Object}  options       Optional Docs handoff bypass.
     */
    function sendToAPI( userMessage, isRegenerate, modeOverride, options ) {
        safetyTimeoutTriggered = false;

        abortController = new AbortController();

        setSendButtonStop();

        startThinkingIndicator();

        let data = {
            action: 'mainwp_mintlify_chat',
            message: userMessage,
            fp: sessionFingerprint
        };

        // Always send mode so the server never has to guess. requestMode carries the
        // active pill ('ask' | 'agent'), restored from localStorage at module
        // init so it is correct even before the async provider check resolves; an
        // escalation passes a one-shot modeOverride without touching pill state.
        // Tested in tests/js/test-mode-guards.js
        data.mode = modeOverride || requestMode;

        // Debug: log the mode being sent (visible in browser console when WP_DEBUG is on).
        if ( window.console && window.mainwp_assistant_debug ) {
            console.log( '[Assistant] Sending mode:', data.mode );
        }

        // Include conversation ID if continuing an existing conversation.
        // Skip if history is disabled to avoid sending stale IDs in opt-out sessions.
        if ( currentConversationId && ! isHistoryDisabled ) {
            data.conversation_id = currentConversationId;
        }

        // Include regenerate flag to signal backend to replace last response.
        if ( isRegenerate ) {
            data.regenerate = true;
            if ( lastAssistantMessageId ) {
                data.regenerate_message_id = lastAssistantMessageId;
            }
        }

        requestDocsHandoffBypass = !! ( data.mode === 'ask' && options && options.docsHandoffBypass === true );
        if ( requestDocsHandoffBypass ) {
            data.docs_handoff_bypass = 'true';
        }

        data = mainwp_secure_data( data );

        let ajaxRequest = $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: data,
            beforeSend: function( jqXHR ) {
                abortController.signal.addEventListener( 'abort', function() {
                    jqXHR.abort();
                } );
            }
        } );

        ajaxRequest.done( function( response ) {
            if ( response && response.success ) {
                if ( response.data.handoff_offer === true ) {
                    stopThinkingIndicator();
                    setSendButtonDefault();
                    abortController = null;
                    restoreRegenerationOriginal();
                    setInputState( true );
                    renderDocsHandoffOffer( userMessage, response.data );
                    return;
                }

                // Set before the early returns below, so batch jobs and confirmations
                // still get their conversation continuity.
                if ( response.data.conversation_id ) {
                    setCurrentConversation( response.data.conversation_id );
                }

                // Also before the early returns, so the opt-out state applies to batch jobs
                // and confirmations. The false branch restores controls after a prior opt-out.
                if ( response.data.history_disabled === true ) {
                    handleHistoryDisabled();
                } else if ( response.data.history_disabled === false ) {
                    restoreHistoryControls();
                }

                // Before the early returns for the same reason. Opt-out users never see the
                // first-use disclosure.
                if ( response.data.show_disclosure === true && ! isHistoryDisabled ) {
                    let dismissed = localStorage.getItem( 'mainwp_assistant_disclosure_dismissed' );
                    if ( ! dismissed ) {
                        $( '#mainwp-mintlify-disclosure' ).show();
                    }
                }

                // Parse token usage for inline display inside the message block.
                let pendingMessageTokens = parseUsageFromResponse( response.data );

                // Update mode indicator BEFORE early returns.
                // The pills are the request; the badge is the receipt. When the
                // server refused an explicit Agent request and answered from docs,
                // converge the UI instead of letting the badge repeat the request.
                // Tested in tests/js/test-mode-guards.js
                if ( isAgentRefusal( response.data, userModeOverride ) ) {
                    convergeAfterAgentRefusal();
                } else if ( userModeOverride ) {
                    updateModeIndicator( userModeOverride, userModeOverride === 'agent' ? availableProviderName : null );
                } else if ( response.data.mode === 'ask' ) {
                    updateModeIndicator( 'ask', null );
                } else if ( response.data.provider_name ) {
                    // Retain provider name so the toggle can switch back to Agent
                    // even when the initial provider check failed.
                    availableProviderName = response.data.provider_name;
                    updateModeIndicator( 'agent', response.data.provider_name );
                } else if ( response.data.provider ) {
                    availableProviderName = response.data.provider;
                    updateModeIndicator( 'agent', response.data.provider );
                }

                // Debug: log route tracing info from server.
                if ( response.data && response.data._debug_route && window.console && window.mainwp_assistant_debug ) {
                    console.log( '[Assistant] Route debug:', response.data._debug_route );
                }

                // Check for async chat response (agent mode offloaded to WP-Cron).
                // Keep the thinking indicator running; polling updates the phase text.
                if ( response.data.async_chat === true ) {
                    abortController = null;
                    handleAsyncChat( response.data );
                    return;
                }

                // Non-async paths: stop thinking indicator now.
                stopThinkingIndicator();
                setSendButtonDefault();

                lastAssistantMessageId = response.data.message_id || null;

                if ( response.data.batch_job === true ) {
                    abortController = null;
                    // Finalize any pending regeneration; the batch result replaces the message.
                    finalizeRegeneration();
                    handleBatchJob( response.data );
                    if ( pendingMessageTokens ) {
                        showMessageCost( pendingMessageTokens );
                    }
                    return;
                }

                if ( response.data.requires_confirmation === true ) {
                    abortController = null;
                    // Finalize any pending regeneration; the confirmation flow continues the conversation.
                    finalizeRegeneration();
                    handleConfirmationRequired( response.data );
                    if ( pendingMessageTokens ) {
                        showMessageCost( pendingMessageTokens );
                    }
                    return;
                }

                if ( response.data.error === 'policy_denied' ) {
                    restoreRegenerationOriginal();
                    showPolicyWarning( 'readonly', response.data );
                    setInputState( true );
                    $input.focus();
                    return;
                }

                let responseText = response.data.response || '';
                let sources = response.data.sources || [];

                appendAssistantMessage( responseText, sources, pendingMessageTokens, response.data.executed_abilities || [], undefined, response.data.truncated === true, response.data.truncated_fields, response.data.feedback );

                // handleHistoryDisabled() already ran above when the server reported the
                // opt-out, so the flag is current by the time this reads it.
                if ( ! isHistoryDisabled ) {
                    messageHistory.push( {
                        role: 'assistant',
                        content: responseText
                    } );
                }

                // Finalize any pending regeneration and remove the hidden original.
                finalizeRegeneration();

                // Show regenerate button when we have a message to regenerate.
                if ( lastUserMessage && resolveRegenerateAvailability( lastAssistantMessageId, isHistoryDisabled ) ) {
                    showRegenerateLink();
                }

                setInputState( true );
                $input.focus();
            } else {
                stopThinkingIndicator();
                setSendButtonDefault();

                // PHP uses wp_send_json() with top-level error field, but also check response.data
                // for compatibility with wp_send_json_error() format.
                let errorCode = null;
                let errorMessage = null;

                if ( response ) {
                    // Check top-level fields first (wp_send_json format used by
                    // handle_chat_request). error_code is the machine-readable
                    // code; the legacy error field carries the message there.
                    errorCode = response.error_code || response.error || null;
                    errorMessage = response.message || null;
                    if ( ! errorMessage && response.error_code && response.error ) {
                        errorMessage = response.error;
                    }

                    // Fall back to response.data fields (wp_send_json_error format).
                    if ( ! errorCode && response.data ) {
                        errorCode = response.data.error_code || response.data.error || null;
                    }
                    if ( ! errorMessage && response.data ) {
                        errorMessage = response.data.message || null;
                    }
                }

                // A policy denial gets the policy warning, not the generic error.
                if ( errorCode === 'policy_denied' ) {
                    restoreRegenerationOriginal();
                    showPolicyWarning( 'readonly', response.data );
                    setInputState( true );
                    $input.focus();
                    return;
                }

                // Debug: log route tracing info from error response.
                if ( response && response._debug_route && window.console && window.mainwp_assistant_debug ) {
                    console.log( '[Assistant] Route debug (error):', response._debug_route );
                }

                // Prefer the server message, then the error code, then the generic string.
                let errorText = errorMessage || errorCode || l10n( 'error_generic', 'An error occurred. Please try again.' );
                appendErrorMessage( errorText, errorCode, ( response && response.data ) || response );

                // Restore original message if this was a failed regeneration.
                restoreRegenerationOriginal();

                setInputState( true );
                $input.focus();
            }

            abortController = null;
        } );

        ajaxRequest.fail( function( jqXHR, textStatus ) {
            stopThinkingIndicator();

            setSendButtonDefault();

            abortController = null;

            if ( textStatus === 'abort' ) {
                if ( safetyTimeoutTriggered ) {
                    // Safety timeout triggered the abort, so show the timeout error.
                    appendErrorMessage( l10n( 'chat_timeout', 'The request timed out. The server may still be working on it, so reload the page to check.' ) );
                } else {
                    // User clicked stop, so show the stopped message.
                    appendSystemMessage( l10n( 'generation_stopped', 'Response generation stopped.' ) );
                }

                // Restore original message if this was an aborted regeneration.
                restoreRegenerationOriginal();

                setInputState( true );
                $input.focus();
                return;
            }

            // Log technical details. The full jqXHR (response body included) only under debug.
            console.error( 'MainWP Mintlify Chat Error:', textStatus );
            if ( window.console && window.mainwp_assistant_debug ) {
                console.error( 'MainWP Mintlify Chat Error details:', jqXHR );
            }

            /**
             * Transport failure error path: the response never reached the
             * client. Show the connection error and put the typed message
             * back into the input so retry is one action (plain sends only;
             * regenerate restores via restoreRegenerationOriginal()).
             * Tested in tests/js/test-mode-guards.js
             */
            function showTransportFailure() {
                appendErrorMessage( l10n( 'error_connection', 'Unable to connect. Please check your connection and try again.' ) );
                restoreRegenerationOriginal();
                let restoredInput = resolveInputRestoreOnTransportFailure( isRegenerate, userMessage );
                if ( restoredInput && ! $input.val() ) {
                    $input.val( restoredInput );
                }
                setInputState( true );
                $input.focus();
            }

            // The docs turn stores both rows BEFORE the response travels back,
            // so a dropped response can mean the answer already exists
            // server-side. When eligible, check the conversation tail and
            // render the stored answer instead of the error.
            // Tested in tests/js/test-mode-guards.js
            if ( resolveTransportRecoveryEligibility( currentConversationId, isHistoryDisabled, isRegenerate ) ) {
                fetchConversationTail( currentConversationId )
                    .done( function( response ) {
                        let tail = [];
                        if ( response && response.success && response.data && Array.isArray( response.data.messages ) ) {
                            tail = response.data.messages.slice( -2 );
                        }
                        if ( resolveTransportFailureRecovery( tail, userMessage ) === 'recover' ) {
                            let answer = tail[ tail.length - 1 ];
                            appendAssistantMessage( answer.content, normalizeStoredSources( answer.sources ), null, answer.executed_abilities || [], answer.created_at, answer.truncated === true, answer.truncated_fields, answer.feedback );
                            if ( ! isHistoryDisabled ) {
                                messageHistory.push( { role: 'assistant', content: answer.content } );
                            }
                            setInputState( true );
                            $input.focus();
                        } else {
                            showTransportFailure();
                        }
                    } )
                    .fail( showTransportFailure );
                return;
            }

            showTransportFailure();
        } );
    }

    // resolveTransportFailureRecovery(), resolveTransportRecoveryEligibility(),
    // resolveInputRestoreOnTransportFailure(), and normalizeStoredSources()
    // live in mainwp-assistant-chat-logic.js (loaded as a script dependency
    // before this file) so the browser and the Node test suite
    // (tests/js/test-mode-guards.js) share one copy.
    var resolveTransportFailureRecovery = MainWPAssistantChatLogic.resolveTransportFailureRecovery;
    var resolveTransportRecoveryEligibility = MainWPAssistantChatLogic.resolveTransportRecoveryEligibility;
    var resolveRegenerateAvailability = MainWPAssistantChatLogic.resolveRegenerateAvailability;
    var resolveInputRestoreOnTransportFailure = MainWPAssistantChatLogic.resolveInputRestoreOnTransportFailure;
    var normalizeStoredSources = MainWPAssistantChatLogic.normalizeStoredSources;

    /**
     * Fetch a conversation's messages for tail reconciliation without the UI
     * side effects of loadConversation() (which wipes $messages and
     * messageHistory and mutates the mode indicator).
     *
     * @param {number} conversationId Conversation to fetch.
     * @return {Object} jQuery jqXHR promise.
     */
    function fetchConversationTail( conversationId ) {
        let data = {
            action: 'mainwp_assistant_load_conversation',
            conversation_id: conversationId
        };
        data = mainwp_secure_data( data );
        // Bounded timeout: the input stays disabled while the reconciliation
        // fetch is in flight, so a hung request must not lock the chat.
        return $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: data,
            timeout: 10000
        } );
    }

    // =========================================================================
    // BATCH JOB HANDLING
    // =========================================================================

    /**
     * Handle batch job response - start client-side polling.
     *
     * @param {Object} jobData Batch job data from server.
     */
    function handleBatchJob( jobData ) {
        // Defensive: sendToAPI should already have stored the conversation_id.
        if ( jobData.conversation_id && ! currentConversationId ) {
            setCurrentConversation( jobData.conversation_id );
        }

        activeBatchJob = {
            job_id: jobData.job_id,
            total: jobData.total || 0,
            poll_endpoint: jobData.poll_endpoint || 'mainwp_assistant_poll_batch_job',
            poll_interval: jobData.poll_interval || BATCH_POLL_INTERVAL
        };
        batchStartTime = Date.now();
        batchRetryCount = 0;

        // Persist to localStorage for page refresh resilience (including polling config).
        localStorage.setItem( STORAGE_KEY, JSON.stringify( {
            job_id: jobData.job_id,
            total: jobData.total,
            conversation_id: jobData.conversation_id || currentConversationId || null,
            poll_endpoint: activeBatchJob.poll_endpoint,
            poll_interval: activeBatchJob.poll_interval,
            startTime: batchStartTime
        } ) );

        let responseText = jobData.response || l10n( 'batch_processing', 'Processing batch operation...' );
        if ( jobData.response ) {
            appendAssistantMessage( responseText, [], null, jobData.executed_abilities || [] );
        }

        if ( ! isHistoryDisabled ) {
            messageHistory.push( {
                role: 'assistant',
                content: responseText
            } );
        }

        appendBatchProgressMessage( jobData );

        startPolling( jobData.job_id );

        // Keep stop button visible during polling so user can cancel.
        setSendButtonStop();
    }

    /**
     * Start polling interval for batch job status.
     *
     * Uses server-provided interval from activeBatchJob, falling back to default.
     *
     * @param {string} jobId The batch job ID.
     */
    function startPolling( jobId ) {
        if ( batchPollInterval ) {
            clearInterval( batchPollInterval );
        }

        let interval = ( activeBatchJob && activeBatchJob.poll_interval ) || BATCH_POLL_INTERVAL;

        batchPollInterval = setInterval( function() {
            if ( batchStartTime && ( Date.now() - batchStartTime > BATCH_TIMEOUT_MS ) ) {
                handleBatchTimeout();
                return;
            }

            pollBatchStatus( jobId );
        }, interval );
    }

    /**
     * Poll batch job status from server.
     *
     * Prevents concurrent polls by tracking in-flight status.
     * Uses server-provided endpoint from activeBatchJob.
     *
     * @param {string} jobId The batch job ID.
     */
    function pollBatchStatus( jobId ) {
        // Prevent concurrent polls (race condition fix).
        if ( isPollInFlight ) {
            return;
        }
        isPollInFlight = true;

        let endpoint = ( activeBatchJob && activeBatchJob.poll_endpoint ) || 'mainwp_assistant_poll_batch_job';

        let data = {
            action: endpoint,
            job_id: jobId
        };

        data = mainwp_secure_data( data );

        $.post( ajaxurl, data )
            .done( function( response ) {
                isPollInFlight = false;

                if ( response && response.success ) {
                    if ( batchRetryCount > 0 ) {
                        batchRetryCount = 0;
                        // Restart the interval if it was paused during retries.
                        if ( ! batchPollInterval && activeBatchJob ) {
                            startPolling( jobId );
                        }
                    }

                    let statusData = response.data;

                    updateBatchProgress( jobId, statusData );

                    // Check for server-side timeout (may have different status value).
                    if ( statusData.job_timed_out === true || statusData.job_error_code === 'timeout' ) {
                        handleBatchComplete( jobId, {
                            status: 'timeout',
                            job_timed_out: true,
                            message: statusData.message || l10n( 'batch_timeout', 'Batch operation timed out. The operation may still be running on the server.' ),
                            processed: statusData.processed || 0,
                            total: statusData.total || activeBatchJob.total || 0,
                            succeeded: statusData.succeeded || 0,
                            failed: statusData.failed || 0
                        } );
                        return;
                    }

                    if ( statusData.status === 'completed' || statusData.status === 'failed' ) {
                        handleBatchComplete( jobId, statusData );
                    }
                } else {
                    let errorCode = response && response.data ? response.data.error : '';
                    let errorMsg = response && response.data && response.data.message
                        ? response.data.message
                        : l10n( 'error_unknown', 'The server did not say what went wrong. Please try again.' );

                    // Job not found, most likely expired.
                    if ( errorCode === 'mainwp_job_not_found' ) {
                        handleBatchExpired( jobId );
                    } else {
                        // Other error, possibly transient.
                        handlePollError( jobId, errorMsg );
                    }
                }
            } )
            .fail( function( jqXHR, textStatus ) {
                isPollInFlight = false;
                handlePollError( jobId, textStatus );
            } );
    }

    /**
     * Handle poll error with retry logic.
     *
     * Pauses the interval during retries to prevent concurrent polls.
     *
     * @param {string} jobId     The batch job ID.
     * @param {string} errorText Error description.
     */
    function handlePollError( jobId, errorText ) {
        batchRetryCount++;

        if ( batchRetryCount <= BATCH_MAX_RETRIES ) {
            // Pause the interval during retry mode to prevent race conditions.
            if ( batchPollInterval ) {
                clearInterval( batchPollInterval );
                batchPollInterval = null;
            }

            updateBatchStatusText( jobId, l10n( 'batch_retrying', 'Connection lost, retrying...' ) + ' (' + batchRetryCount + '/' + BATCH_MAX_RETRIES + ')' );

            // Use exponential backoff. Store timeout ID for cleanup.
            let backoffMs = Math.min( 1000 * Math.pow( 2, batchRetryCount ), 30000 );
            batchRetryTimeout = setTimeout( function() {
                batchRetryTimeout = null;
                pollBatchStatus( jobId );
            }, backoffMs );
        } else {
            handleBatchFailed( jobId, l10n( 'batch_connection_lost', 'Connection lost. Unable to retrieve batch job status.' ) );
        }
    }

    /**
     * Handle batch job timeout (client-side).
     */
    function handleBatchTimeout() {
        if ( ! activeBatchJob ) {
            return;
        }

        handleBatchComplete( activeBatchJob.job_id, {
            status: 'timeout',
            job_timed_out: true,
            message: l10n( 'batch_timeout', 'Batch operation timed out. The operation may still be running on the server.' )
        } );
    }

    /**
     * Handle expired batch job (24-hour limit).
     *
     * @param {string} jobId The batch job ID.
     */
    function handleBatchExpired( jobId ) {
        stopPolling();
        clearActiveJob();

        // The job id becomes part of a DOM id and the selector that reads it back, so
        // everything outside [a-z0-9] is folded to a dash first. The same substitution is
        // repeated at every batch-progress site; they must stay identical or the lookups
        // stop matching the element that was written.
        let $progress = $( '#mainwp-batch-progress-' + jobId.replace( /[^a-z0-9]/gi, '-' ) );
        if ( $progress.length ) {
            $progress.replaceWith(
                '<div class="mainwp-mintlify-message mainwp-mintlify-assistant-message">' +
                    '<div class="message-content mainwp-mintlify-batch-expired">' +
                        '<i class="clock outline icon"></i> ' +
                        escapeHtml( l10n( 'batch_expired', 'Batch job expired (jobs are kept for 24 hours).' ) ) +
                    '</div>' +
                '</div>'
            );
        }

        setInputState( true );
        setSendButtonDefault();
        $input.focus();
    }

    /**
     * Handle batch job failure.
     *
     * @param {string} jobId    The batch job ID.
     * @param {string} errorMsg Error message.
     */
    function handleBatchFailed( jobId, errorMsg ) {
        handleBatchComplete( jobId, {
            status: 'failed',
            message: errorMsg
        } );
    }

    /**
     * Handle batch job completion (success, failure, or timeout).
     *
     * @param {string} jobId      The batch job ID.
     * @param {Object} statusData Final status data.
     */
    function handleBatchComplete( jobId, statusData ) {
        stopPolling();
        clearActiveJob();

        let safeJobId = jobId.replace( /[^a-z0-9]/gi, '-' );
        let $progress = $( '#mainwp-batch-progress-' + safeJobId );

        let html = buildCompletionHtml( statusData );

        if ( $progress.length ) {
            $progress.replaceWith( html );
        } else {
            $messages.append( html );
        }

        // Try to update the last assistant entry (the batch placeholder), otherwise append.
        if ( ! isHistoryDisabled ) {
            let completionText = statusData.message || buildCompletionSummary( statusData );

            let updated = false;
            if ( messageHistory.length > 0 ) {
                let lastMsg = messageHistory[ messageHistory.length - 1 ];
                if ( lastMsg.role === 'assistant' ) {
                    lastMsg.content = completionText;
                    updated = true;
                }
            }

            if ( ! updated ) {
                messageHistory.push( {
                    role: 'assistant',
                    content: completionText
                } );
            }
        }

        scrollToBottom();
        setInputState( true );
        setSendButtonDefault();
        $input.focus();
    }

    /**
     * Build HTML for completion message.
     *
     * @param {Object} statusData Status data.
     * @return {string} HTML string.
     */
    function buildCompletionHtml( statusData ) {
        let timestamp = formatTimestamp();
        let iconClass = 'check circle';
        let messageClass = 'mainwp-mintlify-batch-complete';
        let messageText = '';

        if ( statusData.status === 'completed' ) {
            iconClass = 'check circle green';
            messageText = buildCompletionSummary( statusData );
        } else if ( statusData.status === 'failed' ) {
            iconClass = 'times circle red';
            messageClass += ' mainwp-batch-failed';
            messageText = statusData.message || l10n( 'batch_failed', 'Batch operation failed.' );
        } else if ( statusData.status === 'timeout' || statusData.job_timed_out ) {
            iconClass = 'clock outline orange';
            messageClass += ' mainwp-batch-timeout';
            messageText = statusData.message || l10n( 'batch_timeout', 'Batch operation timed out. The operation may still be running on the server.' );
        }

        let html = '<div class="mainwp-mintlify-message mainwp-mintlify-assistant-message">' +
                       '<div class="message-content ' + messageClass + '">' +
                           '<div class="mainwp-batch-result-header">' +
                               '<i class="' + iconClass + ' icon"></i> ' +
                               escapeHtml( messageText ) +
                           '</div>';

        if ( statusData.errors && statusData.errors.length > 0 ) {
            html += buildErrorsList( statusData.errors );
        }

        html += '</div>' +
                '<div class="mainwp-mintlify-timestamp">' + timestamp + '</div>' +
            '</div>';

        return html;
    }

    /**
     * Build completion summary text.
     *
     * @param {Object} statusData Status data.
     * @return {string} Summary text.
     */
    function buildCompletionSummary( statusData ) {
        let total = statusData.total || 0;
        let succeeded = statusData.succeeded || statusData.processed || 0;
        let failed = statusData.failed || 0;

        if ( failed > 0 ) {
            return l10n( 'batch_complete', 'Batch operation completed.' ) + ' ' +
                   succeeded + ' succeeded, ' + failed + ' failed out of ' + total + ' total.';
        }

        return l10n( 'batch_complete', 'Batch operation completed.' ) + ' ' +
               succeeded + ' of ' + total + ' sites processed successfully.';
    }

    /**
     * Build collapsible errors list HTML.
     *
     * @param {Array} errors Array of error objects.
     * @return {string} HTML string.
     */
    function buildErrorsList( errors ) {
        // A chat bubble is no place for a fleet-sized error dump, so cap the
        // inline list and point at the exportable log for the rest.
        let ERROR_LIST_CAP = 10;

        // Unique per render, because several batch results can sit in one
        // conversation and aria-controls needs an unambiguous target.
        batchErrorsListSeq++;
        let listId = 'mainwp-batch-errors-list-' + batchErrorsListSeq;

        // A real <button> so the disclosure is keyboard-operable; the
        // delegated click handler (bindEvents) toggles the list and flips
        // aria-expanded. Tested in tests/js/test-confirm-flow.js.
        let html = '<div class="mainwp-batch-errors">' +
                       '<button type="button" class="mainwp-batch-errors-header" aria-expanded="false" aria-controls="' + listId + '">' +
                           '<i class="dropdown icon"></i> ' +
                           l10n( 'batch_show_errors', 'Show errors (%d)' ).replace( '%d', errors.length ) +
                       '</button>' +
                       '<div class="mainwp-batch-errors-list" id="' + listId + '" style="display:none;">' +
                           '<ul>';

        errors.slice( 0, ERROR_LIST_CAP ).forEach( function( err ) {
            let siteInfo = err.site_id ? ' (Site #' + err.site_id + ')' : '';
            let errorMsg = err.message || err.error || l10n( 'error_unknown', 'The server did not say what went wrong. Please try again.' );
            html += '<li>' + escapeHtml( errorMsg + siteInfo ) + '</li>';
        } );

        html += '</ul>';

        if ( errors.length > ERROR_LIST_CAP ) {
            html += '<div class="mainwp-batch-errors-more">' +
                        escapeHtml(
                            l10n( 'batch_errors_more', '...and %d more. Export the conversation for the full list.' )
                                .replace( '%d', errors.length - ERROR_LIST_CAP )
                        ) +
                    '</div>';
        }

        html += '</div></div>';
        return html;
    }

    /**
     * Stop polling and clear all timers.
     */
    function stopPolling() {
        if ( batchPollInterval ) {
            clearInterval( batchPollInterval );
            batchPollInterval = null;
        }
        if ( batchRetryTimeout ) {
            clearTimeout( batchRetryTimeout );
            batchRetryTimeout = null;
        }
        isPollInFlight = false;
    }

    /**
     * Clear active job state.
     *
     * @param {boolean} preserveResumeState When true, keep the localStorage
     *        record so a page reload can resume monitoring the job. Used by
     *        Stop, which cannot cancel a server-side batch and so must not
     *        discard the only handle for recovering it.
     */
    function clearActiveJob( preserveResumeState ) {
        activeBatchJob = null;
        batchStartTime = null;
        batchRetryCount = 0;
        isPollInFlight = false;
        if ( ! preserveResumeState ) {
            localStorage.removeItem( STORAGE_KEY );
        }
    }

    // =========================================================================
    // ASYNC CHAT JOB HANDLING
    // =========================================================================

    /**
     * Handle async chat response: keep the thinking indicator and start polling.
     *
     * Called when the server returns async_chat: true, meaning the AI call
     * has been offloaded to WP-Cron to avoid blocking the PHP-FPM worker.
     *
     * @param {Object} data Async chat payload from server.
     */
    function handleAsyncChat( data ) {
        if ( data.conversation_id ) {
            setCurrentConversation( data.conversation_id );
        }

        // Keep thinking indicator running (already active from sendToAPI).

        activeChatJob = {
            job_id: data.job_id,
            poll_endpoint: data.poll_endpoint || 'mainwp_assistant_poll_chat_job',
            poll_interval: data.poll_interval || 2000,
            startTime: Date.now()
        };
        chatPollQueuedCount = 0;
        chatPollRetryCount = 0;
        sseReconnectCount = 0;

        // Try SSE first for real-time updates, falls back to polling if unsupported.
        startSSEConnection( data.job_id );
    }

    /**
     * Start polling interval for async chat job status.
     *
     * @param {string} jobId The chat job ID.
     */
    function startChatPolling( jobId ) {
        if ( chatPollInterval ) {
            clearInterval( chatPollInterval );
        }

        let interval = ( activeChatJob && activeChatJob.poll_interval ) || 2000;

        chatPollInterval = setInterval( function() {
            if ( activeChatJob && ( Date.now() - activeChatJob.startTime > CHAT_POLL_TIMEOUT_MS ) ) {
                handleChatJobTimeout();
                return;
            }

            pollChatStatus( jobId );
        }, interval );
    }

    // Shared with the Node test suite; see mainwp-assistant-chat-logic.js.
    var nextChatPollFallbackState = MainWPAssistantChatLogic.nextChatPollFallbackState;

    /**
     * Poll async chat job status from server.
     *
     * @param {string} jobId The chat job ID.
     */
    function pollChatStatus( jobId ) {
        // Prevent concurrent polls (mirrors the batch isPollInFlight guard):
        // a slow round-trip must not overlap the next interval tick.
        if ( isChatPollInFlight ) {
            return;
        }
        if ( ! isCurrentChatJob( jobId ) ) {
            return;
        }
        isChatPollInFlight = true;

        let endpoint = ( activeChatJob && activeChatJob.poll_endpoint ) || 'mainwp_assistant_poll_chat_job';

        let data = {
            action: endpoint,
            job_id: jobId
        };

        data = mainwp_secure_data( data );

        $.post( ajaxurl, data )
            .done( function( response ) {
                isChatPollInFlight = false;
                if ( ! isCurrentChatJob( jobId ) ) {
                    return;
                }
                if ( response && response.success ) {
                    let statusData = response.data;

                    if ( statusData.status === 'processing' || statusData.status === 'queued' ) {
                        // Capture phase for thinking indicator text.
                        currentChatPhase = statusData.phase || null;

                        // Only persistent 'queued' (no worker claimed the job,
                        // WP-Cron may be dead) counts toward the synchronous
                        // fallback; 'processing' proves a live worker owns the
                        // job and resets the counter so it is never stolen.
                        let fallbackState = nextChatPollFallbackState( statusData.status, chatPollQueuedCount, CHAT_POLL_QUEUED_FALLBACK );
                        chatPollQueuedCount = fallbackState.queuedCount;
                        if ( fallbackState.triggerFallback ) {
                            triggerChatFallback( jobId );
                        }
                        return;
                    }

                    chatPollQueuedCount = 0;

                    if ( statusData.status === 'completed' ) {
                        handleChatJobComplete( statusData );
                    }
                } else {
                    let errorStatus = response && response.data ? response.data.status : '';
                    let errorMsg = response && response.data && response.data.message
                        ? response.data.message
                        : l10n( 'error_unknown', 'The server did not say what went wrong. Please try again.' );
                    let pollErrorCode = response && response.data ? response.data.error_code : null;

                    if ( errorStatus === 'expired' ) {
                        handleChatJobError( l10n( 'chat_job_expired', 'The request expired before it finished. Please try again.' ) );
                    } else if ( errorStatus === 'failed' ) {
                        handleChatJobError( errorMsg, pollErrorCode, response.data );
                    } else {
                        // Transient error, retry.
                        handleChatPollError( jobId, errorMsg );
                    }
                }
            } )
            .fail( function( jqXHR, textStatus ) {
                isChatPollInFlight = false;
                if ( ! isCurrentChatJob( jobId ) ) {
                    return;
                }
                handleChatPollError( jobId, textStatus );
            } );
    }

    /**
     * Handle successful chat job completion.
     *
     * Processes the completed result, which contains the same payload structure
     * as a synchronous AI response (response, conversation_id, batch_job, etc.).
     *
     * @param {Object} resultData Completed job data from poll endpoint.
     */
    function handleChatJobComplete( resultData ) {
        clearChatJob();
        stopThinkingIndicator();
        setSendButtonDefault();

        if ( resultData.history_disabled === true ) {
            handleHistoryDisabled();
        } else if ( resultData.history_disabled === false ) {
            restoreHistoryControls();
        }

        if ( resultData.show_disclosure === true && ! isHistoryDisabled ) {
            let dismissed = localStorage.getItem( 'mainwp_assistant_disclosure_dismissed' );
            if ( ! dismissed ) {
                $( '#mainwp-mintlify-disclosure' ).show();
            }
        }

        lastAssistantMessageId = resultData.message_id || null;
        let messageTokens = parseUsageFromResponse( resultData );

        // Update mode indicator, same receipt/convergence shape as the sync
        // handler for symmetry, though async results are always mode 'agent'
        // (jobs are only queued after the route resolved to agent).
        // Tested in tests/js/test-mode-guards.js
        if ( isAgentRefusal( resultData, userModeOverride ) ) {
            convergeAfterAgentRefusal();
        } else if ( userModeOverride ) {
            updateModeIndicator( userModeOverride, userModeOverride === 'agent' ? availableProviderName : null );
        } else if ( resultData.mode === 'ask' ) {
            updateModeIndicator( 'ask', null );
        } else if ( resultData.provider_name ) {
            availableProviderName = resultData.provider_name;
            updateModeIndicator( 'agent', resultData.provider_name );
        }

        // Check for nested batch job result (tool call queued a batch during async processing).
        if ( resultData.batch_job === true ) {
            finalizeRegeneration();
            handleBatchJob( resultData );
            if ( messageTokens ) {
                showMessageCost( messageTokens );
            }
            return;
        }

        if ( resultData.requires_confirmation === true ) {
            finalizeRegeneration();
            handleConfirmationRequired( resultData );
            if ( messageTokens ) {
                showMessageCost( messageTokens );
            }
            return;
        }

        let responseText = resultData.response || '';
        let sources = resultData.sources || [];

        appendAssistantMessage( responseText, sources, messageTokens, resultData.executed_abilities || [] );

        if ( ! isHistoryDisabled ) {
            messageHistory.push( {
                role: 'assistant',
                content: responseText
            } );
        }

        finalizeRegeneration();

        if ( lastUserMessage && resolveRegenerateAvailability( lastAssistantMessageId, isHistoryDisabled ) ) {
            showRegenerateLink();
        }

        setInputState( true );
        $input.focus();
    }

    /**
     * Handle chat poll error with retry logic.
     *
     * @param {string} jobId     The chat job ID.
     * @param {string} errorText Error description.
     */
    function handleChatPollError( jobId, errorText ) {
        chatPollRetryCount++;

        if ( chatPollRetryCount <= CHAT_POLL_MAX_RETRIES ) {
            let backoffMs = Math.min( 1000 * Math.pow( 2, chatPollRetryCount ), 10000 );
            setTimeout( function() {
                pollChatStatus( jobId );
            }, backoffMs );
        } else {
            handleChatJobError( l10n( 'chat_connection_lost', 'Lost the connection while waiting for the response. Please try again.' ) );
        }
    }

    /**
     * Stop polling and show a chat job error.
     *
     * @param {string} errorMessage  Error text to display.
     * @param {string} [errorCode]   Server error code, passed through to appendErrorMessage().
     * @param {Object} [errorData]   Server error payload, passed through to appendErrorMessage().
     */
    function handleChatJobError( errorMessage, errorCode, errorData ) {
        clearChatJob();
        stopThinkingIndicator();
        setSendButtonDefault();
        restoreRegenerationOriginal();
        appendErrorMessage( errorMessage, errorCode, errorData );
        setInputState( true );
        $input.focus();
    }

    /**
     * Handle chat job client-side timeout.
     */
    function handleChatJobTimeout() {
        handleChatJobError( l10n( 'chat_timeout', 'The request timed out. The server may still be working on it, so reload the page to check.' ) );
    }

    /**
     * Trigger synchronous fallback when WP-Cron appears disabled.
     *
     * After CHAT_POLL_QUEUED_FALLBACK consecutive 'queued' polls (no worker
     * ever claimed the job), fires a separate AJAX call that processes the
     * job inline. A job a worker is actively processing never lands here.
     *
     * @param {string} jobId The chat job ID.
     */
    function triggerChatFallback( jobId ) {
        // Bail if the job was cancelled while queued, or a newer job has
        // replaced it. Identity, not just truthiness, or the fallback would
        // process the OLD job and settle the new one with its result.
        if ( ! isCurrentChatJob( jobId ) ) {
            return;
        }

        // Stop polling; the fallback request blocks until complete.
        if ( chatPollInterval ) {
            clearInterval( chatPollInterval );
            chatPollInterval = null;
        }

        let data = {
            action: 'mainwp_assistant_process_chat_fallback',
            job_id: jobId
        };
        data = mainwp_secure_data( data );

        $.ajax( { url: ajaxurl, type: 'POST', data: data, dataType: 'json', timeout: 300000 } )
            .done( function( response ) {
                if ( ! isCurrentChatJob( jobId ) ) {
                    return;
                }
                if ( response && response.success && response.data.status === 'completed' ) {
                    handleChatJobComplete( response.data );
                } else {
                    let errorMsg = ( response && response.data && response.data.message )
                        ? response.data.message
                        : l10n( 'chat_fallback_failed', 'Unable to process the request. Please try again.' );
                    // Preflight guards use 'code'; job failures use 'error_code'.
                    let fallbackErrorCode = ( response && response.data ) ? ( response.data.error_code || response.data.code || null ) : null;
                    handleChatJobError( errorMsg, fallbackErrorCode, response ? response.data : null );
                }
            } )
            .fail( function( jqXHR, textStatus, errorThrown ) {
                console.error( '[Assistant] Fallback failed:', textStatus, errorThrown );
                if ( window.console && window.mainwp_assistant_debug ) {
                    console.error( '[Assistant] Fallback response:', jqXHR.responseText?.substring( 0, 200 ) );
                }
                if ( ! isCurrentChatJob( jobId ) ) {
                    return;
                }
                handleChatJobError( l10n( 'chat_fallback_failed', 'Unable to process the request. Please try again.' ) );
            } );
    }

    /**
     * Clear async chat job state.
     */
    function clearChatJob() {
        activeChatJob = null;
        chatPollQueuedCount = 0;
        chatPollRetryCount = 0;
        isChatPollInFlight = false;
        currentChatPhase = null;
        if ( chatPollInterval ) {
            clearInterval( chatPollInterval );
            chatPollInterval = null;
        }
        // Close any active SSE connection (also cancels its pending timers).
        closeSSEConnection();
    }

    // =========================================================================
    // SSE (SERVER-SENT EVENTS)
    // =========================================================================

    /**
     * Check if SSE is supported and not disabled.
     *
     * @return {boolean} True if SSE can be attempted.
     */
    function isSSESupported() {
        if ( typeof EventSource === 'undefined' ) {
            return false;
        }
        if ( sseSupported === false ) {
            return false;
        }
        // Session-level SSE disable flag (set after repeated failures).
        if ( sessionStorage.getItem( 'mainwp_sse_unsupported' ) ) {
            return false;
        }
        return true;
    }

    /**
     * Start SSE connection for real-time chat job updates.
     *
     * Attempts to establish an SSE connection for the given job.
     * Falls back to polling if SSE fails to connect or encounters errors.
     *
     * @param {string} jobId The chat job ID.
     */
    function startSSEConnection( jobId ) {
        // Prevent duplicate connections from rapid onerror re-entries.
        if ( activeSSEConnection || chatPollInterval ) {
            return;
        }

        if ( ! isSSESupported() ) {
            startChatPolling( jobId );
            return;
        }

        if ( typeof mainwpMintlifyChatL10n === 'undefined' || ! mainwpMintlifyChatL10n.sse_nonce ) {
            startChatPolling( jobId );
            return;
        }

        let url = ajaxurl + '?' + $.param( {
            action: 'mainwp_assistant_sse_stream',
            job_id: jobId,
            _wpnonce: mainwpMintlifyChatL10n.sse_nonce
        } );

        // Set up connection timeout. Tracked module-level (not a local) so
        // closeSSEConnection() can cancel it when the job is cleared or
        // superseded before it fires.
        sseConnectionTimeout = setTimeout( function() {
            if ( ! isCurrentChatJob( jobId ) ) {
                return;
            }
            if ( activeSSEConnection && activeSSEConnection.readyState === EventSource.CONNECTING ) {
                closeSSEConnection();
                markSSEUnsupported();
                startChatPolling( jobId );
            }
        }, SSE_CONNECTION_TIMEOUT );

        activeSSEConnection = new EventSource( url );

        // Connection opened successfully. Deliberately does NOT reset
        // sseReconnectCount: onopen only proves the HTTP connection opened, so a
        // server that accepts connections and dies without sending anything
        // would refill the budget on every retry and reconnect forever. The
        // budget is per job (reset in handleAsyncChat) and refills only when a
        // meaningful server event arrives (status/heartbeat below).
        // Tested in tests/js/test-mode-guards.js
        activeSSEConnection.onopen = function() {
            clearTimeout( sseConnectionTimeout );
            sseSupported = true;
        };

        activeSSEConnection.addEventListener( 'status', function( e ) {
            sseReconnectCount = 0;
            try {
                let data = JSON.parse( e.data );
                currentChatPhase = data.phase || null;
            } catch ( err ) {
                // Ignore parse errors.
            }
        } );

        // Heartbeat event (keep-alive): the stream is provably real, refill
        // the reconnect budget.
        activeSSEConnection.addEventListener( 'heartbeat', function() {
            sseReconnectCount = 0;
        } );

        activeSSEConnection.addEventListener( 'complete', function( e ) {
            closeSSEConnection();
            if ( ! isCurrentChatJob( jobId ) ) {
                return;
            }
            try {
                let data = JSON.parse( e.data );
                handleChatJobComplete( data );
            } catch ( err ) {
                handleChatJobError( l10n( 'chat_result_unreadable', 'The response arrived in a form the Assistant could not read. Please try again.' ) );
            }
        } );

        // Terminal failure event from server (job failed or SSE timeout).
        // Named 'job_error' server-side: a server-sent event named 'error'
        // would dispatch with the same type as EventSource's built-in
        // connection-error event, firing the onerror reconnect handler on
        // every job failure, an infinite reconnect/re-append loop.
        activeSSEConnection.addEventListener( 'job_error', function( e ) {
            closeSSEConnection();
            if ( ! isCurrentChatJob( jobId ) ) {
                return;
            }
            try {
                let data = JSON.parse( e.data );
                if ( data.fallback ) {
                    // Server indicated we should fall back to polling.
                    startChatPolling( jobId );
                } else {
                    handleChatJobError( data.message || l10n( 'chat_job_failed', 'The request could not be completed. Please try again.' ), data.code || null, data );
                }
            } catch ( err ) {
                // Parse error means connection issue, fall back to polling.
                startChatPolling( jobId );
            }
        } );

        // Connection error (network issues, server unavailable).
        activeSSEConnection.onerror = function() {
            // Ignore the trailing connection-close event after an intentional
            // close (job_error/complete handlers null the connection first).
            if ( ! activeSSEConnection ) {
                return;
            }

            clearTimeout( sseConnectionTimeout );
            sseReconnectCount++;

            if ( sseReconnectCount <= SSE_MAX_RECONNECTS ) {
                // Attempt reconnect with exponential backoff. Tracked so a
                // Stop/New Chat between the error and the reconnect cancels
                // it instead of reviving a cleared job.
                sseReconnectTimeout = setTimeout( function() {
                    if ( ! isCurrentChatJob( jobId ) ) {
                        return;
                    }
                    closeSSEConnection();
                    startSSEConnection( jobId );
                }, 1000 * sseReconnectCount );
            } else {
                // Max reconnects reached, fall back to polling for THIS job
                // only. A stream that opened and then died repeatedly is
                // evidence about this job or a server hiccup, not proof the
                // environment can't do SSE, so the session-wide unsupported
                // flag stays untouched and the next job attempts SSE again.
                // Only the connect-timeout above (EventSource never left
                // CONNECTING) marks SSE unsupported for the session.
                // Tested in tests/js/test-mode-guards.js
                closeSSEConnection();
                startChatPolling( jobId );
            }
        };
    }

    /**
     * Close active SSE connection and cancel its pending timers, so neither
     * a connect-timeout nor a scheduled reconnect can fire for a job that
     * has been cleared or superseded.
     */
    function closeSSEConnection() {
        if ( sseConnectionTimeout ) {
            clearTimeout( sseConnectionTimeout );
            sseConnectionTimeout = null;
        }
        if ( sseReconnectTimeout ) {
            clearTimeout( sseReconnectTimeout );
            sseReconnectTimeout = null;
        }
        if ( activeSSEConnection ) {
            activeSSEConnection.close();
            activeSSEConnection = null;
        }
    }

    /**
     * Mark SSE as unsupported for this session.
     *
     * Prevents repeated SSE connection attempts when the server
     * doesn't support it (shared hosting, proxy issues, etc.).
     */
    function markSSEUnsupported() {
        sseSupported = false;
        sessionStorage.setItem( 'mainwp_sse_unsupported', '1' );
    }

    // =========================================================================
    // PROGRESS UI
    // =========================================================================

    /**
     * Append batch progress message to chat.
     *
     * @param {Object} jobData Job data with job_id and total.
     */
    function appendBatchProgressMessage( jobData ) {
        let safeJobId = jobData.job_id.replace( /[^a-z0-9]/gi, '-' );
        let total = jobData.total || 0;

        let html = '<div id="mainwp-batch-progress-' + safeJobId + '" class="mainwp-mintlify-message mainwp-mintlify-assistant-message">' +
                       '<div class="message-content mainwp-mintlify-batch-progress">' +
                           '<div class="mainwp-batch-status-text">' +
                               l10n( 'batch_queued', 'Batch job queued' ) +
                           '</div>' +
                           '<div class="ui indicating progress" data-percent="0">' +
                               '<div class="bar" style="width: 0%;">' +
                                   '<div class="progress">0%</div>' +
                               '</div>' +
                           '</div>' +
                           '<div class="mainwp-batch-stats">' +
                               '<span class="mainwp-batch-processed">0</span> / ' +
                               '<span class="mainwp-batch-total">' + total + '</span> ' + l10n( 'batch_sites_label', 'sites' ) +
                               '<span class="mainwp-batch-success-fail">' +
                                   ' (<span class="mainwp-batch-succeeded">0</span> ' + l10n( 'batch_succeeded_label', 'succeeded' ) + ', ' +
                                   '<span class="mainwp-batch-failed">0</span> ' + l10n( 'batch_failed_label', 'failed' ) + ')' +
                               '</span>' +
                           '</div>' +
                           '<div class="mainwp-batch-eta">' +
                               l10n( 'estimated_time', 'Estimating...' ) +
                           '</div>' +
                       '</div>' +
                   '</div>';

        $messages.append( html );
        scrollToBottom();
    }

    /**
     * Update batch progress UI.
     *
     * @param {string} jobId      The batch job ID.
     * @param {Object} statusData Status data from server.
     */
    function updateBatchProgress( jobId, statusData ) {
        let safeJobId = jobId.replace( /[^a-z0-9]/gi, '-' );
        let $progress = $( '#mainwp-batch-progress-' + safeJobId );

        if ( ! $progress.length ) {
            return;
        }

        let processed = statusData.processed || 0;
        let total = statusData.total || activeBatchJob.total || 0;
        let succeeded = statusData.succeeded || 0;
        let failed = statusData.failed || 0;
        let percent = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;

        let $bar = $progress.find( '.ui.progress' );
        $bar.attr( 'data-percent', percent );
        $bar.find( '.bar' ).css( 'width', percent + '%' );
        $bar.find( '.progress' ).text( percent + '%' );

        if ( percent >= 100 ) {
            $bar.addClass( 'success' );
        } else if ( failed > 0 ) {
            $bar.addClass( 'warning' );
        }

        $progress.find( '.mainwp-batch-processed' ).text( processed );
        $progress.find( '.mainwp-batch-total' ).text( total );
        $progress.find( '.mainwp-batch-succeeded' ).text( succeeded );
        $progress.find( '.mainwp-batch-failed' ).text( failed );

        let ofTotal = l10n( 'batch_of_total', '%1$s of %2$s' ).replace( '%1$s', processed ).replace( '%2$s', total );
        let statusText = l10n( 'batch_processing_sites', 'Processing' ) + ' ' + ofTotal + ' ' + l10n( 'batch_sites_label', 'sites' );
        if ( statusData.status === 'queued' ) {
            statusText = l10n( 'batch_queued_waiting', 'Batch job queued, waiting to start...' );
        }
        $progress.find( '.mainwp-batch-status-text' ).text( statusText );

        let etaText = calculateEstimatedTime( processed, total, batchStartTime );
        $progress.find( '.mainwp-batch-eta' ).text( etaText );

        scrollToBottom();
    }

    /**
     * Update batch status text only.
     *
     * @param {string} jobId      The batch job ID.
     * @param {string} statusText Status text.
     */
    function updateBatchStatusText( jobId, statusText ) {
        let safeJobId = jobId.replace( /[^a-z0-9]/gi, '-' );
        let $progress = $( '#mainwp-batch-progress-' + safeJobId );

        if ( $progress.length ) {
            $progress.find( '.mainwp-batch-status-text' ).text( statusText );
        }
    }

    /**
     * Calculate estimated time remaining.
     *
     * @param {number} processed Number of processed items.
     * @param {number} total     Total items.
     * @param {number} startTime Start time in milliseconds.
     * @return {string} Human-readable estimated time.
     */
    function calculateEstimatedTime( processed, total, startTime ) {
        if ( ! startTime || processed === 0 || total === 0 ) {
            return l10n( 'estimated_time', 'Estimating...' );
        }

        let elapsed = Date.now() - startTime;
        let rate = processed / elapsed; // items per ms.
        let remaining = total - processed;

        if ( rate <= 0 || remaining <= 0 ) {
            return l10n( 'batch_almost_done', 'Almost done...' );
        }

        let estimatedMs = remaining / rate;

        if ( estimatedMs < 60000 ) {
            return l10n( 'estimated_less_minute', 'Less than a minute remaining' );
        } else if ( estimatedMs < 3600000 ) {
            let minutes = Math.ceil( estimatedMs / 60000 );
            let key = minutes === 1 ? 'estimated_minute_remaining' : 'estimated_minutes_remaining';
            let fallback = minutes === 1 ? '~%d minute remaining' : '~%d minutes remaining';
            return l10n( key, fallback ).replace( '%d', minutes );
        } else {
            let hours = Math.floor( estimatedMs / 3600000 );
            let minutes = Math.ceil( ( estimatedMs % 3600000 ) / 60000 );
            return l10n( 'estimated_hours_remaining', '~%1$dh %2$dm remaining' ).replace( '%1$d', hours ).replace( '%2$d', minutes );
        }
    }


    // =========================================================================
    // MESSAGE RENDERING
    // =========================================================================

    /**
     * Append user message to chat.
     *
     * @param {string} text         The message text.
     * @param {number} [storedTime] created_at of a stored message, in Unix seconds. Omit
     *                              for a live message, which timestamps as now.
     */
    function appendUserMessage( text, storedTime ) {
        let timestamp = formatTimestamp( storedTime );
        let escapedText = $( '<div>' ).text( text ).html();

        let html = '<div class="mainwp-mintlify-message mainwp-mintlify-user-message">' +
                       '<div class="message-content">' + escapedText.replace( /\n/g, '<br>' ) + '</div>' +
                       '<div class="mainwp-mintlify-timestamp">' + timestamp + '</div>' +
                   '</div>';

        $messages.append( html );
        if ( ! isBatchRendering ) {
            scrollToBottom();
        }
    }

    /**
     * Attach ratings to the saved answer, including when history is replayed.
     *
     * @param {Object} $bubble Answer element.
     * @param {Object} feedback Saved local identifiers and current rating.
     */
    function attachAnswerFeedback( $bubble, feedback ) {
        if ( ! feedback || ! Number.isSafeInteger( feedback.message_id ) || feedback.message_id < 1 ||
            ! Number.isSafeInteger( feedback.conversation_id ) || feedback.conversation_id < 1 ) {
            return;
        }
        let rating = feedback.rating === 'positive' || feedback.rating === 'negative' ? feedback.rating : null;
        let pending = false;
        let $support = $( '<p class="mainwp-assistant-support-link"><small><a href="#"></a></small></p>' );
        $support.find( 'a' ).text( mainwpMintlifyChatL10n.support.link ).on( 'click', function( event ) {
            event.preventDefault();
            MainWPAssistantPanel.openSupport();
        } );
        $support.toggle( rating === 'negative' );
        let $controls = $( '<span class="mainwp-assistant-feedback"></span>' );
        let $status = $( '<span class="mainwp-assistant-feedback-status" role="status" aria-live="polite"></span>' );
        [ 'positive', 'negative' ].forEach( function( value ) {
            let label = value === 'positive' ? l10n( 'feedback_helpful', 'Helpful answer' ) : l10n( 'feedback_unhelpful', 'Unhelpful answer' );
            let $button = $( '<button type="button" class="mainwp-assistant-feedback-btn"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12H3V10h4zm0 0 5-8a3 3 0 0 1 3 3v5h5a2 2 0 0 1 2 2l-2 8a2 2 0 0 1-2 2H7"/></svg></button>' );
            $button.attr( { 'aria-label': label, title: label, 'aria-pressed': String( rating === value ), 'data-rating': value } );

            $button.on( 'click', function() {
                if ( pending ) { return; }
                let nextRating = rating === value ? null : value;
                let data = {
                    action: 'mainwp_assistant_feedback',
                    conversation_id: feedback.conversation_id,
                    message_id: feedback.message_id,
                    rating: nextRating || ''
                };
                pending = true;
                $controls.find( 'button' ).prop( 'disabled', true );
                $status.text( '' );
                $.ajax( { url: ajaxurl, type: 'POST', data: mainwp_secure_data( data ), timeout: 25000 } )
                    .done( function( response ) {
                        if ( ! response || ! response.success || ! response.data || response.data.rating !== nextRating ) {
                            $status.text( l10n( 'feedback_error', 'Could not save feedback. Please try again.' ) );
                            return;
                        }
                        rating = nextRating;
                        $support.toggle( rating === 'negative' );
                        $controls.find( 'button' ).each( function() {
                            $( this ).attr( 'aria-pressed', String( $( this ).attr( 'data-rating' ) === rating ) );
                        } );
                        $status.text( rating === null ? l10n( 'feedback_removed', 'Feedback removed.' ) : l10n( 'feedback_saved', 'Feedback saved. Thank you.' ) );
                    } )
                    .fail( function() {
                        $status.text( l10n( 'feedback_error', 'Could not save feedback. Please try again.' ) );
                    } )
                    .always( function() {
                        pending = false;
                        $controls.find( 'button' ).prop( 'disabled', false );
                    } );
            } );
            $controls.append( $button );
        } );
        $controls.append( $status );
        $bubble.find( '.mainwp-assistant-cost-line' ).append( $controls );
        $bubble.append( $support );
    }

    /**
     * Append assistant message to chat.
     *
     * @param {string}             text                 The message text.
     * @param {Array}              sources              Array of source citations.
     * @param {Object|number|null} usageInfo            Usage info from parseUsageFromResponse (legacy bare token count accepted).
     * @param {Array}              [executedAbilities]  Ability records rendered as chips under the message.
     * @param {number}             [storedTime]         created_at of a stored message, in Unix seconds.
     * @param {boolean}            [truncated]          Whether the Bridge shortened the response.
     * @param {Array}              [truncatedFields]    Fields shortened by the Bridge.
     * @param {Object}             [feedback]           Saved answer feedback state.
     * @param {string}             [storedMode]         Mode recorded for a replayed answer.
     */
    function appendAssistantMessage( text, sources, usageInfo, executedAbilities, storedTime, truncated, truncatedFields, feedback, storedMode ) {
        let timestamp = formatTimestamp( storedTime );
        let parsedContent = parseMarkdown( text );
        let sourcesHtml = '';
        let chipsHtml = renderExecutedAbilityChips( executedAbilities || [] );

        if ( sources && sources.length > 0 ) {
            sourcesHtml = renderSources( sources );
        }

        // Build inline token-usage line with copy button.
        let costInfo = normalizeUsageInfo( usageInfo );
        let costTextStyle = costInfo ? '' : ' style="display:none;"';
        let costTextContent = '';
        if ( costInfo ) {
            costTextContent = escapeHtml( formatCostText( costInfo ) );
        }
        let costLine = '<div class="mainwp-assistant-cost-line">' +
            '<span class="mainwp-assistant-cost-text"' + costTextStyle + '>' + costTextContent + '</span>' +
            '<button class="mainwp-assistant-copy-btn" type="button" aria-label="' + l10n( 'copy_response', 'Copy response' ) + '" title="' + l10n( 'copy_response', 'Copy response' ) + '">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                    '<g class="icon-copy"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></g>' +
                    '<g class="icon-check"><polyline points="20 6 9 17 4 12"/></g>' +
                '</svg>' +
            '</button>' +
            '<span class="mainwp-assistant-copy-status" role="status"></span>' +
        '</div>';

        // The receipt names who answered: Sites receipts carry the serving
        // provider when known ("Sites · OpenAI") so the answer is attributable.
        // Tested in tests/js/test-mode-guards.js
        let badgeMode = storedMode || currentMode;
        let modeLabel = badgeMode === 'agent'
            ? l10n( 'mode_agent', 'Sites' )
            : l10n( 'mode_ask', 'Docs' );
        if ( badgeMode === 'agent' && currentProvider && typeof currentProvider === 'string' ) {
            modeLabel += ' &middot; ' + escapeHtml( currentProvider );
        }

        let attribution = '<div class="mainwp-assistant-attribution">' +
                              '<span class="mainwp-assistant-attribution-left">' +
                                  l10n( 'assistant_name', 'MainWP Assistant' ) +
                                  '<span class="attribution-dot">&middot;</span>' +
                                  '<span class="mainwp-assistant-mode-label">' + modeLabel + '</span>' +
                                  '<span class="attribution-dot">&middot;</span>' +
                                  timestamp +
                              '</span>' +
                              '<button type="button" class="mainwp-assistant-regenerate-btn" style="display:none;" aria-label="' + l10n( 'regenerate_label', 'Regenerate last response' ) + '" title="' + l10n( 'regenerate_label', 'Regenerate last response' ) + '">' +
                                  '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                                      '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>' +
                                  '</svg>' +
                              '</button>' +
                          '</div>';

        if ( sourcesHtml && parsedContent.indexOf( '<div class="mainwp-mintlify-suggestions">' ) !== -1 ) {
            parsedContent = parsedContent.replace(
                '<div class="mainwp-mintlify-suggestions">',
                sourcesHtml + '<div class="mainwp-mintlify-suggestions">'
            );
            sourcesHtml = '';
        }

        let html = '<div class="mainwp-mintlify-message mainwp-mintlify-assistant-message">' +
                       '<div class="message-content">' + parsedContent + chipsHtml + sourcesHtml + costLine + '</div>' +
                       attribution +
                   '</div>';

        let $bubble = $( html );
        attachAnswerFeedback( $bubble, feedback );
        if ( truncated === true ) {
            let fields = Array.isArray( truncatedFields ) ? truncatedFields : [];
            let answerTruncated = fields.indexOf( 'answer' ) !== -1;
            let sourcesTruncated = fields.indexOf( 'sources' ) !== -1;
            let noticeText = l10n( 'truncated_notice_both', 'This answer was shortened and some sources were left out.' );
            if ( answerTruncated && ! sourcesTruncated ) {
                noticeText = l10n( 'truncated_notice_answer', 'This answer was shortened because it was too long.' );
            } else if ( sourcesTruncated && ! answerTruncated ) {
                noticeText = l10n( 'truncated_notice_sources', 'Some sources were left out of this answer.' );
            }

            let notice = document.createElement( 'div' );
            notice.className = 'mainwp-assistant-truncated-notice';
            let icon = document.createElement( 'i' );
            icon.className = 'info circle icon';
            let detail = document.createElement( 'span' );
            detail.textContent = noticeText;
            notice.appendChild( icon );
            notice.appendChild( document.createTextNode( ' ' ) );
            notice.appendChild( detail );
            $bubble.find( '.mainwp-assistant-cost-line' ).before( notice );
        }
        $messages.append( $bubble );

        if ( costInfo ) {
            registerBubbleUsage( $messages.find( '.mainwp-mintlify-assistant-message' ).last(), costInfo );
        }

        if ( ! isBatchRendering ) {
            let view = $messages[0];
            if ( $bubble.outerHeight() > view.clientHeight ) {
                // Page coordinates alone lose the reply's place in a scrolled conversation.
                let bubbleOffset = $bubble[0].getBoundingClientRect().top - view.getBoundingClientRect().top + view.scrollTop - view.clientTop;
                $messages.animate( {
                    scrollTop: MainWPAssistantChatLogic.assistantReplyScrollTarget( bubbleOffset, $bubble.outerHeight(), view.clientHeight, view.scrollHeight )
                }, 200 );
            } else {
                scrollToBottom();
            }
        }

        // Notify sidebar if panel is minimized.
        if ( typeof MainWPAssistantPanel !== 'undefined' && MainWPAssistantPanel.isMinimized() ) {
            jQuery( '#mainwp-assistant-chat-menu-item' ).addClass( 'assistant-chat-notification' );
        }
    }

    /**
     * Append system message to chat.
     *
     * @param {string} text The message text.
     */
    function appendSystemMessage( text ) {
        let html = '<div class="mainwp-mintlify-message mainwp-mintlify-system-message">' +
                       '<div class="message-content">' +
                           '<i class="info circle icon"></i> ' + escapeHtml( text ) +
                       '</div>' +
                   '</div>';

        $messages.append( html );
        if ( ! isBatchRendering ) {
            scrollToBottom();
        }
    }

    /**
     * Append error message to chat.
     *
     * @param {string} errorText   The error message text.
     * @param {string} [errorCode] Server error code. When it maps to a localized string,
     *                             that string replaces errorText and can carry a deep link.
     * @param {Object} [errorData] Server error payload, for provider advisories
     *                             (provider_message, retry_after).
     */
    function appendErrorMessage( errorText, errorCode, errorData ) {
        // A mapped, layered message wins over the server's literal text so the
        // user learns which layer failed and what to do about it.
        let codeMap = ( typeof mainwpMintlifyChatL10n !== 'undefined' && mainwpMintlifyChatL10n.error_codes ) || {};
        let displayText = ( errorCode && codeMap[ errorCode ] ) || errorText;

        let link = buildErrorDeepLink( errorCode );
        let linkHtml = typeof link === 'string' ? link : '';
        // Bind the failed message to this bubble so an old Try again link
        // re-sends the prompt that actually failed, not whatever was sent last.
        let retryHtml = lastUserMessage && errorCode !== 'chatgpt_plan_limit' && errorCode !== 'chatgpt_plan_reconnect' && errorCode !== 'chatgpt_plan_not_eligible'
            ? ' <a href="#" class="mainwp-assistant-error-retry" data-retry-message="' + escapeHtml( lastUserMessage ) + '" data-docs-bypass="' + ( requestDocsHandoffBypass ? '1' : '0' ) + '">' + escapeHtml( l10n( 'try_again', 'Try again' ) ) + '</a>'
            : '';

        let html = '<div class="mainwp-mintlify-message mainwp-mintlify-error-message">' +
                       '<div class="ui red message">' +
                           escapeHtml( displayText ) + linkHtml + retryHtml +
                       '</div>' +
                   '</div>';

        let $bubble = $( html );
        // Button actions sit on their own line: the theme zeroes button margins
        // with !important, so a margin on the button itself never applies.
        let appendErrorAction = function( action ) {
            if ( action && typeof action !== 'string' ) {
                $bubble.find( '.ui.red.message' ).append( $( '<div class="mainwp-assistant-error-actions"></div>' ).append( action ) );
            }
        };
        appendErrorAction( link );
        if ( errorCode === 'chatgpt_plan_reconnect' ) {
            checkProviderAvailability( 0, function() {
                $bubble.find( '.mainwp-assistant-error-actions, .mainwp-assistant-error-link' ).remove();
                appendErrorAction( buildErrorDeepLink( errorCode ) );
            } );
        }

        // The provider wrote this text, so it goes in as a text node and never
        // through innerHTML or string concatenation. It is what separates a
        // per-minute cap from a daily one, which we must not guess at.
        let providerMessage = errorData && errorData.provider_message;
        if ( providerMessage ) {
            let $detail = $( '<div class="mainwp-assistant-error-detail"></div>' );
            $detail[0].textContent = String( providerMessage );
            $bubble.find( '.ui.red.message' ).append( $detail );
        }

        let retryAfter = errorData ? parseInt( errorData.retry_after, 10 ) : 0;
        if ( errorCode === 'provider_rate_limited' && retryAfter > 0 ) {
            startRetryCountdown( $bubble.find( '.mainwp-assistant-error-retry' ), retryAfter );
        }

        $messages.append( $bubble );
        if ( ! isBatchRendering ) {
            scrollToBottom();
        }
    }

    /**
     * Hold a bubble's Try again link for the provider's advisory cooldown.
     *
     * The cooldown is advisory, not a promise from the provider, so the link
     * only waits it out and then restores itself.
     *
     * @param {Object} $retry  jQuery-wrapped retry link, possibly empty.
     * @param {number} seconds Cooldown length in seconds.
     */
    function startRetryCountdown( $retry, seconds ) {
        if ( ! $retry.length ) {
            return;
        }

        let remaining = seconds;
        let label = l10n( 'retry_countdown', 'Try again in %ds' );

        $retry.addClass( 'mainwp-assistant-error-retry-waiting' ).text( label.replace( '%d', remaining ) );

        let timer = setInterval( function() {
            remaining--;
            if ( remaining > 0 ) {
                $retry.text( label.replace( '%d', remaining ) );
                return;
            }

            clearInterval( timer );
            $retry.removeClass( 'mainwp-assistant-error-retry-waiting' ).text( l10n( 'try_again', 'Try again' ) );
        }, 1000 );
    }

    /**
     * Build a settings deep link for error codes the user can act on.
     *
     * Provider-layer codes link to the Connectors screen; consent and
     * registration codes link to the Assistant settings page.
     *
     * @param {string} errorCode Server error code, if any.
     * @return {string|Object} Link element or legacy markup, empty when no action is available.
     */
    function buildErrorDeepLink( errorCode ) {
        if ( ! errorCode ) {
            return '';
        }

        if ( errorCode === 'chatgpt_plan_limit' ) {
            return $( '<a class="ui mini green button mainwp-assistant-error-link" target="_blank" rel="noopener noreferrer"></a>' )
                .attr( 'href', chatgptPlan.manage_usage_url || 'https://chatgpt.com/settings/usage' )
                .text( planText( 'manage' ) );
        }
        if ( errorCode === 'chatgpt_plan_reconnect' ) {
            if ( ! planCanConnect() ) {
                return '';
            }
            return $( '<button type="button" class="ui mini green button mainwp-assistant-error-link" data-oai-siwc-button></button>' )
                .text( planText( chatgptPlan.status === 'plan_disabled' ? 'turn_on' : 'continue' ) )
                .on( 'click', function() { window.MainWPAssistantChatGPT.open( false ); } );
        }
        if ( errorCode.indexOf( 'chatgpt_plan_' ) === 0 ) {
            return '';
        }
        if ( ( errorCode === 'no_provider' || errorCode === 'no_usable_provider' || errorCode === 'provider_key_rejected' ) && ! wizardReady() && connectionChoicesReady() && providerReasonCode !== 'wp_version' ) {
            return $( '<button type="button" class="ui mini green button mainwp-assistant-error-link"></button>' )
                .text( l10n( 'wizard_link_text', 'Connect AI' ) )
                .on( 'click', function() { window.MainWPAssistantChatGPT.openChoices( 'error_bubble' ); } );
        }

        // The wizard is a shorter route than the Connectors screen for each of
        // these, and it is the only route that ends in a working free key.
        if ( ( errorCode === 'no_provider' || errorCode === 'no_usable_provider' || errorCode === 'provider_key_rejected' ) && wizardReady() ) {
            return ' <a href="#" class="mainwp-assistant-error-link mainwp-assistant-wizard-link">' +
                escapeHtml( l10n( 'wizard_link_text', 'Connect AI' ) ) + '</a>';
        }

        // Nothing on the Connectors screen helps below WordPress 7.0.
        if ( errorCode === 'no_provider' && providerReasonCode === 'wp_version' ) {
            return '';
        }

        let url = '';
        let text = '';
        // provider_key_rejected lands here when the wizard is unavailable —
        // typically a stored-but-rejected key, where availability still reads
        // true — and the bubble must never leave the user without a route.
        if ( errorCode === 'no_provider' || errorCode === 'no_usable_provider' || errorCode === 'provider_key_rejected' || errorCode === 'ai_result_parse_failed' ) {
            url = l10n( 'connectors_settings_url', '' );
            text = l10n( 'connectors_link_text', 'Open Connectors settings' );
        } else if ( errorCode === 'bridge_no_consent' ) {
            url = l10n( 'assistant_settings_url', '' );
            text = l10n( 'assistant_settings_link', 'Open Assistant settings' );
        }

        if ( ! url ) {
            return '';
        }

        return ' <a href="' + escapeHtml( url ) + '" class="mainwp-assistant-error-link">' + escapeHtml( text ) + '</a>';
    }

    /**
     * Parse basic markdown to HTML.
     *
     * @param {string} text Raw text with markdown.
     * @return {string} HTML string.
     */
    function parseMarkdown( text ) {
        if ( ! text ) {
            return '';
        }

        // Escape HTML first to prevent XSS.
        let escaped = escapeHtml( text );

        // Extract suggestions block before code-fence regex consumes it.
        // Bridge returns: ```suggestions\n(Label)[/relative/path]\n```
        escaped = escaped.replace( /```suggestions\s*([\s\S]*?)```/g, function( match, body ) {
            var links = [];
            // Suggestion format: (Label)[/path].
            body.replace( /\(([^)]+)\)\[([^\]]+)\]/g, function( m, label, path ) {
                var href = '';
                if ( path.match( /^https?:\/\//i ) ) {
                    href = path;
                } else if ( path.match( /^\// ) ) {
                    href = 'https://docs.mainwp.com' + path;
                }
                if ( href ) {
                    links.push( '<a href="' + href + '" target="_blank" rel="noopener" class="mainwp-mintlify-suggestion-link">' + label + '</a>' );
                }
                return '';
            } );
            if ( links.length ) {
                return '<div class="mainwp-mintlify-suggestions">' + links.join( '' ) + '</div>';
            }
            return '';
        } );

        escaped = escaped.replace( /```([\s\S]*?)```/g, '<pre><code>$1</code></pre>' );

        escaped = escaped.replace( /`([^`]+)`/g, '<code>$1</code>' );

        escaped = escaped.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );

        escaped = escaped.replace( /\*([^*]+)\*/g, '<em>$1</em>' );

        escaped = escaped.replace( /\[([^\]]+)\]\(([^)]+)\)/g, function( match, linkText, url ) {
            var href = '';
            if ( url.match( /^https?:\/\//i ) ) {
                href = url;
            } else if ( url.match( /^\// ) ) {
                // Relative URL from Bridge docs, so prefix the docs base.
                href = 'https://docs.mainwp.com' + url;
            }
            if ( href ) {
                return '<a href="' + href + '" target="_blank" rel="noopener">' + linkText + '</a>';
            }
            return match;
        } );

        // Convert headings (h4 before h3 before h2, longest prefix first).
        escaped = escaped.replace( /^####\s+(.+)$/gm, '<h4>$1</h4>' );
        escaped = escaped.replace( /^###\s+(.+)$/gm, '<h3>$1</h3>' );
        escaped = escaped.replace( /^##\s+(.+)$/gm, '<h2>$1</h2>' );

        escaped = escaped.replace( /^(?:---|\*\*\*)$/gm, '<hr>' );

        // Keep numbered items in one list across optional blank lines.
        escaped = escaped.replace( /(<pre><code>[\s\S]*?<\/code><\/pre>)|^\d+\. [^\r\n]+(?:\r?\n(?:[ \t]*\r?\n)*\d+\. [^\r\n]+)*(?:\r?\n(?!- ))?/gm, function( block, codeBlock ) {
            if ( codeBlock ) {
                return block;
            }
            var items = block.trim().split( /\r?\n/ ).filter( function( item ) {
                return item.trim() !== '';
            } );
            var start = items[0].match( /^\d+/ )[0];
            var lis = items.map( function( item ) {
                return '<li>' + item.replace( /^\d+\. /, '' ) + '</li>';
            } ).join( '' );
            return '<ol' + ( start === '1' ? '' : ' start="' + start + '"' ) + '>' + lis + '</ol>';
        } );

        // Convert unordered lists after numbering, before they consume line breaks.
        escaped = escaped.replace( /(?:^- .+$\n?)+/gm, function( block ) {
            var items = block.trim().split( '\n' );
            var lis = items.map( function( item ) {
                return '<li>' + item.replace( /^- /, '' ) + '</li>';
            } ).join( '' );
            return '<ul>' + lis + '</ul>';
        } );

        // Convert blockquotes (consecutive "> " lines, escaped as "&gt; ").
        escaped = escaped.replace( /(?:^&gt; .+$\n?)+/gm, function( block ) {
            var lines = block.trim().split( '\n' );
            var content = lines.map( function( line ) {
                return line.replace( /^&gt; /, '' );
            } ).join( '<br>' );
            return '<blockquote>' + content + '</blockquote>';
        } );

        // Convert tables (consecutive "|"-delimited lines).
        escaped = escaped.replace( /(?:^\|.+\|$\n?)+/gm, function( block ) {
            var rows = block.trim().split( '\n' );
            if ( rows.length < 2 ) {
                return block;
            }
            var html = '<table>';
            var headers = rows[0].split( '|' ).filter( function( c ) { return c.trim(); } );
            html += '<thead><tr>' + headers.map( function( h ) {
                return '<th>' + h.trim() + '</th>';
            } ).join( '' ) + '</tr></thead>';
            // Skip separator row (row[1] with dashes), process body.
            html += '<tbody>';
            for ( var i = 2; i < rows.length; i++ ) {
                var cells = rows[i].split( '|' ).filter( function( c ) { return c.trim(); } );
                html += '<tr>' + cells.map( function( c ) {
                    return '<td>' + c.trim() + '</td>';
                } ).join( '' ) + '</tr>';
            }
            html += '</tbody></table>';
            return html;
        } );

        escaped = escaped.replace( /\n/g, '<br>' );

        return escaped;
    }

    /**
     * Render executed-ability chips for an assistant message.
     *
     * One compact label per ability that actually ran, so the conversation
     * itself records what the agent did and against how many targets.
     *
     * @param {Array} abilities Array of chip objects from the server.
     * @return {string} HTML string, empty when nothing executed.
     */
    function renderExecutedAbilityChips( abilities ) {
        if ( ! abilities || ! abilities.length ) {
            return '';
        }

        let chips = abilities.map( function( ability ) {
            let color = ability.success ? 'green' : 'red';
            let icon = ability.success ? 'check' : 'exclamation triangle';
            let label = escapeHtml( ability.label || ability.ability || '' );
            let countText = ability.target_count ? ' (' + parseInt( ability.target_count, 10 ) + ')' : '';
            return '<span class="ui mini ' + color + ' label" data-tooltip="' + escapeHtml( ability.ability || '' ) + '" data-inverted="" data-position="top center">' +
                       '<i class="' + icon + ' icon"></i>' + label + countText +
                   '</span>';
        } ).join( '' );

        return '<div class="mainwp-assistant-ability-chips">' + chips + '</div>';
    }

    /**
     * Render source citations.
     *
     * @param {Array} sources Array of source objects.
     * @return {string} HTML string.
     */
    function renderSources( sources ) {
        if ( ! sources || ! sources.length ) {
            return '';
        }

        let links = '';

        sources.forEach( function( source ) {
            let url = source.url || '';
            let title = source.title || source.url || 'Source';

            if ( title.length > 50 ) {
                title = title.substring( 0, 47 ) + '...';
            }

            if ( url && url.match( /^https?:\/\//i ) ) {
                links += '<a href="' + escapeHtml( url ) + '" target="_blank" rel="noopener" class="mainwp-mintlify-source-link">' +
                            escapeHtml( title ) +
                        '</a>';
            }
        } );

        // Don't render the container/header if no valid links were produced.
        if ( ! links ) {
            return '';
        }

        return '<div class="mainwp-mintlify-sources">' +
                   '<div class="mainwp-mintlify-sources-header">' + l10n( 'sources_header', 'Sources:' ) + '</div>' +
                   links +
               '</div>';
    }

    /**
     * Format a message timestamp for display.
     *
     * @param {Date|number} when Date object, or a Unix epoch in seconds. Omit for now.
     * @return {string} 'HH:MM AM/PM' for today, 'Mon D, HH:MM AM/PM' for older messages.
     */
    function formatTimestamp( when ) {
        let date;
        if ( when instanceof Date ) {
            date = when;
        } else if ( typeof when === 'number' && when > 0 ) {
            // Stored messages carry their created_at as a Unix epoch (seconds).
            date = new Date( when * 1000 );
        } else {
            date = new Date();
        }

        let hours = date.getHours();
        let minutes = date.getMinutes();
        let ampm = hours >= 12 ? 'PM' : 'AM';

        hours = hours % 12;
        hours = hours ? hours : 12; // Hour '0' should be '12'.
        minutes = minutes < 10 ? '0' + minutes : minutes;

        let time = hours + ':' + minutes + ' ' + ampm;

        // Older messages carry a short date so a restored transcript
        // doesn't read as if everything happened today.
        if ( date.toDateString() !== new Date().toDateString() ) {
            return date.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } ) + ', ' + time;
        }

        return time;
    }

    /**
     * Scroll message container to bottom.
     *
     * @param {boolean} instant Jump without the 200ms animation. Used when restoring a
     *                          transcript, where the animated scroll would visibly crawl.
     */
    function scrollToBottom( instant ) {
        if ( ! $messages || ! $messages.length ) {
            return;
        }
        if ( instant ) {
            $messages[0].scrollTop = $messages[0].scrollHeight;
        } else {
            $messages.animate( {
                scrollTop: $messages[0].scrollHeight
            }, 200 );
        }
    }

    /**
     * Set input enabled/disabled state.
     *
     * This is the canonical reset point for `isSending`. Every exit path from
     * sendMessage/sendToAPI (sync .done/.fail, async chat job completion/error/
     * timeout, batch completion/expiry/timeout, stop generation, confirmation
     * dialog, and policy denial) calls setInputState(true), which clears the
     * isSending guard and re-enables the UI.
     *
     * @param {boolean} enabled Whether input should be enabled.
     */
    function setInputState( enabled ) {
        if ( enabled ) {
            isSending = false;
        }
        $input.prop( 'disabled', ! enabled );
        $sendButton.prop( 'disabled', ! enabled );

        // Also disable history list action buttons if the panel is already open.
        let $historyActions = $( '.mainwp-history-load, .mainwp-history-delete' );
        $historyActions.prop( 'disabled', ! enabled );

        $( '.mainwp-mode-pill' ).toggleClass( 'disabled', ! enabled ).prop( 'disabled', ! enabled );

        if ( enabled ) {
            $sendButton.removeClass( 'disabled' );
            $historyActions.removeClass( 'disabled' );
        } else {
            $sendButton.addClass( 'disabled' );
            $historyActions.addClass( 'disabled' );
        }
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} text Raw text.
     * @return {string} Escaped text.
     */
    function escapeHtml( text ) {
        if ( text == null ) return ''; // eslint-disable-line eqeqeq -- intentional: catch null & undefined, preserve 0.
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String( text ).replace( /[&<>"']/g, function( m ) { return map[ m ]; } );
    }

    // =========================================================================
    // MODE INDICATOR
    // =========================================================================

    // isAgentRefusal() lives in mainwp-assistant-chat-logic.js (loaded as a
    // script dependency before this file) so the browser and the Node test
    // suite (tests/js/test-mode-guards.js) share one copy. Call sites pass
    // userModeOverride explicitly since the shared copy has no closure access
    // to it.
    var isAgentRefusal = MainWPAssistantChatLogic.isAgentRefusal;

    /**
     * Converge the UI after the server refused an explicit Agent request.
     *
     * Mirrors a manual Ask pill click (override + persisted preference), marks
     * the Agent pill as needing setup, and posts a one-time notice with a
     * Connectors link. Self-limiting: once converged, re-selecting Agent hits
     * the availableProviderName === false hard block.
     */
    function convergeAfterAgentRefusal() {
        availableProviderName = false;
        requestMode = 'ask';
        userModeOverride = 'ask';
        localStorage.setItem( 'mainwp_assistant_mode_preference', 'ask' );
        updateModeIndicator( 'ask', null );
        setAgentPillUnavailable( true );
        appendSystemMessageWithLink(
            l10n( 'agent_fallback_notice', 'Answered from MainWP docs. Sites mode needs an AI connection with a provider API key under Settings > Connectors.' ),
            l10n( 'connectors_settings_url', '' ),
            l10n( 'connectors_link_text', 'Open Connectors settings' )
        );
    }

    /**
     * Toggle the needs-setup state on the Agent pill.
     *
     * The pill stays clickable so its tooltip and the hard block in
     * applyModeChange() can explain why Agent is unavailable.
     *
     * @param {boolean} unavailable True to mark the pill, false to clear it.
     */
    function setAgentPillUnavailable( unavailable ) {
        $( '.mainwp-mode-pill[data-mode="agent"]' ).toggleClass( 'needs-setup', unavailable !== false );
    }

    /**
     * Append a system message with an optional trailing link.
     *
     * Same structure as appendSystemMessage(), built from jQuery elements so
     * the link can carry a safe href without raw HTML injection.
     *
     * @param {string} text     The message text.
     * @param {string} url      Link href ('' skips the link).
     * @param {string} linkText Link label.
     */
    function appendSystemMessageWithLink( text, url, linkText ) {
        let $content = $( '<div class="message-content"></div>' )
            .append( '<i class="info circle icon"></i> ' )
            .append( document.createTextNode( text + ' ' ) );

        if ( url ) {
            $content.append(
                $( '<a></a>' )
                    .attr( 'href', url )
                    .text( linkText )
            );
        }

        $messages.append(
            $( '<div class="mainwp-mintlify-message mainwp-mintlify-system-message"></div>' ).append( $content )
        );
        if ( ! isBatchRendering ) {
            scrollToBottom();
        }
    }

    /**
     * Invalidate the offer and its handlers when the chat context changes.
     */
    function clearDocsHandoffOffer( keepUserMessage ) {
        if ( $activeDocsHandoffOffer ) {
            if ( ! keepUserMessage ) {
                if ( $docsHandoffUserBubble ) {
                    $docsHandoffUserBubble.remove();
                }
                let lastMessage = messageHistory[ messageHistory.length - 1 ];
                if ( lastMessage && lastMessage.role === 'user' && lastMessage.content === docsHandoffMessage ) {
                    messageHistory.pop();
                }
            }
            $docsHandoffUserBubble = null;
            docsHandoffMessage = null;
            docsHandoffAwaitingProvider = null;
            $activeDocsHandoffOffer.find( 'button' ).prop( 'disabled', true );
            $activeDocsHandoffOffer.remove();
            $activeDocsHandoffOffer = null;
        }
    }

    /**
     * Offer a local choice before sending an explicit Docs action request.
     *
     * @param {string} userMessage Current request, retained only in memory.
     * @param {Object} data        Routing receipt.
     */
    function renderDocsHandoffOffer( userMessage, data ) {
        clearDocsHandoffOffer();
        let decision = MainWPAssistantChatLogic.resolveDocsHandoffAction( data );
        if ( ! decision.offer ) {
            return;
        }

        let $offer = $( '<div class="mainwp-mintlify-message mainwp-mintlify-system-message mainwp-assistant-handoff-offer"></div>' );
        let $content = $( '<div class="message-content"></div>' );
        $content.append( $( '<p></p>' ).text( l10n( 'handoff_explanation', 'Review this request in Sites. When you send it, Sites uses your configured AI provider.' ) ) );
        let $actions = $( '<div class="mainwp-assistant-handoff-actions"></div>' );

        // The element identity rejects queued clicks from removed or superseded offers.
        function takeOffer( keepUserMessage ) {
            if ( isSending || $activeDocsHandoffOffer !== $offer ) {
                return false;
            }
            clearDocsHandoffOffer( keepUserMessage );
            return true;
        }

        if ( decision.canContinue ) {
            $actions.append( $( '<button type="button"></button>' )
                .text( l10n( 'handoff_continue', 'Continue in Sites' ) )
                .on( 'click', function() {
                    if ( ! takeOffer() ) {
                        return;
                    }
                    if ( availableProviderName === false ) {
                        availableProviderName = null;
                    }
                    // A fresh conversation keeps the Docs transcript out of the Sites prompt.
                    reset();
                    applyModeChange( 'agent' );
                    $input.val( userMessage ).trigger( 'input' ).focus();
                } ) );
        } else {
            let prompt = connectPrompt();
            $content.append( $( '<p></p>' ).text( prompt === 'version'
                ? l10n( 'mode_wp_version', 'Sites mode needs WordPress 7.0 or newer. Docs mode works on this version.' )
                : l10n( 'mode_no_provider', 'No AI connection is configured.' ) ) );
            if ( prompt === 'card' && connectionChoicesReady() ) {
                $content.append( buildConnectCard( 'docs_handoff', false ).children() );
            } else if ( prompt !== 'version' && l10n( 'connectors_settings_url', '' ) ) {
                $content.append( $( '<a></a>' )
                    .attr( 'href', l10n( 'connectors_settings_url', '' ) )
                    .text( l10n( 'connectors_link_text', 'Open Connectors settings' ) ) );
            }
        }

        $actions.append( $( '<button type="button"></button>' )
            .text( l10n( 'handoff_ask_docs', 'Ask Docs instead' ) )
            .on( 'click', function() {
                if ( takeOffer( true ) ) {
                    sendMessage( userMessage, false, 'ask', { docsHandoffBypass: true } );
                }
            } ) );
        $actions.append( $( '<button type="button"></button>' )
            .text( l10n( 'handoff_dismiss', 'Dismiss' ) )
            .on( 'click', function() {
                if ( ! takeOffer() ) {
                    return;
                }
                $input.val( userMessage ).trigger( 'input' ).focus();
            } ) );

        $offer.append( $content.append( $actions ) );
        $activeDocsHandoffOffer = $offer;
        docsHandoffAwaitingProvider = decision.canContinue ? null : { userMessage: userMessage, data: data };
        let $lastUserBubble = $messages.find( '.mainwp-mintlify-user-message' ).last();
        let isProvisional = $lastUserBubble.length &&
            $lastUserBubble.find( '.message-content' ).clone().find( 'br' ).replaceWith( '\n' ).end().text() === userMessage &&
            ! $lastUserBubble.nextAll( '.mainwp-mintlify-assistant-message' ).length;
        $docsHandoffUserBubble = isProvisional ? $lastUserBubble : null;
        docsHandoffMessage = isProvisional ? userMessage : null;
        $messages.append( $offer );
        scrollToBottom();
        $offer.find( 'button' ).first().focus();
    }

    /**
     * A plan connected from the offer's own choices makes the request
     * sendable in Sites, so the offer gains Continue in Sites in place.
     */
    function refreshDocsHandoffOffer() {
        let pending = docsHandoffAwaitingProvider;
        if ( ! pending || ! $activeDocsHandoffOffer ) {
            return;
        }
        clearDocsHandoffOffer( true );
        renderDocsHandoffOffer( pending.userMessage, Object.assign( {}, pending.data, { agent_available: true } ) );
    }

    // resolveConnectPrompt() lives in mainwp-assistant-chat-logic.js so the
    // browser and tests/js/test-onboarding-wizard.js share one copy.
    var resolveConnectPrompt = MainWPAssistantChatLogic.resolveConnectPrompt;

    // Per browser, not per user: the card is a nudge, and someone who waved it
    // away on the welcome screen should not meet it again on every open.
    var CONNECT_CARD_DISMISSED_KEY = 'mainwp_assistant_connect_card_dismissed';

    /**
     * What the current no-provider state should offer the user.
     *
     * @return {string} 'card', 'version' or 'legacy'.
     */
    function connectPrompt() {
        if ( connectionChoicesReady() && ( providerReasonCode === 'no_provider' || providerReasonCode === 'chatgpt_plan_reconnect' || chatgptPlan.status === 'needs_reconnect' || chatgptPlan.status === 'plan_disabled' ) ) {
            return 'card';
        }
        return resolveConnectPrompt( {
            reason_code: providerReasonCode,
            wizard_available: wizardAvailable
        } );
    }

    /**
     * Whether the connect wizard is rendered and openable on this page.
     *
     * @return {boolean} True when the site-key wizard can be opened.
     */
    function wizardReady() {
        return wizardAvailable &&
            typeof window.MainWPAssistantOnboarding !== 'undefined' &&
            window.MainWPAssistantOnboarding.isAvailable();
    }

    function connectionChoicesReady() {
        return typeof window.MainWPAssistantChatGPT !== 'undefined';
    }

    function planText( key ) {
        return ( ( mainwpMintlifyChatL10n.chatgpt || {} ).strings || {} )[ key ] || '';
    }

    function planCanConnect() {
        return connectionChoicesReady() && ( mainwpMintlifyChatL10n.chatgpt || {} ).can_connect === true;
    }

    function updatePlanIndicator() {
        let $indicator = $( '#mainwp-assistant-plan-indicator' );
        if ( ! $indicator.length ) {
            $indicator = $( '<span id="mainwp-assistant-plan-indicator"></span>' );
            $( '.mainwp-mode-pill[data-mode="agent"]' ).parent().after( $indicator );
        }
        // Docs answers come from MainWP's documentation service, never the plan.
        let usingPlan = chatgptPlan.connected && planCanConnect() && 'agent' === currentMode;
        $indicator.empty().prop( 'hidden', ! usingPlan );
        if ( usingPlan ) {
            $indicator.append( $( '<span></span>' ).text( planText( 'using' ) ) );
            $indicator.append( $( '<a target="_blank" rel="noopener noreferrer"></a>' )
                .attr( 'href', chatgptPlan.manage_usage_url || 'https://chatgpt.com/settings/usage' ).text( planText( 'manage' ) ) );
        }
    }

    /**
     * Build connection choices as a chat message.
     *
     * @param {string}  source      Launch source passed to the wizard.
     * @param {boolean} dismissible Whether to draw the dismiss control.
     * @return {Object} jQuery-wrapped message element.
     */
    function buildConnectCard( source, dismissible ) {
        let $card = $( '<div class="ui segment mainwp-assistant-connect-card"></div>' );

        $card.append( window.MainWPAssistantChatGPT.choices( source, true ) );

        if ( dismissible ) {
            $card.append(
                $( '<button type="button" class="mainwp-assistant-connect-dismiss"><i class="times icon"></i></button>' )
                    .attr( 'aria-label', l10n( 'connect_card_dismiss', 'Dismiss' ) )
                    .on( 'click', function() {
                        try {
                            localStorage.setItem( CONNECT_CARD_DISMISSED_KEY, '1' );
                        } catch ( e ) {
                            // Private browsing or a full quota: the card simply
                            // returns on the next panel open.
                        }
                        $card.closest( '.mainwp-mintlify-message' ).remove();
                    } )
            );
        }

        return $( '<div class="mainwp-mintlify-message mainwp-mintlify-system-message"></div>' ).append( $card );
    }

    /**
     * Append the connect card to the chat stream.
     *
     * @param {string}  source      Launch source passed to the wizard.
     * @param {boolean} dismissible Whether to draw the dismiss control.
     * @return {boolean} False when no connection interface is available, so the caller
     *                   can fall back to its own message.
     */
    function renderConnectCard( source, dismissible ) {
        if ( ! connectionChoicesReady() ) {
            return false;
        }

        // Repeated no-provider triggers in one conversation would otherwise
        // stack identical cards. The card moves to the newest trigger instead
        // of being skipped, because an earlier one is now buried under the
        // turns that followed it. A dismissed card was removed from the DOM,
        // so nothing here brings it back.
        $messages.find( '.mainwp-assistant-connect-card' ).closest( '.mainwp-mintlify-message' ).remove();

        $messages.append( buildConnectCard( source, dismissible === true ) );
        if ( ! isBatchRendering ) {
            scrollToBottom();
        }
        return true;
    }

    /**
     * Show the connect card once on panel open, above the welcome state.
     *
     * Only on a fresh panel: an open conversation gets the card from the turn
     * that actually needed Sites mode.
     */
    function maybeRenderWelcomeConnectCard() {
        if ( 'card' !== connectPrompt() || ! connectionChoicesReady() ) {
            return;
        }

        if ( ! $messages.find( '.mainwp-mintlify-welcome' ).length ) {
            return;
        }

        if ( $messages.find( '.mainwp-assistant-connect-card' ).length ) {
            return;
        }

        try {
            if ( localStorage.getItem( CONNECT_CARD_DISMISSED_KEY ) ) {
                return;
            }
        } catch ( e ) {
            // Unreadable storage means "not dismissed"; showing the card is the
            // safe side of that guess.
        }

        renderConnectCard( 'welcome', true );
    }

    /**
     * Update mode indicator dropdown.
     *
     * @param {string} mode         The mode: 'ask' or 'agent'.
     * @param {string} providerName Optional provider name for Agent mode.
     */
    function updateModeIndicator( mode, providerName ) {
        currentMode = mode;
        currentProvider = providerName;

        syncPillToggle( mode );
        updateInfoBar( mode );
        updatePlanIndicator();
    }

    /**
     * Apply a mode change requested from the pill toggle.
     *
     * @param {string} value The selected mode ('ask' or 'agent').
     */
    function applyModeChange( value ) {
        clearDocsHandoffOffer();
        // Guard: Don't switch during active requests or before provider check completes.
        if ( abortController || ! providerCheckComplete ) {
            syncPillToggle( requestMode );
            return;
        }

        if ( value === 'agent' ) {
            // Switch to Agent: Block only when we know no provider is configured (false).
            if ( availableProviderName === false ) {
                syncPillToggle( requestMode );
                showModeDropdownError();
                return;
            }
            requestMode = 'agent';
            userModeOverride = 'agent';
            localStorage.setItem( 'mainwp_assistant_mode_preference', 'agent' );
            currentMode = 'agent';
            currentProvider = availableProviderName || 'AI';
            // Latency expectations live in the always-visible agent info bar,
            // not a one-shot hint that first-time agent users would miss later.
        } else {
            // Switch to Ask: Always allowed.
            requestMode = 'ask';
            userModeOverride = 'ask';
            localStorage.setItem( 'mainwp_assistant_mode_preference', 'ask' );
            currentMode = 'ask';
            currentProvider = null;
        }

        syncPillToggle( value );
        updateInfoBar( value );
        updatePlanIndicator();
    }

    /**
     * Sync pill toggle buttons with the current mode.
     *
     * @param {string} mode The mode to sync to ('ask' or 'agent').
     */
    function syncPillToggle( mode ) {
        // Roving tabindex: only the selected pill is a tab stop, so the
        // radiogroup is reached once and arrow keys move within it.
        $( '.mainwp-mode-pill' ).removeClass( 'active' ).attr( 'aria-checked', 'false' ).attr( 'tabindex', '-1' );
        $( '.mainwp-mode-pill[data-mode="' + mode + '"]' ).addClass( 'active' ).attr( 'aria-checked', 'true' ).attr( 'tabindex', '0' );
    }

    /**
     * Update the info bar message based on the current mode.
     *
     * @param {string} mode The mode ('ask' or 'agent').
     */
    function updateInfoBar( mode ) {
        var $askMsg = $( '.info-bar-ask' );
        var $agentMsg = $( '.info-bar-agent' );

        $askMsg.css( 'visibility', mode === 'agent' ? 'hidden' : 'visible' );
        $agentMsg.css( 'visibility', mode === 'agent' ? 'visible' : 'hidden' );
    }

    /**
     * Show error message below mode selector when no AI provider is configured.
     */
    function showModeDropdownError() {
        let prompt = connectPrompt();

        // The card is the answer to "why can't I switch", so it replaces the
        // six-second error rather than joining it.
        if ( 'card' === prompt && renderConnectCard( 'mode_switch' ) ) {
            return;
        }

        let $selector = $( '.mainwp-assistant-mode-selector' );

        $selector.next( '.mainwp-assistant-mode-error' ).remove();

        let isVersionBlock = 'version' === prompt;
        let errorText = isVersionBlock
            ? l10n( 'mode_wp_version', 'Sites mode needs WordPress 7.0 or newer. Docs mode works on this version.' )
            : l10n( 'mode_no_provider', 'No AI connection is configured.' );
        let $error = $( '<div class="mainwp-assistant-mode-error"></div>' ).text( errorText + ' ' );

        // Below WordPress 7.0 the Connectors screen does not exist, so the
        // message stands alone.
        let settingsUrl = isVersionBlock ? '' : l10n( 'connectors_settings_url', '' );
        if ( settingsUrl ) {
            $error.append(
                $( '<a></a>' )
                    .attr( 'href', settingsUrl )
                    .text( l10n( 'connectors_link_text', 'Open Connectors settings' ) )
            );
        }

        $selector.after( $error );

        // Auto-remove after 6 seconds, long enough to click the link.
        setTimeout( function() {
            $error.fadeOut( 200, function() {
                $error.remove();
            } );
        }, 6000 );
    }

    /**
     * Show error message below mode selector when a provider/model switch fails.
     *
     * @param {string} message The error text to display.
     */
    function showSwitchError( message ) {
        let $selector = $( '.mainwp-assistant-mode-selector' );
        $selector.next( '.mainwp-assistant-switch-error' ).remove();
        let $error = $( '<div class="mainwp-assistant-switch-error"></div>' ).text( message );
        $selector.after( $error );
        setTimeout( function() {
            $error.fadeOut( 200, function() {
                $error.remove();
            } );
        }, 3000 );
    }

    // =========================================================================
    // CONTROL BUTTONS (STOP, REGENERATE)
    // =========================================================================

    /**
     * Common cleanup after stopping generation: stop indicator, reset button,
     * show system message, and re-enable input.
     *
     * @param {string} message System message to display.
     */
    function finalizeStop( message ) {
        stopThinkingIndicator();
        setSendButtonDefault();
        appendSystemMessage( message );
        setInputState( true );
        $input.focus();
    }

    /**
     * Ask the server to cancel a running chat job.
     *
     * The local teardown below is optimistic; this call is what actually
     * halts the server-side loop before its next model round-trip. Worst
     * case one in-flight turn completes after the click.
     *
     * @param {string} jobId The chat job ID.
     */
    function requestServerCancel( jobId ) {
        if ( ! jobId ) {
            return;
        }
        $.post( ajaxurl, mainwp_secure_data( {
            action: 'mainwp_assistant_cancel_job',
            job_id: jobId
        } ) );
    }

    /**
     * Handle stop generation button click.
     */
    function handleStopGeneration() {
        // The fail callback handles its own UI cleanup (stops thinking,
        // restores button, shows message, re-enables input).
        if ( abortController ) {
            abortController.abort();
            abortController = null;
        }

        // Stop batch job polling and clean up UI.
        // Only needed in the polling phase; the initial AJAX
        // already completed so abortController is null and the fail
        // callback won't fire.
        //
        // There is no server-side batch-cancel endpoint, so Stop cannot halt
        // the operation. It only stops this tab's polling. Claiming the
        // operation stopped would be a lie, and discarding the localStorage
        // resume record would strand a batch that is still running. So keep
        // the resume state (a reload picks monitoring back up) and tell the
        // truth about what actually happened.
        if ( activeBatchJob ) {
            stopPolling();
            clearActiveJob( true );
            finalizeStop( l10n( 'batch_stop_monitoring', 'Stopped monitoring here. The batch operation keeps running on the server. Reload the page to resume monitoring it.' ) );
        }

        // Stop async chat job polling and clean up UI, telling the server
        // first so the job halts instead of running on unattended.
        if ( activeChatJob ) {
            requestServerCancel( activeChatJob.job_id );
            clearChatJob();
            finalizeStop( l10n( 'generation_stopped', 'Response generation stopped.' ) );
        }
    }

    /**
     * Switch the send button to stop mode (red with stop icon).
     */
    function setSendButtonStop() {
        $sendButton.addClass( 'is-stop' ).html( '<i class="stop icon"></i>' );
        // The accessible name moves with the icon: the button now stops.
        $sendButton.attr( 'aria-label', l10n( 'stop_generation', 'Stop' ) );
        $sendButton.prop( 'disabled', false ).removeClass( 'disabled' );
        $sendButton.off( 'click.mintlify' ).on( 'click.mintlify', function( e ) {
            e.preventDefault();
            e.stopPropagation();
            handleStopGeneration();
        } );
    }

    /**
     * Restore the send button to default mode (green with paper plane icon).
     */
    function setSendButtonDefault() {
        $sendButton.removeClass( 'is-stop' ).html( '<i class="paper plane icon"></i>' );
        $sendButton.attr( 'aria-label', l10n( 'send_message', 'Send message' ) );
        $sendButton.off( 'click.mintlify' ).on( 'click.mintlify', function( e ) {
            e.preventDefault();
            e.stopPropagation();
            sendMessage();
        } );
    }

    /**
     * Show the regenerate button on the last assistant message only.
     */
    function showRegenerateLink() {
        $messages.find( '.mainwp-assistant-regenerate-btn' ).hide();
        if ( ! resolveRegenerateAvailability( lastAssistantMessageId, isHistoryDisabled ) ) {
            return;
        }
        $messages.find( '.mainwp-mintlify-assistant-message:not(#mainwp-mintlify-thinking-indicator)' )
                 .last()
                 .find( '.mainwp-assistant-regenerate-btn' )
                 .show();
    }

    /**
     * Hide all regenerate buttons.
     */
    function hideRegenerateLinks() {
        if ( $messages ) {
            $messages.find( '.mainwp-assistant-regenerate-btn' ).hide();
        }
    }

    /**
     * Handle regenerate button click.
     * Resends the last user message.
     */
    function handleRegenerate() {
        // Guard: Exit when input is disabled (processing in progress).
        // Prevents concurrent requests during confirmation execution or batch operations.
        if ( $input.prop( 'disabled' ) ) {
            return;
        }

        if ( ! lastUserMessage || ! resolveRegenerateAvailability( lastAssistantMessageId, isHistoryDisabled ) ) {
            return;
        }

        // Store the last assistant message for potential restore on failure.
        // Hide it instead of removing so we can restore if regeneration fails.
        let $assistantMessages = $messages.find( '.mainwp-mintlify-assistant-message:not(#mainwp-mintlify-thinking-indicator)' );
        if ( $assistantMessages.length > 0 ) {
            let $lastMsg = $assistantMessages.last();
            let historyEntry = null;

            if ( messageHistory.length > 0 && messageHistory[ messageHistory.length - 1 ].role === 'assistant' ) {
                historyEntry = messageHistory.pop();
            }

            pendingRegeneration = {
                $element: $lastMsg,
                historyEntry: historyEntry
            };

            $lastMsg.hide();
        }

        // Resend last user message with regenerate flag to signal backend replacement.
        sendMessage( lastUserMessage, true, undefined, { docsHandoffBypass: true } );

        hideRegenerateLinks();
    }

    /**
     * Finalize regeneration - remove the hidden original message.
     * Called on successful regeneration.
     */
    function finalizeRegeneration() {
        if ( pendingRegeneration ) {
            // Drop the replaced bubble's spend from the running total; the
            // server replaced its row, so keeping it would double count.
            let usageKey = pendingRegeneration.$element.attr( 'data-usage-key' );
            if ( usageKey && sessionUsageByKey[ usageKey ] ) {
                delete sessionUsageByKey[ usageKey ];
                updateSessionCostLine();
            }
            pendingRegeneration.$element.remove();
            pendingRegeneration = null;
        }
    }

    /**
     * Restore original message after failed regeneration.
     * Called on failure or abort.
     */
    function restoreRegenerationOriginal() {
        if ( pendingRegeneration ) {
            pendingRegeneration.$element.show();

            if ( pendingRegeneration.historyEntry ) {
                messageHistory.push( pendingRegeneration.historyEntry );
            }

            pendingRegeneration = null;
        }
    }

    // =========================================================================
    // THINKING INDICATOR WITH TIMER
    // =========================================================================

    /**
     * Get phase-aware thinking indicator text.
     *
     * For agent mode, returns descriptive text based on the current async
     * processing phase reported by the poll endpoint. Falls back to
     * time-based messages when no phase is available.
     *
     * @param {string|null} phase   Current processing phase from poll.
     * @param {number}      elapsed Seconds since thinking started.
     * @return {string} Localised status text.
     */
    function getThinkingPhaseText( phase, elapsed ) {
        switch ( phase ) {
            case 'preparing':
                return l10n( 'thinking_preparing', 'Preparing request...' );
            case 'calling_api':
                if ( elapsed > 60 ) {
                    return l10n( 'thinking_api_long',
                        'Waiting for AI response... Sites queries typically take 1-3 minutes.' );
                }
                return l10n( 'thinking_api', 'Waiting for AI response...' );
            case 'executing_tools':
                return l10n( 'thinking_tools', 'Executing actions...' );
            case 'follow_up':
                return l10n( 'thinking_followup', 'Generating response...' );
            default:
                // Time-based fallback when no phase data is available.
                if ( elapsed < 5 ) {
                    return l10n( 'thinking', 'Thinking...' );
                } else if ( elapsed < 30 ) {
                    return l10n( 'thinking_api', 'Waiting for AI response...' );
                } else if ( elapsed < 120 ) {
                    return l10n( 'thinking_api_long',
                        'Waiting for AI response... Sites queries typically take 1-3 minutes.' );
                }
                return l10n( 'thinking_still_processing',
                    'Still processing... Complex queries take longer.' );
        }
    }

    /**
     * Start thinking indicator with elapsed time.
     */
    function startThinkingIndicator() {
        thinkingStartTime = Date.now();

        let html = '<div class="mainwp-mintlify-message mainwp-mintlify-loading-message mainwp-mintlify-assistant-message" id="mainwp-mintlify-thinking-indicator">' +
                       '<div class="message-content">' +
                           '<i class="notched circle loading icon"></i>' +
                           '<span id="mainwp-mintlify-thinking-text">' + l10n( 'thinking', 'Thinking...' ) + '</span>' +
                           '<span id="mainwp-mintlify-thinking-elapsed" style="margin-left: 0.5rem; opacity: 0.7;"></span>' +
                       '</div>' +
                   '</div>';

        $messages.append( html );
        scrollToBottom();

        thinkingInterval = setInterval( updateThinkingElapsed, 1000 );
    }

    /**
     * Update thinking elapsed time display.
     */
    function updateThinkingElapsed() {
        if ( ! thinkingStartTime ) {
            return;
        }

        let elapsed = Math.floor( ( Date.now() - thinkingStartTime ) / 1000 );

        // Safety timeout: if any agent-mode request exceeds the client-side
        // limit, force-terminate it. Covers two paths:
        //  1. Async (activeChatJob set): fallback AJAX hangs or polling was
        //     cleared before its timeout check could fire.
        //  2. Sync (history opt-out): the blocking AJAX to the AI provider
        //     exceeds the timeout with no async job to track.
        if ( currentMode === 'agent' && elapsed > ( CHAT_POLL_TIMEOUT_MS / 1000 ) ) {
            if ( activeChatJob ) {
                handleChatJobTimeout();
            } else {
                // Sync path (defense-in-depth): abort the in-flight AJAX.
                // The .fail() handler handles all UI cleanup using the flag.
                safetyTimeoutTriggered = true;
                if ( abortController ) {
                    abortController.abort();
                }
            }
            return;
        }

        if ( currentMode === 'agent' ) {
            let $thinkingText = $( '#mainwp-mintlify-thinking-text' );
            if ( $thinkingText.length ) {
                $thinkingText.text( getThinkingPhaseText( currentChatPhase, elapsed ) );
            }
        }

        let $elapsedText = $( '#mainwp-mintlify-thinking-elapsed' );

        if ( $elapsedText.length ) {
            let minutes = Math.floor( elapsed / 60 );
            let seconds = elapsed % 60;
            let timeStr = minutes > 0
                ? minutes + 'm ' + seconds + 's'
                : seconds + 's';
            $elapsedText.text( l10n( 'elapsed_time', '%s elapsed' ).replace( '%s', timeStr ) );
        }
    }

    /**
     * Stop thinking indicator.
     */
    function stopThinkingIndicator() {
        if ( thinkingInterval ) {
            clearInterval( thinkingInterval );
            thinkingInterval = null;
        }

        $( '#mainwp-mintlify-thinking-indicator' ).remove();
        thinkingStartTime = null;
        currentChatPhase = null;
    }

    // =========================================================================
    // COST DISCLOSURE
    // =========================================================================

    /**
     * Show inline token usage (and estimated price) on the last assistant
     * message, and register the spend in the session total.
     *
     * @param {Object|number} usageInfo Usage info object from parseUsageFromResponse (legacy number accepted).
     */
    function showMessageCost( usageInfo ) {
        let info = normalizeUsageInfo( usageInfo );
        let $lastMsg = $messages.find( '.mainwp-mintlify-assistant-message' ).last();
        let $costText = $lastMsg.find( '.mainwp-assistant-cost-text' );

        if ( ! $costText.length || ! info ) {
            return;
        }

        $costText.text( formatCostText( info ) ).show();
        registerBubbleUsage( $lastMsg, info );
    }

    /**
     * Parse usage and estimated cost from response metadata.
     *
     * The display* figures fold in a confirmation step when the server sent
     * display_usage/display_cost; tokens/usd always cover only this
     * message's own turn so session totals never double count.
     *
     * @param {Object} responseData Response data from server.
     * @return {Object|null} Usage info {tokens, usd, displayTokens, displayUsd, folded} or null.
     */
    function parseUsageFromResponse( responseData ) {
        if ( ! responseData ) {
            return null;
        }

        let tokens = extractTotalTokens( responseData.usage );
        if ( tokens == null ) {
            return null;
        }

        let usd = extractKnownUsd( responseData.cost );
        let displayTokens = tokens;
        let displayUsd = usd;
        let folded = false;

        let displayTotal = extractTotalTokens( responseData.display_usage );
        if ( displayTotal != null ) {
            displayTokens = displayTotal;
            displayUsd = extractKnownUsd( responseData.display_cost );
            folded = true;
        }

        return {
            tokens: tokens,
            usd: usd,
            displayTokens: displayTokens,
            displayUsd: displayUsd,
            folded: folded,
            plan: !! ( responseData.cost && 'mainwp-chatgpt-plan' === responseData.cost.provider )
        };
    }

    /**
     * Read a total token count out of a usage array.
     *
     * @param {Object} usage Usage object or null.
     * @return {number|null} Total tokens or null.
     */
    function extractTotalTokens( usage ) {
        if ( ! usage ) {
            return null;
        }
        if ( usage.total_tokens != null ) {
            return usage.total_tokens;
        }
        if ( usage.prompt_tokens != null && usage.completion_tokens != null ) {
            return usage.prompt_tokens + usage.completion_tokens;
        }
        return null;
    }

    /**
     * Read a known USD figure out of a cost object.
     *
     * @param {Object} cost Cost object or null.
     * @return {number|null} USD figure, null when unknown.
     */
    function extractKnownUsd( cost ) {
        if ( cost && cost.known && cost.usd != null ) {
            return cost.usd;
        }
        return null;
    }

    /**
     * Accept either a usage info object or a bare token count.
     *
     * @param {Object|number|null} value Usage info, token count, or null.
     * @return {Object|null} Usage info object or null.
     */
    function normalizeUsageInfo( value ) {
        if ( value == null ) {
            return null;
        }
        if ( typeof value === 'number' ) {
            return { tokens: value, usd: null, displayTokens: value, displayUsd: null, folded: false };
        }
        return value.tokens != null ? value : null;
    }

    /**
     * Format the bubble cost line text.
     *
     * @param {Object} info Usage info object.
     * @return {string} Text for the cost line.
     */
    function formatCostText( info ) {
        let text;
        if ( info.plan ) {
            // Plan turns are paid for by the ChatGPT subscription, not per token.
            text = l10n( 'token_usage_plan', '%s tokens · ChatGPT plan' ).replace( '%s', info.displayTokens.toLocaleString() );
        } else if ( info.displayUsd != null ) {
            text = l10n( 'token_usage_cost', '%1$s tokens · est. %2$s' )
                .replace( '%1$s', info.displayTokens.toLocaleString() )
                .replace( '%2$s', formatUsd( info.displayUsd ) );
        } else {
            text = l10n( 'token_usage', '%s tokens' ).replace( '%s', info.displayTokens.toLocaleString() );
        }
        if ( info.folded ) {
            text += ' ' + l10n( 'cost_includes_confirmation', '(includes confirmation step)' );
        }
        return text;
    }

    /**
     * Format a USD figure for display.
     *
     * @param {number} usd USD amount.
     * @return {string} Formatted amount.
     */
    function formatUsd( usd ) {
        if ( usd >= 0.01 ) {
            return '$' + usd.toFixed( 2 );
        }
        if ( usd > 0 ) {
            return '$' + usd.toFixed( 4 );
        }
        return '$0.00';
    }

    /**
     * Register a bubble's unfolded spend in the session total.
     *
     * Keyed per bubble so regeneration can remove the replaced message's
     * entry instead of double counting.
     *
     * @param {Object} $bubble jQuery element for the message bubble.
     * @param {Object} info    Usage info object.
     */
    function registerBubbleUsage( $bubble, info ) {
        if ( ! info || info.tokens == null ) {
            return;
        }
        let key = $bubble.attr( 'data-usage-key' );
        if ( ! key ) {
            sessionUsageSeq += 1;
            key = 'u' + sessionUsageSeq;
            $bubble.attr( 'data-usage-key', key );
        }
        sessionUsageByKey[ key ] = { tokens: info.tokens, usd: info.usd };
        updateSessionCostLine();
    }

    /**
     * Reset the session usage total.
     */
    function resetSessionUsage() {
        sessionUsageByKey = {};
        updateSessionCostLine();
    }

    /**
     * Re-render the conversation running total from the per-bubble entries.
     */
    function updateSessionCostLine() {
        let $line = $( '#mainwp-assistant-session-cost' );
        if ( ! $line.length ) {
            return;
        }

        let totalTokens = 0;
        let totalUsd = 0;
        let partial = false;
        Object.keys( sessionUsageByKey ).forEach( function( key ) {
            let entry = sessionUsageByKey[ key ];
            totalTokens += entry.tokens || 0;
            if ( entry.usd != null ) {
                totalUsd += entry.usd;
            } else {
                partial = true;
            }
        } );

        if ( ! totalTokens ) {
            $line.hide().empty();
            return;
        }

        let text;
        if ( totalUsd > 0 ) {
            text = l10n( 'session_cost_label', '%1$s tokens · est. %2$s this conversation' )
                .replace( '%1$s', totalTokens.toLocaleString() )
                .replace( '%2$s', formatUsd( totalUsd ) + ( partial ? '+' : '' ) );
        } else {
            text = l10n( 'session_tokens_label', '%s tokens this conversation' )
                .replace( '%s', totalTokens.toLocaleString() );
        }

        $line.text( text ).show();
    }

    // =========================================================================
    // HISTORY PANEL
    // =========================================================================

    /**
     * Handle history disabled state from server.
     *
     * Called when the server reports history storage is off (user opted out). Clears the
     * in-memory transcript and the conversation id, and caches the flag locally.
     */
    function handleHistoryDisabled() {
        isHistoryDisabled = true;

        // Cached so a later provider check that fails on the network still knows the
        // user is opted out.
        localStorage.setItem( 'mainwp_assistant_history_disabled', 'true' );

        messageHistory = [];

        // Reset conversation ID to prevent sending stale IDs in opt-out sessions.
        setCurrentConversation( null );

        lastAssistantMessageId = null;
        hideRegenerateLinks();
    }

    /**
     * Clear the opt-out state when the server reports history storage is on.
     */
    function restoreHistoryControls() {
        isHistoryDisabled = false;

        // Cached for the same reason as the opt-out branch above.
        localStorage.setItem( 'mainwp_assistant_history_disabled', 'false' );
    }

    /**
     * Load a conversation from history.
     *
     * @param {number} conversationId Conversation ID.
     * @param {Object} [options]          Load options.
     * @param {boolean} [options.silent]  Session restore: swallow load errors and skip the
     *                                    per-message fade, so a stale id fails quietly.
     * @param {Function} [options.onLoaded] Called once the transcript is rendered.
     */
    function loadConversation( conversationId, options ) {
        clearDocsHandoffOffer();
        options = options || {};

        let isSilentRestore = options.silent === true;

        // Guard: Prevent loading conversations while a request is in flight.
        // This avoids race conditions where a pending response could be
        // appended to the newly loaded conversation instead of the original.
        if ( $input.prop( 'disabled' ) ) {
            return;
        }

        conversationId = parseInt( conversationId, 10 );
        if ( ! Number.isInteger( conversationId ) || conversationId <= 0 ) {
            if ( isSilentRestore ) {
                setCurrentConversation( null );
                return;
            }
            appendErrorMessage( l10n( 'conversation_load_error', 'Failed to load conversation' ) );
            return;
        }

        // Disable input during load to prevent message loss if user types while loading.
        isConversationLoadInProgress = true;
        setInputState( false );

        let data = {
            action: 'mainwp_assistant_load_conversation',
            conversation_id: conversationId
        };
        data = mainwp_secure_data( data );

        $.post( ajaxurl, data )
            .done( function( response ) {
                // Check for history_disabled flag (user opted out mid-session).
                if ( response && response.data && response.data.history_disabled ) {
                    handleHistoryDisabled();
                    finishConversationLoad();
                    return;
                }

                if ( response && response.success && response.data.messages ) {
                    // Clear current messages. A pending confirmation belongs
                    // to the previous conversation, so drop it with the DOM.
                    $messages.empty();
                    messageHistory = [];
                    resolveActiveConfirmCard();
                    resetSessionUsage();

                    // Update mode indicator from conversation metadata.
                    // Silent restore preserves the user's current mode preference;
                    // explicit load (user clicked history) switches to the conversation's mode.
                    // Tested in tests/js/test-mode-guards.js
                    // NOTE: Must run BEFORE message replay so appendAssistantMessage reads the correct currentMode for badges.
                    if ( ! isSilentRestore ) {
                        // Explicit load: match the conversation's mode and update the override.
                        // requestMode moves with the pill so the next request posts what
                        // the pill shows; the localStorage preference is NOT touched.
                        // A provider check that already found no provider (false) wins over the stored one.
                        if ( response.data.mode === 'agent' && response.data.provider_name && false !== availableProviderName ) {
                            availableProviderName = response.data.provider_name;
                            userModeOverride = 'agent';
                            requestMode = 'agent';
                            updateModeIndicator( 'agent', response.data.provider_name );
                        } else {
                            userModeOverride = 'ask';
                            requestMode = 'ask';
                            updateModeIndicator( 'ask', null );
                        }
                    } else {
                        // Silent restore: preserve the current mode and only cache the provider name for the toggle.
                        if ( response.data.mode === 'agent' && response.data.provider_name && false !== availableProviderName ) {
                            availableProviderName = response.data.provider_name;
                        }
                    }

                    // Render messages without per-message scroll animations.
                    isBatchRendering = true;
                    $messages.addClass( 'batch-loading' );
                    let lastLoadedMessageCancelled = false;
                    try {
                        response.data.messages.forEach( function( msg ) {
                            lastLoadedMessageCancelled = !! msg.cancelled;
                            if ( msg.cancelled ) {
                                // A cancelled-confirmation closing row is stored with the
                                // assistant role so the model sees the decline, but it
                                // renders as the same system notice the live Cancel showed.
                                appendSystemMessage( msg.content );
                            } else if ( msg.role === 'user' ) {
                                appendUserMessage( msg.content, msg.created_at );
                            } else if ( msg.role === 'assistant' ) {
                                appendAssistantMessage( msg.content, normalizeStoredSources( msg.sources ), parseUsageFromResponse( msg ) || msg.tokens || null, msg.executed_abilities || [], msg.created_at, false, undefined, msg.feedback, msg.route === 'agent' || msg.route === 'ask' ? msg.route : response.data.mode );
                            } else if ( msg.role === 'system' ) {
                                appendSystemMessage( msg.content );
                            }
                            messageHistory.push( { role: msg.role, content: msg.content, message_id: msg.message_id } );
                        } );
                    } finally {
                        isBatchRendering = false;
                    }

                    setCurrentConversation( conversationId );

                    // Find the most recent user message for regenerate functionality.
                    lastUserMessage = null;
                    lastAssistantMessageId = null;
                    for ( let i = messageHistory.length - 1; i >= 0; i-- ) {
                        if ( messageHistory[ i ].role === 'user' ) {
                            lastUserMessage = messageHistory[ i ].content;
                            break;
                        }
                    }

                    // Regeneration requires an assistant answer immediately after a user message.
                    let lastMsg = messageHistory.length > 0 ? messageHistory[ messageHistory.length - 1 ] : null;
                    lastAssistantMessageId = lastMsg && lastMsg.role === 'assistant' ? lastMsg.message_id || null : null;
                    if ( lastAssistantMessageId && messageHistory.length >= 2 && lastMsg.role === 'assistant' && messageHistory[ messageHistory.length - 2 ].role === 'user' && ! lastLoadedMessageCancelled ) {
                        showRegenerateLink();
                    } else {
                        hideRegenerateLinks();
                    }

                    // An unresolved confirmation request survives the reload:
                    // re-arm the confirmation card from the server-held resume
                    // context so Proceed (or a typed "confirmed") executes
                    // through the confirm endpoint instead of spending an
                    // extra agent turn re-issuing the tool call.
                    if ( response.data.pending_confirmation ) {
                        let restoredConfirmData = response.data.pending_confirmation;
                        if ( restoredConfirmData.extra_confirm === true ) {
                            showPolicyWarning( 'protected_tag', restoredConfirmData );
                        }
                        pendingConfirmationData = restoredConfirmData;
                        renderConfirmationCard( restoredConfirmData, restoredConfirmData.preview || restoredConfirmData );
                        hideRegenerateLinks();
                    }

                    if ( ! isSilentRestore ) {
                        // Emit event for panel navigation integration.
                        document.dispatchEvent( new CustomEvent( 'mainwp-assistant-conversation-loaded', {
                            detail: { conversationId: conversationId }
                        } ) );
                    }

                    requestAnimationFrame( function() {
                        scrollToBottom( true );
                        $messages.removeClass( 'batch-loading' );
                    } );
                } else {
                    if ( shouldClearConversationOnLoadFailure() ) {
                        setCurrentConversation( null );
                    }

                    if ( ! isSilentRestore ) {
                        let errorMsg = ( response && response.data && response.data.message )
                            ? response.data.message
                            : l10n( 'conversation_load_error', 'Failed to load conversation' );
                        appendErrorMessage( errorMsg );
                    }
                }

                finishConversationLoad();
            } )
            .fail( function() {
                if ( ! isSilentRestore ) {
                    appendErrorMessage( l10n( 'error_connection', 'Unable to connect. Please check your connection and try again.' ) );
                }

                finishConversationLoad();
            } );

        function finishConversationLoad() {
            isConversationLoadInProgress = false;
            setInputState( true );
            // Fires after input is re-enabled so the callback can focus it.
            // The guard early-returns above never reach this, so callers that
            // need the callback unconditionally must check those preconditions
            // themselves (refreshSettledConversation does).
            if ( typeof options.onLoaded === 'function' ) {
                options.onLoaded();
            }
        }

        function shouldClearConversationOnLoadFailure() {
            return isSilentRestore || ( currentConversationId && currentConversationId == conversationId );
        }
    }

    // =========================================================================
    // EXPORT FUNCTIONALITY
    // =========================================================================

    /**
     * Serialize a conversation's messages and trigger a browser download.
     *
     * @param {Array}         messages       Messages ({role, content} objects).
     * @param {number|string} conversationId Conversation ID for the filename.
     * @param {string}        format         'json' or 'markdown'.
     */
    function downloadConversationExport( messages, conversationId, format ) {
        let blob;
        let extension;

        if ( 'markdown' === format ) {
            let markdown = '# MainWP Assistant Conversation\n\n';
            markdown += '**Exported:** ' + new Date().toLocaleString() + '\n\n';
            markdown += '---\n\n';

            messages.forEach( function( msg ) {
                let role = msg.role === 'user' ? '**You**' : '**Assistant**';
                markdown += '### ' + role + '\n\n' + msg.content + '\n\n';
            } );

            blob = new Blob( [ markdown ], { type: 'text/markdown' } );
            extension = 'md';
        } else {
            let data = {
                conversation_id: conversationId,
                messages: messages,
                exported_at: new Date().toISOString()
            };

            blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
            extension = 'json';
        }

        let url = URL.createObjectURL( blob );
        let a = document.createElement( 'a' );
        a.href = url;
        a.download = 'mainwp-conversation-' + ( conversationId || 'new' ) + '-' + Date.now() + '.' + extension;
        a.click();
        URL.revokeObjectURL( url );
    }

    /**
     * Export any stored conversation by ID without touching the open chat.
     *
     * Fetches the conversation via the existing load endpoint and serializes
     * it client-side; used by the Messages screen's per-row export action.
     *
     * @param {number|string} conversationId Conversation ID to export.
     * @param {string}        format         'json' or 'markdown'.
     * @return {Promise} Resolves on download, rejects with a message string.
     */
    function exportConversationById( conversationId, format ) {
        return new Promise( function( resolve, reject ) {
            let data = {
                action: 'mainwp_assistant_load_conversation',
                conversation_id: conversationId
            };
            data = mainwp_secure_data( data );

            $.post( ajaxurl, data )
                .done( function( response ) {
                    if ( response && response.success && response.data && Array.isArray( response.data.messages ) && response.data.messages.length ) {
                        downloadConversationExport( response.data.messages, conversationId, format );
                        resolve();
                    } else {
                        let errorMsg = ( response && response.data && response.data.message )
                            ? response.data.message
                            : l10n( 'export_no_messages', 'No messages to export. Start a conversation first.' );
                        reject( errorMsg );
                    }
                } )
                .fail( function() {
                    reject( l10n( 'error_connection', 'Unable to connect. Please check your connection and try again.' ) );
                } );
        } );
    }

    // =========================================================================
    // CONFIRMATION DIALOG
    // =========================================================================

    /**
     * Handle requires_confirmation response from server.
     *
     * Called when the AI wants to execute a destructive or mutating ability
     * that requires user confirmation before proceeding.
     *
     * @param {Object} responseData Response data containing confirmation_data.
     */
    function handleConfirmationRequired( responseData ) {
        let confirmData = responseData.confirmation_data || {};

        if ( confirmData.extra_confirm === true ) {
            showPolicyWarning( 'protected_tag', confirmData );
        }

        // Use server-provided response text for consistency with stored history.
        // Fall back to constructed message only if server didn't provide one.
        let actionMessage = responseData.response;
        if ( ! actionMessage ) {
            let actionLabel = confirmData.label || confirmData.ability || 'this action';
            actionMessage = l10n(
                'confirmation_required',
                'I need your confirmation to proceed with: %s'
            ).replace( '%s', actionLabel );
        }

        appendAssistantMessage( actionMessage, [], null, responseData.executed_abilities || [] );

        if ( ! isHistoryDisabled ) {
            messageHistory.push( {
                role: 'assistant',
                content: actionMessage
            } );
        }

        // Render the inline confirmation card with preview data and arm the
        // typed-response intercept in sendMessage().
        let previewData = confirmData.preview || confirmData;
        pendingConfirmationData = confirmData;
        renderConfirmationCard( confirmData, previewData );

        // Re-enable input so the user can type a response or keep chatting.
        setInputState( true );
        $input.focus();
    }

    /**
     * Build a human-readable summary for the confirmation dialog.
     *
     * Shows the action label, affected sites (when the dry-run preview carries
     * a site list), the preview message, and scalar parameters. The raw JSON
     * preview stays available behind the "Technical details" accordion.
     *
     * @param {Object} actionData  Action data (ability, label, params).
     * @param {Object} previewData Dry-run preview data.
     * @return {string} HTML summary.
     */
    function buildConfirmationSummary( actionData, previewData ) {
        let html = '';
        let label = actionData.label || actionData.ability || '';

        if ( label ) {
            html += '<div class="mainwp-confirm-summary-action"><strong>' + l10n( 'confirm_action_label', 'Action:' ) + '</strong> ' + escapeHtml( label ) + '</div>';
        }

        // Affected sites list, when the preview carries one.
        let sites = null;
        if ( previewData && Array.isArray( previewData.sites ) ) {
            sites = previewData.sites;
        } else if ( Array.isArray( previewData ) ) {
            sites = previewData;
        }
        if ( sites && sites.length ) {
            let maxShown = 10;
            html += '<div class="mainwp-confirm-summary-sites"><strong>' + l10n( 'confirm_affected_sites', 'Affected sites:' ) + '</strong><ul class="ui list">';
            sites.slice( 0, maxShown ).forEach( function( site ) {
                let text = '';
                if ( site && typeof site === 'object' ) {
                    text = site.name || site.title || site.url || site.site_url || ( site.id ? '#' + site.id : '' );
                } else {
                    text = String( site );
                }
                if ( text ) {
                    html += '<li>' + escapeHtml( text ) + '</li>';
                }
            } );
            html += '</ul>';
            if ( sites.length > maxShown ) {
                html += '<div class="mainwp-confirm-summary-more">' + l10n( 'confirm_more_sites', '+%d more' ).replace( '%d', sites.length - maxShown ) + '</div>';
            }
            html += '</div>';
        }

        // Preview message from the dry run.
        if ( previewData && typeof previewData.message === 'string' && previewData.message ) {
            html += '<div class="mainwp-confirm-summary-message">' + escapeHtml( previewData.message ) + '</div>';
        }

        // Scalar parameters table. Empty values (null, '', []) are noise the
        // model's defaults produce, not information, so skip them.
        let params = actionData.params;
        if ( params && typeof params === 'object' && ! Array.isArray( params ) ) {
            let rows = '';
            Object.keys( params ).forEach( function( key ) {
                if ( key === 'dry_run' || key === 'confirm' || key === 'preview_token' ) {
                    return;
                }
                let value = params[ key ];
                if ( value === null || typeof value === 'undefined' || value === '' ) {
                    return;
                }
                if ( Array.isArray( value ) && value.length === 0 ) {
                    return;
                }
                if ( typeof value === 'object' ) {
                    value = JSON.stringify( value );
                }
                rows += '<tr><td>' + escapeHtml( humanizeParamKey( key ) ) + '</td><td>' + escapeHtml( String( value ) ) + '</td></tr>';
            } );
            if ( rows ) {
                html += '<table class="ui very basic compact definition table mainwp-confirm-summary-params"><tbody>' + rows + '</tbody></table>';
            }
        }

        return html;
    }

    // humanizeParamKey() lives in mainwp-assistant-chat-logic.js (loaded as a
    // script dependency before this file) so the browser and the Node test
    // suite (tests/js/test-confirm-flow.js) share one copy, including the
    // PARAM_LABEL_MAP friendly labels for common ability parameters shown on
    // confirmation cards (raw keys stay visible in the Technical details JSON).
    var humanizeParamKey = MainWPAssistantChatLogic.humanizeParamKey;

    /**
     * Render an inline confirmation card into the chat thread.
     *
     * Replaces the old confirmation modal: the card carries the action
     * summary, a collapsed Technical details section with the raw preview
     * JSON, Proceed/Cancel buttons, and a typed-response hint. Only one card
     * is ever live; a newer confirmation disables the previous card first.
     *
     * @param {Object} actionData  Action data to execute on confirm.
     * @param {Object} previewData Preview data to show.
     */
    function renderConfirmationCard( actionData, previewData ) {
        // Never leave two live Proceed buttons: a newer confirmation
        // supersedes the previous card.
        if ( $activeConfirmCard ) {
            markConfirmCardResolved( $activeConfirmCard, true );
            $activeConfirmCard = null;
        }

        let isDestructive = actionData.destructive === true;
        let icon = isDestructive ? 'exclamation triangle' : 'info circle';
        let title = isDestructive
            ? l10n( 'confirm_destructive', 'Confirm Destructive Action' )
            : l10n( 'confirm_action', 'Confirm Action' );
        // A destructive write without a dry run has no preview to point at.
        let messageText = isDestructive && actionData.preview
            ? l10n( 'confirm_destructive_txt', 'This action will make permanent changes. Review the preview below:' )
            : l10n( 'confirm_action_txt', 'Please confirm you want to proceed with this action:' );
        let proceedColor = isDestructive ? 'red' : 'green';

        let html = '<div class="mainwp-mintlify-message mainwp-assistant-confirm-card' + ( isDestructive ? ' mainwp-confirm-card-destructive' : '' ) + '">' +
                       '<div class="mainwp-confirm-card-header"><i class="' + icon + ' icon"></i> <strong>' + escapeHtml( title ) + '</strong></div>' +
                       '<div class="mainwp-confirm-card-text">' + escapeHtml( messageText ) + '</div>' +
                       '<div class="mainwp-confirm-card-summary">' + buildConfirmationSummary( actionData, previewData ) + '</div>' +
                       '<div class="ui accordion mainwp-confirm-card-details">' +
                           '<div class="title"><i class="dropdown icon"></i>' + escapeHtml( l10n( 'confirm_technical_details', 'Technical details' ) ) + '</div>' +
                           '<div class="content"><pre class="mainwp-confirm-card-preview"></pre></div>' +
                       '</div>' +
                       '<div class="mainwp-confirm-card-actions">' +
                           '<button type="button" class="ui ' + proceedColor + ' mini button mainwp-confirm-card-proceed"><i class="check icon"></i>' + escapeHtml( l10n( 'confirm_proceed', 'Proceed' ) ) + '</button>' +
                           '<button type="button" class="ui basic mini button mainwp-confirm-card-cancel"><i class="times icon"></i>' + escapeHtml( l10n( 'confirm_cancel', 'Cancel' ) ) + '</button>' +
                       '</div>' +
                       '<div class="mainwp-confirm-card-hint">' + escapeHtml( l10n( 'confirm_type_hint', 'Type "yes" to proceed, type "cancel" to dismiss, or use the buttons.' ) ) + '</div>' +
                   '</div>';

        $messages.append( html );
        let $card = $messages.find( '.mainwp-assistant-confirm-card' ).last();

        // Raw JSON goes in via .text() so it never parses as markup.
        $card.find( '.mainwp-confirm-card-preview' ).text( JSON.stringify( previewData, null, 2 ) );

        let $details = $card.find( '.mainwp-confirm-card-details' );
        if ( $.fn.accordion ) {
            $details.accordion();
        }

        $card.find( '.mainwp-confirm-card-proceed' ).on( 'click', function() {
            executeConfirmedAction( actionData );
        } );
        $card.find( '.mainwp-confirm-card-cancel' ).on( 'click', function() {
            resolveActiveConfirmCard();
            // persistCancellation() renders the outcome notice from the
            // server's answer.
            persistCancellation( actionData );
            setInputState( true );
            $input.focus();
        } );

        $activeConfirmCard = $card;

        if ( ! isBatchRendering ) {
            scrollToBottom();
        }
    }

    /**
     * Disable a confirmation card's buttons and mark it settled.
     *
     * @param {Object}  $card Card jQuery element.
     * @param {boolean} stale True when superseded by a newer confirmation.
     */
    function markConfirmCardResolved( $card, stale ) {
        $card.addClass( stale ? 'mainwp-confirm-card-stale' : 'mainwp-confirm-card-resolved' );
        $card.find( '.mainwp-confirm-card-proceed, .mainwp-confirm-card-cancel' )
            .prop( 'disabled', true )
            .addClass( 'disabled' );
    }

    /**
     * Settle the live confirmation card (if any) and clear pending state.
     */
    function resolveActiveConfirmCard() {
        if ( $activeConfirmCard ) {
            markConfirmCardResolved( $activeConfirmCard, false );
            $activeConfirmCard = null;
        }
        pendingConfirmationData = null;
    }

    /**
     * Converge a losing tab after an already_settled refusal.
     *
     * Another tab resolved this confirmation first, so this transcript is
     * stale: the winner's closing row is persisted server-side but was never
     * rendered here. Reload the conversation so the outcome appears, then
     * render the corrective notice below it. The tab-local fact that this
     * click did nothing is stored nowhere, so it has to survive the
     * re-render. History opt-out has no conversation to reload, and a
     * disabled input would make loadConversation() return without ever
     * firing the callback; both fall back to rendering the notice
     * immediately. Tested in tests/js/test-confirm-flow.js
     * (settledRefusalPlan).
     *
     * @param {string} noticeText Corrective notice for this tab.
     */
    function refreshSettledConversation( noticeText ) {
        let conversationId = parseInt( currentConversationId, 10 );
        if ( ! Number.isInteger( conversationId ) || conversationId <= 0 || $input.prop( 'disabled' ) ) {
            appendSystemMessage( noticeText );
            $input.focus();
            return;
        }
        loadConversation( conversationId, {
            silent: true,
            onLoaded: function() {
                appendSystemMessage( noticeText );
                $input.focus();
            }
        } );
    }

    /**
     * Record a declined confirmation on the server.
     *
     * Persists the closing row so the cancelled card does not come back on
     * reload and the model learns the action was declined. History opt-out
     * confirmations carry a request token instead of a conversation row; the
     * cancel sends it so the server consumes the pending transient, otherwise
     * a replayed confirm within its TTL could still execute. Without a token
     * nothing is armed server-side and the local dismissal is the whole
     * outcome. When a confirm racing from another tab settled the request
     * first, the response corrects this tab's optimistic "Cancelled." notice.
     *
     * @param {Object} actionData   Confirmation data for the declined action.
     * @param {string} typedMessage The user's literal typed decline; only the
     *                              typed-negative path sends one, so the
     *                              stored thread keeps their word choice.
     */
    function persistCancellation( actionData, typedMessage ) {
        let conversationId = ( actionData && actionData.conversation_id ) || currentConversationId;
        let requestId = ( actionData && actionData.request_id ) || '';
        if ( ! conversationId && ! requestId ) {
            // Nothing armed server-side: the local dismissal is the whole
            // outcome.
            appendSystemMessage( l10n( 'confirm_cancelled', 'Cancelled. The action was not executed.' ) );
            return;
        }

        let request = {
            action: 'mainwp_assistant_cancel_action',
            ability: ( actionData && actionData.ability ) || '',
            conversation_id: conversationId || 0
        };
        if ( typedMessage ) {
            request.user_message = typedMessage;
        }
        // Pin the exact request this card displayed; a stale card must not
        // claim a newer request for the same ability.
        if ( actionData && actionData.request_id ) {
            request.request_id = actionData.request_id;
        }

        // The notice renders from the server's answer, not optimistically: a
        // confirm racing from another tab may have already run the action,
        // and a false "Cancelled." would then contradict what happened.
        // Tested in tests/js/test-confirm-flow.js (cancelNoticeKey).
        $.post( ajaxurl, mainwp_secure_data( request ) )
            .done( function( response ) {
                if ( response && response.data && response.data.already_settled ) {
                    // Reload first so the winner's outcome renders, then the
                    // corrective notice below it.
                    refreshSettledConversation( l10n( 'cancel_already_settled', 'This action was already confirmed in another tab, so the cancel did not apply.' ) );
                    return;
                }
                if ( response && response.success && response.data && ! response.data.cancelled ) {
                    // Nothing was pending server-side: the conversation moved
                    // past this request before the cancel arrived (a racing
                    // resolver settled AND closed it), so "Cancelled." would
                    // claim something that did not happen. Reload to show the
                    // actual outcome.
                    refreshSettledConversation( l10n( 'cancel_nothing_pending', 'This request was already resolved or replaced by a newer one, so there was nothing to cancel.' ) );
                    return;
                }
                if ( response && response.success ) {
                    appendSystemMessage( l10n( 'confirm_cancelled', 'Cancelled. The action was not executed.' ) );
                    return;
                }
                // The server answered with an error: the card is dismissed in
                // this tab, yet nothing confirms the pending request was
                // consumed, so "The action was not executed" would overclaim.
                appendSystemMessage( l10n( 'confirm_cancel_unconfirmed', 'Couldn\'t confirm the cancellation with the server. The pending action may remain active until it expires.' ) );
            } )
            .fail( function() {
                // The server was never reached, so the pending confirmation
                // may still be armed server-side; say so instead of claiming
                // the action was not executed.
                appendSystemMessage( l10n( 'confirm_cancel_unconfirmed', 'Couldn\'t confirm the cancellation with the server. The pending action may remain active until it expires.' ) );
            } );
    }

    /**
     * Execute confirmed action.
     *
     * @param {Object} actionData Action data.
     */
    function executeConfirmedAction( actionData ) {
        // Settle the card and clear the typed-response intercept before the
        // request goes out, so a second click or Enter has nothing left to fire.
        resolveActiveConfirmCard();

        // Disable input and show processing state (mirrors sendToAPI behavior).
        setInputState( false );
        startThinkingIndicator();

        // The backend expects 'params' and 'confirmed_protected_sites' as JSON strings.
        let data = {
            action: 'mainwp_assistant_confirm_action',
            ability: actionData.ability || ''
        };

        // Serialize params as JSON string (backend expects json_decode).
        if ( actionData.params && typeof actionData.params === 'object' ) {
            data.params = JSON.stringify( actionData.params );
        } else if ( actionData.params ) {
            data.params = actionData.params;
        }

        if ( actionData.protected_sites && Array.isArray( actionData.protected_sites ) ) {
            data.confirmed_protected_sites = JSON.stringify( actionData.protected_sites );
        }

        if ( actionData.conversation_id ) {
            data.conversation_id = actionData.conversation_id;
        } else if ( currentConversationId ) {
            data.conversation_id = currentConversationId;
        }

        // Pin the exact request this card displayed; the server refuses a
        // stale card instead of resolving whatever request is newest.
        if ( actionData.request_id ) {
            data.request_id = actionData.request_id;
        }

        // The server resumes from the stored confirmation context, so no tool
        // copies travel with the confirm POST. History opt-out (no
        // conversation) gets the raw-result bubble by design.

        // DEBUG: Log confirmation request data.
        if ( window.mainwp_assistant_debug ) {
            console.log( '[Assistant DEBUG] Confirm request:', {
                ability: data.ability,
                conversation_id: data.conversation_id
            } );
        }

        data = mainwp_secure_data( data );

        $.post( ajaxurl, data )
            .done( function( response ) {
                stopThinkingIndicator();

                // DEBUG: Log confirmation response.
                if ( window.mainwp_assistant_debug ) {
                    console.log( '[Assistant DEBUG] Confirm response:', {
                        success: response?.success,
                        has_ai_response: !!response?.data?.ai_response,
                        ai_response: response?.data?.ai_response?.substring( 0, 100 ),
                        has_result: !!response?.data?.result
                    } );
                }

                if ( response && response.success ) {
                    if ( response.data.conversation_id ) {
                        setCurrentConversation( response.data.conversation_id );
                    }

                    // A batch job response switches to the polling UI.
                    // Note: handleBatchJob() keeps input disabled during batch operation.
                    if ( response.data.batch_job === true ) {
                        handleBatchJob( response.data );
                        return;
                    }

                    // Use AI-summarized response if available, otherwise format raw result.
                    let resultMessage;
                    if ( response.data.ai_response ) {
                        resultMessage = response.data.ai_response;
                    } else {
                        resultMessage = formatConfirmationResult( response.data.result, response.data.ability );
                    }

                    // Update mode indicator BEFORE appending so this message's
                    // badge reflects this response, not the previous one. The
                    // confirm endpoint is agent-only, so the badge derives from
                    // the response. A pill switched while the confirmation was
                    // pending must not relabel it.
                    // Tested in tests/js/test-mode-guards.js
                    if ( response.data.provider_name ) {
                        availableProviderName = response.data.provider_name;
                        updateModeIndicator( 'agent', response.data.provider_name );
                    } else if ( response.data.ai_response ) {
                        updateModeIndicator( 'agent', null );
                    }

                    appendAssistantMessage( resultMessage, [], null, response.data.executed_abilities || [] );

                    let confirmTokens = parseUsageFromResponse( response.data );
                    if ( confirmTokens ) {
                        showMessageCost( confirmTokens );
                    }

                    if ( ! isHistoryDisabled ) {
                        messageHistory.push( {
                            role: 'assistant',
                            content: resultMessage
                        } );
                    }

                    setInputState( true );
                    $input.focus();
                } else {
                    // A policy denial gets the warning banner, not the generic error.
                    if ( response && response.data && response.data.error === 'policy_denied' ) {
                        showPolicyWarning( 'readonly', response.data );
                        setInputState( true );
                        $input.focus();
                        return;
                    }

                    // Another tab confirmed or cancelled this request first:
                    // nothing ran here, so reload the conversation to show the
                    // winner's outcome and render an informational notice
                    // instead of the red error bubble. Input must re-enable
                    // before the refresh or loadConversation()'s guard
                    // swallows it.
                    if ( response && response.data && response.data.error === 'already_settled' ) {
                        setInputState( true );
                        refreshSettledConversation( response.data.message || l10n( 'confirm_already_settled', 'This action was already confirmed or cancelled elsewhere, so nothing ran here.' ) );
                        return;
                    }

                    // Guard against malformed responses where response.data may be undefined.
                    let errorMsg = ( response && response.data && response.data.message )
                        ? response.data.message
                        : l10n( 'action_failed', 'Failed to execute action. Please try again.' );
                    appendErrorMessage( errorMsg );

                    setInputState( true );
                    $input.focus();
                }
            } )
            .fail( function() {
                stopThinkingIndicator();
                appendErrorMessage( l10n( 'action_failed', 'Failed to execute action. Please try again.' ) );
                setInputState( true );
                $input.focus();
            } );
    }

    /**
     * Format confirmation result for display.
     *
     * @param {*}      result  The result from the backend (can be object, array, or primitive).
     * @param {string} ability The ability that was executed.
     * @return {string} Formatted message for display.
     */
    function formatConfirmationResult( result, ability ) {
        if ( result === null || result === undefined ) {
            return l10n( 'action_completed', 'Action completed.' );
        }

        if ( typeof result === 'string' ) {
            return result;
        }

        // Object or array result: build a summary.
        if ( typeof result === 'object' ) {
            if ( result.message ) {
                return result.message;
            }

            if ( result.success !== undefined ) {
                let msg = result.success
                    ? l10n( 'action_success', 'Action completed.' )
                    : l10n( 'action_partial', 'Action completed with errors. See the details below.' );

                if ( typeof result.processed === 'number' ) {
                    msg += ' ' + l10n( 'items_processed', '%d items processed.' ).replace( '%d', result.processed );
                }
                if ( typeof result.failed === 'number' && result.failed > 0 ) {
                    msg += ' ' + l10n( 'items_failed', '%d failed.' ).replace( '%d', result.failed );
                }
                return msg;
            }

            if ( Array.isArray( result ) ) {
                let successCount = result.filter( function( r ) {
                    return r && ( r.success === true || r.status === 'success' );
                } ).length;
                return l10n( 'action_completed', 'Action completed.' ) + ' ' +
                       successCount + ' of ' + result.length + ' succeeded.';
            }

            return l10n( 'action_completed', 'Action completed.' );
        }

        return l10n( 'action_completed', 'Action completed.' );
    }

    // =========================================================================
    // POLICY WARNINGS
    // =========================================================================

    /**
     * Show policy warning message.
     *
     * @param {string} warningType Type of warning: 'readonly' or 'protected_tag'.
     * @param {Object} details     Optional details object with server-provided context.
     */
    function showPolicyWarning( warningType, details ) {
        let message = '';

        if ( warningType === 'readonly' ) {
            message = l10n( 'policy_readonly_block', 'Action blocked. You are in read-only mode.' );
        } else if ( warningType === 'protected_tag' ) {
            // Prefer server-provided context about which sites/tags require confirmation.
            message = ( details && details.protected_message ) ||
                      ( details && details.message ) ||
                      l10n( 'policy_protected_tag', 'This affects sites with protected tags. Extra confirmation required.' );
        }

        let html = '<div class="mainwp-mintlify-message mainwp-mintlify-system-message">' +
                       '<div class="message-content mainwp-mintlify-policy-warning">' +
                           '<i class="exclamation triangle icon"></i>' +
                           '<span>' + escapeHtml( message ) + '</span>' +
                       '</div>' +
                   '</div>';

        $messages.append( html );
        scrollToBottom();
    }

    // =========================================================================
    // PROVIDER AVAILABILITY
    // =========================================================================

    function applyInitialMode( mode, providerName ) {
        // A pending availability check must not replace a loaded or selected mode.
        if ( userModeOverride ) {
            return;
        }
        requestMode = mode;
        updateModeIndicator( mode, providerName );
    }

    /**
     * Check provider availability with automatic retry on failure.
     *
     * @param {number} retryCount Current retry attempt (0-based).
     */
    function checkProviderAvailability( retryCount, afterCheck ) {
        retryCount = retryCount || 0;
        const maxRetries = 2; // Total of 3 attempts (initial + 2 retries).
        const retryDelays = [ 1000, 2000 ]; // 1s, then 2s delay.

        let data = {
            action: 'mainwp_assistant_check_provider'
        };
        data = mainwp_secure_data( data );

        // 15s timeout, raised from 10s to reduce false failures.
        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: data,
            timeout: 15000
        } )
            .done( function( response ) {
                if ( response && response.success && response.data ) {
                    chatgptPlan = response.data.chatgpt_plan || {};
                    updatePlanIndicator();
                    if ( window.MainWPAssistantChatGPT ) {
                        window.MainWPAssistantChatGPT.updateSummary( chatgptPlan );
                    }
                }
                if ( ! response || ! response.success ) {
                    // Mark complete so the mode toggle is not permanently disabled.
                    // Keep availableProviderName as null (unknown) so the toggle allows
                    // optimistic switching; the backend validates on the next request.
                    providerCheckComplete = true;
                    availableProviderName = null;
                    // Correct the mode to Ask if the user preferred Agent, since Ask works without a provider.
                    // Don't clear the preference: the provider might exist, the check just failed transiently.
                    let savedPref = localStorage.getItem( 'mainwp_assistant_mode_preference' );
                    if ( savedPref === 'agent' ) {
                        applyInitialMode( 'ask', null );
                    }
                } else if ( ! response.data.available ) {
                    // Provider check succeeded: confirmed no provider available.
                    // Use false (known absent) so toggle shows error tooltip immediately.
                    providerCheckComplete = true;
                    availableProviderName = false;
                    providerReasonCode = response.data.reason_code || '';
                    wizardAvailable = response.data.wizard_available === true;
                    setAgentPillUnavailable( true );

                    // Sites mode cannot run without a provider, so this also replaces a loaded or selected Sites mode.
                    if ( 'agent' === userModeOverride ) {
                        userModeOverride = 'ask';
                    }
                    requestMode = 'ask';
                    updateModeIndicator( 'ask', null );

                    maybeRenderWelcomeConnectCard();

                    // Clear the stale Agent preference: the server confirmed no provider is available.
                    let savedPref = localStorage.getItem( 'mainwp_assistant_mode_preference' );
                    if ( savedPref === 'agent' ) {
                        localStorage.removeItem( 'mainwp_assistant_mode_preference' );
                    }
                } else {
                    // Provider available: mark complete and store the provider name.
                    providerCheckComplete = true;
                    availableProviderName = response.data.provider_name;
                    providerReasonCode = '';
                    wizardAvailable = false;
                    setAgentPillUnavailable( false );
                    refreshDocsHandoffOffer();

                    // A saved Docs choice stays sticky unless this session already chose a mode.
                    let savedPref = localStorage.getItem( 'mainwp_assistant_mode_preference' );
                    if ( savedPref === 'ask' ) {
                        applyInitialMode( 'ask', null );
                        if ( ! userModeOverride ) {
                            userModeOverride = 'ask';
                        }
                    } else if ( savedPref === 'agent' ) {
                        applyInitialMode( 'agent', response.data.provider_name );
                    } else {
                        applyInitialMode( 'ask', null );
                    }
                }

                // Show disclosure banner immediately if server indicates first-use
                // and user hasn't dismissed it (localStorage is client-side cache).
                // Guard: Don't show disclosure if history is disabled (opt-out users).
                if ( response && response.data ) {
                    // Advisory cap notice: warn before a message is sent. The
                    // input stays enabled and the server gate stays
                    // authoritative. A stale panel can still submit and gets
                    // the truthful server answer.
                    updateBudgetNotice( response.data.budget );

                    if ( response.data.show_disclosure === true && response.data.history_disabled !== true ) {
                        let dismissed = localStorage.getItem( 'mainwp_assistant_disclosure_dismissed' );
                        if ( ! dismissed ) {
                            $( '#mainwp-mintlify-disclosure' ).show();
                            // One first-run card at a time: the disclosure wins,
                            // the explainer returns on the next open.
                            dismissModesIntro( false );
                        }
                    }

                    // Handle history state from server.
                    // Hide history controls for opted-out users, restore for opted-in users.
                    if ( response.data.history_disabled === true ) {
                        handleHistoryDisabled();
                    } else {
                        restoreHistoryControls();
                    }
                }

                attemptConversationRestore();
                if ( afterCheck ) { afterCheck(); }
            } )
            .fail( function( jqXHR, textStatus ) {
                // Retry on timeout or network errors (not on abort).
                if ( retryCount < maxRetries && textStatus !== 'abort' ) {
                    setTimeout( function() {
                        checkProviderAvailability( retryCount + 1, afterCheck );
                    }, retryDelays[ retryCount ] );
                    return;
                }

                // Max retries exhausted: mark complete and show the error.
                // Mark provider check as complete on network failure so the mode
                // toggle isn't permanently disabled. sendToAPI() will still work
                // because the backend uses its default routing when mode is 'ask'.
                providerCheckComplete = true;

                // Apply cached history opt-out state from localStorage.
                // This ensures opted-out users don't see history controls even when
                // the provider check fails due to network issues.
                let cachedHistoryDisabled = localStorage.getItem( 'mainwp_assistant_history_disabled' );
                if ( cachedHistoryDisabled === 'true' ) {
                    handleHistoryDisabled();
                }

                // Correct the mode to Ask if the user preferred Agent, since Ask works without a provider.
                // Don't clear the preference: the provider might exist, the network just failed.
                let savedPref = localStorage.getItem( 'mainwp_assistant_mode_preference' );
                if ( savedPref === 'agent' ) {
                    applyInitialMode( 'ask', null );
                }

                attemptConversationRestore();
            } );
    }

    /**
     * Store the daily spend cap state and toggle the advisory notice above
     * the input.
     *
     * The panel checks provider availability on open, so a cap reached
     * mid-session shows on the next open. No polling.
     *
     * @param {Object|null} budget Budget state from the provider check
     *                             ({limit, spent_today, blocked, unavailable, user_reached}) or null.
     */
    function updateBudgetNotice( budget ) {
        budgetState = budget && typeof budget === 'object' ? budget : null;
        var unavailable = !! ( budgetState && budgetState.unavailable );
        var userReached = ! unavailable && !! ( budgetState && budgetState.user_reached );
        var $notice = $( '#mainwp-assistant-budget-notice' );
        $notice.find( '.mainwp-assistant-budget-reached' ).toggle( ! unavailable && ! userReached );
        $notice.find( '.mainwp-assistant-budget-user-reached' ).toggle( userReached );
        $notice.find( '.mainwp-assistant-budget-unavailable' ).toggle( unavailable );
        $notice.toggle( !! ( budgetState && budgetState.blocked ) );
    }

    // =========================================================================
    // FIRST-USE DISCLOSURE
    // =========================================================================

    /**
     * Hide the first-use disclosure banner when this browser has already dismissed it.
     *
     * This never shows the banner. The server decides that, via show_disclosure=true on
     * the first API response (see the sendToAPI response handler). The localStorage flag
     * is a per-browser cache so a dismissal sticks immediately instead of waiting a
     * round trip, and a cleared localStorage falls back to the server's record.
     */
    function checkFirstUseDisclosure() {
        let dismissed = localStorage.getItem( 'mainwp_assistant_disclosure_dismissed' );

        if ( dismissed ) {
            $( '#mainwp-mintlify-disclosure' ).hide();
        }
    }

    /**
     * Dismiss disclosure banner.
     */
    function dismissDisclosure() {
        // Set localStorage cache for immediate UX on future page loads.
        localStorage.setItem( 'mainwp_assistant_disclosure_dismissed', 'true' );

        $( '#mainwp-mintlify-disclosure' ).hide();

        // Notify server to persist the dismissal.
        let data = {
            action: 'mainwp_assistant_dismiss_disclosure'
        };
        data = mainwp_secure_data( data );
        $.post( ajaxurl, data );
    }

    // =========================================================================
    // FIRST-RUN MODE EXPLAINER
    // =========================================================================

    /**
     * Show the one-time mode explainer card in the welcome state.
     *
     * Renders under the welcome message the first time the chat opens.
     * Skipped once dismissed, while the disclosure banner is visible (the
     * card returns on the next open after that banner is dismissed), or
     * after conversation content has rendered.
     */
    function maybeShowModesIntro() {
        let dismissed = null;
        try {
            dismissed = localStorage.getItem( 'mainwp_assistant_modes_intro_dismissed' );
        } catch ( e ) {
            // localStorage unavailable, degrade silently.
        }

        if ( dismissed || ! isWelcomeState() || $( '#mainwp-mintlify-disclosure' ).is( ':visible' ) ) {
            return;
        }
        if ( $messages.find( '.mainwp-assistant-modes-intro' ).length ) {
            return;
        }

        let $card = $( '<div class="mainwp-assistant-modes-intro"></div>' )
            .append( $( '<div class="modes-intro-title"></div>' ).text( l10n( 'modes_intro_title', 'Two ways to ask' ) ) )
            .append( $( '<div class="modes-intro-text"></div>' ).text( l10n( 'modes_intro_text', 'Docs answers questions about MainWP from the official documentation. Sites works with your own sites using your AI provider and can take actions with your confirmation.' ) ) )
            .append(
                $( '<button type="button" class="modes-intro-dismiss"></button>' )
                    .text( l10n( 'modes_intro_dismiss', 'Got it' ) )
                    .on( 'click', function() {
                        dismissModesIntro( true );
                    } )
            );

        $messages.find( '.mainwp-mintlify-welcome' ).after( $card );
    }

    /**
     * Remove the mode explainer card.
     *
     * @param {boolean} persist True to record the dismissal so the card stays gone.
     */
    function dismissModesIntro( persist ) {
        if ( persist ) {
            try {
                localStorage.setItem( 'mainwp_assistant_modes_intro_dismissed', '1' );
            } catch ( e ) {
                // localStorage unavailable, degrade silently.
            }
        }
        if ( $messages && $messages.length ) {
            $messages.find( '.mainwp-assistant-modes-intro' ).remove();
        }
    }

    // =========================================================================
    // RESET
    // =========================================================================

    /**
     * Reset chat state.
     *
     * Clears messages, history, input, batch jobs, and shows welcome message.
     */
    function reset() {
        clearDocsHandoffOffer();
        // Stop any active batch polling.
        stopPolling();
        clearActiveJob();

        // Stop any active async chat polling. Tell the server first, the way
        // Stop does. Walking away via New Chat must actually cancel the
        // running job, not leave it running unattended.
        if ( activeChatJob ) {
            requestServerCancel( activeChatJob.job_id );
        }
        clearChatJob();

        // Stop thinking indicator if running.
        stopThinkingIndicator();

        // Abort any in-flight request.
        if ( abortController ) {
            abortController.abort();
            abortController = null;
        }

        // Reset control state.
        // providerCheckComplete deliberately survives New Chat: provider
        // availability does not change because a conversation was reset, and
        // checkProviderAvailability() only runs from init(), so clearing the
        // flag here would close sendMessage()'s readiness gate until a page
        // reload. Tested in tests/js/test-confirm-flow.js.
        lastUserMessage = null;
        lastAssistantMessageId = null;
        setCurrentConversation( null );
        isConversationLoadInProgress = false;
        autoRestoreAttempted = false;
        resetSessionUsage();

        // Only restore history controls if user is not opted out.
        // This prevents briefly exposing history UI for opt-out users during reset.
        if ( ! isHistoryDisabled ) {
            restoreHistoryControls();
        }

        // Ensure $messages is available before using it (reset can be
        // called from the panel before the chat module has fully initialised).
        if ( ! $messages || ! $messages.length ) {
            $messages = $( '#mainwp-mintlify-messages' );
        }
        if ( ! $input || ! $input.length ) {
            $input = $( '#mainwp-mintlify-input' );
        }
        if ( ! $sendButton || ! $sendButton.length ) {
            $sendButton = $( '#mainwp-mintlify-send' );
        }

        // Hide control buttons.
        hideRegenerateLinks();
        setSendButtonDefault();

        // Clear messages and show welcome.
        $messages.html( getWelcomeHtml() );

        // Reset history. A pending confirmation belongs to the old chat, and a
        // typed "yes" in the fresh one must not fire the stale action.
        messageHistory = [];
        resolveActiveConfirmCard();

        // Re-offer the mode explainer unless it was dismissed.
        maybeShowModesIntro();

        // Clear input.
        if ( $input && $input.length ) {
            $input.val( '' ).css( 'height', 'auto' );
        }

        setInputState( true );

        // Generate new session.
        sessionFingerprint = mainwp_uid();

        // Mark as not initialized so init() can reinitialize if needed.
        isInitialized = false;
    }

    // Public API.
    return {
        init: init,
        reset: reset,
        clearConversation: reset, // Alias for panel.js compatibility.
        loadConversation: loadConversation,
        exportConversationById: exportConversationById,
        getSupportContext: function() {
            return {
                conversationId: isHistoryDisabled ? null : currentConversationId,
                note: lastUserMessage || '',
                historyDisabled: isHistoryDisabled
            };
        }
    };

} )( jQuery );
