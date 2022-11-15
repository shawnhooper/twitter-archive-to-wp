<?php

namespace ShawnHooper\BirdSiteArchive;
use \WP_CLI;

class Import_Twitter_Command {

	private int $tweets_processed = 0;
	private int $tweets_skipped = 0;

	private array $media_files = [];

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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp import-twitter 1
	 *
	 * @when after_wp_load
	 */
	public function __invoke($args, $assoc_args) : void {
		$post_author_id = (int)$args[0];

		$skip_replies = isset($assoc_args['skip-replies']);

		if ($post_author_id === null || $post_author_id === 0) {
			WP_CLI::error('Error: invalid post author ID');
			return;
		}

		$tweets = $this->get_tweet_array();
		$total_tweets = count($tweets);

		WP_CLI::line('Starting Import of ' . count($tweets) . ' tweets');

		foreach($tweets as $tweet) {
			$this->tweets_processed++;

			WP_CLI::line("Processing Tweet $this->tweets_processed of $total_tweets");

			if (isset($tweet->tweet->in_reply_to_status_id) && $skip_replies) {
				\WP_CLI::success("Skipping Reply Tweet");
				$this->tweets_skipped++;
				continue;
			}

			$post_id = $this->process_tweet($tweet->tweet, $post_author_id);

			$this->set_postmeta($tweet->tweet, $post_id);
			$this->set_hashtags($tweet->tweet, $post_id);
			$this->process_media($tweet->tweet, $post_id);
		}

		WP_CLI::success('Import Complete - ' . $this->tweets_processed . ' tweets processed, ' . $skip_replies . 'skipped');
	}

	/**
	 * @param \stdClass $tweet
	 * @param int $post_author
	 * @return int
	 */
	public function process_tweet(\stdClass $tweet, int $post_author) : int {
		$created_at = \DateTime::createFromFormat('D M d H:i:s O Y', $tweet->created_at)->format('c');

		$tweet_text = $tweet->full_text;

		if (isset($tweet->entities->urls)) {
			foreach($tweet->entities->urls as $url) {
				$tweet_text = str_replace($url->url, $url->expanded_url, $tweet_text);
			}
		}

		$args = [
			'post_name' => $tweet->id,
			'post_author' => $post_author,
			'post_type' => 'birdsite_tweet',
			'post_status' => 'publish',
			'post_title' => $tweet_text,
			//'post_content' => json_encode($tweet, JSON_THROW_ON_ERROR),
			'post_date' => $created_at,
			'post_date_gmt' => $created_at,
		];

		return wp_insert_post($args);

	}

	/**
	 * @throws \JsonException
	 */
	private function get_tweet_array() : array {
		$tweets = file_get_contents(ABSPATH . 'wp-content/uploads/twitter-archive/tweets.js');
		$tweets = str_replace("window.YTD.tweets.part0 = ", '', $tweets);
		$decoded_json = json_decode($tweets, false, 512, JSON_THROW_ON_ERROR);

		// Sort based on created_at date
		usort($decoded_json, static function ($a, $b) { return strnatcmp($a->tweet->id, $b->tweet->id); });

		return $decoded_json;

	}

	private function set_postmeta(\stdClass $tweet, int $post_id) : void {
		update_post_meta($post_id, '_retweet_count', $tweet->retweet_count );
		update_post_meta($post_id, '_favorite_count', $tweet->favorite_count );

		if (isset($tweet->in_reply_to_status_id_str)) {
			update_post_meta($post_id, '_in_reply_to_status_id_str', $tweet->in_reply_to_status_id_str );
		}

		if (isset($tweet->in_reply_to_screen_name)) {
			update_post_meta($post_id, '_in_reply_to_screen_name', $tweet->in_reply_to_screen_name );
		}
	}

	private function set_hashtags(\stdClass $tweet, int $post_id) {
		if (isset($tweet->entities->hashtags)) {
			$hashtags = [];
			foreach($tweet->entities->hashtags as $hashtag) {
				$hashtags[] = $hashtag->text;
			}
			wp_set_post_terms($post_id, $hashtags, 'birdsite_hashtags', true);
		}
	}

	private function process_media(\stdClass $tweet, int $post_id): void
	{
		if ( count($this->media_files) === 0) {
			$this->media_files = scandir(ABSPATH . 'wp-content/uploads/twitter-archive/tweets_media');
		}

		if (isset($tweet->entities->media)) {
			foreach($tweet->entities->media as $media) {

				$filename = null;
				$found_filename = null;
				foreach ($this->media_files as $file) {
					if (str_starts_with($file, $tweet->id)) {
						$found_filename = $file;
						\WP_CLI::success('Found Media (' . $media->type . '): ' . $found_filename);
						update_post_meta($post_id, '_tweet_media', $found_filename);
						update_post_meta($post_id, '_tweet_media_type', $media->type);

						$post = get_post($post_id);
						$post->post_title = str_replace($media->url, '', $post->post_title);
						$post->filter = true;
						$post->post_content = "<img src=\"/wp-content/uploads/twitter-archive/tweets_media/{$found_filename}\" />";

						wp_update_post($post);

						break;
					}
				}

				if ( $found_filename === null ) {
					WP_CLI::error('Unable to find media (' . $media->type . '): ' . $filename);
				}

			}
		}

	}


}
