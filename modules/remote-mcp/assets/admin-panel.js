/**
 * Remote AI connections panel: the app pickers, the Advanced tabs, confirmations, and controls
 * that save on change.
 * Every control posts the real form the page renders, so the server side is the same with or
 * without this file.
 *
 * @package MainWP\Dashboard
 */

jQuery(function ($) {
    const panel = $('#mainwp-remote-mcp-panel');

    if (!panel.length) {
        return;
    }

    const submit = (form) => form.get(0).submit();

    // One confirmation at a time. A modal closed any way other than its approve button cancels.
    let pending = null;
    $('.mainwp-rmcp-confirm').modal({
        onApprove: function () {
            const form = pending ? pending.form : null;
            pending = null;
            if (form) {
                submit(form);
            }
        },
        onHidden: function () {
            if (pending && pending.onCancel) {
                pending.onCancel();
            }
            pending = null;
        },
    });

    const confirmThenSubmit = (form, onCancel) => {
        const modal = form.data('confirm');
        if (!modal) {
            submit(form);
            return;
        }
        pending = { form: form, onCancel: onCancel };
        $(modal).modal('show');
    };

    panel.on('submit', 'form[data-confirm]', function (event) {
        event.preventDefault();
        confirmThenSubmit($(this));
    });

    // Each picker shows the one block of its group whose data-app matches the choice.
    panel.find('select[data-rmcp-picker]').each(function () {
        const group = $(this).data('rmcp-picker');
        const secret = $(this).closest('.mainwp-rmcp-secret');
        const scope = secret.length ? secret : panel;
        $(this).dropdown({
            onChange: function (value) {
                scope.find('.' + group).each(function () {
                    $(this).prop('hidden', $(this).data('app') !== value);
                });
            },
        });
    });

    // Each section owns only its heading. Animating child elements would reveal inactive tabs.
    panel.children('.mainwp-rmcp-section').each(function () {
        const section = $(this);
        const title = section.children('.mainwp-rmcp-section-title');
        const content = section.children('.mainwp-rmcp-section-content');
        const page = typeof pagenow !== 'undefined' ? pagenow : 'mainwp-mcp-direct';
        const stateKey = 'mainwp-accordion[' + page + '][' + this.id + ']';
        const saveState = (open) => {
            try {
                window.localStorage.setItem(stateKey, open ? '1' : '0');
            } catch {
                // Browser storage is optional; the accordion still works without it.
            }
        };
        let savedState = null;
        try {
            savedState = window.localStorage.getItem(stateKey);
        } catch {
            // Keep the server-rendered default when browser storage is unavailable.
        }
        // A form result deliberately reveals its Advanced tab. Ordinary visits restore memory.
        const revealResult = section.is('#mainwp-rmcp-advanced-accordion') && content.hasClass('active');
        if (!revealResult && (savedState === '0' || savedState === '1')) {
            const open = savedState === '1';
            title.toggleClass('active', open).attr('aria-expanded', open ? 'true' : 'false');
            content.toggleClass('active', open);
        }
        section.accordion({
            exclusive: false,
            animateChildren: false,
            selector: {
                title: '.mainwp-rmcp-section-title',
                trigger: '.mainwp-rmcp-section-title',
                content: '.mainwp-rmcp-section-content',
                ignore: '.ui.dropdown, a',
            },
            onOpening: () => saveState(true),
            onClosing: () => saveState(false),
            onOpen: () => title.attr('aria-expanded', 'true'),
            onClose: () => title.attr('aria-expanded', 'false'),
        });
        title.on('keydown', function (event) {
            if (event.target === this && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                section.accordion('toggle', 0);
            }
        });
    });

    const advanced = $('#mainwp-rmcp-advanced');
    const advancedAccordion = $('#mainwp-rmcp-advanced-accordion');
    const tabs = $('#mainwp-rmcp-advanced-menu > .item');
    tabs.tab({
        context: advanced,
        childrenOnly: true,
        onVisible: function (tabPath) {
            tabs.each(function () {
                const active = $(this).data('tab') === tabPath;
                $(this).attr({ 'aria-selected': active ? 'true' : 'false', tabindex: active ? '0' : '-1' });
            });
        },
    });

    tabs.on('keydown', function (event) {
        let index = tabs.index(this);
        if (event.key === 'ArrowRight') {
            index = (index + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            index = (index + tabs.length - 1) % tabs.length;
        } else if (event.key === 'Home') {
            index = 0;
        } else if (event.key === 'End') {
            index = tabs.length - 1;
        } else if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        event.preventDefault();
        tabs.eq(index).trigger('click').trigger('focus');
    });

    const activateSection = (hash) => {
        panel.find('.mainwp-rmcp-section-title').filter(function () {
            return '#' + this.id === hash;
        }).closest('.mainwp-rmcp-section').accordion('open', 0);
        const section = advanced.children('.ui.tab').filter(function () {
            return '#' + this.id === hash;
        });
        if (section.length) {
            advancedAccordion.accordion('open', 0);
            tabs.tab('change tab', section.data('tab'));
        }
    };

    // App instructions and direct links must activate their destination tab before scrolling.
    panel.on('click', 'a[href^="#"]', function () {
        if (!$(this).closest('#mainwp-rmcp-advanced-menu').length) {
            activateSection($(this).attr('href'));
        }
    });
    $(window).on('hashchange.mainwpRemoteMcp', () => activateSection(window.location.hash));
    activateSection(window.location.hash);

    $('#mainwp-rmcp-tool-categories').accordion({ exclusive: false, animateChildren: false });

    // Allowed Tools is one form: toggles stay local until Save Settings is selected.
    $('#mainwp-rmcp-tools-form .ui.toggle.checkbox').checkbox();
    $('#mainwp-rmcp-tools-form input[type="checkbox"]').filter(function () {
        return this.name.startsWith('categories[') || this.name === 'unaudited';
    }).each(function () {
        const input = $(this);
        const children = input.closest('.content').find('input[type="checkbox"]').filter(function () {
            return this.name.startsWith('tools[');
        });
        const syncChildren = (enabled) => {
            const controls = children.closest('.ui.checkbox');
            // Disabled checkboxes aren't submitted, so their hidden fields carry each tool's choice.
            children.each(function () {
                $(this).closest('.ui.checkbox').prev('input[type="hidden"]').val(!enabled && this.checked ? this.value : 'off');
            });
            controls.checkbox(enabled ? 'set enabled' : 'set disabled');
            children.closest('.ui.grid.field').toggleClass('disabled', !enabled);
        };
        input.closest('.ui.checkbox').checkbox({
            onChange: function () {
                syncChildren(this.checked);
            },
        });
        if (!input.prop('disabled')) {
            syncChildren(input.prop('checked'));
        }
    });

    panel.find('form.mainwp-rmcp-switch .ui.toggle.checkbox').each(function () {
        const form = $(this).closest('form');
        $(this).checkbox({ onChange: () => submit(form) });
    });

    const toggleForm = $('#mainwp-rmcp-toggle-form');
    const toggle = toggleForm.find('.ui.toggle.checkbox');
    toggle.checkbox({
        onChange: function () {
            // "set checked" restores the switch without firing onChange again.
            confirmThenSubmit(toggleForm, () => toggle.checkbox('set checked'));
        },
    });

    panel.find('select.mainwp-rmcp-submit-on-change').each(function () {
        const select = $(this);
        const initial = select.val();
        // The table's scroll wrapper would clip an open menu. While the table fits, nothing is
        // scrolled, so the wrapper can let the menu out until it closes.
        const wrapper = select.closest('table').parent();
        select.dropdown({
            onShow: function () {
                if (wrapper[0].scrollWidth <= wrapper[0].clientWidth) {
                    wrapper.css('overflow-x', 'visible');
                }
            },
            onHide: function () {
                wrapper.css('overflow-x', 'auto');
            },
            onChange: function (value) {
                if (value !== initial) {
                    submit(select.closest('form'));
                }
            },
        });
    });

    $('#mainwp-rmcp-disconnect').dropdown({ action: 'hide' });
    panel.on('click', '[data-rmcp-form]', function () {
        confirmThenSubmit($('#' + $(this).data('rmcp-form')));
    });

    const tokenModal = $('#mainwp-rmcp-token-modal');
    if (tokenModal.length) {
        const returnUrl = tokenModal.data('return-url');
        tokenModal.modal({
            onHidden: function () {
                // A fresh GET clears the one-time secret without resubmitting the mint POST.
                window.location.replace(returnUrl);
            },
        }).modal('show');
    }
});
