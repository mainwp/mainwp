/**
 * Interactive setup steps for the MainWP Control page.
 *
 * @package MainWP\Dashboard
 */

jQuery(function ($) {
    const stepList = $('#mainwp-control-steps');

    if (!stepList.length) {
        return;
    }

    const steps = stepList.find('[data-step-index]');
    const panels = $('#mainwp-control-step-panels').find('[data-step-panel]');

    const activateStep = (stepIndex, moveFocus = false) => {
        const targetIndex = Number.parseInt(stepIndex, 10);

        if (Number.isNaN(targetIndex) || targetIndex < 0 || targetIndex >= steps.length) {
            return;
        }

        steps.each(function (index) {
            const step = $(this);
            const isActive = index === targetIndex;

            step.toggleClass('active', isActive);
            step.toggleClass('completed', index < targetIndex);
            step.attr('aria-selected', isActive ? 'true' : 'false');
            step.attr('tabindex', isActive ? '0' : '-1');

            if (isActive) {
                step.attr('aria-current', 'step');
            } else {
                step.removeAttr('aria-current');
            }
        });

        panels.each(function () {
            const panel = $(this);
            panel.prop('hidden', Number.parseInt(panel.data('step-panel'), 10) !== targetIndex);
        });

        if (moveFocus) {
            steps.eq(targetIndex).trigger('focus');
        }
    };

    steps.on('click', function () {
        activateStep($(this).data('step-index'));
    });

    $('.mainwp-control-step-navigation').on('click', function (event) {
        event.preventDefault();
        activateStep($(this).data('step-index'), true);
        stepList.get(0).scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    steps.on('keydown', function (event) {
        const currentIndex = Number.parseInt($(this).data('step-index'), 10);
        let targetIndex = currentIndex;

        if ('ArrowRight' === event.key || 'ArrowDown' === event.key) {
            targetIndex = (currentIndex + 1) % steps.length;
        } else if ('ArrowLeft' === event.key || 'ArrowUp' === event.key) {
            targetIndex = (currentIndex - 1 + steps.length) % steps.length;
        } else if ('Home' === event.key) {
            targetIndex = 0;
        } else if ('End' === event.key) {
            targetIndex = steps.length - 1;
        } else if ('Enter' !== event.key && ' ' !== event.key) {
            return;
        }

        event.preventDefault();
        activateStep(targetIndex, true);
    });

    const handleCircularStepsResize = () => {
        stepList.toggleClass('vertical', window.innerWidth <= 768);
    };

    handleCircularStepsResize();
    $(window).on('resize', handleCircularStepsResize);
});
