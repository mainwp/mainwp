/**
 * Remote AI connections panel: the app pickers, the Advanced rows, confirmations, and controls
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

    // Each picker shows the one block of its group whose data-app matches the choice.
    panel.find('select[data-rmcp-picker]').each(function () {
        const group = $(this).data('rmcp-picker');
        $(this).dropdown({
            onChange: function (value) {
                panel.find('.' + group).each(function () {
                    $(this).prop('hidden', $(this).data('app') !== value);
                });
            },
        });
    });

    // The outer accordion handles the nested tool categories too. Clicks on a switch inside a
    // title flip the switch, not the row.
    const advanced = $('#mainwp-rmcp-advanced');
    advanced.accordion({ exclusive: false, selector: { ignore: '.ui.dropdown, form' } });

    // Links such as "Connection tokens" in the app steps point at a closed row; open it first.
    panel.on('click', 'a[href^="#"]', function () {
        const title = advanced.children('.title' + $(this).attr('href'));
        if (title.length && !title.hasClass('active')) {
            advanced.accordion('open', title.get(0));
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
});
