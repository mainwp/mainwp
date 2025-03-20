
/**
 * Bulk upload sites
 */

let import_current = 0;
let import_stop_by_user = false;
let import_total = 0;
let import_count_success = 0;
let import_count_fails = 0;

window.mainwpVars = window.mainwpVars || {};

jQuery(function () {
    import_total = jQuery('#mainwp_managesites_total_import').val();

    jQuery(document).on('click', '#mainwp_managesites_btn_import', function () {
        if (!import_stop_by_user) {
            import_stop_by_user = true;
            jQuery('#mainwp_managesites_import_logging .log').append(__('Paused import by user.') + "\n");
            jQuery('#mainwp_managesites_btn_import').val(__('Continue'));
            jQuery('#mainwp_managesites_btn_save_csv').prop("disabled", false); //Enable
        } else {
            import_stop_by_user = false;
            jQuery('#mainwp_managesites_import_logging .log').append(__('Continue import.') + "\n");
            jQuery('#mainwp_managesites_btn_import').val(__('Pause'));
            jQuery('#mainwp_managesites_btn_save_csv').attr('disabled', 'true'); // Disable
            mainwp_managesites_import_sites();
        }
    });

    jQuery(document).on('click', '#mainwp_managesites_btn_save_csv', function () {
        let fail_data = '';
        jQuery('#mainwp_managesites_import_fail_logging span').each(function () {
            fail_data += jQuery(this).html() + "\r";
        });
        let blob = new Blob([fail_data], { type: "text/plain;charset=utf-8" });
        saveAs(blob, "import_sites_fails.csv");
    });

    if (jQuery('#mainwp_managesites_do_import').val() == 1) {
        mainwp_managesites_import_sites();
    }
});

// eslint-disable-next-line complexity
let mainwp_managesites_import_sites = function () { // NOSONAR - to compatible.
    if (import_stop_by_user)
        return;

    let page_href = jQuery("#mainwp-import-sites-modal").attr('data-page-url');

    jQuery('#mainwp-importing-sites').hide();

    import_current++;

    if (import_current > import_total) {
        jQuery('#mainwp-import-sites-status-message').hide();
        jQuery('#mainwp_managesites_btn_import').attr('disabled', 'true'); //Disable
        if (import_count_success < import_total) {
            jQuery('#mainwp_managesites_btn_save_csv').prop("disabled", false); //Enable
        }

        if (import_count_fails == 0) {
            jQuery('#mainwp_managesites_import_logging .log').html('<div style="text-align:center;margin:50px 0;"><h2 class="ui icon header"><i class="green check icon"></i><div class="content">Congratulations!<div class="sub header">' + import_count_success + ' sites imported successfully.</div></div></h2></div>');
            jQuery('#mainwp_managesites_btn_import').hide();
            if (page_href !== undefined && page_href !== '') {
                setTimeout(function () {
                    window.location.href = page_href;
                }, 2000);
            } else {
                setTimeout(function () {
                    location.reload();
                }, 2000);
            }
        } else {
            jQuery('#mainwp_managesites_import_logging .log').append('<div class="ui yellow message">Process completed with errors. ' + import_count_fails + ' site(s) failed to import. Please review logs to resolve problems and try again.</div>');
            jQuery('#mainwp_managesites_btn_import').hide();
            jQuery('#mainwp-import-sites-modal-try-again').show();
            jQuery('#mainwp-import-sites-modal-continue').show();
        }

        jQuery('#mainwp_managesites_import_logging').scrollTop(jQuery('#mainwp_managesites_import_logging .log').height());
        return;
    }

    // Call the customer constructor without the website on the last run.
    if (import_current == import_total) {
        mainwp_managesites_import_client_no_website();
    }

    let import_data = jQuery('#mainwp_managesites_import_csv_line_' + import_current).attr('encoded-data');
    let import_line_orig = jQuery('#mainwp_managesites_import_csv_line_' + import_current).attr('original');
    const is_page_managesites = jQuery('#mainwp_managesites_do_managesites_import').val(); // Get value page managesites

    let decodedVal = JSON.parse(import_data);

    let import_wpname = decodedVal.name;
    let import_wpurl = decodedVal.url;
    let import_wpadmin = decodedVal.adminname;
    let import_wpadmin_pwd = decodedVal.adminpwd;
    let import_wpgroups = decodedVal.wpgroups;
    let import_uniqueId = decodedVal.uniqueId;
    let import_http_username = decodedVal.http_user;
    let import_http_password = decodedVal.http_pass;
    let import_verify_certificate = decodedVal.verify_certificate;

    if (typeof (import_wpname) == "undefined")
        import_wpname = '';
    if (typeof (import_wpurl) == "undefined")
        import_wpurl = '';
    if (typeof (import_wpadmin) == "undefined")
        import_wpadmin = '';
    if (typeof (import_wpgroups) == "undefined")
        import_wpgroups = '';
    if (typeof (import_uniqueId) == "undefined")
        import_uniqueId = '';
    if (typeof (import_wpadmin_pwd) == "undefined") {
        import_wpadmin_pwd = '';
    }

    jQuery('#mainwp_managesites_import_logging .log').append('<strong>[' + import_current + '] << ' + import_line_orig + '</strong><br/>');

    let errors = [];

    if (import_wpname == '') {
        errors.push(__('Please enter the site name.'));
    }

    if (import_wpurl == '') {
        errors.push(__('Please enter the site URL.'));
    }

    if (import_wpadmin == '') {
        errors.push(__('Please enter username of the site administrator.'));
    }

    if (errors.length > 0) {
        jQuery('#mainwp_managesites_import_logging .log').append('[' + import_current + '] >> Error - ' + errors.join(" ") + '<br/>');
        jQuery('#mainwp_managesites_import_fail_logging').append('<span>' + import_line_orig + '</span>');
        import_count_fails++;
        mainwp_managesites_import_sites();
        return;
    }

    let data = mainwp_secure_data({
        action: 'mainwp_checkwp',
        name: import_wpname,
        url: import_wpurl,
        admin: import_wpadmin,
        check_me: import_current,
        verify_certificate: import_verify_certificate,
        http_user: import_http_username,
        http_pass: import_http_password
    });

    jQuery.post(ajaxurl, data, function (res_things) {
        let response = res_things.response ?? '';

        let check_result = '[' + res_things.check_me + '] >> ';

        response = response.trim();
        let url = import_wpurl;
        if (url.substring(0, 4) != 'http') {
            url = 'https://' + url; // default https://.
        }
        if (!url.endsWith('/')) {
            url += '/';
        }
        url = url.replace(/"/g, '&quot;');
        if (response == 'HTTPERROR') {
            errors.push(check_result + __('HTTP error: website does not exist!'));
        } else if (response == 'NOMAINWP') {
            errors.push(check_result + __('MainWP Child plugin not detected! First install and activate the MainWP Child plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation)', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins', url + 'wp-admin/plugin-install.php?tab=search&type=term&s=mainwp&plugin-search-input=Search+Plugins'));
        } else if (response.substring(0, 5) == 'ERROR') {
            if (response.length == 5) {
                errors.push(check_result + __('Undefined error!'));
            } else {
                errors.push(check_result + 'ERROR: ' + response.substring(6));
            }
        } else if (response == 'OK') {
            let groupids = [];
            let data = mainwp_secure_data({
                action: "mainwp_addwp",
                managesites_add_wpname: import_wpname,
                managesites_add_wpurl: url,
                managesites_add_wpadmin: import_wpadmin,
                managesites_add_adminpwd: encodeURIComponent(import_wpadmin_pwd),
                managesites_add_uniqueId: import_uniqueId,
                "groupids[]": groupids,
                groupnames_import: import_wpgroups,
                add_me: import_current,
                verify_certificate: import_verify_certificate,
                managesites_add_http_user: import_http_username,
                managesites_add_http_pass: import_http_password,
            });

            jQuery.post(ajaxurl, data, function (res_things) {
                if (res_things.error) {
                    response = 'ERROR: ' + res_things.error;
                } else {
                    response = res_things.response;
                }
                let add_result = '[' + res_things.add_me + '] >> ';

                response = response.trim();

                if (response.substring(0, 5) == 'ERROR') {
                    jQuery('#mainwp_managesites_import_fail_logging').append('<span>' + import_line_orig + '</span>');
                    jQuery('#mainwp_managesites_import_logging .log').append(add_result + response.substring(6) + "<br/>");
                    import_count_fails++;
                } else {
                    //Message the WP was added.
                    jQuery('#mainwp_managesites_import_logging .log').append(add_result + response + "<br/>");
                    // Check if you are on page managesites and import by form.
                    if (is_page_managesites !== undefined && Number(is_page_managesites) === 1) {
                        let data_temp_managesites = mainwp_secure_data({
                            action: 'mainwp_delete_temp_import_website',
                            managesites_add_wpurl: url,
                            managesites_add_wpadmin: import_wpadmin,
                        });
                        jQuery.post(ajaxurl, data_temp_managesites, function () { });
                    }

                    // Create Client
                    mainwp_managesites_import_client(import_current, res_things.siteid);

                    import_count_success++;
                }
                mainwp_managesites_import_sites();
            }, 'json').fail(function (xhr, textStatus, errorThrown) {
                jQuery('#mainwp_managesites_import_fail_logging').append('<span>' + import_line_orig + '</span>');
                jQuery('#mainwp_managesites_import_logging .log').append("error: " + errorThrown + "<br/>");
                import_count_fails++;
                mainwp_managesites_import_sites();
            });
        }

        if (errors.length > 0) {
            jQuery('#mainwp_managesites_import_fail_logging').append('<span>' + import_line_orig + '</span>');
            jQuery('#mainwp_managesites_import_logging .log').append(errors.join("\n") + '<br/>');
            import_count_fails++;
            mainwp_managesites_import_sites();
        }
        jQuery('#mainwp_managesites_import_logging').scrollTop(jQuery('#mainwp_managesites_import_logging .log').height());
    }, 'json');

};

// Handle add new client
const mainwp_managesites_import_client = function (index, siteid) {
    let import_client_data = jQuery('#mainwp_managesites_import_client_line_' + index).attr('encoded-data');

    if (typeof (import_client_data) == "undefined")
        import_client_data = '';

    if (import_client_data !== '') {
        const data = mainwp_secure_data({
            action: 'mainwp_import_website_add_client',
            client: import_client_data,
            site_id: siteid
        });

        jQuery.post(ajaxurl, data, function () { });
    }

    return true;
}

// Handle add new client no website
const mainwp_managesites_import_client_no_website = function () {
    let client_data = [];
    jQuery('.mainwp_managesites_import_client_no_site_lines').each(function () {
        let data = jQuery(this).attr('encoded-data');
        client_data.push(data);
    });

    if (client_data.length > 0) {
        const data = mainwp_secure_data({
            action: 'mainwp_import_website_add_client_no_site',
            client: client_data,
        });

        jQuery.post(ajaxurl, data, function () { });
    }
    return true;
}

// Handle page import website
jQuery(document).ready(function ($) {
    // Get default value
    let import_index = $("#mainwp-managesites-import-row").attr(
        "data-default-row"
    );
    let is_page_setup = $("#mainwp-managesites-import-row").attr(
        "data-page-setup"
    );
    // Store the initial value of the input
    let initial_value = "";
    // Add new row by clicking Add New Row button
    $("#mainwp-managesites-import-row").on("click", function (e) {
        e.preventDefault();
        import_index++; // Update index before create row.

        let new_row = "";
        if (Number(is_page_setup) === 1) {
            new_row =
                mainwp_managesites_import_sites_page_setup_add_row(import_index);
        } else {
            new_row = mainwp_managesites_import_sites_add_row(import_index);
        }

        $(this).parent().parent().before(new_row);
    });

    // When user focuses on input, save initial value
    $("#mainwp-managesites-row-import-sites").on("focus", "input", function () {
        initial_value = $(this).val();
    });

    // Attach blur event to all input fields whose name is site_url
    $("#mainwp-managesites-row-import-sites").on(
        "blur",
        ".mainwp-managesites-import-site-url",
        function () {
            const input = $(this);
            if (input.val() === initial_value) {
                return;
            }
            const row_index = input.attr("data-row-index");
            const input_site_name = $(
                "#mainwp-managesites-import-site-name-" + row_index
            );
            const parsed_url = mainwp_managesites_import_sites_extract_domain(
                input.val()
            );

            if (parsed_url !== "" && parsed_url !== undefined) {
                // Update input value with domain name only
				input.val(`${parsed_url.origin}${parsed_url.pathname}`);
                // Set value site name
				input_site_name.val(`${parsed_url.origin}`);
            } else {
                const user_confirmed = confirm(
                    __(
                        "Please enter a valid URL. Example: http://example.com\nClick OK to stay and correct, or Cancel to continue without correcting."
                    )
                );
                if (user_confirmed) {
                    setTimeout(function () {
                        input.focus();
                    }, 1); // Reset focus immediately after confirm
                }
                input_site_name.val(input.val());
            }
        }
    );

    // Catch paste event to check URL as soon as data is pasted
    $(document).on(
        "paste",
        ".mainwp-managesites-import-site-url",
        function () {
            const input = $(this);
            if (input.val() === initial_value) {
                return;
            }
            const row_index = input.attr("data-row-index");
            const input_site_name = $(
                "#mainwp-managesites-import-site-name-" + row_index
            );
            const parsed_url = mainwp_managesites_import_sites_extract_domain(
                input.val()
            );
            if (parsed_url !== "" && parsed_url !== undefined) {
                // Update input value with domain name only
				input.val(`${parsed_url.origin}${parsed_url.pathname}`);
                // Set value site name
                input_site_name.val(`${parsed_url.origin}`);
            } else {
                // Show confirm, asking the user if they want to edit or not
                const user_confirmed = confirm(
                    __(
                        "The pasted URL is invalid. Example: http://example.com\nClick OK to stay and correct, or Cancel to continue without correcting."
                    )
                );
                if (user_confirmed) {
                    setTimeout(function () {
                        input.focus();
                    }, 1); // 	Reset focus if user selects OK
                    input_site_name.val(input.val());
                }
            }
        }
    );

    // Save data when leaving inputs in a row.
    $("#mainwp-managesites-row-import-sites").on("blur", "input", function () {
        const current_value = $(this).val();
        // Check if value has changed before calling AJAX
        if (current_value !== initial_value) {
            const row_index = $(this).attr("data-row-index");
            mainwp_managesites_save_row_temp_data(row_index);
        }
    });
});

// Function to send temporary data of a row to the server.
const mainwp_managesites_save_row_temp_data = function (row_index) {
    const row = jQuery("#mainwp-managesites-import-row-" + row_index);
    const site_url = row
        .find(`input[name="mainwp_managesites_import[${row_index}][site_url]"]`)
        .val();
    const admin_name = row
        .find(`input[name="mainwp_managesites_import[${row_index}][admin_name]"]`)
        .val();
    const admin_password = row
        .find(
            `input[name="mainwp_managesites_import[${row_index}][admin_password]"]`
        )
        .val();

    // Check if the fields site_url, admin_name and admin_password are not empty.
    if (site_url && admin_name && admin_password) {
        const site_name = row
            .find(`input[name="mainwp_managesites_import[${row_index}][site_name]"]`)
            .val();
        const tag = row
            .find(`input[name="mainwp_managesites_import[${row_index}][tag]"]`)
            .val();
        const security_id = row
            .find(
                `input[name="mainwp_managesites_import[${row_index}][security_id]"]`
            )
            .val();
        const http_username = row
            .find(
                `input[name="mainwp_managesites_import[${row_index}][http_username]"]`
            )
            .val();
        const http_password = row
            .find(
                `input[name="mainwp_managesites_import[${row_index}][http_password]"]`
            )
            .val();
        const verify_certificate = row
            .find(
                `input[name="mainwp_managesites_import[${row_index}][verify_certificate]"]`
            )
            .val();
        const ssl_version = row
            .find(
                `input[name="mainwp_managesites_import[${row_index}][ssl_version]"]`
            )
            .val();
        const data = mainwp_secure_data({
            action: "mainwp_save_temp_import_website",
            status: "save_temp",
            row_index: Number(row_index),
            site_url: site_url,
            admin_name: admin_name,
            admin_password: admin_password,
            site_name: site_name,
            tag: tag,
            security_id: security_id,
            http_username: http_username,
            http_password: http_password,
            verify_certificate: verify_certificate,
            ssl_version: ssl_version,
        });

        // Send row save data to server.
        jQuery.post(ajaxurl, data, function () { });
    }
};
// Function to get the domain part from the entered URL
const mainwp_managesites_import_sites_extract_domain = function (url) { // NOSONAR - to compatible.

    try {
        // Use URL API to parse URL and get only protocol and host part
        return new URL(url);
    } catch (e) {
        // If the URL is invalid or contains an error, return an empty string.
        return "";
    }
};

// Delete row when pressing Delete button.
const mainwp_managesites_import_sites_delete_row = function (index) {
    const row = jQuery("#mainwp-managesites-import-row-" + index);
    const site_url = row
        .find(`input[name="mainwp_managesites_import[${index}][site_url]"]`)
        .val();
    const admin_name = row
        .find(`input[name="mainwp_managesites_import[${index}][admin_name]"]`)
        .val();
    // Check if the fields site_url, admin_name are not empty.
    if (site_url && admin_name) {
        const data = mainwp_secure_data({
            action: "mainwp_save_temp_import_website",
            status: "delete_temp",
            row_index: Number(index),
        });

        jQuery.post(ajaxurl, data, function (response) { });
    }
    jQuery("#mainwp-managesites-import-row-" + index).remove();
    return true;
};
// Toggle form field in page setup wizard.
const mainwp_managesites_import_sites_more_row = function (index) {
    jQuery(".mainwp-managesites-import-column-more-" + index).fadeToggle("slow");
    jQuery("#icon-visible-" + index).toggle();
    jQuery("#icon-hidden-" + index).toggle();
    return false;
};

const mainwp_managesites_import_sites_page_setup_add_row = function (row_index) {
    row_index--;
    return `<div class="row mainwp-managesites-import-rows" id="mainwp-managesites-import-row-${row_index}" data-index="${row_index}">
        <div class="four wide column">
            ${mainwp_managesites_import_sites_render_input(
        row_index,
        "site_url",
        "mainwp-managesites-import-site-url-" + row_index,
        "mainwp-managesites-import-site-url"
    )}
        </div>
        <div class="four wide column">
            ${mainwp_managesites_import_sites_render_input(
        row_index,
        "site_name",
        "mainwp-managesites-import-site-name-" + row_index,
        "mainwp-managesites-import-site-name"
    )}
        </div>
        <div class="four wide column">
            ${mainwp_managesites_import_sites_render_input(
        row_index,
        "admin_name",
        "mainwp-managesites-import-admin-name-" + row_index,
        "mainwp-managesites-import-admin-name"
    )}
        </div>
        <div class="three wide column">
            ${mainwp_managesites_import_sites_render_input(
        row_index,
        "admin_password",
        "mainwp-managesites-import-admin-password-" + row_index,
        "mainwp-managesites-import-admin-password",
        "",
        "",
        "password"
    )}
        </div>
        <div class="one wide column">
            <div class="ui mini fluid input">
            <a class="mainwp-managesites-more-import-row" href="javascript:void(0)" onclick="mainwp_managesites_import_sites_more_row(${row_index})" style="margin-right: 10px !important;">
                <i class="eye outline icon" id="icon-visible-${row_index}" ></i>
                <i class="eye slash outline icon" id="icon-hidden-${row_index}" style="display: none;"></i>
            </a>
            ${mainwp_managesites_import_sites_render_button_remove(row_index)}
            </div>
        </div>
        <div class="three wide column mainwp-managesites-import-column-more-${row_index}" style="display:none">
            ${mainwp_managesites_import_sites_render_column(
        row_index,
        "Tag",
        "tag",
        "tag"
    )}
        </div>
        <div class="three wide column mainwp-managesites-import-column-more-${row_index}" style="display:none">
            ${mainwp_managesites_import_sites_render_column(
        row_index,
        "Security ID",
        "security_id",
        "security-id"
    )}
        </div>
        <div class="three wide column mainwp-managesites-import-column-more-${row_index}" style="display:none">
            ${mainwp_managesites_import_sites_render_column(
        row_index,
        "HTTP Username",
        "http_username",
        "http-username"
    )}
        </div>
        <div class="three wide column mainwp-managesites-import-column-more-${row_index}" style="display:none">
            ${mainwp_managesites_import_sites_render_column(
        row_index,
        "HTTP Password",
        "http_password",
        "http-password"
    )}
        </div>
        <div class="two wide column mainwp-managesites-import-column-more-${row_index}" style="display:none">
            ${mainwp_managesites_import_sites_render_column(
        row_index,
        "Verify Certificate",
        "verify_certificate",
        "verify-certificate",
        "number",
        1
    )}
        </div>
        <div class="two wide column mainwp-managesites-import-column-more-${row_index}" style="display:none">
            ${mainwp_managesites_import_sites_render_column(
        row_index,
        "SSL Version",
        "ssl_version",
        "ssl-version",
        "",
        "auto"
    )}
        </div>
    </div>`;
};
// Add new row by clicking Add New Row button.
const mainwp_managesites_import_sites_add_row = function (row_index) {
    row_index--;
    return `
        <div class="row mainwp-managesites-import-rows" id="mainwp-managesites-import-row-${row_index}" data-index="${row_index}" data-temp-id="${row_index}">
            <div class="two wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "site_url",
        "mainwp-managesites-import-site-url-" + row_index,
        "mainwp-managesites-import-site-url"
    )}
            </div>
            <div class="two wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "site_name",
        "mainwp-managesites-import-site-name-" + row_index,
        "mainwp-managesites-import-site-name"
    )}
            </div>
            <div class="two wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "admin_name",
        "mainwp-managesites-import-admin-name-" + row_index,
        "mainwp-managesites-import-admin-name"
    )}
            </div>
            <div class="two wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "admin_password",
        "mainwp-managesites-import-admin-password-" + row_index,
        "mainwp-managesites-import-admin-password",
        "",
        "",
        "password"
    )}
            </div>

            <div class="one wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "tag",
        "mainwp-managesites-import-tag-" + row_index,
        "mainwp-managesites-import-tag"
    )}
            </div>
            <div class="one wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "security_id",
        "mainwp-managesites-import-security-id-" + row_index,
        "mainwp-managesites-import-security-id"
    )}
            </div>
            <div class="two wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "http_username",
        "mainwp-managesites-import-http-username-" + row_index,
        "mainwp-managesites-import-http-username"
    )}
            </div>
            <div class="one wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "http_password",
        "mainwp-managesites-import-http-password-" + row_index,
        "mainwp-managesites-import-http-password"
    )}
            </div>
            <div class="one wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "verify_certificate",
        "mainwp-managesites-import-verify-certificate-" + row_index,
        "mainwp-managesites-import-verify-certificate",
        "number",
        1
    )}
            </div>
            <div class="one wide column">
                ${mainwp_managesites_import_sites_render_input(
        row_index,
        "ssl_version",
        "mainwp-managesites-import-ssl-version" + row_index,
        "mainwp-managesites-import-ssl-version",
        "",
        "auto"
    )}
            </div>
            <div class="one wide column">
                ${mainwp_managesites_import_sites_render_button_remove(row_index)}
            </div>
        </div>`;
};

// Render column.
const mainwp_managesites_import_sites_render_column = function (
    row_index,
    label,
    input_name,
    input_class,
    input_type = "",
    value = ""
) {
    return `
        <div class="">
            <span class="ui small text">${label}</span>
            ${mainwp_managesites_import_sites_render_input(
        row_index,
        input_name,
        "mainwp-managesites-import-" + input_class + "-" + row_index,
        "mainwp-managesites-import-" + input_class,
        input_type,
        value
    )}
        </div>`;
};

// Render input.
const mainwp_managesites_import_sites_render_input = function (
    row_index,
    name,
    id = "",
    class_st = "",
    type = "",
    value = "",
    filed_type = "text",
) {
    return `
        <div class="ui mini fluid input">
            <input type="${filed_type}" value="${value}" id="${id}" class="mini ${class_st}" name="mainwp_managesites_import[${row_index}][${name}]" data-row-index="${row_index}" ${type === "number" ? "oninput=\"this.value = this.value.replace(/[^0-9]/g, '')\"" : ""}/>
        </div>`;
};

// Render button remove row.
const mainwp_managesites_import_sites_render_button_remove = function (
    row_index
) {
    return `
        <a class="mainwp-managesites-delete-import-row" href="#" onclick="mainwp_managesites_import_sites_delete_row(${row_index})">
            <i class="trash alternate outline icon"></i>
        </a>`;
};
// validate form before submitting form managesites_import.
const mainwp_managesites_import_handle_form_before_submit = function () {
    let has_table_data = false;
    let csv_selected = jQuery(`input[name="mainwp_managesites_file_bulkupload"]`).val() !== ""; // Check if CSV file is selected
    let error_messages = [];

    // Iterate through each row in the rows
    has_table_data = mainwp_managesites_validate_import_rows(error_messages);

    // Check if both CSV and table have data
    if (csv_selected && has_table_data) {
        error_messages.push(__("You can only submit either the table data or a CSV file, not both at the same time"));
    }

    // Check if both are empty
    if (!csv_selected && !has_table_data) {
        error_messages.push(__("Please fill in the table or select a CSV file."));
    }

    return error_messages;
};

// Check data in model table add new website.
const mainwp_managesites_validate_import_rows = function (error_messages, is_valid_row = false) {
    let has_table_data = false;
    let valid_row_count = 0;
    jQuery(
        "#mainwp-managesites-row-import-sites .mainwp-managesites-import-rows"
    ).each(function (index) {
        let site_url = jQuery(
            `input[name="mainwp_managesites_import[${index}][site_url]"]`
        ).val();
        let admin_name = jQuery(
            `input[name="mainwp_managesites_import[${index}][admin_name]"]`
        ).val();
        let admin_password = jQuery(
            `input[name="mainwp_managesites_import[${index}][admin_password]"]`
        ).val();

        // If there is data in any row of the table, check the required fields
        if (site_url || admin_name || admin_password) {
            has_table_data = true;

            let msg = "";
            if (!site_url) {
                msg = __("Site URL is required in row %1", index + 1);
                error_messages.push(msg);
            }
            if (!admin_name) {
                msg = __("Admin Name is required in row %1", index + 1);
                error_messages.push(msg);
            }
            if (!admin_password) {
                msg = __("Admin Password is required in row %1", index + 1);
                error_messages.push(msg);
            }

            // If both site_url, admin_name and admin_password have values, increment the counter variable.
            if (site_url && admin_name && admin_password) {
                valid_row_count++;
            }
        }

    });
    if (is_valid_row && error_messages.length === 0 && valid_row_count === 0) {
        error_messages.push(__("At least one row must have both Site URL, Admin Name and Admin Password."));
    }

    return has_table_data;
}