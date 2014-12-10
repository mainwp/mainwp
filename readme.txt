=== MainWP ===
Contributors: mainwp
Donate link: 
Tags: WordPress Management, WordPress Controller
Author: mainwp
Author URI: https://mainwp.com
Plugin URI: https://mainwp.com
Requires at least: 3.6
Tested up to: 4.1
Stable tag: 2.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Manage all of your WP sites, even those on different servers, from one central dashboard that runs off of your own self-hosted WordPress install.

== Description ==

[MainWP](http://mainwp.com) is a free self-hosted WordPress management system that allows you to manage virtually all aspects of multiple WordPress sites including scheduling backups, up time monitoring of your sites, managing content for posts/pages and much more.

**Note:  MainWP requires two (2) plugins.**

**This is the MainWP Dashboard plugin that you install on your own separate WordPress install and the [MainWP child plugin](http://wordpress.org/plugins/mainwp-child/) that you install on the sites you want to control (child sites) .**

**Please check the [Quick Start - Setting up your MainWP](http://docs.mainwp.com/setting-up-your-mainwp/) support doc for more information.**


= Easy Management =
MainWP takes the hassle out of managing your themes and plugins. Using your dashboard means you can review which of your WordPress sites have themes and plugins that need updating from one central location. Just one click will now upgrade all of your plugins and themes at the same time.


= Reliable Backups =
We understand that your WordPress data is valuable — you’re never more than a moment away from a complete disaster, so make sure you do it right! Use our backup feature and enjoy premium-quality backup services for all of your WordPress sites. You can even choose to exclude specific folders that aren’t mission critical.

= Auto-Backup Scheduling =
Providing you the power to automate your backups is another crucial feature you’ll find useful with MainWP . Automate your backups on a daily, weekly, or monthly basis, and then rest assured knowing that your data will be safe and retrievable weather you are backing up your entire site or just your WordPress database.

= Content Management =
The power of MainWP means that publishing content to sites is now as easy as can be. Pick your site from a list, write content, and publish, without the hassles of logging into each and every site. It’s just as easy to manage links, comments, and spam using our mass publish and delete functions.

= Bulk Posting =
Posting content to multiple websites can prove difficult with WordPress alone. With MainWP , posting content to multiple blogs couldn’t be easier. Create the content, select your blogs, and enjoy the time you saved.

= Self Hosted =
Your main MainWP dashboard is hosted on your own WordPress install and not on our private servers. We do not keep records of your actions, sites, passwords or anything else.

= Discovery Protection =
MainWP protects you from your competitors, search engines or anyone else with prying eyes. No one will ever know you are using MainWP unless you tell them.

= Customize Your MainWP =
Extensions offer custom functions and features so that each user can tailor their MainWP installation to their specific needs. MainWP is built in PHP so that any developer can create a new extension for both fun and profit.

= Public Extension Hooks =
Building on the core principals of WordPress our Extension hooks allow third party developers to “hook” into MainWP for an ever expanding list of features. You can find more information in the MainWP Codex

= More Information =
[MainWP Documentation](http://docs.mainwp.com/)

[MainWP Support](https://mainwp.com/forum/)

[MainWP Videos](http://www.youtube.com/user/MyMainWP)

[MainWP Extensions](https://extensions.mainwp.com/)

[MainWP Ideas](http://ideas.mainwp.com/)

[MainWP Codex](http://codex.mainwp.com/index.php?title=Main_Page)

[MainWP on Github](http://mainwp.com/github/)

== Installation ==

1. We HIGHLY recommend a NEW WordPress install for your MainWP Dashboard.
Using a new WordPress install will help to cut down on Plugin Conflicts and other issues that can be caused by trying to run your MainWP Main Dashboard from an active site. Most hosting companies provide free subdomains ("demo.yourdomain.com") and we recommend creating one if you do not have a specific dedicated domain to run your Network Main Dashboard.
If you are not sure how to set up a subdomain here is a quick step by step with [cPanel](http://docs.mainwp.com/creating-a-subdomain-in-cpanel/), [Plesk](http://docs.mainwp.com/creating-a-subdomain-in-plesk/) or [Direct Admin](http://docs.mainwp.com/creating-a-subdomain-in-directadmin-control-panel/). If you are not sure what you have, contact your hosting companies support.
2. Once you have setup the separate WordPress install you can install the MainWP Dashboard plugin following your normal installation procedure either the Automatic process by searching MainWP or by uploading the MainWP plugin to the '/wp-content/plugins/' directory.
3. Once installed then Activate the Plugin through the Plugins Menu in WordPress
4. Add your first child site to the MainWP Dashboard - [Documentation](http://docs.mainwp.com/setting-up-your-mainwp/)
5. Set your MainWP Settings - [Documentation](http://docs.mainwp.com/mainwp-settings-overview/)

Note: MainWP is not tested on multisite installs, we have reports that most functions work but support will be limited.

== Frequently Asked Questions ==
= Do I need any other plugins for MainWP? =
Yes you need to install the [MainWP Child Plugin](http://wordpress.org/plugins/mainwp-child/) on the sites you want to control with the Dashboard plugin.

= Do you have any documentation? =
Yes, please review the [documentation site](http://docs.mainwp.com/).

= I just want to start using MainWP do you have a Quick Start Guide? =
Yes, please read the [Quick Start – Setting up your MainWP](http://docs.mainwp.com/setting-up-your-mainwp/) guide on the doc site.

= Where do I go for support or to ask for help? =
Please go to the [MainWP Community Support Forum](http://mainwp.com/forum/)

= Do you have any videos? =
Yes, you can [see them on YouTube](http://www.youtube.com/user/MyMainWP).

= I have an idea for MainWP how do I let you know? =
Please add any ideas to the [MainWP Ideas](http://ideas.mainwp.com/) site.

= I have more questions, do you have any other information? =
Sure we have a quick FAQ with a lot more questions and answers [here](http://mainwp.com/presales-faq/).

== Screenshots ==

1. The Dashboard Screen
2. The Sites Screen
3. The Posts Screen
4. The Extensions Screen
5. The Plugins Screen
6. The Offline Checks Screen
7. The Groups Screen

== Changelog ==

= 2.0.2 =
* Fixed: Support for big networks with a low MAX_JOIN_SIZE setting in MySQL.

= 2.0.1 =
* Added: Support for SQL users without CREATE VIEW privilege

= 2.0 =
* Added: Tar GZip as a backup file format
* Added: Tar Bzip2 as a backup file format
* Added: Tar as a backup file format
* Added: Feature to resume unfinished or stalled backups
* Added: Feature to detect is backup is already running
* Added: New Feature for the Post Plus extension - Auto saving drafts in case posting fails
* Added: Support for the Team Control extension
* Added: IP check to the Test Connection feature
* Added: Tips bar on the All Dashboard, Sites, Posts, Pages, Manage Themes, Install Themes, Manage Plugins, Install Plugins, Settings and Offline Checks page
* Added: Breadcrumbs system in the Individual Site screen
* Added: Better search mechanism for plugin auto updates
* Added: Better search mechanism for theme auto updates
* Added: Ability to change Auto Update trust status for Inactive plugins
* Added: Ability to change Auto Update trust status for Inactive themes
* Added: Quick Jump drop down list for quicker navigation between Individual Dashboard
* Added: Bulk Actions for the Manage Sites table (Sync, Delete, Test Connection, Open WP-Admin and Open Front Page)
* Added: Feature for saving the last Manage Sites table sorting
* Added: Ability to edit the Child Site Security ID in Dashboard
* Added: Ability to search All (active and inactive) Plugins
* Added: Ability to search All (active and inactive) Themes
* Added: Filters and select helpers for sites and groups in the Manage Groups page
* Added: Extensions API Management
* Added: Bulk Install purchased extensions
* Added: Bulk Grab extensions API keys
* Fixed: Post and Page search mechanism to search content body and title
* Fixed: Client Reports bug not recording scheduled backups
* Fixed: Issue of backup not running if the site is in two groups trying to backup at same time
* Tweak: Groups page removed from the main navigation and added under the Sites menu
* Tweak: Offline Checks removed from the main navigation and added under the Settings menu
* Tweak: “All Sites” menu item renamed to “Manage Sites”
* Tweak: “All Posts” menu item renamed to “Manage Posts”
* Tweak: “All Pages” menu item renamed to “Manage Pages”
* Tweak: “All Users” menu item renamed to “Manage Users”
* Tweak: “Backups” menu item renamed to “Schedule Backup”
* Tweak: “All Backups” menu item renamed to “Manage Backups”
* Tweak: Email Template updated to call images from user’s dashboard site
* Tweak: Individual Sync Now action syncs on the Manage SItes page
* Tweak: MainWP Account login on the settings page encryption
* Tweak: “Auto Update Trust” menu item renamed to “Auto Updates”
* Tweak: “Child Unique Security Id” field moved to the Advanced Options field
* Redesign: Warning message on the Sites > Add New page
* Redesign: Warning message from the Settings page removed and added as an admin notice after the dashboard plugin activation. 
* Redesign: The Advanced Settings options separated in different boxes
* Redesign: New form field style
* Redesign: New Search Posts, Search Pages, Search Themes, Search Plugin and Search Users form style
* Redesign: New Upload Themes and Plugins from layout
* Redesign: The redirection page style updated
* Redesign: Update Admin Passwords page style updated
* Refactor: Site Backup page added as a separate tab
* Refactor: Site Security Scan tab added including Security issues box, WordFence box and Sucuri box
* Refactor: MainWP Database table split into two tables, mainwp_wp_option and mainwp_wp_sync
* Removed: jsapi link to Google

= 1.2.1 =
* Added Auto detection of allowed File Descriptors during backups
* Added Hide Dashboard from non-admins on Dashboard site
* Fixed issue with some links in posts

= 1.2 =
* Added Tooltips on server information page
* Added auto save login after successful test
* Added Additional tweaks for less Backup timeouts
* Added new option to enable more IO instead of memory approach for Backups
* Fixed Dropbox error when directory ends with space
* Fixed "current running" that is stuck (not continuing backups)
* Fixed deprecated theme calls
* Fixed contacting disconnected child sites
* Fixed non https on https host for extensions
* Fixed issues with self signed SSL certificates (added option in Advanced section)
* Removed possibility to query disconnected childs
* Removed link to Google url for jquery to prevent possible tracking
* Removed old code references

= 1.1 =
* Added Option to automatically exclude common backup locations from Backups
* Added Option to automatically exclude common cache locations from Backups
* Added Option to automatically exclude non-WordPress folders from Backups
* Added Option to automatically exclude Zip Archives from Backups
* Added Several new subtasks to increase performance and reduce timeouts on Backups
* Added New Hooks for Extensions
* Fixed Backups allowing special characters that caused backups to fail
* Fixed Text on Backup popup 
* Fixed Issue in how categories tree displayed
* Fixed Exclude folders and categories duplicated in select list when selecting child sites fast
* Fixed Error on first install that occurred on some Dashboards
* Fixed Issue where the upper sync button did not activate when there are posts/pages with html tags in title
* Additional CSS and Cosmetic Tweaks

= 1.0.9.1 =
* Added support for servers blocking curl_multi_exec calls

= 1.0.9 =
* Added additional pings to decrease Backup timeouts on slower servers
* Added enhancement for sites having a timeout stuck while performing a backup
* Added enhancement to decrease server load when searching for or posting posts/pages/users
* Tweaked CSS for Dashboard boxes mobile devices - ht phalancs

= 1.0.8.9 =
* Added redirect for user to add first site on activation
* Cleaned up messages to user on initial activation
* Fixed forms to move to the top and show messages on submit
* Changed Backups to show exclude folders by default
* Fixed PHP Strict Error
* Fixed Page and Post scheduling

= 1.0.8.8 =
* Fix for backups (database backups deleted incorrectly)
* Fix to reduce timeouts on sync

= 1.0.8.7 =
* Tweak for Backup file dates to match set timezone
* Fix for uploads path outside the conventional path for backups
* Fix for created category name and slug not handling spacing correctly
* Fix for declaring wp_mail causing conflict with Mandrill
* Added new hooks for upcoming extensions

= 1.0.8.6 =
* Added ping from dashboard during backups, to reduce timeouts and add better error reporting
* Added intelligent checks to increase backup speed
* Install New Extension button added to the Extensions page
* Extension names tweaked to link to open the extension page
* Notes widget added to Site Individual Dashboard
* .htaccess file tab added to the server information page
* CSS changes for a cleaner look
* Hooks added for the upcoming extension

= 1.0.8.5 =
* Fixed warning with open basedir restriction in place
* Added zip support to database backups
* Added new hooks for upcoming extensions

= 1.0.8.4 =
* Now compatible with WPEngine-hosted child-sites
* Added new hooks for upcoming extensions

= 1.0.8.3 =
* Fixed issue with some missing images
* Fixed issue with Comments extension redirect
* Fixed PHP Notice on Sync Now when using strict 
* Updated Test Connection to show the hostname
* Added new German translations

= 1.0.8.2 =
* Fixed invalid link on Extensions Page
* Fixed screen layout saving on manage sites page
* Fixed various other CSS Layout issues

= 1.0.8.1 =
* Fixed default values for minimum delays between requests
* Fixed issue that prevented 0 as values for minimum delays between requests

= 1.0.8 =
* Added Minimum delay between requests to Settings Advanced Options
* Added Minimum delay between requests to the same ip Settings Advanced Options
* Fixed issue with some premium plugins showing as Trusted in email when not Trusted
* Added Wp-Config Page Viewer to Server Information
* Added new German translations
* Changed Max Request Error Message to provide link to more information

= 1.0.7 =
* Fix menu position, so menus created by extensions will be below Extensions menu item
* Fix for Favorites Extension: bug upload long file name
* Added support for custom crontab settings (may reduce timeouts on bigger sites)
* Optimized the delay settings between requests and requests to the same ip to reduce timeouts

= 1.0.6 =
* Code changes for WP 3.9 Compatibility 
* Added Plugin Widget to Individual Dashboard screen
* Added Theme Widget to Individual Dashboard screen
* Moved Bulk Update Admin password to the Users screen
* Changed Extension Menu Layout

= 1.0.5 =
* Minor fix for heatmap extension

= 1.0.4 =
* Fix for premium plugins

= 1.0.3 =
* Added possibility to disable basic SEO stats
* Codex Issue Fixed for displaying header and footer
* Added support for premium plugins
* Extended server information with CURL requirement
* Added Sync, Add Sites and Extension to the plugin footer for easy access


= 1.0.2 =
* Fixed issue with adding new posts/pages

= 1.0.1 =
* Internal version

= 1.0.0 =
* Initial version
