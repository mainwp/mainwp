<?php

class MainWPTwitter
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function renderSettings()
    {
        self::mwptt_updated_settings();
        self::mwptt_check_support();
        self::mwptt_connect_oauth();
    }
    
    // Credit to the: wp-to-twitter
    static function mwptt_connect_oauth( $auth = false ) {
        if ( ! $auth ) {
                echo '<div class="ui-sortable meta-box-sortables">';
                echo '<div class="postbox">';
        }

        $class = ( $auth ) ? 'wpt-profile' : 'wpt-settings';
        $form  = ( ! $auth ) ? '<form action="" method="post">' : '';
        $nonce = ( ! $auth ) ? wp_nonce_field( 'mainwp-to-twitter-nonce', '_wpnonce', true, false ) . wp_referer_field( false ) : '';

        if ( ! self::mwptt_oauth_test( $auth, 'verify' ) ) {

                // show notification to authenticate with OAuth. No longer global; settings only.
                if ( ! self::mwptt_check_oauth() ) {                        
                    $message   = sprintf( __( "Twitter requires authentication by OAuth. You will need to complete installation of MainWP Twitter.", 'mainwp' ));
                    echo "<div class='error'><p>$message</p></div>";
                }

                $ack = ( ! $auth ) ? get_option( 'mainwp_tt_app_consumer_key' ) : get_user_meta( $auth, 'mainwp_tt_app_consumer_key', true );
                $acs = ( ! $auth ) ? get_option( 'mainwp_tt_app_consumer_secret' ) : get_user_meta( $auth, 'mainwp_tt_app_consumer_secret', true );
                $ot  = ( ! $auth ) ? get_option( 'mainwp_tt_oauth_token' ) : get_user_meta( $auth, 'mainwp_tt_oauth_token', true );
                $ots = ( ! $auth ) ? get_option( 'mainwp_tt_oauth_token_secret' ) : get_user_meta( $auth, 'mainwp_tt_oauth_token_secret', true );

                $submit = ( ! $auth ) ? '<p class="submit"><input type="submit" name="submit" class="button-primary" value="' . __( 'Connect to Twitter', 'mainwp' ) . '" /></p>' . '</form>' : '';
                print( $form . '                       
                        <h3 class="hndle mainwp_box_title"><span><i class="fa fa-cog"></i>&nbsp;' . __( 'Connect to Twitter', 'mainwp' ) . '</span></h3>
                        <div class="inside ' . $class . '" id="mainwp-to-twitter">
                        <div class="notes">
                        <h4>' . __( 'MainWP Twitter Set-up', 'mainwp' ) . '</h4>
                        </div>
                                        <h4>' . __( '1. Register this site as an application on ', 'mainwp' ) . '<a href="https://apps.twitter.com/app/new/" target="_blank">' . __( 'Twitter\'s application registration page', 'mainwp' ) . '</a></h4>
                                                <ul>
                                                <li>' . __( 'If you\'re not currently logged in to Twitter, log-in to the account you want associated with this site', 'mainwp' ) . '</li>
                                                <li>' . __( 'Your application name cannot include the word "Twitter."', 'mainwp' ) . '</li>
                                                <li>' . __( 'Your Application Description can be anything.', 'mainwp' ) . '</li>
                                                <li>' . __( 'The WebSite and Callback URL should be ', 'mainwp' ) . '<strong>' . esc_url( home_url() ) . '</strong></li>
                                                </ul>
                                        <p><em>' . __( 'Agree to the Twitter Developer Agreement and continue.', 'mainwp' ) . '</em></p>
                                        <h4>' . __( '2. Switch to the "Permissions" tab in Twitter apps', 'mainwp' ) . '</h4>
                                                <ul>
                                                <li>' . __( 'Select "Read and Write" for the Application Type', 'mainwp' ) . '</li>
                                                <li>' . __( 'Update the application settings', 'mainwp' ) . '</li>
                                                </ul>
                                        <h4>' . __( '3. Switch to the Keys and Access Tokens tab and regenerate your consumer key and secret, then create your access token.', 'mainwp' ) . '</h4>
                                                <ul>
                                                <li>' . __( 'Copy your API key and API secret from the "Application Settings" section.', 'mainwp' ) . '</li>
                                                <li>' . __( 'Copy your Access token and Access token secret from the "Your Access Token" section.', 'mainwp' ) . '</li>
                                                </ul>
                       
                                <fieldset class="options">						
                                        <div class="tokens">
                                        <p>
                                                <label for="mwptt_app_consumer_key">' . __( 'API Key', 'mainwp' ) . '</label>
                                                <input type="text" size="45" name="mwptt_app_consumer_key" id="mwptt_app_consumer_key" value="' . esc_attr( $ack ) . '" />
                                        </p>
                                        <p>
                                                <label for="mwptt_app_consumer_secret">' . __( 'API Secret', 'mainwp' ) . '</label>
                                                <input type="text" size="45" name="mwptt_app_consumer_secret" id="mwptt_app_consumer_secret" value="' . esc_attr( $acs ) . '" />
                                        </p>
                                        </div>
                                        <h4>' . __( '4. Copy and paste your Access Token and Access Token Secret into the fields below', 'mainwp' ) . '</h4>
                                        <p>' . __( 'If the Access Level for your Access Token is not "<em>Read and write</em>", you must return to step 2 and generate a new Access Token.', 'mainwp' ) . '</p>
                                        <div class="tokens">
                                        <p>
                                                <label for="mwptt_oauth_token">' . __( 'Access Token', 'mainwp' ) . '</label>
                                                <input type="text" size="45" name="mwptt_oauth_token" id="mwptt_oauth_token" value="' . esc_attr( $ot ) . '" />
                                        </p>
                                        <p>
                                                <label for="mwptt_oauth_token_secret">' . __( 'Access Token Secret', 'mainwp' ) . '</label>
                                                <input type="text" size="45" name="mwptt_oauth_token_secret" id="mwptt_oauth_token_secret" value="' . esc_attr( $ots ) . '" />
                                        </p>
                                        </div>
                                </fieldset>
                                
                                <input type="hidden" name="mainwp_tt_oauth_settings" value="mwptt_oauth_test" class="hidden" style="display: none;" />
                                ' . $nonce . '
                        </div>	');
        } else if ( self::mwptt_oauth_test( $auth ) ) {
                $ack   = ( ! $auth ) ? esc_attr( get_option( 'mainwp_tt_app_consumer_key' ) ) : esc_attr( get_user_meta( $auth, 'mainwp_tt_app_consumer_key', true ) );
                $acs   = ( ! $auth ) ? esc_attr( get_option( 'mainwp_tt_app_consumer_secret' ) ) : esc_attr( get_user_meta( $auth, 'mainwp_tt_app_consumer_secret', true ) );
                $ot    = ( ! $auth ) ? esc_attr( get_option( 'mainwp_tt_oauth_token' ) ) : esc_attr( get_user_meta( $auth, 'mainwp_tt_oauth_token', true ) );
                $ots   = ( ! $auth ) ? esc_attr( get_option( 'mainwp_tt_oauth_token_secret' ) ) : esc_attr( get_user_meta( $auth, 'mainwp_tt_oauth_token_secret', true ) );
                $uname = ( ! $auth ) ? esc_attr( get_option( 'mainwp_tt_twitter_username' ) ) : esc_attr( get_user_meta( $auth, 'mainwp_tt_twitter_username', true ) );
                $nonce = ( ! $auth ) ? wp_nonce_field( 'mainwp-to-twitter-nonce', '_wpnonce', true, false ) . wp_referer_field( false ) : '';
                if ( ! $auth ) {
                        $submit = '
                                        <input type="submit" name="submit" class="button-primary" value="' . __( 'Disconnect your Dashboard and Twitter Account', 'mainwp' ) . '" />
                                        <input type="hidden" name="mainwp_tt_oauth_settings" value="mwptt_twitter_disconnect" class="hidden" />
                                ' . '</form>';
                } else {
                        $submit = '<input type="checkbox" name="mainwp_tt_oauth_settings" value="mwptt_twitter_disconnect" id="disconnect" /> <label for="disconnect">' . __( 'Disconnect your MainWP and Twitter Account', 'mainwp' ) . '</label>' . '</form>';
                }

                print( $form . '                        
                        <h3 class="hndle mainwp_box_title"><span><i class="fa fa-cog"></i>&nbsp;' . __( 'Disconnect from Twitter', 'mainwp' ) . '</span></h3>
                        <div class="inside ' . $class . '">                        
                                <div id="mwptt_authentication_display">
                                        <fieldset class="options">
                                        <ul>
                                                <li><strong class="auth_label">' . __( 'Twitter Username ', 'mainwp' ) . '</strong> <code class="auth_code">' . $uname . '</code></li>
                                                <li><strong class="auth_label">' . __( 'API Key ', 'mainwp' ) . '</strong> <code class="auth_code">' . $ack . '</code></li>
                                                <li><strong class="auth_label">' . __( 'API Secret ', 'mainwp' ) . '</strong> <code class="auth_code">' . $acs . '</code></li>
                                                <li><strong class="auth_label">' . __( 'Access Token ', 'mainwp' ) . '</strong> <code class="auth_code">' . $ot . '</code></li>
                                                <li><strong class="auth_label">' . __( 'Access Token Secret ', 'mainwp' ) . '</strong> <code class="auth_code">' . $ots . '</code></li>
                                        </ul>
                                        </fieldset>
                                        <div>
                                      
                                        </div>
                                </div>		
                                ' . $nonce . '
                        </div>' );

        }
        if ( ! $auth ) {
                echo "</div>";
                echo "</div>";
                echo $submit;
        }
        ?>
<!--        <p>
            <?php _e( 'Check whether MainWP Twitter is setup correctly for Twitter. The test sends a status update to Twitter.', 'mainwp' ); ?>
	</p>
	<form method="post" action="">
            <fieldset>
                <input type="hidden" name="submit-type" value="check-support"/>                
                <?php $nonce = wp_nonce_field( 'mainwp-to-twitter-nonce', '_wpnonce', true, false ) . wp_referer_field( false );
                echo "<div>$nonce</div>"; ?>
                <p>
                        <input type="submit" name="submit" value="<?php _e( 'Test MainWP Twitter', 'mainwp' ); ?>" class="button-primary" />
                </p>
            </fieldset>
	</form>-->
        <?php
    }    
    // check for OAuth configuration
    static function mwptt_check_oauth( $auth = false ) {
        $oauth = self::mwptt_oauth_test( $auth );
        return $oauth;
    }
    
    static function mwptt_oauth_test( $auth = false, $context = '' ) {
        if ( ! $auth ) {
            return ( self::mwptt_oauth_credentials_to_hash() == get_option( 'mainwp_tt_oauth_hash' ) );
        } else {
            $return = ( self::mwptt_oauth_credentials_to_hash( $auth ) == self::mwptt_get_user_verification( $auth ) );
            if ( ! $return && $context != 'verify' ) {
                    return ( self::mwptt_oauth_credentials_to_hash() == get_option( 'mainwp_tt_oauth_hash' ) );
            } else {
                    return $return;
            }
        }
    }

    // convert credentials to md5 hash
    static function mwptt_oauth_credentials_to_hash( $auth = false ) {
        if ( ! $auth ) {
            $hash = md5( get_option( 'mainwp_tt_app_consumer_key' ) . get_option( 'mainwp_tt_app_consumer_secret' ) . get_option( 'mainwp_tt_oauth_token' ) . get_option( 'mainwp_tt_oauth_token_secret' ) );
        } else {
            $hash = md5( get_user_meta( $auth, 'mainwp_tt_app_consumer_key', true ) . get_user_meta( $auth, 'mainwp_tt_app_consumer_secret', true ) . get_user_meta( $auth, 'mainwp_tt_oauth_token', true ) . get_user_meta( $auth, 'mainwp_tt_oauth_token_secret', true ) );
        }
        return $hash;
    }
    
    static function mwptt_get_user_verification( $auth ) {
        $auth = get_user_meta( $auth, 'mainwp_tt_oauth_hash', true );
        return $auth;
    }

	
    static function mwptt_updated_settings() {
            
            if ( !isset( $_POST['mainwp_tt_oauth_settings'] ) ) 
                return;
                     
            if ( ! empty( $_POST['_wpnonce'] ) ) {
                    $nonce = $_REQUEST['_wpnonce'];
                    if ( ! wp_verify_nonce( $nonce, 'mainwp-to-twitter-nonce' ) ) {
                            die( "Security check failed" );
                    }
            }
            $oauth_message = self::mwptt_update_oauth_settings( false, $_POST ); 

            if ( $oauth_message == "success" ) {
                    print( '
                            <div id="message" class="updated fade">
                                    <p>' . __( 'MainWP Twitter is now connected with Twitter.', 'mainwp' ) . '</p>
                            </div>
                    ' );
            } else if ( $oauth_message == "failed" ) {
                    print( '
                            <div id="message" class="error fade">
                                    <p>' . __( 'MainWP Twitter failed to connect with Twitter.', 'mainwp' ) . ' ' . __( 'Error:', 'mainwp' ) . ' ' . get_option( 'mainwp_tt_error' ) . '</p>
                            </div>
                    ' );
            } else if ( $oauth_message == "cleared" ) {
                    print( '
                            <div id="message" class="updated fade">
                                    <p>' . __( 'OAuth Authentication Data Cleared.', 'mainwp' ) . '</p>
                            </div>
                    ' );
            } else if ( $oauth_message == 'nosync' ) {
                    print( '
                            <div id="message" class="error fade">
                                    <p>' . __( 'OAuth Authentication Failed. Your server time is not in sync with the Twitter servers. Talk to your hosting service to see what can be done.', 'mainwp' ) . '</p>
                            </div>
                    ' );
            } else {
                    print( '
                            <div id="message" class="error fade">
                                    <p>' . __( 'OAuth Authentication response not understood.', 'mainwp' ) . '</p>
                            </div>			
                    ' );
            }
            
            

            
    }

    static function mwptt_check_support() {
        $message = "";
        if ( isset( $_POST['submit-type'] ) && $_POST['submit-type'] == 'check-support' ) {                   
                $message = self::mwptt_check_functions();
        }

        if ( !empty($message )) {
                echo '<div id="message" class="updated is-dismissible">' . $message . '</div>';
        }
    }
    
    // response to settings updates
    static function mwptt_update_oauth_settings( $auth = false, $post = false ) {
            if ( isset( $post['mainwp_tt_oauth_settings'] ) ) {
                    switch ( $post['mainwp_tt_oauth_settings'] ) {
                            case 'mwptt_oauth_test':
                                    if ( ! wp_verify_nonce( $post['_wpnonce'], 'mainwp-to-twitter-nonce' ) && ! $auth ) {
                                            wp_die( 'Oops, please try again.' );
                                    }
                                    if ( ! empty( $post['mwptt_app_consumer_key'] )
                                         && ! empty( $post['mwptt_app_consumer_secret'] )
                                         && ! empty( $post['mwptt_oauth_token'] )
                                         && ! empty( $post['mwptt_oauth_token_secret'] )
                                    ) {
                                            $ack = trim( $post['mwptt_app_consumer_key'] );
                                            $acs = trim( $post['mwptt_app_consumer_secret'] );
                                            $ot  = trim( $post['mwptt_oauth_token'] );
                                            $ots = trim( $post['mwptt_oauth_token_secret'] );
                                            if ( ! $auth ) {
                                                    update_option( 'mainwp_tt_app_consumer_key', $ack );
                                                    update_option( 'mainwp_tt_app_consumer_secret', $acs );
                                                    update_option( 'mainwp_tt_oauth_token', $ot );
                                                    update_option( 'mainwp_tt_oauth_token_secret', $ots );
                                            } else {
                                                    update_user_meta( $auth, 'mainwp_tt_app_consumer_key', $ack );
                                                    update_user_meta( $auth, 'mainwp_tt_app_consumer_secret', $acs );
                                                    update_user_meta( $auth, 'mainwp_tt_oauth_token', $ot );
                                                    update_user_meta( $auth, 'mainwp_tt_oauth_token_secret', $ots );
                                            }
                                            $message = 'failed';
                                            if ( $connection = self::mwptt_oauth_connection( $auth ) ) {
                                                    $data = $connection->get( 'https://api.twitter.com/1.1/account/verify_credentials.json' );
                                                    if ( $connection->http_code != '200' ) {
                                                            $data  = json_decode( $data );
                                                            $code  = "<a href='https://dev.twitter.com/docs/error-codes-responses'>" . $data->errors[0]->code . "</a>";
                                                            $error = $data->errors[0]->message;
                                                            update_option( 'mainwp_tt_error', "$code: $error" );
                                                    } else {
                                                            delete_option( 'mainwp_tt_error' );
                                                    }
                                                    if ( $connection->http_code == '200' ) {
                                                            $error_information = '';
                                                            $decode            = json_decode( $data );
                                                            if ( ! $auth ) {
                                                                    update_option( 'mainwp_tt_twitter_username', stripslashes( $decode->screen_name ) );
                                                            } else {
                                                                    update_user_meta( $auth, 'mainwp_tt_twitter_username', stripslashes( $decode->screen_name ) );
                                                            }
                                                            $oauth_hash = self::mwptt_oauth_credentials_to_hash( $auth );
                                                            if ( ! $auth ) {
                                                                    update_option( 'mainwp_tt_oauth_hash', $oauth_hash );
                                                            } else {
                                                                    update_user_meta( $auth, 'mainwp_tt_oauth_hash', $oauth_hash );
                                                            }
                                                            $message = 'success';
                                                            delete_option( 'mainwp_tt_curl_error' );
                                                    } else if ( $connection->http_code == 0 ) {
                                                            $error_information = __( "MainWP Twitter was unable to establish a connection to Twitter.", 'mainwp' );
                                                            update_option( 'mainwp_tt_curl_error', "$error_information" );
                                                    } else {
                                                            $status = ( isset( $connection->http_header['status'] ) ) ? $connection->http_header['status'] : '404';
                                                            $error_information = array(
                                                                    "http_code" => $connection->http_code,
                                                                    "status"    => $status
                                                            );
                                                            $error_code        = __( "Twitter response: http_code $error_information[http_code] - $error_information[status]", 'mainwp' );
                                                            update_option( 'mainwp_tt_curl_error', $error_code );
                                                    }                                                   
                                            }
                                    } else {
                                            $message = "nodata";
                                    }
                                    if ( $message == 'failed' && ( time() < strtotime( $connection->http_header['date'] ) - 300 || time() > strtotime( $connection->http_header['date'] ) + 300 ) ) {
                                            $message = 'nosync';
                                    }

                                    return $message;
                                    break;
                            case 'mwptt_twitter_disconnect':
                                    if ( ! wp_verify_nonce( $post['_wpnonce'], 'mainwp-to-twitter-nonce' ) && ! $auth ) {
                                            wp_die( 'Oops, please try again.' );
                                    }
                                    if ( ! $auth ) {
                                            update_option( 'mainwp_tt_app_consumer_key', '' );
                                            update_option( 'mainwp_tt_app_consumer_secret', '' );
                                            update_option( 'mainwp_tt_oauth_token', '' );
                                            update_option( 'mainwp_tt_oauth_token_secret', '' );
                                            update_option( 'mainwp_tt_twitter_username', '' );
                                    } else {
                                            delete_user_meta( $auth, 'mainwp_tt_app_consumer_key' );
                                            delete_user_meta( $auth, 'mainwp_tt_app_consumer_secret' );
                                            delete_user_meta( $auth, 'mainwp_tt_oauth_token' );
                                            delete_user_meta( $auth, 'mainwp_tt_oauth_token_secret' );
                                            delete_user_meta( $auth, 'mainwp_tt_twitter_username' );
                                    }
                                    $message = "cleared";

                                    return $message;
                                    break;
                    }
            }

            return '';
    }


    
    // function to make connection
    static function mwptt_oauth_connection( $auth = false ) {
            if ( ! $auth ) {
                    $ack = get_option( 'mainwp_tt_app_consumer_key' );
                    $acs = get_option( 'mainwp_tt_app_consumer_secret' );
                    $ot  = get_option( 'mainwp_tt_oauth_token' );
                    $ots = get_option( 'mainwp_tt_oauth_token_secret' );
            } else {
                    $ack = get_user_meta( $auth, 'mainwp_tt_app_consumer_key', true );
                    $acs = get_user_meta( $auth, 'mainwp_tt_app_consumer_secret', true );
                    $ot  = get_user_meta( $auth, 'mainwp_tt_oauth_token', true );
                    $ots = get_user_meta( $auth, 'mainwp_tt_oauth_token_secret', true );
            }
            if ( ! empty( $ack ) && ! empty( $acs ) && ! empty( $ot ) && ! empty( $ots ) ) {                    
                    $connection            = new MainWPTwitterOAuth( $ack, $acs, $ot, $ots );
                    $connection->useragent = get_option( 'blogname' ) . ' ' . home_url();

                    return $connection;
            } else {
                    return false;
            }
    }


    static function mwptt_check_functions() {
            $message = "<div class='update'>";           
            $testpost  = false;
            //check twitter credentials
            if ( self::mwptt_oauth_test() ) {
                    $rand     = rand( 1000000, 9999999 );
                    $testpost = self::doTwitterAPIPost( "This is a test of MainWP Twitter. ($rand)" );
                    if ( $testpost ) {
                            $message .= __( "<p>MainWP Twitter successfully submitted a status update to Twitter.</p>", 'mainwp' );
                    } else {
                            $error = self::mwptt_log( 'mainwp_tt_status_message', 'test' );
                            $message .= __( "<p class=\"error\">MainWP Twitter failed to submit an update to Twitter.</p>", 'mainwp' );
                            if (!empty($error))
                                $message .= "<p class=\"error\">$error</p>";
                    }
            } else {
                    $message .= _e( 'You have not connected Dashboard to Twitter.', 'mainwp' ) . " ";
            }
            // If everything's OK, there's  no reason to do this again.
            if ( $testpost == false ) {
                    $message .= __( "<p class=\"error\">Your server does not appear to support the required methods for MainWP Twitter to function. You can try it anyway - these tests aren't perfect.</p>", 'mainwp' );
            } else {
                    $message .= __( "<p>Your server should run MainWP Twitter successfully.</p>", 'mainwp' );
            }
            $message .= "
            </div>";
            return $message;
    }

    
    public static function doTwitterAPIPost( $twit, $auth = false, $id = false) {
//            $recent     = wpt_check_recent_tweet( $id, $auth );
//
//            if ( get_option( 'mainwp_tt_rate_limiting' ) == 1 ) {
//                    // check whether this post needs to be rate limited.
//                    $continue = wpt_test_rate_limit( $id, $auth );
//                    if ( !$continue ) {
//                            return false;
//                    }
//            }
//
//            
//            if ( $recent ) {
//                    return false;
//            }
            
            $http_code = 0;
            
            if ( ! self::mwptt_check_oauth( $auth ) ) {
                    $error = __( 'This account is not authorized to post to Twitter.', 'mainwp' );                    
                    self::mwptt_set_log( 'mainwp_tt_status_message', $id, $error );

                    return false;
            } // exit silently if not authorized
            
            
//            $check = ( ! $auth ) ? get_option( 'mainwp_tt_last_tweet' ) : get_user_meta( $auth, 'mainwp_tt_last_tweet', true ); // get user's last tweet
            
            // prevent duplicate Tweets
//            if ( $check == $twit ) {
//                    $error = __( 'This tweet is identical to another Tweet recently sent to this account.', 'mainwp' ) . ' ' . __( 'Twitter requires all Tweets to be unique.', 'mainwp' );                    
//                    self::mwptt_set_log( 'mainwp_tt_status_message', $id, $error );                    
//                    return false;
//            } else 
            if ( $twit == '' || ! $twit ) {                    
                    $error = __( 'This tweet was blank and could not be sent to Twitter.', 'mainwp' );                    
                    self::mwptt_set_log( 'mainwp_tt_status_message', $id, $error );                    
                    return false;
            } else {
                    $api = "https://api.twitter.com/1.1/statuses/update.json";                    
                    $status = array(
                                            'status'           => $twit,
                                            'source'           => 'mainwp',
                                            'include_entities' => 'true'
                                    );                    
                    if ( self::mwptt_oauth_test( $auth ) && ( $connection = self::mwptt_oauth_connection( $auth ) ) ) {

                    }
                    
                    if ( empty( $connection ) ) {
                            $connection = array( 'connection' => 'undefined' );                            
                    } else {
                            $connection->post( $api, $status );
                            $http_code = ( $connection ) ? $connection->http_code : 'failed';
                            $notice = '';                                                 		
                    }                    
                    if ( $connection ) {                                                 		
                            if ( isset( $connection->http_header['x-access-level'] ) && $connection->http_header['x-access-level'] == 'read' ) {
                                    $supplement = sprintf( __( 'Your Twitter application does not have read and write permissions. Go to <a href="%s">your Twitter apps</a> to modify these settings.', 'mainwp' ), 'https://dev.twitter.com/apps/' );
                            } else {
                                    $supplement = '';
                            }
                            $return = false;
                            switch ( $http_code ) {
                                    case '100':
                                            $error = __( "100 Continue: Twitter received the header of your submission, but your server did not follow through by sending the body of the data.", 'mainwp' );
                                            break;				
                                    case '200':
                                            $return = true;
                                            $error  = __( "200 OK: Success!", 'mainwp' );
                                            update_option( 'mainwp_tt_authentication_missing', false );
                                            break;
                                    case '304':
                                            $error = __( "304 Not Modified: There was no new data to return", 'mainwp' );
                                            break;
                                    case '400':
                                            $error = __( "400 Bad Request: The request was invalid. This is the status code returned during rate limiting.", 'mainwp' );
                                            break;
                                    case '401':
                                            $error = __( "401 Unauthorized: Authentication credentials were missing or incorrect.", 'mainwp' );
                                            update_option( 'mainwp_tt_authentication_missing', "$auth" );
                                            break;
                                    case '403':
                                            $error = __( "403 Forbidden: The request is understood, but has been refused by Twitter. Possible reasons: too many Tweets, same Tweet submitted twice, Tweet longer than 140 characters.", 'mainwp' );
                                            break;
                                    case '404':
                                            $error = __( "404 Not Found: The URI requested is invalid or the resource requested does not exist.", 'mainwp' );
                                            break;
                                    case '406':
                                            $error = __( "406 Not Acceptable: Invalid Format Specified.", 'mainwp' );
                                            break;
                                    case '422':
                                            $error = __( "422 Unprocessable Entity: The image uploaded could not be processed..", 'mainwp' );
                                            break;
                                    case '429':
                                            $error = __( "429 Too Many Requests: You have exceeded your rate limits.", 'mainwp' );
                                            break;
                                    case '500':
                                            $error = __( "500 Internal Server Error: Something is broken at Twitter.", 'mainwp' );
                                            break;
                                    case '502':
                                            $error = __( "502 Bad Gateway: Twitter is down or being upgraded.", 'mainwp' );
                                            break;
                                    case '503':
                                            $error = __( "503 Service Unavailable: The Twitter servers are up, but overloaded with requests - Please try again later.", 'mainwp' );
                                            break;
                                    case '504':
                                            $error = __( "504 Gateway Timeout: The Twitter servers are up, but the request couldn't be serviced due to some failure within our stack. Try again later.", 'mainwp' );
                                            break;
                                    default:
                                            $error = __( "Code $http_code</strong>: Twitter did not return a recognized response code.", 'mainwp' );
                                            break;
                            }
                            $error .= ( $supplement != '' ) ? " $supplement" : '';    
                            
//                            if ( ! $auth ) {
//                                    update_option( 'mainwp_tt_last_tweet', $twit );
//                            } else {
//                                    update_user_meta( $auth, 'mainwp_tt_last_tweet', $twit );
//                            }
                            
                            if ( $http_code == '200' ) {
                                    $jwt = get_post_meta( $id, '_jd_wp_twitter', true );
                                    if ( ! is_array( $jwt ) ) {
                                            $jwt = array();
                                    }
                                    $jwt[] = urldecode( $twit );
                                    if ( empty( $_POST ) ) {
                                            $_POST = array();
                                    }
                                    $_POST['_jd_wp_twitter'] = $jwt;
                                    update_post_meta( $id, '_jd_wp_twitter', $jwt );                                    
                            }
                            if ( ! $return ) {
                                    self::mwptt_set_log( 'mainwp_tt_status_message', $id, $error );
                            } else {
                                    self::mwptt_set_log( 'mainwp_tt_status_message', $id, $notice . __( 'Tweet sent successfully.', 'mainwp' ) );
                            }                            
                            return $return;
                    } else {                            
                            self::mwptt_set_log( 'mainwp_tt_status_message', $id, __( 'No Twitter OAuth connection found.', 'mainwp' ) );
                            return false;
                    }
            }
    }


    static function mwptt_set_log( $data, $id, $message ) {
            if ( $id == 'test' ) {
                update_option( $data, $message );
            } 
            update_option( $data . '_last', array( $id, $message ) );
    }

    static function mwptt_log( $data, $id ) {
        $log = "";
        if ( $id == 'test' ) {
            $log = get_option( $data );
        } else {
            $log = get_option( $data . '_last' );
            if (is_array($log) && !empty($log[0]) && ($log[0] == $id)) {
                return $log[1];
            }
        } 
        return $log;
    }
    
    static function get_filter() {
        return array(   'upgrade_everything',
                            'upgrade_all_wp_core',
                            'upgrade_all_plugins',
                            'upgrade_all_themes',
                            'new_post',
                            'new_page',
                            'installing_new_plugin',
                            'installing_new_theme',
                            'create_new_user',
                        ); 
    }
    
    public static function enabledTwitterMessages() {        
        if (!get_option('mainwp_hide_twitters_message', 0)) {
            return true;
        }
        return false;
    }
    
    public static function clearAllTwitterMessages() {        
        $filters = self::get_filter();
        $user_id = get_current_user_id();
        
        foreach($filters as $what) {
            $opt_name = 'mainwp_tt_message_' . $what;
            delete_user_option($user_id, $opt_name);
        }
    }
    
    static function getNotice($what, $value) {
        
        if (!is_array($value) || empty($value['sites']) || !isset($value['seconds'])) 
            return "";
        
        $message = "";
        switch ( $what ) {
            case 'upgrade_everything':  
                    $message = sprintf(__('Wow, you updated %d sites'), $value['sites']);                       
                break;
            case 'upgrade_all_wp_core':  
                    $message = sprintf(__('Wow, you updated %d WordPress sites'), $value['sites']);                    
                break;
            case 'upgrade_all_plugins':  
                    $message = sprintf(__('Wow, you updated %d plugins on %d sites'), $value['items'], $value['sites']);
                    $in_sec = $value[2];
                break;
            case 'upgrade_all_themes':  
                    $message = sprintf(__('Wow, you updated %d themes on %d sites'), $value['items'], $value['sites']);                    
                break;
            case 'new_post':  
                    $message = sprintf(__('Wow, you published a new post on %d sites'), $value['sites']);                    
                break;
            case 'new_page':  
                    $message = sprintf(__('Wow, you published a new page on %d sites'), $value['sites']);                    
                break;
            case 'installing_new_plugin':  
                    $message = sprintf(__('Wow, you installed a new plugin on %d sites'), $value['sites']);            
                break;
            case 'installing_new_theme':  
                    $message = sprintf(__('Wow, you installed a new theme on %d sites'), $value['sites']);
                break;
            case 'create_new_user':  
                    $message = sprintf(__('Wow, you created a new user on %d sites'), $value['sites']);                    
                break;
         } 
         
         if (!empty($message)) {           
            $in_sec = $value['seconds'];
            if ( $in_sec <= 60) {
                $message .= " " . sprintf(__('in %d seconds', 'mainwp'), $in_sec);
            }
            $message .= ".";
         }
         
         return $message;
    }
    
    public static function bragOnTwitter($what, $twId) {        
        $twitter_messages = array();
        
        $filters = self::get_filter();
        
        if (!in_array($what, $filters))
            die(json_encode(array('error' => __('Invalid message.', 'mainwp'))));
        
        $opt_name = 'mainwp_tt_message_' . $what;
        $twitter_messages = get_user_option($opt_name);
        $message = $error = ""; 
        if (is_array($twitter_messages[$twId]) && isset($twitter_messages[$twId])) {
            $val = $twitter_messages[$twId];     
            $user_id = get_current_user_id();            
            if (is_array($val) && !empty($val['sites']) && !empty($val['seconds'])) {                
                $twit = "";                 
                switch ( $what ) {
                   case 'upgrade_everything':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully updated %d child sites'), $val['sites']);
                       break;
                   case 'upgrade_all_wp_core':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully updated %d WordPress sites'), $val['sites']);
                       break;
                   case 'upgrade_all_plugins':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully updated %d plugins on %d child sites'), $val['items'], $val['sites']);                            
                       break;
                   case 'upgrade_all_themes':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully updated %d themes on %d child sites'), $val['items'], $val['sites']);
                       break;
                   case 'new_post':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully published a new post on %d child sites'), $val['sites']);
                       break;
                   case 'new_page':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully published a new page on %d child sites'), $val['sites']);
                       break;
                   case 'installing_new_plugin':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully installed a new plugin on %d child sites'), $val['sites']);
                       break;
                   case 'installing_new_theme':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully installed a new theme on %d child sites'), $val['sites']);
                       break;
                   case 'create_new_user':  
                           $twit = sprintf(__('Thanks to @mymainwp I just successfully created a new user on %d child sites'), $val['sites']);
                       break;
                }
                
                
                if (!empty($twit)) {                      
                    $in_sec = $val['seconds'];
                    if ( $in_sec <= 60 ) {
                        $twit .= " " . sprintf(__('in %d seconds', 'mainwp'), $in_sec);
                    }                    
                    $twit .= ' https://mainwp.com';                    
                    if (self::doTwitterAPIPost($twit, false, $twId)) {                        
                        $message = __( "Successful", 'mainwp' );
                        unset($twitter_messages[$twId]);                
                        update_user_option($user_id, $opt_name, $twitter_messages);
                    } else {
                        $error = self::mwptt_log( 'mainwp_tt_status_message', $twId );                        
                        if (!empty($error))
                            $error = "<span class=\"error\">Error: $error</span>";                            
                    }
                }                
            } else {
                unset($twitter_messages[$twId]);                
                update_user_option($user_id, $opt_name, $twitter_messages);
            }
        } 
        
        if (!empty($message)) {
            die(json_encode(array('message' => $message)));
        } else if (!empty($error)) {
            die(json_encode(array('error' => $error)));
        }
        
        die(json_encode(array('error' => __('Invalid message.', 'mainwp'))));
    }    
    
    public static function updateTwitterInfo($what, $countSites = 0, $countSec = 0, $countItems = 0, $twId = 0) {        
        if (empty($twId))
            return false;
        
        $filters = self::get_filter();       
        
        if (!in_array($what, $filters))
            return false;    
        
        if (empty($countSec)) $countSec = 1;        
        $data = array($twId => array('sites' => $countSites, 'seconds' => $countSec, 'items' => $countItems));
        $user_id = get_current_user_id();         
        
        $opt_name = 'mainwp_tt_message_' . $what;       
         
        if (update_user_option($user_id, $opt_name, $data )) {            
            return true;
        }            
        
        return false;   
    }    
    
    public static function clearTwitterInfo($what, $twId = 0) {        
        if (empty($twId))
            return false;
        
        $filters = self::get_filter();
        
        if (!in_array($what, $filters))
            return false;
         
        $opt_name = 'mainwp_tt_message_' . $what;
        
        $data = get_user_option($opt_name);
        
        if (!is_array($data))
            $data = array(); 
        
        if (isset($data[$twId])) {
            unset($data[$twId]);                
            $user_id = get_current_user_id();           
            update_user_option($user_id, $opt_name, $data );                
        }
        
        return true;   
    }
    
    public static function getTwitterNotice($what, $twId = 0) {        
        
        $filters = self::get_filter();
        
        if (!in_array($what, $filters))
            return false;
          
        $opt_name = 'mainwp_tt_message_' . $what;        
        $twitter_messages = get_user_option($opt_name);     
        
        $return = array();
        
        if (is_array($twitter_messages)) {            
            if (!empty($twId)) {
                if (isset($twitter_messages[$twId])) { 
                    $value = $twitter_messages[$twId];
                    $mess = self::getNotice($what, $value);
                    if (!empty($mess))
                        $return[$twId] = $mess;
                } 
            } else {
                foreach($twitter_messages as $time => $value) {                            
                    $mess = self::getNotice($what, $value);
                    if (!empty($mess))
                        $return[$time] = $mess;                    
                }
            }
        }
        return $return;
    }    
    
}