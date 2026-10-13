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
    const requiredHelp = $('#mainwp-knowledge-required-help');
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
        const eligible = scope && ['agency', 'client'].includes(scope.scope_type) && ['context', 'skill'].includes(typeField.val());
        $('#mainwp-knowledge-required-field').toggle(!!eligible && canWrite && !readOnly);
        requiredHelp.toggle(!!eligible && canWrite && !readOnly);
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
        if (['agency', 'client'].includes(scope.scope_type)) {
            data.required = ['context', 'skill'].includes(data.type) && requiredField.prop('checked') ? 1 : 0;
        }
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
                previousOwner.refresh();
                return;
            }
            if (current !== session) {
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

    const reviewLevel = (record, classes) => {
        const level = record.level_url ? $('<a></a>').attr('href', record.level_url) : $('<span></span>');
        if (record.level_url) {
            level.on('click keydown', (event) => event.stopPropagation());
        }
        return level.addClass(classes).text(record.level_label || '');
    };
    const recordPair = (record, canEdit = false, siteUrl = '', options = {}) => {
        const recordCanEdit = options.review ? !!record.can_write : canEdit;
        const title = $('<div class="title mainwp-knowledge-record-title"></div>')
            .attr('data-id', record.id)
            .attr('data-revision', record.revision)
            .data('can-edit', recordCanEdit)
            .attr({ role: 'button', tabindex: '0', 'aria-expanded': 'false' });
        const requiredEligible = ['agency', 'client'].includes(record.scope_type) && ['context', 'skill'].includes(record.type);
        if (requiredEligible) {
            const checkboxId = `mainwp-knowledge-required-record-${record.id}`;
            const required = $('<span class="mainwp-knowledge-required-indicator"></span>').on('click keydown', (event) => event.stopPropagation());
            const checkbox = $('<span class="ui checkbox"></span>').toggleClass('disabled', !recordCanEdit);
            checkbox.append($('<input type="checkbox" class="mainwp-knowledge-required-toggle">').attr('id', checkboxId).prop('checked', !!record.required).prop('disabled', !recordCanEdit));
            checkbox.append($('<label></label>').attr('for', checkboxId).text(strings.required));
            title.append(required.append(checkbox));
        }
        if (!record.verified) {
            title.append($('<span class="ui mini yellow label"></span>').attr('data-tooltip', strings.unverifiedHint).attr('data-inverted', '').text(strings.unverified));
        } else if (options.review) {
            title.append($('<span class="ui mini green label"></span>').text(strings.verified));
        }
        title.append($('<span class="mainwp-knowledge-record-name"></span>').text(record.title));
        if (options.review && record.level_label) {
            title.append(reviewLevel(record, 'ui mini basic label mainwp-knowledge-record-level'));
        }
        title.append($('<i class="chevron right grey icon mainwp-knowledge-record-chevron"></i>').attr('aria-hidden', 'true'));

        const content = $('<div class="content mainwp-knowledge-record-content"></div>')
            .attr('data-id', record.id)
            .attr('data-revision', record.revision)
            .attr('aria-hidden', 'true')
            .data('can-edit', !!recordCanEdit)
            .data('review', !!options.review)
            .attr('data-site-url', siteUrl || '');
        content.append($('<div class="ui active centered inline loader"></div>'));
        return [title, content];
    };
    const loadRecordDetails = (content) => {
        if (content.data('loaded') || content.data('loading')) {
            return;
        }
        content.data('loading', true);
        post('mainwp_knowledge_get', { id: content.data('id') }).done((response) => {
            if (!response.success) {
                content.empty().append($('<div class="ui yellow message"></div>').text(errorText(response)));
                return;
            }
            const record = response.data.record;
            const details = $('<div class="mainwp-knowledge-record-details"></div>');
            const recordCards = $('<div class="ui one cards mainwp-knowledge-record-card"></div>');
            const recordCard = $('<div class="card"></div>');
            if (content.data('review') && record.level_label) {
                recordCard.append($('<div class="content mainwp-knowledge-record-heading"></div>')
                    .append($('<div class="ui small header"></div>').append(reviewLevel(record, 'mainwp-knowledge-record-level-link'))));
            }
            const bodyContent = $('<div class="content mainwp-knowledge-record-body"></div>')
                .attr({ role: 'region', tabindex: '0', 'aria-label': strings.body });
            bodyContent.append($('<div class="description mainwp-knowledge-record-value"></div>').text(record.body || ''));
            const descriptionContent = $('<div class="extra content mainwp-knowledge-record-description"></div>');
            if ('skill' === record.type) {
                descriptionContent
                    .append($('<div class="ui tiny header"></div>').text(strings.description))
                    .append($('<div class="mainwp-knowledge-record-value"></div>').text(record.description || ''));
            }
            const footerContent = $('<div class="extra content mainwp-knowledge-record-footer"></div>');
            const actions = $('<div class="ui two column middle aligned grid mainwp-knowledge-record-actions"></div>');
            const leftActions = $('<div class="eight wide left aligned column"></div>');
            const rightActions = $('<div class="eight wide right aligned column"></div>');
            leftActions.append($('<span class="ui small text mainwp-knowledge-record-meta"></span>').text(`${strings.editedBy} ${record.updated_by_name} ${record.updated_ago}`));
            const inReadOnlyWidget = !!content.closest('.mainwp-knowledge-read-only-widget').length;
            const canEdit = !inReadOnlyWidget && !!content.data('can-edit') && !!response.data.can_write;
            if (canEdit) {
                if (content.data('review') && !record.verified) {
                    rightActions.append($('<button type="button" class="ui mini green button mainwp-knowledge-verify"></button>').text(strings.verify));
                }
                rightActions.append($('<button type="button" class="ui mini basic button mainwp-knowledge-edit"></button>').text(strings.edit));
                rightActions.append($('<button type="button" class="ui mini basic red button mainwp-knowledge-delete"></button>').text(strings.remove));
            } else if (!inReadOnlyWidget) {
                rightActions.append($('<button type="button" class="ui mini basic button mainwp-knowledge-view"></button>').text(strings.view));
            }
            const siteUrl = content.attr('data-site-url');
            if (siteUrl) {
                rightActions.append($('<a class="ui mini basic button"></a>').attr('href', siteUrl).text(strings.viewOnSite));
            }
            footerContent.append(actions.append(leftActions, rightActions));
            if ('skill' === record.type) {
                recordCard.append(descriptionContent);
            }
            recordCard.append(bodyContent);
            recordCard.append(footerContent);
            recordCards.append(recordCard);
            details.append(recordCards);
            content.empty().append(details).data('loaded', true);
        }).fail(() => {
            content.empty().append($('<div class="ui red message"></div>').text(strings.failed));
        }).always(() => content.data('loading', false));
    };
    const initializeRecordAccordions = (container) => {
        container.find('.mainwp-knowledge-records').each(function () {
            const accordion = $(this);
            accordion.accordion({
                exclusive: false,
                onOpening: function () {
                    $(this).attr('aria-hidden', 'false').prev('.mainwp-knowledge-record-title').attr('aria-expanded', 'true');
                    loadRecordDetails($(this));
                },
                onClosing: function () {
                    $(this).attr('aria-hidden', 'true').prev('.mainwp-knowledge-record-title').attr('aria-expanded', 'false');
                },
            });
            // Do not let the surrounding Client Edit accordion process this nested accordion click too.
            accordion.off('click.mainwpKnowledge').on('click.mainwpKnowledge', '.mainwp-knowledge-record-title', (event) => event.stopPropagation());
            accordion.off('keydown.mainwpKnowledge').on('keydown.mainwpKnowledge', '.mainwp-knowledge-record-title', function (event) {
                if ($(event.target).is('input, label, button, a')) {
                    return;
                }
                if (['Enter', ' '].includes(event.key)) {
                    event.preventDefault();
                    $(this).trigger('click');
                }
            });
        });
    };
    const renderRecordSet = (target, records, emptyText, canEdit = false, siteUrl = '', options = {}) => {
        const placeholder = target.siblings('.mainwp-knowledge-empty');
        const sectionBox = target.closest('.mainwp-knowledge-section-box');
        const footer = sectionBox.children('.mainwp-widget-footer');
        const addButton = sectionBox.find('.mainwp-knowledge-add').first();
        target.empty();
        placeholder.hide().attr('aria-hidden', 'true');
        if (!records.length) {
            if (placeholder.length && (emptyText === strings.empty || options.usePlaceholder)) {
                target.hide().attr('aria-hidden', 'true');
                placeholder.show().attr('aria-hidden', 'false');
                addButton.appendTo(placeholder.find('.mainwp-empty-widget-placeholder'));
                footer.hide();
            } else {
                target.show().attr('aria-hidden', 'false').append($('<div class="mainwp-knowledge-empty"></div>').append($('<em></em>').text(emptyText)));
                footer.hide();
            }
        } else {
            target.show().attr('aria-hidden', 'false');
            addButton.appendTo(footer);
            footer.toggle(canEdit);
            records.forEach((record) => {
                const pair = recordPair(record, canEdit, siteUrl, options);
                target.append(pair[0], pair[1]);
            });
        }
        initializeRecordAccordions(target.parent());
    };
    const recordContainer = (element) => element.closest('[data-id]');
    const updateRequired = (checkbox, message, refresh) => {
        const checkboxUi = checkbox.closest('.ui.checkbox');
        const record = checkbox.closest('.mainwp-knowledge-record-title');
        const next = checkbox.prop('checked');
        const restore = () => {
            checkbox.prop('checked', !next).prop('disabled', false);
            checkboxUi.removeClass('disabled').removeAttr('aria-busy');
        };
        if (!record.data('can-edit') || checkbox.prop('disabled')) {
            restore();
            return;
        }
        checkbox.prop('disabled', true);
        checkboxUi.addClass('disabled').attr('aria-busy', 'true');
        post('mainwp_knowledge_required', { id: record.data('id'), revision: record.data('revision'), required: next ? 1 : 0 }).done((response) => {
            if (response.success) {
                checkbox.prop('disabled', false);
                checkboxUi.removeClass('disabled').removeAttr('aria-busy');
                refresh();
                return;
            }
            restore();
            showMessage(message, errorText(response), 'yellow');
            if (response.data && ['mainwp_knowledge_conflict', 'mainwp_knowledge_not_found'].includes(response.data.code)) {
                refresh(true);
            }
        }).fail(() => {
            restore();
            showMessage(message, strings.failed, 'red');
        });
    };

    $('.mainwp-knowledge-panel').each(function () {
        const panel = $(this);
        const panelScope = recordScope({ scope_type: panel.data('scope-type'), scope_id: panel.data('scope-id') });
        const panelReadOnly = '1' === panel.attr('data-read-only') || !!panel.closest('.mainwp-knowledge-read-only-widget').length;
        if (panelReadOnly) {
            panelScope.read_only = 1;
        }
        const message = panel.find('.mainwp-knowledge-message');
        const loader = panel.children('.mainwp-knowledge-loader');
        const inheritedBlock = panel.find('.mainwp-knowledge-inherited');
        let panelCanWrite = false;
        const renderInherited = (inherited) => {
            if (!inherited) {
                inheritedBlock.hide().find('.mainwp-knowledge-records').empty();
                return;
            }
            inheritedBlock.find('.mainwp-knowledge-inherited-title').text(strings.inheritedFrom.replace('%s', () => inherited.client_name));
            inheritedBlock.find('.mainwp-knowledge-inherited-link').attr('href', inherited.client_url);
            inheritedBlock.find('.mainwp-knowledge-inherited-empty').toggle(!inherited.records.length).text(inherited.records.length ? '' : strings.inheritedEmpty);
            inheritedBlock.find('.mainwp-knowledge-inherited-sections').toggle(!!inherited.records.length);
            inheritedBlock.find('.mainwp-knowledge-inherited-section').each(function () {
                const section = $(this);
                const records = inherited.records.filter((record) => record.type === section.data('type'));
                renderRecordSet(section.find('.mainwp-knowledge-records'), records, strings.empty);
            });
            inheritedBlock.show();
        };
        const render = (records, denied) => {
            panel.find('.mainwp-knowledge-section').each(function () {
                const section = $(this);
                const own = records.filter((record) => record.type === section.data('type'));
                renderRecordSet(section.find('.mainwp-knowledge-records'), own, denied ? strings.noAccess : strings.empty, panelCanWrite);
                section.find('.mainwp-knowledge-add').toggle(panelCanWrite);
            });
        };
        const load = (preserveMessage = false) => {
            loader.addClass('active').attr('aria-hidden', 'false');
            post('mainwp_knowledge_list', panelScope).done((response) => {
                if (response.success) {
                    panelCanWrite = !panelReadOnly && !!response.data.can_write;
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
        panel.on('change', '.mainwp-knowledge-required-toggle', function () {
            if (panelReadOnly) {
                return;
            }
            updateRequired($(this), message, load);
        });
        panel.on('click', '.mainwp-knowledge-add', function () {
            if (panelReadOnly || !panelCanWrite) {
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
            openRecord(recordContainer($(this)).data('id'), panelReadOnly || $(this).hasClass('mainwp-knowledge-view') ? 'view' : 'edit', panelOwner);
        });
        panel.on('click', '.mainwp-knowledge-delete', function () {
            if (panelReadOnly) {
                return;
            }
            const record = recordContainer($(this));
            mainwp_confirm(strings.confirmDelete, () => {
                post('mainwp_knowledge_delete', { id: record.data('id'), revision: record.data('revision') }).done((response) => {
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

    $('.mainwp-knowledge-site-list').each(function () {
        const list = $(this);
        const siteScope = recordScope({ scope_type: 'site', scope_id: list.data('scope-id') });
        const message = list.children('.mainwp-knowledge-message');
        const loader = list.children('.mainwp-knowledge-loader');
        const sections = list.find('.mainwp-knowledge-site-sections');
        const empty = list.find('.mainwp-knowledge-site-empty');
        let request = 0;
        const load = (preserveMessage = false) => {
            const current = ++request;
            list.find('.mainwp-knowledge-records').empty();
            loader.addClass('active').attr('aria-hidden', 'false');
            post('mainwp_knowledge_list', $.extend({}, siteScope, { read_only: 1 })).done((response) => {
                if (current !== request) {
                    return;
                }
                if (!response.success) {
                    showMessage(message, errorText(response), 'yellow');
                    return;
                }
                if (!preserveMessage) {
                    hideMessage(message);
                }
                const hasRecords = !!response.data.records.length;
                empty.toggle(!hasRecords).text(hasRecords ? '' : strings.noRecords);
                sections.toggle(hasRecords);
                list.find('.mainwp-knowledge-site-section').each(function () {
                    const section = $(this);
                    const records = response.data.records.filter((record) => record.type === section.data('type'));
                    renderRecordSet(section.find('.mainwp-knowledge-records'), records, strings.empty, false, list.data('site-url'));
                });
            }).fail(() => {
                if (current === request) {
                    showMessage(message, strings.failed, 'red');
                }
            }).always(() => {
                if (current === request) {
                    loader.removeClass('active').attr('aria-hidden', 'true');
                }
            });
        };
        const siteOwner = { message: message, refresh: load };
        list.data('knowledge-owner', siteOwner);
        list.on('click', '.mainwp-knowledge-view', function (event) {
            event.preventDefault();
            openRecord(recordContainer($(this)).data('id'), 'view', siteOwner);
        });
    });
    $('.mainwp-knowledge-site-accordion').each(function () {
        const accordion = $(this);
        accordion.find('tr.title a').on('click', (event) => event.stopPropagation());
        accordion.accordion({
            exclusive: false,
            onOpen: function () {
                const siteOwner = $(this).find('.mainwp-knowledge-site-list').data('knowledge-owner');
                if (siteOwner) {
                    siteOwner.refresh();
                }
            },
        });
    });

    const review = $('#mainwp-knowledge-review');
    if (review.length) {
        const message = $('#mainwp-knowledge-review-message');
        const emptyState = $('#mainwp-knowledge-review-empty');
        const filteredEmpty = $('#mainwp-knowledge-review-filtered-empty');
        const sections = review.find('.mainwp-knowledge-review-sections');
        const loader = review.children('.mainwp-knowledge-loader');
        const filters = $('#mainwp-knowledge-review-filters');
        let request = 0;
        const clearRecords = () => review.find('.mainwp-knowledge-records').empty();
        const renderRecords = (records) => {
            review.find('.mainwp-knowledge-review-section').each(function () {
                const section = $(this);
                const typeRecords = records.filter((record) => record.type === section.data('type'));
                renderRecordSet(section.find('.mainwp-knowledge-records'), typeRecords, strings.noRecords, true, '', { review: true, usePlaceholder: true });
            });
        };
        const load = (preserveMessage = false) => {
            const current = ++request;
            const data = {
                draw: current,
                status: $('#mainwp-knowledge-filter-status').val(),
                level: $('#mainwp-knowledge-filter-level').val(),
            };
            loader.addClass('active').attr('aria-hidden', 'false');
            post('mainwp_knowledge_review', data).done((response) => {
                if (current !== request) {
                    return;
                }
                if (response.success === false) {
                    showMessage(message, errorText(response), 'yellow');
                    clearRecords();
                    return;
                }
                if (!preserveMessage) {
                    hideMessage(message);
                }
                const total = Number(response.recordsTotal);
                const filtered = Number(response.recordsFiltered);
                const hasRecords = total > 0;
                const hasFilteredRecords = filtered > 0;
                filters.toggleClass('hidden', !hasRecords).attr('aria-hidden', hasRecords ? 'false' : 'true');
                review.toggleClass('hidden', !hasRecords).attr('aria-hidden', hasRecords ? 'false' : 'true');
                emptyState.toggleClass('hidden', hasRecords).attr('aria-hidden', hasRecords ? 'true' : 'false');
                sections.toggleClass('hidden', !hasFilteredRecords).attr('aria-hidden', hasFilteredRecords ? 'false' : 'true');
                filteredEmpty.toggleClass('hidden', !hasRecords || hasFilteredRecords).attr('aria-hidden', hasRecords && !hasFilteredRecords ? 'false' : 'true');
                clearRecords();
                if (hasFilteredRecords) {
                    renderRecords(response.data);
                }
            }).fail(() => {
                if (current === request) {
                    showMessage(message, strings.failed, 'red');
                    clearRecords();
                }
            }).always(() => {
                if (current === request) {
                    loader.removeClass('active').attr('aria-hidden', 'true');
                }
            });
        };
        const reviewOwner = { message: message, refresh: load };
        filters.find('select').dropdown().on('change', () => load());
        review.on('change', '.mainwp-knowledge-required-toggle', function () {
            updateRequired($(this), message, load);
        });
        review.on('click', '.mainwp-knowledge-edit, .mainwp-knowledge-view', function (event) {
            event.preventDefault();
            openRecord(recordContainer($(this)).data('id'), $(this).hasClass('mainwp-knowledge-view') ? 'view' : 'edit', reviewOwner);
        });
        review.on('click', '.mainwp-knowledge-verify', function () {
            const button = $(this);
            const record = recordContainer(button);
            button.prop('disabled', true).addClass('loading disabled');
            post('mainwp_knowledge_verify', { id: record.data('id'), revision: record.data('revision') }).done((response) => {
                if (response.success) {
                    load();
                } else {
                    showMessage(message, errorText(response), 'yellow');
                    load(true);
                }
            }).fail(() => {
                button.prop('disabled', false).removeClass('loading disabled');
                showMessage(message, strings.failed, 'red');
            });
        });
        review.on('click', '.mainwp-knowledge-delete', function () {
            const record = recordContainer($(this));
            mainwp_confirm(strings.confirmDelete, () => {
                post('mainwp_knowledge_delete', { id: record.data('id'), revision: record.data('revision') }).done((response) => {
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
                if (response.success) {
                    if (current === session) {
                        modal.modal('hide');
                    }
                    previousOwner.refresh();
                    return;
                }
                if (current !== session) {
                    return;
                }
                if (response.data && response.data.code === 'mainwp_knowledge_conflict') {
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
