<?php
/*
  Plugin Name: MainWP Dashboard
  Plugin URI: http://mainwp.com/
  Description: Manage all of your WP sites, even those on different servers, from one central dashboard that runs off of your own self-hosted WordPress install.
  Author: MainWP
  Author URI: http://mainwp.com
  Version: 2.0.21
 */
include_once(ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php'); //Version information from wordpress

if (!function_exists('mainwp_autoload'))
{
    function mainwp_autoload($class_name)
    {
        $allowedLoadingTypes = array('class', 'page', 'view', 'widget', 'table');

        foreach ($allowedLoadingTypes as $allowedLoadingType)
        {
            $class_file = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace(basename(__FILE__), '', plugin_basename(__FILE__)) . $allowedLoadingType . DIRECTORY_SEPARATOR . $class_name . '.' . $allowedLoadingType . '.php';
            if (file_exists($class_file))
            {
                require_once($class_file);
            }
        }
    }
}

if (function_exists('spl_autoload_register'))
{
    spl_autoload_register('mainwp_autoload');
}
else
{
    function __autoload($class_name)
    {
        mainwp_autoload($class_name);
    }
}

if (!function_exists('mainwpdir'))
{
    function mainwpdir()
    {
        return WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname(plugin_basename(__FILE__)) . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('mainwp_do_not_have_permissions'))
{
    function mainwp_do_not_have_permissions($where = "", $echo = true)
    {
        $msg = __("You do not have sufficient permissions to access this page (" . ucwords($where) . ").", "mainwp");
        if ($echo)
        {
            echo '<div class="mainwp-permission-error"><p>' . $msg . '</p>If you need access to this page please contact the Dashboard Administrator.</div>';
        }
        else
        {
            return $msg;
        }
        return false;
    }
}

$mainWP = new MainWPSystem(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename(__FILE__));
register_activation_hook(__FILE__, array($mainWP, 'activation'));
register_deactivation_hook(__FILE__, array($mainWP, 'deactivation'));
add_action('plugins_loaded', array($mainWP, 'update'));
