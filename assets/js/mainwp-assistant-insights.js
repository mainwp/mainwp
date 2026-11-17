/**
 * MainWP Assistant Insights Dashboard JavaScript
 *
 * Handles data loading, chart rendering, table management, and user interactions
 * for the Assistant Insights widgets on the InsightsOverview page.
 *
 * @package MainWP/Dashboard
 */

/* global jQuery, mainwpAssistantInsights, mainwpParams, ApexCharts, mainwp_datatable_fix_menu_overflow */

(function ($) {
    'use strict';

    // Configuration and state.
    const config = {
        activityTable: null,
        topAbilitiesChart: null,
        providerUsageChart: null
    };

    /**
     * Get date range from the shared InsightsOverview filter bar.
     *
     * @returns {Object} Object with range key and optional custom date strings.
     */
    function getDateRange() {
        var $sharedRange = $('#mainwp-module-log-filter-ranges');
        if ($sharedRange.length > 0) {
            var sharedRangeVal = $sharedRange.dropdown('get value') || 'thismonth';
            var sharedDateFrom = $('#mainwp-module-log-filter-dtsstart input[type=text]').val() || '';
            var sharedDateTo = $('#mainwp-module-log-filter-dtsstop input[type=text]').val() || '';

            // When explicit dates are populated (preset onChange or custom entry),
            // send as 'custom' so the server uses those exact dates rather than
            // recomputing from the symbolic key with potentially different rules.
            if (sharedDateFrom) {
                return { range: 'custom', dateFromStr: sharedDateFrom, dateToStr: sharedDateTo };
            }
            return { range: sharedRangeVal, dateFromStr: '', dateToStr: '' };
        }

        return { range: 'thismonth', dateFromStr: '', dateToStr: '' };
    }

    /**
     * Get user filter value from the shared InsightsOverview filter bar.
     *
     * The shared filter element is a Fomantic UI dropdown `<div>`, so `.val()`
     * on the wrapper returns undefined. Use the Fomantic `.dropdown('get value')`
     * API to read the actual selection from the hidden input.
     *
     * @returns {string} Comma-separated user IDs, 'allusers', or empty string.
     */
    function getUserFilter() {
        var $filterUsers = $('#mainwp-module-log-filter-users');
        if ($filterUsers.length > 0) {
            // Empty shared dropdown = "all users" in Logs; map to allusers sentinel
            // so resolve_user_filter() applies the same scope with permission check.
            return $filterUsers.dropdown('get value') || 'allusers';
        }

        return '';
    }

    /**
     * Format duration in milliseconds to readable string.
     *
     * @param {number} ms Duration in milliseconds.
     * @returns {string} Formatted duration.
     */
    function formatDuration(ms) {
        if (!ms || ms === 0) return '--';
        if (ms < 1000) return ms + 'ms';
        var totalSeconds = ms / 1000;
        if (totalSeconds < 60) return totalSeconds.toFixed(2) + 's';
        var totalMinutes = Math.floor(totalSeconds / 60);
        var remainSeconds = Math.floor(totalSeconds % 60);
        if (totalMinutes < 60) return totalMinutes + 'm ' + remainSeconds + 's';
        var hours = Math.floor(totalMinutes / 60);
        var remainMinutes = totalMinutes % 60;
        return hours + 'h ' + remainMinutes + 'm';
    }

    /**
     * Format status with icon.
     *
     * @param {string} status Status value.
     * @returns {string} HTML with icon and status text.
     */
    function formatStatus(status) {
        const strings = mainwpAssistantInsights.strings;
        switch (status) {
            case 'started':
                return '<span class="status-started"><i class="comment alternate icon"></i> ' + strings.started + '</span>';
            case 'executed':
                return '<span class="status-executed"><i class="check circle icon"></i> ' + strings.executed + '</span>';
            case 'failed':
                return '<span class="status-failed"><i class="times circle icon"></i> ' + strings.failed + '</span>';
            case 'confirmed':
                return '<span class="status-confirmed"><i class="check double icon"></i> ' + strings.confirmed + '</span>';
            default:
                return escapeHtml(status);
        }
    }

    /**
     * Load summary metrics via AJAX.
     */
    function loadSummaryMetrics() {
        const dateRange = getDateRange();

        $.ajax({
            url: mainwpAssistantInsights.ajaxurl,
            type: 'POST',
            data: {
                action: 'mainwp_assistant_insights_summary',
                security: mainwpAssistantInsights.nonce,
                user_ids: getUserFilter(),
                range: dateRange.range,
                date_from_str: dateRange.dateFromStr,
                date_to_str: dateRange.dateToStr
            },
            success: function (response) {
                $('#assistant-summary-error').hide();
                if (response.success && response.data) {
                    $('#assistant-total-executions').text(response.data.total_executions || 0);
                    $('#assistant-failed-count').text(response.data.failed_count || 0);
                    $('#assistant-confirmed-count').text(response.data.confirmed_count || 0);
                    $('#assistant-conversation-count').text(response.data.conversation_count || 0);
                } else {
                    $('#assistant-total-executions, #assistant-failed-count, #assistant-confirmed-count, #assistant-conversation-count').text('0');
                }
            },
            error: function () {
                // A failed request is not an idle assistant, so report the failure
                // instead of leaving the loading placeholders in place.
                $('#assistant-summary-error').show();
            }
        });
    }

    /**
     * Initialize activity table as a DataTable with server-side processing.
     */
    function initActivityTable() {
        const strings = mainwpAssistantInsights.strings;

        // Column definitions, conditional on user permission.
        const columns = [
            {
                data: 'time_formatted',
                title: strings.time || 'Time',
                render: function (data) {
                    return $('<span>').text(data || '--').html();
                }
            }
        ];

        // Only show User column for users who can view all users' data.
        if (mainwpAssistantInsights.canViewAllUsers) {
            columns.push({
                data: 'user_label',
                title: strings.user || 'User',
                render: function (data, type, row) {
                    return $('<span>').text(data || row.user_id || '--').html();
                }
            });
        }

        columns.push(
            {
                data: 'ability_label',
                title: strings.ability || 'Ability',
                orderable: false,
                render: function (data) {
                    return $('<span>').text(data || '--').html();
                }
            },
            {
                data: 'status',
                title: strings.status || 'Status',
                render: function (data) {
                    return formatStatus(data);
                }
            },
            {
                data: 'duration',
                title: strings.duration || 'Duration',
                orderable: false,
                render: function (data, type, row) {
                    // Conversation starts and chat failures carry the PHP request time, not a job duration.
                    return row.status === 'started' || ! row.ability ? '—' : formatDuration(data);
                }
            },
            {
                data: null,
                title: strings.details || 'Details',
                orderable: false,
                render: function (data, type, row) {
                    if (row.conversation_id && row.conversation_id > 0 && row.can_view_conversation) {
                        return '<a href="#" class="view-conversation-link" data-conversation-id="'
                            + row.conversation_id + '"><i class="comment icon"></i> #'
                            + row.conversation_id + '</a>';
                    }
                    return '--';
                }
            }
        );

        config.activityTable = $('#assistant-activity-table').DataTable({
            serverSide: true,
            processing: true,
            ajax: {
                url: mainwpAssistantInsights.ajaxurl,
                type: 'POST',
                data: function (d) {
                    var activityDateRange = getDateRange();
                    d.action = 'mainwp_assistant_insights_activity';
                    d.security = mainwpAssistantInsights.nonce;
                    d.user_ids = getUserFilter();
                    d.range = activityDateRange.range;
                    d.date_from_str = activityDateRange.dateFromStr;
                    d.date_to_str = activityDateRange.dateToStr;
                    return d;
                }
            },
            columns: columns,
            order: [[0, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            searching: true,
            searchDelay: 350,
            pagingType: 'full_numbers',
            stateSave: true,
            stateDuration: 60 * 60 * 24 * 30,
            stateLoadParams: function (settings, data) {
                // Invalidate saved state when column layout changes due to permission change.
                if (data.columns && data.columns.length !== columns.length) {
                    return false;
                }
            },
            responsive: true,
            scrollX: true,
            columnDefs: [{
                targets: 'no-sort',
                orderable: false
            }],
            language: {
                emptyTable: $('#assistant-activity-no-data').html(),
                loadingRecords: strings.loading,
                processing: '<div class="ui active inline loader small"></div>',
                zeroRecords: strings.noData
            },
            drawCallback: function () {
                if (typeof mainwp_datatable_fix_menu_overflow === 'function') {
                    mainwp_datatable_fix_menu_overflow('#assistant-activity-table');
                }
            }
        });
    }

    /**
     * Load top abilities chart.
     */
    function loadTopAbilitiesChart() {
        const dateRange = getDateRange();

        $.ajax({
            url: mainwpAssistantInsights.ajaxurl,
            type: 'POST',
            data: {
                action: 'mainwp_assistant_insights_top_abilities',
                security: mainwpAssistantInsights.nonce,
                user_ids: getUserFilter(),
                range: dateRange.range,
                date_from_str: dateRange.dateFromStr,
                date_to_str: dateRange.dateToStr
            },
            success: function (response) {
                $('#assistant-top-abilities-error').hide();
                if (response.success && response.data && response.data.length > 0) {
                    $('#assistant-top-abilities-no-data').hide();
                    $('#assistant-top-abilities-chart').show();
                    renderTopAbilitiesChart(response.data);
                } else {
                    $('#assistant-top-abilities-chart').hide();
                    $('#assistant-top-abilities-no-data').show();
                    if (config.topAbilitiesChart) {
                        config.topAbilitiesChart.destroy();
                        config.topAbilitiesChart = null;
                    }
                }
            },
            error: function () {
                // Distinguish a failed request from a genuinely empty period.
                $('#assistant-top-abilities-chart').hide();
                $('#assistant-top-abilities-no-data').hide();
                $('#assistant-top-abilities-error').show();
            }
        });
    }

    /**
     * Render top abilities horizontal bar chart using ApexCharts.
     *
     * @param {Array} data Abilities data.
     */
    function renderTopAbilitiesChart(data) {
        const el = document.querySelector('#assistant-top-abilities-chart');
        if (!el) return;

        // Destroy existing chart.
        if (config.topAbilitiesChart) {
            config.topAbilitiesChart.destroy();
            config.topAbilitiesChart = null;
        }

        const options = {
            chart: {
                type: 'bar',
                height: 300
            },
            plotOptions: {
                bar: {
                    horizontal: true
                }
            },
            legend: {
                show: false
            },
            tooltip: {
                theme: 'dark'
            },
            series: [{
                name: mainwpAssistantInsights.strings.topAbilities,
                data: data.map(function (item) {
                    return { x: item.label, y: item.count };
                })
            }],
            colors: ['#7fb100'],
            xaxis: {
                labels: {
                    style: { colors: '#999' }
                }
            },
            yaxis: {
                labels: {
                    style: { colors: '#999' }
                }
            }
        };

        config.topAbilitiesChart = new ApexCharts(el, options);
        // The deferred render is carried over from the logs module widgets
        // (modules/logs/widgets/), which use the same 1s delay. The dependency it waits
        // on was never written down, so treat it as a workaround, not a measured value.
        setTimeout(function () {
            config.topAbilitiesChart.render();
        }, 1000);
    }

    /**
     * Load provider usage chart.
     */
    function loadProviderUsageChart() {
        const dateRange = getDateRange();

        $.ajax({
            url: mainwpAssistantInsights.ajaxurl,
            type: 'POST',
            data: {
                action: 'mainwp_assistant_insights_provider_usage',
                security: mainwpAssistantInsights.nonce,
                user_ids: getUserFilter(),
                range: dateRange.range,
                date_from_str: dateRange.dateFromStr,
                date_to_str: dateRange.dateToStr
            },
            success: function (response) {
                $('#assistant-provider-usage-error').hide();
                if (response.success && response.data && response.data.length > 0) {
                    $('#assistant-provider-usage-no-data').hide();
                    $('#mainwp-assistant-chart-provider-usage').show();
                    renderProviderUsageChart(response.data);
                } else {
                    $('#mainwp-assistant-chart-provider-usage').hide();
                    $('#assistant-provider-usage-no-data').show();
                    if (config.providerUsageChart) {
                        config.providerUsageChart.destroy();
                        config.providerUsageChart = null;
                    }
                }
            },
            error: function () {
                // Distinguish a failed request from a genuinely empty period.
                $('#mainwp-assistant-chart-provider-usage').hide();
                $('#assistant-provider-usage-no-data').hide();
                $('#assistant-provider-usage-error').show();
            }
        });
    }

    /**
     * Render provider usage pie chart using ApexCharts.
     *
     * @param {Array} data Provider data.
     */
    function renderProviderUsageChart(data) {
        const el = document.querySelector('#mainwp-assistant-chart-provider-usage');
        if (!el) return;

        // Destroy existing chart.
        if (config.providerUsageChart) {
            config.providerUsageChart.destroy();
            config.providerUsageChart = null;
        }

        const labels = data.map(function (item) {
            return item.label + ' (' + item.percentage + '%)';
        });
        const counts = data.map(function (item) {
            return item.count;
        });

        // Provider colors: official brand palette only.
        const colors = [
            '#4682b4', // Blue.
            '#7fb100', // Green.
            '#FFD300', // Yellow.
            '#AD0000', // Red.
            '#2d3b44', // Slate.
            '#446200', // Dark green.
            '#dcdee0', // Light gray.
            '#1c1d1b'  // Charcoal.
        ];

        const options = {
            chart: {
                type: 'pie',
                height: 350
            },
            legend: {
                labels: {
                    colors: '#999'
                }
            },
            stroke: {
                width: 0
            },
            tooltip: {
                theme: 'dark'
            },
            series: counts,
            labels: labels,
            colors: colors.slice(0, data.length)
        };

        config.providerUsageChart = new ApexCharts(el, options);
        // Same deferred render as the abilities chart above.
        setTimeout(function () {
            config.providerUsageChart.render();
        }, 1000);
    }

    /**
     * Export activity to CSV.
     */
    function exportActivityCSV() {
        const dateRange = getDateRange();
        const search = config.activityTable ? config.activityTable.search() : '';

        // Create form and submit to trigger download.
        const form = $('<form>', {
            action: mainwpAssistantInsights.ajaxurl,
            method: 'POST'
        });

        form.append($('<input>', { type: 'hidden', name: 'action', value: 'mainwp_assistant_insights_export' }));
        form.append($('<input>', { type: 'hidden', name: 'security', value: mainwpAssistantInsights.nonce }));
        form.append($('<input>', { type: 'hidden', name: 'user_ids', value: getUserFilter() }));
        form.append($('<input>', { type: 'hidden', name: 'range', value: dateRange.range }));
        form.append($('<input>', { type: 'hidden', name: 'date_from_str', value: dateRange.dateFromStr }));
        form.append($('<input>', { type: 'hidden', name: 'date_to_str', value: dateRange.dateToStr }));
        form.append($('<input>', { type: 'hidden', name: 'search', value: search }));

        $('body').append(form);
        form.submit();
        form.remove();
    }

    /**
     * Show conversation details in modal.
     *
     * @param {number} conversationId Conversation ID.
     */
    function showConversationModal(conversationId) {
        const modal = $('#assistant-conversation-modal');
        const content = $('#assistant-conversation-content');

        content.html('<div class="ui active centered inline loader"></div>');
        modal.modal('show');

        $.ajax({
            url: mainwpAssistantInsights.ajaxurl,
            type: 'POST',
            data: {
                action: 'mainwp_assistant_insights_conversation',
                security: mainwpAssistantInsights.nonce,
                conversation_id: conversationId,
                user_ids: getUserFilter()
            },
            success: function (response) {
                if (response.success && response.data) {
                    renderConversationContent(response.data);
                } else {
                    content.html('<div class="ui error message"><p></p></div>').find('p').text((response.data && response.data.message) || mainwpAssistantInsights.strings.convLoadError);
                }
            },
            error: function () {
                content.html('<div class="ui error message"><p></p></div>').find('p').text(mainwpAssistantInsights.strings.convLoadError);
            }
        });
    }

    /**
     * Translate a stored message role.
     *
     * Roles are database slugs ('user', 'assistant'); capitalising the slug
     * ships untranslated English, so prefer the localized labels.
     *
     * @param {string} role The stored role slug.
     * @return {string} A display label for the role.
     */
    function getRoleLabel(role) {
        const s = (typeof mainwpAssistantInsights !== 'undefined' && mainwpAssistantInsights.strings) || {};
        if ('user' === role) {
            return s.roleUser || s.user || 'User';
        }
        if ('assistant' === role) {
            return s.roleAssistant || 'Assistant';
        }
        return String(role).charAt(0).toUpperCase() + String(role).slice(1);
    }

    /**
     * Render conversation content in modal.
     *
     * @param {Object} data Conversation data.
     */
    function renderConversationContent(data) {
        const content = $('#assistant-conversation-content');
        let html = '';

        if (data.conversation) {
            const createdTime = data.conversation.created_at_formatted || '';
            html += '<div class="ui segment">';
            const untitled = (mainwpAssistantInsights.strings.conversationFallback || 'Conversation #%d').replace('%d', data.conversation.id);
            html += '<h4 class="ui small header">' + escapeHtml(data.conversation.title || untitled) + '</h4>';
            html += '<p><small>' + escapeHtml(mainwpAssistantInsights.strings.createdLabel) + ' ' + escapeHtml(createdTime) + '</small></p>';
            html += '</div>';
        }

        if (data.messages && data.messages.length > 0) {
            data.messages.forEach(function (msg) {
                const roleClass = msg.role === 'user' ? 'user' : 'assistant';
                const roleLabel = getRoleLabel(msg.role);
                const msgTime = msg.created_at_formatted || '';

                html += '<div class="conversation-message ' + roleClass + '">';
                html += '<div class="role">' + escapeHtml(roleLabel) + '</div>';
                html += '<div class="content-text">' + escapeHtml(msg.content) + '</div>';
                html += '<div class="time"><small>' + escapeHtml(msgTime) + '</small></div>';
                html += '</div>';
            });
        } else {
            html += '<div class="ui info message"><p>' + escapeHtml(mainwpAssistantInsights.strings.convNoMsgs) + '</p></div>';
        }

        if (data.abilities && data.abilities.length > 0) {
            const s = mainwpAssistantInsights.strings;
            html += '<div class="ui segment" style="margin-top: 1em;">';
            html += '<h4 class="ui small header"><i class="cog icon"></i> ' + escapeHtml(s.abilitiesUsed) + '</h4>';
            html += '<table class="ui very basic compact table">';
            html += '<thead><tr><th scope="col">' + escapeHtml(s.ability) + '</th><th scope="col">' + escapeHtml(s.status) + '</th><th scope="col">' + escapeHtml(s.duration) + '</th><th scope="col">' + escapeHtml(s.time) + '</th></tr></thead>';
            html += '<tbody>';
            data.abilities.forEach(function (ab) {
                html += '<tr>';
                html += '<td>' + escapeHtml(ab.ability_label) + '</td>';
                html += '<td>' + formatStatus(ab.status) + '</td>';
                html += '<td>' + escapeHtml(ab.duration_formatted) + '</td>';
                html += '<td>' + escapeHtml(ab.created_at_formatted) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '</div>';
        }

        content.html(html);
    }

    /**
     * Escape HTML to prevent XSS.
     *
     * @param {string} text Text to escape.
     * @returns {string} Escaped text.
     */
    function escapeHtml( text ) {
        if ( text == null ) return ''; // eslint-disable-line eqeqeq -- intentional: catch null & undefined, preserve 0.
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String( text ).replace( /[&<>"']/g, function( m ) { return map[ m ]; } );
    }

    /**
     * Initialize the dashboard on document ready.
     */
    $(document).ready(function () {
        // Load summary metrics on page load.
        if ($('#assistant-summary-cards').length > 0) {
            loadSummaryMetrics();
        }

        // Initialize modal if present.
        if ($('#assistant-conversation-modal').length > 0) {
            $('#assistant-conversation-modal').modal();
        }

        // Initialize DataTable if present.
        if ($('#assistant-activity-table').length > 0) {
            initActivityTable();
        }

        // Load charts if their containers exist.
        if ($('#assistant-top-abilities-chart').length > 0) {
            loadTopAbilitiesChart();
        }
        if ($('#mainwp-assistant-chart-provider-usage').length > 0) {
            loadProviderUsageChart();
        }

        // Export button.
        $('#assistant-export-btn').on('click', function () {
            exportActivityCSV();
        });

        // Conversation link handler (delegated).
        $(document).on('click', '.view-conversation-link', function (e) {
            e.preventDefault();
            const conversationId = $(this).data('conversation-id');
            if (conversationId) {
                showConversationModal(conversationId);
            }
        });

        $('#assistant-close-conversation-modal').on('click', function () {
            $('#assistant-conversation-modal').modal('hide');
        });
    });

})(jQuery);
