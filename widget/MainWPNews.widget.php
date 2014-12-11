<?php
class MainWPNews
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function getName()
    {
        return __("News",'mainwp');
    }

    public static function render()
    {
        $news = get_option('mainwp_news');
        $newstimestamp = get_option('mainwp_news_timestamp');
        if ($newstimestamp === false || (time() - $newstimestamp) > 60 * 60 * 24) //24 hrs..
        {
            try
            {
                $result = MainWPUtility::http_post("do=news", "mainwp.com", "/versioncontrol/rqst.php", 80, 'main', true);
            }
            catch (Exception $e)
            {
                MainWPLogger::Instance()->warning('An error occured when trying to reach the MainWP server: ' . $e->getMessage());
            }

            //get news..
            if (isset($result[1]))
            {
                $news = json_decode($result[1], true);
                MainWPUtility::update_option('mainwp_news', $news);
                MainWPUtility::update_option('mainwp_news_timestamp', time());
            }
        }

        if (!is_array($news) || count($news) == 0)
        {
            //No news..
            ?>
            <div>No news items found.</div>
            <?php
            return;
        }

        ?>
        <div>
            <div id="mainwp-news-tabs" class="mainwp-row" style="border-top: 0px">
                <?php
                $newsCategories = array();
                foreach ($news as $newsItem)
                {
                    if (!in_array($newsItem['category'], $newsCategories)) $newsCategories[] = $newsItem['category'];
                }

                for ($i = 0; $i < count($newsCategories); $i++)
                {
                    $category = $newsCategories[$i];
                    /** @var $class string */
                    if (count($newsCategories) == 1)
                    {
                        $class = 'single';
                    }
                    else if ($i == (count($newsCategories) - 1))
                    {
                        $class = 'right';
                    }
                    else if ($i == 0)
                    {
                        $class = 'left';
                    }
                    else
                    {
                        $class = 'mid';
                    }

                    if ($category == 'MainWP')
                    {
                        $class .= ' mainwp_action_down';
                    }
                    ?><a class="mainwp_action <?php echo $class; ?> mainwp-news-tab" href="#" name="<?php echo MainWPUtility::sanitize($category); ?>"><?php _e($category,'mainwp'); ?></a><?php
                }
                ?>
            </div>
            <div id="mainwp-news-list">
                <?php
                $category = '';
                foreach ($news as $newsItem)
                {
                    if ($category != $newsItem['category'])
                    {
                        if ($category != '') echo '</div>';
                        echo '<div class="mainwp-news-items" name="'.MainWPUtility::sanitize($newsItem['category']).'" '.($newsItem['category'] == 'MainWP' ? '' : 'style="display: none;"').'>';
                    }
                    ?>
                    <div class="mainwp-news-item" title="<?php echo MainWPUtility::sanitize($newsItem['title']); ?>">
                        <strong><?php echo $newsItem['title']; ?></strong><br />
                        <?php echo $newsItem['body']; ?>
                        <div style="text-align: right"><em>Submitted <?php if (isset($newsItem['submitter']) && $newsItem['submitter'] != '') { echo 'by <strong>' . $newsItem['submitter'] .'</strong> '; } ?> at <strong><?php echo MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($newsItem['timestamp'])); ?></strong></em></div>
                    </div>
                    <?php
                    if ($category != $newsItem['category'])
                    {
                        $category = $newsItem['category'];
                    }
                }
                if ($category != '') echo '</div>';
                ?>
            </div>
        </div>
    <?php
}
}