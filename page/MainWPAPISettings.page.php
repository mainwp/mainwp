<?php

class MainWPAPISettings
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function initMenu()
    {
        MainWPAPISettingsView::initMenu();
    }

    public static function render()
    {
        MainWPAPISettingsView::render();
    }

    public static function saveSettings()
    {
        $username = $_POST['username'];
        $password = $_POST['password'];
        update_option("mainwp_api_username", $username);
        update_option("mainwp_api_password", $password);

        $userExtension = MainWPDB::Instance()->getUserExtension();
        $userExtension->pluginDir = (isset($_POST['footprint']) && $_POST['footprint'] == 'true' ? 'hidden' : 'default');

        MainWPDB::Instance()->updateUserExtension($userExtension);

        MainWPAPISettings::testAPIs(null, false, null, null, true);

        return array('result' => 'success', 'api' => MainWPAPISettings::testAPIs('main'));
    }

    public static function testLogin($username, $password)
    {
        $output = array();
        $parseError = true;

        try
        {
            $output['api_status'] = MainWPAPISettings::testAPIs('main', true, $username, $password);
        }
        catch (Exception $e)
        {
            if ($e->getMessage() == 'MAX_ACCOUNTS')
            {
                $output['api_status'] = MAINWP_API_INVALID;
                $output['error'] = MainWPAPISettingsView::maximumInstallationsReached();
            }
            else
            {
                $parseError = false;
                $output['api_status'] = 'ERROR';
                $output['error'] = $e->getMessage();
            }
        }

        if (($output['api_status'] == MAINWP_API_VALID) && ($username == get_option('mainwp_api_username')))
        {
            update_option("mainwp_api_username", $username);
            update_option("mainwp_api_password", $password);
            MainWPAPISettings::testAPIs('main', true, $username, $password, false, true);
        }

       if ($parseError && stristr($output['api_status'], 'ERROR'))
        {
            $output['error'] = substr($output['api_status'], 6);
            $output['api_status'] = 'ERROR';
        }

        return $output;
    }

    public static function refresh()
    {
        $output = array();
        $parseError = true;

        try
        {
            $output['api_status'] = MainWPAPISettings::testAPIs('main', true, null, null, false, true);
        }
        catch (Exception $e)
        {
            if ($e->getMessage() == 'MAX_ACCOUNTS')
            {
                $output['api_status'] = MAINWP_API_INVALID;
                $output['error'] = MainWPAPISettingsView::maximumInstallationsReached();
            }
            else
            {
                $parseError = false;
                $output['api_status'] = 'ERROR';
                $output['error'] = $e->getMessage();
            }
        }

        if ($parseError && stristr($output['api_status'], 'ERROR'))
        {
            $output['error'] = substr($output['api_status'], 6);
            $output['api_status'] = 'ERROR';
        }

        return $output;
    }

    public static function testAPIs($pAPI = null, $forceRequest = false, $username = null, $password = null, $pIgnoreLastCheckTime = false, $saveAnyway = false)
    {
        if ($username == null) $username = get_option('mainwp_api_username');
        if ($password == null) $password = get_option('mainwp_api_password');

        $requestsDB = get_option('mainwp_requests');
        $requests =  isset($requestsDB['requests']) ? unserialize(base64_decode($requestsDB['requests'])) : array();
        $lastRequests = isset($requestsDB['lastRequests']) ? unserialize(base64_decode($requestsDB['lastRequests'])) : array();
        $maxOccurences = isset($requestsDB['maxOccurences']) ? unserialize(base64_decode($requestsDB['maxOccurences'])) : 0;

        $exclusiveResult = '';
        $url = get_home_url();
        //If we force a request,
        //  or API is not yet checked or invalid,
        //  or last request was too long ago
        if ($forceRequest ||
                (($pAPI != null) && (!isset($requests[$pAPI]) || ($requests[$pAPI] == MAINWP_API_INVALID))) ||
                (time() - $requestsDB['lastRequest'] > 24 * 60 * 60)) //Polls every day
        {
            //init requests
            if (!is_array($requests) || !isset($requests['main']))
            {
                $requests = array('main' => MAINWP_API_INVALID);
            }

            //Not exclusive
            if ($pAPI == null)
            {
                //Check all requests
                foreach ($requests as $api => $current)
                {
                    $request = "do=logintest2&username=" . rawurlencode($username) . "&password=" . rawurlencode($password);
                    if ($api == 'main')
                    {
                        $request .= "&url=" . urlencode($url);
                    }
                    $responseArray = MainWPUtility::http_post($request, "mainwp.com", "/versioncontrol/rqst.php", 80, $api, ($forceRequest && !$saveAnyway));
                    $jsonDecodedResp = json_decode($responseArray[1], true);
                    $requests[$api] = ($jsonDecodedResp['status'] == 'valid' ? MAINWP_API_VALID : MAINWP_API_INVALID);
                    if ($api == 'main')
                    {
                        $maxOccurences = $jsonDecodedResp['max'];
                    }
                    $lastRequests[$api] = time();
                }
            }
            else
            {
                //If it was forced or the API is not yet fetched or (invalid && last fetched later then 10 minutes ago)
                if ($forceRequest || !isset($requests[$pAPI]) || ($requests[$pAPI] == MAINWP_API_INVALID && ($pIgnoreLastCheckTime || (!isset($lastRequests[$pAPI]) || ((time() - $lastRequests[$pAPI]) > (10 * 60))))))
                {
                    $request = "do=logintest2&username=" . rawurlencode($username) . "&password=" . rawurlencode($password);
                    if ($pAPI == 'main')
                    {
                        $request .= "&url=" . urlencode($url);
                    }
                    $responseArray = MainWPUtility::http_post($request, "mainwp.com", "/versioncontrol/rqst.php", 80, $pAPI, ($forceRequest && !$saveAnyway));
                    $jsonDecodedResp = json_decode($responseArray[1], true);
                    $requests[$pAPI] = ($jsonDecodedResp['status'] == 'valid' ? MAINWP_API_VALID : MAINWP_API_INVALID);
                    if ($pAPI == 'main')
                    {
                        $maxOccurences = $jsonDecodedResp['max'];
                    }
                    $lastRequests[$pAPI] = time();
                    $exclusiveResult = $requests[$pAPI];

                    //If it was forced we just return the value without saving
                    if ($forceRequest && !$saveAnyway)
                    {
                        if (isset($jsonDecodedResp['error']) && ($jsonDecodedResp['error'] != ''))
                        {
                            throw new Exception($jsonDecodedResp['error']);
                        }

                        return $exclusiveResult;
                    }
                }
            }

            $requestsDB = array('lastRequest' => ($pAPI != null ? (isset($requests['lastRequest']) ? $requests['lastRequest'] : '') : time()), 'requests' => base64_encode(serialize($requests)), 'lastRequests' => base64_encode(serialize($lastRequests)), 'maxOccurences' => base64_encode(serialize($maxOccurences)));
            update_option('mainwp_requests', $requestsDB);
        }

        return ($exclusiveResult != '' ? $exclusiveResult : ($pAPI == null ? null : $requests[$pAPI]));
    }

    public static function checkUpgrade()
    {
        $username = get_option("mainwp_api_username");
        $password = get_option("mainwp_api_password");

        $slugs = MainWPExtensions::getSlugs();
        if ($slugs == '') return array();
        try
        {
            $responseArray = MainWPUtility::http_post("do=checkUpgradeV2&username=" . rawurlencode($username) . "&password=" . rawurlencode($password) . '&slugs=' . $slugs, "mainwp.com", "/versioncontrol/rqst.php", true);
        }
        catch (Exception $e)
        {
            MainWPLogger::Instance()->warning('An error occured when trying to reach the MainWP server: ' . $e->getMessage());
        }

        if (!is_array($responseArray) || !isset($responseArray[1])) return null;

        $rslt = json_decode($responseArray[1]);
        if (empty($rslt)) return null;

        $output = array();
        foreach ($rslt as $upgrade)
        {
            $output[] = $upgrade;
        }

        return $output;
    }

    public static function getUpgradeInformation($pSlug)
    {
        $username = get_option("mainwp_api_username");
        $password = get_option("mainwp_api_password");

        try
        {
            $responseArray = MainWPUtility::http_post('do=getUpgradeInformationV2&slugs='.$pSlug.'&username=' . rawurlencode($username) . "&password=" . rawurlencode($password), "mainwp.com", "/versioncontrol/rqst.php", true);
        }
        catch (Exception $e)
        {
            MainWPLogger::Instance()->warning('An error occured when trying to reach the MainWP server: ' . $e->getMessage());
            return null;
        }

        if ((stripos($responseArray[1], 'error') !== false) && (stripos($responseArray[1], 'error') == 0)) return null;

        $rslt = unserialize($responseArray[1]);
        $rslt->slug = MainWPSystem::Instance()->slug;
        return $rslt;
    }
}

?>
