jQuery( document ).ready( function ($) {

    $('body').on('click','#generate-new-api-credentials', function(event){

        console.log('I was clicked');

        //do ajax to generate consumer key and secret
        var data = {
            'action': 'mainwp_generate_api_credentials',
        };

        jQuery.post(ajaxurl, data, function (response) {

            console.log(response);
            
            var response = JSON.parse(response);

            //get new values
            var consumer_key = response.consumer_key;
            var consumer_secret = response.consumer_secret;

            //inject values
            //we are also going to change the type so people can see the value
            $('#mainwp_consumer_key').attr('type','text').val(consumer_key);
            $('#mainwp_consumer_secret').attr('type','text').val(consumer_secret);

            //we are going to inject the values into the copy buttons to make things easier for people
            $('#mainwp_consumer_key_clipboard_button').attr('data-clipboard-text',consumer_key);
            $('#mainwp_consumer_secret_clipboard_button').attr('data-clipboard-text',consumer_secret);

            //initiate clipboard
            new ClipboardJS('.copy-to-clipboard');

            //show copy to clipboard buttons 
            $('.copy-to-clipboard').show();

            //show helper message
            $('#api-credentials-created').show();
            
        });

    });



    $('body').on('click','.copy-to-clipboard', function(event){

        alert('Copied!');
        
    });



});