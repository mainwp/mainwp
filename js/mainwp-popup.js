
( function($) {
if (!window.mainwpPopup) {
        mainwpPopup = {                
                overlayEl: '#refresh-status-box',// default                                
                customOverlayEl: null,
                actionsCloseCallback: null,
                title: null,                
                total: 0,                                
                pMax: 0,
                isShow: false,
                initShow: true,                
                $overlayEl: null,
                reloadAfterClose: true,
                doCloseCallback: true,
                
                init: function (data) {                    
                    if (data) { 
                        if (data.callback) {
                            this.actionsCloseCallback = data.callback; 
                        } else {
                            this.actionsCloseCallback = null; 
                        }  
                        
                        if (data.hasOwnProperty('reloadAfterClose')) {
                            this.reloadAfterClose = data.reloadAfterClose; 
                        }
                        
                        if (data.title) {
                            this.title = data.title; 
                        } else {
                            this.title = 'Syncing Websites'; 
                        }
                        
                        if (data.total) {
                            this.total = data.total; 
                        }
                        if (data.initShow) {
                            this.initShow = data.initShow; 
                        }
                        if (data.initShow) {
                            this.initShow = data.initShow; 
                        }
                        if (data.pMax) {
                            this.pMax = data.pMax; 
                        }
                    }
                    
                    if (!this.$overlayEl)
                        this.init_wrapper(); 
                    
                    this.initProgress( {value:0, max:this.pMax} );
                    
                    if (this.initShow) {
                        this.render();
                    }                                       
                }, 
                init_wrapper: function() { 
                    // check custom overlay first
                    if (this.customOverlayEl)
                        this.$overlayEl = $(this.customOverlayEl);    
                    else
                        this.$overlayEl = $(this.overlayEl);                                                            
                },
                setCustomWrapper: function(el) {  // may be call this very first to set custom layout                  
                    if (typeof el === 'undefined') {
                        this.customOverlayEl = null;                    
                    } else {
                        this.customOverlayEl = el;
                    }
                    this.init_wrapper();
                },
                initProgress: function(data) {
                    this.$overlayEl.find('#refresh-status-progress').progressbar({value:data.value, max:data.max});
                },                
                showPopup: function() {   
                    this.render();
                },
                render: function() {
                    if (this.title) {
                        this.$overlayEl.find('.mainwp-popup-header .title').html(this.title);
                    }
                    
                    if (this.total) {
                        this.setTotal(this.total);
                    }
                    
                    if (this.pMax) {
                        this.setProgressValue();
                        this.$overlayEl.find('#refresh-status-progress').progressbar({value:0, max:this.pMax});
                    }
                    
                    if (!this.total || !this.pMax)
                        this.$overlayEl.find('.mainwp-popup-top').hide(); // hide status and progress
                    else
                        this.$overlayEl.find('#refresh-status-progress').show();
                    
                    // display popup
                    this.$overlayEl.removeClass('mainwp-popup-overlay-hidden').addClass('mainwp-popup-overlay'); 
                    this.bindEvents();
                    $( 'body' ).addClass( 'mainwp-modal-open' );                    
                    this.isShow = true;                    
                },
                bindEvents: function() {
                    var self = this;
                    var closeEl = this.$overlayEl.find('.mainwp-popup-header .close');
                    if (closeEl.length > 0) {
                        $(closeEl).click(function() {
                            self.close(); // close and reload
                        });
                    }
                    var closebuttonEl = this.$overlayEl.find('#refresh-status-close');
                    if (closebuttonEl.length > 0) {
                        $(closebuttonEl).click(function() {
                            self.close(); // close and reload
                        });
                    }
                },
                setTitle: function(title) {
                    this.$overlayEl.find('.mainwp-popup-header .title').html(title);                    
                },
                setTotal: function(text) {
                    this.$overlayEl.find('#refresh-status-total').text(text);                    
                },
                setCurrent: function(current) {
                    this.$overlayEl.find('#refresh-status-current').html(current);                    
                },
                setStatusText: function(text) {
                    this.$overlayEl.find('#refresh-status-text').html(text);                    
                },                
                setProgressValue: function(value) {                    
                    return this.$overlayEl.find('#refresh-status-progress').progressbar('value', value);                    
                },                
                appendItemsList: function(html) {  
                    if (this.$overlayEl == null)
                        this.$overlayEl                        
                    this.$overlayEl.find('#refresh-status-sites').append(html);                    
                },                
                clearList: function() {
                    this.$overlayEl.find('#refresh-status-sites').empty();                    
                },
                setActionButtons: function(html) {
                    this.$overlayEl.find('.mainwp-popup-actions').html(html);                    
                },                
                getContentEl: function() {
                    return this.$overlayEl.find('#refresh-status-content');                    
                },                 
                close: function(data) {                    
                    data = data || {};
                    this.destroy();
                    // trigger callback
                    if (this.doCloseCallback) {
                        typeof this.actionsCloseCallback === 'function' && this.actionsCloseCallback();
                    } else {
                        this.doCloseCallback = true; // set to default
                    }
                    
                    if ( this.reloadAfterClose )                   
                        location.href = location.href;
                    else
                        this.reloadAfterClose = true; // make it reload next close
                },
                destroy: function() {
                    //this.$overlayEl.empty(); 
                    this.$overlayEl.removeClass('mainwp-popup-overlay').addClass('mainwp-popup-overlay-hidden'); 
                    $( 'body' ).removeClass( 'mainwp-modal-open' );  
                    
                    // to re-set overlay
                    this.customOverlayEl = null;                    
                    this.$overlayEl = null;     
                    
                    this.isShow = false;
                }
        }
}

jQuery(function() {
    mainwpPopup.init_wrapper();        
});

})( jQuery );
