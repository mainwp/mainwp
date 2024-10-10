
/**
 * MainWP_Page.page
 */

let countSent = 0;
let countReceived = 0;

jQuery(function () {

    // to fix issue not loaded calendar js library
    if (jQuery('.ui.calendar').length > 0) {
        if (mainwpParams.use_wp_datepicker == 1) {
            jQuery('#mainwp-manage-pages .ui.calendar input[type=text],#mainwp-manage-posts .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
        } else {
            mainwp_init_ui_calendar('#mainwp-manage-pages .ui.calendar, #mainwp-manage-posts .ui.calendar');
        }
    }

    jQuery(document).on('click', '#mainwp_show_pages', function () {
        mainwp_fetch_pages();
    });
    jQuery(document).on('click', '.page_submitpublish', function () {
        mainwppage_postAction(jQuery(this), 'publish');
        return false;
    });
    jQuery(document).on('click', '.page_submitdelete', function () {
        mainwppage_postAction(jQuery(this), 'trash');
        return false;
    });
    jQuery(document).on('click', '.page_submitdelete_perm', function () {
        mainwppage_postAction(jQuery(this), 'delete');
        return false;
    });
    jQuery(document).on('click', '.page_submitrestore', function () {
        mainwppage_postAction(jQuery(this), 'restore');
        return false;
    });
    jQuery(document).on('click', '#mainwp-do-pages-bulk-actions', function () {
        let action = jQuery('#mainwp-bulk-actions').val();
        if (action != 'trash' && action != 'restore' && action != 'delete') {
            return;
        }

        let tmp = jQuery("input[name='page[]']:checked");
        countSent = tmp.length;

        if (countSent == 0)
            return;

        let _callback = function () {
            jQuery('#mainwp-do-pages-bulk-actions').attr('disabled', 'true');
            tmp.each(
                function (index, elem) {
                    mainwppage_postAction(elem, action);
                }
            );
        };

        if (action == 'delete') {
            let msg = __('You are about to delete %1 page(s). Are you sure you want to proceed?', countSent);
            mainwp_confirm(msg, _callback);
            return;
        }
        _callback();
    });
});


let mainwppage_postAction = function (elem, what) {
    let rowElement = jQuery(elem).closest('tr');
    let pageId = rowElement.find('.pageId').val();
    let websiteId = rowElement.find('.websiteId').val();

    if (rowElement.find('.allowedBulkActions').val().indexOf('|' + what + '|') == -1) {
        jQuery(elem).prop("checked", false);
        countReceived++;

        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            setTimeout(function () {
                jQuery('#mainwp-do-pages-bulk-actions').prop("disabled", false);
            }, 50);
        }

        return;
    }

    let data = mainwp_secure_data({
        action: 'mainwp_page_' + what,
        postId: pageId,
        websiteId: websiteId
    });

    rowElement.html('<td colspan="99"><i class="notched circle loading icon"></i> Please wait...</td>');
    jQuery.post(ajaxurl, data, function (response) {
        if (response.error) {
            rowElement.html('<td colspan="99"><i class="times red icon"></i>' + response.error + '</td>');
        } else if (response.result) {
            rowElement.html('<td colspan="99"><i class="green check icon"></i> ' + response.result + '</td>');
            if (jQuery(rowElement).hasClass('child')) {
                jQuery(rowElement).prev().hide();
            }
        }
        countReceived++;

        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            jQuery('#mainwp-do-pages-bulk-actions').prop("disabled", false);
        }
    }, 'json');

    return false;
};

let mainwp_fetch_pages = function () {

    let params = mainwp_fetch_pages_prepare();

    if (typeof params !== 'object') {
        return;
    }

    let _status = params['_status'];
    let selected_sites = params['selected_sites'];
    let selected_clients = params['selected_clients'];
    let selected_groups = params['selected_groups'];

    let data = mainwp_secure_data({
        action: 'mainwp_pages_search',
        keyword: jQuery('#mainwp_page_search_by_keyword').val(),
        dtsstart: jQuery('#mainwp_page_search_by_dtsstart').val(),
        dtsstop: jQuery('#mainwp_page_search_by_dtsstop').val(),
        status: _status,
        'groups[]': selected_groups,
        'sites[]': selected_sites,
        'clients[]': selected_clients,
        maximum: jQuery("#mainwp_maximumPages").val(),
        search_on: jQuery("#mainwp_page_search_on").val(),
    });

    jQuery('#mainwp-loading-pages-row').show();
    jQuery.post(ajaxurl, data, function (response) {
        response = response.trim();
        jQuery('#mainwp-loading-pages-row').hide();
        jQuery('#mainwp_pages_main').show();
        jQuery('#mainwp_pages_wrap_table').html(response);
        // re-initialize datatable
        jQuery("#mainwp-pages-table").DataTable().destroy();
        jQuery('#mainwp-pages-table').DataTable({
            "responsive": true,
            "colReorder": { columns: ":not(.check-column):not(:last-child)" },
            "stateSave": true,
            "pagingType": "full_numbers",
            "scrollX": true,
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            "order": [],
            "columnDefs": [{
                "targets": 'no-sort',
                "orderable": false
            }],
            "preDrawCallback": function () {
                console.log('preDrawCallback js');
                setTimeout(() => {
                    jQuery('#mainwp-pages-table .ui.dropdown').dropdown();
                    jQuery('#mainwp-pages-table .ui.checkbox').checkbox();
                    mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                    mainwp_datatable_fix_menu_overflow();
                }, 1000);
            },
            select: {
                items: 'row',
                style: 'multi+shift',
                selector: 'tr>td:not(.not-selectable)'
            }
        }).on('select', function (e, dt, type, indexes) {
            if ('row' == type) {
                dt.rows(indexes)
                    .nodes()
                    .to$().find('td.check-column .ui.checkbox').checkbox('set checked');
            }
        }).on('deselect', function (e, dt, type, indexes) {
            if ('row' == type) {
                dt.rows(indexes)
                    .nodes()
                    .to$().find('td.check-column .ui.checkbox').checkbox('set unchecked');
            }
        }).on('columns-reordered', function () {
            console.log('columns-reordered');
            setTimeout(() => {
                jQuery('#mainwp-pages-table .ui.dropdown').dropdown();
                jQuery('#mainwp-pages-table .ui.checkbox').checkbox();
                mainwp_datatable_fix_menu_overflow();
                mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
            }, 1000);
        });
    });
};

let mainwp_fetch_pages_prepare = function () { // NOSONAR - complexity 19/15.

    let errors = [];
    let selected_sites = [];
    let selected_groups = [];
    let selected_clients = [];

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            selected_sites.push(jQuery(this).val());
        });
        if (selected_sites.length == 0) {
            errors.push('<div class="ui yellow message">' + __('Please select websites or groups or clients.') + '</div>');
        }
    } else if (jQuery('#select_by').val() == 'client') {
        jQuery("input[name='selected_clients[]']:checked").each(function () {
            selected_clients.push(jQuery(this).val());
        });
        if (selected_clients.length == 0) {
            errors.push('<div class="ui yellow message">' + __('Please select websites or groups or clients.') + '</div>');
        }
    } else {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });
        if (selected_groups.length == 0) {
            errors.push('<div class="ui yellow message">' + __('Please select websites or groups or clients.') + '</div>');
        }
    }

    let _status = '';
    let statuses = jQuery("#mainwp_page_search_type").dropdown("get value");
    if (statuses == null)
        errors.push('Please select a page status.');
    else {
        _status = statuses.join(',');
    }

    if (errors.length > 0) {
        jQuery('#mainwp_pages_error').html(errors.join('<br />'));
        jQuery('#mainwp_pages_error').show();
        return;
    } else {
        jQuery('#mainwp_pages_error').html("");
        jQuery('#mainwp_pages_error').hide();
    }

    return {
        'selected_sites': selected_sites,
        'selected_groups': selected_groups,
        'selected_clients': selected_clients,
        '_status': _status,
    }
}
/**
 * MainWP_Post.page
 */

jQuery(function () {
    jQuery(document).on('click', '#mainwp_show_posts', function () {
        mainwp_fetch_posts();
    });
    jQuery(document).on('click', '.post_submitdelete', function () {
        mainwppost_postAction(jQuery(this), 'trash');
        return false;
    });
    jQuery(document).on('click', '.post_submitpublish', function () {
        mainwppost_postAction(jQuery(this), 'publish');
        return false;
    });
    jQuery(document).on('click', '.post_submitunpublish', function () {
        mainwppost_postAction(jQuery(this), 'unpublish');
        return false;
    });
    jQuery(document).on('click', '.post_submitapprove', function () {
        mainwppost_postAction(jQuery(this), 'approve');
        return false;
    });
    jQuery(document).on('click', '.post_submitdelete_perm', function () {
        mainwppost_postAction(jQuery(this), 'delete');
        return false;
    });
    jQuery(document).on('click', '.post_submitrestore', function () {
        mainwppost_postAction(jQuery(this), 'restore');
        return false;
    });

    jQuery(document).on('click', '.post_getedit', function () {
        mainwppost_postAction(jQuery(this), 'get_edit', 'post');
        return false;
    });

    jQuery(document).on('click', '.page_getedit', function () {
        mainwppost_postAction(jQuery(this), 'get_edit', 'page');
        return false;
    });

    jQuery(document).on('click', '#mainwp-do-posts-bulk-actions', function () {
        let action = jQuery('#mainwp-bulk-actions').val();
        if (action != 'publish' && action != 'unpublish' && action != 'trash' && action != 'restore' && action != 'delete') {
            return;
        }

        let tmp = jQuery("input[name='post[]']:checked");
        countSent = tmp.length;

        if (countSent == 0)
            return;

        let _callback = function () {
            jQuery('#mainwp-do-posts-bulk-actions').attr('disabled', 'true');
            tmp.each(
                function (index, elem) {
                    mainwppost_postAction(elem, action);
                }
            );
        }
        if (action == 'delete') {
            let msg = __('You are about to delete %1 post(s). Are you sure you want to proceed?', countSent);
            mainwp_confirm(msg, _callback);
            return;
        }
        _callback();
    });
});

let mainwppost_postAction = function (elem, what, postType) {
    let rowElement = jQuery(elem).closest('tr');
    let postId = rowElement.find('.postId').val();
    let websiteId = rowElement.find('.websiteId').val();
    if (rowElement.find('.allowedBulkActions').val().indexOf('|' + what + '|') == -1) {
        jQuery(elem).prop("checked", false);
        countReceived++;

        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            setTimeout(function () {
                jQuery('#mainwp-do-posts-bulk-actions').prop("disabled", false);
            }, 50);
        }

        return;
    }

    if (what == 'get_edit' && postType === 'page') {
        postId = rowElement.find('.pageId').val();
    }

    let data = {
        action: 'mainwp_post_' + what,
        postId: postId,
        websiteId: websiteId
    };
    if (typeof postType !== "undefined") {
        data['postType'] = postType;
    }
    data = mainwp_secure_data(data);

    rowElement.html('<td colspan="99"><i class="notched circle loading icon"></i> Please wait...</td>');
    jQuery.post(ajaxurl, data, function (response) {
        if (response.error) {
            rowElement.html('<td colspan="99"><i class="times red icon"></i>' + response.error + '</td>');
        } else if (response.result) {
            rowElement.html('<td colspan="99"><i class="green check icon"></i> ' + response.result + '</td>');
            if (jQuery(rowElement).hasClass('child')) {
                jQuery(rowElement).prev().hide();
            }
        } else {
            rowElement.hide();
            if (what == 'get_edit' && response.id) {
                if (response.redirect_to) {
                    location.href = response.redirect_to;
                } else if (postType == 'post') {
                    location.href = 'admin.php?page=PostBulkEdit&post_id=' + response.id;
                } else if (postType == 'page') {
                    location.href = 'admin.php?page=PageBulkEdit&post_id=' + response.id;
                }
            }
        }
        countReceived++;
        if (countReceived == countSent) {
            countReceived = 0;
            countSent = 0;
            jQuery('#mainwp-do-posts-bulk-actions').prop("disabled", false);
        }
    }, 'json');

    return false;
};

let mainwp_show_post = function (siteId, postId, userId) {
    let siteElement = jQuery('input[name="selected_sites[]"][siteid="' + siteId + '"]');
    siteElement.prop('checked', true);
    siteElement.trigger("change");
    mainwp_fetch_posts(postId, userId);
};
/* eslint-disable complexity */
let mainwp_fetch_posts = function (postId, userId, start_sites) {

    let params = mainwp_fetch_posts_prepare(postId, userId, start_sites);

    console.log('params:');
    console.log(params);

    if (typeof params !== 'object') {
        return;
    }

    start_sites = params['start_sites'];

    let errors = params['errors'];
    let selected_sites = params['selected_sites'];
    let selected_clients = params['selected_clients'];
    let selected_groups = params['selected_groups'];
    let bulk_search = params['bulk_search'];


    let _status = '';
    let statuses = jQuery("#mainwp_post_search_type").dropdown("get value");
    if (statuses == null)
        errors.push('<div class="ui yellow message">' + __('Please select at least one post status.') + '</div>');
    else {
        _status = statuses.join(',');
    }

    if (errors.length > 0) {
        mainwp_set_message_zone('#mainwp-message-zone', errors.join('<br />'), 'red');
        return;
    } else {
        mainwp_set_message_zone('#mainwp-message-zone');
    }

    if (bulk_search && selected_sites.length == 0) {
        mainwp_fetch_posts_done();
        return;
    }

    let data = mainwp_secure_data({
        action: 'mainwp_posts_search',
        keyword: jQuery('#mainwp_post_search_by_keyword').val(),
        dtsstart: jQuery('#mainwp_post_search_by_dtsstart').val(),
        dtsstop: jQuery('#mainwp_post_search_by_dtsstop').val(),
        status: _status,
        'groups[]': selected_groups,
        'sites[]': selected_sites,
        'clients[]': selected_clients,
        postId: (postId == undefined ? '' : postId),
        userId: (userId == undefined ? '' : userId),
        post_type: jQuery("#mainwp_get_custom_post_types_select").val(),
        maximum: jQuery("#mainwp_maximumPosts").val(),
        search_on: jQuery("#mainwp_post_search_on").val()
    });

    if (bulk_search && start_sites > 0) {
        data.table_content = 1;
    }


    jQuery('#mainwp-loading-posts-row').show();

    jQuery.post(ajaxurl, data, function (response) {
        response = response.trim();
        if (bulk_search && start_sites > 0) {
            jQuery('#mainwp-posts-list').append(response);
        } else {
            jQuery('#mainwp-posts-table-wrapper').html(response);
        }

        if (bulk_search) {
            start_sites = start_sites + num_sites;
            mainwp_fetch_posts(postId, userId, start_sites);
        } else {
            mainwp_fetch_posts_done();
        }
    });

};

let mainwp_fetch_posts_prepare = function (postId, userId, start_sites) { // NOSONAR - complexity.

    let errors = [];
    let selected_sites = [];
    let selected_groups = [];
    let selected_clients = [];

    let i = 0;
    let num_sites = jQuery('#search-bulk-sites').attr('number-sites');
    num_sites = parseInt(num_sites);

    let select_sites_error = '<div class="ui yellow message">' + __('Please select at least one website or group or client.') + '</div>';

    let bulk_search = num_sites > 0;

    if (jQuery('#select_by').val() == 'site' && start_sites == undefined) {
        start_sites = 0;
    }

    if (jQuery('#select_by').val() == 'site') {
        jQuery("input[name='selected_sites[]']:checked").each(function () {
            if (bulk_search) {
                if (i >= start_sites && i < start_sites + num_sites) {
                    selected_sites.push(jQuery(this).val());
                }
                i++;
            } else {
                selected_sites.push(jQuery(this).val());
            }
        });
        if (selected_sites.length == 0 && (!bulk_search || (bulk_search && start_sites == 0))) {
            errors.push(select_sites_error);
        }
    } else if (jQuery('#select_by').val() == 'client') {
        jQuery("input[name='selected_clients[]']:checked").each(function () {
            selected_clients.push(jQuery(this).val());
        });
        if(selected_clients.length == 0){
            errors.push(select_sites_error);
        }
    } else if (jQuery('#select_by').val() == 'group') {
        jQuery("input[name='selected_groups[]']:checked").each(function () {
            selected_groups.push(jQuery(this).val());
        });

        if(selected_groups.length == 0){
            errors.push(select_sites_error);
        } else if (selected_groups.length > 0 && bulk_search && start_sites == undefined) {
            console.log(num_sites);
            start_sites = 0;
            // get sites of groups.
            let data = mainwp_secure_data({
                action: 'mainwp_get_sites_of_groups',
                'groups[]': selected_groups
            });
            jQuery('#mainwp-loading-posts-row').show();
            jQuery.post(ajaxurl, data, function (response) {
                let site_ids = response;
                if (site_ids) {
                    jQuery("input[name='selected_sites[]'][bulk-search=true]").attr('bulk-search', false);
                    jQuery.each(site_ids, function (index, value) {
                        jQuery("input[name='selected_sites[]'][value=" + value + "]").attr('bulk-search', true);
                    });
                }
                mainwp_fetch_posts(postId, userId, start_sites);
            }, 'json');
            return;
        }

        if (selected_groups.length > 0 && bulk_search) {
            jQuery("input[name='selected_sites[]'][bulk-search=true]").each(function () {
                if (i >= start_sites && i < start_sites + num_sites) {
                    selected_sites.push(jQuery(this).val());
                }
                i++;
            });
        }
    }

    return {
        'errors': errors,
        'selected_sites': selected_sites,
        'selected_clients': selected_clients,
        'selected_groups': selected_groups,
        'start_sites': start_sites,
        'bulk_search': bulk_search,
    };
}

/* eslint-enable complexity */

let mainwp_fetch_posts_done = function () {
    jQuery('#mainwp-loading-posts-row').hide();
    jQuery('#mainwp_posts_main').show();
    let responsive = true;
    if (jQuery(window).width() > 1140) {
        responsive = false;
    }
    // re-initialize datatable.
    jQuery("#mainwp-posts-table").DataTable().destroy();
    jQuery('#mainwp-posts-table').DataTable({
        "responsive": responsive,
        "colReorder": { columns: ":not(.check-column):not(:last-child)" },
        "stateSave": true,
        "pagingType": "full_numbers",
        "order": [],
        "scrollX": true,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        "columnDefs": [{
            "targets": 'no-sort',
            "orderable": false
        }],
        "preDrawCallback": function () {
            setTimeout(() => {
                jQuery('#mainwp-posts-table-wrapper table .ui.dropdown').dropdown();
                jQuery('#mainwp-posts-table-wrapper table .ui.checkbox').checkbox();
                mainwp_datatable_fix_menu_overflow();
                mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
            }, 1000);
        },
        select: {
            items: 'row',
            style: 'multi+shift',
            selector: 'tr>td:not(.not-selectable)'
        }
    }).on('select', function (e, dt, type, indexes) {
        if ('row' == type) {
            dt.rows(indexes)
                .nodes()
                .to$().find('td.check-column .ui.checkbox').checkbox('set checked');
        }
    }).on('deselect', function (e, dt, type, indexes) {
        if ('row' == type) {
            dt.rows(indexes)
                .nodes()
                .to$().find('td.check-column .ui.checkbox').checkbox('set unchecked');
        }
    }).on('columns-reordered', function () {
        console.log('columns-reordered');
        setTimeout(() => {
            jQuery('#mainwp-posts-table-wrapper table .ui.dropdown').dropdown();
            jQuery('#mainwp-posts-table-wrapper table .ui.checkbox').checkbox();
            mainwp_datatable_fix_menu_overflow();
            mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
        }, 1000);
    });
}
