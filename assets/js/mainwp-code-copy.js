/**
 * Copy buttons for AI feature code samples.
 *
 * @package MainWP\Dashboard
 */

jQuery(function ($) {
    const buttons = $('.mainwp-code-copy-button');

    if (!buttons.length || typeof ClipboardJS === 'undefined') {
        return;
    }

    buttons.each(function () {
        const button = $(this);
        const originalHtml = button.html();
        const originalAriaLabel = button.attr('aria-label');
        let resetTimer;

        const showStatus = (icon, label) => {
            globalThis.clearTimeout(resetTimer);
            button.empty().append($('<i>').addClass(icon), document.createTextNode(label));
            button.attr('aria-label', label);
            resetTimer = globalThis.setTimeout(() => {
                button.html(originalHtml);
                button.attr('aria-label', originalAriaLabel);
            }, 2000);
        };

        const clipboard = new ClipboardJS(this);

        clipboard.on('success', (event) => {
            event.clearSelection();
            showStatus('check icon', button.data('copy-success'));
        });

        clipboard.on('error', () => {
            showStatus('exclamation triangle icon', button.data('copy-error'));
        });
    });
});
