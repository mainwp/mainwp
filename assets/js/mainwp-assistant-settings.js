/**
 * MainWP Assistant Settings JavaScript
 *
 * Handles all interactive functionality for the Assistant Settings page
 * including provider management, policy settings, and history controls.
 *
 * @package MainWP/Dashboard
 */

/* global jQuery, mainwpAssistantSettings */

( function( $ ) {
    'use strict';

    /**
     * Derive a user-friendly error message from an AJAX failure.
     *
     * @param {Object} xhr        The jqXHR object.
     * @param {string} textStatus The jQuery textStatus string.
     * @param {string} fallbackMsg Fallback message when no server message is available.
     * @param {string} timeoutMsg  Optional custom timeout message (defaults to generic).
     * @return {string} Error message string.
     */
    function getAjaxErrorMessage( xhr, textStatus, fallbackMsg, timeoutMsg ) {
        if ( textStatus === 'timeout' ) {
            return timeoutMsg || mainwpAssistantSettings.strings.request_timeout;
        }
        if ( xhr.status === 0 ) {
            return mainwpAssistantSettings.strings.network_error;
        }
        return xhr.responseJSON?.data?.message || fallbackMsg;
    }

    /**
     * A revert's .prop().trigger('change') fires the same delegated 'change'
     * event that the autosave handlers below listen for; without this guard
     * a failed save re-triggers itself into an unbounded retry loop (and, for
     * the global toggle, spuriously reopens the disable-confirm modal). Set
     * true only while a revert's synchronous trigger('change') is dispatching,
     * so the event reaches mainwp.js's not-default indicator handler but not
     * the autosave handlers here.
     */
    var revertingIndicatorSync = false;

    /**
     * Launch sources owned by this tab's CTAs. The chat panel renders on this
     * page too and listens for the same wizard events, so the tab reloads only
     * for events whose source names one of its own launches. The source rides
     * on the event rather than in a sticky local flag: a flag set here would
     * survive an abandoned launch and misclassify a later chat-panel one.
     */
    var TAB_WIZARD_SOURCES = [ 'settings', 'replace' ];

    /**
     * True once a reload has been asked for. The wizard can report the same
     * connection on both its events, and location.reload() does not stop the
     * script that called it, so without this the page can be reloaded twice.
     */
    var reloadRequested = false;

    /**
     * Reload the AI Connection tab if this wizard event calls for it.
     *
     * Only for launches from this tab: the chat panel renders on this page too
     * and listens for the same connected event, which it handles without a
     * reload.
     *
     * @param {Object} evt Normalized event for shouldReloadSettings().
     */
    function maybeReloadForWizard( evt ) {
        if ( -1 === TAB_WIZARD_SOURCES.indexOf( evt.source ) || reloadRequested ) {
            return;
        }
        if ( ! window.MainWPAssistantChatLogic.shouldReloadSettings( evt ) ) {
            return;
        }

        reloadRequested = true;
        window.location.reload();
    }

    /**
     * MainWP Assistant Settings Module
     */
    const MainWPAssistantSettings = {

        /**
         * Initialize the module.
         */
        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.initializeUI();
        },

        /**
         * Cache DOM elements for performance.
         */
        cacheElements: function() {
            // AI Connection tab elements
            this.$modelPreference = $( '#mainwp-assistant-model-preference' );

            // Modals
            this.$clearHistoryModal = $( '#mainwp-assistant-clear-history-modal' );

            // History tab elements
            this.$retentionDays = $( '#mainwp-assistant-retention-days' );
            this.$optOutCheckbox = $( '#mainwp-assistant-opt-out-checkbox' );
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            const self = this;

            // AI Connection tab
            $( document ).on( 'click', '#mainwp-assistant-save-model-preference', function( e ) {
                e.preventDefault();
                self.saveModelPreference();
            });

            $( document ).on( 'click', '.mainwp-assistant-connect-cta', function( e ) {
                e.preventDefault();
                if ( typeof window.MainWPAssistantOnboarding === 'undefined' || ! window.MainWPAssistantOnboarding.isAvailable() ) {
                    return;
                }
                window.MainWPAssistantOnboarding.open( $( this ).data( 'onboarding-source' ) || 'settings' );
            });

            // A connected key changes every row on this tab, so the tab is
            // re-read rather than patched in place. Which of the two wizard
            // events is the one that has to trigger it depends on what the user
            // did and on the order the answers arrived in, so both are handed
            // to the same rule.
            $( document ).on( 'mainwp-assistant-connected', function( e, detail ) {
                detail = detail || {};
                maybeReloadForWizard( {
                    type: 'connected',
                    action: detail.action,
                    source: detail.source,
                    modalOpen: true === detail.modal_open
                } );
            });

            $( document ).on( 'mainwp-assistant-onboarding-closed', function( e, detail ) {
                detail = detail || {};
                maybeReloadForWizard( {
                    type: 'closed',
                    connected: true === detail.connected,
                    action: detail.action,
                    source: detail.source
                } );
            });

            // Policies tab
            $( document ).on( 'change', '#mainwp-assistant-access-mode-group input[name="access_mode"]', function() {
                if ( revertingIndicatorSync ) {
                    return;
                }
                const mode = $( this ).val();
                self.saveAccessMode( mode );
                self.toggleConfirmationLevelVisibility( mode );
            });

            $( document ).on( 'change', '#mainwp-assistant-confirmation-level-group input[name="confirmation_level"]', function() {
                if ( revertingIndicatorSync ) {
                    return;
                }
                self.saveConfirmationLevel( $( this ).val() );
            });

            $( document ).on( 'change', '#mainwp-assistant-protected-tags-group input[name="protected_tags[]"]', function() {
                self.saveProtectedTags();
            });

            $( document ).on( 'change', '#mainwp-assistant-global-toggle input', function() {
                if ( revertingIndicatorSync ) {
                    return;
                }
                self.saveGlobalToggle( $( this ).is( ':checked' ) );
            });

            $( document ).on( 'change', '#mainwp-assistant-daily-budget', function() {
                self.saveDailyBudget( $( this ).val() );
            });

            $( document ).on( 'change', '#mainwp-assistant-user-daily-budget', function() {
                self.saveUserDailyBudget( $( this ).val() );
            });

            // History tab
            $( document ).on( 'click', '#mainwp-assistant-save-history-settings', function( e ) {
                e.preventDefault();
                self.saveHistorySettings();
            });

            $( document ).on( 'change', '#mainwp-assistant-opt-out-checkbox input', function() {
                const isOptOut = $( this ).is( ':checked' );
                self.$retentionDays.prop( 'disabled', isOptOut );
            });

            $( document ).on( 'click', '#mainwp-assistant-export-history', function( e ) {
                e.preventDefault();
                self.exportHistory();
            });

            $( document ).on( 'click', '#mainwp-assistant-clear-history', function( e ) {
                e.preventDefault();
                self.$clearHistoryModal.modal( 'show' );
            });

            $( document ).on( 'click', '#mainwp-assistant-clear-history-confirm', function() {
                self.clearHistory();
            });

            // Disclosure banner dismiss
            $( document ).on( 'click', '#mainwp-assistant-dismiss-disclosure', function() {
                self.dismissDisclosure();
            });

            // Message close buttons
            $( document ).on( 'click', '.ui.message .close.icon', function() {
                $( this ).closest( '.message' ).transition( 'fade' );
            });

            // Keyboard activation for icon-only dismiss controls.
            $( document ).on( 'keydown', '#mainwp-assistant-dismiss-disclosure, .ui.message .close.icon', function( e ) {
                if ( 'Enter' === e.key || ' ' === e.key ) {
                    e.preventDefault();
                    $( this ).trigger( 'click' );
                }
            });

            // Revoke consent
            $( document ).on( 'click', '#mainwp-assistant-revoke-consent-btn', function() {
                self.openRevokeConsentModal();
            });

            // Grant consent (enable assistant features in place)
            $( document ).on( 'click', '#mainwp-assistant-grant-consent-btn', function( e ) {
                e.preventDefault();
                self.grantConsent();
            });
        },

        /**
         * Initialize Fomantic UI components.
         */
        initializeUI: function() {
            // Initialize dropdowns
            $( '.ui.dropdown' ).dropdown();

            // Initialize checkboxes
            $( '.ui.checkbox' ).checkbox();

            // Initialize radio buttons
            $( '.ui.radio.checkbox' ).checkbox();

            // Initialize modals
            this.$clearHistoryModal.modal({
                closable: true
            });

            // Remember last saved policy values so controls can revert on a failed save.
            this.lastAccessMode = $( '#mainwp-assistant-access-mode-group input[name="access_mode"]:checked' ).val();
            this.lastConfirmationLevel = $( '#mainwp-assistant-confirmation-level-group input[name="confirmation_level"]:checked' ).val();
            this.lastProtectedTags = this.getCheckedProtectedTags();
        },

        /**
         * Collect the currently checked protected tag IDs.
         *
         * @return {Array} Checked tag ID strings.
         */
        getCheckedProtectedTags: function() {
            const tagIds = [];
            $( '#mainwp-assistant-protected-tags-group input:checked' ).each( function() {
                tagIds.push( $( this ).val() );
            });
            return tagIds;
        },

        /**
         * Show in-flight feedback for an autosave control group: a yellow
         * "Saving..." line in the section's message zone and disabled inputs
         * for the duration of the round-trip.
         *
         * @param {string} zone Message zone selector.
         * @param {string} groupSelector Group container selector.
         * @param {boolean} saving True while the request is in flight.
         */
        setGroupSaving: function( zone, groupSelector, saving ) {
            // The selector is either a group container with input descendants
            // or the input itself (the budget fields carry the id on the
            // <input>); addBack() covers both shapes.
            $( groupSelector ).find( 'input' ).addBack( 'input' ).prop( 'disabled', !! saving );
            if ( saving ) {
                this.showMessage( zone, mainwpAssistantSettings.strings.saving, 'saving' );
            }
        },

        /**
         * Monotonic save-request generations per field. Rapid successive
         * edits can put two saves in flight at once; only the response
         * carrying the newest stamp may write the field or lift its
         * disabled state, so an older, slower response cannot clobber the
         * newer value. Tested in tests/js/test-settings-save.js.
         */
        saveGenerations: {},

        /**
         * Stamp a new outgoing save for a field.
         *
         * @param {string} key Field key.
         * @return {number} The generation stamp for this save.
         */
        beginSaveGeneration: function( key ) {
            this.saveGenerations[ key ] = ( this.saveGenerations[ key ] || 0 ) + 1;
            return this.saveGenerations[ key ];
        },

        /**
         * Whether a response's stamp is still the newest save for the field.
         *
         * @param {string} key Field key.
         * @param {number} generation Stamp returned by beginSaveGeneration().
         * @return {boolean} True when no newer save has been fired since.
         */
        isCurrentSaveGeneration: function( key, generation ) {
            return this.saveGenerations[ key ] === generation;
        },

        /**
         * Re-check the radio matching the given value in a group.
         *
         * @param {string} groupSelector Group container selector.
         * @param {string} value Radio value to restore.
         */
        revertRadioGroup: function( groupSelector, value ) {
            if ( typeof value === 'undefined' ) {
                return;
            }
            revertingIndicatorSync = true;
            try {
                $( groupSelector + ' input[value="' + value + '"]' ).prop( 'checked', true ).trigger( 'change' );
            } finally {
                revertingIndicatorSync = false;
            }
        },

        /**
         * Build the confirmation line for a saved model preference.
         *
         * The server answers with the model it stored plus whether a configured
         * provider actually serves it, so a save that went through but named a
         * model nobody offers says so instead of reading as a clean success.
         *
         * @param {string} savedModel Model id the server stored.
         * @param {Object} data       Response payload (known, providers).
         * @return {string} Message to show under the field.
         */
        modelPreferenceMessage: function( savedModel, data ) {
            const strings = mainwpAssistantSettings.strings;

            if ( ! savedModel ) {
                return strings.model_preference_cleared;
            }

            const providers = ( data && Array.isArray( data.providers ) ) ? data.providers : [];

            if ( data && data.known && providers.length ) {
                return strings.model_preference_provider
                    .replace( '%1$s', savedModel )
                    .replace( '%2$s', providers.join( ', ' ) );
            }

            if ( data && false === data.known ) {
                return strings.model_preference_unknown.replace( '%s', savedModel );
            }

            return strings.model_preference_set.replace( '%s', savedModel );
        },

        /**
         * Save the site-wide model preference.
         */
        saveModelPreference: function() {
            const self = this;
            const $button = $( '#mainwp-assistant-save-model-preference' );

            $button.addClass( 'loading disabled' );

            $.post( mainwpAssistantSettings.ajaxurl, {
                action: 'mainwp_assistant_save_model_preference',
                nonce: mainwpAssistantSettings.nonce,
                model_preference: self.$modelPreference.val()
            }, function( response ) {
                $button.removeClass( 'loading disabled' );
                const ok = response && response.success;
                let message = ( response && response.data && response.data.message )
                    ? response.data.message
                    : mainwpAssistantSettings.strings.request_failed;
                if ( ok ) {
                    // Echo the effective value back so a typo is visible immediately.
                    const savedModel = ( response.data && 'string' === typeof response.data.model )
                        ? response.data.model
                        : $.trim( self.$modelPreference.val() );
                    // Write the stored value into the field and fire 'change' so the
                    // delegated settings handler resyncs the not-default indicator.
                    self.$modelPreference.val( savedModel ).trigger( 'change' );
                    message = self.modelPreferenceMessage( savedModel, response.data );
                }
                self.showMessage( '#mainwp-assistant-model-preference-message', message, ok ? 'success' : 'error' );
            }).fail( function( xhr, textStatus ) {
                $button.removeClass( 'loading disabled' );
                self.showMessage(
                    '#mainwp-assistant-model-preference-message',
                    getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.request_failed, mainwpAssistantSettings.strings.request_failed ),
                    'error'
                );
            });
        },

        /**
         * Save access mode.
         *
         * @param {string} mode Access mode value.
         */
        saveAccessMode: function( mode ) {
            const self = this;

            self.setGroupSaving( '#mainwp-assistant-access-mode-message', '#mainwp-assistant-access-mode-group', true );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_save_access_mode',
                    security: mainwpAssistantSettings.nonce,
                    mode: mode
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( response.success ) {
                        self.lastAccessMode = mode;
                        self.showMessage( '#mainwp-assistant-access-mode-message', mainwpAssistantSettings.strings.access_mode_saved, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-access-mode-message', response.data.message, 'error' );
                        self.revertRadioGroup( '#mainwp-assistant-access-mode-group', self.lastAccessMode );
                        self.toggleConfirmationLevelVisibility( self.lastAccessMode );
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Save Access Mode Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.access_mode_failed );
                    self.showMessage( '#mainwp-assistant-access-mode-message', message, 'error' );
                    self.revertRadioGroup( '#mainwp-assistant-access-mode-group', self.lastAccessMode );
                    self.toggleConfirmationLevelVisibility( self.lastAccessMode );
                })
                .always( function() {
                    self.setGroupSaving( '#mainwp-assistant-access-mode-message', '#mainwp-assistant-access-mode-group', false );
                });
        },

        /**
         * Save confirmation level.
         *
         * @param {string} level Confirmation level value.
         */
        saveConfirmationLevel: function( level ) {
            const self = this;

            self.setGroupSaving( '#mainwp-assistant-confirmation-level-message', '#mainwp-assistant-confirmation-level-group', true );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_save_confirmation_level',
                    security: mainwpAssistantSettings.nonce,
                    level: level
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( response.success ) {
                        self.lastConfirmationLevel = level;
                        self.showMessage( '#mainwp-assistant-confirmation-level-message', mainwpAssistantSettings.strings.confirmation_level_saved, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-confirmation-level-message', response.data.message, 'error' );
                        self.revertRadioGroup( '#mainwp-assistant-confirmation-level-group', self.lastConfirmationLevel );
                    }
                })
                .fail( function( xhr, textStatus ) {
                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.confirmation_level_failed );
                    self.showMessage( '#mainwp-assistant-confirmation-level-message', message, 'error' );
                    self.revertRadioGroup( '#mainwp-assistant-confirmation-level-group', self.lastConfirmationLevel );
                })
                .always( function() {
                    self.setGroupSaving( '#mainwp-assistant-confirmation-level-message', '#mainwp-assistant-confirmation-level-group', false );
                });
        },

        /**
         * Toggle confirmation level section visibility based on access mode.
         *
         * Hidden when access mode is read-only (no mutations possible).
         *
         * @param {string} mode Access mode value.
         */
        toggleConfirmationLevelVisibility: function( mode ) {
            const $section = $( '#mainwp-assistant-confirmation-level-section' );
            if ( mode === 'read-only' ) {
                $section.hide();
            } else {
                $section.show();
            }
        },

        /**
         * Save protected tags.
         */
        saveProtectedTags: function() {
            const self = this;
            const tagIds = this.getCheckedProtectedTags();

            self.setGroupSaving( '#mainwp-assistant-protected-tags-message', '#mainwp-assistant-protected-tags-group', true );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_save_protected_tags',
                    security: mainwpAssistantSettings.nonce,
                    tag_ids: JSON.stringify( tagIds )
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( response.success ) {
                        self.lastProtectedTags = tagIds;
                        self.showMessage( '#mainwp-assistant-protected-tags-message', mainwpAssistantSettings.strings.protected_tags_saved, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-protected-tags-message', response.data.message, 'error' );
                        self.revertProtectedTags();
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Save Protected Tags Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.protected_tags_failed );
                    self.showMessage( '#mainwp-assistant-protected-tags-message', message, 'error' );
                    self.revertProtectedTags();
                })
                .always( function() {
                    self.setGroupSaving( '#mainwp-assistant-protected-tags-message', '#mainwp-assistant-protected-tags-group', false );
                });
        },

        /**
         * Restore protected tag checkboxes to the last saved state.
         */
        revertProtectedTags: function() {
            const saved = this.lastProtectedTags || [];
            $( '#mainwp-assistant-protected-tags-group input[name="protected_tags[]"]' ).each( function() {
                $( this ).prop( 'checked', saved.indexOf( $( this ).val() ) !== -1 );
            });
        },

        /**
         * Save global assistant toggle.
         *
         * @param {boolean} enabled Whether assistant is enabled.
         */
        saveGlobalToggle: function( enabled ) {
            const self = this;

            // Confirm via modal when disabling; revert the toggle unless approved.
            if ( ! enabled ) {
                let approved = false;
                $( '#mainwp-assistant-disable-global-modal' )
                    .modal({
                        closable: true,
                        onApprove: function() {
                            approved = true;
                        },
                        onHidden: function() {
                            if ( approved ) {
                                self.submitGlobalToggle( false );
                            } else {
                                $( '#mainwp-assistant-global-toggle input' ).prop( 'checked', true );
                            }
                        }
                    })
                    .modal( 'show' );
                return;
            }

            this.submitGlobalToggle( true );
        },

        /**
         * Persist the global assistant toggle.
         *
         * @param {boolean} enabled Whether assistant is enabled.
         */
        submitGlobalToggle: function( enabled ) {
            const self = this;

            self.setGroupSaving( '#mainwp-assistant-global-toggle-message', '#mainwp-assistant-global-toggle', true );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_save_global_toggle',
                    security: mainwpAssistantSettings.nonce,
                    enabled: enabled
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( response.success ) {
                        self.showMessage( '#mainwp-assistant-global-toggle-message', enabled ? mainwpAssistantSettings.strings.assistant_enabled : mainwpAssistantSettings.strings.assistant_disabled, 'success' );
                        // Update label text
                        const $label = $( '#mainwp-assistant-global-toggle label' );
                        $label.text( enabled ? mainwpAssistantSettings.strings.label_enabled_all_users : mainwpAssistantSettings.strings.label_disabled_all_users );
                    } else {
                        self.showMessage( '#mainwp-assistant-global-toggle-message', response.data.message, 'error' );
                        // Revert checkbox
                        revertingIndicatorSync = true;
                        try {
                            $( '#mainwp-assistant-global-toggle input' ).prop( 'checked', ! enabled ).trigger( 'change' );
                        } finally {
                            revertingIndicatorSync = false;
                        }
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Save Global Toggle Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.save_setting_failed );
                    self.showMessage( '#mainwp-assistant-global-toggle-message', message, 'error' );
                    // Revert checkbox
                    revertingIndicatorSync = true;
                    try {
                        $( '#mainwp-assistant-global-toggle input' ).prop( 'checked', ! enabled ).trigger( 'change' );
                    } finally {
                        revertingIndicatorSync = false;
                    }
                })
                .always( function() {
                    self.setGroupSaving( '#mainwp-assistant-global-toggle-message', '#mainwp-assistant-global-toggle', false );
                });
        },

        /**
         * Save the daily AI spend limit.
         *
         * @param {string} budget The limit in USD; 0 disables the cap.
         */
        saveDailyBudget: function( budget ) {
            const self = this;
            const generation = self.beginSaveGeneration( 'daily_budget' );

            self.setGroupSaving( '#mainwp-assistant-daily-budget-message', '#mainwp-assistant-daily-budget', true );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_save_daily_budget',
                    security: mainwpAssistantSettings.nonce,
                    budget: budget
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( ! self.isCurrentSaveGeneration( 'daily_budget', generation ) ) {
                        return;
                    }
                    if ( response.success ) {
                        const saved = response.data && response.data.budget ? response.data.budget : budget;
                        $( '#mainwp-assistant-daily-budget' ).val( saved );
                        self.updateBudgetTodayLine( response.data );
                        const message = parseFloat( saved ) > 0
                            ? mainwpAssistantSettings.strings.daily_budget_saved
                            : mainwpAssistantSettings.strings.daily_budget_unlimited;
                        self.showMessage( '#mainwp-assistant-daily-budget-message', message, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-daily-budget-message', response.data.message || mainwpAssistantSettings.strings.daily_budget_failed, 'error' );
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Save Daily Budget Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    if ( ! self.isCurrentSaveGeneration( 'daily_budget', generation ) ) {
                        return;
                    }
                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.daily_budget_failed );
                    self.showMessage( '#mainwp-assistant-daily-budget-message', message, 'error' );
                })
                .always( function() {
                    // Only the newest save may lift the disabled state; an
                    // older response returning early must not re-enable the
                    // field while a newer save is still in flight.
                    if ( self.isCurrentSaveGeneration( 'daily_budget', generation ) ) {
                        self.setGroupSaving( '#mainwp-assistant-daily-budget-message', '#mainwp-assistant-daily-budget', false );
                    }
                });
        },

        /**
         * Save the per-user daily AI spend limit.
         *
         * @param {string} budget The per-user limit in USD; 0 disables it.
         */
        saveUserDailyBudget: function( budget ) {
            const self = this;
            const generation = self.beginSaveGeneration( 'user_daily_budget' );

            self.setGroupSaving( '#mainwp-assistant-user-daily-budget-message', '#mainwp-assistant-user-daily-budget', true );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_save_user_daily_budget',
                    security: mainwpAssistantSettings.nonce,
                    budget: budget
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( ! self.isCurrentSaveGeneration( 'user_daily_budget', generation ) ) {
                        return;
                    }
                    if ( response.success ) {
                        const saved = response.data && response.data.budget ? response.data.budget : budget;
                        $( '#mainwp-assistant-user-daily-budget' ).val( saved );
                        self.updateBudgetTodayLine( response.data, '#mainwp-assistant-user-daily-budget-today', 'user_' );
                        const message = parseFloat( saved ) > 0
                            ? mainwpAssistantSettings.strings.user_budget_saved
                            : mainwpAssistantSettings.strings.user_budget_unlimited;
                        self.showMessage( '#mainwp-assistant-user-daily-budget-message', message, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-user-daily-budget-message', response.data.message || mainwpAssistantSettings.strings.user_budget_failed, 'error' );
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Save User Daily Budget Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    if ( ! self.isCurrentSaveGeneration( 'user_daily_budget', generation ) ) {
                        return;
                    }
                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.user_budget_failed );
                    self.showMessage( '#mainwp-assistant-user-daily-budget-message', message, 'error' );
                })
                .always( function() {
                    // Only the newest save may lift the disabled state; an
                    // older response returning early must not re-enable the
                    // field while a newer save is still in flight.
                    if ( self.isCurrentSaveGeneration( 'user_daily_budget', generation ) ) {
                        self.setGroupSaving( '#mainwp-assistant-user-daily-budget-message', '#mainwp-assistant-user-daily-budget', false );
                    }
                });
        },

        /**
         * Rewrite a "used today" line from the save response so it does not
         * go stale after an in-page save.
         *
         * Covers the three states the PHP template renders: spend unreadable
         * (spent_today null), a positive limit, and no limit.
         *
         * @param {Object} data         Save response data ({budget, spent_today, limit_display}).
         * @param {string} lineSelector Target line element (defaults to the site-wide line).
         * @param {string} stringPrefix Localized-string prefix ('' site-wide, 'user_' per-user).
         */
        updateBudgetTodayLine: function( data, lineSelector, stringPrefix ) {
            const $line = $( lineSelector || '#mainwp-assistant-daily-budget-today' );
            const prefix = stringPrefix || '';
            if ( ! $line.length || ! data ) {
                return;
            }

            let text;
            if ( data.spent_today === null || typeof data.spent_today === 'undefined' ) {
                text = mainwpAssistantSettings.strings[ prefix + 'used_today_unreadable' ];
            } else if ( parseFloat( data.budget ) > 0 ) {
                text = mainwpAssistantSettings.strings[ prefix + 'used_today_with_limit' ]
                    .replace( '%1$s', data.spent_today )
                    .replace( '%2$s', data.limit_display );
            } else {
                text = mainwpAssistantSettings.strings[ prefix + 'used_today_no_limit' ].replace( '%s', data.spent_today );
            }

            $line.text( text );
        },

        /**
         * Save history settings.
         */
        saveHistorySettings: function() {
            const self = this;
            const $button = $( '#mainwp-assistant-save-history-settings' );
            const retentionDays = this.$retentionDays.val();
            const optOut = $( '#mainwp-assistant-opt-out-checkbox input' ).is( ':checked' );

            $button.addClass( 'loading disabled' );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_save_history_settings',
                    security: mainwpAssistantSettings.nonce,
                    retention_days: retentionDays,
                    history_opt_out: optOut
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( response.success ) {
                        let message = mainwpAssistantSettings.strings.history_settings_saved;
                        // The server clamps retention to 1-365; reflect the stored
                        // value back so the field never lies about what was saved.
                        const savedDays = response.data && typeof response.data.retention_days !== 'undefined'
                            ? parseInt( response.data.retention_days, 10 )
                            : NaN;
                        if ( ! isNaN( savedDays ) ) {
                            if ( parseInt( retentionDays, 10 ) !== savedDays ) {
                                message = mainwpAssistantSettings.strings.retention_adjusted.replace( '%d', savedDays );
                            }
                            self.$retentionDays.val( savedDays );
                        }
                        self.showMessage( '#mainwp-assistant-history-message', message, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-history-message', response.data.message, 'error' );
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Save History Settings Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.history_settings_failed );
                    self.showMessage( '#mainwp-assistant-history-message', message, 'error' );
                })
                .always( function() {
                    $button.removeClass( 'loading disabled' );
                });
        },

        /**
         * Export conversation history.
         */
        exportHistory: function() {
            const self = this;
            const $button = $( '#mainwp-assistant-export-history' );

            $button.addClass( 'loading disabled' );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_export_history',
                    security: mainwpAssistantSettings.nonce
                },
                timeout: 120000, // 2 minutes - export may take longer with large histories
                dataType: 'text'
            })
                .done( function( responseText ) {
                    // The server streams the export, so a mid-stream failure
                    // still arrives as HTTP 200; the completeness flag inside
                    // the JSON is the only reliable signal.
                    let exportComplete = false;
                    try {
                        exportComplete = true === JSON.parse( responseText ).complete;
                    } catch ( parseError ) {
                        exportComplete = false;
                    }

                    const blob = new Blob( [ responseText ], { type: 'application/json' } );
                    const url = URL.createObjectURL( blob );
                    const a = document.createElement( 'a' );
                    a.href = url;
                    a.download = 'mainwp-assistant-history-' + new Date().toISOString().slice( 0, 10 ) + '.json';
                    document.body.appendChild( a );
                    a.click();
                    document.body.removeChild( a );
                    URL.revokeObjectURL( url );

                    if ( exportComplete ) {
                        self.showMessage( '#mainwp-assistant-history-data-message', mainwpAssistantSettings.strings.history_exported, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-history-data-message', mainwpAssistantSettings.strings.export_incomplete, 'warning' );
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Export History Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    let errorResponse = null;
                    if ( xhr.responseText ) {
                        try {
                            errorResponse = JSON.parse( xhr.responseText );
                        } catch ( parseError ) {
                            errorResponse = null;
                        }
                    }
                    let fallback = errorResponse?.data?.message || errorResponse?.message || mainwpAssistantSettings.strings.export_failed;
                    let message = getAjaxErrorMessage( xhr, textStatus, fallback, mainwpAssistantSettings.strings.export_timeout );
                    self.showMessage( '#mainwp-assistant-history-data-message', message, 'error' );
                })
                .always( function() {
                    $button.removeClass( 'loading disabled' );
                });
        },

        /**
         * Clear conversation history.
         */
        clearHistory: function() {
            const self = this;
            const $button = $( '#mainwp-assistant-clear-history-confirm' );

            $button.addClass( 'loading disabled' );

            $.ajax({
                url: mainwpAssistantSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mainwp_assistant_clear_history',
                    security: mainwpAssistantSettings.nonce
                },
                timeout: 30000,
                dataType: 'json'
            })
                .done( function( response ) {
                    if ( response.success ) {
                        self.$clearHistoryModal.modal( 'hide' );
                        self.showMessage( '#mainwp-assistant-history-data-message', response.data.message, 'success' );
                    } else {
                        self.showMessage( '#mainwp-assistant-history-data-message', response.data.message, 'error' );
                    }
                })
                .fail( function( xhr, textStatus, errorThrown ) {
                    if ( window.console && window.mainwp_assistant_debug ) {
                        console.error( 'MainWP Assistant - Clear History Error:', {
                            status: xhr.status,
                            textStatus: textStatus,
                            errorThrown: errorThrown,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });
                    }

                    let message = getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.clear_history_failed );
                    self.showMessage( '#mainwp-assistant-history-data-message', message, 'error' );
                })
                .always( function() {
                    $button.removeClass( 'loading disabled' );
                });
        },

        /**
         * Dismiss disclosure banner.
         */
        dismissDisclosure: function() {
            const $banner = $( '#mainwp-assistant-disclosure-banner' );

            $.post( mainwpAssistantSettings.ajaxurl, {
                action: 'mainwp_assistant_dismiss_disclosure',
                security: mainwpAssistantSettings.nonce
            });

            $banner.transition( 'fade' );
        },

        /**
         * Open the revoke consent confirmation modal and handle the approval flow.
         */
        openRevokeConsentModal: function() {
            $( '#mainwp-assistant-revoke-consent-modal' )
                .modal( {
                    closable: true,
                    onApprove: function() {
                        var $btn = $( '#mainwp-assistant-revoke-consent-confirm' );
                        $btn.addClass( 'loading disabled' );

                        $.post( ajaxurl, {
                            action: 'mainwp_assistant_revoke_consent',
                            security: typeof mainwpAssistantSettings !== 'undefined' ? mainwpAssistantSettings.nonce : ''
                        }, function( response ) {
                            $btn.removeClass( 'loading disabled' );
                            if ( response && response.success ) {
                                try {
                                    // Consent is already revoked server-side; a storage
                                    // failure (private mode, quota) must not stop the reload.
                                    localStorage.removeItem( 'mainwp_assistant_panel_open' );
                                    localStorage.removeItem( 'mainwp_assistant_current_screen' );
                                } catch ( e ) {} // eslint-disable-line no-empty
                                window.location.reload();
                            } else {
                                var msg = ( response && response.data && response.data.message ) ? response.data.message : mainwpAssistantSettings.strings.revoke_consent_failed;
                                // The server message is set as text on a constant element, never
                                // concatenated into markup, so it cannot introduce nodes.
                                $( '#mainwp-assistant-revoke-consent-modal .content' ).append( $( '<div class="ui red message"></div>' ).text( msg ) );
                            }
                        } ).fail( function() {
                            $btn.removeClass( 'loading disabled' );
                            $( '#mainwp-assistant-revoke-consent-modal .content' ).append( $( '<div class="ui red message"></div>' ).text( mainwpAssistantSettings.strings.request_failed ) );
                        } );

                        return false;
                    }
                } )
                .modal( 'show' );
        },

        /**
         * Show a persistent inline message in a section's message zone.
         *
         * Uses the shared MainWP helper so save feedback matches other
         * settings screens; the message stays until the next save replaces it.
         *
         * @param {string} zone    Message zone selector (e.g. '#mainwp-assistant-access-mode-message').
         * @param {string} message Message text.
         * @param {string} type    'success', 'warning', 'saving' or 'error'.
         */
        showMessage: function( zone, message, type ) {
            const safeText = $( '<span>' ).text( message || '' ).html();
            let color = 'red';
            if ( 'success' === type ) {
                color = 'green';
            } else if ( 'saving' === type || 'warning' === type ) {
                color = 'yellow';
            }
            if ( typeof window.mainwp_set_message_zone === 'function' ) {
                window.mainwp_set_message_zone( zone, safeText, color );
            } else if ( window.console && window.mainwp_assistant_debug ) {
                window.console.log( message );
            }
        },

        /**
         * Grant assistant features consent from the Policies tab.
         *
         * Mirrors the consent gate in the assistant panel; on success the page
         * reloads so every consent-dependent section re-renders.
         */
        grantConsent: function() {
            const self = this;
            const $button = $( '#mainwp-assistant-grant-consent-btn' );

            $button.addClass( 'loading disabled' );

            $.post( mainwpAssistantSettings.ajaxurl, {
                action: 'mainwp_assistant_grant_consent',
                security: mainwpAssistantSettings.consentNonce
            }, function( response ) {
                if ( response && response.success ) {
                    window.location.reload();
                } else {
                    $button.removeClass( 'loading disabled' );
                    const message = ( response && response.data && response.data.message )
                        ? response.data.message
                        : mainwpAssistantSettings.strings.grant_consent_failed;
                    self.showMessage( '#mainwp-assistant-consent-message', message, 'error' );
                }
            }).fail( function( xhr, textStatus ) {
                $button.removeClass( 'loading disabled' );
                self.showMessage(
                    '#mainwp-assistant-consent-message',
                    getAjaxErrorMessage( xhr, textStatus, mainwpAssistantSettings.strings.grant_consent_failed ),
                    'error'
                );
            });
        }
    };

    // Initialize when DOM is ready
    $( document ).ready( function() {
        MainWPAssistantSettings.init();
    });

}( jQuery ) );
