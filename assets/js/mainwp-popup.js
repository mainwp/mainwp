
( function ( $ ) {
    if ( !window.mainwpPopup ) {
        mainwpPopup = function ( selector ) {
            var popup = mainwpListPopups.getItem( selector );
            console.log( selector );
            if ( popup === null ) {
                popup = new mainwpInstancePopup();
                popup.initWrapper( selector );
                mainwpListPopups.pushItem( popup );
            }
            return popup;
        };
        mainwpListPopups = {
            popupsQueue: [ ],
            pushItem: function ( popup ) {
                if ( 'object' !== typeof popup )
                    return false;
                if ( null === this.getItem( popup.overlayId ) ) {
                    this.popupsQueue.push( popup );
                    return popup;
                }
                return false;
            },
            getItem: function ( id ) {
                var values = $.grep( this.popupsQueue, function ( val ) {
                    return val.overlayId == id;
                } );
                var val = null;
                if ( values.length > 0 )
                    val = values[0];
                return val;
            }
        };
        mainwpInstancePopup = function () {
            var _instancePopup = {
                overlayId: null,
                $overlayElementId: null,
                actionsCloseCallback: null,
                title: '',
                total: 0,
                pMax: 0, // length of process bar
                statusText: '',
                doCloseCallback: null,
                init: function ( data ) {
                    data = data || { };
                    // convert property
                    if ( data.callback ) {
                        this.actionsCloseCallback = data.callback;
                        delete data.callback;
                    }
                    var defaultVal = {
                        //title: 'Syncing Websites',
                        total: 0,
                        pMax: 0,
                        statusText: 'synced'
                    };
                    this.doCloseCallback = true; // default is yes
                    $.extend( this, defaultVal, data );
                    this.initProgress( { value: 0, max: this.pMax , statusText: this.statusText} );
                    this.render();
                    this.bindEvents();
                },
                initWrapper: function ( el ) {  // may be call this very first to set custom wrapper
                    this.overlayId = el;
                    this.$overlayElementId = $( this.overlayId );
                },
                initProgress: function ( data ) {
                    this.$overlayElementId.find( '.mainwp-modal-progress' ).progress( {
                        value: data.value,
                        total: data.max ,
                        text: {
                            active  : '{value} / {total} ' + data.statusText
                        }
                    });
                },
                render: function () {
                    if ( this.title ) {
                        this.$overlayElementId.find( '.header' ).html( this.title );
                    }

                    if ( !this.total || !this.pMax )
                        this.$overlayElementId.find( '.mainwp-modal-progress' ).hide(); // hide status and progress
                    else
                        this.$overlayElementId.find( '.mainwp-modal-progress' ).show();
                    //this.$overlayElementId.modal({closable: false});
                    var self = this;
                    this.$overlayElementId.modal( { onHide: function (){
                                            self.onHideModal();
                                        }} ).modal('show').modal('set active'); // trick to fix diplay issue
                },
                onHideModal: function() {
                    if (this.doCloseCallback) {
                        // do call back when clicking on close button or clicking on dimmer
                        typeof this.actionsCloseCallback === 'function' && this.actionsCloseCallback();
                    }
                },
                bindEvents: function () {
                    var self = this;
                    var closebuttonEl = this.$overlayElementId.find( '.mainwp-modal-close' );
                    if ( closebuttonEl.length > 0 ) {
                        $( closebuttonEl ).click( function () {
                            self.close(true);
                        } );
                    }

                },
                setTitle: function ( title ) {
                      this.$overlayElementId.find( '.header' ).html( title );
                },
                setProgressValue: function ( value ) {
                    this.$overlayElementId.find( '.mainwp-modal-progress' ).progress( 'set progress', value);
                },
                appendItemsList: function ( left, right ) {
                    if ( this.$overlayElementId == null )
                        this.$overlayElementId;

                    var row = '<div class="item">';
                    row += '<div class="right floated content">';
                    row += right;
                    row += '</div>';
                    row += '<div class="content">';
                    row += left;
                    row += '</div>';
                    row += '</div>';

                    this.$overlayElementId.find( '#sync-sites-status' ).append( row );
                },
                clearList: function () {
                    this.$overlayElementId.find( '#sync-sites-status' ).empty();
                },
                setActionButtons: function ( html ) {
                    this.$overlayElementId.find( '.mainwp-modal-actions' ).html( html );
                },
                getContentEl: function () {
                    return this.$overlayElementId.find( '.mainwp-modal-content' );
                },
                setElementsZIndex: function ( val ) {
                    this.$overlayElementId.find( '.mainwp-popup-wrap' ).css( 'z-index', val );
                    this.$overlayElementId.find( '.mainwp-popup-backdrop' ).css( 'z-index', val );
                },
                // close modal with executing callback or not executing callback
                close: function (execCallback) {
                    this.doCloseCallback = typeof execCallback !== 'undefined' && execCallback ? true : false; // do not do callback
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
} )( jQuery );
