=== MainWP ===
Contributors: mainwp
Donate link: 
Tags: WordPress Management, WordPress Controller
Author: mainwp
Author URI: http://mainwp.com
Plugin URI: http://mainwp.com
Requires at least: 3.6
Tested up to: 3.9
Stable tag: 1.0.8
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

[MainWP Support](http://mainwp.com/forum/)

[MainWP Videos](http://www.youtube.com/user/MyMainWP)

[MainWP Extensions](http://extensions.mainwp.com/)

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
= 1.0.9 =
* Added Minimum delay between requests to Settings Advanced Options
* Added Minimum delay between requests to the same ip Settings Advanced Options
* Fixed issue with some preimum plugins showing as Trusted in email when not Trusted
* Added Wp-Config Page Viewer to Server Information
* Added new German translations
* Changed Max /Request Error Message to provide link to more information

= 1.0.8 =
* Added German translations
* Added more information to the server information page


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
