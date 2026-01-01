let bulk_RestAPIMaxThreads = 1;
let bulk_RestAPICurrentThreads = 0;
let bulk_RestAPITotal = 0;
let bulk_RestAPIFinished = 0;
let bulk_RestAPITaskRunning = false;

jQuery(function ($) {
    $("body").on("click", ".copy-to-clipboard", function () {
        $("#mainwp-api-key-copied-confirm-modal").modal("show");
    });

    // Trigger Manage Bulk Actions
    jQuery(document).on(
        "click",
        "#mainwp-do-rest-api-bulk-actions",
        function () {
            let action = jQuery("#mainwp-rest-api-bulk-actions-menu").dropdown(
                "get value"
            );
            if (action == "delete" && !bulk_RestAPITaskRunning) {
                mainwp_restapi_bulk_remove_keys_confirm();
            }
            return false;
        }
    );

    // Initialize Application Passwords functionality
    if ($("#rest-application-passwords-settings").length > 0) {
        init_application_passwords($);
    }
});

let mainwp_restapi_remove_key_confirm = function (pCheckedBox) {
    let confirmMsg = __("You are about to delete the selected REST API Key?");
    mainwp_confirm(confirmMsg, function () {
        mainwp_restapi_bulk_remove_specific(pCheckedBox);
    });
};

let mainwp_restapi_bulk_remove_keys_confirm = function () {
    let confirmMsg = __(
        "You are about to delete the selected REST API Key(s)?"
    );
    mainwp_confirm(confirmMsg, function () {
        mainwp_restapi_bulk_init();
        mainwp_restapi_remove_keys_next();
    });
};

let mainwp_restapi_bulk_init = function () {
    jQuery("#mainwp-message-zone-apikeys").hide();
    if (!bulk_RestAPITaskRunning) {
        bulk_RestAPICurrentThreads = 0;
        bulk_RestAPITotal = 0;
        bulk_RestAPIFinished = 0;
        jQuery(
            ".mainwp-rest-api-body-table-manage .check-column INPUT:checkbox"
        ).each(function () {
            jQuery(this).attr("status", "queue");
        });
    }
};

let mainwp_restapi_remove_keys_next = function () {
    while (
        (checkedBox = jQuery(
            '.mainwp-rest-api-body-table-manage .check-column INPUT:checkbox:checked[status="queue"]:first'
        )) &&
        checkedBox.length > 0 &&
        bulk_RestAPICurrentThreads < bulk_RestAPIMaxThreads
    ) {
        // NOSONAR - variables modified in other functions.
        mainwp_restapi_bulk_remove_specific(checkedBox);
    }
    if (bulk_RestAPITotal > 0 && bulk_RestAPIFinished == bulk_RestAPITotal) {
        // NOSONAR - modified outside the function.
        setHtml(
            "#mainwp-message-zone-apikeys",
            __("Process completed. Reloading page...")
        );
        setTimeout(function () {
            mainwp_forceReload();
        }, 3000);
    }
};

let mainwp_restapi_bulk_remove_specific = function (pCheckedBox) {
    pCheckedBox.attr("status", "running");
    let rowObj = pCheckedBox.closest("tr");
    bulk_RestAPICurrentThreads++;

    let id = rowObj.attr("key-ck-id");

    rowObj.html(
        '<td colspan="999"><i class="notched circle loading icon"></i> ' +
            "Deleting ..." +
            "</td>"
    );

    let data = mainwp_secure_data({
        action: "mainwp_rest_api_remove_keys",
        keyId: id,
        api_ver:
            rowObj.closest("tbody").attr("id") ===
            "mainwp-rest-api-v2-body-table"
                ? "v2"
                : "v1",
    });
    jQuery.post(
        ajaxurl,
        data,
        function (response) {
            bulk_RestAPICurrentThreads--;
            bulk_RestAPIFinished++;
            rowObj.html('<td colspan="999"></td>');
            let result = "";
            let error = "";
            if (response.error != undefined) {
                error = response.error;
            } else if (response.success == "SUCCESS") {
                result = __("The REST API Key has been deleted.");
            }
            if (error != "") {
                rowObj.html(
                    '<td colspan="999"><i class="red times icon"></i>' +
                        error +
                        "</td>"
                );
            } else {
                rowObj.html(
                    '<td colspan="999"><i class="green check icon"></i>' +
                        result +
                        "</td>"
                );
            }
            setTimeout(function () {
                jQuery("tr[key-ck-id=" + id + "]").fadeOut(1000);
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
    const create_button = $("#mainwp-create-application-password-button");
    const create_modal = $("#mainwp-create-application-password-modal");
    const success_modal = $("#mainwp-application-password-success-modal");
    const app_pass_name_input = $("#mainwp-app-password-name-input");
    const app_pass_tbody = $("#mainwp-application-password-table-body");
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
        return text.replace(/[&<>"']/g, (m) => map[m]);
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
        }, 5000);
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

        // Get DataTable instance
        const table = window.mainwp_app_passwords_table; // NOSONAR - noopener - global variable.

        if (!table) {
            console.error("DataTable not initialized");
            return;
        }

        // Create row HTML with proper data-order attributes
        const row_html = `<tr data-uuid="${item.uuid}">
            <td class="check-column">
                <div class="ui checkbox">
                    <input type="checkbox" value="${item.uuid}" name=""/>
                </div>
            </td>
            <td>${escape_html(item.name)}</td>
            <td data-order="${item.created}">${created_str}</td>
            <td data-order="0">&mdash;</td>
            <td>&mdash;</td>
            <td class="right aligned">
                <button type="button" class="ui mini red button mainwp-revoke-application-password" data-uuid="${
                    item.uuid
                }">
                    ${__("Revoke")}
                </button>
            </td>
        </tr>`;

        // Add row using DataTables API with DOM node
        const row_node = table.row.add($(row_html)[0]).draw(false).node();

        // Initialize Semantic UI checkbox
        $(row_node).find(".ui.checkbox").checkbox();
    };

    /**
     * Helper function to revoke multiple passwords
     */
    const revoke_multiple_passwords = (uuids) => {
        const data = mainwp_secure_data({
            action: "mainwp_application_password_delete_multiple",
            uuids: uuids,
        });

        $.post(
            ajaxurl,
            data,
            (response) => {
                if (response.success) {
                    const table = window.mainwp_app_passwords_table; // NOSONAR - noopener - global variable.

                    if (table) {
                        // Remove all selected rows using DataTables API
                        uuids.forEach((uuid) => {
                            const row = table.row(`[data-uuid="${uuid}"]`);
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
    create_button.on("click", () => {
        app_pass_name_input.val("");
        create_modal
            .modal({
                onApprove: () => {
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
                                // Add new row to table after modal is closed
                                add_password_row(response.data.item);
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
        input.select();
        document.execCommand("copy");

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

        clear_messages();
        button.prop("disabled", true).addClass("disabled loading");

        const data = mainwp_secure_data({
            action: "mainwp_application_password_delete",
            uuid: uuid,
        });

        $.post(
            ajaxurl,
            data,
            (response) => {
                button.prop("disabled", false).removeClass("disabled loading");

                if (response.success) {
                    const table = window.mainwp_app_passwords_table;

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
     * Bulk actions
     */
    $("#mainwp-do-application-passwords-bulk-actions").on("click", () => {
        const action = $(
            "#mainwp-application-passwords-bulk-actions-menu"
        ).dropdown("get value");

        if (!action) {
            return false;
        }

        const selected = [];
        app_pass_tbody
            .find('.check-column input[type="checkbox"]:checked')
            .each(function () {
                const uuid = $(this).val();
                if (uuid) {
                    selected.push(uuid);
                }
            });

        if (selected.length === 0) {
            show_message(
                __("Please select at least one application password."),
                "error"
            );
            return false;
        }

        if (action === "delete") {
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
        }

        return false;
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
