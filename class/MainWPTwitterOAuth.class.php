<?php
/*
 * Abraham Williams (abraham@abrah.am) http://abrah.am
 *
 * The first PHP Library to support WPOAuth for Twitter's REST API.
 *
 */

if ( ! defined( 'ABSPATH' ) ) 
	exit;

require_once( MAINWP_PLUGIN_DIR . 'class/MainWPOAuth.php' );

/**
 * Twitter WPOAuth class
 */
class MainWPTwitterOAuth {
        /* Contains the last HTTP status code returned */
        public $http_code;
        /* Contains the last API call. */
        public $url;
        /* Set up the API root URL. */
        public $host = "https://api.twitter.com/1.1/";
        /* Set timeout default. */
        public $format = 'json';
        /* Decode returned json data. */
        public $decode_json = false;
        /* Contains the last API call */
        private $last_api_call;
        /* containe the header */
        public $http_header;

        /**
         * Set API URLS
         */
        function accessTokenURL() {
                return "https://api.twitter.com/oauth/access_token";
        }

        function authenticateURL() {
                return "https://api.twitter.com/oauth/authenticate";
        }

        function authorizeURL() {
                return "https://api.twitter.com/oauth/authorize";
        }

        function requestTokenURL() {
                return "https://api.twitter.com/oauth/request_token";
        }

        /**
         * Debug helpers
         */
        function lastStatusCode() {
                return $this->http_code;
        }

        function lastAPICall() {
                return $this->last_api_call;
        }

        /**
         * construct TwitterWPOAuth object
         */
        function __construct( $consumer_key, $consumer_secret, $WPOAuth_token = null, $WPOAuth_token_secret = null ) {
                $this->sha1_method = new MainWPOAuthSignatureMethod_HMAC_SHA1();
                $this->consumer    = new MainWPOAuthConsumer( $consumer_key, $consumer_secret );
                if ( ! empty( $WPOAuth_token ) && ! empty( $WPOAuth_token_secret ) ) {
                        $this->token = new MainWPOAuthConsumer( $WPOAuth_token, $WPOAuth_token_secret );
                } else {
                        $this->token = null;
                }
        }


        /**
         * Get a request_token from Twitter
         *
         * @returns a key/value array containing MainWPOAuth_token and MainWPOAuth_token_secret
         */
        function getRequestToken() {
                $r           = $this->MainWPOAuthRequest( $this->requestTokenURL() );
                $token       = $this->MainWPOAuthParseResponse( $r );
                $this->token = new WPOAuthConsumer( $token['WPOAuth_token'], $token['WPOAuth_token_secret'] );

                return $token;
        }

        /**
         * Parse a URL-encoded MainWPOAuth response
         *
         * @return a key/value array
         */
        function MainWPOAuthParseResponse( $responseString ) {
                $r = array();
                foreach ( explode( '&', $responseString ) as $param ) {
                        $pair = explode( '=', $param, 2 );
                        if ( count( $pair ) != 2 ) {
                                continue;
                        }
                        $r[ urldecode( $pair[0] ) ] = urldecode( $pair[1] );
                }

                return $r;
        }

        /**
         * Get the authorize URL
         *
         * @returns a string
         */
        function getAuthorizeURL( $token ) {
                if ( is_array( $token ) ) {
                        $token = $token['WPOAuth_token'];
                }

                return $this->authorizeURL() . '?WPOAuth_token=' . $token;
        }


        /**
         * Get the authenticate URL
         *
         * @returns a string
         */
        function getAuthenticateURL( $token ) {
                if ( is_array( $token ) ) {
                        $token = $token['WPOAuth_token'];
                }

                return $this->authenticateURL() . '?WPOAuth_token=' . $token;
        }

        /**
         * Exchange the request token and secret for an access token and
         * secret, to sign API calls.
         *
         * @returns array("WPOAuth_token" => the access token,
         *                "WPOAuth_token_secret" => the access secret)
         */
        function getAccessToken( $token = null ) {
                $r           = $this->MainWPOAuthRequest( $this->accessTokenURL() );
                $token       = $this->MainWPOAuthParseResponse( $r );
                $this->token = new MainWPOAuthConsumer( $token['WPOAuth_token'], $token['WPOAuth_token_secret'] );

                return $token;
        }

        /**
         * Wrapper for POST requests
         */
        function post( $url, $parameters = array() ) {
                $response = $this->MainWPOAuthRequest( $url, $parameters, 'POST' );
                if ( $this->format === 'json' && $this->decode_json ) {
                        return json_decode( $response );
                }

                return $response;
        }

        /**
         * Wrapper for MEDIA requests
         */
        function media( $url, $parameters = array() ) {
                $response = $this->MainWPOAuthRequest( $url, $parameters, 'MEDIA' );
                if ( $this->format === 'json' && $this->decode_json ) {
                        return json_decode( $response );
                }

                return $response;
        }

        /**
         * Wrapper for GET requests
         */
        function get( $url, $parameters = array() ) {
                $response = $this->MainWPOAuthRequest( $url, $parameters, 'GET' );
                if ( $this->format === 'json' && $this->decode_json ) {
                        return json_decode( $response );
                }

                return $response;
        }

        /**
         * Handles a status update that includes an image.
         *
         * @param type $url
         * @param type $args
         *
         * @return boolean
         */
        function handleMediaRequest( $url, $args = array() ) {
                /* Load tmhOAuth for Media uploads only when needed: https://github.com/themattharris/tmhOAuth */
                /* It's not possible to upload media using WP_HTTP, so this needs to use cURL. */
//                if ( ! class_exists( 'tmhOAuth' ) ) {
//                        require_once( plugin_dir_path( __FILE__ ) . 'tmhOAuth/tmhOAuth.php' );
//                        require_once( plugin_dir_path( __FILE__ ) . 'tmhOAuth/tmhUtilities.php' );
//                }
//                $auth = $args['auth'];
//                if ( ! $auth ) {
//                        $ack = get_option( 'mainwp_tt_app_consumer_key' );
//                        $acs = get_option( 'mainwp_tt_app_consumer_secret' );
//                        $ot  = get_option( 'mainwp_tt_oauth_token' );
//                        $ots = get_option( 'mainwp_tt_oauth_token_secret' );
//                } else {
//                        $ack = get_user_meta( $auth, 'mainwp_tt_app_consumer_key', true );
//                        $acs = get_user_meta( $auth, 'mainwp_tt_app_consumer_secret', true );
//                        $ot  = get_user_meta( $auth, 'mainwp_tt_oauth_token', true );
//                        $ots = get_user_meta( $auth, 'mainwp_tt_oauth_token_secret', true );
//                }
//                // when performing as a scheduled action, need to include file.php
//                if ( ! function_exists( 'get_home_path' ) ) {
//                        require_once( ABSPATH . 'wp-admin/includes/file.php' );
//                }
//                $connect    = array(
//                        'consumer_key'    => $ack,
//                        'consumer_secret' => $acs,
//                        'user_token'      => $ot,
//                        'user_secret'     => $ots
//                );
//                $tmhOAuth   = new tmhOAuth( $connect );
//                $attachment = $args['media'];
//
//                $image_sizes = get_intermediate_image_sizes();
//                if ( in_array( 'large', $image_sizes ) ) {
//                        $size = 'large';
//                } else {
//                        $size = array_pop( $image_sizes );
//                }
//                $upload    = wp_get_attachment_image_src( $attachment, apply_filters( 'wpt_upload_image_size', $size ) );
//                $image_url = $upload[0];
//                $remote    = wp_remote_get( $image_url );
//                $binary    = wp_remote_retrieve_body( $remote );
//
//                $mime_type = get_post_mime_type( $attachment );
//                if ( ! $mime_type ) {
//                        $mime_type = 'image/jpeg';
//                }
                /* End New */

//                $code  = $tmhOAuth->request(
//                        'POST',
//                        $url,
//                        array( 'media' => "$binary" ),
//                        true, // use auth
//                        true  // multipart
//                );
//
//                $response = $tmhOAuth->response['response'];
//                $full = $tmhOAuth->response;
//                wpt_mail( "Media Posted - Media ID #$args[media]", print_r( $full, 1 ) . "\n" . "\n" . print_r( $upload, 1 ) .  "\n" . $image_url );
//
//                if ( is_wp_error( $response ) ) {
//                        return '';
//                }
//
//                $this->http_code     = $code;
//                $this->last_api_call = $url;
//                $this->format        = 'json';
//                $this->http_header   = $response;
//                $response            = json_decode( $response );
//                $media_id            = $response->media_id_string;
//
//                return $media_id;
        }

        /**
         * Format and sign an MainWPOAuth / API request
         */
        function MainWPOAuthRequest( $url, $args = array(), $method = null ) {

                //Handle media requests using tmhOAuth library.
//                if ( $method == 'MEDIA' ) {
//                        return $this->handleMediaRequest( $url, $args );
//                }

                if ( empty( $method ) ) {
                        $method = empty( $args ) ? "GET" : "POST";
                }
                $req = MainWPOAuthRequest::from_consumer_and_token( $this->consumer, $this->token, $method, $url, $args );
                $req->sign_request( $this->sha1_method, $this->consumer, $this->token );

                $response = false;
                $url      = null;

                switch ( $method ) {
                        case 'GET':
                                $url      = $req->to_url();
                                $response = wp_remote_get( $url );
                                break;
                        case 'POST':
                                $url      = $req->get_normalized_http_url();
                                $args     = wp_parse_args( $req->to_postdata() );
                                $response = wp_remote_post( $url, array( 'body' => $args, 'timeout' => 30 ) );
                                break;
                }

                if ( is_wp_error( $response ) ) {
                        return false;
                }

                $this->http_code     = $response['response']['code'];
                $this->last_api_call = $url;
                $this->format        = 'json';
                $this->http_header   = $response['headers'];

                return $response['body'];
        }
}
