<?php

namespace ShawnHooper\BirdSiteArchive;
use \WP_CLI;

class Import_Twitter_Command {

	/**
	 * Imports Twitter Data archive to WordPress
	 *
	 * ## OPTIONS
	 *
	 * <post_author>
	 * : The ID of the user to mark as the author of the tweets
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp import-twitter 1
	 *
	 * @when after_wp_load
	 */
	public function __invoke($args) : void {
		$post_author_id = (int)$args[0];

		if ($post_author_id === null || $post_author_id === 0) {
			WP_CLI::error('Error: invalid post author ID');
			return;
		}

		$tweets = $this->get_tweet_array();
		$total_tweets = count($tweets);

		WP_CLI::line('Starting Import of ' . count($tweets) . ' tweets');

		foreach($tweets as $tweet) {
			$this->process_tweet($tweet, $total_tweets, $post_author_id);
		}
	}

	/**
	 * @throws JsonException
	 */
	public function get_tweet_array() : array {
		$tweets = file_get_contents(ABSPATH . 'wp-content/uploads/twitter-archive/tweets.js');
		$tweets = str_replace("window.YTD.tweets.part0 = ", '', $tweets);
		return json_decode($tweets, false, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param stdClass $tweet
	 * @param int $total_tweets
	 * @param int $post_author
	 * @return int
	 * @throws JsonException
	 */
	public function process_tweet(\stdClass $tweet, int $total_tweets, int $post_author) : int {
		$this->tweets_processed++;
		WP_CLI::line("Processing Tweet $this->tweets_processed of $total_tweets");

		$created_at = \DateTime::createFromFormat('D M d H:i:s O Y', $tweet->tweet->created_at)->format('c');

		$tweet_text = $tweet->tweet->full_text;

		if (isset($tweet->tweet->entities->urls)) {
			foreach($tweet->tweet->entities->urls as $url) {
				$tweet_text = str_replace($url->url, $url->expanded_url, $tweet_text);
			}
		}

		$args = [
			'post_name' => $tweet->tweet->id,
			'post_author' => $post_author,
			'post_type' => 'birdsite_tweet',
			'post_status' => 'publish',
			'post_title' => $tweet_text,
			//'post_content' => json_encode($tweet, JSON_THROW_ON_ERROR),
			'post_date' => $created_at,
			'post_date_gmt' => $created_at,
		];

		$post_id = wp_insert_post($args);

		update_post_meta($post_id, '_retweet_count', $tweet->tweet->retweet_count );
		update_post_meta($post_id, '_favorite_count', $tweet->tweet->favorite_count );
		if (isset($tweet->tweet->in_reply_to_status_id_str)) {
			update_post_meta($post_id, '_in_reply_to_status_id_str', $tweet->tweet->in_reply_to_status_id_str );
		}
		if (isset($tweet->tweet->in_reply_to_screen_name)) {
			update_post_meta($post_id, '_in_reply_to_screen_name', $tweet->tweet->in_reply_to_screen_name );
		}
		if (isset($tweet->tweet->entities->hashtags)) {
			$hashtags = [];
			foreach($tweet->tweet->entities->hashtags as $hashtag) {
				$hashtags[] = $hashtag->text;
			}
			wp_set_post_terms($post_id, $hashtags, 'birdsite_hashtags', true);
		}

		return $post_id;

	}


}
