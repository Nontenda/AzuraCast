<?php
namespace PVL;

use \Entity\Station;
use \Entity\Schedule;

use \Entity\Podcast;
use \Entity\PodcastEpisode;

use \Entity\Settings;
use \Entity\ShortUrl;

class NotificationManager
{
    public static function run($force_run = false)
    {
        $em = \Zend_Registry::get('em');
        $config = \Zend_Registry::get('config');

        /**
         * Scheduled Shows from Stations
         */

        $notify_minutes = 15;

        $start_threshold = time();
        $end_threshold = time()+(60*$notify_minutes);

        $schedule_items = $em->createQuery('SELECT s, st FROM Entity\Schedule s JOIN s.station st WHERE s.start_time >= :start AND s.start_time <= :end AND s.type = :type AND s.is_notified = 0')
            ->setParameter('start', $start_threshold)
            ->setParameter('end', $end_threshold)
            ->setParameter('type', 'station')
            ->setMaxResults(1)
            ->execute();

        if ($schedule_items)
        {
            $schedule_item = $schedule_items[0];
            $station = $schedule_item->station;

            if ($station->twitter_url)
                $twitter_handle = '@'.array_pop(explode('/', $station->twitter_url));
            else
                $twitter_handle = $station->name;

            $tweet = 'On The Air: '.$schedule_item->title.' in '.$notify_minutes.' minutes on '.$twitter_handle.'!';
            $tweet_url = $station->getShortUrl();

            self::tweet($tweet, $tweet_url);

            $schedule_item->is_notified = true;
            $schedule_item->save();
        }

        /**
         * New Podcast Episodes
         */

        $start_threshold = time()-86400*7;
        $end_threshold = time();

        $podcast_episodes = $em->createQuery('SELECT pe, p FROM Entity\PodcastEpisode pe JOIN pe.podcast p WHERE pe.timestamp BETWEEN :start AND :end AND pe.is_notified = 0')
            ->setParameter('start', $start_threshold)
            ->setParameter('end', $end_threshold)
            ->setMaxResults(1)
            ->execute();

        if ($podcast_episodes)
        {
            $episode = $podcast_episodes[0];
            $podcast = $episode->podcast;

            $title = \DF\Utilities::truncateText($episode->title, 110-strlen($podcast->name)-6);
            $tweet = $podcast->name.': "'.$title.'" -';

            self::tweet($tweet, $episode->web_url);

            $episode->is_notified = true;
            $episode->save();
        }

        return;
    }

    public static function tweet($message, $url = null, $force = false)
    {
        static $twitter;

        // Suppress notifications for non-production applications.
        if (DF_APPLICATION_ENV != "production" && !$force)
            return false;

        if (!$twitter)
        {
            $config = \Zend_Registry::get('config');
            $twitter_config = $config->apis->twitter->toArray();
            $twitter = new \tmhOAuth($twitter_config);
        }

        $message_length = ($url) ? 110 : 130;
        $tweet = \DF\Utilities::truncateText($message, $message_length);

        if ($url)
            $tweet .= ' '.$url;

        $tweet .= ' #PVLive';

        $twitter->request('POST', 'https://api.twitter.com/1.1/statuses/update.json', array(
            'status' => $tweet,
        ));
        \PVL\Debug::print_r($twitter->response['response']);
    }
}