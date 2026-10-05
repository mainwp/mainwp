/* global jQuery, ajaxurl, mainwp_secure_data, mainwp_confirm */

/**
 * Each panel owns its list; a single editor owns the active record request.
 */
jQuery(function ($) {
    const modal = $('#mainwp-knowledge-modal');
    if (!modal.length) {
        return;
    }
    const modalHeader = modal.find('.header');
    const modalTitle = $('#mainwp-knowledge-modal-title');
    const modalLevel = $('#mainwp-knowledge-modal-level');
    const modalUnverified = $('#mainwp-knowledge-modal-unverified');
    const modalMessage = $('#mainwp-knowledge-modal-message');
    const typeField = $('#mainwp-knowledge-type');
    const typeViewField = $('#mainwp-knowledge-type-view');
    const titleField = $('#mainwp-knowledge-title');
    const descriptionField = $('#mainwp-knowledge-description');
    const bodyField = $('#mainwp-knowledge-body');
    const requiredField = $('#mainwp-knowledge-required');
    const idField = $('#mainwp-knowledge-id');
    const revisionField = $('#mainwp-knowledge-revision');
    const saveButton = $('#mainwp-knowledge-save');
    const deleteButton = $('#mainwp-knowledge-remove');
    const cancelButton = modal.find('.actions .cancel.button');
    const strings = window.mainwpKnowledgeStrings || {};
    let readOnly = true;
    let canWrite = false;
    let scope = null;
    let owner = null;
    let session = 0;

    const showMessage = (target, text, color) => {
        target.removeClass('hidden red green yellow').addClass(color).empty().append($('<p></p>').text(text));
    };
    const hideMessage = (target) => target.addClass('hidden').removeClass('red green yellow').empty();
    const post = (action, data) => $.post(ajaxurl, mainwp_secure_data($.extend({ action: action }, data)), null, 'json');
    const errorText = (response) => (response && response.data && response.data.message) ? response.data.message : strings.failed;
    const recordScope = (record) => {
        const result = { scope_type: record.scope_type };
        // Agency inputs must omit scope_id even though stored records carry zero.
        if ('agency' !== record.scope_type) {
            result.scope_id = parseInt(record.scope_id, 10);
        }
        return result;
    };
    const updateFields = () => {
        const isSkill = 'skill' === typeField.val();
        descriptionField.closest('.field').toggle(isSkill).toggleClass('required', isSkill && !readOnly);
        descriptionField.prop('required', isSkill && !readOnly).prop('readonly', readOnly);
        $('#mainwp-knowledge-description-help').toggle(!readOnly);
        const eligible = scope && 'agency' === scope.scope_type && ['context', 'skill'].includes(typeField.val());
        $('#mainwp-knowledge-required-field').toggle(!!eligible && canWrite && !readOnly);
        requiredField.prop('disabled', !eligible || !canWrite || readOnly);
    };
    const fill = (record) => {
        idField.val(record.id);
        revisionField.val(record.revision);
        typeField.val(record.type);
        typeField.closest('.dropdown').dropdown('set selected', record.type);
        typeViewField.val(record.type_label || '');
        titleField.val(record.title);
        descriptionField.val(record.description || '');
        bodyField.val(record.body);
        requiredField.prop('checked', !!record.required);
        modalLevel.text(record.level_label || '').toggle(!!record.level_label);
        modalUnverified.toggle(record.verified === 0);
        updateFields();
    };
    const openModal = (mode) => {
        readOnly = 'view' === mode;
        hideMessage(modalMessage);
        titleField.prop('readonly', readOnly);
        bodyField.prop('readonly', readOnly);
        typeField.closest('.field').toggle(!readOnly);
        typeViewField.closest('.field').toggle(readOnly);
        updateFields();
        saveButton.toggle(!readOnly).prop('disabled', readOnly).toggleClass('disabled', readOnly).removeClass('loading');
        deleteButton.toggle(!readOnly && parseInt(idField.val(), 10) > 0).prop('disabled', readOnly).removeClass('loading disabled');
        cancelButton.text(cancelButton.data(readOnly ? 'close' : 'cancel'));
        modalTitle.text(modalHeader.data(mode));
        modal.modal('show');
    };
    const begin = (nextOwner) => {
        ++session;
        owner = nextOwner;
        scope = null;
        canWrite = false;
        readOnly = true;
        fill({ id: 0, revision: 0, type: 'context', title: '', body: '' });
        titleField.prop('readonly', true);
        bodyField.prop('readonly', true);
        saveButton.prop('disabled', true).addClass('disabled');
        deleteButton.hide();
        hideMessage(modalMessage);
        return session;
    };
    const applyRecord = (response, mode) => {
        const record = response.data.record;
        scope = recordScope(record);
        canWrite = !!response.data.can_write;
        fill(record);
        openModal('view' === mode || !canWrite ? 'view' : 'edit');
    };
    const openRecord = (id, mode, nextOwner) => {
        const current = begin(nextOwner);
        post('mainwp_knowledge_get', { id: id }).done((response) => {
            if (current !== session) {
                return;
            }
            if (!response.success) {
                showMessage(owner.message, errorText(response), 'yellow');
                owner.refresh(true);
                return;
            }
            applyRecord(response, mode);
        }).fail(() => {
            if (current === session) {
                showMessage(owner.message, strings.failed, 'red');
            }
        });
    };
    const reloadIntoEditor = () => {
        const current = session;
        const button = modalMessage.find('button');
        if (button.hasClass('disabled')) {
            return;
        }
        button.addClass('loading disabled');
        post('mainwp_knowledge_get', { id: idField.val() }).done((response) => {
            if (current !== session) {
                return;
            }
            if (!response.success) {
                if (response.data && response.data.code === 'mainwp_knowledge_not_found') {
                    const previousOwner = owner;
                    modal.modal('hide');
                    showMessage(previousOwner.message, errorText(response), 'yellow');
                    previousOwner.refresh(true);
                    return;
                }
                showConflict(errorText(response));
                return;
            }
            applyRecord(response, 'edit');
            showMessage(modalMessage, strings.reloaded, 'yellow');
        }).fail(() => {
            if (current === session) {
                showConflict(strings.failed);
            }
        });
    };
    const showConflict = (message) => {
        showMessage(modalMessage, message || strings.conflict, 'yellow');
        modalMessage.append($('<p></p>').append(
            $('<button type="button" class="ui mini basic button"></button>').text(strings.reload).on('click', reloadIntoEditor)
        ));
    };
    const save = () => {
        if (readOnly || !canWrite || !scope || saveButton.hasClass('disabled')) {
            return;
        }
        if ('skill' === typeField.val() && !descriptionField[0].reportValidity()) {
            return;
        }
        const current = session;
        const previousOwner = owner;
        hideMessage(modalMessage);
        saveButton.prop('disabled', true).addClass('loading disabled');
        deleteButton.prop('disabled', true).addClass('disabled');
        const data = $.extend({}, scope, {
            type: typeField.val(),
            title: titleField.val(),
            description: 'skill' === typeField.val() ? descriptionField.val() : '',
            body: bodyField.val(),
        });
        if ('agency' === scope.scope_type && ['context', 'skill'].includes(data.type)) {
            data.required = requiredField.prop('checked') ? 1 : 0;
        }
        const id = parseInt(idField.val(), 10);
        if (id > 0) {
            data.id = id;
            data.revision = revisionField.val();
        }
        post('mainwp_knowledge_save', data).done((response) => {
            if (current !== session) {
                return;
            }
            if (response.success) {
                modal.modal('hide');
                previousOwner.refresh();
                return;
            }
            if (response.data && response.data.code === 'mainwp_knowledge_conflict') {
                saveButton.removeClass('loading');
                showConflict(response.data.message);
                return;
            }
            showMessage(modalMessage, errorText(response), 'red');
            saveButton.prop('disabled', false).removeClass('loading disabled');
            deleteButton.prop('disabled', false).removeClass('disabled');
            if (response.data && response.data.code === 'mainwp_knowledge_not_found') {
                previousOwner.refresh();
            }
        }).fail(() => {
            if (current === session) {
                showMessage(modalMessage, strings.failed, 'red');
                saveButton.prop('disabled', false).removeClass('loading disabled');
                deleteButton.prop('disabled', false).removeClass('disabled');
            }
        });
    };

    $('.mainwp-knowledge-panel').each(function () {
        const panel = $(this);
        const panelScope = recordScope({ scope_type: panel.data('scope-type'), scope_id: panel.data('scope-id') });
        const message = panel.find('.mainwp-knowledge-message');
        const loader = panel.children('.mainwp-knowledge-loader');
        const inheritedBlock = panel.find('.mainwp-knowledge-inherited');
        let panelCanWrite = false;
        const row = (record, inherited = false) => {
            const tr = $('<tr></tr>').attr('data-id', record.id).attr('data-revision', record.revision);
            const canEdit = panelCanWrite && !inherited;
            const title = $('<td></td>');
            if (!record.verified) {
                title.append($('<span class="ui mini yellow label"></span>').attr('data-tooltip', strings.unverifiedHint).attr('data-inverted', '').text(strings.unverified)).append(' ');
            }
            if (record.required) {
                title.append($('<span class="ui mini label"></span>').text(strings.required)).append(' ');
            }
            title.append($('<a href="#"></a>').addClass(canEdit ? 'mainwp-knowledge-edit' : 'mainwp-knowledge-view').text(record.title));
            title.append($('<br>')).append($('<span class="ui small text"></span>').text(record.body_preview));
            tr.append(title);
            if (inherited) {
                tr.append($('<td class="mainwp-knowledge-type-cell"></td>').append($('<span class="ui mini label"></span>').text(record.type_label)));
            }
            tr.append($('<td class="mainwp-knowledge-editor-cell"></td>')
                .append($('<span></span>').text(record.updated_by_name)).append($('<br>'))
                .append($('<span class="ui small text"></span>').text(record.updated_ago)));
            const actions = $('<td class="mainwp-knowledge-actions-cell right aligned"></td>');
            if (canEdit) {
                actions.append($('<button type="button" class="ui mini basic button mainwp-knowledge-edit"></button>').text(strings.edit)).append(' ');
                actions.append($('<button type="button" class="ui mini basic red button mainwp-knowledge-delete"></button>').text(strings.remove));
            } else {
                actions.append($('<button type="button" class="ui mini basic button mainwp-knowledge-view"></button>').text(strings.view));
            }
            return tr.append(actions);
        };
        const renderInherited = (inherited) => {
            if (!inherited) {
                inheritedBlock.hide().find('tbody').empty();
                return;
            }
            inheritedBlock.find('.mainwp-knowledge-inherited-title').text(strings.inheritedFrom.replace('%s', () => inherited.client_name));
            inheritedBlock.find('.mainwp-knowledge-inherited-link').attr('href', inherited.client_url);
            const body = inheritedBlock.find('tbody').empty();
            if (!inherited.records.length) {
                body.append($('<tr></tr>').append($('<td colspan="4"></td>').append($('<em></em>').text(strings.inheritedEmpty))));
            }
            inherited.records.forEach((record) => body.append(row(record, true)));
            inheritedBlock.show();
        };
        const render = (records, denied) => {
            panel.find('.mainwp-knowledge-section').each(function () {
                const section = $(this);
                const body = section.find('tbody').empty();
                const own = records.filter((record) => record.type === section.data('type'));
                if (!own.length) {
                    body.append($('<tr></tr>').append($('<td colspan="3"></td>').append($('<em></em>').text(denied ? strings.noAccess : strings.empty))));
                }
                own.forEach((record) => body.append(row(record)));
                section.find('.mainwp-knowledge-add').toggle(panelCanWrite);
            });
        };
        const load = (preserveMessage = false) => {
            loader.addClass('active').attr('aria-hidden', 'false');
            post('mainwp_knowledge_list', panelScope).done((response) => {
                if (response.success) {
                    panelCanWrite = !!response.data.can_write;
                    if (!preserveMessage) {
                        hideMessage(message);
                    }
                    render(response.data.records, false);
                    renderInherited(response.data.inherited);
                } else {
                    const code = response.data ? response.data.code : '';
                    if ('mainwp_knowledge_insecure' !== code && response.data && [403, 404].includes(response.data.status)) {
                        panelCanWrite = false;
                        render([], true);
                        renderInherited(null);
                    }
                    showMessage(message, errorText(response), 'yellow');
                }
            }).fail(() => showMessage(message, strings.failed, 'red')).always(() => {
                loader.removeClass('active').attr('aria-hidden', 'true');
            });
        };
        const panelOwner = { message: message, refresh: load };
        panel.on('click', '.mainwp-knowledge-add', function () {
            if (!panelCanWrite) {
                return;
            }
            begin(panelOwner);
            scope = $.extend({}, panelScope);
            canWrite = panelCanWrite;
            fill({ id: 0, revision: 0, type: $(this).data('type'), title: '', description: '', body: '' });
            openModal('add');
        });
        panel.on('click', '.mainwp-knowledge-edit, .mainwp-knowledge-view', function (event) {
            event.preventDefault();
            openRecord($(this).closest('tr').data('id'), $(this).hasClass('mainwp-knowledge-view') ? 'view' : 'edit', panelOwner);
        });
        panel.on('click', '.mainwp-knowledge-delete', function () {
            const tr = $(this).closest('tr');
            mainwp_confirm(strings.confirmDelete, () => {
                post('mainwp_knowledge_delete', { id: tr.data('id'), revision: tr.data('revision') }).done((response) => {
                    if (response.success) {
                        load();
                    } else {
                        showMessage(message, errorText(response), 'yellow');
                        load(true);
                    }
                }).fail(() => showMessage(message, strings.failed, 'red'));
            });
        });
        load();
    });

    const review = $('#mainwp-knowledge-review-table');
    if (review.length) {
        const message = $('#mainwp-knowledge-review-message');
        const loader = $('#mainwp-knowledge-review').children('.mainwp-knowledge-loader');
        const filters = $('#mainwp-knowledge-review-filters');
        let request = 0;
        const table = review.DataTable({
            serverSide: true,
            ordering: false,
            searching: false,
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            language: { emptyTable: review.data('empty-default'), zeroRecords: review.data('empty-default') },
            columns: ['level', 'type', 'title', 'status', 'editor', 'updated', 'actions'].map((key) => ({ data: key })),
            ajax: (data, callback, settings) => {
                const current = ++request;
                const status = $('#mainwp-knowledge-filter-status').val();
                const type = $('#mainwp-knowledge-filter-type').val();
                const level = $('#mainwp-knowledge-filter-level').val();
                const empty = review.data('unverified' === status && !type && !level ? 'empty-default' : 'empty-filtered');
                settings.oLanguage.sEmptyTable = settings.oLanguage.sZeroRecords = empty;
                loader.addClass('active').attr('aria-hidden', 'false');
                post('mainwp_knowledge_review', $.extend({}, data, { status: status, type: type, level: level })).done((response) => {
                    if (current !== request) {
                        return;
                    }
                    if (response.success === false) {
                        showMessage(message, errorText(response), 'yellow');
                        settings.oLanguage.sEmptyTable = settings.oLanguage.sZeroRecords = errorText(response);
                        callback({ draw: data.draw, data: [], recordsTotal: 0, recordsFiltered: 0 });
                        return;
                    }
                    hideMessage(message);
                    callback(response);
                }).fail(() => {
                    if (current === request) {
                        showMessage(message, strings.failed, 'red');
                        settings.oLanguage.sEmptyTable = settings.oLanguage.sZeroRecords = strings.failed;
                        callback({ draw: data.draw, data: [], recordsTotal: 0, recordsFiltered: 0 });
                    }
                }).always(() => {
                    if (current === request) {
                        loader.removeClass('active').attr('aria-hidden', 'true');
                    }
                });
            },
        });
        filters.find('select').dropdown().on('change', () => table.ajax.reload());
        review.on('click', '.mainwp-knowledge-open', function () {
            openRecord($(this).data('id'), 'edit', { message: message, refresh: (preserveMessage) => {
                if (!preserveMessage) {
                    hideMessage(message);
                }
                table.ajax.reload();
            } });
        });
    }

    modal.modal({ closable: false, onHide: () => {
        ++session;
        owner = null;
        scope = null;
        canWrite = false;
        readOnly = true;
    } });
    saveButton.on('click', save);
    deleteButton.on('click', () => {
        if (readOnly || !canWrite || deleteButton.hasClass('disabled')) {
            return;
        }
        const current = session;
        const previousOwner = owner;
        const data = { id: idField.val(), revision: revisionField.val() };
        mainwp_confirm(strings.confirmDelete, () => {
            if (current !== session) {
                return;
            }
            deleteButton.prop('disabled', true).addClass('loading disabled');
            saveButton.prop('disabled', true).addClass('disabled');
            post('mainwp_knowledge_delete', data).done((response) => {
                if (current !== session) {
                    return;
                }
                if (response.success) {
                    modal.modal('hide');
                    previousOwner.refresh();
                } else if (response.data && response.data.code === 'mainwp_knowledge_conflict') {
                    deleteButton.removeClass('loading');
                    showConflict(response.data.message);
                } else {
                    showMessage(modalMessage, errorText(response), 'red');
                    deleteButton.prop('disabled', false).removeClass('loading disabled');
                    saveButton.prop('disabled', false).removeClass('disabled');
                }
            }).fail(() => {
                if (current === session) {
                    showMessage(modalMessage, strings.failed, 'red');
                    deleteButton.prop('disabled', false).removeClass('loading disabled');
                    saveButton.prop('disabled', false).removeClass('disabled');
                }
            });
        }, false, false, true);
    });
    typeField.on('change', updateFields);
    typeField.closest('.dropdown').dropdown();
    requiredField.closest('.checkbox').checkbox();
});
