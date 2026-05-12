let bulk_RestAPIMaxThreads = 1;
let bulk_RestAPICurrentThreads = 0;
let bulk_RestAPITotal = 0;
let bulk_RestAPIFinished = 0;
let bulk_RestAPITaskRunning = false;

jQuery(function ($) {
    $('body').on('click', '.copy-to-clipboard', function () {
        $('#mainwp-api-key-copied-confirm-modal').modal('show');
    });
    // Trigger Manage Bulk Actions
    jQuery(document).on('click', '#mainwp-do-rest-api-bulk-actions', function () {
        let action = jQuery("#mainwp-rest-api-bulk-actions-menu").dropdown("get value");
        if (action == 'delete' && !bulk_RestAPITaskRunning) {
            mainwp_restapi_bulk_remove_keys_confirm();
        }
        return false;
    });

    // Initialize Application Passwords functionality
    if ($("#rest-application-passwords-settings").length > 0) {
        init_application_passwords($);
    }
});

let mainwp_restapi_remove_key_confirm = function (pCheckedBox) {
    let confirmMsg = __("You are about to delete the selected REST API Key?");
    mainwp_confirm(confirmMsg, function () { mainwp_restapi_bulk_remove_specific(pCheckedBox); });
}

let mainwp_restapi_bulk_remove_keys_confirm = function () {
    let confirmMsg = __("You are about to delete the selected REST API Key(s)?");
    mainwp_confirm(confirmMsg, function () { mainwp_restapi_bulk_init(); mainwp_restapi_remove_keys_next(); });
}

let mainwp_restapi_bulk_init = function () {
    jQuery('#mainwp-message-zone-apikeys').hide();
    if (!bulk_RestAPITaskRunning) {
        bulk_RestAPICurrentThreads = 0;
        bulk_RestAPITotal = 0;
        bulk_RestAPIFinished = 0;
        jQuery('.mainwp-rest-api-body-table-manage .check-column INPUT:checkbox').each(function () {
            jQuery(this).attr('status', 'queue')
        });
    }
};

let mainwp_restapi_remove_keys_next = function () {
    while ((checkedBox = jQuery('.mainwp-rest-api-body-table-manage .check-column INPUT:checkbox:checked[status="queue"]:first')) && (checkedBox.length > 0) && (bulk_RestAPICurrentThreads < bulk_RestAPIMaxThreads)) { // NOSONAR - variables modified in other functions.
        mainwp_restapi_bulk_remove_specific(checkedBox);
    }
    if ((bulk_RestAPITotal > 0) && (bulk_RestAPIFinished == bulk_RestAPITotal)) { // NOSONAR - modified outside the function.
        setHtml('#mainwp-message-zone-apikeys', __("Process completed. Reloading page..."));
        setTimeout(function () {
            mainwp_forceReload();
        }, 3000);
    }
}

let mainwp_restapi_bulk_remove_specific = function (pCheckedBox) {
    pCheckedBox.attr('status', 'running');
    let rowObj = pCheckedBox.closest('tr');
    bulk_RestAPICurrentThreads++;

    let id = rowObj.attr('key-ck-id');
    rowObj.html('<td colspan="999"><i class="notched circle loading icon"></i> ' + 'Deleting ...' + '</td>');
    let data = mainwp_secure_data({
        action: 'mainwp_rest_api_remove_keys',
        keyId: id,
        api_ver: rowObj.closest('tbody').attr('id') === 'mainwp-rest-api-v2-body-table' ? 'v2' : 'v1'
    });

    jQuery.post(ajaxurl, data, function (response) {
        bulk_RestAPICurrentThreads--;
        bulk_RestAPIFinished++;
        rowObj.html('<td colspan="999"></td>');
        let result = '';
        let error = '';
        if (response.error != undefined) {
            error = response.error;
        } else if (response.success == 'SUCCESS') {
            result = __('The REST API Key has been deleted.');
        }
        if (error != '') {
            rowObj.html('<td colspan="999"><i class="red times icon"></i>' + error + '</td>');
        } else {
            rowObj.html('<td colspan="999"><i class="green check icon"></i>' + result + '</td>');
        }
        setTimeout(function () {
            jQuery('tr[key-ck-id=' + id + ']').fadeOut(1000);
        }, 3000);

        mainwp_restapi_remove_keys_next();
    },
        "json"
    );
};

/**
 * Application Passwords functionality
 */
const init_application_passwords = ($) => {
    const app_pass_section = $("#rest-application-passwords-settings");
    const create_modal = $("#mainwp-create-application-password-modal");
    const success_modal = $("#mainwp-application-password-success-modal");
    const app_pass_name_input = $("#mainwp-app-password-name-input");
    const app_pass_tbody = $("#mainwp-application-password-table-body");
    const edit_modal = $("#mainwp-edit-application-password-modal");
    const edit_name_input = $("#mainwp-app-password-edit-name-input");
    const edit_uuid_input = $("#mainwp-app-password-edit-uuid");
    const edit_user_id_input = $("#mainwp-app-password-edit-user-id");
    const has_user_col = $('#mainwp-application-password-table thead th.mainwp-col-user').length > 0;
    const current_user_name = $('#rest-application-passwords-settings').data('current-user-name') || '';
    const message_zone = $("#mainwp-message-zone-app-passwords");

    /**
     * Helper function to escape HTML
     */
    const escape_html = (text) => {
        const map = {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;",
        };
        return text.replace(/[&<>"']/g, (m) => map[m]); // NOSONAR  ok.
    };

    /**
     * Helper function to clear messages
     */
    const clear_messages = () => {
        message_zone.hide().html("");
    };

    /**
     * Helper function to show messages
     */
    const show_message = (message, type) => {
        clear_messages();

        const class_name = type === "error" ? "red" : "green";
        const html = `<div class="ui ${class_name} message">
            <i class="close icon"></i>
            ${message}
        </div>`;

        message_zone.html(html).show();
        message_zone.find(".close.icon").on("click", () => {
            message_zone.fadeOut();
        });

        setTimeout(() => {
            message_zone.fadeOut();
        }, 3000);
    };

    /**
     * Helper function to format date like PHP format_timestamp
     * Format: "January 1, 2026 10:15 am"
     */
    const format_timestamp = (timestamp) => {
        const date = new Date(timestamp * 1000);

        // Get month name
        const month_names = [
            "January",
            "February",
            "March",
            "April",
            "May",
            "June",
            "July",
            "August",
            "September",
            "October",
            "November",
            "December",
        ];
        const month = month_names[date.getMonth()];

        // Get day, year
        const day = date.getDate();
        const year = date.getFullYear();

        // Get hours and minutes
        let hours = date.getHours();
        const minutes = date.getMinutes().toString().padStart(2, "0");
        const ampm = hours >= 12 ? "pm" : "am";
        hours = hours % 12;
        hours = hours ? hours : 12; // NOSONAR - noopener - hours 0 should be 12.

        return `${month} ${day}, ${year} ${hours}:${minutes} ${ampm}`;
    };

    /**
     * Helper function to add password row to table
     */
    const add_password_row = (item) => {
        const created_str = format_timestamp(item.created);
        const table_el = $('#mainwp-application-password-table');
        // Get DataTable instance
        const table = window.mainwp_app_passwords_table; // NOSONAR - noopener - global variable.

        if (!table) {
            console.error("DataTable not initialized");
            return;
        }

        // Check manage capability from table data attribute
        const can_manage_table = table_el.data('can-manage') == 1;
        const can_edit_table = table_el.data('can-edit') == 1;

        // Create row HTML with proper data-order attributes
        const row_html = `<tr data-uuid="${item.uuid}" data-user-id="${item.user_id || ''}">
            <td class="check-column">
                <div class="ui checkbox">
                    <input type="checkbox" value="${item.uuid}" name="" ${can_manage_table ? '' : 'disabled'} />
                </div>
            </td>
            <td>${escape_html(item.name)}</td>
            ${has_user_col ? `<td>${escape_html(current_user_name)}</td>` : ''}
            <td data-order="${item.created}">${created_str}</td>
            <td data-order="0">&mdash;</td>
            <td>&mdash;</td>
            <td class="right aligned">
                ${can_edit_table ? `<button type="button" class="ui mini basic button mainwp-edit-application-password" data-uuid="${item.uuid}" data-user-id="${item.user_id || ''}" data-name="${escape_html(item.name)}">${__("Edit")}</button>` : ''}
                ${can_manage_table ? `<button type="button" class="ui mini grey basic button mainwp-revoke-application-password" data-uuid="${item.uuid}">${__("Revoke")}</button>` : ''}
            </td>
        </tr>`;

        // Add row using DataTables API with DOM node
        const row_node = table.row.add($(row_html)[0]).draw(false).node();

        // Initialize Semantic UI checkbox
        $(row_node).find(".ui.checkbox").checkbox();
    };

    /**
     * Open edit modal
     */
    app_pass_tbody.on('click', '.mainwp-edit-application-password', (e) => {
        e.preventDefault();
        const btn = $(e.currentTarget);
        const uuid = btn.data('uuid');
        const user_id = btn.data('user-id');
        const name = btn.data('name');

        edit_uuid_input.val(uuid);
        edit_user_id_input.val(user_id);
        edit_name_input.val(name);

        edit_modal.modal('show');
    });

    /**
     * Submit edit application password
     */
    $('#mainwp-edit-app-password-submit').on('click', (e) => {
        e.preventDefault();
        const uuid = edit_uuid_input.val();
        const user_id = edit_user_id_input.val();
        const name = (edit_name_input.val() || '').trim();
        if (!name) {
            edit_name_input.trigger('focus');
            show_message(__('Please enter an application name.'), 'error');
            return false;
        }

        const data = mainwp_secure_data({
            action: 'mainwp_application_password_update',
            uuid: uuid,
            user_id: user_id,
            name: name,
        });

        $.post(ajaxurl, data, (resp) => { // NOSONAR - noopener - complex.
            if (resp?.success) {
                try {
                    const table = window.mainwp_app_passwords_table; // NOSONAR - noopener - global variable.
                    const tr = $('#mainwp-application-password-table tbody tr[data-uuid="' + uuid + '"]');
                    if (tr.length) {
                        // Update name cell
                        const name_cell = tr.find('td').eq(1);
                        name_cell.text(name);
                        // Update buttons aria/data
                        const edit_btn = tr.find('.mainwp-edit-application-password');
                        if (edit_btn.length) {
                            edit_btn.attr('data-name', name);
                            edit_btn.attr('aria-label', __('Rename "%s"').replace('%s', name));
                        }
                        const revoke_btn = tr.find('.mainwp-revoke-application-password');
                        if (revoke_btn.length) {
                            revoke_btn.attr('aria-label', __('Revoke "%s"').replace('%s', name));
                        }
                        if (table) {
                            table.row(tr).invalidate().draw(false);
                        }
                    }
                    show_message(__('Application password updated successfully.'), 'success');
                    edit_modal.modal('hide');
                } catch {
                    mainwp_forceReload();
                }
            } else {
                const msg = (resp?.data?.message) ?? __('Failed to update Application Password.');
                show_message(msg, 'error');
            }
        }, 'json');
    });

    /**
     * Helper function to revoke multiple passwords
     */
    const revoke_multiple_passwords = (items) => {
        const data = mainwp_secure_data({
            action: "mainwp_application_password_delete_multiple",
            items: items,
        });

        $.post(
            ajaxurl,
            data,
            (response) => {
                if (response.success) {
                    const table = window.mainwp_app_passwords_table; // NOSONAR - noopener - global variable.

                    if (table) {
                        // Remove all selected rows using DataTables API
                        items.forEach((it) => {
                            const row = table.row(`[data-uuid="${it.uuid}"]`);
                            if (row.length) {
                                row.remove();
                            }
                        });

                        // Redraw table
                        table.draw(false);
                    }

                    show_message(
                        __("Selected application passwords have been revoked."),
                        "success"
                    );
                } else {
                    show_message(
                        response.data.message || __("An error occurred."),
                        "error"
                    );
                }
            },
            "json"
        ).fail(() => {
            show_message(
                __(
                    "An error occurred while revoking the application passwords."
                ),
                "error"
            );
        });
    };

    /**
     * Open create modal
     */
    $("#mainwp-create-application-password-button-top, #mainwp-create-application-password-button").on("click", () => {
        app_pass_name_input.val("");
        const submit_btn = $("#mainwp-create-app-password-submit");
        submit_btn.addClass("disabled");

        app_pass_name_input.off("input.create_modal").on("input.create_modal", () => {
            if (app_pass_name_input.val().trim().length > 0) {
                submit_btn.removeClass("disabled");
            } else {
                submit_btn.addClass("disabled");
            }
        });

        create_modal
            .modal({
                onApprove: () => { // NOSONAR - noopener - prevent close.
                    const name = app_pass_name_input.val().trim();

                    if (name.length === 0) {
                        app_pass_name_input.trigger("focus");
                        show_message(
                            __("Please enter an application name."),
                            "error"
                        );
                        return false;
                    }

                    create_application_password(name);
                    return false; // Prevent modal from closing
                },
            })
            .modal("show");
    });

    /**
     * Create new application password
     */
    const create_application_password = (name) => {
        clear_messages();

        const submit_button = $("#mainwp-create-app-password-submit");
        submit_button.addClass("loading disabled");

        const data = mainwp_secure_data({
            action: "mainwp_application_password_create",
            name: name,
        });

        $.post(
            ajaxurl,
            data,
            (response) => {
                submit_button.removeClass("loading disabled");

                if (response.success) {
                    // Close create modal
                    create_modal.modal("hide");

                    // Show success modal with password
                    $("#app-pass-success-name").text(response.data.item.name);
                    $("#app-pass-success-value").val(response.data.password);

                    success_modal
                        .modal({
                            closable: false,
                            onHidden: () => {
                                location.reload();
                            },
                        })
                        .modal("show");

                    show_message(
                        __("Application password created successfully!"),
                        "success"
                    );
                } else {
                    create_modal.modal("hide");
                    show_message(
                        response.data.message || __("An error occurred."),
                        "error"
                    );
                }
            },
            "json"
        ).fail(() => {
            submit_button.removeClass("loading disabled");
            create_modal.modal("hide");
            show_message(
                __(
                    "An error occurred while creating the application password."
                ),
                "error"
            );
        });
    };

    /**
     * Copy password to clipboard
     */
    $(document).on("click", ".copy-app-password", function () {
        const input = $("#app-pass-success-value");
        const value = input.val();

        if (navigator.clipboard && globalThis.isSecureContext) {
            navigator.clipboard.writeText(value);
        }

        const button = $(this);
        const original_text = button.html();
        button.html('<i class="check icon"></i> ' + __("Copied!"));

        setTimeout(() => {
            button.html(original_text);
        }, 2000);
    });

    /**
     * Revoke single application password
     */
    app_pass_tbody.on("click", ".mainwp-revoke-application-password", (e) => {
        e.preventDefault();

        if (
            !confirm(
                __(
                    "Are you sure you want to revoke this password? This action cannot be undone."
                )
            )
        ) {
            return;
        }

        const button = $(e.currentTarget);
        const tr = button.closest("tr");
        const uuid = button.data("uuid");
        const user_id = button.data("user-id") || button.closest('tr').data('user-id') || '';

        clear_messages();
        button.prop("disabled", true).addClass("disabled loading");

        const data = mainwp_secure_data({
            action: "mainwp_application_password_delete",
            uuid: uuid,
            user_id: user_id,
        });

        $.post(
            ajaxurl,
            data,
            (response) => {
                button.prop("disabled", false).removeClass("disabled loading");

                if (response.success) {
                    const table = window.mainwp_app_passwords_table; // NOSONAR - noopener - global variable.

                    if (table) {
                        // Remove row using DataTables API
                        table.row(tr).remove().draw(false);
                    } else {
                        // Fallback to direct DOM manipulation
                        tr.fadeOut(300, function () {
                            tr.remove();
                        });
                    }

                    show_message(
                        __("Application password revoked successfully."),
                        "success"
                    );
                } else {
                    show_message(
                        response.data.message || __("An error occurred."),
                        "error"
                    );
                }
            },
            "json"
        ).fail(() => {
            button.prop("disabled", false).removeClass("disabled loading");
            show_message(
                __(
                    "An error occurred while revoking the application password."
                ),
                "error"
            );
        });
    });

    /**
     * Select checkboxes and disable/enable bulk actions
     */
    $('.mainwp-application-password-checkbox').on('change', function () {
        const all_checked = $('.mainwp-application-password-checkbox').is(':checked');
        if (all_checked > 0) {
            $("#mainwp-do-application-passwords-bulk-actions").removeClass('disabled');
        } else {
            $("#mainwp-do-application-passwords-bulk-actions").addClass('disabled');
        }
    });

    /**
     * Bulk actions
     */
    $("#mainwp-do-application-passwords-bulk-actions").on("click", (e) => {
        const selected = [];
        app_pass_tbody
            .find('.check-column input[type="checkbox"]:checked')
            .each(function () {
                const uuid = $(this).val();
                const user_id = $(this).data('user-id') || $(this).closest('tr').data('user-id') || '';
                if (uuid) {
                    selected.push({ uuid: uuid, user_id: user_id });
                }
            });

        if (selected.length === 0) {
            show_message(
                __("Please select at least one application password."),
                "error"
            );
            return false;
        }

        if (
            !confirm(
                __(
                    "Are you sure you want to revoke the selected passwords? This action cannot be undone."
                )
            )
        ) {
            return false;
        }

        revoke_multiple_passwords(selected);
        e.preventDefault();
        e.stopPropagation();
        return true;
    });

    /**
     * Close new password notice
     */
    app_pass_section.on(
        "click",
        ".new-application-password-notice .close.icon",
        function () {
            $(this).closest(".new-application-password-notice").fadeOut();
        }
    );

    /**
     * Enter key on name field
     */
    app_pass_name_input.on("keypress", (e) => {
        if (e.which === 13) {
            e.preventDefault();
            create_button.trigger("click");
        }
    });
};

/**
 * Initialize REST API Permission Chips
 */
jQuery(document).ready(function($) { // NOSONAR - complex.
    let initPermissionChips = function() {
        $('.mainwp-rest-api-permission-chips').each(function() {
            let container = $(this);
            let hiddenInput = container.find('input[type="hidden"]');
            let chips = container.find('.mainwp-permission-chip:visible');
            let initValue = container.data('init-value') || '';

            let updateHiddenInput = function() {
                let selectedPermissions = [];
                container.find('.mainwp-permission-chip:visible').each(function() { // NOSONAR - levels deep.
                    if ($(this).hasClass('active')) {
                        let perms = $(this).data('permission').toString().split(',');
                        perms.forEach(function(p) {
                            if (p && !selectedPermissions.includes(p)) {
                                selectedPermissions.push(p);
                            }
                        });
                    }
                });
                hiddenInput.val(selectedPermissions.join(','));
                hiddenInput.trigger('change');
            };

            let initPermissions = new Set(
                initValue
                    .split(',')
                    .filter(function (p) { return p !== ''; })
            );

            chips.each(function() {
                let chip = $(this);
                let chipPerms = chip.data('permission').toString().split(',');
                let isActive = false;

                chipPerms.forEach(function(perm) {
                    if (initPermissions.has(perm)) {
                        isActive = true;
                    }
                });

                if (isActive) {
                    chip.addClass('active green');
                } else {
                    chip.addClass('inactive grey');
                }
            });

            updateHiddenInput();

            chips.off('click.permission-chip').on('click.permission-chip', function(e) {
                e.preventDefault();
                let chip = $(this);

                if (chip.hasClass('active')) {
                    chip.removeClass('active green').addClass('inactive grey');
                } else {
                    chip.removeClass('inactive grey').addClass('active green');
                }

                updateHiddenInput();
            });
        });
    };

    initPermissionChips();

    $('#mainwp_rest_api_keys_compatible_v1').on('change', function() {
        let isV1 = $(this).is(':checked');
        let container = $('.mainwp-rest-api-permission-chips[data-api-version]');

        if (isV1) {
            container.attr('data-api-version', 'v1');
            container.find('.mainwp-v2-chip').hide();
            container.find('.mainwp-v1-chip').show();
        } else {
            container.attr('data-api-version', 'v2');
            container.find('.mainwp-v1-chip').hide();
            container.find('.mainwp-v2-chip').show();
        }

        container.find('.mainwp-permission-chip').removeClass('active');
        initPermissionChips();
    });

    let updateBulkActionsState = function() {
        let checkedCount = $('.mainwp-rest-api-body-table-manage .check-column INPUT:checkbox:checked').length;
        let dropdown = $('#mainwp-rest-api-bulk-actions-menu');
        let applyButton = $('#mainwp-do-rest-api-bulk-actions');

        if (checkedCount > 0) {
            dropdown.removeClass('disabled');
            applyButton.removeClass('disabled');
        } else {
            dropdown.addClass('disabled');
            dropdown.dropdown('clear');
            applyButton.addClass('disabled');
        }
    };

    updateBulkActionsState();

    $(document).on('change', '.mainwp-rest-api-body-table-manage .check-column INPUT:checkbox', function() {
        updateBulkActionsState();
    });
});
