<?php

namespace ShawnHooper\BirdSiteArchive;

use DateTime;
use \WP_CLI;
use WP_Query;

class Import_Twitter_Command {

	private string $post_type;

	private string $hashtag_taxonomy;

	private string $data_dir = '';

	private \stdClass $account_data;

	private int $tweets_processed = 0;
	private int $tweets_skipped = 0;
	private bool $skip_retweets = false;

	private array $media_files = [];

	private array $id_to_post_id_map = [];

	private int $post_author_id = 0;
	private bool $skip_replies = false;

	private string $base_upload_folder_url;

	private $since_date = null;

	/**
	 * Imports Twitter Data archive to WordPress
	 *
	 * ## OPTIONS
	 *
	 * <post_author>
	 * : The ID of the user to mark as the author of the tweets
	 *
	 * [--skip-replies]
	 * : Skip tweets that are replies to someone else
	 *
	 * [--skip-retweets]
	 * : Skip tweets that are retweets
	 *
	 * [--since-date]
	 * : Skip tweets that are before the specified date
	 *
	 * [--post-type]
	 * : Specifies the post type to import to. Defaults to birdsite_tweet
	 *
	 * [--use-aside-format]
	 * : Uses the aside post format for the content.
	 *
	 * [--hashtag-taxonomy]
	 * : Specifies the taxonomy to use when importing hashtags and tickers. Defaults to birdsite_hashtags
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     Import Tweets using birdsite_tweet post type and birdsite_hashtags
	 *     wp import-twitter 1
	 *
	 *     Import Tweets into default post type and tag type.
	 *     wp import-twitter 1 --post-type post --hashtag-taxonomy post_tag
	 *
	 * @when after_wp_load
	 */
	public function __invoke($args, $assoc_args) : void {
		$this->post_type = isset($assoc_args['post-type']) ? $assoc_args['post-type'] : 'birdsite_tweet';
		$this->hashtag_taxonomy = isset($assoc_args['hashtag-taxonomy']) ? $assoc_args['hashtag-taxonomy'] : 'birdsite_hashtags';
		$this->use_aside_format = isset($assoc_args['use-aside-format']);

		if (! post_type_exists($this->post_type)) {
			WP_CLI::error('Error: invalid post type.');
		}

		if (! taxonomy_exists($this->hashtag_taxonomy)) {
			WP_CLI::error('Error: invalid taxonomy.');
		}

		if (isset($assoc_args['since-date'])) {
			$parsed_date = strtotime($assoc_args['since-date']);
			if (!$parsed_date) {
				WP_CLI::error('Invalid date format for --since-date.');
			} else {
				$this->since_date = new DateTime();
				$this->since_date->setTimestamp($parsed_date);
			}
		}

		$this->post_author_id = (int) $args[0];
		$this->skip_replies = isset($assoc_args['skip-replies']);
		$this->skip_retweets = isset($assoc_args['skip-retweets']);

		$upload_dir = wp_upload_dir();
		$this->base_upload_folder_url = $upload_dir['baseurl'];

		if ($this->post_author_id === 0) {
			WP_CLI::error('Error: invalid post author ID');
		}

		$this->data_dir = (wp_upload_dir())['basedir'] . '/twitter-archive';
		$files = $this->get_multipart_tweet_archive_filenames();

		do_action('birdsite_import_start');

		try {
			$this->account_data = $this->get_account_data_from_file();
		} catch (\Exception $e) {
			WP_CLI::error('Error while reading account.js: ' . $e->getMessage());
			die();
		}

		foreach($files as $filename) {
			$this->process_file($filename);
		}

		do_action('birdsite_import_end');
	}

	private function merge_tweet_threads(array $tweets) : array {
		$merged_tweets = [];
		$previous_tweet = null;

		foreach ($tweets as $tweet) {
			if ($previous_tweet && $tweet->in_reply_to_status_id_str == $previous_tweet->id_str) {
				// Merge this tweet's text with the previous one, removing numeric indicators like (1/2)
				$previous_tweet->full_text = $this->remove_numeric_indicators($previous_tweet->full_text) . ' ' . $this->remove_numeric_indicators($tweet->full_text);
				// Update any other necessary fields
				$previous_tweet->entities = $tweet->entities;
				$previous_tweet->extended_entities = $tweet->extended_entities ?? $previous_tweet->extended_entities ?? null;
			} else {
				if ($previous_tweet) {
					$merged_tweets[] = $previous_tweet;
				}
				$previous_tweet = $tweet;
			}
		}

		// Add the last tweet if it wasn't part of a thread
		if ($previous_tweet) {
			$merged_tweets[] = $previous_tweet;
		}

		return $merged_tweets;
	}

	private function remove_numeric_indicators($text) {
		return preg_replace('/\(\d+\/\d+\)\s*$/', '', $text);
	}

	private function process_file(string $filename) : void {
		do_action('birdsite_import_start_of_file', $filename);

		$tweets = $this->get_filtered_result_from_file($filename);
		usort($tweets, static function ($a, $b) { return strnatcmp($a->id, $b->id); });

		$tweets = $this->merge_tweet_threads($tweets);

		$total_tweets = count($tweets);

		WP_CLI::line('Starting Import of ' . count($tweets) . ' tweets');

		foreach ($tweets as $tweet) {
			$this->tweets_processed++;
			$tweet_date = new DateTime($tweet->created_at);

			WP_CLI::line("Processing Tweet $this->tweets_processed of $total_tweets");

			if ($this->since_date && $tweet_date < $this->since_date) {
				WP_CLI::line("Skipping Tweet as it is older than " . $this->since_date->format('Y-m-d'));
				$this->tweets_skipped++;
				continue;
			}

			if ($this->skip_retweets && strpos($tweet->full_text, 'RT') === 0) {
				WP_CLI::line("Skipping Retweet");
				$this->tweets_skipped++;
				continue;
			}

			if ($this->skip_retweets && ($tweet->retweeted || $this->is_quote_retweet($tweet))) {
				WP_CLI::success("Skipping Retweet or Quote Retweet");
				$this->tweets_skipped++;
				continue;
			}

			if ( $this->does_tweet_already_exist($tweet->id)) {
				WP_CLI::success("Tweet already imported, skipping.");
				$this->tweets_skipped++;
				continue;
			}

			if (
				isset($tweet->in_reply_to_status_id) &&
				$this->skip_replies &&
				! isset($this->id_to_post_id_map[$tweet->in_reply_to_status_id]))
			{
				WP_CLI::success("Skipping Reply Tweet");
				$this->tweets_skipped++;
				continue;
			}

			$tweet_text = $tweet->full_text;

			if (isset($tweet->entities->urls)) {
				foreach($tweet->entities->urls as $url) {
					$link_tag = "<a href=\"{$url->expanded_url}\">{$url->display_url}</a>";
					$tweet_text = str_replace($url->url, $link_tag, $tweet_text);
				}
			}

			if (
				isset($tweet->in_reply_to_status_id, $this->id_to_post_id_map[$tweet->in_reply_to_status_id]))
			{
				do_action('birdsite_import_before_each_comment', $tweet);

				$comment_id = wp_insert_comment([
					'comment_post_ID' => $this->id_to_post_id_map[$tweet->in_reply_to_status_id],
					'comment_author' => get_user_by('ID', $this->post_author_id)->display_name,
					'user_id' =>  $this->post_author_id,
					'comment_content' => apply_filters('birdsite_import_comment_tweet_text', $tweet_text, $tweet),
				]);

				add_comment_meta('_tweet_id', $tweet->id, $comment_id, true);

				update_post_meta($this->id_to_post_id_map[$tweet->in_reply_to_status_id], '_is_twitter_thread', '1');
				$this->id_to_post_id_map[$tweet->id] = $this->id_to_post_id_map[$tweet->in_reply_to_status_id];

				do_action('birdsite_import_after_each_comment', $comment_id, $tweet);

				continue;
			}

			$post_id = $this->process_tweet($tweet, $this->post_author_id);

			$this->id_to_post_id_map[$tweet->id] = $post_id;

				if ($this->use_aside_format) {
					set_post_format(get_post($post_id), 'aside');
				}

				$this->set_postmeta($tweet, $post_id);
				$this->set_hashtags($tweet, $post_id);
				$this->set_ticker_symbols($tweet, $post_id);
				$this->process_media($tweet, $post_id);
			}
		}

		do_action('birdsite_import_end_of_file', $filename);

		WP_CLI::success('Import Complete - ' . $this->tweets_processed . ' tweets processed, ' . $this->tweets_skipped . 'skipped');
	}

	private function is_quote_retweet(\stdClass $tweet) : bool {
		if (isset($tweet->entities->urls) && is_array($tweet->entities->urls)) {
			foreach ($tweet->entities->urls as $url) {
				if (strpos($url->expanded_url, 'https://twitter.com/') !== false) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param \stdClass $tweet
	 * @param int $post_author
	 *
	 * @return int
	 */
	public function process_tweet(\stdClass $tweet, int $post_author) : int {
		$tweet = apply_filters('birdsite_import_tweet', $tweet);

		do_action('birdsite_import_before_each_tweet', $tweet);

		$created_at = \DateTime::createFromFormat('D M d H:i:s O Y', $tweet->created_at)->format('c');

		$tweet_text = $tweet->full_text;

		if (isset($tweet->entities->urls)) {
			foreach($tweet->entities->urls as $url) {
				$link_tag = "<a href=\"{$url->expanded_url}\">{$url->display_url}</a>";
				$tweet_text = str_replace($url->url, $link_tag, $tweet_text);
			}
		}

		$args = [
			'post_name' => $tweet->id,
			'post_author' => $post_author,
			'post_type' => $this->post_type,
			'post_status' => 'publish',
			'post_content' => apply_filters('birdsite_import_post_tweet_text', $tweet_text, $tweet),
			'post_date' => $created_at,
			'post_date_gmt' => $created_at,
		];

		$post = wp_insert_post($args);

		do_action('birdsite_import_after_each_tweet', $post);

		return $post;

	}

	/**
	 * @return array
	 */
	private function get_multipart_tweet_archive_filenames() : array {
		$files[] = $this->data_dir . '/tweets.js';
		$additional_parts = glob($this->data_dir . '/tweets-part*.js');

		return apply_filters('birdsite_import_files', array_merge($files, $additional_parts) );
	}

	/**
	 * @return \stdClass
	 * @throws \JsonException
	 */
	private function get_account_data_from_file() : \stdClass {
		$account_data_as_string = file_get_contents($this->data_dir . '/account.js');

		$account_data_as_string = str_replace('window.YTD.account.part0 = ', '', $account_data_as_string);

		$account_data = json_decode($account_data_as_string, false, 512, JSON_THROW_ON_ERROR);

		if (! is_array($account_data)) {
			WP_CLI::error('Data in account.js is not in the expected format');
		}

		return $account_data[0]->account;
	}

	/**
	 * @param string $filepath
	 * @return array
	 * @throws \JsonException
	 */
	private function get_filtered_result_from_file(string $filepath) : array {
		$data = file_get_contents($filepath);
		$tweets = substr($data, strpos($data, "["));

		try {
			$raw_tweets = json_decode($tweets, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			WP_CLI::error("Unable to decode the contents of {$filepath}: " . $e->getMessage());
			die();
		}

		$tweets = [];
		foreach ($raw_tweets as $raw_tweet) {
			$tweet = $raw_tweet;

			// Remove unused entities
			// reduce RAM requirements on large archives
			unset($tweet->tweet->edit_info);
			unset($tweet->tweet->source);
			unset($tweet->tweet->lang);
			unset($tweet->tweet->favorited);
			unset($tweet->tweet->in_reply_to_user_id);
			unset($tweet->tweet->display_text_range);
			unset($tweet->tweet->entities->user_mentions);
			unset($tweet->tweet->in_reply_to_screen_name);

			$tweets[] = $tweet->tweet;
		}

		return $tweets;
	}

	/**
	 * Make the original tweet URL
	 *
	 * @param \stdClass $tweet
	 * @return string
	 */
	private function make_tweet_url(\stdClass $tweet) : string {
		return apply_filters('birdsite_import_tweet_url', 'https://twitter.com/' . $this->account_data->username . '/status/' . $tweet->id, $tweet);
	}

	/**
	 * Set post meta fields for:
	 * - Number of Favorites
	 * - Number of Retweets
	 * - Reply to Tweet ID
	 * - The original Tweet URL
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function set_postmeta(\stdClass $tweet, int $post_id) : void {
		update_post_meta($post_id, '_retweet_count', $tweet->retweet_count );
		update_post_meta($post_id, '_favorite_count', $tweet->favorite_count );

		if (isset($tweet->in_reply_to_status_id_str)) {
			update_post_meta($post_id, '_in_reply_to_status_id_str', $tweet->in_reply_to_status_id_str );
		}

		// Tweets don't seem to have a Tweet URL in the data, so we need to make one.
		update_post_meta($post_id, '_tweet_url', $this->make_tweet_url($tweet));
	}

	/**
	 * Make taxonomy entries from each hashtag
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function set_hashtags(\stdClass $tweet, int $post_id): void
	{
		if (isset($tweet->entities->hashtags)) {
			$hashtags = [];
			foreach($tweet->entities->hashtags as $hashtag) {
				$hashtags[] = $hashtag->text;
			}

			$hashtags = apply_filters('birdsite_import_hashtags', $hashtags, $tweet);

			wp_set_post_terms($post_id, $hashtags, $this->hashtag_taxonomy, true);
		}
	}

	/**
	 * Make taxonomy entries out of each stock ticker symbol
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function set_ticker_symbols(\stdClass $tweet, int $post_id): void
	{
		if (isset($tweet->entities->symbols)) {
			$ticker_symbols = [];
			foreach($tweet->entities->symbols as $symbol) {
				$ticker_symbols[] = '$' . $symbol->text;
			}

			$ticker_symbols = apply_filters('birdsite_import_ticker_symbols', $ticker_symbols, $tweet);

			wp_set_post_terms($post_id, $ticker_symbols, $this->hashtag_taxonomy, true);
		}
	}

	/**
	 * Process an image or video contained in the tweet
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function process_media(\stdClass $tweet, int $post_id): void
	{
		if ( count($this->media_files) === 0) {
			$this->media_files = scandir($this->data_dir . '/tweets_media');
		}

		if (isset($tweet->entities->media)) {
			foreach($tweet->entities->media as $media) {

				$media = apply_filters('birdsite_import_media', $media, $tweet);

				$filename = null;
				$found_filename = null;
				foreach ($this->media_files as $file) {
					if (str_starts_with($file, $tweet->id)) {
						$found_filename = $file;
						WP_CLI::success('Found Media (' . $media->type . '): ' . $found_filename);
						update_post_meta($post_id, '_tweet_media', $found_filename);
						update_post_meta($post_id, '_tweet_media_type', $media->type);
						update_post_meta($post_id, '_tweet_id', $tweet->id);

						$post = get_post($post_id);
						$post->post_title = str_replace($media->url, '', $post->post_title);
						$post->filter = true;
						$media_url = esc_attr($this->base_upload_folder_url . "/twitter-archive/tweets_media/{$found_filename}");
						$post->post_content = str_replace($media->url, apply_filters('birdsite_import_img_tag', "<img src=\"$media_url\" />", $media, $tweet), $post->post_content);

						wp_update_post($post);

						do_action('birdsite_import_media_imported', $media, $post);

						break;
					}
				}

				if ( $found_filename === null ) {
					WP_CLI::warning('Unable to find media (' . $media->type . '): ' . $filename);
				}

			}
		}
	}

	/**
	 * Checks whether a post or comment was already created from this tweet.
	 * Allows you to run the importer multiple times without creating duplicates.
	 *
	 * @param string $tweet_id
	 * @return bool
	 */
	private function does_tweet_already_exist(string $tweet_id) : bool
	{
		$post = $this->get_post_by_name($tweet_id);

		if ($post) {
			return true;
		}

		$comment_count = get_comments([
			'count' => true,
			'meta_key' => '_tweet_id',
			'meta_value' => $tweet_id,
			'post_type' => $this->post_type,
		]);

		return $comment_count > 0;
	}

	/**
	 * Find a post by the post_name field
	 *
	 * @param string $name
	 * @return bool
	 */
	private function get_post_by_name(string $name): bool
	{
		$query = new WP_Query([
			"post_type" => $this->post_type,
			"name" => $name
		]);

		return $query->have_posts();
	}
}
