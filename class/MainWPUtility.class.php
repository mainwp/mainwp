<?php
class MainWPUtility
{
    public static function startsWith($haystack, $needle)
    {
        return !strncmp($haystack, $needle, strlen($needle));
    }

    public static function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }

    public static function getNiceURL($pUrl, $showHttp = false)
    {
        $url = $pUrl;

        if (self::startsWith($url, 'http://'))
        {
            if (!$showHttp) $url = substr($url, 7);
        }
        else if (self::startsWith($pUrl, 'https://'))
        {
            if (!$showHttp) $url = substr($url, 8);
        }
        else
        {
            if ($showHttp) $url = 'http://'.$url;
        }

        if (self::endsWith($url, '/'))
        {
            if (!$showHttp) $url = substr($url, 0, strlen($url) - 1);
        }
        else
        {
            $url = $url . '/';
        }
        return $url;
    }

    public static function limitString($pInput, $pMax = 500)
    {
        $output = strip_tags($pInput);
        if (strlen($output) > $pMax) {
            // truncate string
            $outputCut = substr($output, 0, $pMax);
            // make sure it ends in a word so assassinate doesn't become ass...
            $output = substr($outputCut, 0, strrpos($outputCut, ' ')).'...';
        }
        echo $output;
    }

    public static function isAdmin()
    {
        global $current_user;
        if ($current_user->ID == 0) return false;

        if ($current_user->wp_user_level == 10 || (isset($current_user->user_level) && $current_user->user_level == 10) || current_user_can('level_10')) {
            return true;
        }
        return false;
    }

    public static function isWebsiteAvailable($website)
    {
        if (is_object($website) && isset($website->url)) {
            $url = $website->url;
            $verifyCertificate = isset($website->verify_certificate) ? $website->verify_certificate : null;
        } else {
            $url = $website;
            $verifyCertificate = null;
        }
        
        if (!self::isDomainValid($url)) return false;

        return MainWPUtility::tryVisit($url, $verifyCertificate);
    }

    private static function isDomainValid($url)
    {
        //check, if a valid url is provided
        return filter_var($url, FILTER_VALIDATE_URL);
    }


    public static function tryVisit($url, $verifyCertificate = null)
    {
        $agent = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
        $postdata = array('test' => 'yes');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        
        $ssl_verifyhost = false;        
        if ($verifyCertificate !== null) { 
            if ($verifyCertificate == 1) {
                $ssl_verifyhost = true;
            } else if ($verifyCertificate == 2) { // use global setting
                if (((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)))
                {
                    $ssl_verifyhost = true;
                }                
            } 
        } else {            
            if (((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)))
            {
                $ssl_verifyhost = true;
            }            
        }
        
        if ($ssl_verifyhost)
        {
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 2);
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, true);
        }
        else
        {
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, false);
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $disabled_functions = ini_get('disable_functions');
        if (empty($disabled_functions) || (stristr($disabled_functions, 'curl_multi_exec') === false))
        {
            $mh = curl_multi_init();
            @curl_multi_add_handle($mh, $ch);

            do
            {
                curl_multi_exec($mh, $running); //Execute handlers
                while ($info = curl_multi_info_read($mh))
                {
                    $data = curl_multi_getcontent($info['handle']);
                    $err = curl_error($info['handle']);
                    $http_status = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
                    $err = curl_error($info['handle']);
                    $realurl = curl_getinfo($info['handle'], CURLINFO_EFFECTIVE_URL);

                    curl_multi_remove_handle($mh, $info['handle']);
                }
                usleep(10000);
            } while ($running > 0);

            curl_multi_close($mh);
        }
        else
        {
            $data = curl_exec($ch);
            $err = curl_error($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $realurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
        }

        MainWPLogger::Instance()->debug(' :: tryVisit :: [url=' . $url .'] [http_status='.$http_status.'] [error='.$err . '] [data=' . $data . ']');

        if ($data === FALSE)
        {
            return array('error' => ($err == '' ? 'Invalid host.' : $err));
        }

        $host = parse_url($realurl, PHP_URL_HOST);
        $ip = false;
        $target = false;

        $dnsRecord = dns_get_record($host);
        if ($dnsRecord === false)
        {
            return array('error' => 'Invalid host.');
        }
        else
        {
            if (!isset($dnsRecord['ip']))
            {
                foreach ($dnsRecord as $dnsRec)
                {
                    if (isset($dnsRec['ip']))
                    {
                        $ip = $dnsRec['ip'];
                        break;
                    }
                }
            }
            else
            {
                $ip = $dnsRecord['ip'];
            }

            $found = false;
            if (!isset($dnsRecord['host']))
            {
                foreach ($dnsRecord as $dnsRec)
                {
                    if ($dnsRec['host'] == $host)
                    {
                        if ($dnsRec['type'] == 'CNAME') $target = $dnsRec['target'];
                        $found = true;
                        break;
                    }
                }
            }
            else
            {
                $found = ($dnsRecord['host'] == $host);
                if ($dnsRecord['type'] == 'CNAME') $target = $dnsRecord['target'];
            }

            if (!$found)
            {
                return array('error' => 'Invalid host.'); // Got redirected to: ' . $dnsRecord['host'])));
            }
        }

        if ($ip === false) $ip = gethostbynamel($host);
        if (($target !== false) && ($target != $host)) $host .= ' (CNAME: ' . $target . ')';

        $out = array('host' => $host, 'httpCode' => $http_status, 'error' => $err, 'httpCodeString' => self::getHttpStatusErrorString($http_status));
        if ($ip !== false) $out['ip'] = $ip;

        return $out;
    }



    protected static function getHttpStatusErrorString($httpCode)
    {
        if ($httpCode == 100) return "Continue";
        if ($httpCode == 101) return "Switching Protocols";
        if ($httpCode == 200) return "OK";
        if ($httpCode == 201) return "Created";
        if ($httpCode == 202) return "Accepted";
        if ($httpCode == 203) return "Non-Authoritative Information";
        if ($httpCode == 204) return "No Content";
        if ($httpCode == 205) return "Reset Content";
        if ($httpCode == 206) return "Partial Content";
        if ($httpCode == 300) return "Multiple Choices";
        if ($httpCode == 301) return "Moved Permanently";
        if ($httpCode == 302) return "Found";
        if ($httpCode == 303) return "See Other";
        if ($httpCode == 304) return "Not Modified";
        if ($httpCode == 305) return "Use Proxy";
        if ($httpCode == 306) return "(Unused)";
        if ($httpCode == 307) return "Temporary Redirect";
        if ($httpCode == 400) return "Bad Request";
        if ($httpCode == 401) return "Unauthorized";
        if ($httpCode == 402) return "Payment Required";
        if ($httpCode == 403) return "Forbidden";
        if ($httpCode == 404) return "Not Found";
        if ($httpCode == 405) return "Method Not Allowed";
        if ($httpCode == 406) return "Not Acceptable";
        if ($httpCode == 407) return "Proxy Authentication Required";
        if ($httpCode == 408) return "Request Timeout";
        if ($httpCode == 409) return "Conflict";
        if ($httpCode == 410) return "Gone";
        if ($httpCode == 411) return "Length Required";
        if ($httpCode == 412) return "Precondition Failed";
        if ($httpCode == 413) return "Request Entity Too Large";
        if ($httpCode == 414) return "Request-URI Too Long";
        if ($httpCode == 415) return "Unsupported Media Type";
        if ($httpCode == 416) return "Requested Range Not Satisfiable";
        if ($httpCode == 417) return "Expectation Failed";
        if ($httpCode == 500) return "Internal Server Error";
        if ($httpCode == 501) return "Not Implemented";
        if ($httpCode == 502) return "Bad Gateway";
        if ($httpCode == 503) return "Service Unavailable";
        if ($httpCode == 504) return "Gateway Timeout";
        if ($httpCode == 505) return "HTTP Version Not Supported";

        return null;
    }

    static function getNotificationEmail($user = null)
    {
        if ($user == null)
        {
            global $current_user;
            $user = $current_user;
        }

        if ($user == null) return null;

        if (!($user instanceof WP_User)) return null;

        $userExt = MainWPDB::Instance()->getUserExtension();
        if ($userExt->user_email != '') return $userExt->user_email;

        return $user->user_email;
    }

    /*
     * $website: Expected object ($website->id, $website->url, ... returned by MainWPDB)
     * $what: What function needs to be called - defined in the mainwpplugin
     * $params: (Optional) array(key => value, key => value);
     */

    static function getPostDataAuthed(&$website, $what, $params = null)
    {
        if ($website && $what != '') {
            $data = array();
            $data['user'] = $website->adminname;
            $data['function'] = $what;
            $data['nonce'] = rand(0,9999);
            if ($params != null) {
                $data = array_merge($data, $params);
            }

            if (($website->nossl == 0) && function_exists('openssl_verify')) {
                $data['nossl'] = 0;
                @openssl_sign($what . $data['nonce'], $signature, base64_decode($website->privkey));
            }
            else
            {
                $data['nossl'] = 1;
                $signature = md5($what . $data['nonce'] . $website->nosslkey);
            }
            $data['mainwpsignature'] = base64_encode($signature);
            return http_build_query($data, '', '&');
        }
        return null;
    }

    static function getGetDataAuthed($website, $paramValue, $paramName = 'where', $asArray = false)
    {
        $params = array();
        if ($website && $paramValue != '')
        {
            $nonce = rand(0,9999);
            if (($website->nossl == 0) && function_exists('openssl_verify')) {
                $nossl = 0;
                openssl_sign($paramValue . $nonce, $signature, base64_decode($website->privkey));
            }
            else
            {
                $nossl = 1;
                $signature = md5($paramValue . $nonce . $website->nosslkey);
            }
            $signature = base64_encode($signature);

            $params = array(
                'login_required' => 1,
                'user' => rawurlencode($website->adminname),
                'mainwpsignature' => rawurlencode($signature),
                'nonce' => $nonce,
                'nossl' => $nossl,
                $paramName => rawurlencode($paramValue)
            );
        }

        if ($asArray) return $params;

        $url = (isset($website->siteurl) && $website->siteurl != '' ? $website->siteurl : $website->url);
        $url .= (substr($url, -1) != '/' ? '/' : '');
        $url .= '?';

        foreach ($params as $key => $value)
        {
            $url .= $key . '=' . $value . '&';
        }

        return rtrim($url, '&');
    }

    /*
     * $url: String
     * $admin: admin username
     * $what: What function needs to be called - defined in the mainwpplugin
     * $params: (Optional) array(key => value, key => value);
     */

    static function getPostDataNotAuthed($url, $admin, $what, $params = null)
    {
        if ($url != '' && $admin != '' && $what != '') {
            $data = array();
            $data['user'] = $admin;
            $data['function'] = $what;
            if ($params != null) {
                $data = array_merge($data, $params);
            }
            return http_build_query($data, '', '&');
        }
        return null;
    }

    /*
     * $websites: Expected array of objects ($website->id, $website->url, ... returned by MainWPDB) indexed by the object->id
     * $what: What function needs to be called - defined in the mainwpplugin
     * $params: (Optional) array(key => value, key => value);
     * $handler: Name of a function to be called:
     *      function handler($data, $website, &$output) {}
     *          the $data = data returned by the request, $website = website object returned by MainWPDB
     *          $output has to be filled in by the handler-function - it is used as an output variable!
     */

    static function fetchUrlsAuthed(&$websites, $what, $params = null, $handler, &$output, $whatPage = null)
    {
        if (!is_array($websites) || empty($websites)) return;

        $chunkSize = 10;
        if (count($websites) > $chunkSize)
        {
            $total = count($websites);
            $loops = ceil($total / $chunkSize);
            for ($i = 0; $i < $loops; $i++)
            {
                $newSites = array_slice($websites, $i * $chunkSize, $chunkSize, true);
                self::fetchUrlsAuthed($newSites, $what, $params, $handler, $output, $whatPage);
                sleep(5);
            }

            return;
        }

        $agent= 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
        $mh = curl_multi_init();

        $timeout = 20 * 60 * 60; //20 minutes

        $handleToWebsite = array();
        $requestUrls = array();
        $requestHandles = array();
        foreach ($websites as $website)
        {
            $url = $website->url;
            if (substr($url, -1) != '/') { $url .= '/'; }

            if (strpos($url, 'wp-admin') === false)
            {
                $url .= 'wp-admin/';
            }

            if ($whatPage != null) $url .= $whatPage;
            else $url .= 'admin-ajax.php';

            $_new_post = null;
            if (isset($params) && isset($params['new_post']))
            {
                $_new_post = $params['new_post'];
                $params = apply_filters('mainwp-pre-posting-posts', (is_array($params) ? $params : array()), (object)array('id' => $website->id, 'url' => $website->url, 'name' => $website->name));
            }

            $ch = curl_init();

            if ($website != null)
            {
                $dirs = self::getMainWPDir();
                $cookieDir = $dirs[0] . 'cookies';
                @mkdir($cookieDir, 0777, true);

                $cookieFile = $cookieDir . '/' . sha1(sha1('mainwp' . $website->id) . 'WP_Cookie');
                if (!file_exists($cookieFile))
                {
                    @file_put_contents($cookieFile, '');
                }

                if (file_exists($cookieFile))
                {
                    @curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
                    @curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
                }
            }

            @curl_setopt($ch, CURLOPT_URL, $url);
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            @curl_setopt($ch, CURLOPT_POST, true);
            $postdata = MainWPUtility::getPostDataAuthed($website, $what, $params);
            @curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            @curl_setopt($ch, CURLOPT_USERAGENT, $agent);
            
            $ssl_verifyhost = false;
            $verifyCertificate = isset($website->verify_certificate) ? $website->verify_certificate : null ;
            if ($verifyCertificate !== null) { 
                if ($verifyCertificate == 1) {
                    $ssl_verifyhost = true;
                } else if ($verifyCertificate == 2) { // use global setting
                    if (((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)))
                    {
                        $ssl_verifyhost = true;
                    }                
                } 
            } else {            
                if (((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)))
                {
                    $ssl_verifyhost = true;
                }            
            }

            if ($ssl_verifyhost)
            {
                @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 2);
                @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, true);
            }
            else
            {
                @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, false);
                @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
            }

            @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); //20minutes
            if (!ini_get('safe_mode')) @set_time_limit($timeout); //20minutes
            @ini_set('max_execution_time', $timeout);

            @curl_multi_add_handle($mh, $ch);
            $handleToWebsite[self::get_resource_id($ch)] = $website;
            $requestUrls[self::get_resource_id($ch)] = $website->url;
            $requestHandles[self::get_resource_id($ch)] = $ch;

            if ($_new_post != null) $params['new_post'] = $_new_post; // reassign new_post
        }


        $disabled_functions = ini_get('disable_functions');
        if (empty($disabled_functions) || (stristr($disabled_functions, 'curl_multi_exec') === false))
        {
            $lastRun = 0;
            do
            {
                if (time() - $lastRun > 20)
                {
                    @set_time_limit($timeout); //reset timer..
                    $lastRun = time();
                }

                curl_multi_exec($mh, $running); //Execute handlers
                while ($info = curl_multi_info_read($mh))
                {
                    $data = curl_multi_getcontent($info['handle']);
                    $contains = (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0);
                    curl_multi_remove_handle($mh, $info['handle']);

                    if (!$contains && isset($requestUrls[self::get_resource_id($info['handle'])]))
                    {
                      curl_setopt($info['handle'], CURLOPT_URL, $requestUrls[self::get_resource_id($info['handle'])]);
                      curl_multi_add_handle($mh, $info['handle']);
                      unset($requestUrls[self::get_resource_id($info['handle'])]);
                      $running++;
                      continue;
                    }

                    if ($handler != null)
                    {
                        $site = &$handleToWebsite[self::get_resource_id($info['handle'])];
                        call_user_func($handler, $data, $site, $output);
                    }

                    unset($handleToWebsite[self::get_resource_id($info['handle'])]);
                    if (gettype($info['handle']) == 'resource') curl_close($info['handle']);
                    unset($info['handle']);
                }
                usleep(10000);
            } while ($running > 0);

            curl_multi_close($mh);
        }
        else
        {
            foreach ($requestHandles as $id => $ch)
            {
                $data = curl_exec($ch);

                if ($handler != null)
                {
                    $site = &$handleToWebsite[self::get_resource_id($ch)];
                    call_user_func($handler, $data, $site, $output);
                }
            }
        }
        return true;
    }

    static function fetchUrlAuthed(&$website, $what, $params = null, $checkConstraints = false, $pForceFetch = false, $pRetryFailed = true)
    {
        if ($params == null) $params = array();
        $params['optimize'] = ((get_option("mainwp_optimize") == 1) ? 1 : 0);

        $postdata = MainWPUtility::getPostDataAuthed($website, $what, $params);
        $information = MainWPUtility::fetchUrl($website, $website->url, $postdata, $checkConstraints, $pForceFetch, $website->verify_certificate, $pRetryFailed);
      
        if (is_array($information) && isset($information['sync']) && !empty($information['sync']))
        {
            MainWPSync::syncInformationArray($website, $information['sync']);
            unset($information['sync']);
        }

        return $information;
    }

    static function fetchUrlNotAuthed($url, $admin, $what, $params = null, $pForceFetch = false, $verifyCertificate = null)
    {
        $postdata = MainWPUtility::getPostDataNotAuthed($url, $admin, $what, $params);
        $website = null;
        return MainWPUtility::fetchUrl($website, $url, $postdata, $pForceFetch, false, $verifyCertificate);
    }

    static function fetchUrlClean($url, $postdata)
    {
        $agent= 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);

        if (((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)))
        {
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 2);
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, true);
        }
        else
        {
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, false);
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $data = curl_exec($ch);
        curl_close($ch);
        if (!$data) {
            throw new Exception('HTTPERROR');
        }
        else
        {
            return $data;
        }
    }

    static function fetchUrl(&$website, $url, $postdata, $checkConstraints = false, $pForceFetch = false, $verifyCertificate = null, $pRetryFailed = true)
    {
        $start = time();

        try
        {
            $tmpUrl = $url;
            if (substr($tmpUrl, -1) != '/') { $tmpUrl .= '/'; }

            if (strpos($url, 'wp-admin') === false)
            {
                $tmpUrl .= 'wp-admin/admin-ajax.php';
            }

            return self::_fetchUrl($website, $tmpUrl, $postdata, $checkConstraints, $pForceFetch, $verifyCertificate);
        }
        catch (Exception $e)
        {
            if (!$pRetryFailed || ((time() - $start) > 30))
            {
                //If more then 30secs past since the initial request, do not retry this!
                throw $e;
            }

            try
            {
                return self::_fetchUrl($website, $url, $postdata, $checkConstraints, $pForceFetch, $verifyCertificate);
            }
            catch (Exception $ex)
            {
                throw $e;
            }
        }
    }

    static function _fetchUrl(&$website, $url, $postdata, $checkConstraints = false, $pForceFetch = false, $verifyCertificate = null)
    {
        $agent= 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';

        if (!$pForceFetch)
        {
            //todo: RS:
            //check if offline
        }

        $identifier = null;
        if ($checkConstraints)
        {
            $semLock = '103218'; //SNSyncLock
            //Lock
            $identifier = MainWPUtility::getLockIdentifier($semLock);

            //Check the delays
            //In MS
            $minimumDelay = ((get_option('mainwp_minimumDelay') === false) ? 200 : get_option('mainwp_minimumDelay'));
            if ($minimumDelay > 0) $minimumDelay = $minimumDelay / 1000;
            $minimumIPDelay = ((get_option('mainwp_minimumIPDelay') === false) ? 1000 : get_option('mainwp_minimumIPDelay'));
            if ($minimumIPDelay > 0) $minimumIPDelay = $minimumIPDelay / 1000;

            MainWPUtility::endSession();
            $delay = true;
            while ($delay)
            {
                MainWPUtility::lock($identifier);

                if ($minimumDelay > 0)
                {
                    //Check last request overall
                    $lastRequest = MainWPDB::Instance()->getLastRequestTimestamp();
                    if ($lastRequest > ((microtime(true)) - $minimumDelay))
                    {
                        //Delay!
                        MainWPUtility::release($identifier);
                        usleep(($minimumDelay - ((microtime(true)) - $lastRequest)) * 1000 * 1000);
                        continue;
                    }
                }

                if ($minimumIPDelay > 0 && $website != null)
                {
                    //Get ip of this site url
                    $ip = MainWPDB::Instance()->getWPIp($website->id);

                    if ($ip != null && $ip != '')
                    {
                        //Check last request for this site
                        $lastRequest = MainWPDB::Instance()->getLastRequestTimestamp($ip);

                        //Check last request for this subnet?
                        if ($lastRequest > ((microtime(true)) - $minimumIPDelay))
                        {
                            //Delay!
                            MainWPUtility::release($identifier);
                            usleep(($minimumIPDelay - ((microtime(true)) - $lastRequest)) * 1000 * 1000);
                            continue;
                        }
                    }
                }

                $delay = false;
            }

            //Check the simultaneous requests
            $maximumRequests = ((get_option('mainwp_maximumRequests') === false) ? 4 : get_option('mainwp_maximumRequests'));
            $maximumIPRequests = ((get_option('mainwp_maximumIPRequests') === false) ? 1 : get_option('mainwp_maximumIPRequests'));

            $first = true;
            $delay = true;
            while ($delay)
            {
                if (!$first) MainWPUtility::lock($identifier);
                else $first = false;

                //Clean old open requests (may have timed out or something..)
                MainWPDB::Instance()->closeOpenRequests();

                if ($maximumRequests > 0)
                {
                    $nrOfOpenRequests = MainWPDB::Instance()->getNrOfOpenRequests();
                    if ($nrOfOpenRequests >= $maximumRequests)
                    {
                        //Delay!
                        MainWPUtility::release($identifier);
                        //Wait 200ms
                        usleep(200000);
                        continue;
                    }
                }

                if ($maximumIPRequests > 0 && $website != null)
                {
                    //Get ip of this site url
                    $ip = MainWPDB::Instance()->getWPIp($website->id);

                    if ($ip != null && $ip != '')
                    {
                        $nrOfOpenRequests = MainWPDB::Instance()->getNrOfOpenRequests($ip);
                        if ($nrOfOpenRequests >= $maximumIPRequests)
                        {
                            //Delay!
                            MainWPUtility::release($identifier);
                            //Wait 200ms
                            usleep(200000);
                            continue;
                        }
                    }
                }

                $delay = false;
            }
        }

        if ($website != null)
        {
            //Log the start of this request!
            MainWPDB::Instance()->insertOrUpdateRequestLog($website->id, null, microtime(true), null);
        }

        if ($identifier != null)
        {
            //Unlock
            MainWPUtility::release($identifier);
        }

        $ch = curl_init();

        if ($website != null)
        {
            $dirs = self::getMainWPDir();
            $cookieDir = $dirs[0] . 'cookies';
            if (!@file_exists($cookieDir)) @mkdir($cookieDir, 0777, true);

            $cookieFile = $cookieDir . '/' . sha1(sha1('mainwp' . $website->id) . 'WP_Cookie');
            if (!file_exists($cookieFile))
            {
                @file_put_contents($cookieFile, '');
            }

            if (file_exists($cookieFile))
            {
                @curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
                @curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            }
        }

        @curl_setopt($ch, CURLOPT_URL, $url);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        @curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        
        $ssl_verifyhost = false;
        if ($verifyCertificate !== null) { 
            if ($verifyCertificate == 1) {
                $ssl_verifyhost = true;
            } else if ($verifyCertificate == 2) { // use global setting
                if (((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)))
                {
                    $ssl_verifyhost = true;
                }                
            } 
        } else {            
            if (((get_option('mainwp_sslVerifyCertificate') === false) || (get_option('mainwp_sslVerifyCertificate') == 1)))
            {
                $ssl_verifyhost = true;
            }            
        }
        
        if ($ssl_verifyhost)
        {
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 2);
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, true);
        }
        else
        {
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, false);
            @curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
            
        $timeout = 20 * 60 * 60; //20 minutes
        @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if (!ini_get('safe_mode')) @set_time_limit($timeout);
        @ini_set('max_execution_time', $timeout);
        MainWPUtility::endSession();

        $disabled_functions = ini_get('disable_functions');
        if (empty($disabled_functions) || (stristr($disabled_functions, 'curl_multi_exec') === false))
        {
            $mh = @curl_multi_init();
            @curl_multi_add_handle($mh, $ch);

            $lastRun = 0;
            do
            {
                if (time() - $lastRun > 20)
                {
                    @set_time_limit($timeout); //reset timer..
                    $lastRun = time();
                }
                @curl_multi_exec($mh, $running); //Execute handlers

                //$ready = curl_multi_select($mh);
                while ($info = @curl_multi_info_read($mh))
                {
                    $data = @curl_multi_getcontent($info['handle']);

                    $http_status = @curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
                    $err = @curl_error($info['handle']);
                    $real_url = @curl_getinfo($info['handle'], CURLINFO_EFFECTIVE_URL);

                    @curl_multi_remove_handle($mh, $info['handle']);
                }
                usleep(10000);
            } while ($running > 0);

            @curl_multi_close($mh);
        }
        else
        {
            $data = @curl_exec($ch);
            $http_status = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = @curl_error($ch);
            $real_url = @curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        }

        $host = parse_url($real_url, PHP_URL_HOST);
        $ip = gethostbyname($host);

        if ($website != null)
        {
            MainWPDB::Instance()->insertOrUpdateRequestLog($website->id, $ip, null, microtime(true));
        }

        if (($data === false) && ($http_status == 0))
        {
            MainWPLogger::Instance()->debugForWebsite($website, 'fetchUrl', '[' . $url . '] HTTP Error: [status=0][' . $err . ']');
            throw new MainWPException('HTTPERROR', $err);
        }
        else if (empty($data) && !empty($err))
        {
            MainWPLogger::Instance()->debugForWebsite($website, 'fetchUrl', '[' . $url . '] HTTP Error: [status=' . $http_status . '][' . $err . ']');
            throw new MainWPException('HTTPERROR', $err);
        }
        else if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $result = $results[1];
            $information = unserialize(base64_decode($result));
            return $information;
        }
        else
        {
            MainWPLogger::Instance()->debugForWebsite($website, 'fetchUrl', '[' . $url . '] Result was: [' . $data . ']');
            throw new MainWPException('NOMAINWP', $url);
        }
    }

    static function ctype_digit($str)
    {
        return (is_string($str) || is_int($str) || is_float($str)) && preg_match('/^\d+\z/', $str);
    }

    static function log($text)
    {

    }

    public static function downloadToFile($url, $file, $size = false)
    {
        if (@file_exists($file) && (($size === false) || (@filesize($file) > $size)))
        {
            @unlink($file);
        }

        if (!@file_exists(@dirname($file)))
        {
            @mkdir(@dirname($file), 0777, true);
        }

        if (!@file_exists(@dirname($file)))
        {
            throw new MainWPException(__('Could not create directory to download the file.'));
        }

        if (!@is_writable(@dirname($file)))
        {
            throw new MainWPException(__('MainWP upload directory is not writable.'));
        }

        $fp = fopen($file, 'a');
        $agent= 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
        if ($size !== false)
        {
            if (@file_exists($file))
            {
                $size = @filesize($file);
                $url .= '&foffset='.$size;
            }
        }
        $ch = curl_init(str_replace(' ', '%20', $url));
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }

    static function getBaseDir()
    {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . DIRECTORY_SEPARATOR;
    }

    public static function getMainWPDir()
    {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
        $url = $upload_dir['baseurl'] . '/mainwp/';
        if (!file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!file_exists($dir . 'index.php'))
        {
            @touch($dir . 'index.php');
        }
        return array($dir, $url);
    }

    public static function getDownloadUrl($what, $filename)
    {
        $specificDir = MainWPUtility::getMainWPSpecificDir($what);
        $mwpDir = MainWPUtility::getMainWPDir();
        $mwpDir = $mwpDir[0];
        $fullFile = $specificDir . $filename;

        return admin_url('?sig=' . md5(filesize($fullFile)) . '&mwpdl=' . rawurlencode(str_replace($mwpDir, "", $fullFile)));
    }

    public static function getMainWPSpecificDir($dir = null)
    {
        if (MainWPSystem::Instance()->isSingleUser())
        {
            $userid = 0;
        }
        else
        {
            global $current_user;
            $userid = $current_user->ID;
        }

        $dirs = self::getMainWPDir();
        $newdir = $dirs[0] . $userid . ($dir != null ? DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR : '');

        if (!file_exists($newdir))
        {
            @mkdir($newdir, 0777, true);
        }

        if ($dirs[0] . $userid != null && !file_exists(trailingslashit($dirs[0] . $userid) . '.htaccess'))
        {
            $file = @fopen(trailingslashit($dirs[0] . $userid) . '.htaccess', 'w+');
            @fwrite($file, 'deny from all');
            @fclose($file);
        }

        return $newdir;
    }

    public static function getMainWPSpecificUrl($dir)
    {
        if (MainWPSystem::Instance()->isSingleUser())
        {
            $userid = 0;
        }
        else
        {
            global $current_user;
            $userid = $current_user->ID;
        }
        $dirs = self::getMainWPDir();
        return $dirs[1] . $userid . '/' . $dir . '/';
    }

    public static function getAlexaRank($domain)
    {
        $remote_url = 'http://data.alexa.com/data?cli=10&dat=snbamz&url=' . trim($domain);
        $search_for = '<POPULARITY URL';
        $part = '';
        if ($handle = @fopen($remote_url, "r"))
        {
            while (!feof($handle))
            {
                $part .= fread($handle, 100);
                $pos = strpos($part, $search_for);
                if ($pos === false)
                    continue;
                else
                    break;
            }
            $part .= fread($handle, 100);
            fclose($handle);
        }
        if (!stristr($part, $search_for)) return 0;

        $str = explode($search_for, $part);
        $str = array_shift(explode('"/>', $str[1]));
        $str = explode('TEXT="', $str);

        return $str[1];
    }


    protected static function StrToNum($Str, $Check, $Magic)
    {
        $Int32Unit = 4294967296; // 2^32

        $length = strlen($Str);
        for ($i = 0; $i < $length; $i++)
        {
            $Check *= $Magic;
            //If the float is beyond the boundaries of integer (usually +/- 2.15e+9 = 2^31),
            //  the result of converting to integer is undefined
            //  refer to http://www.php.net/manual/en/language.types.integer.php
            if ($Check >= $Int32Unit) {
                $Check = ($Check - $Int32Unit * (int)($Check / $Int32Unit));
                //if the check less than -2^31
                $Check = ($Check < -2147483648) ? ($Check + $Int32Unit) : $Check;
            }
            $Check += ord($Str{$i});
        }
        return $Check;
    }

//--> for google pagerank
/*
* Genearate a hash for a url
*/
    protected static function HashURL($String)
    {
        $Check1 = MainWPUtility::StrToNum($String, 0x1505, 0x21);
        $Check2 = MainWPUtility::StrToNum($String, 0, 0x1003F);

        $Check1 >>= 2;
        $Check1 = (($Check1 >> 4) & 0x3FFFFC0) | ($Check1 & 0x3F);
        $Check1 = (($Check1 >> 4) & 0x3FFC00) | ($Check1 & 0x3FF);
        $Check1 = (($Check1 >> 4) & 0x3C000) | ($Check1 & 0x3FFF);

        $T1 = (((($Check1 & 0x3C0) << 4) | ($Check1 & 0x3C)) << 2) | ($Check2 & 0xF0F);
        $T2 = (((($Check1 & 0xFFFFC000) << 4) | ($Check1 & 0x3C00)) << 0xA) | ($Check2 & 0xF0F0000);

        return ($T1 | $T2);
    }

    //--> for google pagerank
/*
* genearate a checksum for the hash string
*/
    protected static function CheckHash($Hashnum)
    {
        $CheckByte = 0;
        $Flag = 0;

        $HashStr = sprintf('%u', $Hashnum);
        $length = strlen($HashStr);

        for ($i = $length - 1; $i >= 0; $i--)
        {
            $Re = $HashStr{$i};
            if (1 === ($Flag % 2)) {
                $Re += $Re;
                $Re = (int)($Re / 10) + ($Re % 10);
            }
            $CheckByte += $Re;
            $Flag++;
        }

        $CheckByte %= 10;
        if (0 !== $CheckByte) {
            $CheckByte = 10 - $CheckByte;
            if (1 === ($Flag % 2)) {
                if (1 === ($CheckByte % 2)) {
                    $CheckByte += 9;
                }
                $CheckByte >>= 1;
            }
        }

        return '7' . $CheckByte . $HashStr;
    }

    //get google pagerank
    public static function getpagerank($url)
    {
        $query = "http://toolbarqueries.google.com/tbr?client=navclient-auto&ch=" . MainWPUtility::CheckHash(MainWPUtility::HashURL($url)) . "&features=Rank&q=info:" . $url . "&num=100&filter=0";
        $data = MainWPUtility::file_get_contents_curl($query);
        $pos = strpos($data, "Rank_");
        if ($pos === false) {
        }
        else
        {
            $pagerank = substr($data, $pos + 9);
            return $pagerank;
        }
    }

    protected static function file_get_contents_curl($url)
    {
        $agent= 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        $data = curl_exec($ch);
        curl_close($ch);

        return $data;
    }

    public static function getGoogleCount($domain)
    {
        $content = file_get_contents('http://ajax.googleapis.com/ajax/services/' .
                'search/web?v=1.0&filter=0&q=site:' . urlencode($domain));
        $data = json_decode($content);

        if (empty($data)) return 0;
        if (!property_exists($data, 'responseData')) return 0;
        if (!property_exists($data->responseData, 'cursor')) return 0;
        if (!property_exists($data->responseData->cursor, 'estimatedResultCount')) return 0;

        return intval($data->responseData->cursor->estimatedResultCount);
    }

    public static function countRecursive($array, $levels)
    {
        if ($levels == 0) return count($array);
        $levels--;

        $count = 0;
        foreach ($array as $value)
        {
            if (is_array($value) && ($levels > 0)) {
                $count += MainWPUtility::countRecursive($value, $levels - 1);
            }
            else
            {
                $count += count($value);
            }
        }
        return $count;
    }

    public static function sortmulti($array, $index, $order, $natsort = FALSE, $case_sensitive = FALSE)
    {
        $sorted = array();
        if (is_array($array) && count($array) > 0) {
            foreach (array_keys($array) as $key)
                $temp[$key] = $array[$key][$index];
            if (!$natsort) {
                if ($order == 'asc')
                    asort($temp);
                else
                    arsort($temp);
            }
            else
            {
                if ($case_sensitive === true)
                    natsort($temp);
                else
                    natcasesort($temp);
                if ($order != 'asc')
                    $temp = array_reverse($temp, TRUE);
            }
            foreach (array_keys($temp) as $key)
                if (is_numeric($key))
                    $sorted[] = $array[$key];
                else
                    $sorted[$key] = $array[$key];
            return $sorted;
        }
        return $sorted;
    }

    public static function getSubArrayHaving($array, $index, $value)
    {
        $output = array();
        if (is_array($array) && count($array) > 0) {
            foreach ($array as $arrvalue)
            {
                if ($arrvalue[$index] == $value) $output[] = $arrvalue;
            }
        }
        return $output;
    }

    public static function http_post($request, $http_host, $path, $port = 80, $pApplication = 'main', $throwException = false) {

        if ($pApplication == 'main') $pApplication = 'MainWP/1.1';//.MainWPSystem::Instance()->getVersion();
        else $pApplication = 'MainWPExtension/'.$pApplication.'/v';

        // use the WP HTTP class if it is available
//        if ( function_exists( 'wp_remote_post' ) ) {
        $http_args = array(
            'body'			=> $request,
            'headers'		=> array(
                'Content-Type'	=> 'application/x-www-form-urlencoded; ' .
                        'charset=' . get_option( 'blog_charset' ),
                'Host'			=> $http_host,
                'User-Agent'	=> $pApplication
            ),
            'httpversion'	=> '1.0',
            'timeout'		=> 15
        );
        $mainwp_url = "http://{$http_host}{$path}";

        $response = wp_remote_post( $mainwp_url, $http_args );

        if ( is_wp_error( $response ) )
        {
            if ($throwException)
            {
                throw new Exception($response->get_error_message());
            }
            return '';
        }

        return array( $response['headers'], $response['body'] );
    }

    static function trimSlashes($elem) { return trim($elem, '/'); }

    public static function renderToolTip($pText, $pUrl = null, $pImage = 'images/info.png', $style = null)
    {
        $output = '<span class="tooltipcontainer">';
        if ($pUrl != null) $output .= '<a href="' . $pUrl . '" target="_blank">';
        $output .= '<img src="' . plugins_url($pImage, dirname(__FILE__)) . '" class="tooltip" style="'.($style == null ? '' : $style).'" />';
        if ($pUrl != null) $output .= '</a>';
        $output .= '<span class="tooltipcontent" style="display: none;">' . $pText;
        if ($pUrl != null) $output .= ' (Click to read more)';
        $output .= '</span></span>';
        echo $output;
    }

    public static function encrypt($str, $pass)
    {
        $pass = str_split(str_pad('', strlen($str), $pass, STR_PAD_RIGHT));
        $stra = str_split($str);
        foreach ($stra as $k => $v)
        {
            $tmp = ord($v) + ord($pass[$k]);
            $stra[$k] = chr($tmp > 255 ? ($tmp - 256) : $tmp);
        }
        return base64_encode(join('', $stra));
    }

    public static function decrypt($str, $pass)
    {
        $str = base64_decode($str);
        $pass = str_split(str_pad('', strlen($str), $pass, STR_PAD_RIGHT));
        $stra = str_split($str);
        foreach ($stra as $k => $v)
        {
            $tmp = ord($v) - ord($pass[$k]);
            $stra[$k] = chr($tmp < 0 ? ($tmp + 256) : $tmp);
        }
        return join('', $stra);
    }

    public static function encrypt_legacy($string, $key)
    {
        if (function_exists('mcrypt_encrypt'))
            return base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($key), $string, MCRYPT_MODE_CBC, md5(md5($key))));
        else
            return base64_encode($string);
    }

    public static function decrypt_legacy($encrypted, $key)
    {
        if (function_exists('mcrypt_encrypt'))
            return rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($key), base64_decode($encrypted), MCRYPT_MODE_CBC, md5(md5($key))), "\0");
        else
            return base64_decode($encrypted);
    }

    /**
     * @return WP_Filesystem_Base
     */
    public static function getWPFilesystem()
    {
        global $wp_filesystem;

        if (empty($wp_filesystem))
        {
            ob_start();
            if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
            if (file_exists(ABSPATH . '/wp-admin/includes/template.php')) include_once(ABSPATH . '/wp-admin/includes/template.php');
            $creds = request_filesystem_credentials('test');
            ob_end_clean();
            if (empty($creds))
            {
                define('FS_METHOD', 'direct');
            }
            $init = WP_Filesystem($creds);
        }
        else
        {
            $init = true;
        }

        return $init;
    }

    public static function sanitize($str)
    {
        return preg_replace("/[\\\\\/\:\"\*\?\<\>\|]+/", "", $str);
    }

    public static function formatEmail($to, $body)
    {
        return '<br>
<div>
            <br>
            <div style="background:#ffffff;padding:0 1.618em;font:13px/20px Helvetica,Arial,Sans-serif;padding-bottom:50px!important">
                <div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">
                    <div style="display: block; width: 100% ; background: #fafafa; border-bottom: 2px Solid #7fb100 ; overflow: hidden;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                         <div style="float: left;"><a href="https://mainwp.com"><img src="'. plugins_url('images/logo.png', dirname(__FILE__)) .'" alt="MainWP" height="30"/></a></div>
                         <div style="float: right; margin-top: .6em ;">
                            <span style="display: inline-block; margin-right: .8em;"><a href="https://extensions.mainwp.com" style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;">Extensions</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="https://mainwp.com/forum">Support</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="http://docs.mainwp.com">Documentation</a></span>
                            <span style="display: inline-block; margin-right: .5em;" class="mainwp-memebers-area"><a href="https://mainwp.com/member/login/index" style="padding: .6em .5em ; border-radius: 50px ; -moz-border-radius: 50px ; -webkit-border-radius: 50px ; background: #1c1d1b; border: 1px Solid #000; color: #fff !important; font-size: .9em !important; font-weight: normal ; -webkit-box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1); box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1);">Members Area</a></span>
                         </div><div style="clear: both;"></div>
                      </div>
                    </div>
                    <div>
                        <p>Hello MainWP User!<br></p>
                        ' . $body . '
                        <div></div>
                        <br />
                        <div>MainWP</div>
                        <div><a href="http://www.MainWP.com" target="_blank">www.MainWP.com</a></div>
                        <p></p>
                    </div>

                    <div style="display: block; width: 100% ; background: #1c1d1b;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                        <div style="padding: .5em 0 ; float: left;"><p style="color: #fff; font-family: Helvetica, Sans; font-size: 12px ;">Â© 2013 MainWP. All Rights Reserved.</p></div>
                        <div style="float: right;"><a href="https://mainwp.com"><img src="'. plugins_url('images/g-all-top-menu-item.png', dirname(__FILE__)) .'" height="45"/></a></div><div style="clear: both;"></div>
                      </div>
                   </div>
                </div>
                <center>
                    <br><br><br><br><br><br>
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#ffffff;border-top:1px solid #e5e5e5">
                        <tbody><tr>
                            <td align="center" valign="top" style="padding-top:20px;padding-bottom:20px">
                                <table border="0" cellpadding="0" cellspacing="0">
                                    <tbody><tr>
                                        <td align="center" valign="top" style="color:#606060;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:150%;padding-right:20px;padding-bottom:5px;padding-left:20px;text-align:center">
                                            This email is sent from your MainWP Dashboard.
                                            <br>
                                            If you do not wish to receive these notices please re-check your preferences in the MainWP Settings page.
                                            <br>
                                            <br>
                                        </td>
                                    </tr>
                                </tbody></table>
                            </td>
                        </tr>
                    </tbody></table>

                </center>
            </div>
</div>
<br>';
    }

    public static function endSession()
    {
        session_write_close();
        if (ob_get_length() > 0) ob_end_flush();
    }

    public static function getLockIdentifier($pLockName)
    {
        if (($pLockName == null) || ($pLockName == false)) return false;

        if (function_exists('sem_get')) return sem_get($pLockName);
        else
        {
            $fh = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lock' . $pLockName . '.txt', 'w+');
            if (!$fh) return false;

            return $fh;
        }

        return false;
    }

    public static function lock($pIdentifier)
    {
        if (($pIdentifier == null) || ($pIdentifier == false)) return false;

        if (function_exists('sem_acquire')) return sem_acquire($pIdentifier);
        else
        {
            //Retry lock 3 times
            for ($i = 0; $i < 3; $i++)
            {
                if (@flock($pIdentifier, LOCK_EX))
                {
                    // acquire an exclusive lock
                    return $pIdentifier;
                }
                else
                {
                    //Sleep before lock retry
                    sleep(1);
                }
            }
            return false;
        }

        return false;
    }

    public static function release($pIdentifier)
    {
        if (($pIdentifier == null) || ($pIdentifier == false)) return false;

        if (function_exists('sem_release')) return sem_release($pIdentifier);
        else
        {
            @flock($pIdentifier, LOCK_UN); // release the lock
            @fclose($pIdentifier);
        }
        return false;
    }

    public static function getTimestamp($timestamp)
    {
        $gmtOffset = get_option('gmt_offset');

        return ($gmtOffset ? ($gmtOffset * HOUR_IN_SECONDS) + $timestamp : $timestamp);
    }

    public static function date($format)
    {
        return date($format, self::getTimestamp(time()));
    }

    public static function formatTimestamp($timestamp)
    {
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    public static function human_filesize($bytes, $decimals = 2)
    {
        $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }

    public static function mapSite(&$website, $keys)
    {
        $outputSite = array();
        foreach ($keys as $key)
        {
            $outputSite[$key] = $website->$key;
        }
        return (object)$outputSite;
    }

    public static function can_edit_website(&$website)
    {
        if ($website == null) return false;

        //Everyone may change this website
        if (MainWPSystem::Instance()->isSingleUser()) return true;

        global $current_user;
        return ($website->userid == $current_user->ID);
    }

    public static function can_edit_group(&$group)
    {
        if ($group == null) return false;

        //Everyone may change this website
        if (MainWPSystem::Instance()->isSingleUser()) return true;

        global $current_user;
        return ($group->userid == $current_user->ID);
    }

    public static function can_edit_backuptask(&$task)
    {
        if ($task == null) return false;

        if (MainWPSystem::Instance()->isSingleUser()) return true;

        global $current_user;
        return ($task->userid == $current_user->ID);
    }

    public static function get_current_wpid()
    {
        global $current_user;
        return $current_user->current_site_id;
    }

    public static function set_current_wpid($wpid)
    {
        global $current_user;
        $current_user->current_site_id = $wpid;
    }

    public static function array_merge($arr1, $arr2)
    {
        if (!is_array($arr1) && !is_array($arr2)) return array();
        if (!is_array($arr1)) return $arr2;
        if (!is_array($arr2)) return $arr1;

        $output = array();
        foreach ($arr1 as $el)
        {
            $output[] = $el;
        }
        foreach ($arr2 as $el)
        {
            $output[] = $el;
        }
        return $output;
    }

    public static function getCronSchedules($schedules)
    {
        $schedules['5minutely'] = array(
            'interval' => 5 * 60, // 5minutes in seconds
            'display' => __('Once every 5 minutes', 'mainwp'),
        );
        $schedules['minutely'] = array(
            'interval' => 1 * 60, // 1minute in seconds
            'display' => __('Once every minute', 'mainwp'),
        );
        return $schedules;
    }


    public static function mime_content_type($filename)
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME);
            $mimetype = finfo_file($finfo, $filename);
            finfo_close($finfo);
            return $mimetype;
        }

		if (function_exists('mime_content_type')) {
            return mime_content_type($filename);
        }

		$mime_types = array(

			'txt' => 'text/plain',
			'htm' => 'text/html',
			'html' => 'text/html',
			'php' => 'text/html',
			'css' => 'text/css',
			'js' => 'application/javascript',
			'json' => 'application/json',
			'xml' => 'application/xml',
			'swf' => 'application/x-shockwave-flash',
			'flv' => 'video/x-flv',

			// images
			'png' => 'image/png',
			'jpe' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'gif' => 'image/gif',
			'bmp' => 'image/bmp',
			'ico' => 'image/vnd.microsoft.icon',
			'tiff' => 'image/tiff',
			'tif' => 'image/tiff',
			'svg' => 'image/svg+xml',
			'svgz' => 'image/svg+xml',

			// archives
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'exe' => 'application/x-msdownload',
			'msi' => 'application/x-msdownload',
			'cab' => 'application/vnd.ms-cab-compressed',

			// audio/video
			'mp3' => 'audio/mpeg',
			'qt' => 'video/quicktime',
			'mov' => 'video/quicktime',

			// adobe
			'pdf' => 'application/pdf',
			'psd' => 'image/vnd.adobe.photoshop',
			'ai' => 'application/postscript',
			'eps' => 'application/postscript',
			'ps' => 'application/postscript',

			// ms office
			'doc' => 'application/msword',
			'rtf' => 'application/rtf',
			'xls' => 'application/vnd.ms-excel',
			'ppt' => 'application/vnd.ms-powerpoint',

			// open office
			'odt' => 'application/vnd.oasis.opendocument.text',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
		);

		$ext = strtolower(array_pop(explode('.',$filename)));
		if (array_key_exists($ext, $mime_types)) {
			return $mime_types[$ext];
		}

        return 'application/octet-stream';
    }

    static function update_option($option_name, $option_value)
    {
        $success = add_option($option_name, $option_value, '', 'no');

         if (!$success)
         {
             $success = update_option($option_name, $option_value);
         }

         return $success;
    }

    static function fix_option($option_name)
    {
        global $wpdb;

        if ( 'yes' == $wpdb->get_var( "SELECT autoload FROM $wpdb->options WHERE option_name = '" . $option_name . "'" ) )
        {
            $option_value = get_option( $option_name );
            delete_option( $option_name );
            add_option( $option_name, $option_value, null, 'no' );
        }
    }

    static function get_resource_id($resource)
    {
        if (!is_resource($resource))
            return false;

        $resourceString = (string)$resource;
        $exploded = explode('#', $resourceString);
        $result = array_pop($exploded);

        return $result;
    }

    public static function getFileParameter(&$website)
    {
        if (!isset($website->version) || empty($website->version)) return 'file';
        if (version_compare('0.29.13', $website->version) < 0) return 'f';
        return 'file';
    }

    public static function removePreSlashSpaces($text)
    {
        while (stristr($text, ' /'))
        {
            $text = str_replace(' /', '/', $text);
        }
        return $text;
    }

    public static function removeHttpPrefix($pUrl)
    {
        return str_replace(array('http:', 'https:'), array('', ''), $pUrl);
    }

    public static function isArchive($pFileName, $pPrefix = '', $pSuffix = '')
    {
        return preg_match('/' . $pPrefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $pSuffix . '$/', $pFileName);
    }

    public static function isSQLFile($pFileName)
    {
        return preg_match('/(.*).sql$/', $pFileName) || self::isSQLArchive($pFileName);
    }

    public static function isSQLArchive($pFileName)
    {
        return preg_match('/(.*).sql.(zip|tar|tar.gz|tar.bz2)$/', $pFileName);
    }

    public static function getCurrentArchiveExtension($website = false, $task = false)
    {
        $useSite = true;
        if ($task != false)
        {
            if ($task->archiveFormat == 'global')
            {
                $useGlobal = true;
                $useSite = false;
            }
            else if ($task->archiveFormat == '' || $task->archiveFormat == 'site')
            {
                $useGlobal = false;
                $useSite = true;
            }
            else
            {
                $archiveFormat = $task->archiveFormat;
                $useGlobal = false;
                $useSite = false;
            }
        }

        if ($useSite)
        {
            if ($website == false)
            {
                $useGlobal = true;
            }
            else
            {
                $backupSettings = MainWPDB::Instance()->getWebsiteBackupSettings($website->id);
                $archiveFormat = $backupSettings->archiveFormat;
                $useGlobal = ($archiveFormat == 'global');
            }
        }

        if ($useGlobal)
        {
            $archiveFormat = get_option('mainwp_archiveFormat');
            if ($archiveFormat === false) $archiveFormat = 'tar.gz';
        }

        return $archiveFormat;
    }

    public static function getRealExtension($path)
    {
        $checks = array('.sql.zip', '.sql.tar', '.sql.tar.gz', '.sql.tar.bz2', '.tar.gz', '.tar.bz2');
        foreach ($checks as $check)
        {
            if (self::endsWith($path, $check)) return $check;
        }

        return '.' . pathinfo($path, PATHINFO_EXTENSION);
    }

    public static function sanitize_file_name($filename)
    {
        $filename = str_replace(array('|', '/', '\\', ' ', ':'), array('-', '-', '-', '-', '-'), $filename);
        return sanitize_file_name($filename);
    }

    public static function normalize_filename($s)
    {
        // maps German (umlauts) and other European characters onto two characters before just removing diacritics
        $s    = preg_replace( '@\x{00c4}@u'    , "A",    $s );    // umlaut Ä => A
        $s    = preg_replace( '@\x{00d6}@u'    , "O",    $s );    // umlaut Ö => O
        $s    = preg_replace( '@\x{00dc}@u'    , "U",    $s );    // umlaut Ü => U
        $s    = preg_replace( '@\x{00cb}@u'    , "E",    $s );    // umlaut Ë => E
        $s    = preg_replace( '@\x{00e4}@u'    , "a",    $s );    // umlaut ä => a
        $s    = preg_replace( '@\x{00f6}@u'    , "o",    $s );    // umlaut ö => o
        $s    = preg_replace( '@\x{00fc}@u'    , "u",    $s );    // umlaut ü => u
        $s    = preg_replace( '@\x{00eb}@u'    , "e",    $s );    // umlaut ë => e
        $s    = preg_replace( '@\x{00f1}@u'    , "n",    $s );    // ñ => n
        $s    = preg_replace( '@\x{00ff}@u'    , "y",    $s );    // ÿ => y
        return $s;
    }
    
    public static function showUserTip($tip_id) {
        global $current_user;
        if ($user_id = $current_user->ID) {           
            $reset_tips = get_option("mainwp_reset_user_tips");
            if (!is_array($reset_tips)) $reset_tips = array();                
            if (!isset($reset_tips[$user_id])) { 
                $reset_tips[$user_id] = 1;
                update_option("mainwp_reset_user_tips", $reset_tips);
                update_user_option($user_id, "mainwp_hide_user_tips", array());                 
                return true;
            }

            $hide_usertips = get_user_option('mainwp_hide_user_tips');
            if (!is_array($hide_usertips)) $hide_usertips = array();  
            if (isset($hide_usertips[$tip_id])) {                                                
                return false;
            }            
        }
        return true;
    }

    public static function resetUserCookie($what, $value = "")
    {
        global $current_user;
        if ($user_id = $current_user->ID) {
            $reset_cookies = get_option("mainwp_reset_user_cookies");
            if (!is_array($reset_cookies)) $reset_cookies = array();

            if (!isset($reset_cookies[$user_id]) || !isset($reset_cookies[$user_id][$what])) {
                $reset_cookies[$user_id][$what] = 1;
                MainWPUtility::update_option("mainwp_reset_user_cookies", $reset_cookies);
                update_user_option($user_id, "mainwp_saved_user_cookies", array());
                return false;
            }

            $user_cookies = get_user_option('mainwp_saved_user_cookies');
            if (!is_array($user_cookies)) $user_cookies = array();
            if (!isset($user_cookies[$what])) {
                return false;
            }
        }
        return true;
    }
}