;( function($) {
if (!window.mainwpPopup) {        
        mainwpPopup = function(selector){                  
            var popup = mainwpListPopups.getItem(selector);
            console.log(selector);
            if (popup === null) {
                popup = new mainwpInstancePopup();                               
                popup.initWrapper(selector);                
                mainwpListPopups.pushItem(popup);         
            }
            return popup;
        };        
        mainwpListPopups = {       
            popupsQueue: [],                      
            pushItem: function(popup) {                   
                if ('object' !== typeof popup)
                    return false;                      
                if (null === this.getItem(popup.overlayId)) {                    
                    this.popupsQueue.push(popup);                     
                    return popup;
                }      
                return false;
            },
            getItem: function(id) {                     
                var values = $.grep(this.popupsQueue, function(val, idx) {
                    return val.overlayId == id;
                }); 
                var val = null;
                if (values.length > 0)
                    val = values[0];                    
                return val;
            },
            closeAllPopups: function(excl) { 
                $(this.popupsQueue).each(function() {
                    var cls = true;
                    if (typeof excl === 'array' && this.overlayId in excl) {
                        cls = false;
                    }
                    if (cls)
                        this.closePopup();
                });                               
            },
            resetZIndex: function(popup) {  
                if (this.popupsQueue.length <= 1 )
                    return;                
                var lastOpen = null;                
                $(this.popupsQueue).each(function() {                       
                    if (this.$overlayElementId.hasClass('mainwp-popup-overlay')) {
                        lastOpen = this; // to get the last open item
                    }     
                    this.setElementsZIndex(999); // to trick                       
                });  
                
                if (typeof popup !== 'undefined' && popup !== null) {
                    popup.setElementsZIndex(10000);                            
                } else {
                    console.log(lastOpen);
                    if (lastOpen !== null) {
                        lastOpen.setElementsZIndex(10000);                                      
                    }
                }
            }
        };
        mainwpInstancePopup = function() {
            var _instancePopup = {
                overlayId: '#refresh-status-box', // default value      
                $overlayElementId: null,                             
                actionsCloseCallback: null,
                title: '',                
                total: 0,                                
                pMax: 0,                                                     
                init: function (data) { 
                    data = data || {};
                    // convert property                    
                    if (data.callback) {
                        this.actionsCloseCallback = data.callback; 
                        delete data.callback;
                    }

                    var defaultVal = {  
                        title: 'Syncing Websites',                
                        total: 0,                                
                        pMax: 0                                                                    
                    };
                    $.extend(this, defaultVal, data);                                          
                    
//                                                                
                    this.initProgress( {value:0, max:this.pMax} );
                    this.render();                    
                    this.bindEvents();                     
                    mainwpListPopups.resetZIndex(this); // reset z-index 
                },                   
                initWrapper: function(el) {  // may be call this very first to set custom wrapper                     
                    this.overlayId = el;    
                    this.$overlayElementId = $(this.overlayId);                    
                },                
                initProgress: function(data) {
                    this.$overlayElementId.find('#refresh-status-progress').progressbar({value:data.value, max:data.max});
                },                            
                render: function() {
                    if (this.title) {
                        this.$overlayElementId.find('.mainwp-popup-header .title').html(this.title);
                    }
                    
                    if (this.total) {
                        this.setTotal(this.total);
                    }
                                        
                    if (!this.total || !this.pMax)
                        this.$overlayElementId.find('.mainwp-popup-top').hide(); // hide status and progress
                    else
                        this.$overlayElementId.find('.mainwp-popup-top').show();
                    
                    // display popup
                    if (this.$overlayElementId.hasClass('mainwp-popup-overlay-hidden')) {
                        this.$overlayElementId.removeClass('mainwp-popup-overlay-hidden').addClass('mainwp-popup-overlay'); 
                        this.$overlayElementId.attr('restoreOverlayEl', 'mainwp-popup-overlay-hidden');             
                    } else if (this.$overlayElementId.hasClass('mainwp-popup-overlay-ready')) {
                        this.$overlayElementId.removeClass('mainwp-popup-overlay-ready').addClass('mainwp-popup-overlay'); 
                        this.$overlayElementId.attr('restoreOverlayEl', 'mainwp-popup-overlay-ready');                      
                    }
                        
                                                          
                },               
                bindEvents: function() {
                    var self = this;
                    var closeEl = this.$overlayElementId.find('.mainwp-popup-header .close');
                    if (closeEl.length > 0) {
                        $(closeEl).click(function(e) {
                            self.destroy(); 
                        });
                    }
                    var closebuttonEl = this.$overlayElementId.find('.mainwp-popup-close');
                    if (closebuttonEl.length > 0) {
                        $(closebuttonEl).click(function(e) {
                            self.destroy(); 
                        });
                    }      
                    this.$overlayElementId.on( 'click', '.mainwp-popup-backdrop', function() {
                        self.destroy();
                    });                        
                },               
                setTitle: function(title) {
                    this.$overlayElementId.find('.mainwp-popup-header .title').html(title);                    
                },
                setTotal: function(text) {
                    this.$overlayElementId.find('#refresh-status-total').text(text);                    
                },
                setCurrent: function(current) {
                    this.$overlayElementId.find('#refresh-status-current').html(current);                    
                },
                setStatusText: function(text) {
                    this.$overlayElementId.find('#refresh-status-text').html(text);                    
                },                
                setProgressValue: function(value) {                    
                    return this.$overlayElementId.find('#refresh-status-progress').progressbar('value', value);                    
                },                
                appendItemsList: function(html) {  
                    if (this.$overlayElementId == null)
                        this.$overlayElementId                        
                    this.$overlayElementId.find('#refresh-status-sites').append(html);                    
                },                
                clearList: function() {
                    this.$overlayElementId.find('#refresh-status-sites').empty();                    
                },
                setActionButtons: function(html) {
                    this.$overlayElementId.find('.mainwp-popup-actions').html(html);                    
                },                
                getContentEl: function() {
                    return this.$overlayElementId.find('#refresh-status-content');                    
                }, 
                setElementsZIndex: function(val) {
                    this.$overlayElementId.find('.mainwp-popup-wrap').css('z-index', val);                    
                    this.$overlayElementId.find('.mainwp-popup-backdrop').css('z-index', val);
                },                
                close: function() {                
                    this.closePopup();    
                },
                destroy: function() {                
                    this.closePopup();   
                    // trigger callback                    
                    typeof this.actionsCloseCallback === 'function' && this.actionsCloseCallback();                    
                },                                
                closePopup: function() { 
                    var $container = this.$overlayElementId;
                    if ($container.hasClass('mainwp-popup-overlay')) {
                        var restoreOverlay = $container.attr('restoreOverlayEl');                    
                        //console.log(restoreOverlay);
                        if (restoreOverlay !== '') {                            
                            $container.removeClass('mainwp-popup-overlay').addClass(restoreOverlay);                                
                            mainwpListPopups.resetZIndex(); // reset z-index after close
                            return true;
                        }  
                    }
                    return false;
                },               
                mergerObject: function(obj1, obj2) { 
                    obj1 = obj1 || {};
                    for(var key in obj2) {
                        obj1[key] = obj2[key];
                    }
                    return obj1;
                }
            }
            return _instancePopup;
        };
        
         mainwpAddPopupButtons = function($el) {              
            $el.find('h2.hndle').append("<span style=\"float:right;\" class=\"mainwp-popup-handle\"><i class=\"fa fa-window-maximize\" style=\"z-index:99999999;cursor:pointer;color:#72777c;\"  aria-hidden=\"true\"></i></span>"); // adding expand icon
            $('.mainwp-popup-handle i').each(function(){                   
                $(this).on('click', function(e){
                        var title = $(this).closest('h2').find('span:first-child').html();
                        var $container = $(this).closest('div.postbox');                        
                        var id = 'mainwp-popup-' + $container.attr('id');
                        $container.find('.mainwp-popup-overlay-ready').attr('id', id ); // set id                                           
                        mainwpPopup('#' + id).init({title: title});     
                        if (!$container.hasClass('closed')) { // this is trick to make it open
                            $container.find('button.handlediv').click();
                        } 
                 });
            });
        };         
}

})( jQuery );
