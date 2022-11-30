<?php

namespace ShawnHooper\BirdSiteArchive;
use \WP_CLI;

class Import_Twitter_Command {

	private string $data_dir = '';

	private int $tweets_processed = 0;
	private int $tweets_skipped = 0;

	private array $media_files = [];

	private array $id_to_post_id_map = [];

	private int $post_author_id = 0;
	private bool $skip_replies = false;

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
		$this->post_author_id = (int)$args[0];

		$this->skip_replies = isset($assoc_args['skip-replies']);

		if ($this->post_author_id === 0) {
			WP_CLI::error('Error: invalid post author ID');
			return;
		}

		$this->data_dir = (wp_upload_dir())['basedir'] . '/twitter-archive';
		$files = $this->get_multipart_tweet_archive_filenames();

		foreach($files as $filename) {
			$this->process_file($filename);
		}

	}

	private function process_file(string $filename): void
	{
		$tweets = $this->get_filtered_result_from_file($filename);
		usort($tweets, static function ($a, $b) { return strnatcmp($a->id, $b->id); });


		$total_tweets = count($tweets);

		WP_CLI::line('Starting Import of ' . count($tweets) . ' tweets');

		for ($i = 0; $i < $total_tweets; $i++) {
			$tweet = $tweets[$i];
			$this->tweets_processed++;

			WP_CLI::line("Processing Tweet $this->tweets_processed of $total_tweets");

			if (
				isset($tweet->in_reply_to_status_id) &&
				$this->skip_replies &&
				! isset($this->id_to_post_id_map[$tweet->in_reply_to_status_id]))
			{
				\WP_CLI::success("Skipping Reply Tweet");
				$this->tweets_skipped++;
				continue;
			}

			if (
				isset($tweet->in_reply_to_status_id) &&
				isset($this->id_to_post_id_map[$tweet->in_reply_to_status_id]))
			{
				$comment_id = wp_insert_comment([
					'comment_post_ID' => $this->id_to_post_id_map[$tweet->in_reply_to_status_id],
					'comment_author' => get_user_by('ID', $this->post_author_id)->display_name,
					'user_id' =>  $this->post_author_id,
					'comment_content' => $tweet->full_text,
				]);

				add_comment_meta('_tweet_id', $tweet->id, $comment_id, true);

				update_post_meta($this->id_to_post_id_map[$tweet->in_reply_to_status_id], '_is_twitter_thread', '1');
				$this->id_to_post_id_map[$tweet->id] = $this->id_to_post_id_map[$tweet->in_reply_to_status_id];
				continue;
			}

			$post_id = $this->process_tweet($tweet, $this->post_author_id);

			$this->id_to_post_id_map[$tweet->id] = $post_id;

			$this->set_postmeta($tweet, $post_id);
			$this->set_hashtags($tweet, $post_id);
			$this->set_ticker_symbols($tweet, $post_id);
			$this->process_media($tweet, $post_id);
		}

		WP_CLI::success('Import Complete - ' . $this->tweets_processed . ' tweets processed, ' . $this->skip_replies . 'skipped');
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
				$link_tag = "<a href=\"{$url->expanded_url}\">{$url->display_url}</a>";
				$tweet_text = str_replace($url->url, $link_tag, $tweet_text);
			}
		}

		$args = [
			'post_name' => $tweet->id,
			'post_author' => $post_author,
			'post_type' => 'birdsite_tweet',
			'post_status' => 'publish',
			'post_content' => $tweet_text,
			'post_date' => $created_at,
			'post_date_gmt' => $created_at,
		];

		return wp_insert_post($args);

	}

	/**
	 * @return array
	 */
	private function get_multipart_tweet_archive_filenames() : array {
		$files[] = $this->data_dir . '/tweets.js';
		$additional_parts = glob($this->data_dir . '/tweets-part*.js');

		$files = array_merge($files, $additional_parts);

		return $files;
	}

	/**
	 * @param string $filepath
	 * @return array
	 * @throws \JsonException
	 */
	private function get_filtered_result_from_file(string $filepath) : array {
		$data = file_get_contents($filepath);
		$tweets = substr($data, strpos($data, "["));

		$raw_tweets = json_decode($tweets, false, 512, JSON_THROW_ON_ERROR);

		$tweets = [];
		foreach ($raw_tweets as $raw_tweet) {
			$tweet = $raw_tweet;

			// Remove unused entities
			// reduce RAM requirements on large archjves
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

	private function set_postmeta(\stdClass $tweet, int $post_id) : void {
		update_post_meta($post_id, '_retweet_count', $tweet->retweet_count );
		update_post_meta($post_id, '_favorite_count', $tweet->favorite_count );

		if (isset($tweet->in_reply_to_status_id_str)) {
			update_post_meta($post_id, '_in_reply_to_status_id_str', $tweet->in_reply_to_status_id_str );
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

	private function set_ticker_symbols(\stdClass $tweet, int $post_id) {
		if (isset($tweet->entities->symbols)) {
			$ticker_symbols = [];
			foreach($tweet->entities->symbols as $symbol) {
				$ticker_symbols[] = '$' . $symbol->text;
			}
			wp_set_post_terms($post_id, $ticker_symbols, 'birdsite_hashtags', true);
		}
	}

	private function process_media(\stdClass $tweet, int $post_id): void
	{
		if ( count($this->media_files) === 0) {
			$this->media_files = scandir($this->data_dir . '/tweets_media');
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
						update_post_meta($post_id, '_tweet_id', $tweet->id);

						$post = get_post($post_id);
						$post->post_title = str_replace($media->url, '', $post->post_title);
						$post->filter = true;
						$post->post_content = "<img src=\"/wp-content/uploads/twitter-archive/tweets_media/{$found_filename}\" />";

						wp_update_post($post);

						break;
					}
				}

				if ( $found_filename === null ) {
					WP_CLI::warning('Unable to find media (' . $media->type . '): ' . $filename);
				}

			}
		}

	}


}
