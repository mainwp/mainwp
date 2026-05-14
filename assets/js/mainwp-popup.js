
(function ($) {
    if (!globalThis.mainwpPopup) {
        globalThis.mainwpPopup = function (selector) {
            let popup = mainwpListPopups.getItem(selector);
            if (popup === null) {
                popup = new Mainwp_InstancePopup();
                popup.initWrapper(selector);
                popup.initElements();
                mainwpListPopups.pushItem(popup);
            }
            return popup;
        };
        let mainwpListPopups = {
            popupsQueue: [],
            pushItem: function (popup) {
                if ('object' !== typeof popup)
                    return false;
                if (null === this.getItem(popup.overlayId)) {
                    this.popupsQueue.push(popup);
                    return popup;
                }
                return false;
            },
            getItem: function (id) {
                let values = $.grep(this.popupsQueue, function (val) {
                    return val.overlayId == id;
                });
                let val = null;
                if (values.length > 0)
                    val = values[0];
                return val;
            }
        };
        let Mainwp_InstancePopup = function () {
            let _instancePopup = {
                overlayId: null,
                $overlayElementId: null,
                actionsCloseCallback: null,
                title: '',
                totalSites: 0,
                progressMax: 0, // length of process bar.
                progressInit: 0, // init value of process bar.
                statusText: '',
                hideStatusText: false,
                doCloseCallback: null,
                init: function (data) {
                    data = data || {};
                    // convert property.
                    if (data.callback) {
                        this.actionsCloseCallback = data.callback;
                        delete data.callback;
                    }
                    let defaultVal = {
                        totalSites: 0,
                        progressMax: 0,
                        progressInit: 0,
                        statusText: 'synced'
                    };
                    this.doCloseCallback = true; // default is yes.
                    $.extend(this, defaultVal, data);
                    if (0 == this.totalSites) {
                        this.totalSites = this.progressMax;
                    }
                    this.initProgress();
                    this.render();
                    this.bindEvents();
                },
                initWrapper: function (el) {  // may be call this very first to set custom wrapper.
                    this.overlayId = el;
                    this.$overlayElementId = $(this.overlayId);
                },
                initProgress: function () {
                    let pData = {
                        value: this.progressInit,
                        total: this.progressMax,
                    };

                    if (typeof pMax !== 'undefined') {
                        pData.total = pMax;
                    }

                    if (!this.hideStatusText) {
                        this.setStatusText('0 / ' + this.totalSites + ' ' + this.statusText);
                    }
                    this.$progress = this.$overlayElementId.find('.mainwp-modal-progress');
                    this.$progress.progress(pData);
                },
                initElements: function () {
                    if (!this.$overlayElementId) return;

                    this.$header    = this.$overlayElementId.find('.header');
                    this.$progress  = this.$overlayElementId.find('.mainwp-modal-progress');
                    this.$label     = this.$progress.find('.label');
                    this.$list      = this.$overlayElementId.find('#sync-sites-status');
                    this.$actions   = this.$overlayElementId.find('.mainwp-modal-actions');
                    this.$content   = this.$overlayElementId.find('.mainwp-modal-content');
                    this.$wrap      = this.$overlayElementId.find('.mainwp-popup-wrap');
                    this.$backdrop  = this.$overlayElementId.find('.mainwp-popup-backdrop');
                    this.$closeBtn  = this.$overlayElementId.find('.mainwp-modal-close');
                },
                render: function () {
                    if (!this.$overlayElementId) return;

                    this.initProgressBatch();

                    if (this.title) {
                        this.$header.html(this.title);
                    }

                    this.$progress.show();

                    this.$overlayElementId
                        .modal({
                            onHide: () => this.onHideModal(),
                            allowMultiple: this.allowMultiple ?? false,
                            closable: false
                        })
                        .modal('show')
                        .modal('set active'); // keep if still needed for your UI bug
                },
                bindEvents: function () {
                    if (this.$closeBtn?.length) {
                        this.$closeBtn.off('click.mainwp').on('click.mainwp', () => this.close(true));
                    }
                },
                setTitle: function (title) {
                    this.$header?.html(title);
                },
                setStatusText: function (label) {
                    this.$label?.html(label);
                },
                clearList: function () {
                    this.$list?.empty();
                },
                setActionButtons: function (html) {
                    this.$actions?.html(html);
                },
                getContentEl: function () {
                    return this.$content;
                },
                setElementsZIndex: function (val) {
                    this.$wrap?.css('z-index', val);
                    this.$backdrop?.css('z-index', val);
                },
                onHideModal: function () {
                    if (this.doCloseCallback) {
                        // do call back when clicking on close button or clicking on dimmer.
                        typeof this.actionsCloseCallback === 'function' && this.actionsCloseCallback();
                    }
                },
                initProgressBatch: function () {
                    this._pendingCount = 0;
                    this._lastValue = 0;
                    this._flushTimer = null;
                },
                setProgressSite: function (value) {
                    if (!this.$progress) return;

                    this._pendingCount = (this._pendingCount || 0) + 1;
                    this._lastValue = value;

                    if (this._rafScheduled) return;

                    this._rafScheduled = true;

                    requestAnimationFrame(() => {
                        this.flushProgress();
                        this._rafScheduled = false;
                    });
                },
                flushProgress: function () {
                    if (!this.$progress) return;

                    // update label once
                    this.setStatusText(
                        `${this._lastValue} / ${this.totalSites} ${this.statusText}`
                    );

                    // batch increment
                    const current = this.$progress.progress('get value') || 0;
                    this.$progress.progress('set progress', current + this._pendingCount);

                    // reset
                    this._pendingCount = 0;
                    this._flushTimer = null;
                },
                getProgressValue: function () {
                    return this.$progress
                        ? this.$progress.progress('get value')
                        : 0;
                },
                setProgressValue: function (value) {
                    if (this.$progress) {
                        this.$progress.progress('set progress', value);
                    }
                },
                appendItemsList: function (left, right, { allowHtml = true } = {}) {
                    if (!this.$overlayElementId) return;

                    const $list = this.$list || (
                        this.$list = this.$overlayElementId.find('#sync-sites-status')
                    );

                    const $row = $('<div>', { class: 'item' });

                    const $right = $('<div>', { class: 'right floated content' });
                    const $left  = $('<div>', { class: 'content' });

                    if (allowHtml) {
                        $right.html(right);
                        $left.html(left);
                    } else {
                        $right.text(right);
                        $left.text(left);
                    }

                    $row.append($right, $left);
                    $list.append($row);
                },
                // close modal with executing callback or not executing callback.
                close: function (execCallback) {
                    this.doCloseCallback = execCallback !== undefined && execCallback; // do not do callback.
                    this.closePopup();
                },
                closePopup: function () {
                    this.$overlayElementId.modal('hide');
                    return false;
                }
            };
            return _instancePopup;
        };
    }
})(jQuery);
