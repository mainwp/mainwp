/**
 * MainWP Assistant Chat, shared pure logic
 *
 * DOM/jQuery-free decision helpers shared between the browser chat script
 * (mainwp-mintlify-chat.js) and its Node characterization tests
 * (tests/js/test-*.js). Loaded as a script dependency before
 * mainwp-mintlify-chat.js, which calls these instead of keeping its own
 * copies, so there is one source of truth for the logic both the browser
 * and the tests exercise.
 *
 * @package MainWP/Dashboard
 * @since   6.0.0
 */

/* global mainwpMintlifyChatL10n */

( function( root ) {
    'use strict';

    /**
     * Get localized string with fallback. Private copy of the l10n() helper
     * in mainwp-mintlify-chat.js, needed here only for classifyAckLocally().
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

    /**
     * Classify a short typed reply as an affirmative or negative acknowledgment.
     *
     * Mirrors the server-side classify_acknowledgment() normalization; the word
     * lists are localized from the same filtered PHP source so both sides agree.
     *
     * @param {string} message The typed message.
     * @return {?string} 'affirmative', 'negative', or null when not an acknowledgment.
     */
    function classifyAckLocally( message ) {
        let text = String( message || '' ).trim().toLowerCase();
        if ( ! text || text.length > 40 ) {
            return null;
        }
        text = text.replace( /^[!.?,\s]+|[!.?,\s]+$/g, '' );
        if ( ! text ) {
            return null;
        }

        let splitWords = function( list ) {
            return list.toLowerCase().split( ',' ).map( function( w ) {
                return w.trim();
            } );
        };
        let affirmative = splitWords( l10n( 'confirm_affirmative_words', 'yes,yep,yeah,confirm,confirmed,proceed,go ahead,do it,ok,okay,sure,continue,yes please' ) );
        let negative = splitWords( l10n( 'confirm_negative_words', 'no,nope,cancel,stop,don\'t,do not,never mind,nevermind,abort' ) );

        if ( affirmative.indexOf( text ) !== -1 ) {
            return 'affirmative';
        }
        if ( negative.indexOf( text ) !== -1 ) {
            return 'negative';
        }
        return null;
    }

    /**
     * Whether a response is a refused explicit Agent request answered by docs.
     *
     * Server receipt fields (requested_mode/mode) are authoritative; the local
     * override cross-check covers responses missing the new fields (e.g. a
     * cached or older backend). Auto→Ask is normal routing, never a refusal.
     *
     * @param {Object}  data             Response payload (sync data or async result).
     * @param {?string} userModeOverride Manual mode selection ('ask'/'agent'), or null for auto.
     * @return {boolean} True when the UI should converge to Ask.
     */
    function isAgentRefusal( data, userModeOverride ) {
        var requestedAgent = data.requested_mode === 'agent' ||
            ( data.requested_mode === undefined && userModeOverride === 'agent' );
        return requestedAgent && data.mode === 'ask';
    }

    /**
     * Decide whether a transport-failed send can be recovered from the stored
     * conversation tail: last-but-one row is the user message that just
     * failed (trim-compared, because sanitization may alter whitespace) and an
     * assistant row follows it. Anything else shows the error instead: a tail
     * ending on the user row (async agent job still running), a different turn,
     * or an empty conversation.
     *
     * @param {Array}  tailMessages Last messages of the conversation, oldest first.
     * @param {string} sentMessage  The message whose response was lost.
     * @return {string} 'recover' or 'error'.
     */
    function resolveTransportFailureRecovery( tailMessages, sentMessage ) {
        if ( ! Array.isArray( tailMessages ) || tailMessages.length < 2 ) {
            return 'error';
        }
        let prev = tailMessages[ tailMessages.length - 2 ];
        let last = tailMessages[ tailMessages.length - 1 ];
        if (
            prev && prev.role === 'user' && typeof prev.content === 'string' &&
            prev.content.trim() === String( sentMessage ).trim() &&
            last && last.role === 'assistant'
        ) {
            return 'recover';
        }
        return 'error';
    }

    /**
     * Recovery eligibility: plain sends only, in a known, stored
     * conversation. Regenerate keeps its restoreRegenerationOriginal() path;
     * history opt-out stores nothing server-side.
     *
     * @param {?number} conversationId  Current conversation id, if any.
     * @param {boolean} historyDisabled Whether history storage is opted out.
     * @param {boolean} isRegenerate    Whether the failed send was a regeneration.
     * @return {boolean} True when the recovery fetch should run.
     */
    function resolveTransportRecoveryEligibility( conversationId, historyDisabled, isRegenerate ) {
        return Boolean( conversationId ) && ! historyDisabled && ! isRegenerate;
    }

    /**
     * Stateless turns can resend the prompt without a stored answer to bind.
     *
     * @param {?number} lastAssistantMessageId Bound assistant answer id, if any.
     * @param {boolean} historyDisabled Whether history storage is opted out.
     * @return {boolean} True when regeneration is available.
     */
    function resolveRegenerateAvailability( lastAssistantMessageId, historyDisabled ) {
        return Boolean( lastAssistantMessageId ) || historyDisabled;
    }

    /**
     * Input restore on transport failure: return the message to put back into
     * the input box (plain sends only; regenerate restores via
     * restoreRegenerationOriginal()).
     *
     * @param {boolean} isRegenerate Whether the failed send was a regeneration.
     * @param {string}  typedMessage The message the user typed.
     * @return {?string} Message to restore, or null.
     */
    function resolveInputRestoreOnTransportFailure( isRegenerate, typedMessage ) {
        return isRegenerate ? null : typedMessage;
    }

    /**
     * Normalize the sources field of a stored/restored message: anything that
     * is not an array (older rows, user rows, malformed payloads) becomes []
     * so render code can rely on the shape.
     *
     * @param {*} sources Persisted sources field from a loaded message.
     * @return {Array} Sources array, empty when none were stored.
     */
    function normalizeStoredSources( sources ) {
        return Array.isArray( sources ) ? sources : [];
    }

    /**
     * Advance the chat-poll fallback state for one poll reply.
     *
     * 'queued' means no worker has claimed the job yet, the only signal available
     * that WP-Cron may be dead, so consecutive queued replies count toward
     * the synchronous fallback. 'processing' proves a live worker owns the
     * job, so the counter resets: the fallback must never steal a job a
     * worker is actively running. Any other status resets the counter too.
     *
     * @param {string} status      Poll reply status ('queued', 'processing', ...).
     * @param {number} queuedCount Consecutive queued polls seen before this one.
     * @param {number} threshold   Consecutive queued polls that trigger the fallback.
     * @return {Object} { queuedCount, triggerFallback }
     */
    function nextChatPollFallbackState( status, queuedCount, threshold ) {
        if ( 'queued' === status ) {
            let next = queuedCount + 1;
            return { queuedCount: next, triggerFallback: next >= threshold };
        }
        return { queuedCount: 0, triggerFallback: false };
    }

    // Friendly labels for common ability parameters shown on confirmation
    // cards; raw keys stay visible in the Technical details JSON.
    const PARAM_LABEL_MAP = {
        site_ids_or_domains: 'Sites',
        site_ids: 'Sites',
        site_id: 'Site',
        exclude_ids: 'Excluded sites',
        client_id: 'Client',
        tag_id: 'Tag',
        tags: 'Tags',
        slugs: 'Items',
        type: 'Type'
    };

    /**
     * Humanize an ability parameter key for the confirmation summary.
     *
     * @param {string} key Raw parameter key from the tool call.
     * @return {string} Friendly label.
     */
    function humanizeParamKey( key ) {
        if ( PARAM_LABEL_MAP[ key ] ) {
            return PARAM_LABEL_MAP[ key ];
        }
        return String( key ).replace( /_/g, ' ' ).replace( /\b\w/g, function( c ) {
            return c.toUpperCase();
        } );
    }

    // Save-key failures the wizard reverts to the paste step for: the
    // credential itself is the problem, so a different key is the only fix.
    const KEY_FAILURE_CODES = [
        'invalid_key',
        'wrong_provider_key',
        'malformed_key',
        'empty_key',
        'unchanged'
    ];

    // Failures where the key was stored but Google could not confirm it. The
    // key stays, so the offer is another verification attempt.
    const UNVERIFIED_CODES = [
        'validation_quota',
        'validation_network',
        'validation_provider_down',
        'validation_unknown'
    ];

    // Nothing the wizard can fix in place; the Connectors screen is the exit.
    const LINK_OUT_CODES = [
        'wp_version_unsupported',
        'ai_unsupported',
        'connector_contract_mismatch',
        'provider_inactive',
        'key_source_override',
        'save_failed',
        'forbidden',
        'too_many_attempts'
    ];

    /**
     * Map a save_key error code to the outcome state step 3 renders.
     *
     * An unrecognized code lands on the generic "try another key" state rather
     * than a blank panel: a server that grew a new code must still leave the
     * user somewhere they can act.
     *
     * @param {string} code Server error_code from mainwp_assistant_onboarding_save_key.
     * @return {Object} { severity: 'error'|'warning', action: 'retry_key'|'retry_verify'|'link_out' }
     */
    function classifyKeyFailure( code ) {
        if ( UNVERIFIED_CODES.indexOf( code ) !== -1 ) {
            return { severity: 'warning', action: 'retry_verify' };
        }
        if ( LINK_OUT_CODES.indexOf( code ) !== -1 ) {
            return { severity: 'error', action: 'link_out' };
        }
        if ( KEY_FAILURE_CODES.indexOf( code ) !== -1 ) {
            return { severity: 'error', action: 'retry_key' };
        }
        return { severity: 'error', action: 'retry_key' };
    }

    /**
     * Resolve which wizard panel to show for a status probe.
     *
     * Environment and constant keys outrank the stored option at runtime, so
     * the paste field is unreachable on those sites however the wizard was
     * opened: writing there would look like it worked while the old credential
     * kept serving.
     *
     * @param {Object}  state           Wizard state.
     * @param {string}  state.requested Step the caller asked for: 'get_key', 'paste' or 'verify'.
     * @param {Object}  state.status    Status payload from mainwp_assistant_onboarding_status.
     * @return {string} 'blocked', 'override', 'get_key', 'paste' or 'verify'.
     */
    function nextWizardStep( state ) {
        state = state || {};
        let status = state.status || {};
        let requested = state.requested || 'get_key';

        if ( ! status.wp_version_ok || ! status.ai_supported || ! status.contract_ok ) {
            return 'blocked';
        }
        // The contract reads fine while the provider itself is inactive, and a
        // key pasted into that site is stored but never used. Only an explicit
        // false blocks: a payload from before the flag existed says nothing.
        if ( false === status.provider_active ) {
            return 'blocked';
        }
        if ( 'verify' === requested ) {
            return 'verify';
        }
        if ( 'env' === status.key_source || 'constant' === status.key_source ) {
            return 'override';
        }
        return 'paste' === requested ? 'paste' : 'get_key';
    }

    /**
     * Decide whether the assistant settings page has to reload itself after an
     * onboarding wizard event.
     *
     * A connected key changes every row on the AI Connection tab, so the tab is
     * re-read rather than patched. Which event carries the news depends on what
     * the user did, and the two can arrive in either order:
     *
     * - Verified, then closed: the close reports the connection, so reload.
     * - Closed while the verification was still in flight: the close reports
     *   nothing, and the connected event that lands afterwards is the only
     *   notice there is, so reload on that instead.
     * - Try it: the chat panel is being handed the conversation and a
     *   suggested prompt. A reload would throw both away, so never reload.
     *
     * @param {Object}   evt           Normalized wizard event.
     * @param {string}   evt.type      'closed' or 'connected'.
     * @param {boolean}  evt.connected Close events: whether this wizard session verified a key.
     * @param {string}   evt.action    What ended the modal: 'try_it' or 'close'.
     * @param {boolean}  evt.modalOpen Connected events: whether the modal was still on screen.
     * @return {boolean} True when the page should be reloaded.
     */
    function shouldReloadSettings( evt ) {
        evt = evt || {};

        if ( 'try_it' === evt.action ) {
            return false;
        }
        if ( 'closed' === evt.type ) {
            return true === evt.connected;
        }
        if ( 'connected' === evt.type ) {
            return false === evt.modalOpen;
        }
        return false;
    }

    /**
     * Decide what a no-provider chat surface should offer.
     *
     * 'card' is the connect wizard, 'version' the plain below-7.0 sentence with
     * no links to somewhere the user cannot act, and 'legacy' the existing
     * Connectors deep link for users without the settings right.
     *
     * @param {Object} check Payload from mainwp_assistant_check_provider.
     * @return {string} 'card', 'version' or 'legacy'.
     */
    function resolveConnectPrompt( check ) {
        check = check || {};
        if ( 'wp_version' === check.reason_code ) {
            return 'version';
        }
        return check.wizard_available ? 'card' : 'legacy';
    }

    /**
     * Resolve the controls for a local Docs-to-Sites offer.
     *
     * @param {Object} data Chat response payload.
     * @return {Object} Offer visibility and Sites availability.
     */
    function resolveDocsHandoffAction( data ) {
        let offer = !! data && data.handoff_offer === true;
        return { offer: offer, canContinue: offer && data.agent_available === true };
    }

    function assistantReplyScrollTarget( bubbleOffset, bubbleHeight, viewHeight, scrollHeight ) {
        let bottom = Math.max( 0, scrollHeight - viewHeight );
        return bubbleHeight > viewHeight ? Math.max( 0, Math.min( bubbleOffset - 8, bottom ) ) : bottom;
    }

    let api = {
        assistantReplyScrollTarget: assistantReplyScrollTarget,
        resolveDocsHandoffAction: resolveDocsHandoffAction,
        classifyAckLocally: classifyAckLocally,
        classifyKeyFailure: classifyKeyFailure,
        nextWizardStep: nextWizardStep,
        shouldReloadSettings: shouldReloadSettings,
        resolveConnectPrompt: resolveConnectPrompt,
        isAgentRefusal: isAgentRefusal,
        resolveTransportFailureRecovery: resolveTransportFailureRecovery,
        resolveTransportRecoveryEligibility: resolveTransportRecoveryEligibility,
        resolveRegenerateAvailability: resolveRegenerateAvailability,
        resolveInputRestoreOnTransportFailure: resolveInputRestoreOnTransportFailure,
        normalizeStoredSources: normalizeStoredSources,
        nextChatPollFallbackState: nextChatPollFallbackState,
        humanizeParamKey: humanizeParamKey
    };

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = api;
    } else {
        root.MainWPAssistantChatLogic = api;
    }

} )( typeof window !== 'undefined' ? window : this );
