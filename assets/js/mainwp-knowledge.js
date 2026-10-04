/* global jQuery, ajaxurl, mainwp_secure_data, mainwp_confirm */

/**
 * Knowledge panel: Context, Skills and Memory records for one site or client.
 *
 * The list, the editor and the deletes all go through the four
 * mainwp_knowledge_* handlers; the panel never renders records from PHP.
 */
jQuery(function ($) {
    const panel = $('#mainwp-knowledge-panel');
    if (!panel.length) {
        return;
    }

    const scope = {
        scope_type: panel.data('scope-type'),
        scope_id: parseInt(panel.data('scope-id'), 10),
    };
    const modal = $('#mainwp-knowledge-modal');
    const modalHeader = modal.find('.header');
    const modalMessage = $('#mainwp-knowledge-modal-message');
    const panelMessage = $('#mainwp-knowledge-message');
    const panelLoader = panel.children('.mainwp-knowledge-loader');
    const inheritedBlock = $('#mainwp-knowledge-inherited');
    const typeField = $('#mainwp-knowledge-type');
    const typeViewField = $('#mainwp-knowledge-type-view');
    const titleField = $('#mainwp-knowledge-title');
    const descriptionField = $('#mainwp-knowledge-description');
    const bodyField = $('#mainwp-knowledge-body');
    const idField = $('#mainwp-knowledge-id');
    const revisionField = $('#mainwp-knowledge-revision');
    const saveButton = $('#mainwp-knowledge-save');
    const cancelButton = modal.find('.actions .cancel.button');
    const strings = window.mainwpKnowledgeStrings || {};

    let canWrite = false;
    // True while the modal shows a record to read. save() checks it, so the read-only modal has no
    // submit path even if something other than the hidden Save button calls it.
    let readOnly = false;
    // Bumped when the user starts an add, an edit or a view, before any request: a response from an earlier
    // session must not touch the current editor, or a late Edit response could turn an Add into an update.
    let session = 0;

    const showMessage = (target, text, color) => {
        target.removeClass('hidden red green yellow').addClass(color).empty().append(
            $('<p></p>').text(text)
        );
    };

    const hideMessage = (target) => {
        target.addClass('hidden').removeClass('red green yellow').empty();
    };

    const post = (action, data) => $.post(ajaxurl, mainwp_secure_data($.extend({ action: action }, scope, data)), null, 'json');

    const errorText = (response) => (response && response.data && response.data.message) ? response.data.message : strings.failed;

    const row = (record, inherited = false) => {
        const tr = $('<tr></tr>').attr('data-id', record.id).attr('data-revision', record.revision);
        const canEdit = canWrite && !inherited;
        const title = $('<td></td>');
        if (!record.verified) {
            title.append(
                $('<span class="ui mini yellow label"></span>').attr('data-tooltip', strings.unverifiedHint).attr('data-inverted', '').text(strings.unverified)
            ).append(' ');
        }
        title.append($('<a href="#"></a>').addClass(canEdit ? 'mainwp-knowledge-edit' : 'mainwp-knowledge-view').text(record.title));
        title.append($('<br>')).append($('<span class="ui small text"></span>').text(record.body_preview));
        tr.append(title);
        if (inherited) {
            tr.append($('<td class="mainwp-knowledge-type-cell"></td>').append($('<span class="ui mini label"></span>').text(record.type_label)));
        }
        tr.append(
            $('<td class="mainwp-knowledge-editor-cell"></td>')
                .append($('<span></span>').text(record.updated_by_name))
                .append($('<br>'))
                .append($('<span class="ui small text"></span>').text(record.updated_ago))
        );
        const actions = $('<td class="mainwp-knowledge-actions-cell right aligned"></td>');
        // Inherited client records are edited on the client profile, whatever the caller may write here.
        if (canEdit) {
            actions.append($('<button type="button" class="ui mini basic button mainwp-knowledge-edit"></button>').text(strings.edit));
            actions.append(' ');
            actions.append($('<button type="button" class="ui mini basic red button mainwp-knowledge-delete"></button>').text(strings.remove));
        } else {
            actions.append($('<button type="button" class="ui mini basic button mainwp-knowledge-view"></button>').text(strings.view));
        }
        tr.append(actions);
        return tr;
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

    // A denied scope and an empty scope look different on purpose: an admin
    // signed in under the wrong account should not read "nothing recorded".
    const render = (records, denied) => {
        panel.find('.mainwp-knowledge-section').each(function () {
            const section = $(this);
            const type = section.data('type');
            const body = section.find('tbody').empty();
            const own = records.filter((record) => record.type === type);
            if (!own.length) {
                body.append($('<tr></tr>').append($('<td colspan="3"></td>').append($('<em></em>').text(denied ? strings.noAccess : strings.empty))));
            }
            own.forEach((record) => body.append(row(record)));
            section.find('.mainwp-knowledge-add').toggle(canWrite);
        });
    };

    const load = (preserveMessage = false) => {
        panelLoader.addClass('active').attr('aria-hidden', 'false');
        post('mainwp_knowledge_list', {}).done((response) => {
            if (response.success) {
                canWrite = !!response.data.can_write;
                if (!preserveMessage) {
                    hideMessage(panelMessage);
                }
                render(response.data.records, false);
                renderInherited(response.data.inherited);
            } else {
                // Only an access answer reads as "no permission"; an expired nonce or a server error leaves the rows as they were.
                const code = response.data ? response.data.code : '';
                if ('mainwp_knowledge_insecure' !== code && response.data && [403, 404].includes(response.data.status)) {
                    canWrite = false;
                    render([], true);
                    renderInherited(null);
                }
                showMessage(panelMessage, errorText(response), 'yellow');
            }
        }).fail(() => {
            showMessage(panelMessage, strings.failed, 'red');
        }).always(() => {
            panelLoader.removeClass('active').attr('aria-hidden', 'true');
        });
    };

    const updateDescription = () => {
        const isSkill = 'skill' === typeField.val();
        descriptionField.closest('.field').toggle(isSkill).toggleClass('required', isSkill && !readOnly);
        descriptionField.prop('required', isSkill && !readOnly).prop('readonly', readOnly);
        $('#mainwp-knowledge-description-help').toggle(!readOnly);
    };

    const fill = (record) => {
        idField.val(record.id);
        revisionField.val(record.revision);
        typeField.val(record.type);
        typeField.closest('.dropdown').dropdown('set selected', record.type);
        titleField.val(record.title);
        descriptionField.val(record.description || '');
        updateDescription();
        bodyField.val(record.body);
    };

    const fillView = (record) => {
        // The read-only modal keeps no id and no revision: it has nothing to send back.
        fill($.extend({}, record, { id: 0, revision: 0 }));
        typeViewField.val(record.type_label);
    };

    // Every open sets the whole state, so a view after an edit (or the reverse) inherits nothing.
    const openModal = (mode) => {
        readOnly = 'view' === mode;
        hideMessage(modalMessage);
        titleField.prop('readonly', readOnly);
        bodyField.prop('readonly', readOnly);
        updateDescription();
        typeField.closest('.field').toggle(!readOnly);
        typeViewField.closest('.field').toggle(readOnly);
        saveButton.toggle(!readOnly).prop('disabled', readOnly).toggleClass('disabled', readOnly).removeClass('loading');
        cancelButton.text(cancelButton.data(readOnly ? 'close' : 'cancel'));
        modalHeader.text(modalHeader.data(mode));
        modal.modal({ closable: false }).modal('show');
    };

    const openAdd = (type) => {
        session++;
        fill({ id: 0, revision: 0, type: type, title: '', description: '', body: '' });
        openModal('add');
    };

    // The mode comes from the button that was clicked, never from canWrite: after a failed list call
    // the flag can be stale, and a View must not open as an editor.
    const openRecord = (id, mode) => {
        const current = ++session;
        post('mainwp_knowledge_get', { id: id }).done((response) => {
            if (current !== session) {
                return;
            }
            if (!response.success) {
                showMessage(panelMessage, errorText(response), 'yellow');
                load(true);
                return;
            }
            if ('view' === mode) {
                fillView(response.data.record);
            } else {
                fill(response.data.record);
            }
            openModal(mode);
        }).fail(() => {
            if (current === session) {
                showMessage(panelMessage, strings.failed, 'red');
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
                    modal.modal('hide');
                    showMessage(panelMessage, errorText(response), 'yellow');
                    load(true);
                    return;
                }
                // Save stays disabled after a conflict until a reload succeeds, so keep Reload on screen to retry.
                showConflict(errorText(response));
                return;
            }
            fill(response.data.record);
            showMessage(modalMessage, strings.reloaded, 'yellow');
            saveButton.prop('disabled', false).removeClass('loading disabled');
        }).fail(() => {
            if (current === session) {
                showConflict(strings.failed);
            }
        });
    };

    const showConflict = (message) => {
        showMessage(modalMessage, message || strings.conflict, 'yellow');
        modalMessage.append(
            $('<p></p>').append(
                $('<button type="button" class="ui mini basic button"></button>').text(strings.reload).on('click', reloadIntoEditor)
            )
        );
    };

    const save = () => {
        if (readOnly || saveButton.hasClass('disabled')) {
            return;
        }
        if ('skill' === typeField.val() && !descriptionField[0].reportValidity()) {
            return;
        }
        const current = session;
        hideMessage(modalMessage);
        saveButton.prop('disabled', true).addClass('loading disabled');
        const data = {
            type: typeField.val(),
            title: titleField.val(),
            description: 'skill' === typeField.val() ? descriptionField.val() : '',
            body: bodyField.val(),
        };
        const id = parseInt(idField.val(), 10);
        if (id > 0) {
            data.id = id;
            data.revision = revisionField.val();
        }
        post('mainwp_knowledge_save', data).done((response) => {
            if (response.success) {
                if (current === session) {
                    modal.modal('hide');
                }
                load();
                return;
            }
            if (current !== session) {
                return;
            }
            if (response.data && response.data.code === 'mainwp_knowledge_conflict') {
                saveButton.prop('disabled', true).addClass('disabled').removeClass('loading');
                showConflict(response.data.message);
                return;
            }
            showMessage(modalMessage, errorText(response), 'red');
            saveButton.prop('disabled', false).removeClass('loading disabled');
            if (response.data && response.data.code === 'mainwp_knowledge_not_found') {
                load();
            }
        }).fail(() => {
            if (current !== session) {
                return;
            }
            showMessage(modalMessage, strings.failed, 'red');
            saveButton.prop('disabled', false).removeClass('loading disabled');
        });
    };

    const remove = (id, revision) => {
        mainwp_confirm(strings.confirmDelete, () => {
            post('mainwp_knowledge_delete', { id: id, revision: revision }).done((response) => {
                if (response.success) {
                    load();
                } else {
                    showMessage(panelMessage, errorText(response), 'yellow');
                    load(true);
                }
            }).fail(() => {
                showMessage(panelMessage, strings.failed, 'red');
            });
        });
    };

    panel.on('click', '.mainwp-knowledge-add', function () {
        openAdd($(this).data('type'));
    });
    panel.on('click', '.mainwp-knowledge-edit', function (event) {
        event.preventDefault();
        openRecord($(this).closest('tr').data('id'), 'edit');
    });
    panel.on('click', '.mainwp-knowledge-view', function (event) {
        event.preventDefault();
        openRecord($(this).closest('tr').data('id'), 'view');
    });
    panel.on('click', '.mainwp-knowledge-delete', function () {
        const tr = $(this).closest('tr');
        remove(tr.data('id'), tr.data('revision'));
    });
    saveButton.on('click', save);

    typeField.on('change', updateDescription);
    typeField.closest('.dropdown').dropdown();
    load();
});
