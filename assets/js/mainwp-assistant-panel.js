/**
 * MainWP Assistant Panel
 *
 * Intercom-style floating panel for AI Assistant chat.
 * Handles panel open/close, screen navigation, and keyboard/mouse interactions.
 *
 * @package MainWP/Dashboard
 */

/* global jQuery, MainWPMintlifyChat, mainwpAssistantPanelL10n */

var MainWPAssistantPanel = ( function( $ ) {
    'use strict';

    var state = {
        isOpen: false,
        currentScreen: 'home',
        navigationHistory: [],
        previousFocus: null,
        touchState: {
            startY: 0,
            currentY: 0,
            isDragging: false
        },
        bodyScrollLocked: false,
        savedBodyOverflow: '',
        geometry: { v: 1, x: null, y: null, w: 480, h: 640 },
        isDragging: false,
        isResizing: false,
        dragDidMove: false,
        panelState: 'normal',       // 'normal' | 'minimized' | 'maximized'
        previousGeometry: null,      // saved {x, y, w, h} before min/max
        previousState: 'normal'      // state to restore to from minimized
    };

    var $panel, $header, $title, $closeBtn;
    var $chatScreen;
    var $tabBar, $tabs;

    var $homeScreen;

    /**
     * Stashed action to replay after consent is granted.
     * Shape: { type: 'chat'|'tour'|'video'|'support', data: any }
     */
    var pendingConsentAction = null;
    var supportPending = false;
    var supportReturnScreen = 'home';

    var GEOMETRY_KEY = 'mainwp_assistant_geometry';
    var PANEL_STATE_KEY = 'mainwp_assistant_panel_state';
    var PREV_GEOMETRY_KEY = 'mainwp_assistant_prev_geometry';
    var CONSTRAINTS = {
        minWidth: 320,
        maxWidth: 800,
        minHeight: 360,
        headerHeight: 48,
        adminBarOffset: 8,
        dragThreshold: 5
    };

    /**
     * Cache DOM elements for performance.
     */
    function cacheElements() {
        $panel = $( '#mainwp-assistant-panel' );
        $header = $panel.find( '.mainwp-assistant-panel-header' );
        $title = $header.find( '.panel-title' );
        $closeBtn = $header.find( '.close-button' );
        $chatScreen = $( '#mainwp-assistant-chat-screen' );
        $homeScreen = $( '#mainwp-assistant-home-screen' );
        $tabBar = $panel.find( '.mainwp-assistant-tab-bar' );
        $tabs = $tabBar.find( '.mainwp-assistant-tab' ).not( '.mainwp-assistant-tab-external' );
    }

    /**
     * Bind event handlers.
     */
    function bindEvents() {
        $closeBtn.on( 'click', function() {
            MainWPAssistantPanel.close();
        } );

        $header.find( '.minimize-button' ).on( 'click', function() {
            minimize();
        } );

        $header.find( '.maximize-button' ).on( 'click', function() {
            maximize();
        } );

        $header.find( '.new-chat-button' ).on( 'click', startNewChat );

        $tabs.on( 'click', function() {
            var $tab = $( this );
            var screen = $tab.data( 'screen' );
            if ( screen === 'home' ) {
                MainWPAssistantPanel.navigateToHome();
            } else if ( screen === 'messages' ) {
                MainWPAssistantPanel.navigateToMessages();
            } else if ( screen === 'support' ) {
                MainWPAssistantPanel.openSupport();
            }
        } );

        // ESC key: maximized → restore; normal/minimized → close panel
        $( document ).on( 'keydown.assistantPanel', function( e ) {
            if ( e.key === 'Escape' && state.isOpen ) {
                // Defer to Fomantic modal/dimmer ESC handling when one is active.
                if ( $( '.ui.modal.visible, .ui.dimmer.active' ).filter( ':visible' ).length ) {
                    return;
                }
                if ( state.panelState === 'maximized' ) {
                    maximize(); // restore to normal
                } else {
                    MainWPAssistantPanel.close();
                }
                return;
            }
            handleFocusTrap( e );
            handleCardArrowNav( e );
            handlePanelKeyGeometry( e );
        } );

        $( document ).on( 'click', '#mainwp-home-start-chat-card, #mainwp-home-start-new-chat', function( e ) {
            e.preventDefault();
            requireConsent( 'chat', {}, function() {
                MainWPAssistantPanel.navigateToChat();
            } );
        } );

        $( document ).on( 'click', '#mainwp-home-tour-card', function() {
            if ( $( this ).is( ':disabled' ) || $( this ).hasClass( 'disabled' ) || 'true' === $( this ).attr( 'aria-disabled' ) ) {
                return;
            }
            var tourId = $( this ).data( 'tour-id' );
            if ( ! tourId ) {
                return;
            }
            requireConsent( 'tour', { tourId: tourId }, function() {
                MainWPAssistantPanel.close();
                if ( typeof USETIFUL !== 'undefined' && USETIFUL.tour ) {
                    USETIFUL.tour.start( parseInt( tourId, 10 ) );
                }
            } );
        } );

        $( document ).on( 'click', '#mainwp-home-videos-card', function() {
            if ( $( this ).is( ':disabled' ) || $( this ).hasClass( 'disabled' ) || 'true' === $( this ).attr( 'aria-disabled' ) ) {
                return;
            }
            var videoId = $( this ).data( 'video-id' );
            if ( ! videoId ) {
                return;
            }
            requireConsent( 'video', { videoId: videoId }, function() {
                $( '#mainwp-assistant-video-iframe' ).attr(
                    'src',
                    'https://www.youtube.com/embed/' + videoId
                );
                MainWPAssistantPanel.showScreen( 'video', true );
            } );
        } );

        $( document ).on( 'click', '#mainwp-home-support-card', function() {
            window.open( 'https://community.mainwp.com/', '_blank' );
        } );

        // Home screen: Recent conversation preview
        $( document ).on( 'click', '.recent-conversation-preview', function() {
            var conversationId = $( this ).data( 'conversation-id' );
            if ( conversationId && typeof MainWPMintlifyChat !== 'undefined' && MainWPMintlifyChat.loadConversation ) {
                requireConsent( 'chat', { conversationId: conversationId }, function() {
                    MainWPAssistantPanel.navigateToChat();
                    MainWPMintlifyChat.loadConversation( conversationId );
                } );
            }
        } );

        // Home screen: Keyboard activation for the recent preview.
        $( document ).on( 'keydown', '.recent-conversation-preview', function( e ) {
            if ( 'Enter' === e.key || ' ' === e.key ) {
                e.preventDefault();
                $( this ).trigger( 'click' );
            }
        } );

        // Messages screen: Click conversation to load it
        $( document ).on( 'click', '.mainwp-message-item', function( e ) {
            if ( $( e.target ).closest( '.delete-conversation-btn, .export-conversation-btn' ).length ) {
                return;
            }
            var conversationId = $( this ).data( 'conversation-id' );
            if ( conversationId && typeof MainWPMintlifyChat !== 'undefined' && MainWPMintlifyChat.loadConversation ) {
                requireConsent( 'chat', { conversationId: conversationId }, function() {
                    MainWPAssistantPanel.navigateToChat();
                    MainWPMintlifyChat.loadConversation( conversationId );
                } );
            }
        } );

        // Messages screen: Keyboard activation for conversation rows.
        $( document ).on( 'keydown', '.mainwp-message-item', function( e ) {
            if ( ( 'Enter' === e.key || ' ' === e.key ) && ! $( e.target ).is( 'button' ) ) {
                e.preventDefault();
                $( this ).trigger( 'click' );
            }
        } );

        // Messages screen: Per-conversation export (JSON / Markdown).
        $( document ).on( 'click', '.export-conversation-btn', function( e ) {
            e.stopPropagation();
            var $btn = $( this );
            var conversationId = $btn.closest( '.mainwp-message-item' ).data( 'conversation-id' );
            var format = $btn.data( 'format' ) || 'json';

            if ( ! conversationId || typeof MainWPMintlifyChat === 'undefined' || ! MainWPMintlifyChat.exportConversationById ) {
                return;
            }

            $btn.prop( 'disabled', true );
            MainWPMintlifyChat.exportConversationById( conversationId, format )
                .catch( function( errorMsg ) {
                    if ( $.fn.toast ) {
                        $( 'body' ).toast( { class: 'error', message: errorMsg } );
                    }
                } )
                .finally( function() {
                    $btn.prop( 'disabled', false );
                } );
        } );

        // Messages screen: Delete conversation
        $( document ).on( 'click', '.delete-conversation-btn', function( e ) {
            e.stopPropagation();
            var $item = $( this ).closest( '.mainwp-message-item' );
            var conversationId = $item.data( 'conversation-id' );

            var $modal = $( '#mainwp-messages-delete-modal' );
            var $deleteMsg = $( '#mainwp-messages-delete-message' );
            $deleteMsg.text( getL10n( 'delete_confirm', 'Delete this conversation? This cannot be undone.' ) ).removeClass( 'error' );
            $modal
                .modal( {
                    closable: true,
                    onApprove: function() {
                        var $confirmBtn = $modal.find( '#mainwp-messages-delete-confirm' );
                        $confirmBtn.addClass( 'loading disabled' );

                        var deleteData = {
                            action: 'mainwp_assistant_delete_conversation',
                            conversation_id: conversationId
                        };
                        deleteData = mainwp_secure_data( deleteData );
                        $.post( ajaxurl, deleteData, function( response ) {
                            $confirmBtn.removeClass( 'loading disabled' );
                            if ( response && response.success ) {
                                $modal.modal( 'hide' );
                                $item.fadeOut( 300, function() {
                                    $item.remove();
                                    if ( $( '#mainwp-messages-conversation-list .mainwp-message-item' ).length === 0 ) {
                                        $( '#mainwp-messages-conversation-list' ).hide();
                                        $( '#mainwp-messages-empty' ).show();
                                    }
                                } );
                            } else {
                                $deleteMsg.text( getL10n( 'delete_error', 'Failed to delete conversation. Please try again.' ) ).addClass( 'error' );
                            }
                        } ).fail( function() {
                            $confirmBtn.removeClass( 'loading disabled' );
                            $deleteMsg.text( getL10n( 'delete_error', 'Failed to delete conversation. Please try again.' ) ).addClass( 'error' );
                        } );

                        return false; // Keep modal open until AJAX completes
                    }
                } )
                .modal( 'show' );
        } );

        var messagesSearchTimer;
        $( document ).on( 'keyup', '#mainwp-messages-search-input', function() {
            var query = $( this ).val().toLowerCase();
            clearTimeout( messagesSearchTimer );
            messagesSearchTimer = setTimeout( function() {
                $( '#mainwp-messages-conversation-list .mainwp-message-item' ).each( function() {
                    var title = $( this ).find( '.message-title' ).text().toLowerCase();
                    $( this ).toggle( title.indexOf( query ) !== -1 );
                } );
            }, 300 );
        } );

        $( document ).on( 'click', '#mainwp-messages-new-chat-btn', function( e ) {
            e.preventDefault();
            startNewChat();
        } );

        $( document ).on( 'click', '#mainwp-messages-start-chat', function( e ) {
            e.preventDefault();
            startNewChat();
        } );

    }

    /**
     * Open the panel on the chat screen, including from the minimized state.
     *
     * @param {boolean} skipAnimation Show the panel without the slide-in.
     */
    function showChatScreen( skipAnimation ) {
        var wasMinimized = state.panelState === 'minimized';
        MainWPAssistantPanel.open( 'chat', skipAnimation );
        // open() restores a minimized panel on whatever screen it had.
        if ( wasMinimized ) {
            MainWPAssistantPanel.showScreen( 'chat', false, true );
        }
    }

    /**
     * Start a new chat: consent gate, navigate to chat screen, clear conversation.
     */
    function startNewChat() {
        requireConsent( 'chat', {}, function() {
            MainWPAssistantPanel.navigateToChat();
            if ( typeof MainWPMintlifyChat !== 'undefined' && MainWPMintlifyChat.clearConversation ) {
                MainWPMintlifyChat.clearConversation();
            }
        } );
    }

    /**
     * Check consent and either proceed or show the consent gate.
     *
     * @param {string} actionType The feature being accessed: 'chat', 'tour', or 'video'.
     * @param {object} actionData Extra data to replay after consent (e.g., tourId, videoId).
     * @param {Function} callback Function to call if consent is already granted.
     */
    function requireConsent( actionType, actionData, callback ) {
        var l10n = typeof mainwpAssistantPanelL10n !== 'undefined' ? mainwpAssistantPanelL10n : {};

        if ( l10n.hasConsent ) {
            callback();
            return;
        }

        // Non-admin without consent: show permission toast (no modal mutation).
        if ( ! l10n.canGrantConsent ) {
            var msg = l10n.consent_no_permission || 'Assistant features are off. Ask a dashboard administrator to enable them.';
            if ( $.fn.toast ) {
                $( 'body' ).toast( {
                    class: 'warning',
                    message: msg,
                    showProgress: 'bottom'
                } );
            }
            return;
        }

        // Admin without consent: show consent modal, stash the action.
        pendingConsentAction = { type: actionType, data: actionData, callback: callback };
        showConsentModal();
    }

    /**
     * Display the consent modal with its default content and bind handlers.
     */
    function showConsentModal() {
        var $modal = $( '#mainwp-assistant-consent-modal' );
        var $error = $( '#mainwp-assistant-consent-error' );
        var $acceptBtn = $( '#mainwp-assistant-consent-accept' );

        $error.hide().text( '' );
        $acceptBtn.show().removeClass( 'loading disabled' );

        $modal
            .modal( {
                closable: false,
                onApprove: function() {
                    return false; // Prevent auto-close; we handle it in the AJAX callback.
                },
                onDeny: function() {
                    pendingConsentAction = null;
                }
            } )
            .modal( 'show' );

        // .off() first: the modal is re-shown per gate, and without it each show would
        // stack another click handler and fire the grant request more than once.
        $acceptBtn.off( 'click.consent' ).on( 'click.consent', function() {
            var l10n = typeof mainwpAssistantPanelL10n !== 'undefined' ? mainwpAssistantPanelL10n : {};
            $acceptBtn.addClass( 'loading disabled' );
            $error.hide();

            $.post( ajaxurl, {
                action: 'mainwp_assistant_grant_consent',
                security: l10n.consentNonce || ''
            }, function( response ) {
                $acceptBtn.removeClass( 'loading disabled' );
                if ( response && response.success ) {
                    // Update local state so subsequent clicks skip the gate.
                    if ( typeof mainwpAssistantPanelL10n !== 'undefined' ) {
                        mainwpAssistantPanelL10n.hasConsent = true;
                    }
                    $modal.modal( 'hide' );

                    // Show info message if bridge registration was deferred.
                    if ( response.data && response.data.registered === false ) {
                        var $panel = $( '#mainwp-assistant-panel' );
                        var $info  = $( '<div class="ui info message dismissible" style="margin:8px 12px"><i class="close icon"></i><p></p></div>' );
                        $info.find( 'p' ).text( getL10n( 'connection_deferred', 'Assistant enabled. It connects to the documentation service on first use.' ) );
                        $info.find( '.close' ).on( 'click', function() { $info.remove(); } );
                        $panel.find( '.mainwp-mintlify-messages' ).prepend( $info );
                    }

                    // Replay stashed action.
                    if ( pendingConsentAction && pendingConsentAction.callback ) {
                        pendingConsentAction.callback();
                    }
                    pendingConsentAction = null;
                } else {
                    var errMsg = ( response && response.data && response.data.message )
                        ? response.data.message
                        : ( l10n.consent_error || 'Failed to enable assistant features.' );
                    $error.text( errMsg ).show();
                }
            } ).fail( function() {
                $acceptBtn.removeClass( 'loading disabled' );
                var l10n2 = typeof mainwpAssistantPanelL10n !== 'undefined' ? mainwpAssistantPanelL10n : {};
                $error.text( l10n2.consent_error || 'Failed to enable assistant features.' ).show();
            } );
        } );
    }

    /**
     * Updates the active tab highlight to match the current screen.
     */
    function updateActiveTab() {
        if ( ! $tabs || ! $tabs.length ) {
            return;
        }
        $tabs.removeClass( 'active' ).attr( 'aria-selected', 'false' );
        // Map chat screen to messages tab since chat is a sub-screen of messages.
        var tabScreen = state.currentScreen === 'chat' ? 'messages' : state.currentScreen;
        $tabs.filter( '[data-screen="' + tabScreen + '"]' ).addClass( 'active' ).attr( 'aria-selected', 'true' );
    }

    /**
     * Get localized string with fallback.
     *
     * @param {string} key The localization key.
     * @param {string} fallback Default value if key not found.
     * @return {string} The localized string or fallback.
     */
    function getL10n( key, fallback ) {
        if ( typeof mainwpAssistantPanelL10n !== 'undefined' && mainwpAssistantPanelL10n[ key ] ) {
            return mainwpAssistantPanelL10n[ key ];
        }
        return fallback;
    }

    /**
     * Escape HTML entities in a string.
     *
     * @param {string} text The string to escape.
     * @return {string} The escaped string.
     */
    function escapeHtml( text ) {
        if ( text == null ) return ''; // eslint-disable-line eqeqeq -- intentional: catch null & undefined, preserve 0.
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String( text ).replace( /[&<>"']/g, function( m ) { return map[ m ]; } );
    }

    /**
     * Keep support copy in the shared localized map.
     *
     * @param {string} key Copy key.
     * @return {string} Localized copy.
     */
    function supportText( key ) {
        return mainwpMintlifyChatL10n.support[ key ];
    }

    /**
     * Show the support result using text nodes for customer and localized text.
     *
     * @param {Object} $container Result container.
     * @param {boolean} success Whether support accepted the request.
     * @param {string} email Reply address submitted by the customer.
     */
    function renderSupportResult( $container, success, email ) {
        var $message = $( '<div class="ui message" role="status" aria-live="polite"></div>' );
        if ( success ) {
            $message.text( supportText( 'success' ).replace( '%s', function() { return email; } ) );
        } else {
            $message.text( supportText( 'failure' ) + ' ' );
            $message.append( $( '<a></a>' ).attr( 'href', 'https://mainwp.com/contact/' ).text( supportText( 'contact_label' ) ) );
            $message.append( document.createTextNode( '.' ) );
        }
        $container.empty().append( $message );
    }

    /**
     * Render one support form with a snapshot of the current conversation.
     */
    function renderSupportForm() {
        var context = MainWPMintlifyChat.getSupportContext();
        var $screen = $( '#mainwp-assistant-support-screen' ).empty();
        var $container = $( '<div class="ui segment"></div>' );
        var $form = $( '<form class="ui form"></form>' );
        var $email = $( '<input id="mainwp-support-email" type="email" maxlength="254" required>' ).val( mainwpMintlifyChatL10n.support_email );
        var $note = $( '<textarea id="mainwp-support-note" rows="5" maxlength="1500"></textarea>' ).val( Array.from( context.note ).slice( 0, 1500 ).join( '' ) );
        var hasTranscript = ! context.historyDisabled && !! context.conversationId;
        $note.prop( 'required', ! hasTranscript );
        // Themes colour form inputs through .ui.input > input; .ui.form input rules skip type="email".
        $form.append( $( '<div class="field"></div>' ).append( $( '<label for="mainwp-support-email"></label>' ).text( supportText( 'email' ) ), $( '<div class="ui fluid input"></div>' ).append( $email ) ) );
        $form.append( $( '<div class="field"></div>' ).append( $( '<label for="mainwp-support-note"></label>' ).text( supportText( 'note' ) ), $note ) );
        $form.append( $( '<p></p>' ).text( supportText( 'includes' ) ) );
        var $list = $( '<ul></ul>' ).append( $( '<li></li>' ).text( supportText( 'note' ) ) );
        if ( hasTranscript ) {
            $list.append( $( '<li></li>' ).text( supportText( 'transcript' ) ) );
        }
        $list.append( $( '<li></li>' ).text( supportText( 'versions' ) ) );
        $form.append( $list );
        if ( context.historyDisabled ) {
            $form.append( $( '<p></p>' ).text( supportText( 'history_off' ) ) );
        }
        var $validation = $( '<p role="alert"></p>' );
        var $send = $( '<button type="submit" class="ui green button"></button>' ).text( supportText( 'send' ) );
        var $cancel = $( '<button type="button" class="ui basic button"></button>' ).text( supportText( 'cancel' ) );
        $cancel.on( 'click', function() { MainWPAssistantPanel.showScreen( supportReturnScreen, false ); } );
        $form.append( $validation, $send, $cancel );
        $form.on( 'submit', function( event ) {
            event.preventDefault();
            if ( supportPending ) { return; }
            if ( ! hasTranscript && ! $note.val().trim() ) {
                $validation.text( supportText( 'note_required' ) );
                $note.trigger( 'focus' );
                return;
            }
            var email = $email.val().trim();
            supportPending = true;
            $send.prop( 'disabled', true ).addClass( 'loading' );
            $validation.text( '' );
            $.ajax( {
                url: ajaxurl, type: 'POST', timeout: 25000,
                data: mainwp_secure_data( {
                    action: 'mainwp_assistant_escalate',
                    email: email,
                    note: $note.val(),
                    conversation_id: context.conversationId || 0
                } )
            } ).done( function( response ) {
                renderSupportResult( $container, !! ( response && response.success === true ), email );
            } ).fail( function() {
                renderSupportResult( $container, false, email );
            } ).always( function() {
                supportPending = false;
                $send.prop( 'disabled', false ).removeClass( 'loading' );
            } );
        } );
        $screen.append( $container.append( $form ) );
    }

    /**
     * Check if the current device supports touch.
     *
     * @return {boolean} True if touch events are available.
     */
    function isTouchDevice() {
        return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    }

    /**
     * Check if the viewport is mobile-sized.
     *
     * @return {boolean} True if viewport width is ≤768px.
     */
    function isMobileViewport() {
        return window.innerWidth <= 768;
    }

    /**
     * Check if localStorage is available.
     *
     * @return {boolean} True if localStorage works.
     */
    function hasLocalStorage() {
        try {
            var key = '__mainwp_ls_test__';
            localStorage.setItem( key, '1' );
            localStorage.removeItem( key );
            return true;
        } catch ( e ) {
            return false;
        }
    }

    /**
     * Get the height of the WordPress admin bar.
     *
     * @return {number} The admin bar height in pixels, or 32 as fallback.
     */
    function getAdminBarHeight() {
        var bar = document.getElementById( 'wpadminbar' );
        return bar ? bar.offsetHeight : 32;
    }

    /**
     * Validate that a geometry object has all required finite fields.
     *
     * @param {object} g The geometry object to validate.
     * @return {boolean} True if all four coordinate/size fields are finite numbers.
     */
    function isValidGeometry( g ) {
        return g && isFinite( g.x ) && isFinite( g.y ) && isFinite( g.w ) && isFinite( g.h );
    }

    /**
     * Grow geometry still at the old 400x560 default to the new default.
     *
     * A panel at exactly 400x560 was never resized. Its bottom-right corner
     * stays put.
     *
     * @param {object} g The geometry object, changed in place.
     * @return {boolean} True if the geometry was changed.
     */
    function migrateOldDefault( g ) {
        if ( 400 !== g.w || 560 !== g.h ) {
            return false;
        }
        g.x -= 80;
        g.y -= 80;
        g.w = 480;
        g.h = 640;
        return true;
    }

    /**
     * Load panel geometry from localStorage or compute defaults.
     */
    function loadGeometry() {
        var saved = null;
        if ( hasLocalStorage() ) {
            try {
                saved = JSON.parse( localStorage.getItem( GEOMETRY_KEY ) );
            } catch ( e ) {
                saved = null;
            }
        }
        var migrated = false;
        if ( isValidGeometry( saved ) ) {
            migrated = migrateOldDefault( saved );
            state.geometry = saved;
        } else {
            state.geometry.w = 480;
            state.geometry.h = 640;
            state.geometry.x = window.innerWidth - state.geometry.w - 20;
            state.geometry.y = window.innerHeight - state.geometry.h - 20;
        }
        clampGeometry( true );

        // Restore panel state (minimized/maximized) from localStorage.
        loadPanelState();
        if ( state.previousGeometry && migrateOldDefault( state.previousGeometry ) ) {
            migrated = true;
        }

        // The inline script in the panel markup places the panel from the
        // saved geometry before this runs, so save the new size right away;
        // otherwise every page load shows the old size and then jumps.
        if ( migrated ) {
            saveGeometry();
        }
    }

    /**
     * Save panel geometry to localStorage.
     */
    function saveGeometry() {
        if ( hasLocalStorage() ) {
            localStorage.setItem( GEOMETRY_KEY, JSON.stringify( state.geometry ) );
            savePanelState();
        }
    }

    /**
     * Apply the current geometry state to the panel DOM element.
     */
    function applyGeometry() {
        var g = state.geometry;
        $panel.css( {
            left: g.x + 'px',
            top: g.y + 'px',
            width: g.w + 'px',
            height: g.h + 'px',
            bottom: 'auto',
            right: 'auto'
        } );
    }

    /**
     * Clamp geometry to keep the panel within viewport bounds.
     *
     * @param {boolean} atRest When true, the whole panel must stay on-screen
     *                         (open/restore/resize/settle). When false, only
     *                         48px must remain visible, the loose bound used
     *                         mid-gesture so drags can overshoot smoothly.
     */
    function clampGeometry( atRest ) {
        var g = state.geometry;
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var minTop = getAdminBarHeight() + CONSTRAINTS.adminBarOffset;
        var maxH = Math.floor( vh * 0.85 );

        g.w = Math.max( CONSTRAINTS.minWidth, Math.min( g.w, CONSTRAINTS.maxWidth ) );
        g.h = Math.max( CONSTRAINTS.minHeight, Math.min( g.h, maxH ) );

        if ( atRest ) {
            // Fully on-viewport: a panel at rest never overflows the screen.
            g.x = Math.max( 0, Math.min( g.x, vw - g.w ) );
            g.y = Math.max( minTop, Math.min( g.y, vh - g.h ) );
        } else {
            // Mid-gesture: keep at least 48px of panel visible on each edge.
            g.x = Math.max( CONSTRAINTS.headerHeight - g.w, Math.min( g.x, vw - CONSTRAINTS.headerHeight ) );
            g.y = Math.max( minTop, Math.min( g.y, vh - CONSTRAINTS.headerHeight ) );
        }
    }

    /**
     * Bind drag-to-reposition on the panel header via Pointer Events.
     */
    function bindDragHandlers() {
        var headerEl = $header[0];
        if ( ! headerEl ) {
            return;
        }

        var dragStart = { px: 0, py: 0, panelX: 0, panelY: 0 };

        headerEl.addEventListener( 'pointerdown', function( e ) {
            if ( e.target.closest( 'button, input, a, [data-action]' ) ) {
                return;
            }
            if ( isMobileViewport() || e.button !== 0 ) {
                return;
            }
            if ( state.panelState === 'maximized' ) {
                return;
            }

            dragStart.px = e.clientX;
            dragStart.py = e.clientY;
            dragStart.panelX = state.geometry.x;
            dragStart.panelY = state.geometry.y;

            headerEl.setPointerCapture( e.pointerId );
            $panel.addClass( 'dragging' );
            state.isDragging = true;
            state.dragDidMove = false;
            e.preventDefault();
        } );

        headerEl.addEventListener( 'pointermove', function( e ) {
            if ( ! state.isDragging ) {
                return;
            }

            var dx = e.clientX - dragStart.px;
            var dy = e.clientY - dragStart.py;

            if ( Math.abs( dx ) > CONSTRAINTS.dragThreshold || Math.abs( dy ) > CONSTRAINTS.dragThreshold ) {
                state.dragDidMove = true;
            }

            var minTop = getAdminBarHeight() + CONSTRAINTS.adminBarOffset;
            var vw = window.innerWidth;
            var vh = window.innerHeight;

            state.geometry.x = Math.max(
                CONSTRAINTS.headerHeight - state.geometry.w,
                Math.min( dragStart.panelX + dx, vw - CONSTRAINTS.headerHeight )
            );
            state.geometry.y = Math.max(
                minTop,
                Math.min( dragStart.panelY + dy, vh - CONSTRAINTS.headerHeight )
            );

            $panel.css( { left: state.geometry.x + 'px', top: state.geometry.y + 'px' } );
        } );

        headerEl.addEventListener( 'pointerup', function( e ) {
            if ( ! state.isDragging ) {
                return;
            }

            headerEl.releasePointerCapture( e.pointerId );
            $panel.removeClass( 'dragging' );
            state.isDragging = false;

            state.geometry.x = Math.round( state.geometry.x );
            state.geometry.y = Math.round( state.geometry.y );
            // Settle fully on-screen: a drag may overshoot mid-gesture but
            // the panel never rests off-viewport.
            clampGeometry( true );
            applyGeometry();
            saveGeometry();
        } );

        // Click suppression after a drag. Capture phase, so it runs before the jQuery
        // delegated handlers.
        headerEl.addEventListener( 'click', function( e ) {
            if ( state.dragDidMove ) {
                e.stopPropagation();
                e.preventDefault();
                state.dragDidMove = false;
            }
        }, true );

        // Lost pointer capture cleanup
        headerEl.addEventListener( 'lostpointercapture', function() {
            if ( state.isDragging ) {
                $panel.removeClass( 'dragging' );
                state.isDragging = false;
                clampGeometry( true );
                applyGeometry();
                saveGeometry();
            }
        } );
    }

    /**
     * Bind resize handlers on corner handle elements via Pointer Events.
     */
    function bindResizeHandlers() {
        var $handles = $panel.find( '.mainwp-assistant-resize-handle' );

        if ( ! $handles.length ) {
            return;
        }

        var resizeStart = { px: 0, py: 0, x: 0, y: 0, w: 0, h: 0, corner: '' };

        $handles.each( function() {
            var handleEl = this;
            var corner = handleEl.dataset.corner;

            handleEl.addEventListener( 'pointerdown', function( e ) {
                if ( isMobileViewport() || e.button !== 0 || state.isResizing ) {
                    return;
                }

                resizeStart.px = e.clientX;
                resizeStart.py = e.clientY;
                resizeStart.x = state.geometry.x;
                resizeStart.y = state.geometry.y;
                resizeStart.w = state.geometry.w;
                resizeStart.h = state.geometry.h;
                resizeStart.corner = corner;

                handleEl.setPointerCapture( e.pointerId );
                $panel.addClass( 'resizing' );
                state.isResizing = true;
                e.preventDefault();
            } );

            handleEl.addEventListener( 'pointermove', function( e ) {
                if ( ! state.isResizing ) {
                    return;
                }

                var dx = e.clientX - resizeStart.px;
                var dy = e.clientY - resizeStart.py;
                var maxH = Math.floor( window.innerHeight * 0.85 );
                var newW, newH;

                if ( resizeStart.corner === 'br' ) {
                    newW = resizeStart.w + dx;
                    newH = resizeStart.h + dy;
                } else if ( resizeStart.corner === 'bl' ) {
                    newW = resizeStart.w - dx;
                    newH = resizeStart.h + dy;
                } else if ( resizeStart.corner === 'tr' ) {
                    newW = resizeStart.w + dx;
                    newH = resizeStart.h - dy;
                } else { // tl
                    newW = resizeStart.w - dx;
                    newH = resizeStart.h - dy;
                }

                var clampedW = Math.max( CONSTRAINTS.minWidth, Math.min( newW, CONSTRAINTS.maxWidth ) );
                var clampedH = Math.max( CONSTRAINTS.minHeight, Math.min( newH, maxH ) );

                // Recompute position from the clamped size, anchoring the opposite edge.
                if ( resizeStart.corner === 'br' ) {
                    state.geometry.x = resizeStart.x;
                    state.geometry.y = resizeStart.y;
                } else if ( resizeStart.corner === 'bl' ) {
                    state.geometry.x = resizeStart.x + resizeStart.w - clampedW;
                    state.geometry.y = resizeStart.y;
                } else if ( resizeStart.corner === 'tr' ) {
                    state.geometry.x = resizeStart.x;
                    state.geometry.y = resizeStart.y + resizeStart.h - clampedH;
                } else { // tl
                    state.geometry.x = resizeStart.x + resizeStart.w - clampedW;
                    state.geometry.y = resizeStart.y + resizeStart.h - clampedH;
                }

                state.geometry.w = clampedW;
                state.geometry.h = clampedH;

                // Position clamping, same formula as drag.
                clampGeometry();
                applyGeometry();
            } );

            handleEl.addEventListener( 'pointerup', function( e ) {
                if ( ! state.isResizing ) {
                    return;
                }

                handleEl.releasePointerCapture( e.pointerId );
                $panel.removeClass( 'resizing' );
                state.isResizing = false;

                state.geometry.x = Math.round( state.geometry.x );
                state.geometry.y = Math.round( state.geometry.y );
                state.geometry.w = Math.round( state.geometry.w );
                state.geometry.h = Math.round( state.geometry.h );
                clampGeometry( true );
                applyGeometry();
                saveGeometry();
            } );

            // Lost pointer capture cleanup
            handleEl.addEventListener( 'lostpointercapture', function() {
                if ( state.isResizing ) {
                    $panel.removeClass( 'resizing' );
                    state.isResizing = false;
                    clampGeometry( true );
                    applyGeometry();
                    saveGeometry();
                }
            } );
        } );
    }

    /**
     * Bind swipe-to-close gesture on panel header.
     */
    function bindSwipeGesture() {
        if ( ! isTouchDevice() ) {
            return;
        }

        var headerEl = $header[0];
        if ( ! headerEl ) {
            return;
        }

        headerEl.addEventListener( 'touchstart', function( e ) {
            if ( ! isMobileViewport() ) {
                return;
            }
            state.touchState.startY = e.touches[0].clientY;
            state.touchState.isDragging = true;
        }, { passive: true } );

        headerEl.addEventListener( 'touchmove', function( e ) {
            if ( ! isMobileViewport() || ! state.touchState.isDragging ) {
                return;
            }
            state.touchState.currentY = e.touches[0].clientY;
            var deltaY = state.touchState.currentY - state.touchState.startY;

            if ( deltaY > 0 ) {
                $panel.css( 'transform', 'translateY(' + Math.min( deltaY, 150 ) + 'px)' );
                $panel.css( 'transition', 'none' );
            }
        }, { passive: true } );

        headerEl.addEventListener( 'touchend', function() {
            if ( ! isMobileViewport() || ! state.touchState.isDragging ) {
                return;
            }
            state.touchState.isDragging = false;
            var deltaY = state.touchState.currentY - state.touchState.startY;

            $panel.css( 'transition', '' );
            $panel.css( 'transform', '' );

            if ( deltaY > 100 ) {
                MainWPAssistantPanel.close();
            }
        }, { passive: true } );
    }

    /**
     * Get all focusable elements within the panel.
     *
     * @return {jQuery} Focusable elements.
     */
    function getFocusableElements() {
        return $panel.find(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ).filter( ':visible' );
    }

    /**
     * Trap focus within the panel while open.
     *
     * @param {Event} e The keydown event.
     */
    function handleFocusTrap( e ) {
        if ( e.key !== 'Tab' || ! state.isOpen ) {
            return;
        }

        // Yield focus control when an external modal or dimmer is active,
        // so keyboard users can interact with modal controls.
        if ( $( '.ui.modal.visible, .ui.dimmer.active' ).filter( ':visible' ).length ) {
            return;
        }

        var $focusable = getFocusableElements();
        if ( $focusable.length === 0 ) {
            return;
        }

        var $first = $focusable.first();
        var $last = $focusable.last();

        if ( e.shiftKey ) {
            if ( document.activeElement === $first[0] ) {
                e.preventDefault();
                $last.focus();
            }
        } else {
            if ( document.activeElement === $last[0] ) {
                e.preventDefault();
                $first.focus();
            }
        }
    }

    /**
     * Handle arrow key navigation on home screen cards.
     *
     * @param {Event} e The keydown event.
     */
    function handleCardArrowNav( e ) {
        if ( ! state.isOpen || state.currentScreen !== 'home' ) {
            return;
        }

        var $cards = $homeScreen.find( '.mainwp-assistant-home-card:not(.disabled)' );
        var currentIndex = $cards.index( document.activeElement );
        if ( currentIndex === -1 ) {
            return;
        }

        var newIndex = -1;
        var columns = isMobileViewport() ? 1 : 2;

        switch ( e.key ) {
            case 'ArrowRight':
                newIndex = Math.min( currentIndex + 1, $cards.length - 1 );
                break;
            case 'ArrowLeft':
                newIndex = Math.max( currentIndex - 1, 0 );
                break;
            case 'ArrowDown':
                newIndex = Math.min( currentIndex + columns, $cards.length - 1 );
                break;
            case 'ArrowUp':
                newIndex = Math.max( currentIndex - columns, 0 );
                break;
        }

        if ( newIndex !== -1 && newIndex !== currentIndex ) {
            e.preventDefault();
            $cards.eq( newIndex ).focus();
        }
    }

    /**
     * Keyboard move/resize for the panel. When the header move target has
     * focus, arrow keys reposition the panel; when a resize handle has focus,
     * arrow keys grow or shrink from that corner (Right/Down grow, Left/Up
     * shrink) with the opposite edge anchored. Shift takes larger steps. Every
     * path shares the pointer tail: clampGeometry → applyGeometry → saveGeometry.
     *
     * @param {Event} e The keydown event.
     */
    function handlePanelKeyGeometry( e ) {
        if ( ! state.isOpen || isMobileViewport() || state.panelState === 'maximized' ) {
            return;
        }

        var key = e.key;
        if ( 'ArrowLeft' !== key && 'ArrowRight' !== key && 'ArrowUp' !== key && 'ArrowDown' !== key ) {
            return;
        }

        var active = document.activeElement;
        var step = e.shiftKey ? 48 : 16;
        var g = state.geometry;

        // Header move target: shift the whole panel by one step.
        if ( active === $header[0] ) {
            if ( 'ArrowLeft' === key ) {
                g.x -= step;
            } else if ( 'ArrowRight' === key ) {
                g.x += step;
            } else if ( 'ArrowUp' === key ) {
                g.y -= step;
            } else {
                g.y += step;
            }

            e.preventDefault();
            clampGeometry( true );
            applyGeometry();
            saveGeometry();
            return;
        }

        // Resize handle: grow/shrink from the focused corner.
        if ( active && active.classList && active.classList.contains( 'mainwp-assistant-resize-handle' ) ) {
            var corner = active.dataset.corner;
            var maxH = Math.floor( window.innerHeight * 0.85 );
            var dw = 0;
            var dh = 0;

            if ( 'ArrowRight' === key ) {
                dw = step;
            } else if ( 'ArrowLeft' === key ) {
                dw = -step;
            } else if ( 'ArrowDown' === key ) {
                dh = step;
            } else {
                dh = -step;
            }

            var newW = Math.max( CONSTRAINTS.minWidth, Math.min( g.w + dw, CONSTRAINTS.maxWidth ) );
            var newH = Math.max( CONSTRAINTS.minHeight, Math.min( g.h + dh, maxH ) );

            // Left-edge corners keep their right edge fixed; top-edge corners
            // keep their bottom edge fixed (matches the pointer resize anchors).
            if ( 'tl' === corner || 'bl' === corner ) {
                g.x += g.w - newW;
            }
            if ( 'tl' === corner || 'tr' === corner ) {
                g.y += g.h - newH;
            }
            g.w = newW;
            g.h = newH;

            e.preventDefault();
            clampGeometry( true );
            applyGeometry();
            saveGeometry();
        }
    }

    /**
     * Announce screen change to screen readers via the live region.
     *
     * @param {string} message The message to announce.
     */
    function announceToScreenReader( message ) {
        var $liveRegion = $( '#mainwp-assistant-live-region' );
        if ( $liveRegion.length ) {
            $liveRegion.text( message );
        }
    }

    /**
     * Lock body scroll on mobile when panel is open.
     * Saves current overflow value so other components' locks aren't clobbered.
     */
    function lockBodyScroll() {
        if ( isMobileViewport() && ! state.bodyScrollLocked ) {
            state.savedBodyOverflow = document.body.style.overflow;
            state.bodyScrollLocked = true;
            document.body.style.overflow = 'hidden';
        }
    }

    /**
     * Unlock body scroll, restoring the value that existed before the lock.
     */
    function unlockBodyScroll() {
        if ( state.bodyScrollLocked ) {
            document.body.style.overflow = state.savedBodyOverflow || '';
            state.bodyScrollLocked = false;
            state.savedBodyOverflow = '';
        }
    }

    /**
     * Load conversations list for the messages screen.
     */
    function loadMessagesList() {
        var $loading = $( '#mainwp-messages-loading' );
        var $empty = $( '#mainwp-messages-empty' );
        var $list = $( '#mainwp-messages-conversation-list' );

        // Skip AJAX when consent has not been granted.
        var l10nMsg = typeof mainwpAssistantPanelL10n !== 'undefined' ? mainwpAssistantPanelL10n : {};
        if ( ! l10nMsg.hasConsent ) {
            $( '.mainwp-messages-search' ).hide();
            $list.hide();
            $empty.find( 'p' ).text( l10nMsg.consent_required_history || 'Enable assistant features to view conversation history.' );
            $empty.find( 'a' ).hide();
            $empty.show();
            return;
        }

        $loading.show();
        $empty.hide();
        $list.hide().empty();

        var historyData = {
            action: 'mainwp_assistant_get_history',
            limit: 50,
            page: 1
        };
        historyData = mainwp_secure_data( historyData );
        $.post( ajaxurl, historyData, function( response ) {
            if ( response && response.success && response.data ) {
                if ( response.data.history_disabled ) {
                    $( '.mainwp-messages-search' ).hide();
                    $( '#mainwp-messages-conversation-list' ).hide();
                    $empty.find( 'p' ).text( getL10n( 'history_disabled', 'Conversation history is disabled for your account.' ) );
                    $empty.find( 'a' ).hide();
                    $empty.show();
                } else if ( response.data.conversations && response.data.conversations.length > 0 ) {
                    $( '.mainwp-messages-search' ).show();
                    renderConversationList( response.data.conversations );
                } else {
                    $empty.find( 'p' ).text( getL10n( 'no_conversations', 'No conversations yet' ) );
                    $empty.find( 'a' ).show();
                    $empty.show();
                }
            } else {
                // A malformed 200 is a failure, not an empty history, so report it as one.
                $empty.find( 'p' ).text( getL10n( 'load_error', 'Unable to load conversations. Please try again later.' ) );
                $empty.find( 'a' ).hide();
                $empty.show();
            }
        } ).fail( function() {
            $empty.find( 'p' ).text( getL10n( 'load_error', 'Unable to load conversations. Please try again later.' ) );
            $empty.find( 'a' ).show();
            $empty.show();
        } ).always( function() {
            $loading.hide();
        } );
    }

    /**
     * Render conversation list items in the messages screen.
     *
     * @param {Array} conversations Array of conversation objects.
     */
    function renderConversationList( conversations ) {
        var $list = $( '#mainwp-messages-conversation-list' );
        var $empty = $( '#mainwp-messages-empty' );

        $list.empty();

        // Translations land in HTML attributes here, so they need the same
        // escaping the conversation fields get.
        var exportJsonLabel = escapeHtml( getL10n( 'export_json_label', 'Export as JSON' ) );
        var exportMdLabel   = escapeHtml( getL10n( 'export_markdown_label', 'Export as Markdown' ) );
        var deleteLabel     = escapeHtml( getL10n( 'delete_label', 'Delete conversation' ) );

        $.each( conversations, function( _i, convo ) {
            var title = escapeHtml( convo.title || getL10n( 'untitled', 'Untitled Conversation' ) );
            var count = convo.message_count ? convo.message_count + ' ' + getL10n( 'message_count', 'messages' ) : '';
            var date = convo.date || '';
            var meta = count + ( count && date ? ' &middot; ' : '' ) + escapeHtml( date );

            var $item = $(
                '<div class="mainwp-message-item" role="button" tabindex="0" data-conversation-id="' + escapeHtml( convo.id ) + '" aria-label="' + title + '">' +
                    '<div class="mainwp-message-item-content">' +
                        '<div class="message-title">' + title + '</div>' +
                        '<div class="message-meta">' + meta + '</div>' +
                    '</div>' +
                    '<button class="ui mini icon button export-conversation-btn" data-format="json" data-tooltip="' + exportJsonLabel + '" data-inverted="" data-position="top center" aria-label="' + exportJsonLabel + '">' +
                        '<i class="download icon"></i>' +
                    '</button>' +
                    '<button class="ui mini icon button export-conversation-btn" data-format="markdown" data-tooltip="' + exportMdLabel + '" data-inverted="" data-position="top center" aria-label="' + exportMdLabel + '">' +
                        '<i class="file text icon"></i>' +
                    '</button>' +
                    '<button class="ui mini icon button delete-conversation-btn" data-tooltip="' + deleteLabel + '" data-inverted="" data-position="top center" aria-label="' + deleteLabel + '">' +
                        '<i class="trash alternate outline icon"></i>' +
                    '</button>' +
                '</div>'
            );

            $list.append( $item );
        } );

        $list.show();
        $empty.hide();
    }

    /**
     * Load the most recent conversation for the home screen.
     */
    function loadRecentConversation() {
        var $loading = $( '#mainwp-home-recent-loading' );
        var $empty = $( '#mainwp-home-recent-empty' );
        var $preview = $( '#mainwp-home-recent-preview' );

        // Skip AJAX when consent has not been granted.
        var l10nRecent = typeof mainwpAssistantPanelL10n !== 'undefined' ? mainwpAssistantPanelL10n : {};
        if ( ! l10nRecent.hasConsent ) {
            $preview.hide();
            $empty.show();
            return;
        }

        $loading.show();
        $empty.hide();
        $preview.hide();

        var recentData = {
            action: 'mainwp_assistant_get_history',
            limit: 1
        };
        recentData = mainwp_secure_data( recentData );
        $.post( ajaxurl, recentData, function( response ) {
            $loading.hide();

            if ( response && response.success && response.data &&
                response.data.conversations && response.data.conversations.length > 0 ) {
                var convo = response.data.conversations[0];
                var title = convo.title || getL10n( 'untitled', 'Untitled Conversation' );
                // Prefer the real last-message excerpt; fall back to the count
                // for conversations stored before snippets were returned.
                var summary = convo.snippet
                    ? convo.snippet
                    : ( convo.message_count ? convo.message_count + ' ' + getL10n( 'messages', 'messages' ) : '' );

                $( '#mainwp-home-recent-title' ).text( title );
                $( '#mainwp-home-recent-snippet' ).text( summary );
                $( '#mainwp-home-recent-time' ).text( convo.date || '' );
                $preview.data( 'conversation-id', convo.id ).attr( { role: 'button', tabindex: '0' } ).show();
            } else {
                $empty.show();
            }
        } ).fail( function() {
            $loading.hide();
            $empty.find( 'span' ).text( getL10n( 'load_error', 'Unable to load conversations. Please try again later.' ) );
            $empty.show();
        } );
    }

    /**
     * Minimize the panel: hide it entirely and highlight the sidebar menu item.
     * Saves previousState and previousGeometry so restore can return to the
     * exact position/size and maximized-or-normal state the user had before.
     */
    function minimize() {
        if ( state.panelState === 'maximized' ) {
            state.previousState = 'maximized';
            $panel.removeClass( 'maximized' );
            updateMaximizeIcon( false );
        } else {
            state.previousState = 'normal';
            state.previousGeometry = $.extend( {}, state.geometry );
        }

        state.panelState = 'minimized';
        state.isOpen = false;
        $panel.css( 'display', 'none' ).removeClass( 'opening' );

        $( '#mainwp-assistant-chat-menu-item' ).addClass( 'assistant-chat-active' );

        unlockBodyScroll();

        savePanelState();
    }

    /**
     * Restore the panel from minimized state.
     * Called from open() when it detects panelState === 'minimized'.
     */
    function restoreFromMinimized() {
        $( '#mainwp-assistant-chat-menu-item' ).removeClass( 'assistant-chat-active assistant-chat-notification' );

        $panel.css( 'display', 'flex' );

        if ( state.previousState === 'maximized' ) {
            state.panelState = 'maximized';
            $panel.addClass( 'maximized' );
            updateMaximizeIcon( true );
        } else {
            state.panelState = 'normal';
            if ( isValidGeometry( state.previousGeometry ) ) {
                state.geometry = $.extend( {}, state.previousGeometry );
            }
            clampGeometry( true );
            applyGeometry();
        }

        state.isOpen = true;
        state.previousState = 'normal';

        lockBodyScroll();

        savePanelState();
    }

    /**
     * Toggle maximized state.
     * Maximizing saves current geometry and expands panel to fill the viewport.
     * Restoring returns to previousGeometry.
     */
    function maximize() {
        if ( state.panelState === 'maximized' ) {
            $panel.removeClass( 'maximized' );
            state.panelState = 'normal';
            updateMaximizeIcon( false );

            if ( isValidGeometry( state.previousGeometry ) ) {
                state.geometry = $.extend( {}, state.previousGeometry );
                applyGeometry();
            }
        } else {
            state.previousGeometry = $.extend( {}, state.geometry );

            state.panelState = 'maximized';
            $panel.addClass( 'maximized' );
            updateMaximizeIcon( true );
        }

        savePanelState();
    }

    /**
     * Update the maximize button icon and tooltip.
     *
     * @param {boolean} isMaximized Whether the panel is currently maximized.
     */
    function updateMaximizeIcon( isMaximized ) {
        var $btn = $header.find( '.maximize-button' );
        var $icon = $btn.find( 'i.icon' );
        if ( isMaximized ) {
            $icon.removeClass( 'expand' ).addClass( 'compress' );
            $btn.attr( 'aria-label', getL10n( 'restore', 'Restore' ) )
                .attr( 'data-tooltip', getL10n( 'restore', 'Restore' ) );
        } else {
            $icon.removeClass( 'compress' ).addClass( 'expand' );
            $btn.attr( 'aria-label', getL10n( 'maximize', 'Maximize' ) )
                .attr( 'data-tooltip', getL10n( 'maximize', 'Maximize' ) );
        }
    }

    /**
     * Save panel state (normal/minimized/maximized) and previousGeometry to localStorage.
     */
    function savePanelState() {
        if ( hasLocalStorage() ) {
            localStorage.setItem( PANEL_STATE_KEY, state.panelState );
            if ( state.previousGeometry ) {
                localStorage.setItem( PREV_GEOMETRY_KEY, JSON.stringify( state.previousGeometry ) );
            }
        }
    }

    /**
     * Load panel state and previousGeometry from localStorage.
     */
    function loadPanelState() {
        if ( ! hasLocalStorage() ) {
            return;
        }
        var savedState = localStorage.getItem( PANEL_STATE_KEY );
        if ( savedState === 'minimized' || savedState === 'maximized' || savedState === 'normal' ) {
            state.panelState = savedState;
        }
        try {
            var prevGeo = JSON.parse( localStorage.getItem( PREV_GEOMETRY_KEY ) );
            if ( isValidGeometry( prevGeo ) ) {
                state.previousGeometry = prevGeo;
            }
        } catch ( e ) {
            state.previousGeometry = null;
        }
    }

    return {
        /**
         * Initialize the panel.
         */
        init: function() {
            try {
                cacheElements();
                if ( $panel.length === 0 ) {
                    return;
                }
                bindEvents();
                bindSwipeGesture();
                bindDragHandlers();
                bindResizeHandlers();

                if ( ! isMobileViewport() ) {
                    loadGeometry();
                }

                var resizeTimer;
                $( window ).on( 'resize.assistantPanel', function() {
                    clearTimeout( resizeTimer );
                    resizeTimer = setTimeout( function() {
                        if ( state.isOpen && ! isMobileViewport() && state.panelState === 'normal' ) {
                            clampGeometry( true );
                            applyGeometry();
                            saveGeometry();
                        }
                    }, 150 );
                } );

                $homeScreen.find( '.mainwp-assistant-home-card:not(.disabled)' ).attr( 'tabindex', '0' );

                $( document ).on( 'keydown', '.mainwp-assistant-home-card:not(.disabled)', function( e ) {
                    if ( e.key === 'Enter' || e.key === ' ' ) {
                        e.preventDefault();
                        $( this ).trigger( 'click' );
                    }
                } );

                if ( hasLocalStorage() ) {
                    var wasOpen = localStorage.getItem( 'mainwp_assistant_panel_open' ) === 'true';
                    var lastScreen = localStorage.getItem( 'mainwp_assistant_current_screen' ) || 'home';

                    // If consent was revoked since last session, clear stale screen
                    // state so we don't auto-open into a gated screen.
                    var l10n = typeof mainwpAssistantPanelL10n !== 'undefined' ? mainwpAssistantPanelL10n : {};
                    if ( ! l10n.hasConsent && lastScreen !== 'home' ) {
                        localStorage.removeItem( 'mainwp_assistant_current_screen' );
                        lastScreen = 'home';
                    }

                    if ( wasOpen ) {
                        if ( state.panelState === 'minimized' ) {
                            // Panel was minimized: keep it hidden and show the sidebar indicator.
                            $( '#mainwp-assistant-chat-menu-item' ).addClass( 'assistant-chat-active' );
                        } else {
                            this.open( lastScreen, true );
                        }
                    }
                }
            } catch ( e ) {
                // Any init failure leaves the panel closed rather than half-built.
                if ( window.console && console.error ) {
                    console.error( 'MainWP Assistant: initialization failed', e );
                }
            }
        },

        /**
         * Open the panel.
         *
         * @param {string} screen Optional screen to show. Defaults to 'home'.
         * @param {boolean} skipAnimation If true, show panel instantly (no slide-in animation).
         */
        open: function( screen, skipAnimation ) {
            try {
                screen = screen || 'home';

                // Restore from minimized: bring back the panel at its previous
                // position/size without re-running full open logic or showScreen.
                if ( state.panelState === 'minimized' ) {
                    restoreFromMinimized();

                    if ( hasLocalStorage() ) {
                        localStorage.setItem( 'mainwp_assistant_panel_open', 'true' );
                    }
                    return;
                }

                state.previousFocus = document.activeElement;

                if ( ! isMobileViewport() ) {
                    clampGeometry( true );
                    applyGeometry();
                }

                if ( skipAnimation ) {
                    $panel.css( 'display', 'flex' ).removeClass( 'closing opening' );
                } else {
                    $panel.css( 'display', 'flex' ).removeClass( 'closing' ).addClass( 'opening' );
                }
                state.isOpen = true;

                if ( state.panelState === 'maximized' ) {
                    $panel.addClass( 'maximized' );
                    updateMaximizeIcon( true );
                }

                this.showScreen( screen, false, !! skipAnimation );

                lockBodyScroll();

                if ( hasLocalStorage() ) {
                    localStorage.setItem( 'mainwp_assistant_panel_open', 'true' );
                }
            } catch ( e ) {
                if ( window.console && console.error ) {
                    console.error( 'MainWP Assistant: open failed', e );
                }
            }
        },

        /**
         * Open the panel on the chat screen after the consent gate.
         */
        openChat: function() {
            requireConsent( 'chat', {}, function() {
                showChatScreen( false );
            } );
        },

        /**
         * Open chat and append a question without sending it or changing mode.
         *
         * @param {string} text Question to add to the current draft after consent.
         */
        ask: function( text ) {
            requireConsent( 'chat', { text: text }, function() {
                showChatScreen( true );

                // Let the screen's 50 ms focus callback finish first.
                setTimeout( function() {
                    var $input = $( '#mainwp-mintlify-input' );
                    var draft = $input.val();
                    $input.val( draft ? draft + '\n' + text : text ).trigger( 'input.mintlify' ).trigger( 'focus' );
                }, 100 );
            } );
        },

        /**
         * Close the panel.
         */
        close: function() {
            try {
                $panel.removeClass( 'opening minimized maximized' ).addClass( 'closing' );
                state.panelState = 'normal';
                state.previousState = 'normal';
                updateMaximizeIcon( false );
                state.isOpen = false;

                // Clear the sidebar highlight and notification dot, in case close ran while minimized.
                $( '#mainwp-assistant-chat-menu-item' ).removeClass( 'assistant-chat-active assistant-chat-notification' );

                // Matches the 0.3s mainwp-panel-slide-out animation in
                // mainwp-assistant-panel.css; changing one means changing the other.
                setTimeout( function() {
                    $panel.css( 'display', 'none' ).removeClass( 'closing' );
                }, 300 );

                state.navigationHistory = [];

                // Stop video playback when closing panel.
                $( '#mainwp-assistant-video-iframe' ).attr( 'src', '' );

                unlockBodyScroll();

                if ( state.previousFocus && typeof state.previousFocus.focus === 'function' ) {
                    state.previousFocus.focus();
                    state.previousFocus = null;
                }

                if ( hasLocalStorage() ) {
                    localStorage.removeItem( 'mainwp_assistant_panel_open' );
                    localStorage.removeItem( 'mainwp_assistant_current_screen' );
                    localStorage.removeItem( PANEL_STATE_KEY );
                }
            } catch ( e ) {
                if ( window.console && console.error ) {
                    console.error( 'MainWP Assistant: close failed', e );
                }
            }
        },

        /**
         * Show a specific screen within the panel.
         *
         * @param {string} screenName The screen identifier ('home', 'messages', 'chat', 'video', 'support').
         * @param {boolean} addToHistory Whether to add current screen to history stack.
         * @param {boolean} suppressAnimation Skip the enter/exit transition. Used on
         *                  session restore so the panel slide-in, screen-enter, and
         *                  per-message fades don't stack into a washed-out flash.
         */
        showScreen: function( screenName, addToHistory, suppressAnimation ) {
            try {
                addToHistory = addToHistory !== false; // Default true

                // Stop video playback when leaving video screen.
                if ( state.currentScreen === 'video' ) {
                    $( '#mainwp-assistant-video-iframe' ).attr( 'src', '' );
                }

                if ( addToHistory && state.currentScreen && state.currentScreen !== screenName ) {
                    state.navigationHistory.push( state.currentScreen );
                }

                // Guard: prevent navigating to gated screens without consent.
                var l10nScreen = typeof mainwpAssistantPanelL10n !== 'undefined' ? mainwpAssistantPanelL10n : {};
                if ( ! l10nScreen.hasConsent && ( screenName === 'chat' || screenName === 'support' ) ) {
                    screenName = 'home';
                }

                var $allScreens = $panel.find( '.mainwp-assistant-screen' );
                var $currentActive = $allScreens.filter( '.active' );

                var $targetScreen;
                var titleText;

                if ( screenName === 'home' ) {
                    $targetScreen = $homeScreen;
                    titleText = getL10n( 'home_title', 'MainWP Assistant' );
                } else if ( screenName === 'messages' ) {
                    $targetScreen = $( '#mainwp-assistant-messages-screen' );
                    titleText = getL10n( 'messages_title', 'Messages' );
                } else if ( screenName === 'chat' ) {
                    $targetScreen = $chatScreen;
                    titleText = getL10n( 'chat_title', 'MainWP Assistant' );
                } else if ( screenName === 'support' ) {
                    $targetScreen = $( '#mainwp-assistant-support-screen' );
                    titleText = supportText( 'menu' );
                } else if ( screenName === 'video' ) {
                    $targetScreen = $( '#mainwp-assistant-video-screen' );
                    titleText = getL10n( 'video_title', 'Video Tutorial' );
                }

                // Fall back to chat screen if the requested screen doesn't exist in the DOM
                if ( ! $targetScreen || ! $targetScreen.length ) {
                    $targetScreen = $chatScreen;
                    screenName = 'chat';
                    titleText = getL10n( 'chat_title', 'MainWP Assistant' );
                }

                // Screen transition: exit current, enter new
                var prefersReducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
                var skipTransition = prefersReducedMotion || !! suppressAnimation;
                var doAnimate = ! skipTransition && $currentActive.length && $currentActive[0] !== $targetScreen[0];

                if ( doAnimate ) {
                    // Matches the 0.2s mainwp-screen-exit animation in the panel CSS.
                    $currentActive.addClass( 'screen-exit' );
                    setTimeout( function() {
                        $currentActive.removeClass( 'active screen-exit' );
                        activateScreen( $targetScreen, screenName );
                    }, 200 );
                } else {
                    $allScreens.removeClass( 'active screen-enter screen-exit' );
                    activateScreen( $targetScreen, screenName );
                }

                state.currentScreen = screenName;
                $title.text( titleText );

                updateActiveTab();

                announceToScreenReader( titleText );

                if ( hasLocalStorage() ) {
                    // Video and support screens are filled when opened, not when restored after a page load.
                    localStorage.setItem(
                        'mainwp_assistant_current_screen',
                        screenName === 'video' || screenName === 'support' ? 'home' : screenName
                    );
                }
            } catch ( e ) {
                if ( window.console && console.error ) {
                    console.error( 'MainWP Assistant: showScreen failed', e );
                }
            }

            /**
             * Activate screen and load its data.
             *
             * @param {jQuery} $screen The screen element to activate.
             * @param {string} name The screen name identifier.
             */
            function activateScreen( $screen, name ) {
                $screen.addClass( 'active' );

                if ( ! skipTransition ) {
                    $screen.addClass( 'screen-enter' );
                    // Matches the 0.25s mainwp-screen-enter animation in the panel CSS.
                    setTimeout( function() {
                        $screen.removeClass( 'screen-enter' );
                    }, 250 );
                }

                if ( name === 'home' ) {
                    loadRecentConversation();
                } else if ( name === 'messages' ) {
                    loadMessagesList();
                } else if ( name === 'chat' ) {
                    if ( typeof MainWPMintlifyChat !== 'undefined' ) {
                        MainWPMintlifyChat.init();
                    }
                }

                setTimeout( function() {
                    var $focusable = $screen.find( 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex="0"]' ).filter( ':visible' ).first();
                    if ( $focusable.length ) {
                        $focusable.focus();
                    }
                }, 50 );
            }
        },

        /**
         * Open support from either the navigation menu or a Docs answer.
         */
        openSupport: function() {
            requireConsent( 'support', {}, function() {
                if ( state.currentScreen !== 'support' && ! supportPending ) {
                    supportReturnScreen = state.currentScreen;
                    renderSupportForm();
                }
                MainWPAssistantPanel.showScreen( 'support', true );
            } );
        },

        /**
         * Navigate to the home screen (clears history).
         */
        navigateToHome: function() {
            state.navigationHistory = [];
            this.showScreen( 'home', false );
        },

        /**
         * Navigate to the chat screen (with history tracking).
         */
        navigateToChat: function() {
            this.showScreen( 'chat', true );
        },

        /**
         * Navigate to the messages screen (clears history; it is a tab-level destination).
         */
        navigateToMessages: function() {
            state.navigationHistory = [];
            this.showScreen( 'messages', false );
        },

        /**
         * Check if the panel is currently open.
         *
         * @return {boolean} True if panel is open.
         */
        isOpen: function() {
            return state.isOpen;
        },

        /**
         * Check if the panel is currently minimized.
         *
         * @return {boolean} True if panel is minimized.
         */
        isMinimized: function() {
            return state.panelState === 'minimized';
        },

        /**
         * Toggle the panel open/closed.
         */
        toggle: function() {
            if ( state.isOpen ) {
                this.close();
            } else {
                this.open( 'home' );
            }
        }
    };
} )( jQuery );

// Initialize on DOM ready
jQuery( document ).ready( function() {
    MainWPAssistantPanel.init();
} );
